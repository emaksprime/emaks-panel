<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\Page;
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
            'customer_statement',
            'proforma_customer_search',
            'proforma_stock_search',
        ] as $code) {
            $source = DataSource::query()->where('code', $code)->first();

            $this->assertNotNull($source, "Datasource [{$code}] seed edilmeliydi.");
            $this->assertNotSame('', trim((string) $source->query_template), "Datasource [{$code}] query_template bos olmamali.");
        }

        foreach (['customer_detail', 'customer_documents', 'proforma_list', 'proforma_detail', 'proforma_draft', 'proforma_items'] as $code) {
            $this->assertDatabaseHas('panel.data_sources', ['code' => $code]);
        }
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

        $this->assertMatchesRegularExpression("/cari_grup_kodu[^\\n]+IN\\s*\\([^)]*120\\.01[^)]*120\\.16/is", $onlineQuery);
        $this->assertMatchesRegularExpression("/cari_grup_kodu[^\\n]+NOT\\s+IN\\s*\\([^)]*120\\.01[^)]*120\\.16/is", $bayiQuery);
        $this->assertMatchesRegularExpression("/NULLIF|IS\\s+NULL/i", $bayiQuery);
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

        $this->assertSame('Ürün Ciro Dağılımı', $urunPayload['chart']['title']);
        $this->assertSame('Ürün / Müşteri Kırılımı', $urunPayload['breakdown']['title']);
    }

    public function test_empty_customer_and_proforma_queries_return_friendly_messages_without_gateway_call(): void
    {
        $customerDetailSource = DataSource::query()->where('code', 'customer_detail')->firstOrFail();
        $this->assertSame('', trim((string) $customerDetailSource->query_template));

        Page::query()->updateOrCreate(
            ['code' => 'customer_detail'],
            [
                'name' => 'Müşteri Detayı',
                'route' => '/customer/detail',
                'component' => 'panel/page',
                'layout_type' => 'module',
                'icon' => 'wallet',
                'description' => 'Müşteri detay testi',
                'resource_code' => 'customers',
                'page_order' => 999,
                'active' => true,
            ],
        );

        PageConfig::query()->updateOrCreate(
            ['page_code' => 'customer_detail'],
            [
                'layout_json' => [],
                'filters_json' => [],
                'datasource_id' => $customerDetailSource->id,
            ],
        );

        Http::fake();

        $customer = User::factory()->create(['role_code' => 'customer']);

        $this->actingAs($customer)
            ->postJson('/api/data/customer_detail')
            ->assertOk()
            ->assertJsonPath('rows', [])
            ->assertJsonPath('queryMeta.notice', 'Müşteri veri kaynağı henüz tanımlı değil.');

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

        Resource::query()->firstOrCreate(['code' => 'stock'], ['name' => 'Stok', 'type' => 'page', 'active' => true]);

        $this->assertFalse($service->userCanAccess($user, 'stock'));

        UserAccess::query()->create([
            'user_id' => $user->id,
            'resource_code' => 'stock',
            'can_view' => true,
        ]);

        $this->assertTrue($service->userCanAccess($user->refresh(), 'stock'));

        RoleResourcePermission::query()->updateOrCreate(
            ['role_code' => 'viewer', 'resource_code' => 'stock'],
            ['can_view' => true],
        );

        UserAccess::query()->where('user_id', $user->id)->where('resource_code', 'stock')->update(['can_view' => false]);

        $this->assertFalse($service->userCanAccess($user->refresh(), 'stock'));
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

    public function test_admin_can_access_user_management(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();
    }
}
