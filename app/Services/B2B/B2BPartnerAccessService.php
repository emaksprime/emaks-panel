<?php

namespace App\Services\B2B;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerUserAccess;
use App\Models\User;
use App\Services\PanelAccessService;
use Illuminate\Database\Eloquent\Builder;

class B2BPartnerAccessService
{
    public function __construct(
        private readonly PanelAccessService $panelAccess,
    ) {}

    public function canViewPartner(User $user, B2BPartner $partner): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return B2BPartnerUserAccess::query()
            ->where('user_id', $user->id)
            ->where('partner_id', $partner->id)
            ->where('can_view', true)
            ->exists();
    }

    public function canManagePartner(User $user, B2BPartner $partner): bool
    {
        return $this->canAccessScope($user, $partner, 'manage', 'update');
    }

    public function canCreatePartner(User $user, string $partnerType): bool
    {
        return $this->canManagePartnerType($user, $partnerType);
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    public function canManageCapabilities(User $user, array $capabilities): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if ($this->panelAccess->userCanAccess($user, 'b2b.manage')) {
            return true;
        }

        $capabilities = collect($capabilities)
            ->filter()
            ->unique()
            ->values();

        if ($capabilities->isEmpty()) {
            return false;
        }

        return $capabilities->every(fn (string $capability): bool => $this->canManagePartnerType($user, $capability));
    }

    public function canUpdatePartner(User $user, B2BPartner $partner): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $this->canManageCapabilities($user, $partner->capabilityCodes())
            && $this->canManagePartner($user, $partner);
    }

    public function canManagePartnerType(User $user, string $partnerType): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if ($this->panelAccess->userCanAccess($user, 'b2b.manage')) {
            return true;
        }

        return match ($partnerType) {
            B2BPartner::TYPE_DEALER => $this->panelAccess->userCanAccess($user, 'b2b.dealers.manage'),
            B2BPartner::TYPE_LOCKSMITH => $this->panelAccess->userCanAccess($user, 'b2b.locksmiths.manage'),
            default => false,
        };
    }

    public function canManagePartnerUsers(User $user, B2BPartner $partner): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $hasPanelAccess = $this->panelAccess->userCanAccess($user, 'b2b.view')
            || $this->panelAccess->userCanAccess($user, 'b2b.manage')
            || $this->panelAccess->userCanAccess($user, 'b2b.partner_users.manage');

        if (! $hasPanelAccess) {
            return false;
        }

        return $this->canAccessScope($user, $partner, 'users', 'update')
            || $this->canAccessScope($user, $partner, 'manage', 'update');
    }

    public function canSearchPanelUsers(User $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $this->panelAccess->userCanAccess($user, 'b2b.manage')
            || $this->panelAccess->userCanAccess($user, 'b2b.partner_users.manage');
    }

    public function visiblePartnerQuery(User $user, ?string $partnerType = null): Builder
    {
        $query = B2BPartner::query()->with(['technician', 'capabilities']);

        if ($partnerType !== null && trim($partnerType) !== '') {
            $query->whereHas('activeCapabilities', function (Builder $query) use ($partnerType): void {
                $query->where('capability', $partnerType);
            });
        }

        if ($this->isSuperAdmin($user)) {
            return $query;
        }

        return $query->whereHas('access', function (Builder $query) use ($user): void {
            $query->where('user_id', $user->id)
                ->where('can_view', true);
        });
    }

    public function visibleDealerQuery(User $user): Builder
    {
        return $this->visiblePartnerQuery($user, B2BPartner::TYPE_DEALER);
    }

    public function visibleLocksmithQuery(User $user): Builder
    {
        return $this->visiblePartnerQuery($user, B2BPartner::TYPE_LOCKSMITH);
    }

    public function canAccessScope(User $user, B2BPartner $partner, string $scope, string $ability = 'view'): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $column = $this->abilityColumn($ability);

        if ($column === null) {
            return false;
        }

        return B2BPartnerUserAccess::query()
            ->where('user_id', $user->id)
            ->where('partner_id', $partner->id)
            ->where('access_scope', $scope)
            ->where($column, true)
            ->exists();
    }

    private function isSuperAdmin(User $user): bool
    {
        $user->loadMissing('role');

        return (bool) $user->role?->is_super_admin;
    }

    private function abilityColumn(string $ability): ?string
    {
        return match ($ability) {
            'view' => 'can_view',
            'create' => 'can_create',
            'update', 'manage' => 'can_update',
            'approve' => 'can_approve',
            default => null,
        };
    }
}
