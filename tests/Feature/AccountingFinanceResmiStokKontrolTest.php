<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\RoleResourcePermission;
use App\Models\User;
use App\Models\UserAccess;
use App\Services\PanelAccessService;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithTestHttpIsolation;
use Tests\TestCase;

class AccountingFinanceResmiStokKontrolTest extends TestCase
{
    use InteractsWithTestHttpIsolation, RefreshDatabase;

    private const RESOURCE_CODE = 'accounting_finance_resmi_stok_kontrol';

    private const ROUTE_PATH = '/accounting-finance/resmi-stok-kontrol';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(PanelMetadataSeeder::class);
        $this->seed(PanelKnownWorkflowDataSourcesSeeder::class);
        $this->useTestPanelDataSourceGateway();
    }

    public function test_admin_can_see_accounting_finance_resmi_stok_kontrol_by_default(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $this->assertTrue(app(PanelAccessService::class)->userCanAccess($admin, self::RESOURCE_CODE));

        $hrefs = $this->navigationHrefsFor($admin);

        $this->assertContains(self::ROUTE_PATH, $hrefs);

        $this->actingAs($admin)
            ->get(self::ROUTE_PATH)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('panel/accounting-finance/resmi-stok-kontrol')
                ->where('page.slug', self::RESOURCE_CODE)
                ->where('page.routePath', self::ROUTE_PATH)
            );
    }

    public function test_non_admin_roles_do_not_see_accounting_finance_by_default(): void
    {
        foreach (['manager', 'viewer', 'sales', 'stock', 'orders', 'technical', 'customer', 'proforma'] as $roleCode) {
            $user = User::factory()->create(['role_code' => $roleCode]);

            $this->assertFalse(app(PanelAccessService::class)->userCanAccess($user, self::RESOURCE_CODE));
            $this->assertNotContains(self::ROUTE_PATH, $this->navigationHrefsFor($user), "{$roleCode} should not see accounting finance.");

            $this->actingAs($user)
                ->get(self::ROUTE_PATH)
                ->assertForbidden();
        }
    }

    public function test_explicit_user_access_allows_accounting_finance_navigation(): void
    {
        $user = User::factory()->create(['role_code' => 'viewer']);

        UserAccess::query()->create([
            'user_id' => $user->id,
            'resource_code' => self::RESOURCE_CODE,
            'can_view' => true,
        ]);

        $hrefs = $this->navigationHrefsFor($user->refresh());

        $this->assertContains(self::ROUTE_PATH, $hrefs);
        $this->assertTrue(app(PanelAccessService::class)->userCanAccess($user, self::RESOURCE_CODE));

        $this->actingAs($user)
            ->get(self::ROUTE_PATH)
            ->assertOk();
    }

    public function test_dashboard_home_and_module_layout_use_authorized_accounting_finance_route(): void
    {
        $dashboardHome = file_get_contents(resource_path('js/pages/panel/DashboardHome.jsx')) ?: '';
        $moduleLayout = file_get_contents(resource_path('js/layouts/module-layout.tsx')) ?: '';

        $this->assertStringContainsString('Muhasebe / Finans', $dashboardHome);
        $this->assertStringContainsString('Resmi stok, fiili stok ve muhasebe kontrol farklarını izleyin.', $dashboardHome);
        $this->assertStringContainsString("candidates: ['".self::ROUTE_PATH."']", $dashboardHome);
        $this->assertStringContainsString('firstVisibleHref(card.candidates, visibleHrefs)', $dashboardHome);
        $this->assertStringContainsString('.filter((card) => card.href !== null)', $dashboardHome);

        $this->assertStringContainsString('Muhasebe / Finans', $moduleLayout);
        $this->assertStringContainsString("candidates: ['".self::ROUTE_PATH."']", $moduleLayout);
        $this->assertStringContainsString("match: ['".self::ROUTE_PATH."']", $moduleLayout);
        $this->assertMatchesRegularExpression(
            '/selectModuleHref\(\s*item\.candidates,\s*visibleHrefs,?\s*\)/',
            $moduleLayout,
        );

        $unauthorized = User::factory()->create(['role_code' => 'viewer']);
        $authorized = User::factory()->create(['role_code' => 'viewer']);

        UserAccess::query()->create([
            'user_id' => $authorized->id,
            'resource_code' => self::RESOURCE_CODE,
            'can_view' => true,
        ]);

        $this->assertNotContains(self::ROUTE_PATH, $this->navigationHrefsFor($unauthorized));
        $this->assertContains(self::ROUTE_PATH, $this->navigationHrefsFor($authorized->refresh()));
    }

    public function test_data_endpoint_uses_panel_gateway_source_code_and_frontend_has_no_hardcoded_webhook(): void
    {
        $this->fakeIsolatedHttp([
            self::TEST_PANEL_DATA_SOURCE_GATEWAY_URL => Http::response([
                'ok' => true,
                'rows' => [
                    ['row_type' => 'summary', 'Kategori' => 'Toplam', 'NetStokEtkisi' => 0],
                ],
            ]),
        ]);

        $admin = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($admin)
            ->postJson('/api/data/'.self::RESOURCE_CODE, [
                'date_from' => '2026-05-15',
                'date_to' => '2026-05-15',
                'bypass_cache' => true,
            ])
            ->assertOk()
            ->assertJsonPath('queryMeta.dataSource', self::RESOURCE_CODE)
            ->assertJsonPath('rows.0.row_type', 'summary');

        Http::assertSent(fn (Request $request) => ($request['source_code'] ?? null) === self::RESOURCE_CODE
            && ($request['date_from'] ?? null) === '2026-05-15'
            && ($request['date_to'] ?? null) === '2026-05-15');

        $frontend = file_get_contents(resource_path('js/pages/panel/accounting-finance/resmi-stok-kontrol.tsx')) ?: '';

        $this->assertStringContainsString('/api/data/${dataSourceCode}', $frontend);
        $this->assertStringContainsString("const dataSourceCode = '".self::RESOURCE_CODE."';", $frontend);

        foreach ($this->forbiddenEndpointFragments() as $forbiddenFragment) {
            $this->assertStringNotContainsString($forbiddenFragment, $frontend);
        }
    }

    public function test_role_permission_rows_do_not_grant_non_admin_default_visibility(): void
    {
        $defaultVisibleRoles = RoleResourcePermission::query()
            ->where('resource_code', self::RESOURCE_CODE)
            ->where('can_view', true)
            ->pluck('role_code')
            ->all();

        $this->assertContains('admin', $defaultVisibleRoles);
        $this->assertNotContains('manager', $defaultVisibleRoles);
        $this->assertNotContains('viewer', $defaultVisibleRoles);
        $this->assertNotContains('sales', $defaultVisibleRoles);
        $this->assertNotContains('stock', $defaultVisibleRoles);
        $this->assertNotContains('orders', $defaultVisibleRoles);
        $this->assertNotContains('technical', $defaultVisibleRoles);
        $this->assertNotContains('customer', $defaultVisibleRoles);
        $this->assertNotContains('proforma', $defaultVisibleRoles);
    }

    public function test_datasource_query_template_is_real_read_only_mssql_query(): void
    {
        $this->assertAccountingFinanceQueryTemplateIsCanonical();
    }

    public function test_deploy_seed_order_preserves_accounting_finance_query_template(): void
    {
        $this->seed(PanelMetadataSeeder::class);
        $this->seed(PanelKnownWorkflowDataSourcesSeeder::class);
        $this->assertAccountingFinanceQueryTemplateIsCanonical();

        $this->seed(PanelMetadataSeeder::class);
        $this->assertAccountingFinanceQueryTemplateIsCanonical();

        $this->seed(PanelKnownWorkflowDataSourcesSeeder::class);
        $this->assertAccountingFinanceQueryTemplateIsCanonical();
    }

    public function test_known_workflow_seeder_refresh_source_is_available_and_scoped(): void
    {
        $seeder = new PanelKnownWorkflowDataSourcesSeeder;
        $stockSource = DataSource::query()->where('code', 'stock_dashboard')->firstOrFail();
        $stockQueryTemplate = $stockSource->query_template;
        $stockAllowedParams = $stockSource->allowed_params;

        $this->assertTrue(method_exists($seeder, 'refreshSource'));
        $this->assertTrue($seeder->refreshSource(self::RESOURCE_CODE));
        $this->assertAccountingFinanceQueryTemplateIsCanonical();

        $this->assertFalse($seeder->refreshSource('stock_dashboard'));
        $stockSource->refresh();

        $this->assertSame($stockQueryTemplate, $stockSource->query_template);
        $this->assertSame($stockAllowedParams, $stockSource->allowed_params);
    }

    public function test_start_container_runs_source_scoped_post_deploy_refresh_after_panel_metadata_seed(): void
    {
        $script = file_get_contents(base_path('docker/start-container.sh')) ?: '';
        $fullRefreshCommand = 'php artisan panel:post-deploy-refresh --no-interaction';
        $scopedRefreshCommand = 'php artisan panel:post-deploy-refresh --source='.self::RESOURCE_CODE.' --no-interaction';
        $metadataSeedPosition = strpos($script, 'php artisan db:seed --class=PanelMetadataSeeder --force --no-interaction');
        $postDeployRefreshPosition = strpos($script, $scopedRefreshCommand);

        $this->assertNotFalse($metadataSeedPosition);
        $this->assertStringNotContainsString($fullRefreshCommand, $script);
        $this->assertNotFalse($postDeployRefreshPosition);
        $this->assertGreaterThan($metadataSeedPosition, $postDeployRefreshPosition);
    }

    public function test_source_scoped_post_deploy_refresh_updates_only_accounting_finance_datasource(): void
    {
        $protectedSources = [
            'sales_main_dashboard' => "SELECT N'sales-main-sentinel' AS value",
            'stock_dashboard' => "SELECT N'stock-sentinel' AS value",
            'orders_alinan' => "SELECT N'orders-alinan-sentinel' AS value",
            'orders_verilen' => "SELECT N'orders-verilen-sentinel' AS value",
        ];

        foreach ($protectedSources as $code => $queryTemplate) {
            DataSource::query()->where('code', $code)->firstOrFail()->forceFill([
                'query_template' => $queryTemplate,
                'allowed_params' => ['sentinel_param'],
            ])->save();
        }

        $this->artisan('panel:post-deploy-refresh', [
            '--source' => self::RESOURCE_CODE,
        ])->assertExitCode(0);

        $this->assertAccountingFinanceQueryTemplateIsCanonical();

        foreach ($protectedSources as $code => $queryTemplate) {
            $source = DataSource::query()->where('code', $code)->firstOrFail();

            $this->assertSame($queryTemplate, $source->query_template);
            $this->assertSame(['sentinel_param'], $source->allowed_params);
        }
    }

    public function test_source_scoped_post_deploy_refresh_rejects_unsupported_source(): void
    {
        $stockSource = DataSource::query()->where('code', 'stock_dashboard')->firstOrFail();
        $stockQueryTemplate = $stockSource->query_template;

        try {
            $this->artisan('panel:post-deploy-refresh', [
                '--source' => 'stock_dashboard',
            ]);

            $this->fail('Expected unsupported datasource source exception.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unsupported datasource source [stock_dashboard].', $exception->getMessage());
        }

        $stockSource->refresh();

        $this->assertSame($stockQueryTemplate, $stockSource->query_template);
    }

    private function assertAccountingFinanceQueryTemplateIsCanonical(): void
    {
        $source = DataSource::query()->where('code', self::RESOURCE_CODE)->firstOrFail();
        $query = trim((string) $source->query_template);

        $this->assertSame(['date_from', 'date_to', 'bypass_cache'], $source->allowed_params);
        $this->assertNotSame('', $query);
        $this->assertStringNotContainsString('Canlı SQL bu aşamada eklenmedi', $query);
        $this->assertMatchesRegularExpression('/(\[\[date_to\]\]|\{\{date_to\}\})/', $query);
        $this->assertMatchesRegularExpression('/\b(SELECT|WITH|DECLARE)\b/i', $query);
        $this->assertStringContainsString('DECLARE @RaporTarihi', $query);
        $this->assertStringContainsString('Devir2024', $query);
        $this->assertStringContainsString('KesinStokRaw', $query);
        $this->assertStringContainsString('FinalRows', $query);
        $this->assertStringNotContainsString('stock_scope', $query);
        $this->assertStringNotContainsString('DECLARE @BasTar', $query);
        $this->assertMatchesRegularExpression('/\bSTOK_HAREKETLERI\b/i', $query);
        $this->assertMatchesRegularExpression('/\bSTOKLAR\b/i', $query);
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|MERGE|ALTER|DROP|EXEC)\b/i', $query);
    }

    /**
     * @return list<string>
     */
    private function navigationHrefsFor(User $user): array
    {
        return collect($this->actingAs($user)->getJson('/api/navigation')->assertOk()->json('groups'))
            ->flatMap(fn (array $group) => $group['items'] ?? [])
            ->pluck('href')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function forbiddenEndpointFragments(): array
    {
        return [
            implode('', ['hook.', 'emaksprime.com.tr']),
            implode('', ['n8n.', 'emaksprime.com.tr']),
            'PANEL_'.'N8N_GATEWAY_URL',
            'PANEL_'.'N8N_TOKEN',
        ];
    }
}
