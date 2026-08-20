<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\DataSourceCache;
use App\Services\Mikro\MikroParitySource;
use App\Services\N8nPanelDataGateway;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\InteractsWithTestHttpIsolation;
use Tests\TestCase;

class N8nPanelDataGatewayTest extends TestCase
{
    use InteractsWithTestHttpIsolation, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestPanelDataSourceGateway();
    }

    public function test_gateway_posts_datasource_metadata_to_configured_endpoint(): void
    {
        $this->fakeIsolatedHttp([
            self::TEST_PANEL_DATA_SOURCE_GATEWAY_URL => Http::response([
                'rows' => [
                    ['stok_kodu' => 'STK-1', 'miktar' => 12],
                ],
                'meta' => ['source' => 'stock_dashboard'],
            ]),
        ]);

        $source = DataSource::query()->where('code', 'stock_dashboard')->firstOrFail();
        $source->forceFill(['query_template' => 'SELECT 1 AS miktar'])->save();

        $result = app(N8nPanelDataGateway::class)->run('stock_dashboard', [
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-27',
            'grain' => 'week',
        ], $source);

        $this->assertSame('STK-1', $result['rows'][0]['stok_kodu']);

        Http::assertSent(function (Request $request) {
            return $request->url() === self::TEST_PANEL_DATA_SOURCE_GATEWAY_URL
                && $request['source_code'] === 'stock_dashboard'
                && $request['query_template'] === 'SELECT 1 AS miktar'
                && $request['data_source']['query_template_available'] === true
                && $request['params']['date_from'] === '2026-04-01';
        });
    }

    public function test_gateway_does_not_send_placeholder_query_as_runnable_sql(): void
    {
        $this->fakeIsolatedHttp([
            self::TEST_PANEL_DATA_SOURCE_GATEWAY_URL => Http::response([
                'rows' => [],
            ]),
        ]);

        $source = DataSource::query()->where('code', 'stock_dashboard')->firstOrFail();
        $source->forceFill(['query_template' => '-- Canli SQL daha sonra panel metadata ile yonetilecek.'])->save();

        app(N8nPanelDataGateway::class)->run('stock_dashboard', [
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-27',
            'grain' => 'week',
        ], $source);

        Http::assertSent(fn (Request $request) => $request['query_template'] === ''
            && $request['data_source']['query_template_available'] === false);
    }

    public function test_gateway_continues_when_cache_schema_is_incomplete(): void
    {
        $this->fakeIsolatedHttp([
            self::TEST_PANEL_DATA_SOURCE_GATEWAY_URL => Http::response([
                'ok' => true,
                'rows' => [
                    ['satir_tipi' => 'GRUP', 'ciro' => 1000],
                ],
                'meta' => ['source' => 'sales_main_dashboard'],
            ]),
        ]);

        Schema::dropIfExists('panel.data_source_cache');
        Schema::create('panel.data_source_cache', function (Blueprint $table) {
            $table->id();
            $table->string('source_code')->nullable();
            $table->timestamps();
        });

        try {
            $source = DataSource::query()->where('code', 'sales_main_dashboard')->firstOrFail();

            $result = app(N8nPanelDataGateway::class)->run('sales_main_dashboard', [
                'date_from' => '2026-04-01',
                'date_to' => '2026-04-27',
                'grain' => 'week',
                'detail_type' => 'cari',
                'scope_key' => 'all',
            ], $source);

            $this->assertSame(1000, $result['rows'][0]['ciro']);
            Http::assertSent(fn (Request $request) => $request['source_code'] === 'sales_main_dashboard');
        } finally {
            Schema::dropIfExists('panel.data_source_cache');
            Schema::create('panel.data_source_cache', function (Blueprint $table) {
                $table->id();
                $table->string('cache_key', 128)->unique();
                $table->string('source_code', 128)->index();
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
            });
        }
    }

    public function test_n8n_parity_read_writes_no_cache_snapshot_business_or_datasource_row(): void
    {
        $url = 'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-local-v2';
        config()->set('panel.n8n_gateway_url', $url);
        $cacheBefore = DataSourceCache::query()->count();
        $sourcesBefore = DataSource::query()->count();
        Http::fake([$url => Http::response([
            'ok' => true,
            'rows' => [[
                'record_id' => '123e4567-e89b-42d3-a456-426614174000',
                'customer_code' => 'C001',
                'title_1' => 'Test',
                'title_2' => null,
                'customer_group_code' => 'G1',
                'active_abandon_code' => 0,
                'company_open_closed_flag' => 0,
                'locked_flag' => 0,
                'currency_index' => 0,
                'currency_code' => 'TRY',
                'source_updated_at' => '2026-07-31T10:00:00',
            ]],
            'meta' => ['should_not_escape' => true],
            'request' => ['query_template' => 'should_not_escape'],
        ])]);

        $result = app(N8nPanelDataGateway::class)->readForParity(
            MikroParitySource::CUSTOMER_DETAIL,
            ['customer_code' => 'C001'],
        );

        $this->assertSame('READY', $result['status']);
        $this->assertSame('TRY', $result['envelope']['currency_code']);
        $this->assertArrayNotHasKey('meta', $result);
        $this->assertArrayNotHasKey('request', $result);
        $this->assertSame($cacheBefore, DataSourceCache::query()->count());
        $this->assertSame($sourcesBefore, DataSource::query()->count());
        $this->assertFalse(DataSource::query()->where('code', MikroParitySource::CUSTOMER_DETAIL->value)->exists());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === $url
            && $request['source_code'] === MikroParitySource::CUSTOMER_DETAIL->value
            && $request['bypass_cache'] === true
            && $request['params'] === ['customer_code' => 'C001']
            && $request['allowed_params'] === ['customer_code']
            && $request['data_source']['active'] === false
            && str_starts_with($request['query_template'], 'SELECT TOP 1')
            && str_contains($request['query_template'], 'currency.KUR_NUMARASI = cari.cari_doviz_cinsi')
            && str_contains($request['query_template'], "= N'TL' THEN N'TRY'")
            && str_contains($request['query_template'], "N'[[customer_code]]'"));
    }

    public function test_public_or_generic_callers_cannot_select_parity_query_or_source(): void
    {
        Http::fake();

        try {
            app(N8nPanelDataGateway::class)->run(MikroParitySource::CUSTOMER_DETAIL->value, ['customer_code' => 'C001']);
            $this->fail('Generic gateway path must reject parity source codes.');
        } catch (RuntimeException $exception) {
            $this->assertSame('MIKRO_PARITY_SOURCE_REQUIRES_INTERNAL_TYPED_PATH', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_parity_read_rejects_non_local_v2_endpoint_before_network(): void
    {
        config()->set('panel.n8n_gateway_url', 'https://unapproved.example.test/webhook/panel-data-source-run-local-v2');
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MIKRO_PARITY_N8N_ENDPOINT_NOT_ALLOWLISTED');

        try {
            app(N8nPanelDataGateway::class)->readForParity(
                MikroParitySource::CUSTOMER_DETAIL,
                ['customer_code' => 'C001'],
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_parity_read_maps_http_failure_to_a_safe_status_only_code(): void
    {
        $url = 'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-local-v2';
        config()->set('panel.n8n_gateway_url', $url);
        Http::fake([$url => Http::response(['error' => 'raw provider detail'], 422)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('N8N_PARITY_HTTP_422');

        app(N8nPanelDataGateway::class)->readForParity(
            MikroParitySource::CUSTOMER_DETAIL,
            ['customer_code' => 'C001'],
        );
    }
}
