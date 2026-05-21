<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WarehouseRackOperation;
use App\Models\WarehouseSerialLocation;
use App\Models\WarehouseStockLocation;
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
            ->assertJsonPath('message', 'Bu ürün/seri belirtilen depoda ve kaynak rafta bulunamadı.');

        $this->assertDatabaseMissing('panel.warehouse_rack_operations', [
            'serial_no' => 'SN-200',
        ]);
    }

    public function test_stock_quantity_transfer_decreases_source_and_increases_target(): void
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

    public function test_same_source_and_target_rack_is_rejected(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        $this->actingAs($user)->postJson('/api/operations/warehouse-terminal/rack-transfer/validate', [
            'warehouse_no' => 1,
            'source_rack_code' => 'A-01',
            'target_rack_code' => 'A-01',
            'item_code' => 'STK-400',
            'quantity' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Kaynak raf ile hedef raf aynı olamaz.');
    }
}
