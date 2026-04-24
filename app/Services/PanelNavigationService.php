<?php

namespace App\Services;

use App\Models\Button;
use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

class PanelNavigationService
{
    public function __construct(
        private readonly PanelAccessService $access,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sharedForUser(?User $user, ?string $requestPath = null): array
    {
        if (! $user) {
            return [
                'groups' => [],
                'currentPage' => null,
                'role' => null,
                'meta' => $this->meta(),
            ];
        }

        $pages = $this->visiblePagesFor($user);
        $currentPage = $pages->first(
            fn (Page $page) => $this->normalizePath($page->route) === $this->normalizePath($requestPath),
        );

        return [
            'groups' => $this->navigationGroups($pages),
            'currentPage' => $currentPage ? $this->pagePayload($currentPage, $user) : null,
            'role' => $this->rolePayload($user->role),
            'meta' => $this->meta(),
        ];
    }

    public function homePathFor(?User $user): string
    {
        if (! $user) {
            return route('login');
        }

        return $this->visiblePagesFor($user)->first()?->route ?? '/dashboard';
    }

    public function resolveVisiblePage(?User $user, string $path): ?Page
    {
        if (! $user) {
            return null;
        }

        return $this->visiblePagesFor($user)
            ->first(fn (Page $page) => $this->normalizePath($page->route) === $this->normalizePath($path));
    }

    public function grantedResourceCountFor(?User $user): int
    {
        return $this->access->resourceCodesFor($user)->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function pagePayload(Page $page, ?User $user): array
    {
        $page->loadMissing('pageConfig.dataSource');

        return [
            'id' => $page->id,
            'title' => $page->name,
            'slug' => $page->code,
            'routePath' => $page->route,
            'component' => $page->component,
            'description' => $page->description,
            'icon' => $page->icon,
            'heroEyebrow' => $page->pageConfig?->layout_json['heroEyebrow'] ?? null,
            'buttons' => $page->buttons
                ->filter(fn (Button $button) => $button->is_visible)
                ->filter(fn (Button $button) => $button->resource_code === null || $this->access->userCanAccess($user, $button->resource_code))
                ->map(fn (Button $button) => [
                    'id' => $button->id,
                    'label' => $button->label,
                    'slug' => $button->code,
                    'variant' => $button->variant,
                    'actionType' => $button->action_type,
                    'actionTarget' => $button->action_target,
                    'canExecute' => $button->resource_code === null || $this->access->userCanAccess($user, $button->resource_code),
                    'icon' => null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, Page>
     */
    private function visiblePagesFor(User $user): Collection
    {
        return Page::query()
            ->with([
                'resource',
                'buttons.resource',
                'menuItems.menuGroup',
                'pageConfig.dataSource',
            ])
            ->where('active', true)
            ->orderBy('page_order')
            ->get()
            ->filter(fn (Page $page) => $this->access->userCanAccess($user, $page->code))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function navigationGroups(Collection $pages): Collection
    {
        return $pages
            ->flatMap(function (Page $page) {
                return $page->menuItems
                    ->filter(fn ($item) => $item->is_visible && $item->menuGroup?->active)
                    ->map(fn ($item) => [
                        'groupId' => $item->menuGroup->id,
                        'groupTitle' => $item->menuGroup->name,
                        'groupSlug' => $item->menuGroup->code,
                        'groupIcon' => $item->menuGroup->icon,
                        'groupOrder' => $item->menuGroup->menu_order,
                        'pageOrder' => $item->sort_order,
                        'item' => [
                            'id' => $page->id,
                            'title' => $item->label ?: $page->name,
                            'href' => $page->route,
                            'icon' => $item->icon ?: $page->icon,
                        ],
                    ]);
            })
            ->groupBy('groupId')
            ->map(function (Collection $items) {
                $first = $items->first();

                return [
                    'id' => $first['groupId'],
                    'title' => $first['groupTitle'],
                    'slug' => $first['groupSlug'],
                    'icon' => $first['groupIcon'],
                    'order' => $first['groupOrder'],
                    'items' => $items
                        ->sortBy('pageOrder')
                        ->pluck('item')
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy('order')
            ->values();
    }

    /**
     * @return array{name: string, slug: string, isSuperAdmin: bool}|null
     */
    private function rolePayload(?Role $role): ?array
    {
        if (! $role) {
            return null;
        }

        return [
            'name' => $role->name,
            'slug' => $role->code,
            'isSuperAdmin' => $role->is_super_admin,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'brand' => config('panel.brand'),
            'environment' => app()->environment(),
            'host' => parse_url((string) config('app.url'), PHP_URL_HOST),
            'generatedAt' => now()->toIso8601String(),
        ];
    }

    private function normalizePath(?string $path): string
    {
        if (! $path || $path === '/') {
            return '/dashboard';
        }

        return '/'.trim($path, '/');
    }
}
