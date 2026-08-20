<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PanelAccessService;
use Database\Seeders\PanelDataSourcesSeeder;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithTestHttpIsolation;
use Tests\TestCase;

class StockManagementCleanCriticalAdminTest extends TestCase
{
    use InteractsWithTestHttpIsolation, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->seed(PanelMetadataSeeder::class);
        $this->seed(PanelDataSourcesSeeder::class);
        $this->seed(PanelKnownWorkflowDataSourcesSeeder::class);
        $this->useTestPanelDataSourceGateway();
    }

    public function test_stock_page_uses_dedicated_dashboard_without_proforma_actions(): void
    {
        $panelPage = file_get_contents(resource_path('js/pages/panel/page.tsx')) ?: '';
        $dashboard = file_get_contents(resource_path('js/pages/panel/stock/StockDashboard.jsx')) ?: '';
        $utils = file_get_contents(resource_path('js/pages/panel/stock/stockUtils.js')) ?: '';

        $this->assertStringContainsString('StockDashboard', $panelPage);
        $this->assertStringContainsString("matchesPage('stock', '/stock')", $panelPage);
        $this->assertStringContainsString("matchesPage('stock_critical', '/stock/critical')", $panelPage);
        $this->assertStringContainsString('AKILLI KİLİT', $utils);
        $this->assertStringContainsString('resolveCategoryFilter', $dashboard);
        $this->assertStringContainsString('Stok Yönetimi', $dashboard);
        $this->assertStringContainsString('Kritik stok belirle', $dashboard);
        $this->assertStringContainsString('Kritik Stoktaki Model Adedi', $dashboard);
        $this->assertStringContainsString('canManageCritical', $dashboard);
        $this->assertStringContainsString('/api/stock/critical-settings', $dashboard);
        $this->assertStringContainsString('md:hidden', $dashboard);
        $this->assertStringContainsString('Eye', $dashboard);
        $this->assertStringContainsString('EyeOff', $dashboard);
        $this->assertStringNotContainsString('Proforma Sepeti', $dashboard);
        $this->assertStringNotContainsString('>Ekle<', $dashboard);
        $this->assertStringNotContainsString('addToCart', $dashboard);
    }

    public function test_stock_critical_utils_only_mark_admin_configured_threshold_rows(): void
    {
        [$exitCode, $output, $error] = $this->runNodeModule(<<<'JS'
            import {
                ALL_CATEGORIES,
                DEFAULT_STOCK_CATEGORY,
                LOCK_STOCK_CATEGORY_CODES,
                applyStockScope,
                categoryOptions,
                decorateStockRows,
                filterStockRows,
                resolveCategoryFilter,
                sortStockRows,
            } from './resources/js/pages/panel/stock/stockUtils.js';

            const rows = [
                { stok_kodu: 'LOW-NO-SETTING', stok_adi: 'Düşük ama ayarsız', kategori: 'AKILLI KİLİT', kategori_kodu: 'A1', toplam_miktar: 1 },
                { stok_kodu: 'CRIT-1', stok_adi: 'Kritik ürün', kategori: 'AKILLI KİLİT', kategori_kodu: 'A1', toplam_miktar: 2 },
                { stok_kodu: 'SAFE-1', stok_adi: 'Güvenli ürün', kategori: 'AKILLI KİLİT', kategori_kodu: 'A1', toplam_miktar: 20 },
                { stok_kodu: 'MECH-1', stok_adi: 'Mekanik ürün', kategori: 'MEKANİK', kategori_kodu: 'M1', toplam_miktar: 7 },
                { stok_kodu: 'WIDE-CRIT', stok_adi: 'Whitelist dışı kritik', kategori: 'DİĞER', kategori_kodu: 'X1', toplam_miktar: 1 },
                { stok_kodu: 'NAME-ONLY', stok_adi: 'Kod yok ama isim kilit', kategori: 'AKILLI KİLİT', toplam_miktar: 6 },
                { stok_kodu: 'ZERO-1', stok_adi: 'Sıfır stok', kategori: 'AKILLI KİLİT', toplam_miktar: 0 },
            ];
            const settings = [
                { stock_code: 'CRIT-1', threshold_quantity: 5, active: true },
                { stock_code: 'SAFE-1', threshold_quantity: 5, active: true },
                { stock_code: 'WIDE-CRIT', threshold_quantity: 5, active: true },
            ];
            const decorated = decorateStockRows(rows, settings);
            const sorted = sortStockRows(decorated);
            const options = categoryOptions(decorated);
            const lockScoped = applyStockScope(decorated, 'locks');
            const lockOptions = categoryOptions(lockScoped);
            const allScoped = applyStockScope(decorated, 'all');
            const fallbackOptions = categoryOptions(decorateStockRows([
                { stok_kodu: 'ONLY-MECH', stok_adi: 'Sadece mekanik', kategori: 'MEKANİK', kategori_kodu: 'M1', toplam_miktar: 8 },
            ], []));

            console.log(JSON.stringify({
                defaultCategory: DEFAULT_STOCK_CATEGORY,
                lockWhitelist: LOCK_STOCK_CATEGORY_CODES,
                criticalCodes: filterStockRows(decorated, { mode: 'critical', category: 'AKILLI KİLİT' }).map((row) => row.stock_code),
                allCodes: filterStockRows(decorated, { mode: 'list', category: 'AKILLI KİLİT' }).map((row) => row.stock_code),
                allCategoryCodes: filterStockRows(decorated, { mode: 'list', category: ALL_CATEGORIES }).map((row) => row.stock_code),
                mechanicalCodes: filterStockRows(decorated, { mode: 'list', category: 'M1' }).map((row) => row.stock_code),
                optionValues: options.map((option) => option.value),
                optionLabels: options.map((option) => option.label),
                lockCodes: lockScoped.map((row) => row.stock_code),
                allScopeCodes: allScoped.map((row) => row.stock_code),
                lockAllCategoryCodes: filterStockRows(lockScoped, { mode: 'list', category: ALL_CATEGORIES }).map((row) => row.stock_code),
                lockOptionValues: lockOptions.map((option) => option.value),
                lockOptionLabels: lockOptions.map((option) => option.label),
                lockCriticalCount: lockScoped.filter((row) => row.isCritical).length,
                allCriticalCount: allScoped.filter((row) => row.isCritical).length,
                defaultResolved: resolveCategoryFilter(DEFAULT_STOCK_CATEGORY, options),
                missingDefaultResolved: resolveCategoryFilter(DEFAULT_STOCK_CATEGORY, fallbackOptions),
                firstCode: sorted[0].stock_code,
                lowWithoutSettingCritical: decorated.find((row) => row.stock_code === 'LOW-NO-SETTING').isCritical,
                safeWithSettingCritical: decorated.find((row) => row.stock_code === 'SAFE-1').isCritical,
            }));
        JS);

        $this->assertSame(0, $exitCode, $error);

        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('AKILLI KİLİT', $results['defaultCategory']);
        $this->assertSame(['A1', 'AS1', 'D1', 'G1', 'K1', 'KA1', 'M1', 'O1', 'OT1', 'YM1'], $results['lockWhitelist']);
        $this->assertSame(['CRIT-1'], $results['criticalCodes']);
        $this->assertSame(['LOW-NO-SETTING', 'CRIT-1', 'SAFE-1', 'NAME-ONLY'], $results['allCodes']);
        $this->assertSame(['LOW-NO-SETTING', 'CRIT-1', 'SAFE-1', 'MECH-1', 'WIDE-CRIT', 'NAME-ONLY'], $results['allCategoryCodes']);
        $this->assertSame(['MECH-1'], $results['mechanicalCodes']);
        $this->assertContains('A1', $results['optionValues']);
        $this->assertContains('M1', $results['optionValues']);
        $this->assertContains('AKILLI KİLİT', $results['optionLabels']);
        $this->assertContains('MEKANİK', $results['optionLabels']);
        $this->assertSame(['LOW-NO-SETTING', 'CRIT-1', 'SAFE-1', 'MECH-1'], $results['lockCodes']);
        $this->assertSame(['LOW-NO-SETTING', 'CRIT-1', 'SAFE-1', 'MECH-1', 'WIDE-CRIT', 'NAME-ONLY'], $results['allScopeCodes']);
        $this->assertSame(['LOW-NO-SETTING', 'CRIT-1', 'SAFE-1', 'MECH-1'], $results['lockAllCategoryCodes']);
        $this->assertSame(['Tüm Kategoriler', 'A1', 'M1'], $results['lockOptionValues']);
        $this->assertSame(['Tüm Kategoriler', 'AKILLI KİLİT', 'MEKANİK'], $results['lockOptionLabels']);
        $this->assertSame(1, $results['lockCriticalCount']);
        $this->assertSame(2, $results['allCriticalCount']);
        $this->assertSame('A1', $results['defaultResolved']);
        $this->assertSame('Tüm Kategoriler', $results['missingDefaultResolved']);
        $this->assertSame('CRIT-1', $results['firstCode']);
        $this->assertFalse($results['lowWithoutSettingCritical']);
        $this->assertFalse($results['safeWithSettingCritical']);
    }

    public function test_stock_scope_resources_and_role_defaults_are_seeded(): void
    {
        $this->assertDatabaseHas('panel.resources', [
            'code' => 'stock_all',
            'name' => 'Tüm Stok Görünümü',
            'type' => 'scope',
        ]);
        $this->assertDatabaseHas('panel.resources', [
            'code' => 'stock_locks',
            'name' => 'Kilit Stok Görünümü',
            'type' => 'scope',
        ]);

        foreach (['stock', 'technical', 'sales', 'customer', 'proforma', 'viewer'] as $roleCode) {
            $this->assertDatabaseHas('panel.role_resource_permissions', [
                'role_code' => $roleCode,
                'resource_code' => 'stock_locks',
                'can_view' => true,
            ]);
            $this->assertDatabaseHas('panel.role_resource_permissions', [
                'role_code' => $roleCode,
                'resource_code' => 'stock_all',
                'can_view' => false,
            ]);
        }

        $this->assertDatabaseHas('panel.role_resource_permissions', [
            'role_code' => 'manager',
            'resource_code' => 'stock_all',
            'can_view' => true,
        ]);
    }

    public function test_stock_scope_access_prefers_all_then_locks_and_blocks_missing_scope(): void
    {
        $access = app(PanelAccessService::class);
        $stockUser = User::factory()->create(['role_code' => 'stock', 'aktif' => true]);
        $manager = User::factory()->create(['role_code' => 'manager', 'aktif' => true]);
        $combined = User::factory()->create(['role_code' => 'viewer', 'aktif' => true]);
        $blocked = User::factory()->create(['role_code' => 'stock', 'aktif' => true]);

        DB::table('panel.user_access')->insert([
            [
                'user_id' => $combined->id,
                'resource_code' => 'stock_all',
                'can_view' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $blocked->id,
                'resource_code' => 'stock_locks',
                'can_view' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $blocked->id,
                'resource_code' => 'stock_all',
                'can_view' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame('locks', $access->stockScopeFor($stockUser));
        $this->assertSame('all', $access->stockScopeFor($manager));
        $this->assertSame('all', $access->stockScopeFor($combined));
        $this->assertNull($access->stockScopeFor($blocked));

        $this->actingAs($blocked)->get('/stock')->assertForbidden();
    }

    public function test_stock_data_api_exposes_stock_scope_and_requires_explicit_scope(): void
    {
        $this->fakeIsolatedHttp([
            self::TEST_PANEL_DATA_SOURCE_GATEWAY_URL => Http::response([
                'ok' => true,
                'rows' => [
                    ['stok_kodu' => 'LOCK-1', 'stok_adi' => 'Kilit', 'kategori' => 'AKILLI KİLİT', 'kategori_kodu' => 'A1', 'toplam_miktar' => 5],
                    ['stok_kodu' => 'OTHER-1', 'stok_adi' => 'Diğer', 'kategori' => 'DİĞER', 'kategori_kodu' => 'X1', 'toplam_miktar' => 8],
                ],
                'request' => [],
                'meta' => [],
            ]),
        ], expectedRequests: 2);

        $locksUser = User::factory()->create(['role_code' => 'stock', 'aktif' => true]);
        $allUser = User::factory()->create(['role_code' => 'manager', 'aktif' => true]);
        $blocked = User::factory()->create(['role_code' => 'stock', 'aktif' => true]);

        DB::table('panel.user_access')->insert([
            [
                'user_id' => $blocked->id,
                'resource_code' => 'stock_locks',
                'can_view' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $blocked->id,
                'resource_code' => 'stock_all',
                'can_view' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($locksUser)
            ->postJson('/api/data/stock', ['bypass_cache' => true])
            ->assertOk()
            ->assertJsonPath('queryMeta.stockScope', 'locks')
            ->assertJsonCount(2, 'rows');

        $this->actingAs($allUser)
            ->postJson('/api/data/stock', ['bypass_cache' => true])
            ->assertOk()
            ->assertJsonPath('queryMeta.stockScope', 'all')
            ->assertJsonCount(2, 'rows');

        $this->actingAs($blocked)
            ->postJson('/api/data/stock', ['bypass_cache' => true])
            ->assertForbidden();
    }

    public function test_super_admin_can_manage_stock_critical_settings(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin', 'aktif' => true]);

        $this->actingAs($admin)
            ->postJson('/api/stock/critical-settings', [
                'stock_code' => 'STK-001',
                'product_name' => 'Akıllı Kilit',
                'category' => 'AKILLI KİLİT',
                'threshold_quantity' => 5,
                'active' => true,
                'note' => 'Pilot ürün',
            ])
            ->assertOk()
            ->assertJsonPath('row.stock_code', 'STK-001')
            ->assertJsonPath('row.threshold_quantity', 5);

        $this->assertDatabaseHas('stock_critical_settings', [
            'stock_code' => 'STK-001',
            'active' => true,
            'created_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/stock/critical-settings/STK-001')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('stock_critical_settings', [
            'stock_code' => 'STK-001',
            'active' => false,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_manager_cannot_manage_stock_critical_settings(): void
    {
        $manager = User::factory()->create(['role_code' => 'manager', 'aktif' => true]);

        $this->actingAs($manager)
            ->postJson('/api/stock/critical-settings', [
                'stock_code' => 'STK-002',
                'threshold_quantity' => 3,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Yetki bulunmamaktadır.');

        $this->assertDatabaseMissing('stock_critical_settings', [
            'stock_code' => 'STK-002',
        ]);
    }

    public function test_stock_user_can_read_active_critical_settings_without_manage_permission(): void
    {
        $stockUser = User::factory()->create(['role_code' => 'stock', 'aktif' => true]);

        DB::table('stock_critical_settings')->insert([
            'stock_code' => 'STK-003',
            'product_name' => 'Kritik ürün',
            'category' => 'AKILLI KİLİT',
            'threshold_quantity' => 4,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($stockUser)
            ->getJson('/api/stock/critical-settings')
            ->assertOk()
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('rows.0.stock_code', 'STK-003');
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
