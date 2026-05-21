<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WarehouseRack;
use App\Models\WarehouseSerialLocation;
use App\Models\WarehouseStockLocation;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseRackReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PanelMetadataSeeder::class);
    }

    public function test_serial_location_is_reported_with_quantity_one(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseRack::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'rack_name' => 'A Rafı',
            'is_active' => true,
        ]);
        WarehouseSerialLocation::query()->create([
            'serial_no' => 'SN-RPT-001',
            'stock_code' => 'STK-SERIAL',
            'stock_name' => 'Serili Rapor Ürün',
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'status' => 'in_stock',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report?item_type=serial')
            ->assertOk()
            ->assertJsonPath('items.0.item_type', 'serial')
            ->assertJsonPath('items.0.quantity', 1)
            ->assertJsonPath('items.0.rack_name', 'A Rafı')
            ->assertJsonPath('summary.total_serial_count', 1);
    }

    public function test_stock_location_is_reported_with_real_quantity(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'B-02',
            'stock_code' => 'STK-STOCK',
            'stock_name' => 'Adetli Rapor Ürün',
            'quantity' => 7.5,
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report?item_type=stock')
            ->assertOk()
            ->assertJsonPath('items.0.item_type', 'stock')
            ->assertJsonPath('items.0.quantity', 7.5)
            ->assertJsonPath('summary.total_stock_rows', 1)
            ->assertJsonPath('summary.total_stock_quantity', 7.5);
    }

    public function test_rack_filter_returns_only_matching_rack(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseSerialLocation::query()->create([
            'serial_no' => 'SN-RACK-A',
            'stock_code' => 'STK-A',
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'status' => 'in_stock',
            'source' => 'manual',
        ]);
        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'B-02',
            'stock_code' => 'STK-B',
            'quantity' => 4,
            'source' => 'manual',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report?rack_code=A-01')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('A-01', $response->json('items.0.rack_code'));
    }

    public function test_search_matches_stock_code_stock_name_and_serial_no(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseSerialLocation::query()->create([
            'serial_no' => 'SN-SEARCH-001',
            'stock_code' => 'STK-SERIAL-SEARCH',
            'stock_name' => 'Serili Arama Ürün',
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'status' => 'in_stock',
            'source' => 'manual',
        ]);
        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'B-02',
            'stock_code' => 'STK-CODE-SEARCH',
            'stock_name' => 'Adetli Arama Ürün',
            'quantity' => 3,
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report?search=STK-CODE-SEARCH')
            ->assertOk()
            ->assertJsonFragment(['stock_code' => 'STK-CODE-SEARCH']);

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report?search=Adetli')
            ->assertOk()
            ->assertJsonFragment(['stock_name' => 'Adetli Arama Ürün']);

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report?search=SN-SEARCH-001')
            ->assertOk()
            ->assertJsonFragment(['serial_no' => 'SN-SEARCH-001']);
    }

    public function test_only_in_stock_excludes_zero_quantity_stock_rows(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'stock_code' => 'STK-ZERO',
            'quantity' => 0,
            'source' => 'manual',
        ]);
        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-02',
            'stock_code' => 'STK-POSITIVE',
            'quantity' => 2,
            'source' => 'manual',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report?item_type=stock&only_in_stock=1')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('STK-POSITIVE', $response->json('items.0.stock_code'));
    }

    public function test_user_without_warehouse_terminal_permission_cannot_access_report(): void
    {
        $user = User::factory()->create(['role_code' => 'viewer']);

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report')
            ->assertForbidden();
    }
}
