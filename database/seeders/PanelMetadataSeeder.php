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
            ['code' => 'admin', 'name' => 'Admin', 'description' => 'Tam yetkili sistem yöneticisi', 'is_super_admin' => true],
            ['code' => 'manager', 'name' => 'Yönetici', 'description' => 'Yönetim görünümü ve geniş panel yetkisi', 'is_super_admin' => false],
            ['code' => 'sales', 'name' => 'Satış', 'description' => 'Satış ekip erişimi', 'is_super_admin' => false],
            ['code' => 'stock', 'name' => 'Stok', 'description' => 'Stok ekip erişimi', 'is_super_admin' => false],
            ['code' => 'orders', 'name' => 'Sipariş', 'description' => 'Sipariş ekip erişimi', 'is_super_admin' => false],
            ['code' => 'customer', 'name' => 'Müşteri', 'description' => 'Müşteri/CRM ekip erişimi', 'is_super_admin' => false],
            ['code' => 'proforma', 'name' => 'Proforma', 'description' => 'Proforma operasyon erişimi', 'is_super_admin' => false],
            ['code' => 'viewer', 'name' => 'Görüntüleyici', 'description' => 'Sadece atanmış kaynakları görüntüler', 'is_super_admin' => false],
        ])->mapWithKeys(fn (array $role) => [
            $role['code'] => Role::query()->updateOrCreate(['code' => $role['code']], $role),
        ]);

        $resources = collect([
            ['code' => 'dashboard', 'name' => 'Yönetim Özeti', 'type' => 'page'],
            ['code' => 'sales_main', 'name' => 'Genel Satış', 'type' => 'page'],
            ['code' => 'sales_main_all', 'name' => 'Satış Yönetimi Tüm Kapsamlar', 'type' => 'scope'],
            ['code' => 'sales_online', 'name' => 'Online / Perakende', 'type' => 'page'],
            ['code' => 'sales_bayi', 'name' => 'Bayi / Proje', 'type' => 'page'],
            ['code' => 'sales_representatives', 'name' => 'Satış Temsilcisi Görünümü', 'type' => 'page'],
            ['code' => 'stock', 'name' => 'Stok Listesi', 'type' => 'page'],
            ['code' => 'stock_critical', 'name' => 'Kritik Stoklar', 'type' => 'page'],
            ['code' => 'stock_warehouse', 'name' => 'Depo / Raf Durumu', 'type' => 'page'],
            ['code' => 'finance_cari_durum', 'name' => 'Müşteri Durumu', 'type' => 'page'],
            ['code' => 'orders', 'name' => 'Sipariş Yönetimi', 'type' => 'page'],
            ['code' => 'orders_alinan', 'name' => 'Alınan Siparişler', 'type' => 'page'],
            ['code' => 'orders_verilen', 'name' => 'Verilen Siparişler', 'type' => 'page'],
            ['code' => 'cari', 'name' => 'Müşteri Listesi', 'type' => 'page'],
            ['code' => 'cari_balance', 'name' => 'Müşteri Bakiyesi', 'type' => 'page'],
            ['code' => 'cari_detail', 'name' => 'Müşteri Detayı ve Ekstre', 'type' => 'page'],
            ['code' => 'proforma', 'name' => 'Proforma Liste', 'type' => 'page'],
            ['code' => 'proforma_create', 'name' => 'Proforma Oluştur', 'type' => 'page'],
            ['code' => 'proforma_detail', 'name' => 'Proforma Detay', 'type' => 'page'],
            ['code' => 'proforma_edit', 'name' => 'Proforma Düzenle', 'type' => 'page'],
            ['code' => 'admin_panel', 'name' => 'Yönetim Paneli', 'type' => 'page'],
            ['code' => 'admin_users', 'name' => 'Kullanıcılar', 'type' => 'page'],
            ['code' => 'admin_pages', 'name' => 'Sayfalar', 'type' => 'page'],
            ['code' => 'admin_datasources', 'name' => 'Veri Kaynakları', 'type' => 'page'],
            ['code' => 'admin_logs', 'name' => 'Loglar', 'type' => 'page'],
            ['code' => 'customers', 'name' => 'Müşteri Yönetimi', 'type' => 'page'],
            ['code' => 'customers_all', 'name' => 'Tüm Müşteriler', 'type' => 'scope'],
            ['code' => 'customers_online', 'name' => 'Online / Perakende Müşterileri', 'type' => 'scope'],
            ['code' => 'customers_bayi', 'name' => 'Bayi / Proje Müşterileri', 'type' => 'scope'],
            ['code' => 'customers_own_rep', 'name' => 'Kendi Temsilci Müşterileri', 'type' => 'scope'],
            ['code' => 'user_admin', 'name' => 'Kullanıcı Yönetimi', 'type' => 'page'],
            ['code' => 'data_sources', 'name' => 'Veri Kaynakları Yönetimi', 'type' => 'page'],
            ['code' => 'sales_main_dashboard', 'name' => 'Satış Yönetimi Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'sales_customer_search', 'name' => 'Satış Müşteri Arama Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'stock_dashboard', 'name' => 'Stok Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'sales_bayi_proje_detail', 'name' => 'Bayi / Proje Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'sales_online_perakende_detail', 'name' => 'Online / Perakende Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'orders_dashboard', 'name' => 'Sipariş Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'orders_alinan', 'name' => 'Alınan Sipariş Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'orders_verilen', 'name' => 'Verilen Sipariş Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'cari_list', 'name' => 'Müşteri Liste Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'cari_statement', 'name' => 'Müşteri Ekstre Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'customers_list', 'name' => 'Müşteri Liste Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'customers_balance', 'name' => 'Müşteri Bakiye Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'customer_detail', 'name' => 'Müşteri Detay Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'customer_documents', 'name' => 'Müşteri Evrak Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'customer_statement', 'name' => 'Müşteri Ekstre Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'proforma_list', 'name' => 'Proforma Liste Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'proforma_detail', 'name' => 'Proforma Detay Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'proforma_draft', 'name' => 'Proforma Taslak Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'proforma_items', 'name' => 'Proforma Satır Veri Kaynağı', 'type' => 'data_source'],
            ['code' => 'proforma_customer_search', 'name' => 'Proforma Müşteri Arama', 'type' => 'data_source'],
            ['code' => 'proforma_stock_search', 'name' => 'Proforma Stok Arama', 'type' => 'data_source'],
            ['code' => 'proforma_price_list', 'name' => 'Proforma Fiyat Listesi', 'type' => 'data_source'],
            ['code' => 'proforma_discount_defs', 'name' => 'Proforma İskonto Tanımları', 'type' => 'data_source'],
        ])->mapWithKeys(fn (array $resource) => [
            $resource['code'] => Resource::query()->updateOrCreate(['code' => $resource['code']], $resource),
        ]);

        $groups = collect([
            ['code' => 'executive', 'name' => 'Yönetim', 'icon' => 'layout-grid', 'menu_order' => 10, 'active' => true],
            ['code' => 'sales', 'name' => 'Satış Yönetimi', 'icon' => 'chart-column', 'menu_order' => 20, 'active' => true],
            ['code' => 'stock', 'name' => 'Stok Yönetimi', 'icon' => 'boxes', 'menu_order' => 30, 'active' => true],
            ['code' => 'orders', 'name' => 'Sipariş Yönetimi', 'icon' => 'shopping-cart', 'menu_order' => 40, 'active' => true],
            ['code' => 'cari', 'name' => 'Müşteri Yönetimi', 'icon' => 'wallet', 'menu_order' => 50, 'active' => true],
            ['code' => 'proforma', 'name' => 'Proforma', 'icon' => 'folder-kanban', 'menu_order' => 60, 'active' => true],
            ['code' => 'administration', 'name' => 'Sistem Yönetimi', 'icon' => 'shield', 'menu_order' => 70, 'active' => true],
        ])->mapWithKeys(fn (array $group) => [
            $group['code'] => MenuGroup::query()->updateOrCreate(['code' => $group['code']], $group),
        ]);

        $pages = collect([
            ['code' => 'dashboard', 'name' => 'Yönetim Özeti', 'route' => '/dashboard', 'component' => 'panel/page', 'layout_type' => 'admin', 'icon' => 'layout-grid', 'description' => 'Genel yönetim ve metadata özet görünümü', 'resource_code' => 'dashboard', 'page_order' => 10, 'active' => true],
            ['code' => 'sales_main', 'name' => 'Genel Satış', 'route' => '/sales/main', 'component' => 'panel/sales-main', 'layout_type' => 'module', 'icon' => 'chart-column', 'description' => 'Ana satış dashboardu ve yönetim kapsamları', 'resource_code' => 'sales_main', 'page_order' => 20, 'active' => true],
            ['code' => 'sales_online', 'name' => 'Online / Perakende', 'route' => '/sales/online', 'component' => 'panel/sales-main', 'layout_type' => 'module', 'icon' => 'signal', 'description' => 'Online ve perakende satış görünümü', 'resource_code' => 'sales_online', 'page_order' => 30, 'active' => true],
            ['code' => 'sales_bayi', 'name' => 'Bayi / Proje', 'route' => '/sales/bayi', 'component' => 'panel/sales-main', 'layout_type' => 'module', 'icon' => 'store', 'description' => 'Bayi ve proje satış görünümü', 'resource_code' => 'sales_bayi', 'page_order' => 40, 'active' => true],
            ['code' => 'sales_representatives', 'name' => 'Satış Temsilcisi Görünümü', 'route' => '/sales/representatives', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'users', 'description' => 'Temsilci bazlı satış kapsamları ana Satış Yönetimi kapsam filtresinden yönetilir.', 'resource_code' => 'sales_representatives', 'page_order' => 50, 'active' => false],
            ['code' => 'stock', 'name' => 'Stok Listesi', 'route' => '/stock', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'boxes', 'description' => 'Stok listesi ve ürün izleme ekranı.', 'resource_code' => 'stock', 'page_order' => 60, 'active' => true],
            ['code' => 'stock_critical', 'name' => 'Kritik Stoklar', 'route' => '/stock/critical', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'boxes', 'description' => 'Kritik stok seviyeleri ve uyarı listesi burada hazırlanır.', 'resource_code' => 'stock_critical', 'page_order' => 61, 'active' => true],
            ['code' => 'stock_warehouse', 'name' => 'Depo / Raf Durumu', 'route' => '/stock/warehouse', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'boxes', 'description' => 'Depo, raf ve lokasyon durumu bu modül altında izlenir.', 'resource_code' => 'stock_warehouse', 'page_order' => 62, 'active' => true],
            ['code' => 'orders', 'name' => 'Sipariş Yönetimi', 'route' => '/orders', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'shopping-cart', 'description' => 'Sipariş operasyonları için genel görünüm.', 'resource_code' => 'orders', 'page_order' => 70, 'active' => true],
            ['code' => 'orders_alinan', 'name' => 'Alınan Siparişler', 'route' => '/orders/alinan', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'shopping-cart', 'description' => 'Müşterilerden alınan siparişler bu ekranda listelenecek.', 'resource_code' => 'orders_alinan', 'page_order' => 71, 'active' => true],
            ['code' => 'orders_verilen', 'name' => 'Verilen Siparişler', 'route' => '/orders/verilen', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'shopping-cart', 'description' => 'Tedarikçi ve üretim tarafına verilen siparişler burada izlenecek.', 'resource_code' => 'orders_verilen', 'page_order' => 72, 'active' => true],
            ['code' => 'cari', 'name' => 'Müşteri Yönetimi', 'route' => '/cari', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'wallet', 'description' => 'Müşteri, bayi ve hesap bilgileri bu modül altında yönetilir.', 'resource_code' => 'customers', 'page_order' => 80, 'active' => true],
            ['code' => 'cari_balance', 'name' => 'Müşteri Bakiyesi', 'route' => '/cari/balance', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'wallet', 'description' => 'Müşteri bakiye izleme ve risk görünümü.', 'resource_code' => 'customers', 'page_order' => 81, 'active' => true],
            ['code' => 'cari_detail', 'name' => 'Müşteri Detayı ve Ekstre', 'route' => '/cari/detail', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'wallet', 'description' => 'Müşteri detay ve ekstre kırılımları.', 'resource_code' => 'customers', 'page_order' => 82, 'active' => true],
            ['code' => 'cari_document_detail', 'name' => 'Evrak Detayı', 'route' => '/cari/document-detail', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'wallet', 'description' => 'Cari ekstre evrak detay ekranı', 'resource_code' => 'customers', 'page_order' => 83, 'active' => true],
            ['code' => 'proforma', 'name' => 'Proforma Liste', 'route' => '/proforma', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'folder-kanban', 'description' => 'Proforma kayıtlarının listeleneceği operasyon ekranı.', 'resource_code' => 'proforma', 'page_order' => 90, 'active' => true],
            ['code' => 'proforma_create', 'name' => 'Proforma Oluştur', 'route' => '/proforma/create', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'folder-kanban', 'description' => 'Yeni proforma taslakları için hazırlık ekranı.', 'resource_code' => 'proforma_create', 'page_order' => 91, 'active' => true],
            ['code' => 'proforma_detail', 'name' => 'Proforma Detay', 'route' => '/proforma/detail', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'folder-kanban', 'description' => 'Proforma detay görüntüleme iskeleti.', 'resource_code' => 'proforma_detail', 'page_order' => 92, 'active' => true],
            ['code' => 'proforma_edit', 'name' => 'Proforma Düzenle', 'route' => '/proforma/edit', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'folder-kanban', 'description' => 'Proforma düzenleme akışı için placeholder ekran.', 'resource_code' => 'proforma_edit', 'page_order' => 93, 'active' => true],
            ['code' => 'finance_cari_durum', 'name' => 'Müşteri Durumu', 'route' => '/finance/cari-durum', 'component' => 'panel/page', 'layout_type' => 'admin', 'icon' => 'wallet', 'description' => 'Müşteri durumu ve finans yönetim görünümü', 'resource_code' => 'finance_cari_durum', 'page_order' => 100, 'active' => true],
            ['code' => 'admin_panel', 'name' => 'Yönetim Paneli', 'route' => '/admin', 'component' => 'panel/admin/index', 'layout_type' => 'admin', 'icon' => 'shield', 'description' => 'Panel yönetim merkezi', 'resource_code' => 'admin_panel', 'page_order' => 110, 'active' => true],
            ['code' => 'admin_users', 'name' => 'Kullanıcılar', 'route' => '/admin/users', 'component' => 'panel/admin/users', 'layout_type' => 'admin', 'icon' => 'users', 'description' => 'Kullanıcı, rol ve erişim yönetimi', 'resource_code' => 'user_admin', 'page_order' => 111, 'active' => true],
            ['code' => 'admin_pages', 'name' => 'Sayfalar', 'route' => '/admin/pages', 'component' => 'panel/admin/pages', 'layout_type' => 'admin', 'icon' => 'panel-left', 'description' => 'Menü, route ve sayfa konfigürasyonu', 'resource_code' => 'admin_pages', 'page_order' => 112, 'active' => true],
            ['code' => 'admin_datasources', 'name' => 'Veri Kaynakları', 'route' => '/admin/datasources', 'component' => 'panel/admin/datasources', 'layout_type' => 'admin', 'icon' => 'database', 'description' => 'MSSQL, Postgres ve workflow metadata yönetimi', 'resource_code' => 'data_sources', 'page_order' => 113, 'active' => true],
            ['code' => 'admin_logs', 'name' => 'Loglar', 'route' => '/admin/logs', 'component' => 'panel/admin/logs', 'layout_type' => 'admin', 'icon' => 'scroll-text', 'description' => 'Aksiyon ve audit log kayıtları', 'resource_code' => 'admin_logs', 'page_order' => 114, 'active' => true],
        ])->mapWithKeys(fn (array $page) => [
            $page['code'] => Page::query()->updateOrCreate(['code' => $page['code']], $page),
        ]);

        $menuItems = [
            ['menu_group' => 'executive', 'page' => 'dashboard', 'label' => 'Yönetim Özeti', 'icon' => 'layout-grid', 'sort_order' => 10],
            ['menu_group' => 'sales', 'page' => 'sales_main', 'label' => 'Genel Satış', 'icon' => 'chart-column', 'sort_order' => 20],
            ['menu_group' => 'sales', 'page' => 'sales_online', 'label' => 'Online / Perakende', 'icon' => 'signal', 'sort_order' => 30],
            ['menu_group' => 'sales', 'page' => 'sales_bayi', 'label' => 'Bayi / Proje', 'icon' => 'store', 'sort_order' => 40],
            ['menu_group' => 'stock', 'page' => 'stock', 'label' => 'Stok Listesi', 'icon' => 'boxes', 'sort_order' => 60],
            ['menu_group' => 'stock', 'page' => 'stock_critical', 'label' => 'Kritik Stoklar', 'icon' => 'boxes', 'sort_order' => 61],
            ['menu_group' => 'stock', 'page' => 'stock_warehouse', 'label' => 'Depo / Raf Durumu', 'icon' => 'boxes', 'sort_order' => 62, 'is_visible' => false],
            ['menu_group' => 'orders', 'page' => 'orders', 'label' => 'Sipariş Yönetimi', 'icon' => 'shopping-cart', 'sort_order' => 70],
            ['menu_group' => 'orders', 'page' => 'orders_alinan', 'label' => 'Alınan Siparişler', 'icon' => 'shopping-cart', 'sort_order' => 71],
            ['menu_group' => 'orders', 'page' => 'orders_verilen', 'label' => 'Verilen Siparişler', 'icon' => 'shopping-cart', 'sort_order' => 72],
            ['menu_group' => 'cari', 'page' => 'cari', 'label' => 'Müşteri Listesi', 'icon' => 'wallet', 'sort_order' => 80],
            ['menu_group' => 'cari', 'page' => 'cari_balance', 'label' => 'Müşteri Bakiyesi', 'icon' => 'wallet', 'sort_order' => 81],
            ['menu_group' => 'cari', 'page' => 'cari_detail', 'label' => 'Müşteri Detay / Ekstre', 'icon' => 'wallet', 'sort_order' => 82, 'is_visible' => false],
            ['menu_group' => 'cari', 'page' => 'cari_document_detail', 'label' => 'Evrak Detayı', 'icon' => 'wallet', 'sort_order' => 83, 'is_visible' => false],
            ['menu_group' => 'proforma', 'page' => 'proforma', 'label' => 'Proforma Liste', 'icon' => 'folder-kanban', 'sort_order' => 90],
            ['menu_group' => 'proforma', 'page' => 'proforma_create', 'label' => 'Proforma Oluştur', 'icon' => 'folder-kanban', 'sort_order' => 91],
            ['menu_group' => 'proforma', 'page' => 'proforma_detail', 'label' => 'Proforma Detay', 'icon' => 'folder-kanban', 'sort_order' => 92],
            ['menu_group' => 'proforma', 'page' => 'proforma_edit', 'label' => 'Proforma Düzenle', 'icon' => 'folder-kanban', 'sort_order' => 93],
            ['menu_group' => 'executive', 'page' => 'finance_cari_durum', 'label' => 'Müşteri Durumu', 'icon' => 'wallet', 'sort_order' => 100],
            ['menu_group' => 'administration', 'page' => 'admin_panel', 'label' => 'Yönetim Paneli', 'icon' => 'shield', 'sort_order' => 110],
            ['menu_group' => 'administration', 'page' => 'admin_users', 'label' => 'Kullanıcılar', 'icon' => 'users', 'sort_order' => 111],
            ['menu_group' => 'administration', 'page' => 'admin_pages', 'label' => 'Sayfalar / Butonlar', 'icon' => 'panel-left', 'sort_order' => 112],
            ['menu_group' => 'administration', 'page' => 'admin_datasources', 'label' => 'Veri Kaynakları', 'icon' => 'database', 'sort_order' => 113],
            ['menu_group' => 'administration', 'page' => 'admin_logs', 'label' => 'Loglar', 'icon' => 'scroll-text', 'sort_order' => 114],
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
                    'is_visible' => $item['is_visible'] ?? true,
                ],
            );
        }

        Page::query()->where('code', 'sales_representatives')->update(['active' => false]);
        PageMenu::query()
            ->where('page_id', $pages['sales_representatives']->id)
            ->update(['is_visible' => false]);

        $buttons = [
            ['page' => 'admin_panel', 'resource_code' => 'user_admin', 'label' => 'Kullanıcılara Git', 'code' => 'admin_panel_users', 'variant' => 'primary', 'action_type' => 'navigate', 'action_target' => '/admin/users', 'position' => 'page_top', 'sort_order' => 10],
            ['page' => 'admin_panel', 'resource_code' => 'data_sources', 'label' => 'Veri Kaynaklarını Yönet', 'code' => 'admin_panel_datasources', 'variant' => 'secondary', 'action_type' => 'navigate', 'action_target' => '/admin/datasources', 'position' => 'page_top', 'sort_order' => 20],
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
                    'position' => $button['position'] ?? 'page_top',
                    'config_json' => [],
                    'confirmation_required' => false,
                    'confirmation_text' => null,
                    'sort_order' => $button['sort_order'],
                    'is_visible' => true,
                ],
            );
        }

        $existingSalesMainQuery = (string) DataSource::query()
            ->where('code', 'sales_main_dashboard')
            ->value('query_template');

        $dataSource = DataSource::query()->updateOrCreate(
            ['code' => 'sales_main_dashboard'],
            [
                'name' => 'Satış Yönetimi Dashboard',
                'db_type' => 'n8n_json',
                'query_template' => <<<'SQL'
DECLARE @date_from DATE = '{{date_from}}';
DECLARE @date_to DATE = '{{date_to}}';
DECLARE @detail_type NVARCHAR(10) = '{{detail_type}}';
DECLARE @rep_code NVARCHAR(20) = '{{rep_code}}';
DECLARE @cari_filter NVARCHAR(MAX) = '{{cari_filter}}';
-- Query template metadata panel.data_sources uzerinden okunur.
-- Gerçek MSSQL executor bilerek bağlanmamıştır.
SQL,
                'allowed_params' => ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code', 'cari_filter', 'customer_filter'],
                'connection_meta' => [
                    'driver' => 'n8n_json',
                    'method' => 'POST',
                    'endpoint_url' => 'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1',
                    'response_rows_key' => 'rows',
                    'source_workflow' => 'PANEL - MSSQL Gateway - DataSource Runner v1',
                    'sql_policy' => 'unchanged',
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
                'description' => 'Satış Yönetimi sayfası için MSSQL metadata kaydı',
            ],
        );

        if (trim($existingSalesMainQuery) !== '') {
            $dataSource->forceFill(['query_template' => $existingSalesMainQuery])->save();
        }

        PageConfig::query()->updateOrCreate(
            ['page_code' => 'sales_main'],
            [
                'layout_json' => [
                    'heroEyebrow' => 'Satış kontrol merkezi',
                    'previewNotice' => 'Önizleme verisi; canlı endpoint henüz bağlanmadı.',
                    'moduleTabs' => [
                        ['label' => 'Tümü', 'href' => '/sales/main'],
                        ['label' => 'Ümit Yıldız', 'href' => '/sales/main'],
                        ['label' => 'Salih İmal', 'href' => '/sales/main'],
                        ['label' => 'Online / Perakende', 'href' => '/sales/online'],
                        ['label' => 'Bayi / Proje', 'href' => '/sales/bayi'],
                    ],
                    'topNav' => [
                        ['key' => 'sales', 'label' => 'Satış Yönetimi', 'href' => '/sales/main'],
                        ['key' => 'stock', 'label' => 'Stok Yönetimi', 'href' => '/stock'],
                        ['key' => 'orders', 'label' => 'Sipariş Yönetimi', 'href' => '/orders'],
                        ['key' => 'cari', 'label' => 'Müşteri Yönetimi', 'href' => '/cari'],
                        ['key' => 'proforma', 'label' => 'Proforma', 'href' => '/proforma'],
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
                        ['key' => 'all', 'label' => 'Tümü', 'repCode' => null, 'allowAll' => true, 'salesView' => 'tumu', 'note' => 'Tüm satışlar'],
                        ['key' => 'umit', 'label' => 'Ümit Yıldız', 'repCode' => '0003', 'allowAll' => false, 'salesView' => 'kendi', 'note' => 'Temsilci kodu 0003'],
                        ['key' => 'salih', 'label' => 'Salih İmal', 'repCode' => '0024', 'allowAll' => false, 'salesView' => 'kendi', 'note' => 'Temsilci kodu 0024'],
                        ['key' => 'bulent_saglam', 'label' => 'Bülent Sağlam', 'repCode' => '0024', 'allowAll' => false, 'salesView' => 'temsilci', 'note' => 'Bülent Sağlam temsilci kapsamı', 'navigateTo' => null],
                        ['key' => 'online-perakende', 'label' => 'Online / Perakende', 'repCode' => null, 'allowAll' => false, 'salesView' => 'kendi', 'navigateTo' => '/sales/online', 'note' => 'Online satış workflow görünümü'],
                        ['key' => 'bayi-proje', 'label' => 'Bayi / Proje', 'repCode' => null, 'allowAll' => false, 'salesView' => 'kendi', 'navigateTo' => '/sales/bayi', 'note' => 'Bayi satış workflow görünümü'],
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
                'name' => 'Bayi / Proje Detay',
                'workflow' => 'SALES_BAYI_PROJE_DETAY_V1.json',
                'target' => 'sales.bayi_proje',
            ],
            [
                'page_code' => 'sales_online',
                'code' => 'sales_online_perakende_detail',
                'name' => 'Online / Perakende Detay',
                'workflow' => 'SALES_ONLINE_PERAKENDE_DETAY_V1.json',
                'target' => 'sales.online_perakende',
            ],
            [
                'page_code' => 'orders',
                'code' => 'orders_dashboard',
                'name' => 'Emaks Prime Siparişler',
                'workflow' => 'EMAKS PRIME - Siparişler Workflow (TAM FIX).json',
                'target' => 'orders.dashboard',
            ],
        ] as $metadataSource) {
            $source = DataSource::query()->updateOrCreate(
                ['code' => $metadataSource['code']],
                [
                    'name' => $metadataSource['name'],
                    'db_type' => 'mssql',
                    'query_template' => '-- Query template bu workflow referansindan admin panelde yönetilecek: '.$metadataSource['workflow'],
                    'allowed_params' => ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code'],
                    'connection_meta' => [
                        'target' => $metadataSource['target'],
                        'reference_workflow' => $metadataSource['workflow'],
                    ],
                    'preview_payload' => [],
                    'active' => true,
                    'sort_order' => 20,
                    'description' => 'n8n workflow referansı Laravel datasource metadata kaydına taşındı. Canlı veri bağlantısı bu aşamada yapılmaz.',
                ],
            );

            PageConfig::query()->updateOrCreate(
                ['page_code' => $metadataSource['page_code']],
                [
                    'layout_json' => [
                        'heroEyebrow' => 'Workflow metadata modülü',
                        'previewNotice' => 'Önizleme verisi; canlı endpoint henüz bağlanmadı.',
                    ],
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

        $n8nConnectionMeta = [
            'driver' => 'n8n_json',
            'method' => 'POST',
            'endpoint_url' => 'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1',
            'response_rows_key' => 'rows',
            'source_workflow' => 'PANEL - MSSQL Gateway - DataSource Runner v1',
            'sql_policy' => 'unchanged',
        ];

        foreach ([
            ['code' => 'sales_online_perakende_detail', 'name' => 'Online / Perakende Detay', 'description' => 'Online ve perakende satış workflow metadata kaydı.'],
            ['code' => 'sales_bayi_proje_detail', 'name' => 'Bayi / Proje Detay', 'description' => 'Bayi ve proje satış workflow metadata kaydı.'],
            ['code' => 'stock_dashboard', 'name' => 'Stok Dashboard', 'description' => 'Stok modülü için n8n JSON metadata kaydı.'],
            ['code' => 'stock_critical', 'name' => 'Kritik Stoklar', 'description' => 'Kritik stoklar için placeholder veri kaynağı.'],
            ['code' => 'stock_warehouse', 'name' => 'Depo / Raf Durumu', 'description' => 'Depo ve raf durumu için placeholder veri kaynağı.'],
            ['code' => 'orders_alinan', 'name' => 'Alınan Siparişler', 'description' => 'Alınan siparişler için placeholder veri kaynağı.'],
            ['code' => 'orders_verilen', 'name' => 'Verilen Siparişler', 'description' => 'Verilen siparişler için placeholder veri kaynağı.'],
            ['code' => 'cari_list', 'name' => 'Müşteri Listesi', 'description' => 'Müşteri listesi için placeholder veri kaynağı.'],
            ['code' => 'cari_balance', 'name' => 'Müşteri Bakiyesi', 'description' => 'Müşteri bakiyesi için placeholder veri kaynağı.'],
            ['code' => 'cari_statement', 'name' => 'Müşteri Ekstre', 'description' => 'Müşteri ekstre için placeholder veri kaynağı.'],
            ['code' => 'customers_list', 'name' => 'Müşteri Listesi', 'description' => 'Müşteri listesi için kanonik n8n veri kaynağı.'],
            ['code' => 'customers_balance', 'name' => 'Müşteri Bakiyesi', 'description' => 'Müşteri bakiyesi için kanonik n8n veri kaynağı.'],
            ['code' => 'customer_detail', 'name' => 'Müşteri Detay', 'description' => 'Müşteri detayı için kanonik n8n veri kaynağı.'],
            ['code' => 'customer_documents', 'name' => 'Müşteri Evrakları', 'description' => 'Müşteri evrakları için kanonik n8n veri kaynağı.'],
            ['code' => 'customer_statement', 'name' => 'Müşteri Ekstre', 'description' => 'Müşteri ekstresi için kanonik n8n veri kaynağı.'],
            ['code' => 'proforma_list', 'name' => 'Proforma Liste', 'description' => 'Proforma liste için placeholder veri kaynağı.'],
            ['code' => 'proforma_detail', 'name' => 'Proforma Detay', 'description' => 'Proforma detay için placeholder veri kaynağı.'],
        ] as $index => $sourceDefinition) {
            DataSource::query()->updateOrCreate(
                ['code' => $sourceDefinition['code']],
                [
                    'name' => $sourceDefinition['name'],
                    'db_type' => 'n8n_json',
                    'query_template' => '-- Canlı SQL bu aşamada eklenmedi. Query template panel.data_sources üzerinden yönetilecek.',
                    'allowed_params' => ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code'],
                    'connection_meta' => $n8nConnectionMeta,
                    'preview_payload' => [
                        'mode' => 'placeholder',
                        'message' => 'Canlı veri kaynağı henüz bağlanmadı.',
                    ],
                    'active' => true,
                    'sort_order' => 30 + $index,
                    'description' => $sourceDefinition['description'],
                ],
            );
        }

        $tabs = [
            'sales' => [
                ['label' => 'Tümü', 'href' => '/sales/main'],
                ['label' => 'Ümit Yıldız', 'href' => '/sales/main'],
                ['label' => 'Salih İmal', 'href' => '/sales/main'],
                ['label' => 'Online / Perakende', 'href' => '/sales/online'],
                ['label' => 'Bayi / Proje', 'href' => '/sales/bayi'],
            ],
            'stock' => [
                ['label' => 'Stok Listesi', 'href' => '/stock'],
                ['label' => 'Kritik Stoklar', 'href' => '/stock/critical'],
            ],
            'orders' => [
                ['label' => 'Sipariş Yönetimi', 'href' => '/orders'],
                ['label' => 'Alınan Siparişler', 'href' => '/orders/alinan'],
                ['label' => 'Verilen Siparişler', 'href' => '/orders/verilen'],
            ],
            'cari' => [
                ['label' => 'Müşteri Listesi', 'href' => '/cari'],
                ['label' => 'Müşteri Bakiyesi', 'href' => '/cari/balance'],
            ],
            'proforma' => [
                ['label' => 'Proforma Liste', 'href' => '/proforma'],
                ['label' => 'Proforma Oluştur', 'href' => '/proforma/create'],
                ['label' => 'Proforma Detay', 'href' => '/proforma/detail'],
                ['label' => 'Proforma Düzenle', 'href' => '/proforma/edit'],
            ],
        ];

        foreach ([
            'sales_online' => ['eyebrow' => 'Satış Yönetimi', 'tabs' => 'sales', 'datasource' => 'sales_online_perakende_detail'],
            'sales_bayi' => ['eyebrow' => 'Satış Yönetimi', 'tabs' => 'sales', 'datasource' => 'sales_bayi_proje_detail'],
            'stock' => ['eyebrow' => 'Stok Yönetimi', 'tabs' => 'stock', 'datasource' => 'stock_dashboard'],
            'stock_critical' => ['eyebrow' => 'Stok Yönetimi', 'tabs' => 'stock', 'datasource' => 'stock_critical'],
            'stock_warehouse' => ['eyebrow' => 'Stok Yönetimi', 'tabs' => 'stock', 'datasource' => 'stock_warehouse'],
            'orders' => ['eyebrow' => 'Sipariş Yönetimi', 'tabs' => 'orders', 'datasource' => 'orders_alinan'],
            'orders_alinan' => ['eyebrow' => 'Sipariş Yönetimi', 'tabs' => 'orders', 'datasource' => 'orders_alinan'],
            'orders_verilen' => ['eyebrow' => 'Sipariş Yönetimi', 'tabs' => 'orders', 'datasource' => 'orders_verilen'],
            'cari' => ['eyebrow' => 'Müşteri Yönetimi', 'tabs' => 'cari', 'datasource' => 'customers_list'],
            'cari_balance' => ['eyebrow' => 'Müşteri Yönetimi', 'tabs' => 'cari', 'datasource' => 'customers_balance'],
            'cari_detail' => ['eyebrow' => 'Müşteri Yönetimi', 'tabs' => 'cari', 'datasource' => 'customer_statement'],
            'cari_document_detail' => ['eyebrow' => 'Müşteri Yönetimi', 'tabs' => 'cari', 'datasource' => 'customer_documents'],
            'proforma' => ['eyebrow' => 'Proforma', 'tabs' => 'proforma', 'datasource' => 'proforma_list'],
            'proforma_create' => ['eyebrow' => 'Proforma', 'tabs' => 'proforma', 'datasource' => 'proforma_list'],
            'proforma_detail' => ['eyebrow' => 'Proforma', 'tabs' => 'proforma', 'datasource' => 'proforma_detail'],
            'proforma_edit' => ['eyebrow' => 'Proforma', 'tabs' => 'proforma', 'datasource' => 'proforma_detail'],
        ] as $pageCode => $configDefinition) {
            PageConfig::query()->updateOrCreate(
                ['page_code' => $pageCode],
                [
                    'layout_json' => [
                        'heroEyebrow' => $configDefinition['eyebrow'],
                        'previewNotice' => 'Canlı veri kaynağı henüz bağlanmadı.',
                        'moduleTabs' => $tabs[$configDefinition['tabs']],
                    ],
                    'filters_json' => [],
                    'datasource_id' => DataSource::query()->where('code', $configDefinition['datasource'])->value('id'),
                ],
            );
        }

        foreach (['admin_panel', 'admin_users', 'admin_pages', 'admin_datasources', 'admin_logs'] as $pageCode) {
            PageConfig::query()->updateOrCreate(
                ['page_code' => $pageCode],
                [
                    'layout_json' => ['heroEyebrow' => 'Yönetim modülü'],
                    'filters_json' => [],
                    'datasource_id' => null,
                ],
            );
        }

        PageConfig::query()->where('page_code', 'sales_representatives')->delete();

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
                            'sales' => in_array($resource->code, [
                                'dashboard',
                                'sales_main',
                                'sales_main_all',
                                'sales_online',
                                'sales_bayi',
                                'customers',
                                'customers_own_rep',
                                'proforma',
                                'proforma_create',
                                'proforma_detail',
                                'proforma_edit',
                            ], true),
                            'stock' => in_array($resource->code, [
                                'dashboard',
                                'stock',
                                'stock_critical',
                                'stock_warehouse',
                                'orders',
                                'orders_alinan',
                                'orders_verilen',
                            ], true),
                            'orders' => in_array($resource->code, [
                                'dashboard',
                                'orders',
                                'orders_alinan',
                                'orders_verilen',
                            ], true),
                            'customer' => in_array($resource->code, [
                                'dashboard',
                                'customers',
                                'customers_own_rep',
                            ], true),
                            'proforma' => in_array($resource->code, [
                                'dashboard',
                                'customers',
                                'customers_own_rep',
                                'stock',
                                'proforma',
                                'proforma_create',
                                'proforma_detail',
                                'proforma_edit',
                            ], true),
                            'viewer' => in_array($resource->code, [
                                'dashboard',
                            ], true),
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
                    ['can_view' => true],
                );
            });
        }
    }
}
