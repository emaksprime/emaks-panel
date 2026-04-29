<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserAccess;
use App\Services\PanelAccessService;
use App\Services\PanelNavigationService;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRedirectPermissionCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PanelMetadataSeeder::class);
    }

    public function test_first_accessible_route_uses_operation_priority(): void
    {
        $navigation = app(PanelNavigationService::class);

        $admin = User::factory()->create(['role_code' => 'admin']);
        $sales = User::factory()->create(['role_code' => 'sales']);
        $stock = User::factory()->create(['role_code' => 'stock']);
        $orders = User::factory()->create(['role_code' => 'orders']);
        $customer = User::factory()->create(['role_code' => 'customer']);
        $dashboardOnly = User::factory()->create(['role_code' => 'viewer']);
        $proformaOnly = User::factory()->create(['role_code' => 'viewer']);
        UserAccess::query()->create([
            'user_id' => $proformaOnly->id,
            'resource_code' => 'proforma',
            'can_view' => true,
        ]);

        $this->assertSame('/sales/main', $navigation->firstAccessibleRouteFor($admin));
        $this->assertSame('/sales/main', $navigation->firstAccessibleRouteFor($sales));
        $this->assertSame('/stock', $navigation->firstAccessibleRouteFor($stock));
        $this->assertSame('/orders', $navigation->firstAccessibleRouteFor($orders));
        $this->assertSame('/cari', $navigation->firstAccessibleRouteFor($customer));
        $this->assertSame('/proforma', $navigation->firstAccessibleRouteFor($proformaOnly));
        $this->assertSame('/dashboard', $navigation->firstAccessibleRouteFor($dashboardOnly));
    }

    public function test_user_without_visible_pages_lands_on_forbidden_dashboard(): void
    {
        $navigation = app(PanelNavigationService::class);
        $user = User::factory()->create(['role_code' => 'viewer']);
        UserAccess::query()->create([
            'user_id' => $user->id,
            'resource_code' => 'dashboard',
            'can_view' => false,
        ]);

        $this->assertNull($navigation->firstAccessibleRouteFor($user));
        $this->assertSame('/dashboard', $navigation->homePathFor($user));
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }

    public function test_login_redirects_to_first_accessible_route(): void
    {
        $stock = User::factory()->create(['role_code' => 'stock']);

        $this->post(route('login.store'), [
            'email' => $stock->username,
            'password' => 'password',
        ])->assertRedirect('/stock');

        $this->assertAuthenticatedAs($stock);
    }

    public function test_admin_user_resource_list_is_unique_and_grouped(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $payload = $this->actingAs($admin)->getJson('/api/admin/users')->assertOk()->json();

        $codes = collect($payload['resources'])->pluck('code');
        $groups = collect($payload['resources'])->pluck('group')->unique()->values();

        $this->assertSame($codes->unique()->count(), $codes->count());
        $this->assertContains('Satış Yönetimi', $groups);
        $this->assertContains('Stok Yönetimi', $groups);
        $this->assertContains('Sipariş Yönetimi', $groups);
        $this->assertContains('Müşteri Yönetimi', $groups);
        $this->assertContains('Proforma', $groups);
        $this->assertContains('Sistem Yönetimi', $groups);
        $this->assertContains('Veri Kaynakları', $groups);
    }

    public function test_saving_user_permissions_deduplicates_overrides_and_denies_win(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $user = User::factory()->create(['role_code' => 'viewer']);

        $this->actingAs($admin)
            ->postJson('/api/admin/users', [
                'id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'password' => '',
                'role_code' => 'viewer',
                'temsilci_kodu' => null,
                'aktif' => true,
                'force_password_change' => false,
                'access' => ['stock', 'stock', 'orders'],
                'denied_access' => ['stock', 'stock'],
            ])
            ->assertOk();

        $this->assertSame(2, UserAccess::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('panel.user_access', [
            'user_id' => $user->id,
            'resource_code' => 'stock',
            'can_view' => false,
        ]);
        $this->assertDatabaseHas('panel.user_access', [
            'user_id' => $user->id,
            'resource_code' => 'orders',
            'can_view' => true,
        ]);
    }

    public function test_user_overrides_take_precedence_over_role_permissions(): void
    {
        $access = app(PanelAccessService::class);

        $stockUser = User::factory()->create(['role_code' => 'stock']);
        UserAccess::query()->create([
            'user_id' => $stockUser->id,
            'resource_code' => 'stock',
            'can_view' => false,
        ]);

        $viewer = User::factory()->create(['role_code' => 'viewer']);
        UserAccess::query()->create([
            'user_id' => $viewer->id,
            'resource_code' => 'stock',
            'can_view' => true,
        ]);

        $this->assertFalse($access->userCanAccess($stockUser, 'stock'));
        $this->assertTrue($access->userCanAccess($viewer, 'stock'));
    }
}
