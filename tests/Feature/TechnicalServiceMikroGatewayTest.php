<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\User;
use App\Services\TechnicalService\MikroSerialNumberService;
use Database\Seeders\PanelDataSourcesSeeder;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TechnicalServiceMikroGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PanelMetadataSeeder::class);
        $this->seed(PanelDataSourcesSeeder::class);
        $this->seed(PanelKnownWorkflowDataSourcesSeeder::class);
    }

    public function test_direct_mikro_sql_connection_is_removed(): void
    {
        $this->assertArrayNotHasKey('mikro_readonly', config('database.connections'));
        $this->assertStringNotContainsString('mikro_readonly', file_get_contents(config_path('database.php')));
        $this->assertStringNotContainsString('DB::connection', file_get_contents(app_path('Services/TechnicalService/MikroSerialNumberService.php')));
    }

    public function test_technical_service_mikro_datasources_are_seeded_for_gateway(): void
    {
        foreach ([
            'technical_service_serial_check',
            'technical_service_serial_history',
            'technical_service_warranty_serial',
        ] as $code) {
            $source = DataSource::query()->where('code', $code)->firstOrFail();

            $this->assertTrue($source->active);
            $this->assertContains('serial_no', $source->allowed_params);
            $this->assertContains('bypass_cache', $source->allowed_params);
            $this->assertStringContainsString('[[serial_no]]', $source->query_template);
            $this->assertStringContainsString('CIHAZ_HAREKETLERI', $source->query_template);
        }
    }

    public function test_serial_check_uses_n8n_gateway_source_and_serial_param(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            'ok' => true,
            'rows' => match ($request['source_code']) {
                'technical_service_serial_check' => [$this->decisionRow('SN-1')],
                default => [],
            },
            'meta' => [],
            'request' => $request->data(),
        ]));

        $result = app(MikroSerialNumberService::class)->checkInstallation('SN-1');

        $this->assertTrue($result['found']);
        $this->assertSame('SN-1', $result['cihaz_seri_no']);
        Http::assertSent(function (Request $request): bool {
            return $request['source_code'] === 'technical_service_serial_check'
                && $request['serial_no'] === 'SN-1'
                && ($request['params']['serial_no'] ?? null) === 'SN-1'
                && in_array('serial_no', $request['allowed_params'] ?? [], true);
        });
    }

    public function test_latest_valid_sale_uses_warranty_serial_gateway_source(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            'ok' => true,
            'rows' => $request['source_code'] === 'technical_service_warranty_serial'
                ? [$this->historyRow('SN-W', true)]
                : [],
            'meta' => [],
            'request' => $request->data(),
        ]));

        $sale = app(MikroSerialNumberService::class)->latestValidSale('SN-W');

        $this->assertSame('SN-W', $sale['serial_no'] ?? null);
        $this->assertSame('C-1', $sale['customer_code'] ?? null);
        $this->assertNotEmpty($sale['fingerprint'] ?? null);
        Http::assertSent(fn (Request $request): bool => $request['source_code'] === 'technical_service_warranty_serial'
            && ($request['params']['serial_no'] ?? null) === 'SN-W');
    }

    public function test_gateway_ok_false_returns_json_error_from_serial_endpoint(): void
    {
        Http::fake(['*' => Http::response([
            'ok' => false,
            'error' => 'MSSQL gateway failed',
            'rows' => [],
            'meta' => [],
            'request' => [],
        ])]);

        $this->actingAs(User::factory()->create(['role_code' => 'admin']))
            ->getJson('/api/technical-service/mikro/serial-check?serial_no=SN-ERR')
            ->assertStatus(502)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'MSSQL gateway failed');
    }

    public function test_gateway_empty_rows_keep_serial_history_endpoint_healthy(): void
    {
        Http::fake(['*' => Http::response([
            'ok' => true,
            'rows' => [],
            'meta' => [],
            'request' => [],
        ])]);

        $this->actingAs(User::factory()->create(['role_code' => 'admin']))
            ->getJson('/api/technical-service/mikro/serial-history?serial_no=SN-EMPTY')
            ->assertOk()
            ->assertJsonPath('serial_no', 'SN-EMPTY')
            ->assertJsonPath('decision.found', false)
            ->assertJsonPath('items', []);
    }

    public function test_serial_query_permission_blocks_gateway_call(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create(['role_code' => 'viewer']))
            ->getJson('/api/technical-service/mikro/serial-check?serial_no=SN-DENY')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function decisionRow(string $serialNo): array
    {
        return [
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'montaj_ek_aciklama' => 'Test montaj bulundu.',
            'cihaz_seri_no' => $serialNo,
            'stok_kodu' => 'STK-1',
            'stok_adi' => 'Test Ürün',
            'irsaliye_tarihi' => '2026-05-01',
            'irsaliye_seri' => 'IRS',
            'irsaliye_sira' => '1',
            'asil_cari_kodu' => 'C-1',
            'asil_cari_unvani' => 'Test Cari',
            'farkli_cari_uyarisi' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyRow(string $serialNo, bool $latest): array
    {
        return [
            'event_type' => 'satış',
            'event_date' => '2026-05-01',
            'title' => 'Satış / çıkış',
            'description' => 'Test satış',
            'stok_kodu' => 'STK-1',
            'stok_adi' => 'Test Ürün',
            'cari_kodu' => 'C-1',
            'cari_unvani' => 'Test Cari',
            'evrak_seri' => 'IRS',
            'evrak_sira' => '1',
            'siparis_seri' => 'SIP',
            'siparis_sira' => '2',
            'fatura_sira' => 'FAT-1',
            'is_latest_valid_sale' => $latest,
            'cihaz_seri_no' => $serialNo,
        ];
    }
}
