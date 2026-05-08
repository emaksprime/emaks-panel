<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserAccess;
use App\Models\RoleResourcePermission;
use Database\Seeders\PanelDataSourcesSeeder;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrdersDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PanelMetadataSeeder::class);
        $this->seed(PanelDataSourcesSeeder::class);
        $this->seed(PanelKnownWorkflowDataSourcesSeeder::class);
    }

    public function test_orders_alinan_all_scope_sends_filter_payload_to_gateway(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'rows' => []])]);

        $admin = User::factory()->create([
            'role_code' => 'admin',
            'temsilci_kodu' => '0003',
            'aktif' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/data/orders_alinan', [
                'brand_filter' => 'philips',
                'product_filter' => 'DDL720 FVP',
                'bypass_cache' => true,
            ])
            ->assertOk()
            ->assertJsonPath('queryMeta.ordersScope', 'all')
            ->assertJsonPath('queryMeta.effectiveRepCode', null)
            ->assertJsonPath('queryMeta.canOrdersAlinanAll', true)
            ->assertJsonPath('queryMeta.deniedOrdersAlinanAll', false);

        [$request] = Http::recorded()->first();
        $payload = $request->data();
        $params = $payload['params'] ?? [];

        $this->assertSame('orders_alinan', $payload['source_code'] ?? null);
        $this->assertSame('philips', $payload['brand_filter'] ?? null);
        $this->assertSame('DDL720 FVP', $payload['product_filter'] ?? null);
        $this->assertArrayHasKey('rep_code', $payload);
        $this->assertNull($payload['rep_code']);
        $this->assertSame('all', $params['orders_scope'] ?? null);
        $this->assertArrayHasKey('rep_code', $params);
        $this->assertNull($params['rep_code']);
        $this->assertSame('philips', $params['brand_filter'] ?? null);
        $this->assertSame('DDL720 FVP', $params['product_filter'] ?? null);
    }

    public function test_orders_alinan_representative_scope_sends_user_rep_code(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'rows' => []])]);

        $user = User::factory()->create([
            'role_code' => 'viewer',
            'temsilci_kodu' => '0003',
            'aktif' => true,
        ]);

        foreach (['orders_alinan', 'orders_alinan_temsilci'] as $resourceCode) {
            UserAccess::query()->create([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
                'can_view' => true,
            ]);
        }

        $this->actingAs($user)
            ->postJson('/api/data/orders_alinan', [
                'brand_filter' => 'emaks_prime',
                'product_filter' => 'GALAXY',
                'bypass_cache' => true,
            ])
            ->assertOk()
            ->assertJsonPath('queryMeta.ordersScope', 'temsilci')
            ->assertJsonPath('queryMeta.effectiveRepCode', '0003')
            ->assertJsonPath('queryMeta.canOrdersAlinanAll', false)
            ->assertJsonPath('queryMeta.canOrdersAlinanTemsilci', true)
            ->assertJsonPath('queryMeta.deniedOrdersAlinanAll', false);

        [$request] = Http::recorded()->first();
        $payload = $request->data();
        $params = $payload['params'] ?? [];

        $this->assertSame('orders_alinan', $payload['source_code'] ?? null);
        $this->assertSame('0003', $payload['rep_code'] ?? null);
        $this->assertSame('0003', $params['rep_code'] ?? null);
        $this->assertSame('temsilci', $params['orders_scope'] ?? null);
        $this->assertSame('emaks_prime', $params['brand_filter'] ?? null);
        $this->assertSame('GALAXY', $params['product_filter'] ?? null);
    }

    public function test_orders_alinan_representative_scope_without_user_rep_code_uses_safe_no_match_payload(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'rows' => []])]);

        $user = User::factory()->create([
            'role_code' => 'viewer',
            'temsilci_kodu' => null,
            'aktif' => true,
        ]);

        foreach (['orders_alinan', 'orders_alinan_temsilci'] as $resourceCode) {
            UserAccess::query()->create([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
                'can_view' => true,
            ]);
        }

        $this->actingAs($user)
            ->postJson('/api/data/orders_alinan', [
                'bypass_cache' => true,
            ])
            ->assertOk()
            ->assertJsonPath('queryMeta.ordersScope', 'temsilci')
            ->assertJsonPath('queryMeta.effectiveRepCode', '__NO_REP_CODE__')
            ->assertJsonPath('queryMeta.canOrdersAlinanAll', false)
            ->assertJsonPath('queryMeta.canOrdersAlinanTemsilci', true);

        [$request] = Http::recorded()->first();
        $payload = $request->data();
        $params = $payload['params'] ?? [];

        $this->assertSame('orders_alinan', $payload['source_code'] ?? null);
        $this->assertSame('__NO_REP_CODE__', $payload['rep_code'] ?? null);
        $this->assertSame('__NO_REP_CODE__', $params['rep_code'] ?? null);
        $this->assertSame('temsilci', $params['orders_scope'] ?? null);
    }

    public function test_orders_alinan_all_scope_resource_keeps_all_payload_even_with_user_rep_code(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'rows' => []])]);

        $user = User::factory()->create([
            'role_code' => 'viewer',
            'temsilci_kodu' => '0003',
            'aktif' => true,
        ]);

        foreach (['orders_alinan', 'orders_alinan_all'] as $resourceCode) {
            UserAccess::query()->create([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
                'can_view' => true,
            ]);
        }

        $this->actingAs($user)
            ->postJson('/api/data/orders_alinan', [
                'bypass_cache' => true,
            ])
            ->assertOk()
            ->assertJsonPath('queryMeta.ordersScope', 'all')
            ->assertJsonPath('queryMeta.effectiveRepCode', null)
            ->assertJsonPath('queryMeta.canOrdersAlinanAll', true);

        [$request] = Http::recorded()->first();
        $payload = $request->data();
        $params = $payload['params'] ?? [];

        $this->assertSame('orders_alinan', $payload['source_code'] ?? null);
        $this->assertArrayHasKey('rep_code', $payload);
        $this->assertNull($payload['rep_code']);
        $this->assertArrayHasKey('rep_code', $params);
        $this->assertNull($params['rep_code']);
        $this->assertSame('all', $params['orders_scope'] ?? null);
    }

    public function test_orders_alinan_all_deny_override_forces_representative_scope_payload(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'rows' => []])]);

        RoleResourcePermission::query()->updateOrCreate(
            ['role_code' => 'viewer', 'resource_code' => 'orders_alinan_all'],
            ['can_view' => true, 'can_execute' => true],
        );

        $user = User::factory()->create([
            'role_code' => 'viewer',
            'temsilci_kodu' => '0024',
            'aktif' => true,
        ]);

        foreach ([
            'orders_alinan' => true,
            'orders_alinan_all' => false,
            'orders_alinan_temsilci' => true,
        ] as $resourceCode => $canView) {
            UserAccess::query()->create([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
                'can_view' => $canView,
            ]);
        }

        $response = $this->actingAs($user)
            ->postJson('/api/data/orders_alinan', [
                'bypass_cache' => true,
            ])
            ->assertOk()
            ->assertJsonPath('queryMeta.ordersScope', 'temsilci')
            ->assertJsonPath('queryMeta.effectiveRepCode', '0024')
            ->assertJsonPath('queryMeta.canOrdersAlinanAll', false)
            ->assertJsonPath('queryMeta.canOrdersAlinanTemsilci', true)
            ->assertJsonPath('queryMeta.deniedOrdersAlinanAll', true);

        [$request] = Http::recorded()->first();
        $payload = $request->data();
        $params = $payload['params'] ?? [];

        $this->assertSame('0024', $payload['rep_code'] ?? null);
        $this->assertSame('0024', $params['rep_code'] ?? null);
        $this->assertSame('temsilci', $params['orders_scope'] ?? null);
        $this->assertSame('temsilci', $response->json('queryMeta.ordersScope'));
    }

    public function test_orders_verilen_sends_brand_product_and_delivery_filters_to_gateway(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'rows' => []])]);

        $user = User::factory()->create([
            'role_code' => 'admin',
            'aktif' => true,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/data/orders_verilen', [
                'brand_filter' => 'emaks_prime',
                'product_filter' => 'DDL720',
                'delivery_week' => "MAYIS'IN 3. HAFTASI",
                'delivery_date' => '2026-05-15',
                'bypass_cache' => true,
            ])
            ->assertOk();

        [$request] = Http::recorded()->first();
        $payload = $request->data();
        $params = $payload['params'] ?? [];

        $this->assertSame('orders_verilen', $payload['source_code'] ?? null);
        $this->assertSame('emaks_prime', $payload['brand_filter'] ?? null);
        $this->assertSame('DDL720', $payload['product_filter'] ?? null);
        $this->assertSame('emaks_prime', $params['brand_filter'] ?? null);
        $this->assertSame('DDL720', $params['product_filter'] ?? null);
        $this->assertSame("MAYIS'IN 3. HAFTASI", $params['delivery_week'] ?? null);
        $this->assertSame('2026-05-15', $params['delivery_date'] ?? null);
        $this->assertArrayNotHasKey('ordersScope', $response->json('queryMeta'));
    }
}
