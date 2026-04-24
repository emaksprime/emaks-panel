<?php

namespace App\Services;

use App\Models\Button;
use App\Models\DataSource;
use App\Models\Page;
use App\Models\RoleResourcePermission;
use App\Models\User;
use App\Models\UserAccess;
use Illuminate\Support\Collection;

class PanelAccessService
{
    public function userCanAccess(User|int|null $user, string $resourceCode): bool
    {
        $resolvedUser = $user instanceof User
            ? $user
            : ($user ? User::query()->with('role')->find($user) : null);

        if (! $resolvedUser || ! $resolvedUser->aktif) {
            return false;
        }

        if ($resolvedUser->role?->is_super_admin) {
            return true;
        }

        $roleCanAccess = RoleResourcePermission::query()
            ->where('role_code', $resolvedUser->role_code)
            ->where('resource_code', $resourceCode)
            ->where('can_view', true)
            ->exists();

        if ($roleCanAccess) {
            return true;
        }

        return UserAccess::query()
            ->where('user_id', $resolvedUser->id)
            ->where('resource_code', $resourceCode)
            ->exists();
    }

    /**
     * @return Collection<int, string>
     */
    public function resourceCodesFor(?User $user): Collection
    {
        if (! $user || ! $user->aktif) {
            return collect();
        }

        if ($user->role?->is_super_admin) {
            return collect()
                ->merge(Page::query()->where('active', true)->pluck('code'))
                ->merge(Button::query()->where('is_visible', true)->whereNotNull('resource_code')->pluck('resource_code'))
                ->merge(DataSource::query()->where('active', true)->pluck('code'))
                ->filter()
                ->unique()
                ->values();
        }

        return collect()
            ->merge(RoleResourcePermission::query()
                ->where('role_code', $user->role_code)
                ->where('can_view', true)
                ->pluck('resource_code'))
            ->merge(UserAccess::query()
            ->where('user_id', $user->id)
            ->pluck('resource_code'))
            ->filter()
            ->unique()
            ->values();
    }

    public function isPrivileged(?User $user): bool
    {
        return (bool) ($user?->role?->is_super_admin);
    }
}
