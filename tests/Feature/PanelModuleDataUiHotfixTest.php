<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\Page;
use App\Models\PageConfig;
use App\Models\User;
use App\Models\UserAccess;
use App\Services\SalesMainPageService;
use Carbon\CarbonImmutable;
use Database\Seeders\PanelDataSourcesSeeder;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
                adet_kusurat: formatCell('1,5', { key: 'adet', label: 'Adet' }),
                ciro: formatCell(100, { key: 'ciro', label: 'Ciro' }),
                evrak_no: formatCell(12345, { key: 'evrak_no', label: 'Evrak No' }),
                siparis_no: formatCell(12345, { key: 'siparis_no', label: 'Sipariş No' }),
                stok_kodu: formatCell('STK-1', { key: 'stok_kodu', label: 'Stok Kodu' }),
            }));
        JS);

        $this->assertSame(0, $exitCode, $error);

        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('6', $results['toplam_miktar']);
        $this->assertSame('100', $results['adet']);
        $this->assertSame('1,5', $results['adet_kusurat']);
        $this->assertSame('100,00 TL', $results['ciro']);
        $this->assertSame('12345', $results['evrak_no']);
        $this->assertSame('12345', $results['siparis_no']);
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

    public function test_stock_category_filter_and_positive_quantity_contract(): void
    {
        [$exitCode, $output, $error] = $this->runNodeModule(<<<'JS'
            import { categoryOptionsForRows, filterRowsForSearch } from './resources/js/components/primecrm/module-data.js';

            const rows = [
                { stok_kodu: 'A1', stok_adi: 'Çekiç', kategori: 'El Aletleri', toplam_miktar: 6 },
                { stok_kodu: 'B1', stok_adi: 'Kablo', stok_kategori_adi: 'Elektrik', toplam_miktar: 3 },
                { stok_kodu: 'C1', stok_adi: 'Negatif', kategori: 'El Aletleri', toplam_miktar: -2 },
            ];

            console.log(JSON.stringify({
                categories: categoryOptionsForRows('stock', rows),
                filtered: filterRowsForSearch('stock', rows, 'cekic', { category: 'El Aletleri' }).map((row) => row.stok_kodu),
                positive: filterRowsForSearch('stock', rows, '', {}).map((row) => row.stok_kodu),
            }));
        JS);

        $this->assertSame(0, $exitCode, $error);

        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['El Aletleri', 'Elektrik'], $results['categories']);
        $this->assertSame(['A1'], $results['filtered']);
        $this->assertSame(['A1', 'B1'], $results['positive']);
    }

    public function test_stock_and_order_queries_keep_known_source_filters(): void
    {
        $stock = (string) DataSource::query()->where('code', 'stock_dashboard')->value('query_template');
        $alinan = (string) DataSource::query()->where('code', 'orders_alinan')->value('query_template');
        $verilen = (string) DataSource::query()->where('code', 'orders_verilen')->value('query_template');

        $this->assertStringContainsString('STOK_KATEGORILERI', $stock);
        $this->assertStringContainsString('STOK_MODEL_TANIMLARI', $stock);
        $this->assertStringContainsString('kategori', $stock);
        $this->assertMatchesRegularExpression('/HAVING\s+SUM\(miktar\)\s*>\s*0/i', $stock);

        $this->assertMatchesRegularExpression('/sip\.sip_tip\s*=\s*0/i', $alinan);
        $this->assertMatchesRegularExpression('/sip\.sip_iptal\s*=\s*0/i', $alinan);
        $this->assertMatchesRegularExpression('/sip\.sip_kapat_fl\s*=\s*0/i', $alinan);
        $this->assertStringContainsString('CARI_HESAPLAR', $alinan);
        $this->assertStringContainsString('CARI_HESAP_GRUPLARI', $alinan);
        $this->assertStringContainsString('STOK_MODEL_TANIMLARI', $alinan);
        $this->assertStringContainsString('kalan_miktar', $alinan);
        $this->assertStringContainsString("sip_evrakno_seri = N'B'", $alinan);
        $this->assertStringContainsString('Onay Bekleyen Siparişler', $alinan);
        $this->assertStringContainsString('Onaylı Siparişler', $alinan);
        $this->assertStringContainsString('DONUSUM APARAT', $alinan);
        $this->assertStringContainsString('YEDEK PARCA', $alinan);
        $this->assertStringContainsString('GARANTI DISI KONTROL', $alinan);
        $this->assertStringContainsString('sorumluluk_kodu', $alinan);
        $this->assertStringContainsString('kalan_tutar', $alinan);
        $this->assertStringContainsString('DECLARE @OrdersScope', $alinan);
        $this->assertStringContainsString('[[rep_code]]', $alinan);
        $this->assertStringContainsString('[[orders_scope]]', $alinan);
        $this->assertStringContainsString('[[brand_filter]]', $alinan);
        $this->assertStringContainsString('[[product_filter]]', $alinan);
        $this->assertStringContainsString('sto.sto_marka_kodu', $alinan);
        $this->assertStringContainsString('STRING_SPLIT(@ProductFilter', $alinan);
        $this->assertStringContainsString('brand_key', $alinan);
        $this->assertStringContainsString('marka', $alinan);

        $this->assertMatchesRegularExpression('/sip\.sip_tip\s*=\s*1/i', $verilen);
        $this->assertMatchesRegularExpression('/sip\.sip_iptal\s*=\s*0/i', $verilen);
        $this->assertMatchesRegularExpression('/sip\.sip_kapat_fl\s*=\s*0/i', $verilen);
        $this->assertStringContainsString('kalan_miktar', $verilen);
        $this->assertStringContainsString('STOK_KATEGORILERI', $verilen);
        $this->assertStringContainsString('STOK_MODEL_TANIMLARI', $verilen);
        $this->assertStringContainsString('sip_teslim_tarih', $verilen);
        $this->assertStringContainsString('teslim_tarihi', $verilen);
        $this->assertStringContainsString('tahmini_teslim_haftasi', $verilen);
        $this->assertStringContainsString('TESLİM TARİHİ BELİRSİZ', $verilen);
        $this->assertStringContainsString('teslim_sira', $verilen);
        $this->assertStringContainsString('stok_kategori_adi', $verilen);
        $this->assertStringContainsString('siparis_tutari', $verilen);
        $this->assertStringContainsString('[[brand_filter]]', $verilen);
        $this->assertStringContainsString('[[product_filter]]', $verilen);
        $this->assertStringContainsString('[[delivery_week]]', $verilen);
        $this->assertStringContainsString('[[delivery_date]]', $verilen);
        $this->assertStringContainsString('sto.sto_marka_kodu', $verilen);
        $this->assertStringContainsString('STRING_SPLIT(@ProductFilter', $verilen);
        $this->assertStringContainsString('@DeliveryWeek', $verilen);
        $this->assertStringContainsString('brand_key', $verilen);
        $this->assertStringContainsString('marka', $verilen);
        $this->assertNotSame($alinan, $verilen);

        $this->assertSame(
            ['search', 'date_from', 'date_to', 'status', 'rep_code', 'orders_scope', 'brand_filter', 'product_filter', 'page', 'limit', 'bypass_cache'],
            DataSource::query()->where('code', 'orders_alinan')->firstOrFail()->allowed_params,
        );
        $this->assertSame(
            ['search', 'date_from', 'date_to', 'brand_filter', 'product_filter', 'delivery_week', 'delivery_date', 'page', 'limit', 'bypass_cache'],
            DataSource::query()->where('code', 'orders_verilen')->firstOrFail()->allowed_params,
        );

        $this->assertDatabaseHas('panel.resources', [
            'code' => 'orders_alinan_all',
            'type' => 'scope',
            'active' => true,
        ]);
        $this->assertDatabaseHas('panel.resources', [
            'code' => 'orders_alinan_temsilci',
            'type' => 'scope',
            'active' => true,
        ]);
    }

    public function test_orders_dashboard_frontend_contract_and_delivery_week_helpers(): void
    {
        $page = file_get_contents(resource_path('js/pages/panel/page.tsx')) ?: '';
        $dashboard = file_get_contents(resource_path('js/pages/panel/orders/OrdersDashboard.jsx')) ?: '';
        $layout = file_get_contents(resource_path('js/layouts/module-layout.tsx')) ?: '';
        $routes = file_get_contents(base_path('routes/web.php')) ?: '';

        $this->assertStringContainsString("matchesPage('orders_alinan', '/orders/alinan')", $page);
        $this->assertStringContainsString("matchesPage('orders_verilen', '/orders/verilen')", $page);
        $this->assertStringContainsString('OrdersDashboard', $page);
        $this->assertStringContainsString('Alınan Siparişler', $dashboard);
        $this->assertStringContainsString('Verilen Siparişler', $dashboard);
        $this->assertStringContainsString('Onaylı Siparişler', $dashboard);
        $this->assertStringContainsString('Onay Bekleyen Siparişler', $dashboard);
        $this->assertStringContainsString('Tahmini Teslim Haftası', $dashboard);
        $this->assertStringContainsString('table-fixed', $dashboard);
        $this->assertStringContainsString('md:hidden', $dashboard);
        $this->assertStringContainsString('break-words', $dashboard);
        $this->assertStringContainsString('ProductPicker', $dashboard);
        $this->assertStringContainsString('OrdersPieChart', $dashboard);
        $this->assertStringContainsString('BrandFilter', $dashboard);
        $this->assertStringContainsString('DeliveryWeekFilter', $dashboard);
        $this->assertStringContainsString('Onaylı Açık Sipariş Satırı', $dashboard);
        $this->assertStringContainsString('Onay Bekleyen Açık Sipariş Satırı', $dashboard);
        $this->assertStringContainsString('brand_filter', $dashboard);
        $this->assertStringContainsString('product_filter', $dashboard);
        $this->assertStringContainsString('delivery_week', $dashboard);
        $this->assertStringContainsString('requestIdRef', $dashboard);
        $this->assertStringNotContainsString('disabled={loading}', $dashboard);
        $this->assertStringContainsString("candidates: ['/orders/alinan', '/orders/verilen', '/orders']", $layout);
        $this->assertStringContainsString("Route::get('orders', [PanelPageController::class, 'orders'])", $routes);

        [$exitCode, $output, $error] = $this->runNodeModule(<<<'JS'
            import { estimatedWeekLabel, groupGivenOrders } from './resources/js/pages/panel/orders/ordersUtils.js';

            const groups = groupGivenOrders([
                { teslim_tarihi: '2026-05-15', stok_adi: 'B Model', siparis_miktari: 1 },
                { teslim_tarihi: '2026-05-01', stok_adi: 'A Model', siparis_miktari: 2 },
                { teslim_tarihi: null, stok_adi: 'Z Model', siparis_miktari: 3 },
            ]);

            console.log(JSON.stringify({
                label: estimatedWeekLabel('2026-05-15'),
                groups: groups.map((group) => group.label),
            }));
        JS);

        $this->assertSame(0, $exitCode, $error);

        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame("MAYIS'IN 3. HAFTASI", $results['label']);
        $this->assertSame([
            "MAYIS'IN 1. HAFTASI",
            "MAYIS'IN 3. HAFTASI",
            'TESLİM TARİHİ BELİRSİZ',
        ], $results['groups']);
    }

    public function test_sales_online_and_bayi_use_processed_dashboard_config(): void
    {
        $service = app(SalesMainPageService::class);

        $online = $service->config(null, 'sales_online');
        $bayi = $service->config(null, 'sales_bayi');
        $onlineSource = DataSource::query()->where('code', 'sales_online_perakende_detail')->firstOrFail();

        $this->assertSame('sales_online_perakende_detail', $online['dataSource']['slug']);
        $this->assertSame('online_perakende', $online['defaults']['scopeKey']);
        $this->assertSame('panel/sales-main', $online['page']['component']);
        $this->assertTrue($onlineSource->active);
        $this->assertNotSame('', trim((string) $onlineSource->query_template));
        $this->assertSame(
            ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code', 'cari_filter', 'customer_filter', 'brand_filter', 'category_filter', 'product_filter', 'search', 'page', 'bypass_cache'],
            $onlineSource->allowed_params,
        );
        foreach (['120.01', '120.02', '120.03', '120.04', '120.05', '120.06', '120.07', '120.08', '120.09', '120.16'] as $groupCode) {
            $this->assertStringContainsString($groupCode, (string) $onlineSource->query_template);
        }

        $this->assertSame('sales_bayi_proje_detail', $bayi['dataSource']['slug']);
        $this->assertSame('bayi_proje', $bayi['defaults']['scopeKey']);
        $this->assertSame('panel/sales-main', $bayi['page']['component']);
    }

    public function test_sales_customer_search_datasource_uses_primecrm_customer_lookup(): void
    {
        $source = DataSource::query()->where('code', 'sales_customer_search')->firstOrFail();
        $query = (string) $source->query_template;

        $this->assertSame('n8n_json', $source->db_type);
        $this->assertContains('search', $source->allowed_params);
        $this->assertContains('rep_code', $source->allowed_params);
        $this->assertContains('scope_key', $source->allowed_params);
        $this->assertContains('date_from', $source->allowed_params);
        $this->assertContains('date_to', $source->allowed_params);
        $this->assertContains('grain', $source->allowed_params);
        $this->assertContains('detail_type', $source->allowed_params);
        $this->assertContains('limit', $source->allowed_params);
        $this->assertStringContainsString('DECLARE @Search', $query);
        $this->assertStringContainsString('DECLARE @RepCode', $query);
        $this->assertStringContainsString('DECLARE @ScopeKey', $query);
        $this->assertStringContainsString('DECLARE @date_from', $query);
        $this->assertStringContainsString('DECLARE @date_to', $query);
        $this->assertStringContainsString('DECLARE @CanViewAll', $query);
        $this->assertStringContainsString('fn_Stok_Masraf_Musteri_Grup_Hareket_Kubu', $query);
        $this->assertStringContainsString('CARI_HESAPLAR', $query);
        $this->assertStringContainsString('CARI_HESAP_GRUPLARI', $query);
        $this->assertStringContainsString("@ScopeKey = N'online_perakende'", $query);
        $this->assertStringContainsString("@ScopeKey = N'bayi_proje'", $query);
        $this->assertStringContainsString("ISNULL(cari.cari_grup_kodu, N'') IN", $query);
        $this->assertStringContainsString("cari.cari_grup_kodu NOT IN", $query);
        $this->assertStringContainsString('cari.cari_kod LIKE', $query);
        $this->assertStringContainsString('cari.cari_unvan1 LIKE', $query);
        $this->assertStringContainsString('grp.crg_isim', $query);
        $this->assertStringContainsString('cari.cari_temsilci_kodu', $query);
        $this->assertStringContainsString('ORDER BY', $query);
        $this->assertStringContainsString('toplam_ciro DESC', $query);
        $this->assertStringContainsString('cari_kodu ASC', $query);
        $this->assertStringContainsString('display_text', $query);
    }

    public function test_sales_datasources_apply_stock_category_code_whitelist_in_base_sales_rows(): void
    {
        $whitelistCodes = ['A1', 'AS1', 'D1', 'G1', 'K1', 'KA1', 'M1', 'O1', 'OT1', 'YM1'];
        $sources = [
            'sales_main_dashboard' => [
                'join' => 'LEFT JOIN STOKLAR sto WITH (NOLOCK)',
                'filter' => "UPPER(LTRIM(RTRIM(ISNULL(sto.sto_kategori_kodu, N'')))) IN",
                'fallback' => "OR UPPER(LTRIM(RTRIM(ISNULL(c.kategori_kodu_raw, N'')))) IN",
            ],
            'sales_online_perakende_detail' => [
                'join' => 'LEFT JOIN STOKLAR sto WITH (NOLOCK)',
                'filter' => "UPPER(LTRIM(RTRIM(ISNULL(sto.sto_kategori_kodu, N'')))) IN",
                'fallback' => "OR UPPER(LTRIM(RTRIM(ISNULL(c.kategori_kodu_raw, N'')))) IN",
            ],
            'sales_bayi_proje_detail' => [
                'join' => 'LEFT JOIN STOKLAR sto WITH (NOLOCK)',
                'filter' => "UPPER(LTRIM(RTRIM(ISNULL(sto.sto_kategori_kodu, N'')))) IN",
                'fallback' => "OR UPPER(LTRIM(RTRIM(ISNULL(c.kategori_kodu_raw, N'')))) IN",
            ],
            'sales_customer_search' => [
                'join' => 'INNER JOIN dbo.STOKLAR sto WITH (NOLOCK)',
                'filter' => "LTRIM(RTRIM(ISNULL(sto.sto_kategori_kodu, N''))) IN",
                'fallback' => null,
            ],
        ];

        foreach ($sources as $sourceCode => $expectations) {
            $query = (string) DataSource::query()->where('code', $sourceCode)->value('query_template');

            $this->assertStringContainsString($expectations['join'], $query, "{$sourceCode} STOKLAR join eksik.");
            $this->assertStringContainsString($expectations['filter'], $query, "{$sourceCode} kategori whitelist filtresi eksik.");
            $this->assertStringNotContainsString("N'X1'", $query, "{$sourceCode} whitelist dışı kategori kodu içeriyor.");

            foreach ($whitelistCodes as $categoryCode) {
                $this->assertStringContainsString("N'{$categoryCode}'", $query, "{$sourceCode} {$categoryCode} whitelist kodunu içermiyor.");
            }

            if ($expectations['fallback'] !== null) {
                $this->assertStringContainsString($expectations['fallback'], $query, "{$sourceCode} kategori_kodu_raw fallback filtresi eksik.");
            }

            $filterPosition = strpos($query, (string) $expectations['filter']);
            $basePosition = $sourceCode === 'sales_customer_search'
                ? strpos($query, 'filtered AS')
                : strpos($query, 'INTO #filtered');

            $this->assertNotFalse($basePosition, "{$sourceCode} base filtered bloğu bulunamadı.");
            $this->assertNotFalse($filterPosition, "{$sourceCode} whitelist filtresi bulunamadı.");
            $this->assertGreaterThan($basePosition, $filterPosition, "{$sourceCode} whitelist filtresi base satış satırları sonrası uygulanmalı.");
        }
    }

    public function test_sales_customer_search_sends_search_to_gateway_payload(): void
    {
        DB::table('panel.data_source_cache')->delete();

        $source = DataSource::query()->where('code', 'sales_customer_search')->firstOrFail();
        $this->assertTrue($source->active);
        $this->assertNotSame('', trim((string) $source->query_template));

        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'cari_kodu' => '120.00.001',
                        'cari_unvani' => 'Mehmet Test',
                        'cari_grubu' => 'Test Grup',
                        'display_text' => 'Mehmet Test | 120.00.001 | Test Grup',
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create(['role_code' => 'admin', 'aktif' => true]);
        DB::table('panel.user_access')->updateOrInsert(
            ['user_id' => $user->id, 'resource_code' => 'sales_main'],
            ['can_view' => true, 'created_at' => now(), 'updated_at' => now()],
        );

        $response = $this->actingAs($user)
            ->postJson('/api/data/sales_customer_search', [
                'search' => 'mehmet',
                'scope_key' => 'online_perakende',
                'date_from' => '2026-04-29',
                'date_to' => '2026-04-29',
                'grain' => 'day',
                'detail_type' => 'cari',
                'limit' => 80,
                'bypass_cache' => true,
            ]);

        $response->assertOk();
        $response->assertJsonPath('rows.0.cari_kodu', '120.00.001');
        $response->assertJsonPath('rows.0.cari_unvani', 'Mehmet Test');

        Http::assertSentCount(1);
        [$request] = Http::recorded()->first();
        $payload = $request->data();

        $this->assertSame('sales_customer_search', $payload['source_code'] ?? null);
        $this->assertSame('mehmet', $payload['search'] ?? null);
        $this->assertSame('mehmet', $payload['params']['search'] ?? null);
        $this->assertSame('online_perakende', $payload['scope_key'] ?? null);
        $this->assertSame('online_perakende', $payload['params']['scope_key'] ?? null);
        $this->assertSame('2026-04-29', $payload['date_from'] ?? null);
        $this->assertSame('2026-04-29', $payload['params']['date_from'] ?? null);
        $this->assertSame('2026-04-29', $payload['date_to'] ?? null);
        $this->assertSame('2026-04-29', $payload['params']['date_to'] ?? null);
        $this->assertSame('day', $payload['grain'] ?? null);
        $this->assertSame('day', $payload['params']['grain'] ?? null);
        $this->assertSame('cari', $payload['detail_type'] ?? null);
        $this->assertSame('cari', $payload['params']['detail_type'] ?? null);
        $this->assertTrue($payload['bypass_cache'] ?? false);
        $this->assertTrue($payload['params']['bypass_cache'] ?? false);
        $this->assertContains('search', $payload['allowed_params'] ?? []);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return ($payload['source_code'] ?? null) === 'sales_customer_search'
                && ($payload['search'] ?? null) === 'mehmet'
                && ($payload['params']['search'] ?? null) === 'mehmet'
                && ($payload['scope_key'] ?? null) === 'online_perakende'
                && ($payload['params']['scope_key'] ?? null) === 'online_perakende'
                && ($payload['date_from'] ?? null) === '2026-04-29'
                && ($payload['params']['date_from'] ?? null) === '2026-04-29'
                && ($payload['date_to'] ?? null) === '2026-04-29'
                && ($payload['params']['date_to'] ?? null) === '2026-04-29'
                && ($payload['grain'] ?? null) === 'day'
                && ($payload['params']['grain'] ?? null) === 'day'
                && ($payload['detail_type'] ?? null) === 'cari'
                && ($payload['params']['detail_type'] ?? null) === 'cari'
                && ($payload['bypass_cache'] ?? null) === true
                && ($payload['params']['bypass_cache'] ?? null) === true
                && in_array('search', $payload['allowed_params'] ?? [], true);
        });
    }

    public function test_sales_customer_search_enforces_scope_access(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'rows' => []]),
        ]);

        $onlineUser = User::factory()->create(['role_code' => 'viewer', 'aktif' => true]);
        DB::table('panel.user_access')->updateOrInsert(
            ['user_id' => $onlineUser->id, 'resource_code' => 'sales_online'],
            ['can_view' => true, 'created_at' => now(), 'updated_at' => now()],
        );

        $this->actingAs($onlineUser)
            ->postJson('/api/data/sales_customer_search', [
                'search' => 'mehmet',
                'scope_key' => 'all',
                'bypass_cache' => true,
            ])
            ->assertOk();

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return ($payload['source_code'] ?? null) === 'sales_customer_search'
                && ($payload['scope_key'] ?? null) === 'online_perakende'
                && ($payload['params']['scope_key'] ?? null) === 'online_perakende';
        });

        Http::fake([
            '*' => Http::response(['ok' => true, 'rows' => []]),
        ]);

        $bayiUser = User::factory()->create(['role_code' => 'viewer', 'aktif' => true]);
        DB::table('panel.user_access')->updateOrInsert(
            ['user_id' => $bayiUser->id, 'resource_code' => 'sales_bayi'],
            ['can_view' => true, 'created_at' => now(), 'updated_at' => now()],
        );

        $this->actingAs($bayiUser)
            ->postJson('/api/data/sales_customer_search', [
                'search' => 'mehmet',
                'scope_key' => 'online_perakende',
                'bypass_cache' => true,
            ])
            ->assertForbidden();
    }

    public function test_sales_datasources_accept_customer_filter_and_send_cari_filter_to_gateway(): void
    {
        foreach (['sales_main_dashboard', 'sales_online_perakende_detail', 'sales_bayi_proje_detail'] as $sourceCode) {
            $source = DataSource::query()->where('code', $sourceCode)->firstOrFail();

            $this->assertContains('cari_filter', $source->allowed_params);
            $this->assertContains('customer_filter', $source->allowed_params);
        }

        $salesMainQuery = (string) DataSource::query()->where('code', 'sales_main_dashboard')->value('query_template');

        $this->assertStringContainsString('DECLARE @cari_filter', $salesMainQuery);
        $this->assertStringContainsString('@cari_filter', $salesMainQuery);
        $this->assertStringContainsString('STRING_SPLIT(@cari_filter', $salesMainQuery);
        $this->assertStringContainsString('c.cari_kodu = @cari_filter', $salesMainQuery);
        $this->assertStringContainsString('ch.cari_unvan1 LIKE', $salesMainQuery);
        $this->assertStringContainsString('INNER JOIN CARI_HESAPLAR ch', $salesMainQuery);
        $this->assertStringContainsString('konsinye_tutari', $salesMainQuery);
        $this->assertStringContainsString('excluded_from_total', $salesMainQuery);
        $this->assertStringContainsString("N'KONSINYE' AS satir_tipi", $salesMainQuery);

        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 1,
                        'cari_grup_adi' => 'Grup A',
                        'adet' => 1,
                        'ciro' => 100,
                    ],
                ],
            ]),
        ]);

        app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'online_perakende',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'customer_filter' => 'C-1,C-2',
            'bypass_cache' => true,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['source_code'] ?? null) === 'sales_online_perakende_detail'
                && ($payload['cari_filter'] ?? null) === 'C-1,C-2'
                && ($payload['customer_filter'] ?? null) === 'C-1,C-2'
                && ($payload['scope_key'] ?? null) === 'online_perakende'
                && ($payload['params']['cari_filter'] ?? null) === 'C-1,C-2'
                && ($payload['params']['customer_filter'] ?? null) === 'C-1,C-2';
        });

        app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'bayi_proje',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'customer_filter' => 'B-1',
            'bypass_cache' => true,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['source_code'] ?? null) === 'sales_bayi_proje_detail'
                && ($payload['cari_filter'] ?? null) === 'B-1'
                && ($payload['customer_filter'] ?? null) === 'B-1'
                && ($payload['scope_key'] ?? null) === 'bayi_proje';
        });

        app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'all',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'customer_filter' => 'A-1',
            'bypass_cache' => true,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['source_code'] ?? null) === 'sales_main_dashboard'
                && ($payload['cari_filter'] ?? null) === 'A-1'
                && ($payload['customer_filter'] ?? null) === 'A-1'
                && ($payload['scope_key'] ?? null) === 'all';
        });
    }

    public function test_sales_product_detail_filters_are_allowed_and_sent_only_for_product_mode(): void
    {
        foreach (['sales_main_dashboard', 'sales_online_perakende_detail', 'sales_bayi_proje_detail'] as $sourceCode) {
            $source = DataSource::query()->where('code', $sourceCode)->firstOrFail();

            $this->assertContains('brand_filter', $source->allowed_params);
            $this->assertContains('category_filter', $source->allowed_params);
            $this->assertContains('product_filter', $source->allowed_params);
        }

        $query = (string) DataSource::query()->where('code', 'sales_main_dashboard')->value('query_template');
        $gateway = file_get_contents(app_path('Services/N8nPanelDataGateway.php')) ?: '';

        $this->assertStringContainsString('DECLARE @brand_filter', $query);
        $this->assertStringContainsString('DECLARE @category_filter', $query);
        $this->assertStringContainsString('DECLARE @product_filter', $query);
        $this->assertStringContainsString('LEFT JOIN STOK_MARKALARI mrk WITH (NOLOCK)', $query);
        $this->assertStringContainsString('sto.sto_marka_kodu', $query);
        $this->assertStringContainsString('mrk.mrk_ismi', $query);
        $this->assertStringContainsString('brand_code', $query);
        $this->assertStringContainsString('brand_name', $query);
        $this->assertStringContainsString('marka_adi', $query);
        $this->assertStringContainsString("@brand_filter = N'philips'", $query);
        $this->assertStringContainsString("@brand_filter = N'emaks_prime'", $query);
        $this->assertStringContainsString("UPPER(LTRIM(RTRIM(ISNULL(c.stok_kodu_raw, N'')))) LIKE N'%PHILIPS%'", $query);
        $this->assertStringContainsString("UPPER(LTRIM(RTRIM(ISNULL(c.urun_adi_raw, N'')))) LIKE N'%PHILIPS%'", $query);
        $this->assertStringContainsString("UPPER(LTRIM(RTRIM(ISNULL(c.model_adi_raw, N'')))) LIKE N'%EMAKS%'", $query);
        $this->assertStringContainsString("UPPER(LTRIM(RTRIM(ISNULL(sto.sto_isim, N'')))) LIKE N'%EMAKS%'", $query);
        $this->assertStringContainsString('sto.sto_kategori_kodu', $query);
        $this->assertStringContainsString('c.kategori_kodu_raw', $query);
        $this->assertStringContainsString("OR UPPER(LTRIM(RTRIM(ISNULL(c.kategori_kodu_raw, N'')))) = @category_filter", $query);
        $this->assertStringContainsString('c.stok_kodu_raw', $query);
        $this->assertStringContainsString('c.urun_adi_raw', $query);
        $this->assertStringContainsString('c.model_adi_raw', $query);
        $this->assertStringContainsString('sto.sto_isim', $query);
        $this->assertStringContainsString("foreach (['brand_filter', 'category_filter', 'product_filter'] as \$optionalFilter)", $gateway);
        $this->assertStringContainsString("->except(['bypass_cache'])", $gateway);

        foreach (['sales_online_perakende_detail', 'sales_bayi_proje_detail'] as $sourceCode) {
            $generatedQuery = (string) DataSource::query()->where('code', $sourceCode)->value('query_template');

            $this->assertStringContainsString('DECLARE @brand_filter', $generatedQuery);
            $this->assertStringContainsString('DECLARE @category_filter', $generatedQuery);
            $this->assertStringContainsString('DECLARE @product_filter', $generatedQuery);
        }

        DB::table('panel.data_source_cache')->delete();

        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 1, 'cari_grup_adi' => 'Model P', 'satir_adi' => 'Model P', 'adet' => 1, 'ciro' => 100, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS', 'konsinye_tutari' => 222],
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 2, 'cari_grup_adi' => 'Model E', 'satir_adi' => 'Model E', 'adet' => 2, 'ciro' => 200, 'brand_code' => 'EMAKS', 'brand_name' => 'EMAKS PRIME'],
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 3, 'cari_grup_adi' => 'Model X', 'satir_adi' => 'Model X', 'adet' => 1, 'ciro' => 50, 'brand_code' => 'X', 'brand_name' => 'X MARKA'],
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 4, 'cari_grup_adi' => 'DDL720 FVP', 'satir_adi' => 'DDL720 FVP', 'adet' => 3, 'ciro' => 300, 'brand_code' => 'X', 'brand_name' => 'X MARKA'],
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 5, 'cari_grup_adi' => 'DDL720 MVP', 'satir_adi' => 'DDL720 MVP', 'adet' => 2, 'ciro' => 200, 'brand_code' => 'X', 'brand_name' => 'X MARKA'],
                    ['satir_tipi' => 'KATEGORI', 'cari_grup_adi' => 'AKILLI KİLİT', 'parent_key' => 'Model P', 'satir_adi' => 'AKILLI KİLİT', 'kategori_kodu' => 'A1', 'adet' => 4, 'ciro' => 350],
                ],
            ]),
        ]);

        $productPayload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'online_perakende',
            'detail_type' => 'urun',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'brand_filter' => 'philips',
            'category_filter' => 'AS1',
            'product_filter' => 'kilit',
            'bypass_cache' => true,
        ]);

        $this->assertSame('PHILIPS Ürün Satış Dağılımı', $productPayload['chart']['title']);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['source_code'] ?? null) === 'sales_online_perakende_detail'
                && ($payload['detail_type'] ?? null) === 'urun'
                && ($payload['brand_filter'] ?? null) === 'philips'
                && ($payload['category_filter'] ?? null) === 'AS1'
                && ($payload['product_filter'] ?? null) === 'kilit'
                && ($payload['params']['brand_filter'] ?? null) === 'philips'
                && ($payload['params']['category_filter'] ?? null) === 'AS1'
                && ($payload['params']['product_filter'] ?? null) === 'kilit';
        });

        DB::table('panel.data_source_cache')->delete();

        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 1,
                        'cari_grup_adi' => 'DDL720 FVP',
                        'satir_adi' => 'DDL720 FVP',
                        'adet' => 3,
                        'ciro' => 300,
                        'brand_code' => 'PHILIPS',
                        'brand_name' => 'PHILIPS',
                        'marka_adi' => 'PHILIPS',
                    ],
                ],
                'request' => [],
                'meta' => [],
            ]),
        ]);

        $routeResponse = $this
            ->actingAs(User::factory()->create(['role_code' => 'admin']))
            ->postJson('/api/data/sales-main', [
                'detail_type' => 'urun',
                'brand_filter' => 'philips',
                'category_filter' => 'A1',
                'product_filter' => '720 fvp',
                'bypass_cache' => true,
            ]);

        $routeResponse->assertOk();
        $routeResponse->assertJsonPath('filters.brandFilter', 'philips');
        $routeResponse->assertJsonPath('filters.categoryFilter', 'A1');
        $routeResponse->assertJsonPath('filters.productFilter', '720 fvp');

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['source_code'] ?? null) === 'sales_main_dashboard'
                && ($payload['detail_type'] ?? null) === 'urun'
                && ($payload['brand_filter'] ?? null) === 'philips'
                && ($payload['category_filter'] ?? null) === 'A1'
                && ($payload['product_filter'] ?? null) === '720 fvp'
                && ($payload['params']['brand_filter'] ?? null) === 'philips'
                && ($payload['params']['category_filter'] ?? null) === 'A1'
                && ($payload['params']['product_filter'] ?? null) === '720 fvp';
        });

        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 1,
                        'cari_grup_adi' => 'Model P',
                        'satir_adi' => 'Model P',
                        'adet' => 1,
                        'ciro' => 100,
                        'brand_code' => 'PHILIPS',
                        'brand_name' => 'PHILIPS',
                        'marka_adi' => 'PHILIPS',
                        'konsinye_tutari' => 222,
                    ],
                    [
                        'satir_tipi' => 'DETAY',
                        'cari_grup_adi' => 'Model P',
                        'parent_key' => 'Model P',
                        'satir_adi' => 'Cari A',
                        'adet' => 1,
                        'ciro' => 100,
                        'brand_code' => 'PHILIPS',
                        'brand_name' => 'PHILIPS',
                        'marka_adi' => 'PHILIPS',
                    ],
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 2,
                        'cari_grup_adi' => 'Model E',
                        'satir_adi' => 'Model E',
                        'adet' => 2,
                        'ciro' => 200,
                        'brand_code' => 'EMAKS',
                        'brand_name' => 'EMAKS PRIME',
                        'marka_adi' => 'EMAKS PRIME',
                    ],
                    [
                        'satir_tipi' => 'DETAY',
                        'cari_grup_adi' => 'Model E',
                        'parent_key' => 'Model E',
                        'satir_adi' => 'Cari B',
                        'adet' => 2,
                        'ciro' => 200,
                        'brand_code' => 'EMAKS',
                        'brand_name' => 'EMAKS PRIME',
                        'marka_adi' => 'EMAKS PRIME',
                    ],
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 3,
                        'cari_grup_adi' => 'Model X',
                        'satir_adi' => 'Model X',
                        'adet' => 1,
                        'ciro' => 50,
                        'brand_code' => 'X',
                        'brand_name' => 'X MARKA',
                        'marka_adi' => 'X MARKA',
                    ],
                    [
                        'satir_tipi' => 'DETAY',
                        'cari_grup_adi' => 'Model X',
                        'parent_key' => 'Model X',
                        'satir_adi' => 'Cari C',
                        'adet' => 1,
                        'ciro' => 50,
                        'brand_code' => 'X',
                        'brand_name' => 'X MARKA',
                        'marka_adi' => 'X MARKA',
                    ],
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 4,
                        'cari_grup_adi' => 'KONSINYE',
                        'satir_adi' => 'KONSINYE',
                        'adet' => 9,
                        'ciro' => 900,
                        'brand_code' => 'PHILIPS',
                        'brand_name' => 'PHILIPS',
                        'marka_adi' => 'PHILIPS',
                    ],
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 5,
                        'cari_grup_adi' => 'Excluded Model',
                        'satir_adi' => 'Excluded Model',
                        'adet' => 4,
                        'ciro' => 400,
                        'brand_code' => 'PHILIPS',
                        'brand_name' => 'PHILIPS',
                        'marka_adi' => 'PHILIPS',
                        'excluded_from_total' => true,
                    ],
                    [
                        'satir_tipi' => 'KONSINYE',
                        'cari_grup_adi' => 'Model P',
                        'parent_key' => 'Model P',
                        'satir_adi' => 'KONSINYE - Cari A',
                        'adet' => 2,
                        'ciro' => 222,
                        'brand_code' => 'PHILIPS',
                        'brand_name' => 'PHILIPS',
                        'marka_adi' => 'PHILIPS',
                        'excluded_from_total' => true,
                        'konsinye_tutari' => 222,
                    ],
                    [
                        'satir_tipi' => 'KATEGORI',
                        'cari_grup_adi' => 'AKILLI KİLİT',
                        'parent_key' => 'Model P',
                        'satir_adi' => 'AKILLI KİLİT',
                        'kategori_kodu' => 'A1',
                        'adet' => 4,
                        'ciro' => 350,
                        'brand_code' => 'PHILIPS',
                        'brand_name' => 'PHILIPS',
                        'marka_adi' => 'PHILIPS',
                    ],
                ],
            ]),
        ]);

        DB::table('panel.data_source_cache')->delete();

        $brandComparison = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'all',
            'detail_type' => 'urun',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'brand_filter' => 'all',
            'category_filter' => 'all',
            'product_filter' => 'model',
            'bypass_cache' => true,
        ]);

        $this->assertSame('Ürün Satış Dağılımı', $brandComparison['chart']['title']);
        $this->assertNotSame([], $brandComparison['chart']['items']);
        $this->assertSame(['Model P', 'Model E', 'Model X'], array_column($brandComparison['chart']['items'], 'label'));
        $this->assertSame(['PHILIPS', 'EMAKS PRIME', 'Diğer Marka'], array_column($brandComparison['brandComparison']['items'], 'label'));
        $this->assertSame(['Model P', 'Model E', 'Model X'], array_column($brandComparison['breakdown']['groups'], 'label'));
        $this->assertSame(['Model P', 'Model E', 'Model X'], array_column($brandComparison['productOptions'], 'label'));
        $this->assertSame(['PHILIPS', 'EMAKS PRIME', 'Diğer Marka'], array_column($brandComparison['productOptions'], 'brand'));
        $this->assertSame(222.0, $brandComparison['chart']['konsinyeAmount']);
        $this->assertNotContains('KONSINYE', array_column($brandComparison['chart']['items'], 'label'));
        $this->assertNotContains('KONSINYE', array_column($brandComparison['brandComparison']['items'], 'label'));
        $this->assertNotContains('KONSINYE', array_column($brandComparison['breakdown']['groups'], 'label'));
        $this->assertNotContains('Excluded Model', array_column($brandComparison['chart']['items'], 'label'));
        $this->assertNotContains('Excluded Model', array_column($brandComparison['breakdown']['groups'], 'label'));
        $this->assertNotContains('AKILLI KİLİT', array_column($brandComparison['breakdown']['groups'], 'label'));

        DB::table('panel.data_source_cache')->delete();

        $categoryPayload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'all',
            'detail_type' => 'urun',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'brand_filter' => 'all',
            'category_filter' => 'A1',
            'product_filter' => '',
            'bypass_cache' => true,
        ]);

        $this->assertSame('Ürün Satış Dağılımı', $categoryPayload['chart']['title']);
        $this->assertNotSame([], $categoryPayload['chart']['items']);
        $this->assertContains('Model P', array_column($categoryPayload['chart']['items'], 'label'));
        $this->assertContains('PHILIPS', array_column($categoryPayload['brandComparison']['items'], 'label'));
        $this->assertContains('Model P', array_column($categoryPayload['breakdown']['groups'], 'label'));
        $this->assertNotContains('KONSINYE', array_column($categoryPayload['chart']['items'], 'label'));
        $this->assertNotContains('KONSINYE', array_column($categoryPayload['breakdown']['groups'], 'label'));

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['source_code'] ?? null) === 'sales_main_dashboard'
                && ($payload['detail_type'] ?? null) === 'urun'
                && ($payload['category_filter'] ?? null) === 'A1'
                && ($payload['params']['category_filter'] ?? null) === 'A1';
        });

        DB::table('panel.data_source_cache')->delete();

        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 1, 'cari_grup_adi' => 'Model P', 'satir_adi' => 'Model P', 'adet' => 1, 'ciro' => 100, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS'],
                    ['satir_tipi' => 'DETAY', 'cari_grup_adi' => 'Model P', 'parent_key' => 'Model P', 'satir_adi' => 'Cari A', 'adet' => 1, 'ciro' => 100, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS'],
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 2, 'cari_grup_adi' => 'Model E', 'satir_adi' => 'Model E', 'adet' => 2, 'ciro' => 200, 'brand_code' => 'EMAKS', 'brand_name' => 'EMAKS PRIME'],
                    ['satir_tipi' => 'DETAY', 'cari_grup_adi' => 'Model E', 'parent_key' => 'Model E', 'satir_adi' => 'Cari B', 'adet' => 2, 'ciro' => 200, 'brand_code' => 'EMAKS', 'brand_name' => 'EMAKS PRIME'],
                ],
            ]),
        ]);

        $philipsPayload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'all',
            'detail_type' => 'urun',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'brand_filter' => 'philips',
            'bypass_cache' => true,
        ]);

        $this->assertSame('PHILIPS Ürün Satış Dağılımı', $philipsPayload['chart']['title']);
        $this->assertSame(['Model P'], array_column($philipsPayload['chart']['items'], 'label'));
        $this->assertSame(['PHILIPS'], array_column($philipsPayload['brandComparison']['items'], 'label'));
        $this->assertSame(['Model P'], array_column($philipsPayload['breakdown']['groups'], 'label'));

        DB::table('panel.data_source_cache')->delete();

        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 1, 'cari_grup_adi' => 'Model P', 'satir_adi' => 'Model P', 'adet' => 1, 'ciro' => 100, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS'],
                    ['satir_tipi' => 'DETAY', 'cari_grup_adi' => 'Model P', 'parent_key' => 'Model P', 'satir_adi' => 'Cari A', 'adet' => 1, 'ciro' => 100, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS'],
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 2, 'cari_grup_adi' => 'Model E', 'satir_adi' => 'Model E', 'adet' => 2, 'ciro' => 200, 'brand_code' => 'EMAKS', 'brand_name' => 'EMAKS PRIME'],
                    ['satir_tipi' => 'DETAY', 'cari_grup_adi' => 'Model E', 'parent_key' => 'Model E', 'satir_adi' => 'Cari B', 'adet' => 2, 'ciro' => 200, 'brand_code' => 'EMAKS', 'brand_name' => 'EMAKS PRIME'],
                ],
            ]),
        ]);

        $emaksPayload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'all',
            'detail_type' => 'urun',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'brand_filter' => 'emaks_prime',
            'bypass_cache' => true,
        ]);

        $this->assertSame('EMAKS PRIME Ürün Satış Dağılımı', $emaksPayload['chart']['title']);
        $this->assertSame(['Model E'], array_column($emaksPayload['chart']['items'], 'label'));
        $this->assertSame(['EMAKS PRIME'], array_column($emaksPayload['brandComparison']['items'], 'label'));
        $this->assertSame(['Model E'], array_column($emaksPayload['breakdown']['groups'], 'label'));

        DB::table('panel.data_source_cache')->delete();

        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'rows' => [
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 1, 'cari_grup_adi' => 'DDL720 FVP', 'satir_adi' => 'DDL720 FVP', 'adet' => 3, 'ciro' => 300, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS'],
                    ['satir_tipi' => 'DETAY', 'cari_grup_adi' => 'DDL720 FVP', 'parent_key' => 'DDL720 FVP', 'satir_adi' => 'Cari A', 'adet' => 3, 'ciro' => 300, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS'],
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 2, 'cari_grup_adi' => 'DDL720 MVP', 'satir_adi' => 'DDL720 MVP', 'adet' => 2, 'ciro' => 200, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS'],
                    ['satir_tipi' => 'DETAY', 'cari_grup_adi' => 'DDL720 MVP', 'parent_key' => 'DDL720 MVP', 'satir_adi' => 'Cari B', 'adet' => 2, 'ciro' => 200, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS'],
                ],
            ]),
        ]);

        $productSearchPayload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'all',
            'detail_type' => 'urun',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'brand_filter' => 'all',
            'category_filter' => 'all',
            'product_filter' => '720 fvp',
            'bypass_cache' => true,
        ]);

        $this->assertSame(['DDL720 FVP'], array_column($productSearchPayload['chart']['items'], 'label'));
        $this->assertSame(['DDL720 FVP'], array_column($productSearchPayload['breakdown']['groups'], 'label'));

        DB::table('panel.data_source_cache')->delete();

        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 1, 'cari_grup_adi' => 'DDL720 FVP', 'satir_adi' => 'DDL720 FVP', 'adet' => 3, 'ciro' => 300, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS'],
                    ['satir_tipi' => 'DETAY', 'cari_grup_adi' => 'DDL720 FVP', 'parent_key' => 'DDL720 FVP', 'satir_adi' => 'Cari A', 'adet' => 3, 'ciro' => 300, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS'],
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 2, 'cari_grup_adi' => 'DDL720 MVP', 'satir_adi' => 'DDL720 MVP', 'adet' => 2, 'ciro' => 200, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS'],
                    ['satir_tipi' => 'DETAY', 'cari_grup_adi' => 'DDL720 MVP', 'parent_key' => 'DDL720 MVP', 'satir_adi' => 'Cari B', 'adet' => 2, 'ciro' => 200, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS'],
                    ['satir_tipi' => 'GRUP', 'siralama_1' => 3, 'cari_grup_adi' => 'DDL303', 'satir_adi' => 'DDL303', 'adet' => 1, 'ciro' => 100, 'brand_code' => 'PHILIPS', 'brand_name' => 'PHILIPS'],
                ],
            ]),
        ]);

        $multiProductPayload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'all',
            'detail_type' => 'urun',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'brand_filter' => 'all',
            'category_filter' => 'all',
            'product_filter' => 'DDL720 FVP, DDL720 MVP',
            'bypass_cache' => true,
        ]);

        $this->assertSame(['DDL720 FVP', 'DDL720 MVP'], array_column($multiProductPayload['chart']['items'], 'label'));
        $this->assertSame(['DDL720 FVP', 'DDL720 MVP'], array_column($multiProductPayload['breakdown']['groups'], 'label'));
        $this->assertSame(['DDL720 FVP', 'DDL720 MVP'], array_column($multiProductPayload['productOptions'], 'label'));

        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 1,
                        'cari_grup_adi' => 'Grup A',
                        'adet' => 1,
                        'ciro' => 100,
                    ],
                ],
            ]),
        ]);

        app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'all',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'brand_filter' => 'emaks_prime',
            'category_filter' => 'A1',
            'product_filter' => 'kilit',
            'bypass_cache' => true,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['source_code'] ?? null) === 'sales_main_dashboard'
                && ($payload['detail_type'] ?? null) === 'cari'
                && ! array_key_exists('brand_filter', $payload)
                && ! array_key_exists('category_filter', $payload)
                && ! array_key_exists('product_filter', $payload)
                && ! array_key_exists('brand_filter', $payload['params'] ?? [])
                && ! array_key_exists('category_filter', $payload['params'] ?? [])
                && ! array_key_exists('product_filter', $payload['params'] ?? []);
        });
    }

    public function test_sales_selected_customer_empty_rows_return_empty_dataset_without_502(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::sequence()
                ->push(['ok' => true, 'rows' => []])
                ->push(['ok' => true, 'rows' => []]),
        ]);

        $payload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'online_perakende',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'customer_filter' => 'C-404',
            'bypass_cache' => true,
        ]);

        $this->assertSame('sales_main_dashboard', $payload['queryMeta']['dataSource']);
        $this->assertSame('Seçili müşteri için bu kapsam/dönemde satış kaydı bulunamadı.', $payload['queryMeta']['notice']);
        $this->assertSame([], $payload['table']['rows']);
        $this->assertEquals(0, $payload['kpis'][0]['raw']);
        $this->assertEquals(0, $payload['chart']['totalNet']);
    }

    public function test_sales_empty_rows_return_empty_dataset_without_customer_filter(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [],
            ]),
        ]);

        $payload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'all',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        $this->assertSame('sales_main_dashboard', $payload['queryMeta']['dataSource']);
        $this->assertSame('Seçili filtrelerde satış kaydı bulunamadı.', $payload['queryMeta']['notice']);
        $this->assertSame([], $payload['table']['rows']);
        $this->assertEquals(0, $payload['chart']['totalNet']);
    }

    public function test_sales_filters_default_to_current_year_range_when_year_grain_without_dates(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 4, 30, 12, 0, 0));

        try {
            Http::fake([
                'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                    'ok' => true,
                    'rows' => [],
                ]),
            ]);

            $payload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
                'scope_key' => 'all',
                'detail_type' => 'cari',
                'grain' => 'year',
                'bypass_cache' => true,
            ]);

            $this->assertSame('2026-01-01', $payload['filters']['dateFrom']);
            $this->assertSame('2026-04-30', $payload['filters']['dateTo']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_sales_filters_default_to_current_month_range_when_month_grain_without_dates(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 4, 30, 12, 0, 0));

        try {
            Http::fake([
                'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                    'ok' => true,
                    'rows' => [],
                ]),
            ]);

            $payload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
                'scope_key' => 'all',
                'detail_type' => 'cari',
                'grain' => 'month',
                'bypass_cache' => true,
            ]);

            $this->assertSame('2026-04-01', $payload['filters']['dateFrom']);
            $this->assertSame('2026-04-30', $payload['filters']['dateTo']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_sales_filters_default_to_today_when_day_grain_without_dates(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 4, 30, 12, 0, 0));

        try {
            Http::fake([
                'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                    'ok' => true,
                    'rows' => [],
                ]),
            ]);

            $payload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
                'scope_key' => 'all',
                'detail_type' => 'cari',
                'grain' => 'day',
                'bypass_cache' => true,
            ]);

            $this->assertSame('2026-04-30', $payload['filters']['dateFrom']);
            $this->assertSame('2026-04-30', $payload['filters']['dateTo']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_sales_online_and_bayi_scope_allow_without_representative_code_sends_null_rep_code(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 1,
                        'cari_grup_adi' => 'Grup A',
                        'adet' => 1,
                        'ciro' => 100,
                    ],
                ],
            ]),
        ]);

        $onlineUser = User::factory()->create(['role_code' => 'viewer', 'temsilci_kodu' => null]);
        UserAccess::query()->create([
            'user_id' => $onlineUser->id,
            'resource_code' => 'sales_online',
            'can_view' => true,
        ]);

        $onlinePayload = app(SalesMainPageService::class)->dataset($onlineUser, [
            'scope_key' => 'online_perakende',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        $this->assertSame('sales_online_perakende_detail', $onlinePayload['queryMeta']['dataSource']);
        $this->assertSame('online_perakende', $onlinePayload['scope']['key']);
        $this->assertNull($onlinePayload['scope']['effectiveRepresentativeCode']);
        $this->assertNull($onlinePayload['queryMeta']['gatewayRequest']['rep_code'] ?? null);

        $bayiUser = User::factory()->create(['role_code' => 'viewer', 'temsilci_kodu' => null]);
        UserAccess::query()->create([
            'user_id' => $bayiUser->id,
            'resource_code' => 'sales_bayi',
            'can_view' => true,
        ]);

        $bayiPayload = app(SalesMainPageService::class)->dataset($bayiUser, [
            'scope_key' => 'bayi_proje',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        $this->assertSame('sales_bayi_proje_detail', $bayiPayload['queryMeta']['dataSource']);
        $this->assertSame('bayi_proje', $bayiPayload['scope']['key']);
        $this->assertNull($bayiPayload['scope']['effectiveRepresentativeCode']);
        $this->assertNull($bayiPayload['queryMeta']['gatewayRequest']['rep_code'] ?? null);
    }

    public function test_sales_online_empty_special_source_falls_back_to_main_dashboard_with_same_scope(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::sequence()
                ->push(['ok' => true, 'rows' => []])
                ->push([
                    'ok' => true,
                    'rows' => [
                        [
                            'satir_tipi' => 'GRUP',
                            'siralama_1' => 1,
                            'cari_grup_adi' => 'Online Grup',
                            'adet' => 2,
                            'ciro' => 250,
                        ],
                    ],
                ]),
        ]);

        $payload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'online_perakende',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        $this->assertSame('sales_main_dashboard', $payload['queryMeta']['dataSource']);
        $this->assertSame('online_perakende', $payload['scope']['key']);
        $this->assertEquals(250, $payload['chart']['totalNet']);

        Http::assertSentCount(2);
    }

    public function test_user_without_sales_scope_cannot_fetch_sales_dataset(): void
    {
        $user = User::factory()->create(['role_code' => 'viewer', 'temsilci_kodu' => null]);

        try {
            app(SalesMainPageService::class)->dataset($user, [
                'scope_key' => 'online_perakende',
                'detail_type' => 'cari',
                'grain' => 'week',
                'date_from' => '2026-04-01',
                'date_to' => '2026-04-28',
                'bypass_cache' => true,
            ]);

            $this->fail('Yetkisiz satis scope istegi 403 ile kesilmeliydi.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_sales_konsinye_rows_are_excluded_from_totals_while_teshir_rows_are_included(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 1,
                        'cari_grup_adi' => 'Normal Grup',
                        'adet' => 1,
                        'ciro' => 100,
                        'excluded_from_total' => 0,
                        'konsinye_tutari' => 900,
                    ],
                    [
                        'satir_tipi' => 'CARI',
                        'cari_grup_adi' => 'Normal Grup',
                        'cari_kodu' => 'C-1',
                        'satir_adi' => 'Normal Müşteri',
                        'adet' => 1,
                        'ciro' => 100,
                        'excluded_from_total' => 0,
                        'konsinye_tutari' => 900,
                    ],
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 2,
                        'cari_grup_adi' => 'Teşhir Grup',
                        'adet' => 1,
                        'ciro' => 300,
                        'excluded_from_total' => 0,
                        'konsinye_tutari' => 900,
                    ],
                    [
                        'satir_tipi' => 'CARI',
                        'cari_grup_adi' => 'Teşhir Grup',
                        'cari_kodu' => 'C-2.TESHIR',
                        'satir_adi' => 'Teşhir Müşteri - TEŞHİR HESABI',
                        'adet' => 1,
                        'ciro' => 300,
                        'excluded_from_total' => 0,
                        'konsinye_tutari' => 900,
                    ],
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 999998,
                        'cari_grup_adi' => 'KONSİNYE',
                        'adet' => 0,
                        'ciro' => 900,
                        'excluded_from_total' => 1,
                        'konsinye_tutari' => 900,
                    ],
                    [
                        'satir_tipi' => 'KONSINYE',
                        'cari_grup_adi' => 'KONSİNYE',
                        'cari_kodu' => 'C-1.KONSINYE',
                        'satir_adi' => 'KONSİNYE - Normal Müşteri',
                        'adet' => 1,
                        'ciro' => 900,
                        'excluded_from_total' => 1,
                        'konsinye_tutari' => 900,
                    ],
                ],
            ]),
        ]);

        $payload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'all',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        $this->assertEquals(400, $payload['kpis'][0]['raw']);
        $this->assertEquals(400, $payload['chart']['totalNet']);
        $this->assertSame(['Normal Grup', 'Teşhir Grup'], array_column($payload['chart']['items'], 'label'));
        $this->assertSame('Teşhir Grup', $payload['table']['rows'][1]['label']);
        $this->assertFalse($payload['table']['rows'][1]['excludedFromTotal']);
        $this->assertSame('C-2.TESHIR', $payload['table']['rows'][1]['children'][0]['customerCode']);
        $this->assertFalse($payload['table']['rows'][1]['children'][0]['excludedFromTotal']);
        $this->assertSame('KONSİNYE', $payload['table']['rows'][2]['label']);
        $this->assertSame('KONSINYE', $payload['table']['rows'][2]['children'][0]['type']);
        $this->assertTrue($payload['table']['rows'][2]['children'][0]['excludedFromTotal']);
    }

    public function test_sales_customer_filter_chart_uses_customer_items_with_account_flags(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 1,
                        'cari_grup_adi' => 'BAYİ',
                        'adet' => 2,
                        'ciro' => 300,
                        'excluded_from_total' => 0,
                        'konsinye_tutari' => 900,
                    ],
                    [
                        'satir_tipi' => 'CARI',
                        'cari_grup_adi' => 'BAYİ',
                        'cari_kodu' => 'C-1',
                        'satir_adi' => 'AYNI FİRMA',
                        'adet' => 1,
                        'ciro' => 100,
                        'excluded_from_total' => 0,
                        'konsinye_tutari' => 900,
                    ],
                    [
                        'satir_tipi' => 'CARI',
                        'cari_grup_adi' => 'BAYİ',
                        'cari_kodu' => 'C-2',
                        'satir_adi' => 'AYNI FİRMA',
                        'adet' => 1,
                        'ciro' => 200,
                        'excluded_from_total' => 0,
                        'konsinye_tutari' => 900,
                    ],
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 2,
                        'cari_grup_adi' => 'Teşhir Grup',
                        'adet' => 1,
                        'ciro' => 300,
                        'excluded_from_total' => 0,
                        'konsinye_tutari' => 900,
                    ],
                    [
                        'satir_tipi' => 'CARI',
                        'cari_grup_adi' => 'Teşhir Grup',
                        'cari_kodu' => 'C-3.TESHIR',
                        'satir_adi' => 'TEŞHİR MÜŞTERİ - TEŞHİR HESABI',
                        'adet' => 1,
                        'ciro' => 300,
                        'excluded_from_total' => 0,
                        'konsinye_tutari' => 900,
                    ],
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 999998,
                        'cari_grup_adi' => 'KONSİNYE',
                        'adet' => 1,
                        'ciro' => 900,
                        'excluded_from_total' => 1,
                        'konsinye_tutari' => 900,
                    ],
                    [
                        'satir_tipi' => 'KONSINYE',
                        'cari_grup_adi' => 'KONSİNYE',
                        'cari_kodu' => 'C-1.KONSINYE',
                        'satir_adi' => 'KONSİNYE - AYNI FİRMA',
                        'adet' => 1,
                        'ciro' => 900,
                        'excluded_from_total' => 1,
                        'konsinye_tutari' => 900,
                    ],
                ],
            ]),
        ]);

        $payload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'all',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'customer_filter' => 'C-1,C-2,C-3.TESHIR,C-1.KONSINYE',
            'bypass_cache' => true,
        ]);

        $this->assertSame('Seçili Müşteri Karşılaştırması', $payload['chart']['title']);
        $this->assertEquals(600, $payload['chart']['totalNet']);
        $this->assertSame(['C-3.TESHIR', 'C-2', 'C-1', 'C-1.KONSINYE'], array_column($payload['chart']['items'], 'customerCode'));
        $this->assertSame(['TEŞHİR MÜŞTERİ - TEŞHİR HESABI', 'AYNI FİRMA', 'AYNI FİRMA', 'KONSİNYE - AYNI FİRMA'], array_column($payload['chart']['items'], 'label'));
        $this->assertNotContains('BAYİ', array_column($payload['chart']['items'], 'label'));
        $this->assertFalse($payload['chart']['items'][0]['excludedFromTotal']);
        $this->assertTrue($payload['chart']['items'][0]['isTeshir']);
        $this->assertEquals(50.0, $payload['chart']['items'][0]['percentage']);
        $this->assertSame('AYNI FİRMA', $payload['chart']['items'][1]['label']);
        $this->assertSame('AYNI FİRMA', $payload['chart']['items'][2]['label']);
        $this->assertNotSame($payload['chart']['items'][1]['customerCode'], $payload['chart']['items'][2]['customerCode']);
        $this->assertSame('KONSİNYE', $payload['chart']['items'][3]['groupLabel']);
        $this->assertTrue($payload['chart']['items'][3]['excludedFromTotal']);
        $this->assertTrue($payload['chart']['items'][3]['isConsignment']);
        $this->assertSame(0, $payload['chart']['items'][3]['percentage']);
    }

    public function test_sales_customer_picker_and_mobile_breakdown_contract_exist(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/panel/SalesMainDashboard.jsx')) ?: '';
        $picker = file_get_contents(resource_path('js/components/sales-main/CustomerFilterPicker.jsx')) ?: '';
        $productFilter = file_get_contents(resource_path('js/components/sales-main/ProductFilter.jsx')) ?: '';
        $managementScopeFilter = file_get_contents(resource_path('js/components/sales-main/ManagementScopeFilter.jsx')) ?: '';
        $pieChart = file_get_contents(resource_path('js/components/sales-main/SalesPieChart.jsx')) ?: '';
        $brandComparisonStrip = file_get_contents(resource_path('js/components/sales-main/BrandComparisonStrip.jsx')) ?: '';
        $highlightedLabel = file_get_contents(resource_path('js/components/sales-main/HighlightedAccountLabel.jsx')) ?: '';
        $table = file_get_contents(resource_path('js/components/sales-main/data-table/DataTable.jsx')) ?: '';
        $expandableRows = file_get_contents(resource_path('js/components/sales-main/data-table/ExpandableRows.jsx')) ?: '';

        $this->assertStringContainsString('CustomerFilterPicker', $dashboard);
        $this->assertStringContainsString('scopeKey={filters.scope_key}', $dashboard);
        $this->assertStringContainsString('dateFrom={filters.date_from}', $dashboard);
        $this->assertStringContainsString('dateTo={filters.date_to}', $dashboard);
        $this->assertStringContainsString('grain={filters.grain}', $dashboard);
        $this->assertStringContainsString('detailType={filters.detail_type}', $dashboard);
        $this->assertStringContainsString('ProductFilter', $dashboard);
        $this->assertStringContainsString('BrandComparisonStrip', $dashboard);
        $this->assertStringContainsString("filters.detail_type === 'urun'", $dashboard);
        $this->assertStringContainsString('brandFilter={filters.brand_filter}', $dashboard);
        $this->assertStringContainsString('categoryFilter={filters.category_filter}', $dashboard);
        $this->assertStringContainsString('productFilter={filters.product_filter}', $dashboard);
        $this->assertStringContainsString('productOptionsCacheKey', $dashboard);
        $this->assertStringContainsString('productOptionsCacheRef', $dashboard);
        $this->assertStringContainsString('currentProductOptionsCacheKey', $dashboard);
        $this->assertStringContainsString('productOptionsForPicker', $dashboard);
        $this->assertStringContainsString('productOptions={productOptionsForPicker}', $dashboard);
        $this->assertStringContainsString("filters.product_filter === ''", $dashboard);
        $this->assertStringContainsString('options: nextData.productOptions', $dashboard);
        $this->assertStringContainsString('filters.brand_filter', $dashboard);
        $this->assertStringContainsString('filters.category_filter', $dashboard);
        $this->assertStringContainsString('filters.scope_key', $dashboard);
        $this->assertStringNotContainsString('productOptions={data?.productOptions ?? []}', $dashboard);
        $this->assertStringContainsString('const handleDetailTypeChange', $dashboard);
        $this->assertStringContainsString("brand_filter: 'all'", $dashboard);
        $this->assertStringContainsString("category_filter: 'all'", $dashboard);
        $this->assertStringContainsString("product_filter: ''", $dashboard);
        $this->assertStringContainsString("queryParam('grain')", $dashboard);
        $this->assertStringContainsString("queryParam('date_from')", $dashboard);
        $this->assertStringContainsString("queryParam('date_to')", $dashboard);
        $this->assertStringContainsString("queryParam('detail_type')", $dashboard);
        $this->assertStringContainsString("queryParam('scope_key')", $dashboard);
        $this->assertStringContainsString("queryParam('brand_filter')", $dashboard);
        $this->assertStringContainsString("queryParam('category_filter')", $dashboard);
        $this->assertStringContainsString("queryParam('product_filter')", $dashboard);
        $this->assertStringContainsString('function requestSignature', $dashboard);
        $this->assertStringContainsString('function responseSignature', $dashboard);
        $this->assertStringContainsString('normalizeChoiceSignatureValue', $dashboard);
        $this->assertStringContainsString('responseFilterValue', $dashboard);
        $this->assertStringContainsString('const requestIdRef = useRef(0)', $dashboard);
        $this->assertStringContainsString('requestId === requestIdRef.current', $dashboard);
        $this->assertStringContainsString('const actualSignature = responseSignature(nextData)', $dashboard);
        $this->assertStringContainsString("console.warn('Sales response signature mismatch'", $dashboard);
        $this->assertStringNotContainsString('&& signaturesMatch(expectedSignature, responseSignature(nextData))', $dashboard);
        $this->assertLessThan(strpos($dashboard, '<SalesPieChart'), strpos($dashboard, '<BrandComparisonStrip'));
        $this->assertStringNotContainsString('setData(null)', $dashboard);
        $this->assertStringContainsString('const handleScopeChange', $dashboard);
        $this->assertStringContainsString('setSelectedCustomers([])', $dashboard);
        $this->assertStringContainsString("customer_filter: ''", $dashboard);
        $this->assertStringContainsString("cari_filter: ''", $dashboard);
        $this->assertStringContainsString('onChange={handleScopeChange}', $dashboard);
        $this->assertStringNotContainsString('filters={filters}', $dashboard);
        $this->assertStringNotContainsString('detail_type: config?.defaults?.detailType ?? current.detail_type', $dashboard);
        $this->assertStringNotContainsString('scope_key: config?.defaults?.scopeKey ?? current.scope_key', $dashboard);
        $this->assertStringContainsString('customer_filter', $dashboard);
        $this->assertStringContainsString('cari_filter: csv', $dashboard);
        $this->assertStringNotContainsString("scope_key: 'all'", $dashboard);
        $this->assertStringNotContainsString('router.visit', $dashboard);
        $this->assertStringNotContainsString('window.location =', $dashboard);
        $this->assertStringNotContainsString('window.history', $dashboard);
        $this->assertStringContainsString('bypass_cache: true', $dashboard);
        $this->assertStringContainsString('/api/data/sales_customer_search', $picker);
        $this->assertStringContainsString('scope_key: scopeKey', $picker);
        $this->assertStringContainsString('date_from: dateFrom', $picker);
        $this->assertStringContainsString('date_to: dateTo', $picker);
        $this->assertStringContainsString('grain', $picker);
        $this->assertStringContainsString('detail_type: detailType', $picker);
        $this->assertStringContainsString('limit: 80', $picker);
        $this->assertStringContainsString('selected.map((item) => item.code)', $picker);
        $this->assertStringContainsString('candidate.code === item.code', $picker);
        $this->assertStringContainsString('selectedCodes.has(customer.code)', $picker);
        $this->assertStringContainsString('Müşteri bulunamadı', $picker);
        $this->assertStringContainsString('Ürün Filtresi', $productFilter);
        $this->assertStringContainsString('Ürün, model veya stok kodu ara', $productFilter);
        $this->assertStringContainsString('PHILIPS', $productFilter);
        $this->assertStringContainsString('EMAKS PRIME', $productFilter);
        $this->assertStringContainsString('useState(normalizedProductFilter)', $productFilter);
        $this->assertStringContainsString("setLocalProductFilter(hasSelectedOptions ? '' : normalizedProductFilter)", $productFilter);
        $this->assertStringContainsString('window.setTimeout', $productFilter);
        $this->assertStringContainsString('350', $productFilter);
        $this->assertStringContainsString('productOptions = []', $productFilter);
        $this->assertStringContainsString('splitProductFilter', $productFilter);
        $this->assertStringContainsString('selectedProducts', $productFilter);
        $this->assertStringContainsString('type="checkbox"', $productFilter);
        $this->assertStringContainsString('Ürün bulunamadı.', $productFilter);
        $this->assertStringContainsString('Tümünü temizle', $productFilter);
        $this->assertStringContainsString("{ value: 'A1', label: 'AKILLI KİLİT' }", $productFilter);
        $this->assertStringContainsString("{ value: 'YM1', label: 'YÜZEY MONTAJLI KİLİT CAM VS.' }", $productFilter);
        $this->assertStringNotContainsString('A1 - AKILLI KİLİT', $productFilter);
        $this->assertStringNotContainsString('YM1 - YÜZEY MONTAJLI KİLİT CAM VS.', $productFilter);
        $this->assertStringContainsString('brand_filter: event.target.value', $productFilter);
        $this->assertStringContainsString('category_filter: event.target.value', $productFilter);
        $this->assertStringContainsString('product_filter: localProductFilter', $productFilter);
        $this->assertStringContainsString("product_filter: values.join(', ')", $productFilter);
        $this->assertStringContainsString('setLocalProductFilter(event.target.value)', $productFilter);
        $this->assertStringNotContainsString('disabled={loading}', $productFilter);
        $this->assertStringContainsString('bypass_cache: true', $productFilter);
        $this->assertStringContainsString('HighlightedAccountLabel', $picker);
        $this->assertStringContainsString('comparison?.items ?? []', $brandComparisonStrip);
        $this->assertStringContainsString('Marka Karşılaştırması', $brandComparisonStrip);
        $this->assertStringContainsString('item.amountLabel', $brandComparisonStrip);
        $this->assertStringContainsString('item.quantityLabel', $brandComparisonStrip);
        $this->assertStringContainsString('item.percentage', $brandComparisonStrip);
        $this->assertStringContainsString('HighlightedAccountLabel', $pieChart);
        $this->assertStringContainsString('customerCode', $pieChart);
        $this->assertStringContainsString('groupLabel', $pieChart);
        $this->assertStringContainsString('TEŞHİR HESABI', $highlightedLabel);
        $this->assertStringContainsString('KONSİNYE HESABI', $highlightedLabel);
        $this->assertStringContainsString('<strong', $highlightedLabel);
        $this->assertStringNotContainsString('dangerouslySetInnerHTML', $highlightedLabel);
        $this->assertStringContainsString('onClick={() => onChange({ scope_key: scope.key })}', $managementScopeFilter);
        $this->assertStringContainsString('scope_key: scope.key', $managementScopeFilter);
        $this->assertStringNotContainsString('filters = {}', $managementScopeFilter);
        $this->assertStringNotContainsString('@inertiajs/react', $managementScopeFilter);
        $this->assertStringNotContainsString('router.visit', $managementScopeFilter);
        $this->assertStringNotContainsString('scope.navigateTo', $managementScopeFilter);
        $this->assertStringNotContainsString('preserveScroll', $managementScopeFilter);
        $this->assertStringNotContainsString('preserveState', $managementScopeFilter);
        $this->assertStringContainsString('md:hidden', $table);
        $this->assertStringContainsString('MobileRow', $table);
        $this->assertStringContainsString('min-w-[1100px]', $table);
        $this->assertStringContainsString('whitespace-normal', $table);
        $this->assertStringContainsString('break-words', $table);
        $this->assertStringContainsString('HighlightedAccountLabel', $table);
        $this->assertStringContainsString('min-w-[560px]', $expandableRows);
        $this->assertStringContainsString('whitespace-normal', $expandableRows);
        $this->assertStringContainsString('break-words', $expandableRows);
        $this->assertStringContainsString('HighlightedAccountLabel', $expandableRows);
        $this->assertStringNotContainsString('truncate', $table);
        $this->assertStringNotContainsString('truncate', $expandableRows);
        $this->assertStringNotContainsString('line-clamp-2', $table);
        $this->assertStringContainsString('row.id ?? row.label', $table);
        $this->assertStringContainsString('row.id ?? row.label', $expandableRows);
        $this->assertStringNotContainsString('key={row.label}', $table);
        $this->assertStringNotContainsString('key={row.label}', $expandableRows);
    }

    public function test_sales_product_picker_keeps_cached_options_while_product_filter_is_active(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/panel/SalesMainDashboard.jsx')) ?: '';
        $productFilter = file_get_contents(resource_path('js/components/sales-main/ProductFilter.jsx')) ?: '';

        $this->assertStringContainsString('productOptionsCacheRef', $dashboard);
        $this->assertStringContainsString('productOptionsCacheKey(filters)', $dashboard);
        $this->assertStringContainsString('currentProductOptionsCacheKey', $dashboard);
        $this->assertStringContainsString('productOptionsForPicker', $dashboard);
        $this->assertStringContainsString('productOptionsCacheRef.current.key !== currentProductOptionsCacheKey', $dashboard);
        $this->assertStringContainsString('productOptionsCacheRef.current.options.length > 0', $dashboard);
        $this->assertStringContainsString('filters.product_filter === \'\'', $dashboard);
        $this->assertStringContainsString('options: nextData.productOptions', $dashboard);
        $this->assertStringContainsString('productOptions={productOptionsForPicker}', $dashboard);
        $this->assertStringNotContainsString('productOptions={data?.productOptions ?? []}', $dashboard);
        $this->assertStringContainsString('normalizeSignatureValue(filters.detail_type', $dashboard);
        $this->assertStringContainsString('normalizeSignatureValue(filters.scope_key', $dashboard);
        $this->assertStringContainsString('normalizeChoiceSignatureValue(filters.brand_filter)', $dashboard);
        $this->assertStringContainsString('normalizeChoiceSignatureValue(filters.category_filter)', $dashboard);
        $this->assertStringNotContainsString('setData(null)', $dashboard);
        $this->assertStringNotContainsString('router.visit', $dashboard);

        $this->assertStringContainsString('selectedProducts', $productFilter);
        $this->assertStringContainsString('productOptions.find((option) => option.value === value) ?? { value, label: value }', $productFilter);
        $this->assertStringContainsString("product_filter: values.join(', ')", $productFilter);
    }

    public function test_sales_bulent_scope_uses_sales_main_with_representative_code(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $service = app(SalesMainPageService::class);
        $config = $service->config($user, 'sales_main');
        $bulent = collect($config['managementScopes'])->firstWhere('key', 'bulent_saglam');

        $this->assertIsArray($bulent);
        $this->assertSame('Bülent Sağlam', $bulent['label']);
        $this->assertSame('0024', $bulent['repCode']);
        $this->assertSame('temsilci', $bulent['salesView']);
        $this->assertFalse((bool) $bulent['allowAll']);
        $this->assertNull($bulent['navigateTo'] ?? null);

        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 1,
                        'cari_grup_adi' => 'Grup A',
                        'adet' => 1,
                        'ciro' => 100,
                    ],
                ],
            ]),
        ]);

        $payload = $service->dataset($user, [
            'scope_key' => 'bulent_saglam',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        $this->assertSame('sales_main_dashboard', $payload['queryMeta']['dataSource']);
        $this->assertSame('bulent_saglam', $payload['scope']['key']);
        $this->assertSame('0024', $payload['scope']['effectiveRepresentativeCode']);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['source_code'] ?? null) === 'sales_main_dashboard'
                && ($payload['scope_key'] ?? null) === 'bulent_saglam'
            && ($payload['rep_code'] ?? null) === '0024';
        });
    }

    public function test_sales_representative_scopes_follow_own_rep_explicit_allow_and_deny_rules(): void
    {
        $service = app(SalesMainPageService::class);
        $salih = User::factory()->create(['role_code' => 'sales', 'temsilci_kodu' => '0024']);

        $keys = collect($service->config($salih, 'sales_main')['managementScopes'])->pluck('key')->all();

        $this->assertContains('salih', $keys);
        $this->assertNotContains('all', $keys);
        $this->assertNotContains('umit', $keys);
        $this->assertNotContains('bulent_saglam', $keys);

        UserAccess::query()->create([
            'user_id' => $salih->id,
            'resource_code' => 'sales_rep_umit_yildiz',
            'can_view' => true,
        ]);

        $keys = collect($service->config($salih, 'sales_main')['managementScopes'])->pluck('key')->all();

        $this->assertContains('salih', $keys);
        $this->assertContains('umit', $keys);
        $this->assertNotContains('bulent_saglam', $keys);
        $this->assertNotContains('all', $keys);

        UserAccess::query()->create([
            'user_id' => $salih->id,
            'resource_code' => 'sales_rep_bulent_saglam',
            'can_view' => true,
        ]);

        $keys = collect($service->config($salih, 'sales_main')['managementScopes'])->pluck('key')->all();

        $this->assertContains('salih', $keys);
        $this->assertContains('umit', $keys);
        $this->assertContains('bulent_saglam', $keys);
        $this->assertNotContains('all', $keys);

        UserAccess::query()->create([
            'user_id' => $salih->id,
            'resource_code' => 'sales_main_all',
            'can_view' => true,
        ]);

        $keys = collect($service->config($salih, 'sales_main')['managementScopes'])->pluck('key')->all();

        $this->assertContains('all', $keys);

        $deniedSalih = User::factory()->create(['role_code' => 'sales', 'temsilci_kodu' => '0024']);
        UserAccess::query()->create([
            'user_id' => $deniedSalih->id,
            'resource_code' => 'sales_rep_salih_cakir',
            'can_view' => false,
        ]);

        $keys = collect($service->config($deniedSalih, 'sales_main')['managementScopes'])->pluck('key')->all();

        $this->assertNotContains('salih', $keys);
    }

    public function test_explicit_representative_scope_uses_scope_rep_code_in_gateway_payload(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 1,
                        'cari_grup_adi' => 'Grup A',
                        'adet' => 1,
                        'ciro' => 100,
                    ],
                ],
            ]),
        ]);

        $salih = User::factory()->create(['role_code' => 'sales', 'temsilci_kodu' => '0024']);
        UserAccess::query()->create([
            'user_id' => $salih->id,
            'resource_code' => 'sales_rep_umit_yildiz',
            'can_view' => true,
        ]);

        $payload = app(SalesMainPageService::class)->dataset($salih, [
            'scope_key' => 'umit',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        $this->assertSame('umit', $payload['scope']['key']);
        $this->assertSame('0003', $payload['scope']['effectiveRepresentativeCode']);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['source_code'] ?? null) === 'sales_main_dashboard'
                && ($payload['scope_key'] ?? null) === 'umit'
                && ($payload['rep_code'] ?? null) === '0003';
        });
    }

    public function test_explicit_representative_scope_works_without_user_representative_code(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 1,
                        'cari_grup_adi' => 'Salih Grup',
                        'adet' => 1,
                        'ciro' => 100,
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create(['role_code' => 'viewer', 'temsilci_kodu' => null]);

        foreach (['sales_main', 'sales_rep_salih_cakir'] as $resourceCode) {
            UserAccess::query()->create([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
                'can_view' => true,
            ]);
        }

        $service = app(SalesMainPageService::class);
        $keys = collect($service->config($user, 'sales_main')['managementScopes'])->pluck('key')->all();

        $this->assertContains('salih', $keys);
        $this->assertNotContains('all', $keys);

        $payload = $service->dataset($user, [
            'scope_key' => 'salih',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        $this->assertSame('salih', $payload['scope']['key']);
        $this->assertSame('0024', $payload['scope']['effectiveRepresentativeCode']);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['source_code'] ?? null) === 'sales_main_dashboard'
                && ($payload['scope_key'] ?? null) === 'salih'
                && ($payload['rep_code'] ?? null) === '0024';
        });
    }

    public function test_sales_main_entry_with_explicit_rep_scopes_does_not_grant_all_scope(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 1,
                        'cari_grup_adi' => 'Temsilci Grup',
                        'adet' => 1,
                        'ciro' => 100,
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create(['role_code' => 'viewer', 'temsilci_kodu' => null]);

        foreach (['sales_main', 'sales_rep_salih_cakir', 'sales_rep_umit_yildiz'] as $resourceCode) {
            UserAccess::query()->create([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
                'can_view' => true,
            ]);
        }

        $service = app(SalesMainPageService::class);
        $keys = collect($service->config($user, 'sales_main')['managementScopes'])->pluck('key')->all();

        $this->assertSame(['umit', 'salih'], $keys);
        $this->assertNotContains('all', $keys);

        $payload = $service->dataset($user, [
            'scope_key' => 'umit',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        $this->assertSame('umit', $payload['scope']['key']);
        $this->assertSame('0003', $payload['scope']['effectiveRepresentativeCode']);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['scope_key'] ?? null) === 'umit'
                && ($payload['rep_code'] ?? null) === '0003';
        });
    }

    public function test_super_admin_sees_all_sales_scopes(): void
    {
        $keys = collect(app(SalesMainPageService::class)
            ->config(User::factory()->create(['role_code' => 'admin']), 'sales_main')['managementScopes'])
            ->pluck('key')
            ->all();

        $this->assertContains('all', $keys);
        $this->assertContains('salih', $keys);
        $this->assertContains('umit', $keys);
        $this->assertContains('bulent_saglam', $keys);
        $this->assertContains('online_perakende', $keys);
        $this->assertContains('bayi_proje', $keys);
    }

    public function test_sales_customer_breakdown_keeps_customer_rows_under_groups(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 1,
                        'cari_grup_adi' => 'Grup A',
                        'adet' => 3,
                        'ciro' => 300,
                    ],
                    [
                        'satir_tipi' => 'CARI',
                        'cari_grup_adi' => 'Grup A',
                        'cari_kodu' => 'C-1',
                        'satir_adi' => 'Müşteri A',
                        'adet' => 2,
                        'ciro' => 200,
                    ],
                    [
                        'satir_tipi' => 'URUN',
                        'cari_grup_adi' => 'Grup A',
                        'parent_key' => 'C-1',
                        'satir_adi' => 'Ürün A',
                        'adet' => 2,
                        'ciro' => 200,
                    ],
                    [
                        'satir_tipi' => 'CARI',
                        'cari_grup_adi' => 'Grup B',
                        'cari_kodu' => 'C-2',
                        'satir_adi' => 'Müşteri B',
                        'adet' => 1,
                        'ciro' => 100,
                    ],
                    [
                        'satir_tipi' => 'CARI',
                        'cari_grup_adi' => '',
                        'cari_kodu' => 'C-3',
                        'satir_adi' => 'Orphan Müşteri',
                        'adet' => 1,
                        'ciro' => 50,
                    ],
                    [
                        'satir_tipi' => 'URUN',
                        'cari_grup_adi' => 'Grup A',
                        'parent_key' => 'NO_MATCH',
                        'satir_adi' => 'Roota Çıkmamalı',
                        'adet' => 1,
                        'ciro' => 25,
                    ],
                ],
            ]),
        ]);

        $payload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'all',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        $rootLabels = collect($payload['breakdown']['groups'])->pluck('label')->all();

        $this->assertSame('Grup A', $rootLabels[0]);
        $this->assertContains('Grup B', $rootLabels);
        $this->assertContains('Diğer Gelirler', $rootLabels);
        $this->assertNotContains('Müşteri A', $rootLabels);
        $this->assertNotContains('Orphan Müşteri', $rootLabels);

        $groupA = collect($payload['breakdown']['groups'])->firstWhere('label', 'Grup A');
        $groupB = collect($payload['breakdown']['groups'])->firstWhere('label', 'Grup B');
        $other = collect($payload['breakdown']['groups'])->firstWhere('label', 'Diğer Gelirler');

        $this->assertSame('Müşteri A', $groupA['children'][0]['label']);
        $this->assertSame('Ürün A', $groupA['children'][0]['children'][0]['label']);
        $this->assertSame('Müşteri B', $groupB['children'][0]['label']);
        $this->assertSame('Orphan Müşteri', $other['children'][0]['label']);
        $this->assertStringNotContainsString('Roota Çıkmamalı', json_encode($payload['breakdown']['groups'], JSON_UNESCAPED_UNICODE));
    }

    public function test_sales_breakdown_keeps_same_title_customers_distinct_by_cari_code(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    ['satir_tipi' => 'GRUP', 'cari_grup_adi' => 'Group A', 'adet' => 2, 'ciro' => 200],
                    ['satir_tipi' => 'CARI', 'cari_grup_adi' => 'Group A', 'cari_kodu' => '120.00.001', 'satir_adi' => 'Same Title', 'adet' => 1, 'ciro' => 100],
                    ['satir_tipi' => 'CARI', 'cari_grup_adi' => 'Group A', 'cari_kodu' => '320.02.355', 'satir_adi' => 'Same Title', 'adet' => 1, 'ciro' => 100],
                    ['satir_tipi' => 'URUN', 'cari_grup_adi' => 'Group A', 'parent_key' => '120.00.001', 'satir_adi' => 'Product A', 'adet' => 1, 'ciro' => 100],
                    ['satir_tipi' => 'URUN', 'cari_grup_adi' => 'Group A', 'parent_key' => '320.02.355', 'satir_adi' => 'Product B', 'adet' => 1, 'ciro' => 100],
                ],
            ]),
        ]);

        $payload = app(SalesMainPageService::class)->dataset(User::factory()->create(['role_code' => 'admin']), [
            'scope_key' => 'all',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        $group = $payload['breakdown']['groups'][0];

        $this->assertSame('GRUP:Group A', $group['id']);
        $this->assertCount(2, $group['children']);
        $this->assertSame('Same Title', $group['children'][0]['label']);
        $this->assertSame('Same Title', $group['children'][1]['label']);
        $this->assertSame('CARI:120.00.001', $group['children'][0]['id']);
        $this->assertSame('CARI:320.02.355', $group['children'][1]['id']);
        $this->assertSame('120.00.001', $group['children'][0]['customerCode']);
        $this->assertSame('320.02.355', $group['children'][1]['customerCode']);
        $this->assertStringStartsWith('URUN:120.00.001:', $group['children'][0]['children'][0]['id']);
        $this->assertStringStartsWith('URUN:320.02.355:', $group['children'][1]['children'][0]['id']);
    }

    public function test_user_facing_metadata_uses_customer_terminology(): void
    {
        $this->assertSame('Müşteri Yönetimi', Page::query()->where('code', 'cari')->value('name'));
        $this->assertSame('Müşteri Bakiyesi', Page::query()->where('code', 'cari_balance')->value('name'));

        $labels = DB::table('panel.page_menu')->pluck('label')->implode(' ');

        $this->assertStringContainsString('Müşteri Listesi', $labels);
        $this->assertStringNotContainsString('Cari Yönetimi', $labels);
    }

    public function test_customer_pages_use_customer_datasources_without_list_drawer(): void
    {
        $this->assertSame(
            'customers_list',
            PageConfig::query()->with('dataSource')->where('page_code', 'cari')->firstOrFail()->dataSource?->code,
        );
        $this->assertSame(
            'customers_balance',
            PageConfig::query()->with('dataSource')->where('page_code', 'cari_balance')->firstOrFail()->dataSource?->code,
        );

        $component = file_get_contents(resource_path('js/pages/panel/customer-crm/CustomerInfo.jsx')) ?: '';

        $this->assertStringContainsString('/api/data/cari', $component);
        $this->assertStringContainsString('/api/data/cari_balance', $component);
        $this->assertStringContainsString('/cari/detail?code=', $component);
        $this->assertStringNotContainsString('CustomerDetailDrawer', $component);
        $this->assertStringNotContainsString('Müşteri kodu bulunamadı', $component);
    }

    public function test_customer_crm_pages_use_primecrm_list_and_balance_contracts(): void
    {
        $page = file_get_contents(resource_path('js/pages/panel/page.tsx')) ?: '';
        $component = file_get_contents(resource_path('js/pages/panel/customer-crm/CustomerInfo.jsx')) ?: '';

        $this->assertStringContainsString('page.code ?? page.slug', $page);
        $this->assertStringContainsString('routePath = String', $page);
        $this->assertStringContainsString('matchesPage', $page);
        $this->assertStringContainsString("matchesPage('cari', '/cari')", $page);
        $this->assertStringContainsString('<CustomerInfoPage />', $page);
        $this->assertStringContainsString("matchesPage('cari_balance', '/cari/balance')", $page);
        $this->assertStringContainsString('<CustomerBalancePage />', $page);
        $this->assertStringContainsString("matchesPage('cari_detail', '/cari/detail')", $page);
        $this->assertStringContainsString('<CustomerStatementPage />', $page);
        $this->assertStringContainsString("matchesPage('cari_document_detail', '/cari/document-detail')", $page);
        $this->assertStringContainsString('<CustomerDocumentDetailPage />', $page);

        foreach ([
            'Cari Kodu',
            'Firma Ünvanı',
            'Grup',
            'Temsilci',
            'Bakiye Durumu',
            'Onaylı Açık Sipariş',
            'Genel Durum',
            'Onay Bekleyen Sipariş',
        ] as $column) {
            $this->assertStringContainsString($column, $component);
        }

        $this->assertStringContainsString('Cari kodu, firma adı, grup veya temsilci ara', $component);
        $this->assertStringContainsString('Sonuçlar', $component);
        $this->assertStringContainsString('Cari satırına tıklayarak hesap ekstresini görüntüleyebilirsiniz.', $component);
        $this->assertStringContainsString('{rows.length} kayıt', $component);
        $this->assertStringNotContainsString('min-w-[1320px]', $component);
        $this->assertStringNotContainsString('min-w-[1120px]', $component);
        $this->assertStringContainsString('md:hidden', $component);
        $this->assertStringContainsString('hidden w-full md:block', $component);
        $this->assertStringContainsString('representativeScopeCode(queryMeta)', $component);
        $this->assertStringNotContainsString('props?.auth?.user', $component);
        $this->assertStringContainsString('whitespace-normal break-words', $component);
        $this->assertStringContainsString('/api/data/cari', $component);
        $this->assertStringContainsString('/api/data/cari_balance', $component);
    }

    public function test_customer_balance_screen_is_customer_based_not_group_summary(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/customer-crm/CustomerInfo.jsx')) ?: '';
        $balanceColumnsOffset = strpos($component, 'const BALANCE_COLUMNS');

        $this->assertNotFalse($balanceColumnsOffset);
        $this->assertLessThan(
            strpos($component, "'Grup'", $balanceColumnsOffset),
            strpos($component, "'Cari Kodu'", $balanceColumnsOffset),
            'Müşteri Bakiyesi ilk kolonu Grup olmamalı.',
        );

        foreach (['Cari Kodu', 'Firma Ünvanı', 'Grup', 'Temsilci', 'Borç', 'Alacak', 'Net Bakiye', 'Ekstre'] as $column) {
            $this->assertStringContainsString($column, $component);
        }

        $query = (string) DataSource::query()->where('code', 'customers_balance')->value('query_template');

        $this->assertStringContainsString('musteri_kodu', $query);
        $this->assertStringContainsString('firma_unvani', $query);
        $this->assertStringContainsString('temsilci_kodu', $query);
        $this->assertStringContainsString('net_bakiye', $query);
        $this->assertStringNotContainsString('GROUP BY GRUP', strtoupper($query));
    }

    public function test_customer_list_datasource_returns_primecrm_summary_and_row_fields(): void
    {
        $query = (string) DataSource::query()->where('code', 'customers_list')->value('query_template');

        $this->assertStringContainsString('SiparisOzet', $query);
        $this->assertStringContainsString('AcikSiparisTutar', $query);
        $this->assertStringContainsString('GenelDurumTutar', $query);
        $this->assertStringContainsString('toplam_alacak_bakiyesi', $query);
        $this->assertStringContainsString('toplam_borc_bakiyesi', $query);
        $this->assertStringContainsString('toplam_onayli_acik_siparis', $query);
        $this->assertStringContainsString('toplam_onay_bekleyen_siparis', $query);
        $this->assertStringContainsString('genel_sonuc', $query);
        $this->assertStringContainsString('SummaryTotals', $query);
        $this->assertStringContainsString('ToplamAlacakBakiyesi', $query);
        $this->assertStringContainsString('ToplamBorcBakiyesi', $query);
        $this->assertStringContainsString('ToplamCariSayisi', $query);
    }

    public function test_customer_datasource_payload_scopes_admin_and_normal_users(): void
    {
        DB::table('panel.data_source_cache')->delete();

        Http::fake([
            '*' => Http::response(['ok' => true, 'rows' => []]),
        ]);

        $admin = User::factory()->create([
            'role_code' => 'admin',
            'temsilci_kodu' => '0003',
            'aktif' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/data/cari', [
                'search' => '',
                'bypass_cache' => true,
            ])
            ->assertOk();

        Http::assertSent(function ($request): bool {
            $payload = method_exists($request, 'data') ? $request->data() : [];
            $params = $payload['params'] ?? [];

            return ($payload['source_code'] ?? null) === 'customers_list'
                && array_key_exists('rep_code', $payload)
                && $payload['rep_code'] === null
                && array_key_exists('rep_code', $params)
                && $params['rep_code'] === null
                && ($params['customer_scope_key'] ?? null) === 'all'
                && ($params['customer_group_scope'] ?? null) === 'all';
        });

        $normalUser = User::factory()->create([
            'role_code' => 'customer',
            'temsilci_kodu' => '0003',
            'aktif' => true,
        ]);

        $this->actingAs($normalUser)
            ->postJson('/api/data/cari_balance', [
                'search' => '',
                'bypass_cache' => true,
            ])
            ->assertOk();

        Http::assertSent(function ($request): bool {
            $payload = method_exists($request, 'data') ? $request->data() : [];
            $params = $payload['params'] ?? [];

            return ($payload['source_code'] ?? null) === 'customers_balance'
                && ($payload['rep_code'] ?? null) === '0003'
                && ($params['rep_code'] ?? null) === '0003'
                && ($params['customer_scope_key'] ?? null) === 'own_rep'
                && ($params['customer_group_scope'] ?? null) === 'own_rep';
        });
    }

    public function test_customer_datasources_include_row_scope_params_and_filters(): void
    {
        foreach (['customers_all', 'customers_online', 'customers_bayi', 'customers_own_rep'] as $resourceCode) {
            $this->assertDatabaseHas('panel.resources', [
                'code' => $resourceCode,
                'type' => 'scope',
            ]);
        }

        foreach (['customers_list', 'customers_balance', 'customer_detail', 'customer_statement', 'customer_documents'] as $sourceCode) {
            $source = DataSource::query()->where('code', $sourceCode)->firstOrFail();
            $query = (string) $source->query_template;

            $this->assertContains('customer_scope_key', $source->allowed_params);
            $this->assertContains('customer_group_scope', $source->allowed_params);
            $this->assertStringContainsString('@CustomerScopeKey', $query);
            $this->assertStringContainsString("N'online_perakende'", $query);
            $this->assertStringContainsString("N'bayi_proje'", $query);
            $this->assertStringContainsString("N'own_rep'", $query);
            $this->assertStringContainsString('cari_grup_kodu', $query);
        }
    }

    public function test_customer_detail_and_document_drilldowns_have_empty_states_without_request(): void
    {
        $statement = file_get_contents(resource_path('js/pages/panel/customer-crm/CustomerStatement.jsx')) ?: '';
        $document = file_get_contents(resource_path('js/pages/panel/customer-crm/CustomerDocumentDetail.jsx')) ?: '';

        $this->assertStringContainsString('if (!canLoad)', $statement);
        $this->assertStringContainsString('Önce Müşteri Listesi’nden bir cari seçin.', $statement);
        $this->assertStringContainsString('yearStartDate()', $statement);
        $this->assertStringContainsString('${today.getFullYear()}-01-01', $statement);
        $this->assertStringNotContainsString('monthStart', $statement);
        $this->assertStringContainsString('Filtrele', $statement);
        $this->assertStringContainsString('/api/data/customer_statement', $statement);
        $this->assertStringContainsString('/api/data/customer_detail', $statement);

        $this->assertStringContainsString('if (!guid)', $document);
        $this->assertStringContainsString('Evrak listesinde Detay Gör ile geçiş yapınız.', $document);
        $this->assertStringContainsString('/api/data/customer_documents', $document);
        $this->assertStringContainsString("rowsByKind(rows, ['header'])", $document);
        $this->assertStringContainsString("rowsByKind(rows, ['cari', 'movement'])", $document);
        $this->assertStringContainsString("rowsByKind(rows, ['stock', 'stok'])", $document);
    }

    public function test_customer_crm_long_company_names_are_not_truncated(): void
    {
        foreach ([
            resource_path('js/pages/panel/customer-crm/CustomerInfo.jsx'),
            resource_path('js/pages/panel/customer-crm/CustomerStatement.jsx'),
            resource_path('js/pages/panel/customer-crm/CustomerDocumentDetail.jsx'),
        ] as $path) {
            $component = file_get_contents($path) ?: '';

            $this->assertStringNotContainsString('truncate', $component);
            $this->assertStringContainsString('whitespace-normal', $component);
            $this->assertStringContainsString('break-words', $component);
        }
    }

    public function test_customer_documents_datasource_uses_primecrm_document_detail_queries(): void
    {
        $source = DataSource::query()->where('code', 'customer_documents')->firstOrFail();
        $query = (string) $source->query_template;

        $this->assertNotSame('', trim($query));
        $this->assertStringContainsString('CARI_HESAP_HAREKETLERI', $query);
        $this->assertStringContainsString('STOK_HAREKETLERI', $query);
        $this->assertStringContainsString("N'header' AS line_type", $query);
        $this->assertStringContainsString("N'cari' AS line_type", $query);
        $this->assertStringContainsString("N'stock' AS line_type", $query);

        $allowed = (array) $source->allowed_params;
        $this->assertContains('guid', $allowed);
        $this->assertContains('hareket_guid', $allowed);
        $this->assertContains('document_guid', $allowed);
        $this->assertContains('evrak_guid', $allowed);
    }

    public function test_customer_detail_and_document_pages_are_drilldowns_not_main_tabs(): void
    {
        $cariDetailPageId = Page::query()->where('code', 'cari_detail')->value('id');
        $cariTabs = PageConfig::query()->where('page_code', 'cari')->firstOrFail()->layout_json['moduleTabs'] ?? [];
        $tabLabels = collect($cariTabs)->pluck('label')->all();

        $this->assertFalse((bool) DB::table('panel.page_menu')->where('page_id', $cariDetailPageId)->value('is_visible'));
        $this->assertSame(['Müşteri Listesi', 'Müşteri Bakiyesi'], $tabLabels);
    }

    public function test_customer_datasource_codes_can_be_called_without_page_records(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    ['cari_kodu' => 'M-1', 'cari_adi' => 'Test Müşteri'],
                ],
            ]),
        ]);

        $user = User::factory()->create(['role_code' => 'customer']);

        $this->actingAs($user)
            ->postJson('/api/data/customer_detail', ['customer_code' => 'M-1'])
            ->assertOk()
            ->assertJsonPath('queryMeta.dataSource', 'customer_detail');

        $this->actingAs($user)
            ->postJson('/api/data/customer_statement', ['customer_code' => 'M-1'])
            ->assertOk()
            ->assertJsonPath('queryMeta.dataSource', 'customer_statement');
    }

    public function test_customer_documents_data_source_allows_guid_aliases_in_request_and_gateway_payload(): void
    {
        $source = DataSource::query()->where('code', 'customer_documents')->firstOrFail();

        $source->update([
            'db_type' => 'n8n_json',
            'query_template' => 'SELECT 1',
            'allowed_params' => [
                'guid',
                'hareket_guid',
                'document_guid',
                'evrak_guid',
                'customer_code',
                'document_id',
                'bypass_cache',
            ],
            'connection_meta' => [
                'driver' => 'n8n_json',
                'method' => 'POST',
                'endpoint_url' => 'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1',
                'response_rows_key' => 'rows',
                'source_workflow' => 'PANEL - MSSQL Gateway - DataSource Runner v1',
                'source_reference' => 'CariService.cs',
            ],
            'active' => true,
        ]);

        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [],
            ]),
        ]);

        $user = User::factory()->create(['role_code' => 'admin', 'aktif' => true]);
        DB::table('panel.user_access')->updateOrInsert(
            ['user_id' => $user->id, 'resource_code' => 'customers'],
            ['can_view' => true, 'created_at' => now(), 'updated_at' => now()],
        );

        $this->actingAs($user)
            ->postJson('/api/data/customer_documents', [
                'guid' => 'GUID-01',
                'hareket_guid' => 'HAREKET-GUID-01',
                'document_guid' => 'DOCUMENT-GUID-01',
                'evrak_guid' => 'EVRAK-GUID-01',
                'bypass_cache' => true,
            ])
            ->assertOk()
            ->assertJsonPath('queryMeta.dataSource', 'customer_documents');

        Http::assertSent(function ($request): bool {
            $payload = method_exists($request, 'data') ? $request->data() : null;

            if (! is_array($payload) || $payload === []) {
                $decodedBody = json_decode((string) $request->body(), true);
                if (is_array($decodedBody)) {
                    $payload = $decodedBody;
                }
            }

            if (! is_array($payload)) {
                return false;
            }

            $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

            $guid = $payload['guid'] ?? null;
            $hareketGuid = $payload['hareket_guid'] ?? null;
            $documentGuid = $payload['document_guid'] ?? null;
            $evrakGuid = $payload['evrak_guid'] ?? null;

            return ($payload['source_code'] ?? null) === 'customer_documents'
                && (($guid ?? ($params['guid'] ?? null)) === 'GUID-01')
                && (($hareketGuid ?? ($params['hareket_guid'] ?? null)) === 'HAREKET-GUID-01')
                && (($documentGuid ?? ($params['document_guid'] ?? null)) === 'DOCUMENT-GUID-01')
                && (($evrakGuid ?? ($params['evrak_guid'] ?? null)) === 'EVRAK-GUID-01');
        });
    }

    public function test_customer_crm_helpers_and_customer_statement_key_use_no_randomness(): void
    {
        $utils = file_get_contents(base_path('resources/js/pages/panel/customer-crm/customerCrmUtils.js')) ?: '';
        $statement = file_get_contents(resource_path('js/pages/panel/customer-crm/CustomerStatement.jsx')) ?: '';
        $documentDetail = file_get_contents(resource_path('js/pages/panel/customer-crm/CustomerDocumentDetail.jsx')) ?: '';

        $this->assertFalse(str_starts_with($utils, "\xEF\xBB\xBF"), 'customerCrmUtils.js should not include UTF-8 BOM.');
        $this->assertStringNotContainsString('Math.random()', $statement);
        $this->assertStringContainsString('statementNumber(row)', $statement);
        $this->assertStringContainsString('`${statementNumber(row)}-${tarih}-${index}`', $statement);
        $this->assertStringContainsString('formatPercentOrNumber', $documentDetail);
        $this->assertStringContainsString('formatNumber', $documentDetail);
        $this->assertStringContainsString('evrak_guid', $documentDetail);

        [$exitCode, $output, $error] = $this->runNodeModule(<<<'JS'
            import { formatPercentOrNumber, formatNumber } from './resources/js/pages/panel/customer-crm/customerCrmUtils.js';

            console.log(
                JSON.stringify({
                    quantityInteger: formatNumber(5),
                    quantityDecimal: formatNumber(12.5),
                    discountPercent: formatPercentOrNumber('%33,50'),
                    discountNumber: formatPercentOrNumber(33.5),
                }),
            );
JS);

        $this->assertSame(0, $exitCode, $error);

        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('TL', $results['quantityInteger']);
        $this->assertStringNotContainsString('TL', $results['quantityDecimal']);
        $this->assertStringNotContainsString('TL', $results['discountPercent']);
        $this->assertStringNotContainsString('TL', $results['discountNumber']);
        $this->assertSame('%33,50', $results['discountPercent']);
    }

    public function test_customer_documents_allowed_params_include_guid_aliases_in_seeded_source(): void
    {
        $allowed = (array) (DataSource::query()->where('code', 'customer_documents')->value('allowed_params') ?? []);

        $this->assertContains('guid', $allowed);
        $this->assertContains('hareket_guid', $allowed);
        $this->assertContains('document_guid', $allowed);
        $this->assertContains('evrak_guid', $allowed);
        $this->assertContains('bypass_cache', $allowed);
    }

    public function test_proforma_create_contract_uses_customer_search_aliases_discounts_and_local_draft(): void
    {
        $component = file_get_contents(resource_path('js/components/primecrm/ProformaCreatePanel.jsx')) ?: '';
        $controller = file_get_contents(app_path('Http/Controllers/Api/PageDataController.php')) ?: '';

        $this->assertStringContainsString('/api/data/proforma_customer_search', $component);
        $this->assertStringContainsString('/api/data/proforma_price_list', $component);
        $this->assertStringContainsString('/api/data/proforma_discount_defs', $component);
        $this->assertStringContainsString('musteri_kodu', $component);
        $this->assertStringContainsString('cari_kodu', $component);
        $this->assertStringContainsString('cari_unvan1', $component);
        $this->assertStringContainsString('emaks_proforma_cart', $component);
        $this->assertStringContainsString('emaks_proforma_draft', $component);
        $this->assertStringContainsString('discounts', $component);
        $this->assertStringContainsString('Ek İskonto Ekle', $component);
        $this->assertStringContainsString("str_starts_with(\$sourceCode, 'proforma_')", $controller);
    }

    public function test_sales_and_module_frontend_do_not_expose_raw_technical_columns(): void
    {
        $salesTable = file_get_contents(resource_path('js/components/sales-main/data-table/DataTable.jsx')) ?: '';
        $salesBreakdown = file_get_contents(resource_path('js/components/sales-main/SalesBreakdown.jsx')) ?: '';
        $moduleData = file_get_contents(resource_path('js/components/primecrm/module-data.js')) ?: '';
        $moduleLayout = file_get_contents(resource_path('js/layouts/module-layout.tsx')) ?: '';

        foreach (['period_label', 'satir_tipi', 'cari_grup_adi', 'cari_kodu', 'satir_adi', 'parent_key', 'siralama_1', 'siralama_2'] as $technicalColumn) {
            $this->assertStringNotContainsString($technicalColumn, $salesTable);
            $this->assertStringNotContainsString($technicalColumn, $salesBreakdown);
        }

        $this->assertStringContainsString("['urunAdi'", $moduleData);
        $this->assertStringContainsString('Ürün / Model', $moduleData);
        $this->assertStringContainsString("['miktar', 'Miktar']", $moduleData);
        $this->assertStringContainsString('teslim_tarihi_hafta', $moduleData);
        $this->assertStringContainsString('Teslim Haftası', $moduleData);
        $this->assertStringNotContainsString('/stock/warehouse', $moduleLayout);
        $this->assertStringContainsString('Operasyon Paneli', file_get_contents(resource_path('js/components/app-logo.tsx')) ?: '');
    }

    public function test_post_deploy_refresh_command_refreshes_datasources_without_user_or_permission_seed(): void
    {
        $commandSource = file_get_contents(app_path('Console/Commands/PanelPostDeployRefresh.php')) ?: '';

        $this->assertArrayHasKey('panel:post-deploy-refresh', Artisan::all());
        $this->assertStringContainsString('PanelDataSourcesSeeder', $commandSource);
        $this->assertStringContainsString('PanelKnownWorkflowDataSourcesSeeder', $commandSource);
        $this->assertStringContainsString('panel.data_source_cache', $commandSource);
        $this->assertStringNotContainsString('PanelMetadataSeeder', $commandSource);
        $this->assertStringNotContainsString('DatabaseSeeder', $commandSource);

        $user = User::factory()->create([
            'username' => 'post-deploy-user@example.test',
            'full_name' => 'Post Deploy User',
            'role_code' => 'viewer',
            'temsilci_kodu' => '9999',
            'aktif' => true,
        ]);
        DB::table('panel.user_access')->insert([
            'user_id' => $user->id,
            'resource_code' => 'stock',
            'can_view' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('panel.data_source_cache')->insert([
            'cache_key' => 'post-deploy-refresh-test',
            'source_code' => 'sales_main_dashboard',
            'request_payload' => json_encode(['test' => true], JSON_THROW_ON_ERROR),
            'response_payload' => json_encode(['ok' => true], JSON_THROW_ON_ERROR),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userCount = DB::table('panel.users')->count();
        $userAccessRows = DB::table('panel.user_access')->get()->map(fn ($row) => (array) $row)->all();
        $rolePermissionCount = DB::table('panel.role_resource_permissions')->count();

        $this->artisan('panel:post-deploy-refresh')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('panel.data_source_cache')->count());
        $this->assertSame($userCount, DB::table('panel.users')->count());
        $this->assertSame($rolePermissionCount, DB::table('panel.role_resource_permissions')->count());
        $this->assertSame($userAccessRows, DB::table('panel.user_access')->get()->map(fn ($row) => (array) $row)->all());

        $user->refresh();
        $this->assertSame('9999', $user->temsilci_kodu);
        $this->assertSame('viewer', $user->role_code);
    }

    public function test_panel_metadata_seeder_preserves_existing_bootstrap_admin_fields(): void
    {
        $envKeys = [
            'PANEL_BOOTSTRAP_ADMIN_USERNAME',
            'PANEL_BOOTSTRAP_ADMIN_PASSWORD',
            'PANEL_BOOTSTRAP_ADMIN_NAME',
            'PANEL_BOOTSTRAP_ADMIN_REP_CODE',
        ];
        $previous = [];

        foreach ($envKeys as $key) {
            $previous[$key] = getenv($key) === false ? null : getenv($key);
        }

        try {
            foreach ([
                'PANEL_BOOTSTRAP_ADMIN_USERNAME' => 'existing-admin@example.test',
                'PANEL_BOOTSTRAP_ADMIN_PASSWORD' => 'new-secret-password',
                'PANEL_BOOTSTRAP_ADMIN_NAME' => 'New Bootstrap Name',
                'PANEL_BOOTSTRAP_ADMIN_REP_CODE' => '0003',
            ] as $key => $value) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }

            $admin = User::factory()->create([
                'username' => 'existing-admin@example.test',
                'full_name' => 'Existing Admin Name',
                'role_code' => 'viewer',
                'temsilci_kodu' => null,
                'aktif' => false,
            ]);
            DB::table('panel.user_access')->insert([
                'user_id' => $admin->id,
                'resource_code' => 'sales_main',
                'can_view' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $before = $admin->fresh()->only(['full_name', 'password_hash', 'role_code', 'temsilci_kodu', 'aktif']);
            $beforeAccessRows = DB::table('panel.user_access')->where('user_id', $admin->id)->get()->map(fn ($row) => (array) $row)->all();

            $this->seed(PanelMetadataSeeder::class);

            $admin->refresh();
            $this->assertSame($before['full_name'], $admin->full_name);
            $this->assertSame($before['password_hash'], $admin->password_hash);
            $this->assertSame($before['role_code'], $admin->role_code);
            $this->assertSame($before['temsilci_kodu'], $admin->temsilci_kodu);
            $this->assertSame($before['aktif'], $admin->aktif);
            $this->assertSame($beforeAccessRows, DB::table('panel.user_access')->where('user_id', $admin->id)->get()->map(fn ($row) => (array) $row)->all());
        } finally {
            foreach ($envKeys as $key) {
                if ($previous[$key] === null) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                } else {
                    putenv("{$key}={$previous[$key]}");
                    $_ENV[$key] = $previous[$key];
                    $_SERVER[$key] = $previous[$key];
                }
            }
        }
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
