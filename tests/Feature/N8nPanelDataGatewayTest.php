<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Services\N8nPanelDataGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class N8nPanelDataGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_posts_datasource_metadata_to_configured_endpoint(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
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
            return $request->url() === 'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1'
                && $request['source_code'] === 'stock_dashboard'
                && $request['query_template'] === 'SELECT 1 AS miktar'
                && $request['data_source']['query_template_available'] === true
                && $request['params']['date_from'] === '2026-04-01';
        });
    }

    public function test_gateway_does_not_send_placeholder_query_as_runnable_sql(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
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
}
