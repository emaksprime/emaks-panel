<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\B2B\B2BPartner;
use App\Models\Button;
use App\Models\DataSource;
use App\Models\MenuGroup;
use App\Models\Page;
use App\Models\PageConfig;
use App\Models\PageMenu;
use App\Models\Resource;
use App\Models\Role;
use App\Models\RoleResourcePermission;
use App\Models\User;
use App\Models\UserAccess;
use App\Services\AuditLogger;
use App\Services\B2B\B2BPartnerAccessService;
use App\Services\PanelAccessService;
use App\Services\PanelDataSourceManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

class AdminController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PanelDataSourceManager $dataSourceManager,
        private readonly PanelAccessService $access,
        private readonly B2BPartnerAccessService $partnerAccess,
    ) {}

    public function overview(): JsonResponse
    {
        return response()->json([
            'counts' => [
                'users' => User::query()->count(),
                'pages' => Page::query()->count(),
                'datasources' => DataSource::query()->count(),
                'logs' => AuditLog::query()->count(),
            ],
            'roles' => Role::query()->orderBy('code')->get(['code', 'name', 'description', 'is_super_admin']),
            'urls' => [
                'publicUrl' => config('panel.public_url'),
                'apiBaseUrl' => config('panel.api_base_url'),
                'webhookBaseUrl' => config('panel.webhook_base_url'),
                'workflowUrls' => config('panel.workflow_urls'),
            ],
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', Rule::in(['active', 'inactive'])],
            'role_code' => ['nullable', Rule::exists(Role::class, 'code')],
            'partner_assignment' => ['nullable', Rule::in(['assigned', 'unassigned', 'multiple', 'inactive'])],
            'capabilities' => ['nullable', 'array', 'max:4'],
            'capabilities.*' => ['string', Rule::in(B2BPartner::SUPPORTED_CAPABILITIES)],
            'capability_match' => ['nullable', Rule::in(['any', 'all', 'exclude'])],
            'partner_id' => ['nullable', 'integer', Rule::exists(B2BPartner::class, 'id')],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $visiblePartners = $this->partnerAccess
            ->visiblePartnerQuery($actor)
            ->with('activeCapabilities')
            ->orderBy('display_name')
            ->get();
        $visiblePartnerIds = $visiblePartners->pluck('id')->map(fn ($id): int => (int) $id)->values();

        if (isset($filters['partner_id'])) {
            abort_unless($visiblePartnerIds->contains((int) $filters['partner_id']), 403);
        }

        $usersQuery = User::query()
            ->with([
                'role',
                'b2bPartnerProfiles' => fn ($query) => $query
                    ->whereIn('partner_id', $visiblePartnerIds)
                    ->with('partner.activeCapabilities'),
            ]);

        $this->applyAdminUserFilters($usersQuery, $filters, $visiblePartnerIds->all());

        $perPage = (int) ($filters['per_page'] ?? 100);
        $filteredTotal = (clone $usersQuery)->count();
        $lastPage = max(1, (int) ceil($filteredTotal / $perPage));
        $page = min((int) ($filters['page'] ?? 1), $lastPage);
        $users = $usersQuery
            ->orderBy('full_name')
            ->forPage($page, $perPage)
            ->get();
        $accessByUser = UserAccess::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->get(['user_id', 'resource_code', 'can_view'])
            ->groupBy('user_id');

        return response()->json([
            'users' => $users
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'full_name' => $user->full_name,
                    'role_code' => $user->role_code,
                    'temsilci_kodu' => $user->temsilci_kodu,
                    'aktif' => $user->aktif,
                    'force_password_change' => (bool) ($user->force_password_change ?? false),
                    'access' => $accessByUser->get($user->id, collect())
                        ->where('can_view', true)
                        ->pluck('resource_code')
                        ->unique()
                        ->values(),
                    'denied_access' => $accessByUser->get($user->id, collect())
                        ->where('can_view', false)
                        ->pluck('resource_code')
                        ->unique()
                        ->values(),
                    'partner_memberships' => $user->b2bPartnerProfiles
                        ->filter(fn ($profile) => $profile->partner !== null)
                        ->map(fn ($profile) => [
                            'id' => $profile->id,
                            'partner_id' => $profile->partner_id,
                            'partner_code' => $profile->partner->partner_code,
                            'partner_name' => $profile->partner->display_name,
                            'active' => (bool) $profile->active,
                            'partner_active' => (bool) $profile->partner->active,
                            'capabilities' => $profile->partner->activeCapabilities
                                ->pluck('capability')
                                ->unique()
                                ->sort()
                                ->values(),
                        ])
                        ->values(),
                ]),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'filtered_total' => $filteredTotal,
                'total' => User::query()->count(),
            ],
            'partners' => $visiblePartners->map(fn (B2BPartner $partner) => [
                'id' => $partner->id,
                'partner_code' => $partner->partner_code,
                'display_name' => $partner->display_name,
                'active' => (bool) $partner->active,
                'capabilities' => $partner->activeCapabilities
                    ->pluck('capability')
                    ->unique()
                    ->sort()
                    ->values(),
            ])->values(),
            'roles' => Role::query()->orderBy('code')->get(['code', 'name', 'description', 'is_super_admin']),
            'resources' => Resource::query()
                ->where('active', true)
                ->orderBy('type')
                ->orderBy('name')
                ->get(['code', 'name', 'type'])
                ->unique('code')
                ->map(fn (Resource $resource) => [
                    'code' => $resource->code,
                    'name' => $resource->name,
                    'type' => $resource->type,
                    'group' => $this->resourceGroupFor($resource->code, $resource->type),
                ])
                ->sortBy([['group', 'asc'], ['name', 'asc']])
                ->values(),
            'rolePermissions' => RoleResourcePermission::query()
                ->where('can_view', true)
                ->get(['role_code', 'resource_code'])
                ->groupBy('role_code')
                ->map(fn ($permissions) => $permissions->pluck('resource_code')->unique()->values())
                ->all(),
        ]);
    }

    public function saveUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', Rule::exists(User::class, 'id')],
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique(User::class, 'username')->ignore($request->integer('id')),
            ],
            'full_name' => ['required', 'string', 'max:255'],
            'password' => [$request->filled('id') ? 'nullable' : 'required', 'string', 'min:8'],
            'role_code' => ['required', Rule::exists(Role::class, 'code')],
            'temsilci_kodu' => ['nullable', 'string', 'max:32'],
            'aktif' => ['boolean'],
            'force_password_change' => ['boolean'],
            'access' => ['array'],
            'access.*' => ['string', Rule::exists(Resource::class, 'code')],
            'denied_access' => ['array'],
            'denied_access.*' => ['string', Rule::exists(Resource::class, 'code')],
            'strict_access' => ['boolean'],
        ]);

        $payload = [
            'username' => $data['username'],
            'full_name' => $data['full_name'],
            'role_code' => $data['role_code'],
            'temsilci_kodu' => $data['temsilci_kodu'] ?? null,
            'aktif' => (bool) ($data['aktif'] ?? true),
            'force_password_change' => (bool) ($data['force_password_change'] ?? false),
        ];

        if (! empty($data['password'])) {
            $payload['password_hash'] = Hash::make($data['password']);
        }

        $user = isset($data['id'])
            ? tap(User::query()->findOrFail($data['id']))->update($payload)
            : User::query()->create($payload);

        $role = Role::query()->where('code', $data['role_code'])->first();
        $denied = collect($data['denied_access'] ?? [])->filter()->unique()->values();
        $allowed = collect($data['access'] ?? [])->filter()->unique()->values();

        if ((bool) ($data['strict_access'] ?? false) && ! (bool) ($role?->is_super_admin ?? false)) {
            $allowed = $allowed
                ->push('dashboard')
                ->filter()
                ->unique()
                ->values();

            $denied = Resource::query()
                ->where('active', true)
                ->pluck('code')
                ->diff($allowed)
                ->values();
        }

        $allowed = $allowed->diff($denied)->values();

        UserAccess::query()->where('user_id', $user->id)->delete();
        foreach ($allowed as $resourceCode) {
            UserAccess::query()->updateOrCreate([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
            ], [
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
                'can_view' => true,
            ]);
        }

        foreach ($denied as $resourceCode) {
            UserAccess::query()->updateOrCreate([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
            ], [
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
                'can_view' => false,
            ]);
        }

        $this->auditLogger->log($request->user(), 'admin.user.save', ['target_user_id' => $user->id], $request);

        return $this->users($request);
    }

    public function cloneUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255', Rule::unique(User::class, 'username')],
            'full_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'temsilci_kodu' => ['nullable', 'string', 'max:32'],
            'aktif' => ['boolean'],
            'force_password_change' => ['boolean'],
            'strict_access' => ['boolean'],
        ]);

        DB::transaction(function () use ($data, $request, $user): void {
            $clonedUser = User::query()->create([
                'username' => $data['username'],
                'full_name' => $data['full_name'],
                'password_hash' => Hash::make($data['password']),
                'role_code' => $user->role_code,
                'temsilci_kodu' => $data['temsilci_kodu'] ?? null,
                'aktif' => (bool) ($data['aktif'] ?? true),
                'force_password_change' => (bool) ($data['force_password_change'] ?? true),
            ]);

            $strictAccess = (bool) ($data['strict_access'] ?? true);
            [$allowed, $denied] = $strictAccess
                ? $this->strictAccessSnapshotForClone($user)
                : $this->explicitAccessSnapshotForClone($user);

            $this->syncUserAccess($clonedUser, $allowed, $denied);

            $this->auditLogger->log($request->user(), 'admin.user.clone', [
                'source_user_id' => $user->id,
                'new_user_id' => $clonedUser->id,
                'strict_access' => $strictAccess,
            ], $request);
        });

        return $this->users($request);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, int>  $visiblePartnerIds
     */
    private function applyAdminUserFilters(Builder $query, array $filters, array $visiblePartnerIds): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $casePreservingNeedle = '%'.$search.'%';

            $query->where(function (Builder $query) use ($casePreservingNeedle, $needle, $visiblePartnerIds): void {
                $query
                    ->whereRaw('LOWER(full_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(username) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(role_code) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(temsilci_kodu, \'\')) LIKE ?', [$needle])
                    ->orWhere('full_name', 'like', $casePreservingNeedle)
                    ->orWhere('username', 'like', $casePreservingNeedle);

                if ($visiblePartnerIds !== []) {
                    $query->orWhereHas('b2bPartnerProfiles', function (Builder $profiles) use ($casePreservingNeedle, $needle, $visiblePartnerIds): void {
                        $profiles
                            ->whereIn('partner_id', $visiblePartnerIds)
                            ->whereHas('partner', function (Builder $partner) use ($casePreservingNeedle, $needle): void {
                                $partner
                                    ->whereRaw('LOWER(display_name) LIKE ?', [$needle])
                                    ->orWhereRaw('LOWER(partner_code) LIKE ?', [$needle])
                                    ->orWhere('display_name', 'like', $casePreservingNeedle)
                                    ->orWhere('partner_code', 'like', $casePreservingNeedle);
                            });
                    });
                }
            });
        }

        if (($filters['active'] ?? null) === 'active') {
            $query->where('aktif', true);
        } elseif (($filters['active'] ?? null) === 'inactive') {
            $query->where('aktif', false);
        }

        if (! empty($filters['role_code'])) {
            $query->where('role_code', $filters['role_code']);
        }

        if (isset($filters['partner_id'])) {
            $partnerId = (int) $filters['partner_id'];
            $query->whereHas('b2bPartnerProfiles', fn (Builder $profiles) => $profiles->where('partner_id', $partnerId));
        }

        $partnerAssignment = $filters['partner_assignment'] ?? null;
        if ($partnerAssignment === 'assigned') {
            $query->whereHas('b2bPartnerProfiles', fn (Builder $profiles) => $profiles->whereIn('partner_id', $visiblePartnerIds));
        } elseif ($partnerAssignment === 'unassigned') {
            $query->whereDoesntHave('b2bPartnerProfiles', fn (Builder $profiles) => $profiles->whereIn('partner_id', $visiblePartnerIds));
        } elseif ($partnerAssignment === 'multiple') {
            $query->whereHas(
                'b2bPartnerProfiles',
                fn (Builder $profiles) => $profiles->whereIn('partner_id', $visiblePartnerIds),
                '>=',
                2,
            );
        } elseif ($partnerAssignment === 'inactive') {
            $query->whereHas('b2bPartnerProfiles', fn (Builder $profiles) => $profiles
                ->whereIn('partner_id', $visiblePartnerIds)
                ->where('active', false));
        }

        $capabilities = collect($filters['capabilities'] ?? [])
            ->filter(fn ($capability) => in_array($capability, B2BPartner::SUPPORTED_CAPABILITIES, true))
            ->unique()
            ->values();
        $capabilityMatch = $filters['capability_match'] ?? 'any';

        if ($capabilities->isEmpty()) {
            return;
        }

        $profileHasCapabilities = function (Builder $profiles, array $required) use ($visiblePartnerIds): void {
            $profiles
                ->whereIn('partner_id', $visiblePartnerIds)
                ->whereHas('partner.activeCapabilities', fn (Builder $capabilities) => $capabilities
                    ->whereIn('capability', $required));
        };

        if ($capabilityMatch === 'exclude') {
            $query->whereDoesntHave(
                'b2bPartnerProfiles',
                fn (Builder $profiles) => $profileHasCapabilities($profiles, $capabilities->all()),
            );

            return;
        }

        if ($capabilityMatch === 'all') {
            foreach ($capabilities as $capability) {
                $query->whereHas(
                    'b2bPartnerProfiles',
                    fn (Builder $profiles) => $profileHasCapabilities($profiles, [$capability]),
                );
            }

            return;
        }

        $query->whereHas(
            'b2bPartnerProfiles',
            fn (Builder $profiles) => $profileHasCapabilities($profiles, $capabilities->all()),
        );
    }

    /**
     * @return array{0: Collection<int, string>, 1: Collection<int, string>}
     */
    private function strictAccessSnapshotForClone(User $source): array
    {
        $activeResources = Resource::query()
            ->where('active', true)
            ->pluck('code')
            ->filter()
            ->unique()
            ->values();

        if ((bool) ($source->role?->is_super_admin ?? false)) {
            return [$activeResources, collect()];
        }

        $allowed = $activeResources
            ->filter(fn (string $resourceCode): bool => $this->access->userCanAccess($source, $resourceCode))
            ->push('dashboard')
            ->filter()
            ->unique()
            ->values();

        return [
            $allowed,
            $activeResources->diff($allowed)->values(),
        ];
    }

    /**
     * @return array{0: Collection<int, string>, 1: Collection<int, string>}
     */
    private function explicitAccessSnapshotForClone(User $source): array
    {
        $overrides = UserAccess::query()
            ->where('user_id', $source->id)
            ->orderBy('resource_code')
            ->get(['resource_code', 'can_view']);

        return [
            $overrides
                ->where('can_view', true)
                ->pluck('resource_code')
                ->filter()
                ->unique()
                ->values(),
            $overrides
                ->where('can_view', false)
                ->pluck('resource_code')
                ->filter()
                ->unique()
                ->values(),
        ];
    }

    /**
     * @param  Collection<int, string>  $allowed
     * @param  Collection<int, string>  $denied
     */
    private function syncUserAccess(User $user, $allowed, $denied): void
    {
        $denied = $denied->filter()->unique()->values();
        $allowed = $allowed->filter()->unique()->diff($denied)->values();

        UserAccess::query()->where('user_id', $user->id)->delete();

        foreach ($allowed as $resourceCode) {
            UserAccess::query()->updateOrCreate([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
            ], [
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
                'can_view' => true,
            ]);
        }

        foreach ($denied as $resourceCode) {
            UserAccess::query()->updateOrCreate([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
            ], [
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
                'can_view' => false,
            ]);
        }
    }

    private function resourceGroupFor(string $code, ?string $type): string
    {
        return match (true) {
            $code === 'data_sources' => 'Veri Kaynakları',
            str_starts_with($code, 'partner.') => 'B2B Partner Portal',
            str_starts_with($code, 'b2b') => 'B2B',
            str_starts_with($code, 'sales_') || $code === 'sales_main' => 'Satış Yönetimi',
            str_starts_with($code, 'stock') => 'Stok Yönetimi',
            str_starts_with($code, 'orders') => 'Sipariş Yönetimi',
            str_starts_with($code, 'technical_service') => 'Teknik Servis',
            str_starts_with($code, 'support') => 'Destek',
            str_starts_with($code, 'cari') || str_starts_with($code, 'customer') || $code === 'customers' || str_starts_with($code, 'finance_cari') => 'Müşteri Yönetimi',
            str_starts_with($code, 'proforma') => 'Proforma',
            str_starts_with($code, 'accounting_finance') => 'Muhasebe / Finans',
            str_starts_with($code, 'admin') || $code === 'user_admin' || $code === 'dashboard' => 'Sistem Yönetimi',
            $type === 'data_source' => 'Veri Kaynakları',
            default => 'Sistem Yönetimi',
        };
    }

    public function pages(): JsonResponse
    {
        return response()->json([
            'pages' => Page::query()
                ->with('menuItems.menuGroup')
                ->orderBy('page_order')
                ->get()
                ->map(fn (Page $page) => [
                    'id' => $page->id,
                    'code' => $page->code,
                    'name' => $page->name,
                    'route' => $page->route,
                    'icon' => $page->icon,
                    'resource_code' => $page->resource_code,
                    'component' => $page->component,
                    'layout_type' => $page->layout_type ?? 'module',
                    'description' => $page->description,
                    'page_order' => $page->page_order,
                    'active' => $page->active,
                    'menu_group_id' => $page->menuItems->first()?->menu_group_id,
                    'menu_label' => $page->menuItems->first()?->label,
                    'menu_visible' => $page->menuItems->first()?->is_visible ?? true,
                    'menu_sort_order' => $page->menuItems->first()?->sort_order ?? $page->page_order,
                ]),
            'menuGroups' => MenuGroup::query()->orderBy('menu_order')->get(),
            'resources' => Resource::query()->where('type', 'page')->orderBy('name')->get(['code', 'name']),
            'buttons' => Button::query()
                ->with('page')
                ->orderBy('page_id')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Button $button) => [
                    'id' => $button->id,
                    'page_id' => $button->page_id,
                    'page_code' => $button->page?->code,
                    'code' => $button->code,
                    'label' => $button->label,
                    'resource_code' => $button->resource_code,
                    'variant' => $button->variant,
                    'action_type' => $button->action_type,
                    'action_target' => $button->action_target,
                    'position' => $button->position ?? 'page_top',
                    'config_json' => $button->config_json ?? [],
                    'confirmation_required' => $button->confirmation_required ?? false,
                    'confirmation_text' => $button->confirmation_text,
                    'sort_order' => $button->sort_order,
                    'is_visible' => $button->is_visible,
                ]),
        ]);
    }

    public function savePage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', Rule::exists(Page::class, 'id')],
            'code' => ['required', 'string', 'max:128', Rule::unique(Page::class, 'code')->ignore($request->integer('id'))],
            'name' => ['required', 'string', 'max:255'],
            'route' => ['required', 'string', 'max:255', Rule::unique(Page::class, 'route')->ignore($request->integer('id'))],
            'icon' => ['nullable', 'string', 'max:80'],
            'resource_code' => ['nullable', 'string', 'max:128'],
            'component' => ['required', 'string', 'max:255'],
            'layout_type' => ['nullable', Rule::in(['admin', 'module'])],
            'description' => ['nullable', 'string'],
            'page_order' => ['integer', 'min:0'],
            'active' => ['boolean'],
            'menu_group_id' => ['nullable', 'integer', Rule::exists(MenuGroup::class, 'id')],
            'menu_label' => ['nullable', 'string', 'max:255'],
            'menu_visible' => ['boolean'],
            'menu_sort_order' => ['integer', 'min:0'],
        ]);

        Resource::query()->updateOrCreate(
            ['code' => $data['resource_code'] ?: $data['code']],
            ['name' => $data['name'], 'type' => 'page', 'active' => true],
        );

        $pagePayload = [
            ...$data,
            'resource_code' => $data['resource_code'] ?: $data['code'],
            'layout_type' => $data['layout_type'] ?? 'module',
            'active' => (bool) ($data['active'] ?? true),
        ];

        unset($pagePayload['id']);
        unset($pagePayload['menu_group_id'], $pagePayload['menu_label'], $pagePayload['menu_visible'], $pagePayload['menu_sort_order']);

        $page = isset($data['id'])
            ? tap(Page::query()->findOrFail($data['id']))->update($pagePayload)
            : Page::query()->create($pagePayload);

        PageConfig::query()->firstOrCreate(['page_code' => $page->code], ['layout_json' => [], 'filters_json' => []]);

        if (! empty($data['menu_group_id'])) {
            PageMenu::query()->updateOrCreate(
                ['page_id' => $page->id, 'menu_group_id' => $data['menu_group_id']],
                [
                    'label' => $data['menu_label'] ?: $page->name,
                    'icon' => $page->icon,
                    'sort_order' => $data['menu_sort_order'] ?? $page->page_order,
                    'is_visible' => (bool) ($data['menu_visible'] ?? true),
                ],
            );
        }

        $this->auditLogger->log($request->user(), 'admin.page.save', ['page_code' => $page->code], $request);

        return $this->pages();
    }

    public function saveButton(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', Rule::exists(Button::class, 'id')],
            'page_id' => ['required', 'integer', Rule::exists(Page::class, 'id')],
            'code' => ['required', 'string', 'max:128', Rule::unique(Button::class, 'code')->ignore($request->integer('id'))],
            'label' => ['required', 'string', 'max:255'],
            'resource_code' => ['nullable', 'string', 'max:128'],
            'variant' => ['required', Rule::in(['primary', 'secondary', 'danger', 'ghost'])],
            'action_type' => ['required', Rule::in(['navigate', 'webhook', 'modal', 'refresh', 'custom'])],
            'action_target' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', Rule::in(['header_right', 'filter_bar', 'table_row', 'table_bulk', 'card_footer', 'page_top'])],
            'config_json' => ['nullable', 'array'],
            'confirmation_required' => ['boolean'],
            'confirmation_text' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['integer', 'min:0'],
            'is_visible' => ['boolean'],
        ]);

        $payload = [
            ...$data,
            'position' => $data['position'] ?? 'page_top',
            'config_json' => $data['config_json'] ?? [],
            'confirmation_required' => (bool) ($data['confirmation_required'] ?? false),
            'is_visible' => (bool) ($data['is_visible'] ?? true),
        ];

        unset($payload['id']);

        $button = isset($data['id'])
            ? tap(Button::query()->findOrFail($data['id']))->update($payload)
            : Button::query()->create($payload);

        $this->auditLogger->log($request->user(), 'admin.button.save', ['button_code' => $button->code], $request);

        return $this->pages();
    }

    public function deletePage(Request $request, Page $page): JsonResponse
    {
        $this->auditLogger->log($request->user(), 'admin.page.delete', ['page_code' => $page->code], $request);
        $page->delete();

        return $this->pages();
    }

    public function dataSources(): JsonResponse
    {
        return response()->json([
            'dataSources' => DataSource::query()->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function saveDataSource(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', Rule::exists(DataSource::class, 'id')],
            'code' => ['required', 'string', 'max:128', Rule::unique(DataSource::class, 'code')->ignore($request->integer('id'))],
            'name' => ['required', 'string', 'max:255'],
            'db_type' => ['required', Rule::in(['mssql', 'postgres', 'n8n_json', 'static_preview'])],
            'query_template' => ['nullable', 'string'],
            'allowed_params' => ['array'],
            'connection_meta' => ['array'],
            'preview_payload' => ['array'],
            'active' => ['boolean'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $sourcePayload = [
            ...$data,
            'query_template' => $data['query_template'] ?? '',
            'active' => (bool) ($data['active'] ?? true),
        ];

        unset($sourcePayload['id']);

        $source = isset($data['id'])
            ? tap(DataSource::query()->findOrFail($data['id']))->update($sourcePayload)
            : DataSource::query()->create($sourcePayload);

        if (! Page::query()->where('code', $source->code)->exists()) {
            Resource::query()->updateOrCreate(
                ['code' => $source->code],
                ['name' => $source->name, 'type' => 'data_source', 'active' => $source->active],
            );
        }

        $this->auditLogger->log($request->user(), 'admin.datasource.save', ['data_source_code' => $source->code], $request);

        return $this->dataSources();
    }

    public function testDataSource(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', Rule::exists(DataSource::class, 'code')],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'grain' => ['nullable', 'in:day,week,month,year'],
            'detail_type' => ['nullable', 'in:cari,urun'],
            'scope_key' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'bypass_cache' => ['nullable', 'boolean'],
        ]);

        $source = DataSource::query()->where('code', $data['code'])->firstOrFail();

        try {
            $result = $this->dataSourceManager->execute($source, [
                ...$data,
                'bypass_cache' => (bool) ($data['bypass_cache'] ?? true),
                'limit' => (int) ($data['limit'] ?? 20),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'message' => 'Veri kaynagi test istegi basarisiz oldu.',
                'detail' => $exception->getMessage(),
            ], 502);
        }

        $rows = is_array($result['rows'] ?? null) ? array_values($result['rows']) : [];

        $this->auditLogger->log($request->user(), 'admin.datasource.test', ['data_source_code' => $source->code], $request);

        return response()->json([
            'ok' => true,
            'status' => 'basarili',
            'rows_count' => count($rows),
            'preview_rows' => array_slice($rows, 0, (int) ($data['limit'] ?? 20)),
            'first_5_rows' => array_slice($rows, 0, 5),
            'meta' => $result['meta'] ?? [],
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:160'],
            'page' => ['nullable', 'string', 'max:160'],
            'q' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $limit = (int) ($filters['limit'] ?? 200);
        $timezone = 'Europe/Istanbul';
        $auditTable = (new AuditLog)->getTable();
        $userTable = (new User)->getTable();
        $pageLabels = Page::query()
            ->pluck('name', 'code')
            ->map(fn ($name) => (string) $name)
            ->all();

        $query = AuditLog::query()
            ->leftJoin("{$userTable} as log_users", 'log_users.id', '=', "{$auditTable}.user_id")
            ->orderByDesc("{$auditTable}.created_at");

        if (! empty($filters['user_id'])) {
            $query->where("{$auditTable}.user_id", (int) $filters['user_id']);
        }

        if (! empty($filters['action'])) {
            $query->where("{$auditTable}.action", (string) $filters['action']);
        }

        if (! empty($filters['date_from'])) {
            $query->where("{$auditTable}.created_at", '>=', CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['date_from'], $timezone)
                ->startOfDay()
                ->timezone('UTC'));
        }

        if (! empty($filters['date_to'])) {
            $query->where("{$auditTable}.created_at", '<=', CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['date_to'], $timezone)
                ->endOfDay()
                ->timezone('UTC'));
        }

        $logs = $query
            ->limit(min($limit * 5, 5000))
            ->get([
                "{$auditTable}.id",
                "{$auditTable}.created_at",
                "{$auditTable}.user_id",
                "{$auditTable}.action",
                "{$auditTable}.payload",
                'log_users.full_name as user_full_name',
                'log_users.username as username',
            ])
            ->map(fn ($log) => $this->formatAuditLog($log, $pageLabels, $timezone))
            ->filter(function (array $log) use ($filters): bool {
                if (! empty($filters['page']) && ($log['page'] ?? '') !== (string) $filters['page']) {
                    return false;
                }

                $term = trim((string) ($filters['q'] ?? ''));

                if ($term === '') {
                    return true;
                }

                $haystack = strtolower(json_encode([
                    $log['user_name'] ?? '',
                    $log['username'] ?? '',
                    $log['action'] ?? '',
                    $log['action_label'] ?? '',
                    $log['page_label'] ?? '',
                    $log['path'] ?? '',
                    $log['ip_address'] ?? '',
                    $log['search_term'] ?? '',
                    $log['filters_summary'] ?? '',
                    $log['payload_summary'] ?? '',
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return str_contains($haystack, strtolower($term));
            })
            ->take($limit)
            ->values();

        $todayStartUtc = CarbonImmutable::now($timezone)->startOfDay()->timezone('UTC');
        $todayQuery = AuditLog::query()->where('created_at', '>=', $todayStartUtc);
        $lastLog = $logs->first();

        return response()->json([
            'summary' => [
                'total_recent' => $logs->count(),
                'today_count' => (clone $todayQuery)->count(),
                'unique_users_today' => (clone $todayQuery)->whereNotNull('user_id')->distinct('user_id')->count('user_id'),
                'last_log_at' => $lastLog['created_at_human'] ?? null,
                'archived_available' => Schema::hasTable('panel.log_archives'),
            ],
            'options' => [
                'actions' => $logs
                    ->pluck('action')
                    ->filter()
                    ->unique()
                    ->map(fn (string $action) => ['value' => $action, 'label' => $this->actionLabel($action)])
                    ->values(),
                'pages' => $logs
                    ->map(fn (array $log) => ['value' => $log['page'], 'label' => $log['page_label']])
                    ->filter(fn (array $page) => $page['value'] !== null && $page['value'] !== '')
                    ->unique('value')
                    ->values(),
            ],
            'logs' => $logs,
        ]);

    }

    /**
     * @param  array<string, string>  $pageLabels
     * @return array<string, mixed>
     */
    private function formatAuditLog(mixed $log, array $pageLabels, string $timezone): array
    {
        $payload = is_array($log->payload ?? null) ? $log->payload : [];
        $createdAt = $this->createdAtUtc($log->created_at);
        $local = $createdAt->timezone($timezone);
        $userName = $log->user_id
            ? ($log->user_full_name ?: $log->username ?: "Kullanıcı #{$log->user_id}")
            : 'Sistem';
        $page = $this->pageCodeFromPayload($payload);

        return [
            'id' => $log->id,
            'created_at_utc' => $createdAt->toISOString(),
            'created_at_local' => $local->format('Y-m-d H:i:s'),
            'created_at_human' => $local->format('d.m.Y H:i:s'),
            'user_id' => $log->user_id,
            'user_name' => $userName,
            'full_name' => $log->user_full_name,
            'username' => $log->username,
            'action' => $log->action,
            'action_label' => $payload['action_label'] ?? $this->actionLabel((string) $log->action),
            'page' => $page,
            'page_label' => $this->pageLabel($page, $pageLabels),
            'path' => $payload['path'] ?? null,
            'method' => $payload['method'] ?? null,
            'ip_address' => $payload['ip_address'] ?? null,
            'device_label' => $this->deviceLabel($payload),
            'browser_label' => $this->browserLabel($payload),
            'search_term' => $this->searchTermFromPayload($payload),
            'filters_summary' => $this->filtersSummary($payload),
            'payload_summary' => $this->payloadSummary($payload),
            'raw_payload' => $payload,
        ];
    }

    private function createdAtUtc(mixed $createdAt): CarbonImmutable
    {
        if ($createdAt instanceof \DateTimeInterface) {
            return CarbonImmutable::parse($createdAt->format('Y-m-d H:i:s'), 'UTC')->timezone('UTC');
        }

        return CarbonImmutable::parse((string) $createdAt, 'UTC')->timezone('UTC');
    }

    private function pageCodeFromPayload(array $payload): ?string
    {
        $page = $payload['page'] ?? $payload['page_code'] ?? null;

        if (is_string($page) && trim($page) !== '') {
            return trim($page);
        }

        $path = (string) ($payload['path'] ?? '');

        return match (true) {
            str_contains($path, 'sales') => 'sales_main',
            str_contains($path, 'stock') => 'stock',
            str_contains($path, 'orders') => 'orders',
            str_contains($path, 'admin/logs') => 'admin_logs',
            str_contains($path, 'admin/users') => 'admin_users',
            str_contains($path, 'admin/datasources') => 'admin_datasources',
            str_contains($path, 'admin/pages') => 'admin_pages',
            str_contains($path, 'admin') => 'admin_panel',
            default => null,
        };
    }

    private function pageLabel(?string $page, array $pageLabels): ?string
    {
        if ($page === null || $page === '') {
            return null;
        }

        return $pageLabels[$page] ?? match ($page) {
            'admin_logs' => 'Loglar',
            'admin_users' => 'Kullanıcı Yönetimi',
            'admin_datasources' => 'Veri Kaynakları',
            'admin_pages' => 'Sayfalar',
            'admin_panel' => 'Yönetim Paneli',
            'sales_main' => 'Genel Satış',
            'stock' => 'Stok Yönetimi',
            'orders' => 'Sipariş Yönetimi',
            default => $page,
        };
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'panel.page.view' => 'Sayfa görüntüledi',
            'admin.user.save' => 'Kullanıcı kaydetti',
            'admin.user.clone' => 'Kullanıcı kopyaladı',
            'admin.datasource.save' => 'Veri kaynağı kaydetti',
            'admin.datasource.test' => 'Veri kaynağı test etti',
            'admin.page.save' => 'Sayfa kaydetti',
            'sales.customer.search' => 'Müşteri aradı',
            'sales.data.view', 'sales.data.filter' => 'Satış verisi filtreledi',
            default => $action,
        };
    }

    private function deviceLabel(array $payload): ?string
    {
        $agent = $this->parseUserAgent((string) ($payload['user_agent'] ?? ''));
        $parts = collect([
            $payload['device_type'] ?? $agent['device_type'],
            $payload['platform'] ?? $agent['platform'],
        ])->filter();

        return $parts->isEmpty() ? null : $parts->implode(' / ');
    }

    private function browserLabel(array $payload): ?string
    {
        $agent = $this->parseUserAgent((string) ($payload['user_agent'] ?? ''));
        $parts = collect([
            $payload['browser'] ?? $agent['browser'],
            $payload['browser_version'] ?? $agent['browser_version'],
        ])->filter();

        return $parts->isEmpty() ? null : $parts->implode(' ');
    }

    /**
     * @return array{device_type: string|null, browser: string|null, browser_version: string|null, platform: string|null}
     */
    private function parseUserAgent(string $userAgent): array
    {
        if ($userAgent === '') {
            return [
                'device_type' => null,
                'browser' => null,
                'browser_version' => null,
                'platform' => null,
            ];
        }

        $platform = match (true) {
            stripos($userAgent, 'Windows') !== false => 'Windows',
            stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPad') !== false => 'iOS',
            stripos($userAgent, 'Android') !== false => 'Android',
            stripos($userAgent, 'Mac OS') !== false || stripos($userAgent, 'Macintosh') !== false => 'macOS',
            stripos($userAgent, 'Linux') !== false => 'Linux',
            default => null,
        };
        $device = match (true) {
            stripos($userAgent, 'Tablet') !== false || stripos($userAgent, 'iPad') !== false => 'Tablet',
            stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'Mobile') !== false => 'Mobil',
            stripos($userAgent, 'Android') !== false => 'Tablet',
            default => 'Masaüstü',
        };

        return [
            'device_type' => $device,
            'platform' => $platform,
            ...$this->browserFromUserAgent($userAgent),
        ];
    }

    /**
     * @return array{browser: string|null, browser_version: string|null}
     */
    private function browserFromUserAgent(string $userAgent): array
    {
        foreach ([
            'Edge' => '/(?:Edg|EdgA|EdgiOS)\/([0-9.]+)/',
            'Chrome' => '/(?:Chrome|CriOS)\/([0-9.]+)/',
            'Firefox' => '/(?:Firefox|FxiOS)\/([0-9.]+)/',
            'Safari' => '/Version\/([0-9.]+).*Safari/',
        ] as $browser => $pattern) {
            if (preg_match($pattern, $userAgent, $matches) === 1) {
                return [
                    'browser' => $browser,
                    'browser_version' => $matches[1] ?? null,
                ];
            }
        }

        return [
            'browser' => 'Diğer',
            'browser_version' => null,
        ];
    }

    private function searchTermFromPayload(array $payload): ?string
    {
        foreach (['search', 'query', 'customer_filter', 'cari_filter', 'product_filter'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function filtersSummary(array $payload): ?string
    {
        $labels = [
            'scope_key' => 'Kapsam',
            'detail_type' => 'Detay',
            'brand_filter' => 'Marka',
            'category_filter' => 'Kategori',
            'customer_filter' => 'Müşteri',
            'cari_filter' => 'Cari',
            'product_filter' => 'Ürün',
            'date_from' => 'Başlangıç',
            'date_to' => 'Bitiş',
            'rep_code' => 'Temsilci',
            'result_count' => 'Sonuç',
        ];
        $parts = [];

        foreach ($labels as $key => $label) {
            $value = $payload[$key] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $parts[] = "{$label}: ".(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        return $parts === [] ? null : implode(' | ', $parts);
    }

    private function payloadSummary(array $payload): ?string
    {
        if (($payload['target_user_id'] ?? null) !== null) {
            return 'Hedef kullanıcı #'.$payload['target_user_id'];
        }

        if (($payload['source_user_id'] ?? null) !== null && ($payload['new_user_id'] ?? null) !== null) {
            return 'Kaynak #'.$payload['source_user_id'].' -> Yeni #'.$payload['new_user_id'];
        }

        if (($payload['data_source_code'] ?? null) !== null) {
            return 'Veri kaynağı: '.$payload['data_source_code'];
        }

        if (($payload['page'] ?? null) !== null || ($payload['path'] ?? null) !== null) {
            return collect([$payload['page'] ?? null, $payload['path'] ?? null])->filter()->implode(' / ');
        }

        return $this->filtersSummary($payload);
    }
}
