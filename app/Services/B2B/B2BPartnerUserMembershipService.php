<?php

namespace App\Services\B2B;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerAuditLog;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BPartnerUserAccess;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class B2BPartnerUserMembershipService
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

    /**
     * @return array<string, mixed>
     */
    public function validatePayload(Request $request, B2BPartner $partner, bool $requireUser): array
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
            'technical_service_technician_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($partner): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $validLink = B2BPartnerTechnician::query()
                        ->where('partner_id', $partner->id)
                        ->where('technical_service_technician_id', (int) $value)
                        ->where('active', true)
                        ->whereHas('technician', fn ($query) => $query->where('active', true))
                        ->exists();

                    if (! $validLink) {
                        $fail('Usta profili aynı partnere bağlı ve aktif bir usta olmalıdır.');
                    }
                },
            ],
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
    public function assign(
        B2BPartner $partner,
        User $targetUser,
        array $data,
        Request $request,
        User $actor,
    ): void {
        if (B2BPartnerUserProfile::query()
            ->where('partner_id', $partner->id)
            ->where('user_id', $targetUser->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'partner_id' => 'Bu kullanıcı için partner üyeliği zaten mevcut; mevcut üyeliği düzenleyin.',
            ]);
        }

        $this->save($partner, $targetUser, $data, $request, $actor, 'b2b.partner_user.assigned');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        B2BPartner $partner,
        User $targetUser,
        array $data,
        Request $request,
        User $actor,
    ): void {
        $this->save($partner, $targetUser, $data, $request, $actor, 'b2b.partner_user.access_updated');
    }

    public function deactivate(
        B2BPartner $partner,
        User $targetUser,
        Request $request,
        User $actor,
    ): void {
        DB::transaction(function () use ($partner, $targetUser, $request, $actor): void {
            $before = $this->combinedSnapshot($partner, $targetUser);

            B2BPartnerUserProfile::query()
                ->where('partner_id', $partner->id)
                ->where('user_id', $targetUser->id)
                ->update(['active' => false]);

            B2BPartnerUserAccess::query()
                ->where('partner_id', $partner->id)
                ->where('user_id', $targetUser->id)
                ->update([
                    'can_view' => false,
                    'can_create' => false,
                    'can_update' => false,
                    'can_approve' => false,
                ]);

            $this->writeAuditLog(
                $partner,
                $request,
                'b2b.partner_user.revoked',
                $before,
                $this->combinedSnapshot($partner, $targetUser),
                $actor->id,
                $targetUser->id,
            );
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function partnerUsersPayload(B2BPartner $partner): Collection
    {
        $profiles = B2BPartnerUserProfile::query()
            ->where('partner_id', $partner->id)
            ->get()
            ->keyBy('user_id');
        $accessRows = B2BPartnerUserAccess::query()
            ->where('partner_id', $partner->id)
            ->get()
            ->groupBy('user_id');
        $userIds = $profiles->keys()->merge($accessRows->keys())->unique()->values();
        $users = User::query()->with('role')->whereIn('id', $userIds)->get()->keyBy('id');
        $technicianLinks = $this->technicianLinksForProfiles($profiles);

        return $userIds
            ->map(function (int|string $userId) use ($profiles, $accessRows, $users, $technicianLinks): ?array {
                $user = $users->get($userId);
                if (! $user) {
                    return null;
                }

                $profile = $profiles->get($userId);
                $link = $profile ? $technicianLinks->get($this->technicianLinkKey($profile)) : null;

                return [
                    ...$this->userPayload($user),
                    'profile_title' => $profile?->title,
                    'profile_phone' => $profile?->phone,
                    'profile_active' => $profile ? (bool) $profile->active : false,
                    'last_seen_at' => $profile?->last_seen_at?->toIso8601String(),
                    'technical_service_technician_id' => $this->profileTechnicianId($profile),
                    'linked_technician' => $this->technicianPayload($link),
                    'technician_mapping_valid' => $link !== null,
                    'scopes' => $this->scopesPayload($accessRows->get($userId, collect())),
                ];
            })
            ->filter()
            ->sortBy('name')
            ->values();
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  array<int, int>  $visiblePartnerIds
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    public function membershipsForUsers(Collection $users, array $visiblePartnerIds): Collection
    {
        if ($users->isEmpty() || $visiblePartnerIds === []) {
            return collect();
        }

        $profiles = B2BPartnerUserProfile::query()
            ->with('partner.activeCapabilities')
            ->whereIn('user_id', $users->pluck('id'))
            ->whereIn('partner_id', $visiblePartnerIds)
            ->get();
        $accessRows = B2BPartnerUserAccess::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->whereIn('partner_id', $visiblePartnerIds)
            ->get()
            ->groupBy(fn (B2BPartnerUserAccess $access): string => $this->membershipKey($access->user_id, $access->partner_id));
        $technicianLinks = $this->technicianLinksForProfiles($profiles);

        return $profiles
            ->filter(fn (B2BPartnerUserProfile $profile): bool => $profile->partner !== null)
            ->groupBy('user_id')
            ->map(fn (Collection $userProfiles): Collection => $userProfiles
                ->map(function (B2BPartnerUserProfile $profile) use ($accessRows, $technicianLinks): array {
                    $partner = $profile->partner;
                    $link = $technicianLinks->get($this->technicianLinkKey($profile));

                    return [
                        'id' => $profile->id,
                        'partner_id' => $profile->partner_id,
                        'partner_code' => $partner->partner_code,
                        'partner_name' => $partner->display_name,
                        'active' => (bool) $profile->active,
                        'partner_active' => (bool) $partner->active,
                        'title' => $profile->title,
                        'phone' => $profile->phone,
                        'capabilities' => $partner->activeCapabilities
                            ->pluck('capability')
                            ->unique()
                            ->sort()
                            ->values(),
                        'technical_service_technician_id' => $this->profileTechnicianId($profile),
                        'linked_technician' => $this->technicianPayload($link),
                        'technician_mapping_valid' => $link !== null,
                        'scopes' => $this->scopesPayload($accessRows->get(
                            $this->membershipKey($profile->user_id, $profile->partner_id),
                            collect(),
                        )),
                    ];
                })
                ->sortBy('partner_name')
                ->values());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(
        B2BPartner $partner,
        User $targetUser,
        array $data,
        Request $request,
        User $actor,
        string $action,
    ): void {
        DB::transaction(function () use ($partner, $targetUser, $data, $request, $actor, $action): void {
            $before = $this->combinedSnapshot($partner, $targetUser);
            $profile = $this->upsertProfile($partner, $targetUser, $data);
            $this->syncScopes($partner, $targetUser, $data['scopes'] ?? [], $actor);
            $after = $this->combinedSnapshot($partner, $targetUser, $profile->fresh());

            $this->writeAuditLog($partner, $request, $action, $before, $after, $actor->id, $targetUser->id);
            $this->writeProfileAuditIfChanged($partner, $request, $before, $after, $actor->id, $targetUser->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertProfile(B2BPartner $partner, User $targetUser, array $data): B2BPartnerUserProfile
    {
        $profile = B2BPartnerUserProfile::query()->firstOrNew([
            'partner_id' => $partner->id,
            'user_id' => $targetUser->id,
        ]);
        $metadata = is_array($profile->metadata) ? $profile->metadata : [];

        if (array_key_exists('technical_service_technician_id', $data)) {
            if ($data['technical_service_technician_id'] === null) {
                unset($metadata['technical_service_technician_id']);
            } else {
                $metadata['technical_service_technician_id'] = (int) $data['technical_service_technician_id'];
            }
        }

        $profile->fill([
            'title' => $this->nullableString($data['title'] ?? null),
            'phone' => $this->nullableString($data['phone'] ?? null),
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
            'metadata' => $metadata,
        ])->save();

        return $profile;
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
            ->update(array_fill_keys(self::ABILITY_COLUMNS, false));
    }

    /**
     * @param  Collection<int, B2BPartnerUserAccess>  $accessRows
     * @return array<string, array<string, bool>>
     */
    private function scopesPayload(Collection $accessRows): array
    {
        $payload = [];

        foreach ($accessRows as $access) {
            $payload[$access->access_scope] = collect(self::ABILITY_COLUMNS)
                ->mapWithKeys(fn (string $column): array => [$column => (bool) $access->{$column}])
                ->all();
        }

        return $payload;
    }

    /**
     * @param  Collection<int, B2BPartnerUserProfile>  $profiles
     * @return Collection<string, B2BPartnerTechnician>
     */
    private function technicianLinksForProfiles(Collection $profiles): Collection
    {
        $technicianIds = $profiles
            ->map(fn (B2BPartnerUserProfile $profile): ?int => $this->profileTechnicianId($profile))
            ->filter()
            ->unique()
            ->values();

        if ($technicianIds->isEmpty()) {
            return collect();
        }

        return B2BPartnerTechnician::query()
            ->with('technician')
            ->whereIn('partner_id', $profiles->pluck('partner_id')->unique())
            ->whereIn('technical_service_technician_id', $technicianIds)
            ->where('active', true)
            ->whereHas('technician', fn ($query) => $query->where('active', true))
            ->get()
            ->keyBy(fn (B2BPartnerTechnician $link): string => $this->membershipKey(
                $link->partner_id,
                $link->technical_service_technician_id,
            ));
    }

    private function technicianLinkKey(B2BPartnerUserProfile $profile): string
    {
        return $this->membershipKey($profile->partner_id, $this->profileTechnicianId($profile) ?? 0);
    }

    private function membershipKey(int|string $left, int|string $right): string
    {
        return $left.':'.$right;
    }

    private function profileTechnicianId(?B2BPartnerUserProfile $profile): ?int
    {
        $value = $profile?->metadata['technical_service_technician_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function technicianPayload(?B2BPartnerTechnician $link): ?array
    {
        $technician = $link?->technician;
        if (! $link || ! $technician) {
            return null;
        }

        return [
            'link_id' => $link->id,
            'id' => $technician->id,
            'name' => $technician->display_name ?? $technician->name,
            'phone' => $technician->phone,
            'active' => (bool) $technician->active,
            'relationship_type' => $link->relationship_type,
            'is_primary' => (bool) $link->is_primary,
        ];
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
                'technical_service_technician_id' => $this->profileTechnicianId($profile),
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
            'new_values' => [...$newValues, 'acting_user_id' => $actorId],
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

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
