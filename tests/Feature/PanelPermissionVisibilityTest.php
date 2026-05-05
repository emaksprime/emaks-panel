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

        foreach (['/dashboard', '/sales/online', '/stock', '/orders', '/orders/alinan', '/orders/verilen'] as $path) {
            $this->actingAs($user)->get($path)->assertOk();
        }

        foreach (['/sales/main', '/sales/bayi', '/cari', '/proforma', '/admin'] as $path) {
            $this->actingAs($user)->get($path)->assertForbidden();
        }
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

        $user = $this->createExactUser(['dashboard', 'stock']);

        $this->actingAs($user)
            ->postJson('/api/data/stock', ['search' => 'STK', 'bypass_cache' => true])
            ->assertOk()
            ->assertJsonPath('rows.0.stok_kodu', 'STK-1');

        Http::assertSent(fn (Request $request) => ($request['source_code'] ?? null) === 'stock_dashboard');

        $this->actingAs($user)
            ->postJson('/api/data/cari', ['bypass_cache' => true])
            ->assertForbidden();
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
            'access' => ['sales_online', 'stock', 'orders', 'orders_alinan', 'orders_verilen'],
            'denied_access' => [],
            'strict_access' => true,
        ]);

        $response->assertOk();

        $createdUser = User::query()->where('username', 'strict.user')->firstOrFail();

        foreach (['dashboard', 'sales_online', 'stock', 'orders', 'orders_alinan', 'orders_verilen'] as $resourceCode) {
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
    }

    /**
     * @param  list<string>  $allowedResourceCodes
     */
    private function createExactUser(array $allowedResourceCodes): User
    {
        $user = User::factory()->create(['role_code' => 'manager']);
        $allowed = collect($allowedResourceCodes)
            ->push('dashboard')
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
