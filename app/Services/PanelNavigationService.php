<?php

namespace App\Services;

use App\Models\Button;
use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use App\Support\PartnerPortalPublicUrl;
use Illuminate\Support\Collection;

class PanelNavigationService
{
    /**
     * @var list<string>
     */
    private array $routePriority = [
        '/sales/main',
        '/sales/online',
        '/sales/bayi',
        '/technical-service',
        '/technical-service/dashboard',
        '/technical-service/serial-query',
        '/technical-service/qr-products',
        '/technical-service/technicians',
        '/stock',
        '/orders/alinan',
        '/orders/verilen',
        '/orders',
        '/cari',
        '/proforma',
        '/admin',
        '/dashboard',
    ];

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
                'resources' => [],
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
            'resources' => $this->access->resourceCodesFor($user)->sort()->values()->all(),
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

        if ($this->access->userCanAccess($user, 'dashboard')) {
            return '/dashboard';
        }

        if ($this->access->userCanAccess($user, 'partner.dashboard.view')) {
            return '/partner/dashboard';
        }

        return $this->firstAccessibleRouteFor($user) ?? '/dashboard';
    }

    public function firstAccessibleRouteFor(User $user): ?string
    {
        $visibleRoutes = $this->visiblePagesFor($user)
            ->map(fn (Page $page) => $this->normalizePath($page->route))
            ->flip();

        foreach ($this->routePriorityFor($user) as $route) {
            if ($visibleRoutes->has($this->normalizePath($route))) {
                return $route;
            }
        }

        return $visibleRoutes->keys()->first();
    }

    /**
     * @return list<string>
     */
    private function routePriorityFor(User $user): array
    {
        if ($user->role_code !== 'technical') {
            return $this->routePriority;
        }

        $technicalRoutes = [
            '/technical-service',
            '/technical-service/dashboard',
            '/technical-service/serial-query',
            '/technical-service/qr-products',
            '/technical-service/technicians',
        ];

        return array_values(array_unique([
            ...$technicalRoutes,
            ...$this->routePriority,
        ]));
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
            'layoutType' => $page->layout_type ?? 'module',
            'description' => $page->description,
            'icon' => $page->icon,
            'heroEyebrow' => $page->pageConfig?->layout_json['heroEyebrow'] ?? null,
            'previewNotice' => $page->pageConfig?->layout_json['previewNotice'] ?? null,
            'moduleTabs' => $this->filterNavigableItems($page->pageConfig?->layout_json['moduleTabs'] ?? [], $user),
            'buttons' => $page->buttons
                ->filter(fn (Button $button) => $button->is_visible)
                ->filter(fn (Button $button) => $this->userCanAccessButton($button, $user))
                ->map(function (Button $button) use ($user) {
                    $resourceCode = $this->buttonResourceCode($button);

                    return [
                        'id' => $button->id,
                        'label' => $button->label,
                        'slug' => $button->code,
                        'variant' => $button->variant,
                        'actionType' => $button->action_type,
                        'actionTarget' => $button->action_target,
                        'position' => $button->position ?? 'page_top',
                        'confirmationRequired' => (bool) ($button->confirmation_required ?? false),
                        'confirmationText' => $button->confirmation_text,
                        'canExecute' => $resourceCode === null || $this->access->userCanAccess($user, $resourceCode),
                        'icon' => null,
                    ];
                })
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
            ->filter(fn (Page $page) => $this->access->userCanAccess($user, $page->resource_code ?? $page->code))
            ->filter(fn (Page $page) => ! in_array($page->code, ['stock', 'stock_critical'], true) || $this->access->stockScopeFor($user) !== null)
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
                            'title' => $this->navigationItemTitle($page->route, $item->label ?: $page->name),
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

    private function navigationItemTitle(string $route, string $title): string
    {
        return $this->normalizePath($route) === '/technical-service/dashboard'
            ? 'Operasyon Dashboard — Pilot'
            : $title;
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
            'host' => PartnerPortalPublicUrl::panelHost(),
            'publicUrl' => PartnerPortalPublicUrl::panelBaseUrl(),
            'apiBaseUrl' => PartnerPortalPublicUrl::panelApiBaseUrl(),
            'webhookBaseUrl' => PartnerPortalPublicUrl::panelWebhookBaseUrl(),
            'workflowUrls' => config('panel.workflow_urls'),
            'generatedAt' => now()->toIso8601String(),
        ];
    }

    private function userCanAccessButton(Button $button, ?User $user): bool
    {
        $resourceCode = $this->buttonResourceCode($button);

        return $resourceCode === null || $this->access->userCanAccess($user, $resourceCode);
    }

    private function buttonResourceCode(Button $button): ?string
    {
        $resourceCode = trim((string) ($button->resource_code ?? ''));

        if ($resourceCode !== '') {
            return $resourceCode;
        }

        if ($button->action_type !== 'navigate') {
            return null;
        }

        $targetPage = $this->targetPageFor($button->action_target);

        return $targetPage?->resource_code ?? $targetPage?->code;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, mixed>
     */
    private function filterNavigableItems(array $items, ?User $user): array
    {
        return collect($items)
            ->filter(function (mixed $item) use ($user): bool {
                if (! is_array($item) || ! array_key_exists('href', $item)) {
                    return true;
                }

                return $this->userCanAccessRoute($user, (string) ($item['href'] ?? ''));
            })
            ->values()
            ->all();
    }

    private function userCanAccessRoute(?User $user, ?string $target): bool
    {
        $targetPage = $this->targetPageFor($target);

        if (! $targetPage) {
            return true;
        }

        return $this->access->userCanAccess($user, $targetPage->resource_code ?? $targetPage->code);
    }

    private function targetPageFor(?string $target): ?Page
    {
        if (! $target) {
            return null;
        }

        $path = parse_url($target, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $target;

        return Page::query()
            ->where('route', $this->normalizePath($path))
            ->where('active', true)
            ->first();
    }

    private function normalizePath(?string $path): string
    {
        if (! $path || $path === '/') {
            return '/dashboard';
        }

        return '/'.trim($path, '/');
    }
}
