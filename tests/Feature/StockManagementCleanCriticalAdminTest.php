<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PanelDataSourcesSeeder;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockManagementCleanCriticalAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->seed(PanelMetadataSeeder::class);
        $this->seed(PanelDataSourcesSeeder::class);
        $this->seed(PanelKnownWorkflowDataSourcesSeeder::class);
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
        $this->assertStringContainsString('Stok Yönetimi', $dashboard);
        $this->assertStringContainsString('Kritik stok belirle', $dashboard);
        $this->assertStringContainsString('canManageCritical', $dashboard);
        $this->assertStringContainsString('/api/stock/critical-settings', $dashboard);
        $this->assertStringContainsString('md:hidden', $dashboard);
        $this->assertStringContainsString('Eye', $dashboard);
        $this->assertStringContainsString('EyeOff', $dashboard);
        $this->assertStringNotContainsString('Proforma Sepeti', $dashboard);
        $this->assertStringNotContainsString('addToCart', $dashboard);
    }

    public function test_stock_critical_utils_only_mark_admin_configured_threshold_rows(): void
    {
        [$exitCode, $output, $error] = $this->runNodeModule(<<<'JS'
            import {
                DEFAULT_STOCK_CATEGORY,
                decorateStockRows,
                filterStockRows,
                sortStockRows,
            } from './resources/js/pages/panel/stock/stockUtils.js';

            const rows = [
                { stok_kodu: 'LOW-NO-SETTING', stok_adi: 'Düşük ama ayarsız', kategori: 'AKILLI KİLİT', toplam_miktar: 1 },
                { stok_kodu: 'CRIT-1', stok_adi: 'Kritik ürün', kategori: 'AKILLI KİLİT', toplam_miktar: 2 },
                { stok_kodu: 'SAFE-1', stok_adi: 'Güvenli ürün', kategori: 'AKILLI KİLİT', toplam_miktar: 20 },
                { stok_kodu: 'ZERO-1', stok_adi: 'Sıfır stok', kategori: 'AKILLI KİLİT', toplam_miktar: 0 },
            ];
            const settings = [
                { stock_code: 'CRIT-1', threshold_quantity: 5, active: true },
                { stock_code: 'SAFE-1', threshold_quantity: 5, active: true },
            ];
            const decorated = decorateStockRows(rows, settings);
            const sorted = sortStockRows(decorated);

            console.log(JSON.stringify({
                defaultCategory: DEFAULT_STOCK_CATEGORY,
                criticalCodes: filterStockRows(decorated, { mode: 'critical', category: 'AKILLI KİLİT' }).map((row) => row.stock_code),
                allCodes: filterStockRows(decorated, { mode: 'list', category: 'AKILLI KİLİT' }).map((row) => row.stock_code),
                firstCode: sorted[0].stock_code,
                lowWithoutSettingCritical: decorated.find((row) => row.stock_code === 'LOW-NO-SETTING').isCritical,
                safeWithSettingCritical: decorated.find((row) => row.stock_code === 'SAFE-1').isCritical,
            }));
        JS);

        $this->assertSame(0, $exitCode, $error);

        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('AKILLI KİLİT', $results['defaultCategory']);
        $this->assertSame(['CRIT-1'], $results['criticalCodes']);
        $this->assertSame(['LOW-NO-SETTING', 'CRIT-1', 'SAFE-1'], $results['allCodes']);
        $this->assertSame('CRIT-1', $results['firstCode']);
        $this->assertFalse($results['lowWithoutSettingCritical']);
        $this->assertFalse($results['safeWithSettingCritical']);
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
