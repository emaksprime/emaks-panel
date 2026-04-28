<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\Resource;
use App\Models\RoleResourcePermission;
use App\Models\User;
use App\Models\UserAccess;
use App\Services\PanelAccessService;
use Database\Seeders\PanelDataSourcesSeeder;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
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
        $user = User::factory()->create(['role_code' => 'stock']);

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
