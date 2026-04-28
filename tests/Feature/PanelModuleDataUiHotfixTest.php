<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageConfig;
use Database\Seeders\PanelDataSourcesSeeder;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PanelModuleDataUiHotfixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PanelMetadataSeeder::class);
        $this->seed(PanelDataSourcesSeeder::class);
        $this->seed(PanelKnownWorkflowDataSourcesSeeder::class);
    }

    public function test_module_pages_use_expected_datasources_and_sales_dashboard_component(): void
    {
        $expectedSources = [
            'sales_online' => 'sales_online_perakende_detail',
            'sales_bayi' => 'sales_bayi_proje_detail',
            'stock' => 'stock_dashboard',
            'orders_alinan' => 'orders_alinan',
            'orders_verilen' => 'orders_verilen',
            'cari' => 'customers_list',
            'cari_balance' => 'customers_balance',
        ];

        foreach ($expectedSources as $pageCode => $sourceCode) {
            $config = PageConfig::query()->with('dataSource')->where('page_code', $pageCode)->firstOrFail();

            $this->assertSame($sourceCode, $config->dataSource?->code, "{$pageCode} yanlış veri kaynağına bağlı.");
        }

        $this->assertSame('panel/sales-main', Page::query()->where('code', 'sales_online')->value('component'));
        $this->assertSame('panel/sales-main', Page::query()->where('code', 'sales_bayi')->value('component'));
    }

    public function test_stock_warehouse_is_hidden_from_user_navigation_and_tabs(): void
    {
        $stockWarehouse = Page::query()->where('code', 'stock_warehouse')->firstOrFail();

        $this->assertFalse((bool) DB::table('panel.page_menu')->where('page_id', $stockWarehouse->id)->value('is_visible'));

        $stockLayout = json_encode(PageConfig::query()->where('page_code', 'stock')->firstOrFail()->layout_json, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('/stock/warehouse', (string) $stockLayout);
    }

    public function test_frontend_format_helpers_keep_quantity_and_code_columns_clean(): void
    {
        [$exitCode, $output, $error] = $this->runNodeModule(<<<'JS'
            import { formatCell } from './resources/js/components/primecrm/format.js';

            console.log(JSON.stringify({
                toplam_miktar: formatCell(6, { key: 'toplam_miktar', label: 'Miktar' }),
                adet: formatCell(100, { key: 'adet', label: 'Adet' }),
                ciro: formatCell(100, { key: 'ciro', label: 'Ciro' }),
                evrak_no: formatCell(12345, { key: 'evrak_no', label: 'Evrak No' }),
                stok_kodu: formatCell('STK-1', { key: 'stok_kodu', label: 'Stok Kodu' }),
            }));
        JS);

        $this->assertSame(0, $exitCode, $error);

        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('6', $results['toplam_miktar']);
        $this->assertSame('100', $results['adet']);
        $this->assertSame('100,00 TL', $results['ciro']);
        $this->assertSame('12345', $results['evrak_no']);
        $this->assertSame('STK-1', $results['stok_kodu']);
    }

    public function test_stock_search_and_code_toggle_contract_exists_in_frontend(): void
    {
        $moduleData = file_get_contents(resource_path('js/components/primecrm/module-data.js')) ?: '';
        $panelPage = file_get_contents(resource_path('js/pages/panel/page.tsx')) ?: '';

        $this->assertStringContainsString('filterRowsForSearch', $moduleData);
        $this->assertStringContainsString('normalizeSearchText', $moduleData);
        $this->assertStringContainsString("'stokKodu'", $moduleData);
        $this->assertStringContainsString("'urunAdi'", $moduleData);
        $this->assertStringContainsString("'kategori'", $moduleData);
        $this->assertStringContainsString('Stok Kodu:', $panelPage);
        $this->assertStringContainsString('Eye', $panelPage);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function runNodeModule(string $script): array
    {
        $process = proc_open(
            'node --input-type=module',
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            base_path(),
        );

        if (! is_resource($process)) {
            $this->fail('Node process başlatılamadı.');
        }

        fwrite($pipes[0], $script);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);

        $error = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, trim($output), trim($error)];
    }
}
