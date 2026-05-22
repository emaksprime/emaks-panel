<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WarehouseRack;
use App\Models\WarehouseRackOperation;
use App\Models\WarehouseRackOperationItem;
use App\Models\WarehouseSerialLocation;
use App\Models\WarehouseStockLocation;
use App\Services\TechnicalService\MikroSerialNumberService;
use App\Services\WarehouseRackReportService;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
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
            'category_name' => 'Elektronik Kilit',
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
            ->assertJsonPath('items.0.category_name', 'Elektronik Kilit')
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

    public function test_warehouse_filter_returns_only_matching_warehouse(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'stock_code' => 'STK-WAREHOUSE-ONE',
            'quantity' => 4,
            'source' => 'manual',
        ]);
        WarehouseStockLocation::query()->create([
            'warehouse_no' => 2,
            'rack_code' => 'A-01',
            'stock_code' => 'STK-WAREHOUSE-TWO',
            'quantity' => 6,
            'source' => 'manual',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report?warehouse_no=2')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(2, $response->json('items.0.warehouse_no'));
        $this->assertSame('STK-WAREHOUSE-TWO', $response->json('items.0.stock_code'));
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

    public function test_default_report_excludes_unavailable_locations_when_only_in_stock_is_missing(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseSerialLocation::query()->create([
            'serial_no' => 'SN-IN-STOCK',
            'stock_code' => 'STK-SERIAL-IN',
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'status' => 'in_stock',
            'source' => 'manual',
        ]);
        WarehouseSerialLocation::query()->create([
            'serial_no' => 'SN-OUT-OF-STOCK',
            'stock_code' => 'STK-SERIAL-OUT',
            'warehouse_no' => 1,
            'rack_code' => 'A-02',
            'status' => 'out_of_stock',
            'source' => 'manual',
        ]);
        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-03',
            'stock_code' => 'STK-ZERO',
            'quantity' => 0,
            'source' => 'manual',
        ]);
        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-04',
            'stock_code' => 'STK-POSITIVE',
            'quantity' => 2,
            'source' => 'manual',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report')
            ->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $response->assertJsonFragment(['serial_no' => 'SN-IN-STOCK']);
        $response->assertJsonFragment(['stock_code' => 'STK-POSITIVE']);
        $response->assertJsonMissing(['serial_no' => 'SN-OUT-OF-STOCK']);
        $response->assertJsonMissing(['stock_code' => 'STK-ZERO']);
    }

    public function test_rack_report_export_returns_excel_compatible_csv_with_stock_code_column(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseSerialLocation::query()->create([
            'serial_no' => 'SN-EXPORT-001',
            'stock_code' => 'STK-EXPORT',
            'stock_name' => 'Türkçe Export Ürün',
            'category_name' => 'Kilit Seti',
            'warehouse_no' => 1,
            'rack_code' => 'A-04',
            'status' => 'in_stock',
            'source' => 'manual',
            'last_operation_no' => 'OP-EXPORT',
        ]);

        $response = $this->actingAs($user)
            ->get('/api/operations/warehouse-terminal/rack-report/export?item_type=serial')
            ->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('raf-raporu-', (string) $response->headers->get('content-disposition'));

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $rows = $this->csvRows($content);
        $this->assertSame([
            'Depo',
            'Raf',
            'Tip',
            'Kategori Adı',
            'Stok Kodu',
            'Stok Adı',
            'Seri No',
            'Miktar',
            'Durum',
            'Son İşlem No',
            'Son Raf Hareketi',
        ], $rows[0]);
        $this->assertSame('Kilit Seti', $rows[1][3]);
        $this->assertSame('STK-EXPORT', $rows[1][4]);
        $this->assertSame('Türkçe Export Ürün', $rows[1][5]);
    }

    public function test_rack_report_export_applies_filters_and_returns_flat_rows(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseSerialLocation::query()->create([
            'serial_no' => 'SN-FLAT-001',
            'stock_code' => 'STK-FLAT-SERIAL',
            'stock_name' => 'Flat Serili Ürün',
            'warehouse_no' => 2,
            'rack_code' => 'A-01',
            'status' => 'in_stock',
            'source' => 'manual',
        ]);
        WarehouseStockLocation::query()->create([
            'warehouse_no' => 2,
            'rack_code' => 'A-01',
            'stock_code' => 'STK-FLAT-STOCK',
            'stock_name' => 'Flat Serisiz Ürün',
            'quantity' => 3,
            'source' => 'manual',
        ]);
        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'stock_code' => 'STK-FLAT-DROP',
            'stock_name' => 'Flat Farklı Depo',
            'quantity' => 5,
            'source' => 'manual',
        ]);

        $response = $this->actingAs($user)
            ->get('/api/operations/warehouse-terminal/rack-report/export?warehouse_no=2&search=FLAT')
            ->assertOk();

        $content = $response->streamedContent();
        $rows = $this->csvRows($content);
        $this->assertCount(3, $rows);
        $this->assertSame('STK-FLAT-SERIAL', $rows[1][4]);
        $this->assertSame('STK-FLAT-STOCK', $rows[2][4]);
        $this->assertCount(11, $rows[1]);
        $this->assertCount(11, $rows[2]);
        $this->assertStringNotContainsString('STK-FLAT-DROP', $content);
    }

    public function test_rack_report_export_uses_only_in_stock_default_and_excludes_zero_stock(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'stock_code' => 'STK-EXPORT-ZERO',
            'quantity' => 0,
            'source' => 'manual',
        ]);
        WarehouseStockLocation::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-02',
            'stock_code' => 'STK-EXPORT-POSITIVE',
            'quantity' => 9,
            'source' => 'manual',
        ]);

        $content = $this->actingAs($user)
            ->get('/api/operations/warehouse-terminal/rack-report/export?item_type=stock')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('STK-EXPORT-POSITIVE', $content);
        $this->assertStringNotContainsString('STK-EXPORT-ZERO', $content);
    }

    public function test_rack_report_export_rejects_too_many_rows(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        $this->mock(WarehouseRackReportService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('exportRows')
                ->once()
                ->andReturn(collect(array_fill(0, WarehouseRackReportService::EXPORT_LIMIT + 1, [])));
        });

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report/export')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Rapor satır sayısı çok yüksek. Lütfen filtre kullanın.');
    }

    public function test_serial_history_endpoint_returns_panel_rack_transfer_history(): void
    {
        $user = User::factory()->create(['role_code' => 'stock', 'full_name' => 'Local Admin']);

        WarehouseRack::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'A-01',
            'rack_name' => 'Kaynak Raf',
            'is_active' => true,
        ]);
        WarehouseRack::query()->create([
            'warehouse_no' => 1,
            'rack_code' => 'B-02',
            'rack_name' => 'Hedef Raf',
            'is_active' => true,
        ]);
        WarehouseSerialLocation::query()->create([
            'serial_no' => 'SN-HISTORY-001',
            'stock_code' => 'STK-HISTORY',
            'stock_name' => 'Geçmiş Ürün',
            'category_name' => 'Geçmiş Kategori',
            'warehouse_no' => 1,
            'rack_code' => 'B-02',
            'status' => 'in_stock',
            'source' => 'manual',
            'last_seen_at' => now(),
        ]);
        $operation = WarehouseRackOperation::query()->create([
            'operation_no' => 'RF-HISTORY-001',
            'operation_type' => 'rack_transfer',
            'source_warehouse_no' => 1,
            'source_rack_code' => 'A-01',
            'target_warehouse_no' => 1,
            'target_rack_code' => 'B-02',
            'serial_no' => 'SN-HISTORY-001',
            'stock_code' => 'STK-HISTORY',
            'quantity' => 1,
            'status' => 'completed',
            'created_by' => $user->id,
            'completed_by' => $user->id,
            'completed_at' => now(),
        ]);
        WarehouseRackOperationItem::query()->create([
            'operation_id' => $operation->id,
            'line_no' => 1,
            'item_type' => 'serial',
            'warehouse_no' => 1,
            'source_rack_code' => 'A-01',
            'target_rack_code' => 'B-02',
            'serial_no' => 'SN-HISTORY-001',
            'stock_code' => 'STK-HISTORY',
            'stock_name' => 'Geçmiş Ürün',
            'category_name' => 'Geçmiş Kategori',
            'quantity' => 1,
            'status' => 'completed',
        ]);

        $this->mock(MikroSerialNumberService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('history')
                ->once()
                ->with('SN-HISTORY-001')
                ->andReturn(['items' => []]);
        });

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report/serial-history?serial_no=SN-HISTORY-001')
            ->assertOk()
            ->assertJsonPath('serial_no', 'SN-HISTORY-001')
            ->assertJsonPath('stock_name', 'Geçmiş Ürün')
            ->assertJsonPath('category_name', 'Geçmiş Kategori')
            ->assertJsonPath('current_location.rack_code', 'B-02')
            ->assertJsonPath('items.0.source', 'panel')
            ->assertJsonPath('items.0.movement_type', 'Raf Transferi')
            ->assertJsonPath('items.0.operation_no', 'RF-HISTORY-001')
            ->assertJsonPath('items.0.source_rack_name', 'Kaynak Raf')
            ->assertJsonPath('items.0.target_rack_name', 'Hedef Raf')
            ->assertJsonPath('items.0.user', 'Local Admin');
    }

    public function test_serial_history_endpoint_returns_empty_items_for_unknown_serial(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);

        $this->mock(MikroSerialNumberService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('history')
                ->once()
                ->with('SN-UNKNOWN')
                ->andReturn(['items' => []]);
        });

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report/serial-history?serial_no=SN-UNKNOWN')
            ->assertOk()
            ->assertJsonPath('serial_no', 'SN-UNKNOWN')
            ->assertJsonPath('current_location', null)
            ->assertJsonPath('items', []);
    }

    public function test_serial_history_endpoint_keeps_panel_history_when_mikro_fails(): void
    {
        $user = User::factory()->create(['role_code' => 'stock']);
        $operation = WarehouseRackOperation::query()->create([
            'operation_no' => 'RF-MIKRO-FAIL',
            'operation_type' => 'rack_transfer',
            'source_warehouse_no' => 1,
            'source_rack_code' => 'A-01',
            'target_warehouse_no' => 1,
            'target_rack_code' => 'B-02',
            'serial_no' => 'SN-MIKRO-FAIL',
            'stock_code' => 'STK-MIKRO-FAIL',
            'quantity' => 1,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        WarehouseRackOperationItem::query()->create([
            'operation_id' => $operation->id,
            'line_no' => 1,
            'item_type' => 'serial',
            'warehouse_no' => 1,
            'source_rack_code' => 'A-01',
            'target_rack_code' => 'B-02',
            'serial_no' => 'SN-MIKRO-FAIL',
            'stock_code' => 'STK-MIKRO-FAIL',
            'quantity' => 1,
            'status' => 'completed',
        ]);

        $this->mock(MikroSerialNumberService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('history')
                ->once()
                ->with('SN-MIKRO-FAIL')
                ->andThrow(new RuntimeException('Gateway kapalı'));
        });

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report/serial-history?serial_no=SN-MIKRO-FAIL')
            ->assertOk()
            ->assertJsonPath('items.0.source', 'panel')
            ->assertJsonPath('message', 'Mikro seri geçmişi alınamadı; panel raf hareketleri gösteriliyor.');
    }

    public function test_user_without_warehouse_terminal_permission_cannot_access_report(): void
    {
        $user = User::factory()->create(['role_code' => 'viewer']);

        $this->actingAs($user)
            ->getJson('/api/operations/warehouse-terminal/rack-report')
            ->assertForbidden();
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    private function csvRows(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = array_values(array_filter(
            preg_split('/\r\n|\n|\r/', $content) ?: [],
            fn (string $line): bool => $line !== '',
        ));

        return array_map(fn (string $line): array => str_getcsv($line, ';'), $lines);
    }
}
