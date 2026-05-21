<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WarehouseRack;
use App\Models\WarehouseRackOperation;
use App\Models\WarehouseRackOperationItem;
use App\Models\WarehouseSerialLocation;
use App\Models\WarehouseStockLocation;
use App\Services\WarehouseTerminalLookupService;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseRackTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PanelMetadataSeeder::class);
    }

    public function test_warehouse_lookup_endpoint_requires_terminal_permission_and_returns_items(): void
    {
        $this->mock(WarehouseTerminalLookupService::class, function ($mock): void {
            $mock->shouldReceive('warehouses')
                ->once()
                ->andReturn([
                    'items' => [
                        ['warehouse_no' => 1, 'warehouse_name' => 'MERKEZ DEPO'],
                    ],
                    'source' => 'test',
                    'message' => null,
                ]);
        });

        $user = User::factory()->create(['role_code' => 'stock']);

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/lookups/warehouses')
            ->assertOk()
            ->assertJsonPath('items.0.warehouse_no', 1)
            ->assertJsonPath('items.0.warehouse_name', 'MERKEZ DEPO');
    }

    public function test_warehouse_lookup_uses_panel_fallback_when_mikro_datasource_is_not_active(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseSerialLocation::query()->create([
            'serial_no' => 'SN-FB-1',
            'stock_code' => 'STK-FB-1',
            'stock_name' => 'Fallback Seri',
            'warehouse_no' => 4,
            'rack_code' => 'A-01',
            'status' => 'in_stock',
            'source' => 'manual',
        ]);
        WarehouseStockLocation::query()->create([
            'warehouse_no' => 7,
            'rack_code' => 'B-01',
            'stock_code' => 'STK-FB-2',
            'stock_name' => 'Fallback Stok',
            'quantity' => 2,
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/lookups/warehouses')
            ->assertOk()
            ->assertJsonPath('source', 'panel_fallback')
            ->assertJsonPath('message', 'Mikro lookup kaynağı bulunamadı; panel lokasyon kayıtları gösteriliyor.')
            ->assertJsonFragment(['warehouse_no' => 4, 'warehouse_name' => 'Depo 4'])
            ->assertJsonFragment(['warehouse_no' => 7, 'warehouse_name' => 'Depo 7']);
    }

    public function test_fallback_rack_lookup_is_filtered_by_warehouse_no(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseRack::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'rack_name' => 'A Rafı',
            'is_active' => true,
        ]);
        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'B-02',
            'stock_code' => 'STK-RACK',
            'quantity' => 1,
            'source' => 'manual',
        ]);
        WarehouseStockLocation::query()->create([
            'warehouse_no' => 2,
            'rack_code' => 'C-03',
            'stock_code' => 'STK-RACK',
            'quantity' => 1,
            'source' => 'manual',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/lookups/racks?warehouse_no=1&type=source')
            ->assertOk()
            ->assertJsonPath('source', 'panel_fallback');

        $this->assertContains('A-01', collect($response->json('items'))->pluck('rack_code'));
        $this->assertContains('B-02', collect($response->json('items'))->pluck('rack_code'));
        $this->assertNotContains('C-03', collect($response->json('items'))->pluck('rack_code'));
    }

    public function test_fallback_item_lookup_returns_stock_code_and_serial_matches(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseSerialLocation::query()->create([
            'serial_no' => 'SN-LOOKUP-1',
            'stock_code' => 'STK-SERIAL',
            'stock_name' => 'Serili Lookup Ürün',
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'status' => 'in_stock',
            'source' => 'manual',
        ]);
        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'B-02',
            'stock_code' => 'STK-STOCK',
            'stock_name' => 'Adetli Lookup Ürün',
            'quantity' => 5,
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/lookups/items?warehouse_no=1&q=SN-LOOKUP')
            ->assertOk()
            ->assertJsonPath('source', 'panel_fallback')
            ->assertJsonFragment([
                'match_type' => 'serial',
                'serial_no' => 'SN-LOOKUP-1',
                'is_serial_tracked' => true,
            ]);

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/lookups/items?warehouse_no=1&q=STK-STOCK')
            ->assertOk()
            ->assertJsonPath('source', 'panel_fallback')
            ->assertJsonFragment([
                'match_type' => 'stock_code',
                'stock_code' => 'STK-STOCK',
                'is_serial_tracked' => false,
            ]);
    }

    public function test_serial_item_validates_when_it_is_in_the_source_rack(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseSerialLocation::query()->create([
            'serial_no' => 'SN-100',
            'stock_code' => 'STK-100',
            'stock_name' => 'Test Seri Ürün',
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'status' => 'in_stock',
            'source' => 'manual',
        ]);

        $response = $this->actingAs($user)->postJson('/api/operations/warehouse-terminal/rack-transfer/validate', [
            'warehouse_no' => 1,
            'source_rack_code' => 'A-01',
            'target_rack_code' => 'B-02',
            'item_code' => 'SN-100',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('item_type', 'serial')
            ->assertJsonPath('summary.serial_no', 'SN-100')
            ->assertJsonPath('summary.quantity', 1);

        $this->assertDatabaseHas('panel.warehouse_rack_operations', [
            'operation_no' => $response->json('operation_no'),
            'status' => 'validated',
            'serial_no' => 'SN-100',
            'source_rack_code' => 'A-01',
            'target_rack_code' => 'B-02',
        ]);
    }

    public function test_serial_item_validation_fails_when_source_rack_does_not_match(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseSerialLocation::query()->create([
            'serial_no' => 'SN-200',
            'stock_code' => 'STK-200',
            'stock_name' => 'Yanlış Raf Ürünü',
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'status' => 'in_stock',
            'source' => 'manual',
        ]);

        $this->actingAs($user)->postJson('/api/operations/warehouse-terminal/rack-transfer/validate', [
            'warehouse_no' => 1,
            'source_rack_code' => 'X-99',
            'target_rack_code' => 'B-02',
            'item_code' => 'SN-200',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Bu seri şu an A-01 rafında. X-99 kaynak rafından transfer edilemez.');

        $this->assertDatabaseMissing('panel.warehouse_rack_operations', [
            'serial_no' => 'SN-200',
        ]);
    }

    public function test_legacy_stock_quantity_transfer_decreases_source_and_increases_target(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'stock_code' => 'STK-300',
            'stock_name' => 'Adetli Ürün',
            'quantity' => 5,
            'source' => 'manual',
        ]);

        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'B-02',
            'stock_code' => 'STK-300',
            'stock_name' => 'Adetli Ürün',
            'quantity' => 2,
            'source' => 'manual',
        ]);

        $validateResponse = $this->actingAs($user)->postJson('/api/operations/warehouse-terminal/rack-transfer/validate', [
            'warehouse_no' => 1,
            'source_rack_code' => 'A-01',
            'target_rack_code' => 'B-02',
            'item_code' => 'STK-300',
            'quantity' => 2,
        ])->assertOk();

        $operationNo = $validateResponse->json('operation_no');

        $this->actingAs($user)->postJson('/api/operations/warehouse-terminal/rack-transfer/complete', [
            'operation_no' => $operationNo,
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Raf transferi tamamlandı. Mikro’ya yazma yapılmadı.')
            ->assertJsonPath('summary.item_type', 'stock');

        $source = WarehouseStockLocation::query()
            ->where('warehouse_no', 1)
            ->where('rack_code', 'A-01')
            ->where('stock_code', 'STK-300')
            ->firstOrFail();
        $target = WarehouseStockLocation::query()
            ->where('warehouse_no', 1)
            ->where('rack_code', 'B-02')
            ->where('stock_code', 'STK-300')
            ->firstOrFail();

        $this->assertSame(3.0, (float) $source->quantity);
        $this->assertSame(4.0, (float) $target->quantity);
        $this->assertSame($operationNo, $source->last_operation_no);
        $this->assertSame($operationNo, $target->last_operation_no);
        $this->assertSame('completed', WarehouseRackOperation::query()->where('operation_no', $operationNo)->value('status'));
    }

    public function test_same_source_and_target_rack_is_rejected_by_transfer_endpoint(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        $this->actingAs($user)->postJson('/api/operations/warehouse-terminal/rack-transfer/transfer', [
            'warehouse_no' => 1,
            'source_rack_code' => 'A-01',
            'target_rack_code' => 'A-01',
            'stock_code' => 'STK-400',
            'item_code' => 'STK-400',
            'quantity' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Kaynak raf ile hedef raf aynı olamaz.');
    }

    public function test_stock_transfer_endpoint_decreases_source_increases_target_and_creates_operation_item(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'stock_code' => 'STK-500',
            'stock_name' => 'Adetli Transfer Ürünü',
            'quantity' => 10,
            'source' => 'manual',
        ]);

        $response = $this->actingAs($user)->postJson('/api/operations/warehouse-terminal/rack-transfer/transfer', [
            'warehouse_no' => 1,
            'warehouse_name' => 'MERKEZ DEPO',
            'source_rack_code' => 'A-01',
            'target_rack_code' => 'B-02',
            'stock_code' => 'STK-500',
            'item_code' => 'STK-500',
            'quantity' => 3,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('summary.item_type', 'stock')
            ->assertJsonPath('summary.quantity', 3);

        $operationNo = $response->json('operation_no');
        $operationId = WarehouseRackOperation::query()->where('operation_no', $operationNo)->value('id');

        $this->assertSame(7.0, (float) WarehouseStockLocation::query()
            ->where('warehouse_no', 1)
            ->where('rack_code', 'A-01')
            ->where('stock_code', 'STK-500')
            ->value('quantity'));
        $this->assertSame(3.0, (float) WarehouseStockLocation::query()
            ->where('warehouse_no', 1)
            ->where('rack_code', 'B-02')
            ->where('stock_code', 'STK-500')
            ->value('quantity'));
        $this->assertDatabaseHas('panel.warehouse_rack_operation_items', [
            'operation_id' => $operationId,
            'item_type' => 'stock',
            'stock_code' => 'STK-500',
            'source_rack_code' => 'A-01',
            'target_rack_code' => 'B-02',
        ]);
    }

    public function test_serial_tracked_product_rejects_transfer_without_serial_numbers(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseSerialLocation::query()->create([
            'serial_no' => 'SN-401',
            'stock_code' => 'STK-401',
            'stock_name' => 'Serili Ürün',
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'status' => 'in_stock',
            'source' => 'manual',
        ]);

        $this->actingAs($user)->postJson('/api/operations/warehouse-terminal/rack-transfer/transfer', [
            'warehouse_no' => 1,
            'source_rack_code' => 'A-01',
            'target_rack_code' => 'B-02',
            'stock_code' => 'STK-401',
            'item_code' => 'STK-401',
            'quantity' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Seri takipli ürünlerde seri no zorunludur.');
    }

    public function test_multi_serial_transfer_moves_each_serial_and_creates_operation_items(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        foreach (['SN-501', 'SN-502'] as $serialNo) {
            WarehouseSerialLocation::query()->create([
                'serial_no' => $serialNo,
                'stock_code' => 'STK-501',
                'stock_name' => 'Çoklu Seri Ürünü',
                'warehouse_no' => 1,
                'rack_code' => 'A-01',
                'status' => 'in_stock',
                'source' => 'manual',
            ]);
        }

        $response = $this->actingAs($user)->postJson('/api/operations/warehouse-terminal/rack-transfer/transfer', [
            'warehouse_no' => 1,
            'source_rack_code' => 'A-01',
            'target_rack_code' => 'B-02',
            'stock_code' => 'STK-501',
            'item_code' => 'STK-501',
            'quantity' => 99,
            'serial_numbers' => ['SN-501', 'SN-502'],
            'is_serial_tracked' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('summary.item_type', 'serial')
            ->assertJsonPath('summary.quantity', 2)
            ->assertJsonPath('summary.serial_count', 2);

        $operationNo = $response->json('operation_no');
        $operationId = WarehouseRackOperation::query()->where('operation_no', $operationNo)->value('id');

        foreach (['SN-501', 'SN-502'] as $serialNo) {
            $this->assertDatabaseHas('panel.warehouse_serial_locations', [
                'serial_no' => $serialNo,
                'rack_code' => 'B-02',
                'last_operation_no' => $operationNo,
            ]);
        }

        $this->assertSame(2, WarehouseRackOperationItem::query()->where('operation_id', $operationId)->count());
        $this->assertSame(2.0, (float) WarehouseRackOperation::query()->where('id', $operationId)->value('quantity'));
    }

    public function test_serial_cannot_be_transferred_twice_from_old_source_rack(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseSerialLocation::query()->create([
            'serial_no' => 'TEST-SN-001',
            'stock_code' => 'STK-TEST',
            'stock_name' => 'Test Seri Ürünü',
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'status' => 'in_stock',
            'source' => 'manual',
        ]);

        $payload = [
            'warehouse_no' => 1,
            'source_rack_code' => 'A-01',
            'target_rack_code' => 'B-02',
            'stock_code' => 'STK-TEST',
            'item_code' => 'STK-TEST',
            'quantity' => 1,
            'serial_numbers' => ['TEST-SN-001'],
            'is_serial_tracked' => true,
        ];

        $this->actingAs($user)
            ->postJson('/api/operations/warehouse-terminal/rack-transfer/transfer', $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $response = $this->actingAs($user)
            ->postJson('/api/operations/warehouse-terminal/rack-transfer/transfer', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->assertContains($response->json('message'), [
            'Bu seri şu an B-02 rafında. A-01 kaynak rafından transfer edilemez.',
            'Bu seri zaten hedef rafta.',
        ]);
        $this->assertSame(1, WarehouseRackOperation::query()->where('status', 'completed')->count());
        $this->assertSame(1, WarehouseRackOperationItem::query()->where('serial_no', 'TEST-SN-001')->count());
    }

    public function test_serial_already_in_target_rack_is_rejected(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseSerialLocation::query()->create([
            'serial_no' => 'TEST-SN-002',
            'stock_code' => 'STK-TEST',
            'stock_name' => 'Test Seri Ürünü',
            'warehouse_no' => 1,
            'rack_code' => 'B-02',
            'status' => 'in_stock',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->postJson('/api/operations/warehouse-terminal/rack-transfer/transfer', [
                'warehouse_no' => 1,
                'source_rack_code' => 'A-01',
                'target_rack_code' => 'B-02',
                'stock_code' => 'STK-TEST',
                'item_code' => 'STK-TEST',
                'quantity' => 1,
                'serial_numbers' => ['TEST-SN-002'],
                'is_serial_tracked' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Bu seri zaten hedef rafta.');

        $this->assertSame(0, WarehouseRackOperation::query()->where('status', 'completed')->count());
        $this->assertSame(0, WarehouseRackOperationItem::query()->where('serial_no', 'TEST-SN-002')->count());
    }

    public function test_legacy_complete_cannot_run_completed_operation_twice(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseSerialLocation::query()->create([
            'serial_no' => 'TEST-SN-003',
            'stock_code' => 'STK-LEGACY',
            'stock_name' => 'Legacy Seri Ürünü',
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'status' => 'in_stock',
            'source' => 'manual',
        ]);

        $operationNo = $this->actingAs($user)
            ->postJson('/api/operations/warehouse-terminal/rack-transfer/validate', [
                'warehouse_no' => 1,
                'source_rack_code' => 'A-01',
                'target_rack_code' => 'B-02',
                'item_code' => 'TEST-SN-003',
            ])
            ->assertOk()
            ->json('operation_no');

        $this->actingAs($user)
            ->postJson('/api/operations/warehouse-terminal/rack-transfer/complete', [
                'operation_no' => $operationNo,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($user)
            ->postJson('/api/operations/warehouse-terminal/rack-transfer/complete', [
                'operation_no' => $operationNo,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->assertDatabaseHas('panel.warehouse_serial_locations', [
            'serial_no' => 'TEST-SN-003',
            'rack_code' => 'B-02',
            'last_operation_no' => $operationNo,
        ]);
        $this->assertSame('completed', WarehouseRackOperation::query()->where('operation_no', $operationNo)->value('status'));
        $this->assertSame(1, WarehouseRackOperation::query()->where('operation_no', $operationNo)->where('status', 'completed')->count());
    }

    public function test_transfer_history_returns_completed_operations_in_date_range(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'stock_code' => 'STK-700',
            'stock_name' => 'Geçmiş Ürünü',
            'quantity' => 4,
            'source' => 'manual',
        ]);

        $transfer = $this->actingAs($user)->postJson('/api/operations/warehouse-terminal/rack-transfer/transfer', [
            'warehouse_no' => 1,
            'warehouse_name' => 'MERKEZ DEPO',
            'source_rack_code' => 'A-01',
            'target_rack_code' => 'B-02',
            'stock_code' => 'STK-700',
            'item_code' => 'STK-700',
            'quantity' => 2,
        ])->assertOk();

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-transfer/history?date_from='.now()->subDay()->toDateString().'&date_to='.now()->toDateString().'&warehouse_no=1')
            ->assertOk()
            ->assertJsonPath('items.0.operation_no', $transfer->json('operation_no'))
            ->assertJsonPath('items.0.warehouse_name', 'MERKEZ DEPO')
            ->assertJsonPath('items.0.items.0.stock_code', 'STK-700');
    }
}
