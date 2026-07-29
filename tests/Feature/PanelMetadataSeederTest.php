<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\Page;
use App\Models\PageConfig;
use App\Models\Resource;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelMetadataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_metadata_seed_creates_expected_pages_and_data_sources(): void
    {
        $this->assertDatabaseHas('panel.pages', [
            'code' => 'sales_main',
            'route' => '/sales/main',
            'layout_type' => 'module',
        ]);

        $this->assertDatabaseHas('panel.pages', [
            'code' => 'admin_datasources',
            'route' => '/admin/datasources',
            'layout_type' => 'admin',
        ]);

        $this->assertDatabaseHas('panel.pages', [
            'code' => 'cari_bilgi',
            'route' => '/finance/cari-bilgi',
            'component' => 'panel/cari-bilgi',
        ]);

        $this->assertDatabaseHas('panel.data_sources', [
            'code' => 'sales_main_dashboard',
            'db_type' => 'n8n_json',
            'active' => true,
        ]);

        $this->assertDatabaseHas('panel.data_sources', [
            'code' => 'cari_bilgi_dashboard',
            'db_type' => 'n8n_json',
            'active' => true,
        ]);

        $this->assertDatabaseHas('panel.pages', [
            'code' => 'sales_representatives',
            'active' => false,
        ]);

        $this->assertGreaterThanOrEqual(20, Page::query()->count());
        $this->assertGreaterThanOrEqual(10, DataSource::query()->count());
    }

    public function test_sales_representative_scope_metadata_is_current_and_idempotent(): void
    {
        Resource::query()->updateOrCreate(
            ['code' => 'sales_rep_salih_cakir'],
            ['name' => 'Salih Satış Kapsamı', 'type' => 'scope', 'active' => true],
        );

        $this->seed(PanelMetadataSeeder::class);
        $this->seed(PanelMetadataSeeder::class);

        $config = PageConfig::query()->where('page_code', 'sales_main')->firstOrFail();
        $scopes = collect($config->filters_json['managementScopes'] ?? []);

        $this->assertSame(
            ['all', 'umit', 'bulent_saglam', 'mehmet_can', 'orkun_genc', 'online-perakende', 'bayi-proje'],
            $scopes->pluck('key')->all(),
        );
        $this->assertSame($scopes->pluck('key')->unique()->count(), $scopes->count());

        $this->assertSame('0003', $scopes->firstWhere('key', 'umit')['repCode'] ?? null);
        $this->assertSame('0035', $scopes->firstWhere('key', 'bulent_saglam')['repCode'] ?? null);
        $this->assertSame('0039', $scopes->firstWhere('key', 'mehmet_can')['repCode'] ?? null);
        $this->assertSame('0040', $scopes->firstWhere('key', 'orkun_genc')['repCode'] ?? null);
        $this->assertNull($scopes->firstWhere('key', 'salih'));
        $this->assertFalse($scopes->contains(fn (array $scope): bool => ($scope['repCode'] ?? null) === '0024'));
        $this->assertFalse($scopes->contains(fn (array $scope): bool => ($scope['resourceCode'] ?? null) === 'sales_rep_salih_cakir'));

        foreach ([
            'sales_rep_umit_yildiz',
            'sales_rep_bulent_saglam',
            'sales_rep_mehmet_can',
            'sales_rep_orkun_genc',
        ] as $resourceCode) {
            $this->assertDatabaseHas('panel.resources', [
                'code' => $resourceCode,
                'active' => true,
            ]);
        }

        $this->assertDatabaseHas('panel.resources', [
            'code' => 'sales_rep_salih_cakir',
            'active' => false,
        ]);
    }
}
