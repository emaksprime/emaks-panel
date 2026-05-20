<?php

namespace App\Http\Controllers\Api\B2B;

use App\Http\Controllers\Controller;
use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerAuditLog;
use App\Models\B2B\B2BPartnerUserAccess;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\User;
use App\Services\B2B\B2BPartnerAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class B2BPartnerUserController extends Controller
{
    private const SCOPES = [
        'view',
        'manage',
        'orders',
        'stock',
        'finance',
        'technical_service',
        'users',
    ];

    private const ABILITY_COLUMNS = [
        'can_view',
        'can_create',
        'can_update',
        'can_approve',
    ];

    public function __construct(
        private readonly B2BPartnerAccessService $access,
    ) {}

    public function index(Request $request, B2BPartner $partner): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $this->access->canViewPartner($actor, $partner), 403);

        return response()->json([
            'partner' => $this->partnerPayload($partner->loadMissing('technician')),
            'items' => $this->partnerUsersPayload($partner),
            'can_manage' => $this->access->canManagePartnerUsers($actor, $partner),
        ]);
    }

    public function store(Request $request, B2BPartner $partner): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $this->access->canManagePartnerUsers($actor, $partner), 403);

        $data = $this->validatedAccessPayload($request, true);
        $targetUser = User::query()->findOrFail($data['user_id']);

        DB::transaction(function () use ($partner, $targetUser, $data, $request, $actor): void {
            $before = $this->combinedSnapshot($partner, $targetUser);

            $profile = $this->upsertProfile($partner, $targetUser, $data);
            $this->syncScopes($partner, $targetUser, $data['scopes'] ?? [], $actor);

            $after = $this->combinedSnapshot($partner, $targetUser, $profile->fresh());

            $this->writeAuditLog(
                $partner,
                $request,
                'b2b.partner_user.assigned',
                $before,
                $after,
                $actor->id,
                $targetUser->id,
            );

            $this->writeProfileAuditIfChanged($partner, $request, $before, $after, $actor->id, $targetUser->id);
        });

        return response()->json([
            'partner' => $this->partnerPayload($partner->loadMissing('technician')),
            'items' => $this->partnerUsersPayload($partner),
        ], 201);
    }

    public function update(Request $request, B2BPartner $partner, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $this->access->canManagePartnerUsers($actor, $partner), 403);

        $data = $this->validatedAccessPayload($request, false);

        DB::transaction(function () use ($partner, $user, $data, $request, $actor): void {
            $before = $this->combinedSnapshot($partner, $user);

            $profile = $this->upsertProfile($partner, $user, $data);
            $this->syncScopes($partner, $user, $data['scopes'] ?? [], $actor);

            $after = $this->combinedSnapshot($partner, $user, $profile->fresh());

            $this->writeAuditLog(
                $partner,
                $request,
                'b2b.partner_user.access_updated',
                $before,
                $after,
                $actor->id,
                $user->id,
            );

            $this->writeProfileAuditIfChanged($partner, $request, $before, $after, $actor->id, $user->id);
        });

        return response()->json([
            'partner' => $this->partnerPayload($partner->loadMissing('technician')),
            'items' => $this->partnerUsersPayload($partner),
        ]);
    }

    public function destroy(Request $request, B2BPartner $partner, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $this->access->canManagePartnerUsers($actor, $partner), 403);

        DB::transaction(function () use ($partner, $user, $request, $actor): void {
            $before = $this->combinedSnapshot($partner, $user);

            B2BPartnerUserProfile::query()
                ->where('partner_id', $partner->id)
                ->where('user_id', $user->id)
                ->update(['active' => false]);

            B2BPartnerUserAccess::query()
                ->where('partner_id', $partner->id)
                ->where('user_id', $user->id)
                ->update([
                    'can_view' => false,
                    'can_create' => false,
                    'can_update' => false,
                    'can_approve' => false,
                ]);

            $after = $this->combinedSnapshot($partner, $user);

            $this->writeAuditLog(
                $partner,
                $request,
                'b2b.partner_user.revoked',
                $before,
                $after,
                $actor->id,
                $user->id,
            );
        });

        return response()->json([
            'partner' => $this->partnerPayload($partner->loadMissing('technician')),
            'items' => $this->partnerUsersPayload($partner),
        ]);
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $this->access->canSearchPanelUsers($actor), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
            'role_code' => ['nullable', 'string', 'max:128'],
        ]);

        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query = User::query()->with('role');

        if (array_key_exists('active', $filters)) {
            $query->where('aktif', $filters['active']);
        }

        if (! empty($filters['role_code'])) {
            $query->where('role_code', $filters['role_code']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $query) use ($likeOperator, $search): void {
                $query->where('full_name', $likeOperator, '%'.$search.'%')
                    ->orWhere('username', $likeOperator, '%'.$search.'%')
                    ->orWhere('role_code', $likeOperator, '%'.$search.'%');
            });
        }

        return response()->json([
            'items' => $query
                ->orderByDesc('aktif')
                ->orderBy('full_name')
                ->limit(50)
                ->get()
                ->map(fn (User $user): array => $this->userPayload($user))
                ->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAccessPayload(Request $request, bool $requireUser): array
    {
        return $request->validate([
            'user_id' => [
                $requireUser ? 'required' : 'sometimes',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! User::query()->whereKey($value)->exists()) {
                        $fail('Seçilen panel kullanıcısı bulunamadı.');
                    }
                },
            ],
            'title' => ['nullable', 'string', 'max:128'],
            'phone' => ['nullable', 'string', 'max:64'],
            'active' => ['sometimes', 'boolean'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*.access_scope' => ['required_with:scopes', 'string', Rule::in(self::SCOPES)],
            'scopes.*.can_view' => ['sometimes', 'boolean'],
            'scopes.*.can_create' => ['sometimes', 'boolean'],
            'scopes.*.can_update' => ['sometimes', 'boolean'],
            'scopes.*.can_approve' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertProfile(B2BPartner $partner, User $targetUser, array $data): B2BPartnerUserProfile
    {
        return B2BPartnerUserProfile::query()->updateOrCreate(
            [
                'partner_id' => $partner->id,
                'user_id' => $targetUser->id,
            ],
            [
                'title' => $this->nullableString($data['title'] ?? null),
                'phone' => $this->nullableString($data['phone'] ?? null),
                'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $scopes
     */
    private function syncScopes(B2BPartner $partner, User $targetUser, array $scopes, User $actor): void
    {
        $normalizedScopes = collect($scopes)
            ->filter(fn (array $scope): bool => in_array($scope['access_scope'] ?? null, self::SCOPES, true))
            ->keyBy('access_scope');

        foreach ($normalizedScopes as $scope => $abilities) {
            B2BPartnerUserAccess::query()->updateOrCreate(
                [
                    'partner_id' => $partner->id,
                    'user_id' => $targetUser->id,
                    'access_scope' => $scope,
                ],
                [
                    'can_view' => (bool) ($abilities['can_view'] ?? false),
                    'can_create' => (bool) ($abilities['can_create'] ?? false),
                    'can_update' => (bool) ($abilities['can_update'] ?? false),
                    'can_approve' => (bool) ($abilities['can_approve'] ?? false),
                    'created_by' => $actor->id,
                ],
            );
        }

        B2BPartnerUserAccess::query()
            ->where('partner_id', $partner->id)
            ->where('user_id', $targetUser->id)
            ->whereNotIn('access_scope', $normalizedScopes->keys()->all())
            ->update([
                'can_view' => false,
                'can_create' => false,
                'can_update' => false,
                'can_approve' => false,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function partnerUsersPayload(B2BPartner $partner): Collection
    {
        $profiles = B2BPartnerUserProfile::query()
            ->with('user.role')
            ->where('partner_id', $partner->id)
            ->get()
            ->keyBy('user_id');

        $accessRows = B2BPartnerUserAccess::query()
            ->with('user.role')
            ->where('partner_id', $partner->id)
            ->get()
            ->groupBy('user_id');

        $userIds = $profiles->keys()
            ->merge($accessRows->keys())
            ->unique()
            ->values();

        $users = User::query()
            ->with('role')
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        return $userIds
            ->map(function (int|string $userId) use ($profiles, $accessRows, $users): ?array {
                $user = $users->get($userId);

                if (! $user) {
                    return null;
                }

                $profile = $profiles->get($userId);

                return [
                    ...$this->userPayload($user),
                    'profile_title' => $profile?->title,
                    'profile_phone' => $profile?->phone,
                    'profile_active' => $profile ? (bool) $profile->active : false,
                    'last_seen_at' => $profile?->last_seen_at?->toIso8601String(),
                    'scopes' => $this->scopesPayload($accessRows->get($userId, collect())),
                ];
            })
            ->filter()
            ->sortBy('name')
            ->values();
    }

    /**
     * @param  Collection<int, B2BPartnerUserAccess>  $accessRows
     * @return array<string, array<string, bool>>
     */
    private function scopesPayload(Collection $accessRows): array
    {
        $payload = [];

        foreach ($accessRows as $access) {
            $payload[$access->access_scope] = [
                'can_view' => (bool) $access->can_view,
                'can_create' => (bool) $access->can_create,
                'can_update' => (bool) $access->can_update,
                'can_approve' => (bool) $access->can_approve,
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function combinedSnapshot(
        B2BPartner $partner,
        User $targetUser,
        ?B2BPartnerUserProfile $profile = null,
    ): array {
        $profile ??= B2BPartnerUserProfile::query()
            ->where('partner_id', $partner->id)
            ->where('user_id', $targetUser->id)
            ->first();

        $scopeRows = B2BPartnerUserAccess::query()
            ->where('partner_id', $partner->id)
            ->where('user_id', $targetUser->id)
            ->orderBy('access_scope')
            ->get();

        return [
            'partner_id' => $partner->id,
            'user_id' => $targetUser->id,
            'profile' => $profile ? [
                'title' => $profile->title,
                'phone' => $profile->phone,
                'active' => (bool) $profile->active,
                'last_seen_at' => $profile->last_seen_at?->toIso8601String(),
            ] : null,
            'scopes' => $this->scopesPayload($scopeRows),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function writeAuditLog(
        B2BPartner $partner,
        Request $request,
        string $action,
        ?array $oldValues,
        array $newValues,
        int $actorId,
        int $targetUserId,
    ): void {
        B2BPartnerAuditLog::query()->create([
            'partner_id' => $partner->id,
            'user_id' => $actorId,
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $targetUserId,
            'old_values' => $oldValues,
            'new_values' => [
                ...$newValues,
                'acting_user_id' => $actorId,
            ],
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function writeProfileAuditIfChanged(
        B2BPartner $partner,
        Request $request,
        array $before,
        array $after,
        int $actorId,
        int $targetUserId,
    ): void {
        if (($before['profile'] ?? null) === ($after['profile'] ?? null)) {
            return;
        }

        $this->writeAuditLog(
            $partner,
            $request,
            'b2b.partner_user.profile_updated',
            ['profile' => $before['profile'] ?? null, 'partner_id' => $partner->id, 'user_id' => $targetUserId],
            ['profile' => $after['profile'] ?? null, 'partner_id' => $partner->id, 'user_id' => $targetUserId],
            $actorId,
            $targetUserId,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerPayload(B2BPartner $partner): array
    {
        return [
            'id' => $partner->id,
            'partner_type' => $partner->partner_type,
            'capabilities' => $partner->capabilityCodes(),
            'partner_code' => $partner->partner_code,
            'display_name' => $partner->display_name,
            'mikro_cari_kodu' => $partner->mikro_cari_kodu,
            'mikro_cari_unvan' => $partner->mikro_cari_unvan,
            'city' => $partner->city,
            'district' => $partner->district,
            'active' => (bool) $partner->active,
            'technical_service_technician_id' => $partner->technical_service_technician_id,
            'linked_technician_name' => $partner->technician?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role_code' => $user->role_code,
            'active' => (bool) $user->aktif,
        ];
    }
}
