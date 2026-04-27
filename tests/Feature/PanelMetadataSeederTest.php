<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\Page;
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

        $this->assertGreaterThanOrEqual(20, Page::query()->count());
        $this->assertGreaterThanOrEqual(10, DataSource::query()->count());
    }
}
