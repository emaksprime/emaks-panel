<?php

namespace Tests\Feature;

use App\Models\Button;
use App\Models\Page;
use App\Models\PageConfig;
use App\Models\Resource;
use App\Models\User;
use App\Models\UserAccess;
use App\Services\PanelAccessService;
use App\Services\PanelNavigationService;
use Database\Seeders\PanelDataSourcesSeeder;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PanelPermissionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PanelMetadataSeeder::class);
        $this->seed(PanelDataSourcesSeeder::class);
        $this->seed(PanelKnownWorkflowDataSourcesSeeder::class);
    }

    public function test_navigation_payload_only_contains_exact_allowed_pages(): void
    {
        $user = $this->createExactUser([
            'dashboard',
            'sales_online',
            'stock',
            'orders',
            'orders_alinan',
            'orders_verilen',
        ]);

        $payload = $this->actingAs($user)->getJson('/api/navigation')->assertOk()->json();
        $hrefs = collect($payload['groups'])
            ->flatMap(fn (array $group) => $group['items'] ?? [])
            ->pluck('href')
            ->values()
            ->all();

        $this->assertContains('/dashboard', $hrefs);
        $this->assertContains('/sales/online', $hrefs);
        $this->assertContains('/stock', $hrefs);
        $this->assertContains('/orders', $hrefs);
        $this->assertContains('/orders/alinan', $hrefs);
        $this->assertContains('/orders/verilen', $hrefs);

        $this->assertNotContains('/sales/main', $hrefs);
        $this->assertNotContains('/sales/bayi', $hrefs);
        $this->assertNotContains('/cari', $hrefs);
        $this->assertNotContains('/proforma', $hrefs);
        $this->assertNotContains('/admin', $hrefs);
    }

    public function test_dashboard_buttons_and_tabs_are_filtered_by_target_route_when_resource_code_is_missing(): void
    {
        $user = $this->createExactUser(['dashboard', 'stock']);
        $dashboard = Page::query()->where('code', 'dashboard')->firstOrFail();

        PageConfig::query()->updateOrCreate(
            ['page_code' => 'dashboard'],
            [
                'layout_json' => [
                    'moduleTabs' => [
                        ['label' => 'Stok', 'href' => '/stock'],
                        ['label' => 'Müşteri', 'href' => '/cari'],
                        ['label' => 'Dış Bağlantı', 'href' => 'https://example.com'],
                    ],
                ],
                'filters_json' => [],
            ],
        );

        Button::query()->create([
            'page_id' => $dashboard->id,
            'resource_code' => null,
            'label' => 'Stok',
            'code' => 'dashboard_stock_shortcut',
            'variant' => 'primary',
            'action_type' => 'navigate',
            'action_target' => '/stock',
            'position' => 'page_top',
            'sort_order' => 10,
            'is_visible' => true,
        ]);

        Button::query()->create([
            'page_id' => $dashboard->id,
            'resource_code' => null,
            'label' => 'Müşteri',
            'code' => 'dashboard_customer_shortcut',
            'variant' => 'primary',
            'action_type' => 'navigate',
            'action_target' => '/cari',
            'position' => 'page_top',
            'sort_order' => 20,
            'is_visible' => true,
        ]);

        $payload = app(PanelNavigationService::class)->pagePayload($dashboard->refresh(), $user);

        $this->assertSame(['Stok'], collect($payload['buttons'])->pluck('label')->all());
        $this->assertSame(['Stok', 'Dış Bağlantı'], collect($payload['moduleTabs'])->pluck('label')->all());
    }

    public function test_exact_user_is_forbidden_from_denied_routes(): void
    {
        $user = $this->createExactUser([
            'dashboard',
            'sales_online',
            'stock',
            'orders',
            'orders_alinan',
            'orders_verilen',
        ]);

        foreach (['/dashboard', '/sales/online', '/stock', '/orders/alinan', '/orders/verilen'] as $path) {
            $this->actingAs($user)->get($path)->assertOk();
        }

        $this->actingAs($user)->get('/orders')->assertRedirect('/orders/alinan');

        foreach (['/sales/main', '/sales/bayi', '/cari', '/proforma', '/admin'] as $path) {
            $this->actingAs($user)
                ->get($path)
                ->assertForbidden()
                ->assertSee('Yetki bulunmamaktadır.', false);
        }
    }

    public function test_orders_landing_redirects_to_first_authorized_order_page(): void
    {
        $receivedUser = $this->createExactUser(['orders', 'orders_alinan', 'orders_verilen']);
        $givenOnlyUser = $this->createExactUser(['orders', 'orders_verilen']);

        $this->actingAs($receivedUser)->get('/orders')->assertRedirect('/orders/alinan');
        $this->actingAs($givenOnlyUser)->get('/orders')->assertRedirect('/orders/verilen');
    }

    public function test_module_layout_uses_first_visible_route_for_module_buttons(): void
    {
        $moduleLayout = file_get_contents(resource_path('js/layouts/module-layout.tsx')) ?: '';
        $appSidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx')) ?: '';

        $this->assertStringContainsString('selectModuleHref', $moduleLayout);
        $this->assertStringContainsString("candidates: ['/sales/main', '/sales/online', '/sales/bayi']", $moduleLayout);
        $this->assertStringContainsString("candidates: ['/stock', '/stock/critical']", $moduleLayout);
        $this->assertStringContainsString("candidates: ['/orders/alinan', '/orders/verilen', '/orders']", $moduleLayout);
        $this->assertStringContainsString("candidates: ['/cari', '/cari/balance']", $moduleLayout);
        $this->assertStringContainsString('visibleHrefs', $moduleLayout);
        $this->assertStringContainsString('href="/dashboard"', $moduleLayout);
        $this->assertStringContainsString('href="/dashboard"', $appSidebar);
        $this->assertStringContainsString('[&::-webkit-scrollbar]:hidden', $moduleLayout);
        $this->assertStringContainsString('lg:overflow-visible', $moduleLayout);
        $this->assertStringNotContainsString('visibleHref: item.match.find', $moduleLayout);
    }

    public function test_user_menu_exposes_admin_panel_only_when_admin_route_is_visible(): void
    {
        $userMenu = file_get_contents(resource_path('js/components/user-menu-content.tsx')) ?: '';
        $exactUser = $this->createExactUser(['dashboard', 'stock']);
        $admin = User::factory()->create(['role_code' => 'admin']);

        $this->assertStringContainsString('canAccessAdmin', $userMenu);
        $this->assertStringContainsString("item.href === '/admin'", $userMenu);
        $this->assertStringContainsString('Yönetim Paneli', $userMenu);
        $this->assertStringContainsString('Profil / Ayarlar', $userMenu);
        $this->assertStringContainsString('Çıkış Yap', $userMenu);

        $exactHrefList = collect($this->actingAs($exactUser)->getJson('/api/navigation')->assertOk()->json('groups'))
            ->flatMap(fn (array $group) => $group['items'] ?? [])
            ->pluck('href')
            ->all();
        $adminHrefList = collect($this->actingAs($admin)->getJson('/api/navigation')->assertOk()->json('groups'))
            ->flatMap(fn (array $group) => $group['items'] ?? [])
            ->pluck('href')
            ->all();

        $this->assertNotContains('/admin', $exactHrefList);
        $this->assertContains('/admin', $adminHrefList);
        $this->actingAs($exactUser)->get('/admin')->assertForbidden();
        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_technical_role_only_sees_technical_stock_and_order_pages(): void
    {
        $user = User::factory()->create(['role_code' => 'technical']);

        $payload = $this->actingAs($user)->getJson('/api/navigation')->assertOk()->json();
        $hrefs = collect($payload['groups'])
            ->flatMap(fn (array $group) => $group['items'] ?? [])
            ->pluck('href')
            ->values()
            ->all();

        foreach ([
            '/technical-service',
            '/technical-service/dashboard',
            '/technical-service/serial-query',
            '/technical-service/technicians',
            '/stock',
            '/stock/critical',
            '/orders/alinan',
            '/orders/verilen',
        ] as $path) {
            $this->assertContains($path, $hrefs);
            $this->actingAs($user)->get($path)->assertOk();
        }

        $this->assertContains('/orders', $hrefs);
        $this->actingAs($user)->get('/orders')->assertRedirect('/orders/alinan');

        foreach ([
            '/sales/main',
            '/sales/online',
            '/sales/bayi',
            '/cari',
            '/proforma',
            '/admin',
        ] as $path) {
            $this->assertNotContains($path, $hrefs);
            $this->actingAs($user)->get($path)->assertForbidden();
        }
    }

    public function test_default_roles_can_see_stock_and_order_pages_unless_user_deny_blocks_them(): void
    {
        $access = app(PanelAccessService::class);

        foreach (['sales', 'stock', 'orders', 'technical', 'customer', 'proforma', 'viewer'] as $roleCode) {
            $user = User::factory()->create(['role_code' => $roleCode]);

            foreach (['stock', 'stock_critical', 'stock_locks', 'orders', 'orders_alinan', 'orders_verilen'] as $resourceCode) {
                $this->assertTrue($access->userCanAccess($user, $resourceCode), "{$roleCode} should access {$resourceCode}");
            }

            if ($roleCode !== 'manager') {
                $this->assertFalse($access->userCanAccess($user, 'stock_all'), "{$roleCode} should not access stock_all by default");
            }
        }

        $viewer = User::factory()->create(['role_code' => 'viewer']);
        UserAccess::query()->create([
            'user_id' => $viewer->id,
            'resource_code' => 'stock',
            'can_view' => false,
        ]);

        $this->assertFalse($access->userCanAccess($viewer->refresh(), 'stock'));
    }

    public function test_exact_user_data_api_access_is_enforced_before_gateway_call(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'rows' => [
                    ['stok_kodu' => 'STK-1', 'stok_adi' => 'Test Ürün', 'toplam_miktar' => 3],
                ],
            ]),
        ]);

        $user = $this->createExactUser(['dashboard', 'stock', 'stock_locks']);

        $this->actingAs($user)
            ->postJson('/api/data/stock', ['search' => 'STK', 'bypass_cache' => true])
            ->assertOk()
            ->assertJsonPath('rows.0.stok_kodu', 'STK-1');

        Http::assertSent(fn (Request $request) => ($request['source_code'] ?? null) === 'stock_dashboard');

        $this->actingAs($user)
            ->postJson('/api/data/cari', ['bypass_cache' => true])
            ->assertForbidden()
            ->assertJsonPath('message', 'Yetki bulunmamaktadır.');
    }

    public function test_user_deny_override_blocks_role_permission(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        UserAccess::query()->create([
            'user_id' => $user->id,
            'resource_code' => 'sales_main',
            'can_view' => false,
        ]);

        $this->assertFalse(app(PanelAccessService::class)->userCanAccess($user->refresh(), 'sales_main'));
        $this->actingAs($user)->get('/sales/main')->assertForbidden();
    }

    public function test_admin_strict_access_save_writes_allowlist_and_denies_other_active_resources(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'username' => 'strict.user',
            'full_name' => 'Strict User',
            'password' => 'password123',
            'role_code' => 'manager',
            'temsilci_kodu' => null,
            'aktif' => true,
            'force_password_change' => false,
            'access' => ['sales_online', 'stock', 'stock_locks', 'orders', 'orders_alinan', 'orders_verilen'],
            'denied_access' => [],
            'strict_access' => true,
        ]);

        $response->assertOk();

        $createdUser = User::query()->where('username', 'strict.user')->firstOrFail();

        foreach (['dashboard', 'sales_online', 'stock', 'stock_locks', 'orders', 'orders_alinan', 'orders_verilen'] as $resourceCode) {
            $this->assertDatabaseHas('panel.user_access', [
                'user_id' => $createdUser->id,
                'resource_code' => $resourceCode,
                'can_view' => true,
            ]);
        }

        foreach (['sales_main', 'sales_bayi', 'customers', 'proforma', 'admin_panel', 'data_sources'] as $resourceCode) {
            $this->assertDatabaseHas('panel.user_access', [
                'user_id' => $createdUser->id,
                'resource_code' => $resourceCode,
                'can_view' => false,
            ]);
        }
    }

    public function test_super_admin_navigation_and_buttons_are_not_restricted(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $dashboard = Page::query()->where('code', 'dashboard')->firstOrFail();

        Button::query()->create([
            'page_id' => $dashboard->id,
            'resource_code' => null,
            'label' => 'Müşteri',
            'code' => 'dashboard_customer_shortcut_super_admin',
            'variant' => 'primary',
            'action_type' => 'navigate',
            'action_target' => '/cari',
            'position' => 'page_top',
            'sort_order' => 10,
            'is_visible' => true,
        ]);

        $payload = $this->actingAs($admin)->getJson('/api/navigation')->assertOk()->json();
        $hrefs = collect($payload['groups'])
            ->flatMap(fn (array $group) => $group['items'] ?? [])
            ->pluck('href')
            ->values()
            ->all();

        $this->assertContains('/sales/main', $hrefs);
        $this->assertContains('/sales/online', $hrefs);
        $this->assertContains('/sales/bayi', $hrefs);
        $this->assertContains('/cari', $hrefs);
        $this->assertContains('/proforma', $hrefs);
        $this->assertContains('/admin', $hrefs);

        $buttonPayload = app(PanelNavigationService::class)->pagePayload($dashboard->refresh(), $admin);

        $this->assertContains('Müşteri', collect($buttonPayload['buttons'])->pluck('label')->all());
    }

    public function test_admin_user_exact_permission_ui_contract_exists(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/admin/AdminUsers.jsx')) ?: '';

        $this->assertStringContainsString('Sadece seçilenlere izin ver', $component);
        $this->assertStringContainsString('strict_access', $component);
        $this->assertStringContainsString('Bu modüle erişim', $component);
        $this->assertStringContainsString('Ekranlar', $component);
        $this->assertStringContainsString('Butonlar/Aksiyonlar', $component);
        $this->assertStringContainsString('Veri kaynakları', $component);
        $this->assertStringContainsString('Kapsamlar/Scope', $component);
        $this->assertStringContainsString('setModuleAccess', $component);
        $this->assertStringContainsString('salesScopeResourceCodes', $component);
        $this->assertStringContainsString("'sales_main_all'", $component);
        $this->assertStringContainsString("'sales_online'", $component);
        $this->assertStringContainsString("'sales_bayi'", $component);
        $this->assertStringContainsString("'sales_rep_salih_cakir'", $component);
        $this->assertStringContainsString("groupName !== 'Satış Yönetimi'", $component);
    }

    /**
     * @param  list<string>  $allowedResourceCodes
     */
    private function createExactUser(array $allowedResourceCodes): User
    {
        $user = User::factory()->create(['role_code' => 'manager']);
        $allowed = collect($allowedResourceCodes)
            ->push('dashboard')
            ->when(in_array('stock', $allowedResourceCodes, true), fn ($resources) => $resources->push('stock_locks'))
            ->filter()
            ->unique()
            ->values();
        $activeResourceCodes = Resource::query()->where('active', true)->pluck('code');

        UserAccess::query()->where('user_id', $user->id)->delete();

        foreach ($allowed as $resourceCode) {
            UserAccess::query()->create([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
                'can_view' => true,
            ]);
        }

        foreach ($activeResourceCodes->diff($allowed) as $resourceCode) {
            UserAccess::query()->create([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
                'can_view' => false,
            ]);
        }

        return $user->refresh();
    }
}
