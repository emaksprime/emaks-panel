<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\PageConfig;
use App\Models\Resource;
use App\Models\RoleResourcePermission;
use App\Models\User;
use App\Models\UserAccess;
use App\Services\PanelAccessService;
use App\Services\SalesMainPageService;
use Database\Seeders\PanelDataSourcesSeeder;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PanelAuthDatasourceHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PanelMetadataSeeder::class);
        $this->seed(PanelDataSourcesSeeder::class);
        $this->seed(PanelKnownWorkflowDataSourcesSeeder::class);
    }

    public function test_canonical_datasources_are_seeded_without_overwriting_sales_main(): void
    {
        $salesMain = DataSource::query()->where('code', 'sales_main_dashboard')->firstOrFail();

        $this->assertGreaterThan(1000, strlen((string) $salesMain->query_template));

        foreach ([
            'sales_online_perakende_detail',
            'sales_bayi_proje_detail',
            'stock_dashboard',
            'orders_alinan',
            'orders_verilen',
            'customers_list',
            'customers_balance',
            'customer_detail',
            'customer_documents',
            'customer_statement',
            'proforma_customer_search',
            'proforma_stock_search',
            'proforma_price_list',
            'proforma_discount_defs',
        ] as $code) {
            $source = DataSource::query()->where('code', $code)->first();

            $this->assertNotNull($source, "Datasource [{$code}] seed edilmeliydi.");
            $this->assertNotSame('', trim((string) $source->query_template), "Datasource [{$code}] query_template bos olmamali.");
        }

        foreach (['proforma_list', 'proforma_detail', 'proforma_draft', 'proforma_items'] as $code) {
            $this->assertDatabaseHas('panel.data_sources', ['code' => $code]);
        }
    }

    public function test_seeded_user_facing_metadata_has_no_mojibake_and_uses_customer_terms(): void
    {
        $failPattern = '/[ÃÄÅÂ�]/u';

        foreach ([
            'panel.roles' => ['name', 'description'],
            'panel.resources' => ['name'],
            'panel.menu_groups' => ['name'],
            'panel.pages' => ['name', 'description'],
            'panel.page_menu' => ['label'],
            'panel.buttons' => ['label'],
            'panel.data_sources' => ['name'],
        ] as $table => $columns) {
            DB::table($table)->select($columns)->get()->each(function (object $row) use ($columns, $failPattern, $table): void {
                foreach ($columns as $column) {
                    $value = (string) ($row->{$column} ?? '');
                    $this->assertDoesNotMatchRegularExpression($failPattern, $value, "{$table}.{$column} mojibake içeriyor: {$value}");
                }
            });
        }

        PageConfig::query()->get()->each(function (PageConfig $config) use ($failPattern): void {
            $layout = json_encode($config->layout_json ?? [], JSON_UNESCAPED_UNICODE);
            $filters = json_encode($config->filters_json ?? [], JSON_UNESCAPED_UNICODE);

            $this->assertDoesNotMatchRegularExpression($failPattern, (string) $layout, "{$config->page_code} layout_json mojibake içeriyor.");
            $this->assertDoesNotMatchRegularExpression($failPattern, (string) $filters, "{$config->page_code} filters_json mojibake içeriyor.");
        });

        $salesConfig = PageConfig::query()->where('page_code', 'sales_main')->firstOrFail();
        $filtersJson = json_encode($salesConfig->filters_json, JSON_UNESCAPED_UNICODE);
        $layoutJson = json_encode($salesConfig->layout_json, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Tümü', (string) $filtersJson);
        $this->assertStringContainsString('Ümit Yıldız', (string) $filtersJson);
        $this->assertStringContainsString('Günlük', (string) $filtersJson);
        $this->assertStringContainsString('Haftalık', (string) $filtersJson);
        $this->assertStringContainsString('Aylık', (string) $filtersJson);
        $this->assertStringContainsString('Yıllık', (string) $filtersJson);
        $this->assertStringContainsString('Müşteri Satış Detayı', (string) $filtersJson);
        $this->assertStringContainsString('Ürün Satış Detayı', (string) $filtersJson);
        $this->assertStringContainsString('Müşteri Yönetimi', (string) $layoutJson);
        $this->assertStringNotContainsString('Cari Yönetimi', (string) $layoutJson);

        $detailModeKeys = collect($salesConfig->filters_json['detailModes'] ?? [])->pluck('key')->all();
        $this->assertSame(['cari', 'urun'], $detailModeKeys);
    }

    public function test_sales_preview_payload_keeps_ascii_data_keys(): void
    {
        $seederSource = file_get_contents(database_path('seeders/PanelMetadataSeeder.php')) ?: '';

        $this->assertStringContainsString("'satir_tipi'", $seederSource);
        $this->assertStringContainsString("'satir_adi'", $seederSource);
        $this->assertStringContainsString("'urun' => [", $seederSource);
        $this->assertStringNotContainsString("'satır_tipi'", $seederSource);
        $this->assertStringNotContainsString("'satır_adi'", $seederSource);
        $this->assertStringNotContainsString("'ürün' => [", $seederSource);
    }

    public function test_sales_layout_keeps_chart_above_breakdown(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/SalesMainDashboard.jsx')) ?: '';

        $this->assertStringContainsString('<SalesPieChart chart={data?.chart} />', $component);
        $this->assertStringContainsString('<SalesBreakdown breakdown={data?.breakdown} table={data?.table} />', $component);
        $this->assertStringNotContainsString('xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]', $component);
    }

    public function test_sales_scope_datasource_queries_are_distinct_and_filtered(): void
    {
        $salesMain = DataSource::query()->where('code', 'sales_main_dashboard')->firstOrFail();
        $online = DataSource::query()->where('code', 'sales_online_perakende_detail')->firstOrFail();
        $bayi = DataSource::query()->where('code', 'sales_bayi_proje_detail')->firstOrFail();

        $salesQuery = (string) $salesMain->query_template;
        $onlineQuery = (string) $online->query_template;
        $bayiQuery = (string) $bayi->query_template;

        $this->assertNotSame($salesQuery, $onlineQuery, 'Online/perakende query sales_main ile ayni kalmamali.');
        $this->assertNotSame($salesQuery, $bayiQuery, 'Bayi/proje query sales_main ile ayni kalmamali.');
        $this->assertNotSame($onlineQuery, $bayiQuery, 'Online ve bayi queryleri farkli olmalı.');

        $this->assertMatchesRegularExpression('/cari_grup_kodu[^\\n]+IN\\s*\\([^)]*120\\.01[^)]*120\\.16/is', $onlineQuery);
        $this->assertMatchesRegularExpression('/cari_grup_kodu[^\\n]+NOT\\s+IN\\s*\\([^)]*120\\.01[^)]*120\\.16/is', $bayiQuery);
        $this->assertMatchesRegularExpression('/NULLIF|IS\\s+NULL/i', $bayiQuery);
    }

    public function test_sales_main_labels_use_user_facing_turkish(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'siralama_1' => 1,
                        'cari_grup_adi' => 'Grup A',
                        'adet' => 2,
                        'ciro' => 1250.50,
                        'konsinye_tutari' => 0,
                    ],
                    [
                        'satir_tipi' => 'CARI',
                        'cari_grup_adi' => 'Grup A',
                        'cari_kodu' => 'C-1',
                        'satir_adi' => 'Müşteri A',
                        'adet' => 2,
                        'ciro' => 1250.50,
                    ],
                    [
                        'satir_tipi' => 'URUN',
                        'cari_grup_adi' => 'Grup A',
                        'parent_key' => 'C-1',
                        'satir_adi' => 'Ürün A',
                        'adet' => 2,
                        'ciro' => 1250.50,
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create(['role_code' => 'admin']);

        $payload = app(SalesMainPageService::class)->dataset($user, [
            'scope_key' => 'all',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        $this->assertContains('Seçili Dönem', collect($payload['kpis'])->pluck('label')->all());
        $this->assertContains('Konsinye Hariç', collect($payload['kpis'])->pluck('label')->all());
        $this->assertSame('Satış Dağılımı', $payload['chart']['title']);
        $this->assertSame('Satış Detayı', $payload['breakdown']['title']);

        $urunPayload = app(SalesMainPageService::class)->dataset($user, [
            'scope_key' => 'all',
            'detail_type' => 'urun',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        $this->assertSame('Marka Satış Karşılaştırması', $urunPayload['chart']['title']);
        $this->assertSame('Ürün / Müşteri Özeti', $urunPayload['breakdown']['title']);
    }

    public function test_empty_proforma_queries_return_friendly_messages_without_gateway_call(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create(['role_code' => 'proforma']))
            ->postJson('/api/data/proforma')
            ->assertOk()
            ->assertJsonPath('rows', [])
            ->assertJsonPath('queryMeta.notice', 'Proforma veri kaynağı henüz tanımlı değil.');

        Http::assertNothingSent();
    }

    public function test_user_access_override_can_allow_or_deny_role_permission(): void
    {
        $service = app(PanelAccessService::class);
        $user = User::factory()->create(['role_code' => 'viewer']);

        Resource::query()->firstOrCreate(['code' => 'customers'], ['name' => 'Müşteri', 'type' => 'page', 'active' => true]);

        $this->assertFalse($service->userCanAccess($user, 'customers'));

        UserAccess::query()->create([
            'user_id' => $user->id,
            'resource_code' => 'customers',
            'can_view' => true,
        ]);

        $this->assertTrue($service->userCanAccess($user->refresh(), 'customers'));

        RoleResourcePermission::query()->updateOrCreate(
            ['role_code' => 'viewer', 'resource_code' => 'customers'],
            ['can_view' => true],
        );

        UserAccess::query()->where('user_id', $user->id)->where('resource_code', 'customers')->update(['can_view' => false]);

        $this->assertFalse($service->userCanAccess($user->refresh(), 'customers'));
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create(['aktif' => false]);

        $this->post(route('login.store'), [
            'email' => $user->username,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_unauthorized_page_and_data_api_are_blocked(): void
    {
        $user = User::factory()->create(['role_code' => 'viewer']);

        $this->actingAs($user)->get('/sales/main')->assertForbidden();
        $this->actingAs($user)->postJson('/api/data/sales_main')->assertForbidden();
    }

    public function test_authorized_data_api_calls_gateway(): void
    {
        Http::fake([
            'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1' => Http::response([
                'ok' => true,
                'rows' => [
                    ['stok_kodu' => 'STK-1', 'stok_adi' => 'Test Urun', 'toplam_miktar' => 3],
                ],
            ]),
        ]);

        $user = User::factory()->create(['role_code' => 'stock']);

        $this->actingAs($user)
            ->postJson('/api/data/stock', ['search' => 'STK'])
            ->assertOk()
            ->assertJsonPath('rows.0.stok_kodu', 'STK-1');

        Http::assertSent(fn (Request $request) => $request['source_code'] === 'stock_dashboard'
            && $request['params']['search'] === 'STK');
    }

    public function test_direct_data_source_posts_use_module_resource_mapping_and_call_gateway(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'id' => 1,
                        'stok_kodu' => 'STK-1',
                        'cari_kodu' => 'C-1',
                        'siparis_no' => 'SIP-1',
                        'satir_tipi' => 'GRUP',
                        'satir_adi' => 'Test',
                        'adet' => 1,
                        'ciro' => 100,
                    ],
                ],
            ]),
        ]);

        $cases = [
            'stock_dashboard' => ['stock', 'stock_locks'],
            'orders_alinan' => ['orders_alinan'],
            'orders_verilen' => ['orders_verilen'],
            'customers_list' => ['customers', 'customers_own_rep'],
            'customer_statement' => ['customers', 'customers_own_rep'],
            'sales_online_perakende_detail' => ['sales_online'],
            'sales_bayi_proje_detail' => ['sales_bayi'],
        ];

        foreach ($cases as $sourceCode => $resources) {
            $user = User::factory()->create([
                'role_code' => 'viewer',
                'aktif' => true,
                'temsilci_kodu' => '0003',
            ]);

            foreach ($resources as $resourceCode) {
                UserAccess::query()->create([
                    'user_id' => $user->id,
                    'resource_code' => $resourceCode,
                    'can_view' => true,
                ]);
            }

            $this->actingAs($user)
                ->postJson("/api/data/{$sourceCode}", ['bypass_cache' => true])
                ->assertOk()
                ->assertJsonPath('queryMeta.dataSource', $sourceCode);

            Http::assertSent(fn (Request $request): bool => ($request['source_code'] ?? null) === $sourceCode);
        }
    }

    public function test_direct_data_source_posts_are_forbidden_before_gateway_when_scope_resource_is_missing(): void
    {
        Http::fake();

        $onlineOnly = User::factory()->create(['role_code' => 'viewer', 'aktif' => true]);
        UserAccess::query()->create([
            'user_id' => $onlineOnly->id,
            'resource_code' => 'sales_online',
            'can_view' => true,
        ]);

        $this->actingAs($onlineOnly)
            ->postJson('/api/data/sales_bayi_proje_detail', ['bypass_cache' => true])
            ->assertForbidden();

        $bayiOnly = User::factory()->create(['role_code' => 'viewer', 'aktif' => true]);
        UserAccess::query()->create([
            'user_id' => $bayiOnly->id,
            'resource_code' => 'sales_bayi',
            'can_view' => true,
        ]);

        $this->actingAs($bayiOnly)
            ->postJson('/api/data/sales_online_perakende_detail', ['bypass_cache' => true])
            ->assertForbidden();

        $noStockScope = User::factory()->create(['role_code' => 'viewer', 'aktif' => true]);
        UserAccess::query()->create([
            'user_id' => $noStockScope->id,
            'resource_code' => 'stock',
            'can_view' => true,
        ]);
        UserAccess::query()->create([
            'user_id' => $noStockScope->id,
            'resource_code' => 'stock_locks',
            'can_view' => false,
        ]);

        $this->actingAs($noStockScope)
            ->postJson('/api/data/stock_dashboard', ['bypass_cache' => true])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_sales_main_post_uses_online_and_bayi_scope_sources_without_representative_code(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'satir_tipi' => 'GRUP',
                        'satir_adi' => 'Online Grup',
                        'cari_grup_adi' => 'Online Grup',
                        'adet' => 1,
                        'ciro' => 100,
                    ],
                ],
            ]),
        ]);

        $onlineUser = User::factory()->create([
            'role_code' => 'viewer',
            'aktif' => true,
            'temsilci_kodu' => null,
        ]);
        UserAccess::query()->create([
            'user_id' => $onlineUser->id,
            'resource_code' => 'sales_online',
            'can_view' => true,
        ]);

        $this->actingAs($onlineUser)
            ->postJson('/api/data/sales-main', [
                'scope_key' => 'online_perakende',
                'detail_type' => 'cari',
                'date_from' => '2026-04-01',
                'date_to' => '2026-04-30',
                'bypass_cache' => true,
            ])
            ->assertOk()
            ->assertJsonPath('queryMeta.dataSource', 'sales_online_perakende_detail')
            ->assertJsonPath('scope.key', 'online_perakende')
            ->assertJsonPath('queryMeta.gatewayRequest.rep_code', null);

        $bayiUser = User::factory()->create([
            'role_code' => 'viewer',
            'aktif' => true,
            'temsilci_kodu' => null,
        ]);
        UserAccess::query()->create([
            'user_id' => $bayiUser->id,
            'resource_code' => 'sales_bayi',
            'can_view' => true,
        ]);

        $this->actingAs($bayiUser)
            ->postJson('/api/data/sales-main', [
                'scope_key' => 'bayi_proje',
                'detail_type' => 'cari',
                'date_from' => '2026-04-01',
                'date_to' => '2026-04-30',
                'bypass_cache' => true,
            ])
            ->assertOk()
            ->assertJsonPath('queryMeta.dataSource', 'sales_bayi_proje_detail')
            ->assertJsonPath('scope.key', 'bayi_proje')
            ->assertJsonPath('queryMeta.gatewayRequest.rep_code', null);

        Http::assertSent(fn (Request $request): bool => ($request['source_code'] ?? null) === 'sales_online_perakende_detail'
            && ($request['scope_key'] ?? null) === 'online_perakende'
            && ($request['rep_code'] ?? null) === null);
        Http::assertSent(fn (Request $request): bool => ($request['source_code'] ?? null) === 'sales_bayi_proje_detail'
            && ($request['scope_key'] ?? null) === 'bayi_proje'
            && ($request['rep_code'] ?? null) === null);
    }

    public function test_admin_can_access_user_management(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();
    }
}
