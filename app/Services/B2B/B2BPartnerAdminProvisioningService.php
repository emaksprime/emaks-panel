<?php

namespace App\Services\B2B;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerAuditLog;
use App\Models\B2B\B2BPartnerUserAccess;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class B2BPartnerAdminProvisioningService
{
    private const DEFAULT_PASSWORD = '12345678';

    private const PORTAL_ADMIN_ROLES = [
        'b2b_dealer',
        'b2b_locksmith',
        'b2b_manufacturer',
        'b2b_seller',
    ];

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function provisionForPartner(B2BPartner $partner, array $options = []): array
    {
        $force = (bool) ($options['force'] ?? false);
        $showDefaultPassword = (bool) ($options['show_default_password'] ?? true);
        $actor = $options['actor'] ?? null;
        $request = $options['request'] ?? null;

        if (! $partner->active && ! $force) {
            return [
                'created' => false,
                'linked' => false,
                'skipped' => true,
                'status' => 'skipped_inactive_partner',
                'partner_id' => $partner->id,
            ];
        }

        return DB::transaction(function () use ($partner, $actor, $request, $showDefaultPassword): array {
            $partner->loadMissing(['capabilities', 'profiles.user']);
            $roleCode = $this->resolveRoleForPartner($partner);
            $existingProfile = $this->activePortalAdminProfile($partner);
            $created = false;
            $linked = false;

            if ($existingProfile?->user) {
                $user = $existingProfile->user;
                $linked = true;
                $this->syncPartnerAdminAccess($user, $partner, $actor instanceof User ? $actor : null);
                $this->writeAudit($partner, $request, 'b2b.partner.admin_user_access_synced', $actor, User::class, $user->id, null, [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'role_code' => $user->role_code,
                    'scopes' => $this->defaultAccessRowsForPartner($partner),
                ]);

                return $this->resultPayload($partner, $user, $created, $linked, 'already_linked', false, $showDefaultPassword);
            }

            $username = $this->uniqueUsername($this->buildUsername($partner));
            $user = User::query()->create([
                'username' => $username,
                'full_name' => Str::limit((string) ($partner->display_name ?: $partner->mikro_cari_unvan ?: $username), 120, ''),
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'role_code' => $roleCode,
                'aktif' => true,
                'force_password_change' => true,
            ]);
            $created = true;

            $this->syncPartnerAdminAccess($user, $partner, $actor instanceof User ? $actor : null);
            $this->writeAudit($partner, $request, 'b2b.partner.admin_user_created', $actor, User::class, $user->id, null, [
                'user_id' => $user->id,
                'username' => $user->username,
                'role_code' => $user->role_code,
                'default_password_assigned' => true,
                'password_reset_required' => true,
            ]);
            $this->writeAudit($partner, $request, 'b2b.partner_user.assigned', $actor, User::class, $user->id, null, [
                'user_id' => $user->id,
                'partner_id' => $partner->id,
                'username' => $user->username,
                'role_code' => $user->role_code,
                'portal_admin' => true,
                'scopes' => $this->defaultAccessRowsForPartner($partner),
            ]);

            return $this->resultPayload($partner, $user, $created, $linked, 'created', true, $showDefaultPassword);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function provisionForAllActivePartners(array $filters = []): array
    {
        $partnerIds = array_values(array_filter($filters['partner_ids'] ?? []));
        $capabilities = array_values(array_filter($filters['capabilities'] ?? []));
        $activeOnly = (bool) ($filters['active_only'] ?? true);
        $onlyWithoutUsers = (bool) ($filters['only_without_users'] ?? true);
        $actor = $filters['actor'] ?? null;
        $request = $filters['request'] ?? null;
        $showDefaultPassword = (bool) ($filters['show_default_password'] ?? true);

        $query = B2BPartner::query()->with(['capabilities', 'profiles.user']);

        if ($activeOnly) {
            $query->where('active', true);
        }

        if ($partnerIds !== []) {
            $query->whereIn('id', $partnerIds);
        }

        if ($capabilities !== []) {
            $query->whereHas('activeCapabilities', fn ($query) => $query->whereIn('capability', $capabilities));
        }

        $results = $query
            ->orderBy('display_name')
            ->get()
            ->map(function (B2BPartner $partner) use ($actor, $request, $showDefaultPassword, $onlyWithoutUsers): array {
                try {
                    $existingProfile = $this->activePortalAdminProfile($partner);

                    if ($onlyWithoutUsers && $existingProfile?->user) {
                        return [
                            ...$this->resultPayload($partner, $existingProfile->user, false, true, 'already_linked', false, $showDefaultPassword),
                            'failed' => false,
                        ];
                    }

                    return [
                        ...$this->provisionForPartner($partner, [
                            'actor' => $actor,
                            'request' => $request,
                            'show_default_password' => $showDefaultPassword,
                        ]),
                        'failed' => false,
                    ];
                } catch (\Throwable $exception) {
                    return [
                        'partner_id' => $partner->id,
                        'partner_name' => $partner->display_name,
                        'created' => false,
                        'linked' => false,
                        'failed' => true,
                        'status' => 'failed',
                        'message' => $exception->getMessage(),
                    ];
                }
            })
            ->values();

        return [
            'total' => $results->count(),
            'created' => $results->where('created', true)->count(),
            'linked' => $results->where('linked', true)->count(),
            'skipped_existing' => $results->where('status', 'already_linked')->count(),
            'failed' => $results->where('failed', true)->count(),
            'results' => $results->all(),
        ];
    }

    public function buildUsername(B2BPartner $partner): string
    {
        $sourceName = strtolower(Str::ascii((string) ($partner->display_name ?: $partner->mikro_cari_unvan ?: 'partner')));
        $namePrefix = substr(preg_replace('/[^a-z0-9]/', '', $sourceName) ?: 'partn', 0, 5);
        $digits = preg_replace('/\D/', '', (string) $partner->mikro_cari_kodu) ?: '000';

        return $namePrefix.substr($digits, 0, 3);
    }

    public function resolveRoleForPartner(B2BPartner $partner): string
    {
        $capabilities = $partner->capabilityCodes();

        if (in_array(B2BPartner::TYPE_DEALER, $capabilities, true)) {
            return 'b2b_dealer';
        }

        if (in_array(B2BPartner::TYPE_LOCKSMITH, $capabilities, true)) {
            return 'b2b_locksmith';
        }

        if (in_array(B2BPartner::TYPE_MANUFACTURER, $capabilities, true)) {
            return 'b2b_manufacturer';
        }

        if (in_array(B2BPartner::TYPE_SELLER, $capabilities, true)) {
            return 'b2b_seller';
        }

        return 'b2b_dealer';
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function syncPartnerAdminAccess(User $user, B2BPartner $partner, ?User $actor = null): array
    {
        B2BPartnerUserProfile::query()->updateOrCreate(
            [
                'partner_id' => $partner->id,
                'user_id' => $user->id,
            ],
            [
                'title' => 'Partner portal admin',
                'phone' => $partner->phone,
                'active' => true,
                'metadata' => [
                    'portal_admin' => true,
                    'password_reset_required' => true,
                    'source' => 'b2b_partner_admin_provisioning',
                ],
            ],
        );

        $rows = $this->defaultAccessRowsForPartner($partner);

        foreach ($rows as $scope => $attributes) {
            B2BPartnerUserAccess::query()->updateOrCreate(
                [
                    'partner_id' => $partner->id,
                    'user_id' => $user->id,
                    'access_scope' => $scope,
                ],
                [
                    ...$attributes,
                    'created_by' => $actor?->id,
                ],
            );
        }

        return $rows;
    }

    public function activePortalAdminProfile(B2BPartner $partner): ?B2BPartnerUserProfile
    {
        return B2BPartnerUserProfile::query()
            ->with('user')
            ->where('partner_id', $partner->id)
            ->where('active', true)
            ->whereHas('user', fn ($query) => $query
                ->where('aktif', true)
                ->whereIn('role_code', self::PORTAL_ADMIN_ROLES))
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function portalAdminSummaries(B2BPartner $partner): array
    {
        return B2BPartnerUserProfile::query()
            ->with('user')
            ->where('partner_id', $partner->id)
            ->where('active', true)
            ->whereHas('user', fn ($query) => $query
                ->where('aktif', true)
                ->whereIn('role_code', self::PORTAL_ADMIN_ROLES))
            ->orderBy('id')
            ->get()
            ->map(fn (B2BPartnerUserProfile $profile): array => [
                'user_id' => $profile->user_id,
                'username' => $profile->user?->username,
                'name' => $profile->user?->name,
                'role_code' => $profile->user?->role_code,
                'active' => (bool) $profile->active,
                'portal_admin' => (bool) ($profile->metadata['portal_admin'] ?? false),
            ])
            ->values()
            ->all();
    }

    private function uniqueUsername(string $base): string
    {
        $username = $base;
        $counter = 2;

        while (User::query()->where('username', $username)->exists()) {
            $username = $base.$counter;
            $counter++;
        }

        return $username;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function defaultAccessRowsForPartner(B2BPartner $partner): array
    {
        $capabilities = $partner->capabilityCodes();
        $hasDealerLike = collect($capabilities)->intersect([
            B2BPartner::TYPE_DEALER,
            B2BPartner::TYPE_MANUFACTURER,
            B2BPartner::TYPE_SELLER,
        ])->isNotEmpty();
        $hasLocksmith = in_array(B2BPartner::TYPE_LOCKSMITH, $capabilities, true);

        $rows = $this->blankAccessRows();
        $rows['view']['can_view'] = true;

        if ($hasDealerLike) {
            $rows['orders'] = [
                'can_view' => true,
                'can_create' => true,
                'can_update' => true,
                'can_approve' => false,
            ];
            $rows['stock']['can_view'] = true;
            $rows['users'] = [
                'can_view' => true,
                'can_create' => true,
                'can_update' => true,
                'can_approve' => false,
            ];
        }

        if ($hasLocksmith) {
            $rows['technical_service']['can_view'] = true;
            $rows['users']['can_view'] = true;

            if ($hasDealerLike) {
                $rows['users']['can_update'] = true;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function blankAccessRows(): array
    {
        return collect(['view', 'manage', 'orders', 'stock', 'finance', 'technical_service', 'users'])
            ->mapWithKeys(fn (string $scope): array => [$scope => [
                'can_view' => false,
                'can_create' => false,
                'can_update' => false,
                'can_approve' => false,
            ]])
            ->all();
    }

    private function resultPayload(
        B2BPartner $partner,
        User $user,
        bool $created,
        bool $linked,
        string $status,
        bool $passwordCreated,
        bool $showDefaultPassword,
    ): array {
        $scopes = $this->defaultAccessRowsForPartner($partner);

        return [
            'created' => $created,
            'linked' => $linked,
            'skipped' => false,
            'status' => $status,
            'user_id' => $user->id,
            'username' => $user->username,
            'role_code' => $user->role_code,
            'partner_id' => $partner->id,
            'partner_name' => $partner->display_name,
            'default_password' => $passwordCreated && $showDefaultPassword && app()->environment(['local', 'testing'])
                ? self::DEFAULT_PASSWORD
                : null,
            'scopes' => $scopes,
        ];
    }

    private function writeAudit(
        B2BPartner $partner,
        ?Request $request,
        string $action,
        mixed $actor,
        ?string $subjectType,
        int|string|null $subjectId,
        ?array $oldValues,
        array $newValues,
    ): void {
        B2BPartnerAuditLog::query()->create([
            'partner_id' => $partner->id,
            'user_id' => $actor instanceof User ? $actor->id : null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'old_values' => $oldValues,
            'new_values' => [
                ...$newValues,
                'acting_user_id' => $actor instanceof User ? $actor->id : null,
            ],
            'ip' => $request?->ip(),
            'user_agent' => $request ? (string) $request->userAgent() : null,
        ]);
    }
}
