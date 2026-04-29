<?php

namespace Database\Seeders;

use App\Models\Button;
use App\Models\DataSource;
use App\Models\MenuGroup;
use App\Models\Page;
use App\Models\PageConfig;
use App\Models\PageMenu;
use App\Models\Resource;
use App\Models\Role;
use App\Models\RoleResourcePermission;
use App\Models\User;
use App\Models\UserAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PanelMetadataSeeder extends Seeder
{
    public function run(): void
    {
        $roles = collect([
            ['code' => 'admin', 'name' => 'Yönetici', 'description' => 'Tam yetkili sistem yöneticisi', 'is_super_admin' => true],
            ['code' => 'manager', 'name' => 'Yönetici', 'description' => 'Yönetim görünümü ve geniş panel yetkisi', 'is_super_admin' => false],
            ['code' => 'sales', 'name' => 'Satış', 'description' => 'Satış ekip erişimi', 'is_super_admin' => false],
            ['code' => 'stock', 'name' => 'Stok', 'description' => 'Stok ekip erişimi', 'is_super_admin' => false],
        ])->mapWithKeys(fn (array $role) => [
            $role['code'] => Role::query()->updateOrCreate(['code' => $role['code']], $role),
        ]);

        $resources = collect([
            ['code' => 'dashboard', 'name' => 'Yönetim Paneli', 'type' => 'page'],
            ['code' => 'sales_main', 'name' => 'Ana Satış', 'type' => 'page'],
            ['code' => 'sales_main_all', 'name' => 'Tüm Satış Kapsamı', 'type' => 'scope'],
            ['code' => 'sales_online', 'name' => 'Online / Perakende', 'type' => 'page'],
            ['code' => 'sales_bayi', 'name' => 'Bayi / Proje', 'type' => 'page'],
            ['code' => 'stock', 'name' => 'Stok', 'type' => 'page'],
            ['code' => 'finance_cari_durum', 'name' => 'Müşteri Durumu', 'type' => 'page'],
            ['code' => 'orders', 'name' => 'Siparişler', 'type' => 'page'],
            ['code' => 'admin_panel', 'name' => 'Yönetim Modülü', 'type' => 'page'],
            ['code' => 'admin_users', 'name' => 'Kullanıcı Yönetimi', 'type' => 'page'],
            ['code' => 'admin_pages', 'name' => 'Sayfalar', 'type' => 'page'],
            ['code' => 'admin_datasources', 'name' => 'Veri Kaynakları', 'type' => 'page'],
            ['code' => 'admin_logs', 'name' => 'Sistem Kayıtları', 'type' => 'page'],
            ['code' => 'sales_main_dashboard', 'name' => 'Satış Ana Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'stock_dashboard', 'name' => 'Stok Dashboard Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'sales_bayi_proje_detail', 'name' => 'Bayi Proje Detay Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'sales_online_perakende_detail', 'name' => 'Online Perakende Detay Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'orders_dashboard', 'name' => 'Siparişler Veri Kaynağı', 'type' => 'data_source'],
        ])->mapWithKeys(fn (array $resource) => [
            $resource['code'] => Resource::query()->updateOrCreate(['code' => $resource['code']], $resource),
        ]);

        $groups = collect([
            ['code' => 'executive', 'name' => 'Yönetim', 'icon' => 'layout-grid', 'menu_order' => 10, 'active' => true],
            ['code' => 'sales', 'name' => 'Satış', 'icon' => 'chart-column', 'menu_order' => 20, 'active' => true],
            ['code' => 'operations', 'name' => 'Operasyonlar', 'icon' => 'boxes', 'menu_order' => 30, 'active' => true],
            ['code' => 'administration', 'name' => 'Yönetim', 'icon' => 'shield', 'menu_order' => 40, 'active' => true],
        ])->mapWithKeys(fn (array $group) => [
            $group['code'] => MenuGroup::query()->updateOrCreate(['code' => $group['code']], $group),
        ]);

        $pages = collect([
            ['code' => 'dashboard', 'name' => 'Yönetim Paneli', 'route' => '/dashboard', 'component' => 'panel/page', 'icon' => 'layout-grid', 'description' => 'Genel yönetim özeti', 'resource_code' => 'dashboard', 'page_order' => 10, 'active' => true],
            ['code' => 'sales_main', 'name' => 'Ana Satış', 'route' => '/sales/main', 'component' => 'panel/sales-main', 'icon' => 'chart-column', 'description' => 'Ana satış paneli', 'resource_code' => 'sales_main', 'page_order' => 20, 'active' => true],
            ['code' => 'sales_online', 'name' => 'Online / Perakende', 'route' => '/sales/online', 'component' => 'panel/page', 'icon' => 'signal', 'description' => 'Online ve perakende satış görünümü', 'resource_code' => 'sales_online', 'page_order' => 30, 'active' => true],
            ['code' => 'sales_bayi', 'name' => 'Bayi / Proje', 'route' => '/sales/bayi', 'component' => 'panel/page', 'icon' => 'store', 'description' => 'Bayi ve proje satış görünümü', 'resource_code' => 'sales_bayi', 'page_order' => 40, 'active' => true],
            ['code' => 'stock', 'name' => 'Stok', 'route' => '/stock', 'component' => 'panel/page', 'icon' => 'boxes', 'description' => 'Stok yönetim görünümü', 'resource_code' => 'stock', 'page_order' => 50, 'active' => true],
            ['code' => 'finance_cari_durum', 'name' => 'Müşteri Durumu', 'route' => '/finance/cari-durum', 'component' => 'panel/page', 'icon' => 'wallet', 'description' => 'Müşteri durumu ve finans görünümü', 'resource_code' => 'finance_cari_durum', 'page_order' => 60, 'active' => true],
            ['code' => 'orders', 'name' => 'Siparişler', 'route' => '/orders', 'component' => 'panel/page', 'icon' => 'shopping-cart', 'description' => 'Sipariş operasyonları', 'resource_code' => 'orders', 'page_order' => 70, 'active' => true],
            ['code' => 'admin_panel', 'name' => 'Yönetim Modülü', 'route' => '/admin', 'component' => 'panel/admin/index', 'icon' => 'shield', 'description' => 'Panel yönetim merkezi', 'resource_code' => 'admin_panel', 'page_order' => 80, 'active' => true],
            ['code' => 'admin_users', 'name' => 'Kullanıcı Yönetimi', 'route' => '/admin/users', 'component' => 'panel/admin/users', 'icon' => 'users', 'description' => 'Kullanıcı, rol ve erişim yönetimi', 'resource_code' => 'admin_users', 'page_order' => 81, 'active' => true],
            ['code' => 'admin_pages', 'name' => 'Sayfalar', 'route' => '/admin/pages', 'component' => 'panel/admin/pages', 'icon' => 'panel-left', 'description' => 'Menü, rota ve sayfa ayarları', 'resource_code' => 'admin_pages', 'page_order' => 82, 'active' => true],
            ['code' => 'admin_datasources', 'name' => 'Veri Kaynakları', 'route' => '/admin/datasources', 'component' => 'panel/admin/datasources', 'icon' => 'database', 'description' => 'MSSQL ve PostgreSQL veri kaynağı ayarları', 'resource_code' => 'admin_datasources', 'page_order' => 83, 'active' => true],
            ['code' => 'admin_logs', 'name' => 'Sistem Kayıtları', 'route' => '/admin/logs', 'component' => 'panel/admin/logs', 'icon' => 'scroll-text', 'description' => 'Aksiyon ve denetim kayıtları', 'resource_code' => 'admin_logs', 'page_order' => 84, 'active' => true],
        ])->mapWithKeys(fn (array $page) => [
            $page['code'] => Page::query()->updateOrCreate(['code' => $page['code']], $page),
        ]);

        $menuItems = [
            ['menu_group' => 'executive', 'page' => 'dashboard', 'label' => 'Yönetim Paneli', 'icon' => 'layout-grid', 'sort_order' => 10],
            ['menu_group' => 'sales', 'page' => 'sales_main', 'label' => 'Ana Satış', 'icon' => 'chart-column', 'sort_order' => 20],
            ['menu_group' => 'sales', 'page' => 'sales_online', 'label' => 'Online / Perakende', 'icon' => 'signal', 'sort_order' => 30],
            ['menu_group' => 'sales', 'page' => 'sales_bayi', 'label' => 'Bayi / Proje', 'icon' => 'store', 'sort_order' => 40],
            ['menu_group' => 'operations', 'page' => 'stock', 'label' => 'Stok', 'icon' => 'boxes', 'sort_order' => 50],
            ['menu_group' => 'operations', 'page' => 'orders', 'label' => 'Siparişler', 'icon' => 'shopping-cart', 'sort_order' => 60],
            ['menu_group' => 'executive', 'page' => 'finance_cari_durum', 'label' => 'Müşteri Durumu', 'icon' => 'wallet', 'sort_order' => 70],
            ['menu_group' => 'administration', 'page' => 'admin_panel', 'label' => 'Yönetim', 'icon' => 'shield', 'sort_order' => 80],
            ['menu_group' => 'administration', 'page' => 'admin_users', 'label' => 'Kullanıcı Yönetimi', 'icon' => 'users', 'sort_order' => 81],
            ['menu_group' => 'administration', 'page' => 'admin_pages', 'label' => 'Sayfalar', 'icon' => 'panel-left', 'sort_order' => 82],
            ['menu_group' => 'administration', 'page' => 'admin_datasources', 'label' => 'Veri Kaynakları', 'icon' => 'database', 'sort_order' => 83],
            ['menu_group' => 'administration', 'page' => 'admin_logs', 'label' => 'Sistem Kayıtları', 'icon' => 'scroll-text', 'sort_order' => 84],
        ];

        foreach ($menuItems as $item) {
            PageMenu::query()->updateOrCreate(
                [
                    'menu_group_id' => $groups[$item['menu_group']]->id,
                    'page_id' => $pages[$item['page']]->id,
                ],
                [
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                    'sort_order' => $item['sort_order'],
                    'is_visible' => true,
                ],
            );
        }

        $buttons = [
            ['page' => 'admin_panel', 'resource_code' => 'admin_users', 'label' => 'Kullanıcılara Git', 'code' => 'admin_panel_users', 'variant' => 'primary', 'action_type' => 'navigate', 'action_target' => '/admin/users', 'sort_order' => 10],
            ['page' => 'admin_panel', 'resource_code' => 'admin_datasources', 'label' => 'Veri Kaynaklarına Git', 'code' => 'admin_panel_datasources', 'variant' => 'secondary', 'action_type' => 'navigate', 'action_target' => '/admin/datasources', 'sort_order' => 20],
        ];

        foreach ($buttons as $button) {
            Button::query()->updateOrCreate(
                ['code' => $button['code']],
                [
                    'page_id' => $pages[$button['page']]->id,
                    'resource_code' => $button['resource_code'],
                    'label' => $button['label'],
                    'variant' => $button['variant'],
                    'action_type' => $button['action_type'],
                    'action_target' => $button['action_target'],
                    'sort_order' => $button['sort_order'],
                    'is_visible' => true,
                ],
            );
        }

        $dataSource = DataSource::query()->updateOrCreate(
            ['code' => 'sales_main_dashboard'],
            [
                'name' => 'Satış Ana Dashboard',
                'db_type' => 'mssql',
                'query_template' => <<<'SQL'
DECLARE @date_from DATE = '{{date_from}}';
DECLARE @date_to DATE = '{{date_to}}';
DECLARE @detail_type NVARCHAR(10) = '{{detail_type}}';
DECLARE @rep_code NVARCHAR(20) = '{{rep_code}}';
-- Query template metadata panel.data_sources uzerinden okunur.
-- Gerçek MSSQL executor bilerek bağlanmamıştır.
SQL,
                'allowed_params' => ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code'],
                'connection_meta' => [
                    'target' => 'sales.main',
                    'database' => 'LOGO',
                    'host' => 'mssql.internal',
                ],
                'preview_payload' => [
                    'cari' => [
                        ['satir_tipi' => 'GRUP', 'cari_grup_adi' => 'PERAKENDE', 'adet' => 182, 'ciro' => 1842500.50, 'siralama_1' => 1, 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'CARI', 'cari_grup_adi' => 'PERAKENDE', 'cari_kodu' => 'CR-1001', 'satir_adi' => 'Ata Home', 'adet' => 96, 'ciro' => 925400.75, 'siralama_1' => 1, 'siralama_2' => 1, 'parent_key' => 'CR-1001', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'URUN', 'cari_grup_adi' => 'PERAKENDE', 'cari_kodu' => 'CR-1001', 'satir_adi' => 'Luna Koltuk', 'adet' => 41, 'ciro' => 451200.00, 'siralama_1' => 1, 'siralama_2' => 1, 'parent_key' => 'CR-1001', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'URUN', 'cari_grup_adi' => 'PERAKENDE', 'cari_kodu' => 'CR-1001', 'satir_adi' => 'Mira Yemek', 'adet' => 55, 'ciro' => 474200.75, 'siralama_1' => 1, 'siralama_2' => 2, 'parent_key' => 'CR-1001', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'CARI', 'cari_grup_adi' => 'PERAKENDE', 'cari_kodu' => 'CR-1002', 'satir_adi' => 'Nova Living', 'adet' => 86, 'ciro' => 917099.75, 'siralama_1' => 1, 'siralama_2' => 2, 'parent_key' => 'CR-1002', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'URUN', 'cari_grup_adi' => 'PERAKENDE', 'cari_kodu' => 'CR-1002', 'satir_adi' => 'Softline Baza', 'adet' => 48, 'ciro' => 517100.00, 'siralama_1' => 1, 'siralama_2' => 1, 'parent_key' => 'CR-1002', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'URUN', 'cari_grup_adi' => 'PERAKENDE', 'cari_kodu' => 'CR-1002', 'satir_adi' => 'Aura Konsol', 'adet' => 38, 'ciro' => 399999.75, 'siralama_1' => 1, 'siralama_2' => 2, 'parent_key' => 'CR-1002', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'GRUP', 'cari_grup_adi' => 'PROJE', 'adet' => 74, 'ciro' => 1398700.00, 'siralama_1' => 2, 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'CARI', 'cari_grup_adi' => 'PROJE', 'cari_kodu' => 'CR-2001', 'satir_adi' => 'Zen Contract', 'adet' => 42, 'ciro' => 804300.00, 'siralama_1' => 2, 'siralama_2' => 1, 'parent_key' => 'CR-2001', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'URUN', 'cari_grup_adi' => 'PROJE', 'cari_kodu' => 'CR-2001', 'satir_adi' => 'Atlas Suite', 'adet' => 42, 'ciro' => 804300.00, 'siralama_1' => 2, 'siralama_2' => 1, 'parent_key' => 'CR-2001', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'CARI', 'cari_grup_adi' => 'PROJE', 'cari_kodu' => 'CR-2004', 'satir_adi' => 'Mavi Residence', 'adet' => 32, 'ciro' => 594400.00, 'siralama_1' => 2, 'siralama_2' => 2, 'parent_key' => 'CR-2004', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'URUN', 'cari_grup_adi' => 'PROJE', 'cari_kodu' => 'CR-2004', 'satir_adi' => 'Linea Base', 'adet' => 32, 'ciro' => 594400.00, 'siralama_1' => 2, 'siralama_2' => 1, 'parent_key' => 'CR-2004', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'GRUP', 'cari_grup_adi' => 'IADE', 'adet' => -12, 'ciro' => -142250.00, 'siralama_1' => 3, 'konsinye_tutari' => 128500.00],
                    ],
                    'urun' => [
                        ['satir_tipi' => 'GRUP', 'cari_grup_adi' => 'Luna Koltuk', 'adet' => 58, 'ciro' => 981250.00, 'siralama_1' => 1, 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'DETAY', 'cari_grup_adi' => 'Luna Koltuk', 'satir_adi' => 'Ata Home', 'adet' => 31, 'ciro' => 521200.00, 'siralama_1' => 1, 'siralama_2' => 1, 'parent_key' => 'Luna Koltuk', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'DETAY', 'cari_grup_adi' => 'Luna Koltuk', 'satir_adi' => 'Zen Contract', 'adet' => 27, 'ciro' => 460050.00, 'siralama_1' => 1, 'siralama_2' => 2, 'parent_key' => 'Luna Koltuk', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'GRUP', 'cari_grup_adi' => 'Atlas Suite', 'adet' => 42, 'ciro' => 804300.00, 'siralama_1' => 2, 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'DETAY', 'cari_grup_adi' => 'Atlas Suite', 'satir_adi' => 'Zen Contract', 'adet' => 42, 'ciro' => 804300.00, 'siralama_1' => 2, 'siralama_2' => 1, 'parent_key' => 'Atlas Suite', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'GRUP', 'cari_grup_adi' => 'Softline Baza', 'adet' => 48, 'ciro' => 517100.00, 'siralama_1' => 3, 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'DETAY', 'cari_grup_adi' => 'Softline Baza', 'satir_adi' => 'Nova Living', 'adet' => 48, 'ciro' => 517100.00, 'siralama_1' => 3, 'siralama_2' => 1, 'parent_key' => 'Softline Baza', 'konsinye_tutari' => 128500.00],
                        ['satir_tipi' => 'GRUP', 'cari_grup_adi' => 'IADE', 'adet' => -12, 'ciro' => -142250.00, 'siralama_1' => 4, 'konsinye_tutari' => 128500.00],
                    ],
                ],
                'active' => true,
                'sort_order' => 10,
                'description' => 'Satış ana sayfa için veri kaynağı tanımı',
            ],
        );

        PageConfig::query()->updateOrCreate(
            ['page_code' => 'sales_main'],
            [
                'layout_json' => [
                    'heroEyebrow' => 'Satış komutu',
                'topNav' => [
                        ['key' => 'sales', 'label' => 'Satış Yönetimi', 'href' => '/sales/main'],
                        ['key' => 'stock', 'label' => 'Stok Yönetimi', 'href' => '/stock'],
                        ['key' => 'orders', 'label' => 'Sipariş Yönetimi', 'href' => '/orders'],
                    ],
                ],
                'filters_json' => [
                    'defaults' => ['grain' => 'week', 'detailType' => 'cari', 'scopeKey' => 'all'],
                    'grains' => [
                        ['key' => 'day', 'label' => 'Günlük'],
                        ['key' => 'week', 'label' => 'Haftalık'],
                        ['key' => 'month', 'label' => 'Aylık'],
                        ['key' => 'year', 'label' => 'Yıllık'],
                    ],
                    'detailModes' => [
                        ['key' => 'cari', 'label' => 'Müşteri Satış Detayı'],
                        ['key' => 'urun', 'label' => 'Ürün Satış Detayı'],
                    ],
                    'managementScopes' => [
                        ['key' => 'all', 'label' => 'Tümü', 'repCode' => null, 'allowAll' => true, 'salesView' => 'tümü', 'note' => 'Tüm satışlar'],
                        ['key' => 'umit', 'label' => 'Ümit Yıldız', 'repCode' => '0003', 'allowAll' => false, 'salesView' => 'kendi', 'note' => 'Temsilci kodu 0003'],
                    ['key' => 'salih', 'label' => 'Salih İmal', 'repCode' => '0024', 'allowAll' => false, 'salesView' => 'kendi', 'note' => 'Temsilci kodu 0024'],
                        ['key' => 'online-perakende', 'label' => 'Online / Perakende', 'repCode' => null, 'allowAll' => false, 'salesView' => 'kendi', 'navigateTo' => '/sales/online', 'note' => 'Online satış iş akışı görünümü'],
                        ['key' => 'bayi-proje', 'label' => 'Bayi / Proje', 'repCode' => null, 'allowAll' => false, 'salesView' => 'kendi', 'navigateTo' => '/sales/bayi', 'note' => 'Bayi satış iş akışı görünümü'],
                    ],
                ],
                'datasource_id' => $dataSource->id,
            ],
        );

        foreach ([
            [
                'page_code' => 'stock',
                'code' => 'stock_dashboard',
                'name' => 'Twenty Stok Dashboard',
                'workflow' => 'Twenty - Stok Dashboard - Corrected v2.json',
                'target' => 'stock.dashboard',
            ],
            [
                'page_code' => 'sales_bayi',
                'code' => 'sales_bayi_proje_detail',
                'name' => 'Bayi Proje Detayı',
                'workflow' => 'SALES_BAYI_PROJE_DETAY_V1.json',
                'target' => 'sales.bayi_proje',
            ],
            [
                'page_code' => 'sales_online',
                'code' => 'sales_online_perakende_detail',
                'name' => 'Online Perakende Detayı',
                'workflow' => 'SALES_ONLINE_PERAKENDE_DETAY_V1.json',
                'target' => 'sales.online_perakende',
            ],
            [
                'page_code' => 'orders',
                'code' => 'orders_dashboard',
                'name' => 'Emaks Prime Siparisler',
                'workflow' => 'EMAKS PRIME - Siparisler Workflow (TAM FIX).json',
                'target' => 'orders.dashboard',
            ],
        ] as $metadataSource) {
            $source = DataSource::query()->updateOrCreate(
                ['code' => $metadataSource['code']],
                [
                    'name' => $metadataSource['name'],
                    'db_type' => 'mssql',
                    'query_template' => '-- Sorgu şablonu bu iş akışı referansından admin panelde yönetilecek: '.$metadataSource['workflow'],
                    'allowed_params' => ['date_from', 'date_to', 'grain', 'scope_key', 'rep_code'],
                    'connection_meta' => [
                        'target' => $metadataSource['target'],
                        'reference_workflow' => $metadataSource['workflow'],
                    ],
                    'preview_payload' => [],
                    'active' => true,
                    'sort_order' => 20,
                    'description' => 'n8n iş akışı referansı Laravel veri kaynağı kaydına taşındı.',
                ],
            );

            PageConfig::query()->updateOrCreate(
                ['page_code' => $metadataSource['page_code']],
                [
                    'layout_json' => ['heroEyebrow' => 'Workflow migrated module'],
                    'filters_json' => [
                        'defaults' => ['grain' => 'week', 'scopeKey' => 'all'],
                        'grains' => [
                            ['key' => 'day', 'label' => 'Günlük'],
                            ['key' => 'week', 'label' => 'Haftalık'],
                            ['key' => 'month', 'label' => 'Aylık'],
                            ['key' => 'year', 'label' => 'Yıllık'],
                        ],
                    ],
                    'datasource_id' => $source->id,
                ],
            );
        }

        foreach (['admin_panel', 'admin_users', 'admin_pages', 'admin_datasources', 'admin_logs'] as $pageCode) {
            PageConfig::query()->updateOrCreate(
                ['page_code' => $pageCode],
                [
                    'layout_json' => ['heroEyebrow' => 'Admin module'],
                    'filters_json' => [],
                    'datasource_id' => null,
                ],
            );
        }

        foreach ($resources as $resource) {
            foreach ($roles as $role) {
                RoleResourcePermission::query()->updateOrCreate(
                    [
                        'role_code' => $role->code,
                        'resource_code' => $resource->code,
                    ],
                    [
                'can_view' => match ($role->code) {
                    'admin' => true,
                    'manager' => true,
                    'sales' => in_array($resource->code, ['dashboard', 'sales_main', 'sales_online', 'sales_bayi'], true),
                    'stock' => in_array($resource->code, ['dashboard', 'stock', 'orders'], true),
                    default => false,
                },
                'can_execute' => $role->code === 'admin',
                    ],
                );
            }
        }

        $adminUsername = env('PANEL_BOOTSTRAP_ADMIN_USERNAME');
        $adminPassword = env('PANEL_BOOTSTRAP_ADMIN_PASSWORD');
        $adminName = env('PANEL_BOOTSTRAP_ADMIN_NAME', 'Panel Administrator');

        if ($adminUsername && $adminPassword) {
            $adminUser = User::query()->updateOrCreate(
                ['username' => $adminUsername],
                [
                    'full_name' => $adminName,
                    'password_hash' => Hash::make($adminPassword),
                    'role_code' => 'admin',
                    'temsilci_kodu' => env('PANEL_BOOTSTRAP_ADMIN_REP_CODE', '0003'),
                    'aktif' => true,
                ],
            );

            collect($resources->keys())->each(function (string $resourceCode) use ($adminUser): void {
                UserAccess::query()->updateOrCreate(
                    ['user_id' => $adminUser->id, 'resource_code' => $resourceCode],
                    [],
                );
            });
        }
    }
}
