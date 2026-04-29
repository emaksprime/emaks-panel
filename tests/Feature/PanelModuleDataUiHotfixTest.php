<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\Page;
use App\Models\PageConfig;
use App\Models\User;
use App\Services\SalesMainPageService;
use Database\Seeders\PanelDataSourcesSeeder;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        $this->assertStringContainsString('CARI_HESAPLAR', $alinan);
        $this->assertStringContainsString('CARI_HESAP_GRUPLARI', $alinan);
        $this->assertStringContainsString('NOT LIKE', $alinan);
        $this->assertStringContainsString('İHRACAT', $alinan);
        $this->assertStringContainsString('kalan_tutar', $alinan);

        $this->assertMatchesRegularExpression('/sip\.sip_tip\s*=\s*1/i', $verilen);
        $this->assertStringContainsString('STOK_KATEGORILERI', $verilen);
        $this->assertStringContainsString('teslim_tarihi', $verilen);
        $this->assertStringContainsString('teslim_tarihi_hafta', $verilen);
        $this->assertStringContainsString('stok_kategori_adi', $verilen);
        $this->assertStringContainsString('siparis_tutari', $verilen);
        $this->assertNotSame($alinan, $verilen);
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
            ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code', 'cari_filter', 'customer_filter', 'search', 'page', 'bypass_cache'],
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
        $this->assertContains('limit', $source->allowed_params);
        $this->assertStringContainsString('DECLARE @Search', $query);
        $this->assertStringContainsString('DECLARE @RepCode', $query);
        $this->assertStringContainsString('DECLARE @CanViewAll', $query);
        $this->assertStringContainsString('CARI_HESAPLAR', $query);
        $this->assertStringContainsString('CARI_HESAP_GRUPLARI', $query);
        $this->assertStringContainsString('cari.cari_kod LIKE', $query);
        $this->assertStringContainsString('cari.cari_unvan1 LIKE', $query);
        $this->assertStringContainsString('grp.crg_isim', $query);
        $this->assertStringContainsString('cari.cari_temsilci_kodu', $query);
        $this->assertStringContainsString('ORDER BY', $query);
        $this->assertStringContainsString('cari.cari_kod ASC', $query);
        $this->assertStringContainsString('display_text', $query);
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
        $this->assertTrue($payload['bypass_cache'] ?? false);
        $this->assertTrue($payload['params']['bypass_cache'] ?? false);
        $this->assertContains('search', $payload['allowed_params'] ?? []);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return ($payload['source_code'] ?? null) === 'sales_customer_search'
                && ($payload['search'] ?? null) === 'mehmet'
                && ($payload['params']['search'] ?? null) === 'mehmet'
                && ($payload['bypass_cache'] ?? null) === true
                && ($payload['params']['bypass_cache'] ?? null) === true
                && in_array('search', $payload['allowed_params'] ?? [], true);
        });
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
            'customer_filter' => 'C-1,C-2',
            'bypass_cache' => true,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['source_code'] ?? null) === 'sales_main_dashboard'
                && ($payload['cari_filter'] ?? null) === 'C-1,C-2'
                && ($payload['customer_filter'] ?? null) === 'C-1,C-2'
                && ($payload['params']['cari_filter'] ?? null) === 'C-1,C-2'
                && ($payload['params']['customer_filter'] ?? null) === 'C-1,C-2';
        });
    }

    public function test_sales_customer_picker_and_mobile_breakdown_contract_exist(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/panel/SalesMainDashboard.jsx')) ?: '';
        $picker = file_get_contents(resource_path('js/components/sales-main/CustomerFilterPicker.jsx')) ?: '';
        $table = file_get_contents(resource_path('js/components/sales-main/data-table/DataTable.jsx')) ?: '';
        $expandableRows = file_get_contents(resource_path('js/components/sales-main/data-table/ExpandableRows.jsx')) ?: '';

        $this->assertStringContainsString('CustomerFilterPicker', $dashboard);
        $this->assertStringContainsString('customer_filter', $dashboard);
        $this->assertStringContainsString('cari_filter: csv', $dashboard);
        $this->assertStringContainsString('bypass_cache: true', $dashboard);
        $this->assertStringContainsString('/api/data/sales_customer_search', $picker);
        $this->assertStringContainsString('body: JSON.stringify({ search, limit: 80, bypass_cache: true })', $picker);
        $this->assertStringContainsString('selected.map((item) => item.code)', $picker);
        $this->assertStringContainsString('candidate.code === item.code', $picker);
        $this->assertStringContainsString('selectedCodes.has(customer.code)', $picker);
        $this->assertStringContainsString('Müşteri bulunamadı', $picker);
        $this->assertStringContainsString('md:hidden', $table);
        $this->assertStringContainsString('MobileRow', $table);
        $this->assertStringContainsString('row.id ?? row.label', $table);
        $this->assertStringContainsString('row.id ?? row.label', $expandableRows);
        $this->assertStringNotContainsString('key={row.label}', $table);
        $this->assertStringNotContainsString('key={row.label}', $expandableRows);
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
        $this->assertContains('Diğer', $rootLabels);
        $this->assertNotContains('Müşteri A', $rootLabels);
        $this->assertNotContains('Orphan Müşteri', $rootLabels);

        $groupA = collect($payload['breakdown']['groups'])->firstWhere('label', 'Grup A');
        $groupB = collect($payload['breakdown']['groups'])->firstWhere('label', 'Grup B');
        $other = collect($payload['breakdown']['groups'])->firstWhere('label', 'Diğer');

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

    public function test_customer_pages_and_drawer_use_customer_datasources(): void
    {
        $this->assertSame(
            'customers_list',
            PageConfig::query()->with('dataSource')->where('page_code', 'cari')->firstOrFail()->dataSource?->code,
        );
        $this->assertSame(
            'customers_balance',
            PageConfig::query()->with('dataSource')->where('page_code', 'cari_balance')->firstOrFail()->dataSource?->code,
        );

        $drawer = file_get_contents(resource_path('js/components/primecrm/CustomerDetailDrawer.jsx')) ?: '';

        $this->assertStringContainsString('Genel Bilgi', $drawer);
        $this->assertStringContainsString('Bakiye', $drawer);
        $this->assertStringContainsString('Ekstre', $drawer);
        $this->assertStringContainsString('/api/data/customer_detail', $drawer);
        $this->assertStringContainsString('/api/data/customer_statement', $drawer);
        $this->assertStringNotContainsString('Evraklar', $drawer);
    }

    public function test_customer_datasource_codes_can_be_called_without_page_records(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    ['cari_kodu' => 'M-1', 'cari_adi' => 'Test MÃ¼ÅŸteri'],
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
