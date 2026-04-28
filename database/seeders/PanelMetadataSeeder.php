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
            ['code' => 'admin', 'name' => 'Admin', 'description' => 'Tam yetkili sistem yoneticisi', 'is_super_admin' => true],
            ['code' => 'manager', 'name' => 'YÃ¶netici', 'description' => 'YÃ¶netim gÃ¶rÃ¼nÃ¼mÃ¼ ve geniÅŸ panel yetkisi', 'is_super_admin' => false],
            ['code' => 'sales', 'name' => 'SatÄ±ÅŸ', 'description' => 'SatÄ±ÅŸ ekip eriÅŸimi', 'is_super_admin' => false],
            ['code' => 'stock', 'name' => 'Stok', 'description' => 'Stok ekip eriÅŸimi', 'is_super_admin' => false],
            ['code' => 'orders', 'name' => 'Siparis', 'description' => 'Siparis ekip erisimi', 'is_super_admin' => false],
            ['code' => 'customer', 'name' => 'Musteri', 'description' => 'Musteri/CRM ekip erisimi', 'is_super_admin' => false],
            ['code' => 'proforma', 'name' => 'Proforma', 'description' => 'Proforma operasyon erisimi', 'is_super_admin' => false],
            ['code' => 'viewer', 'name' => 'Goruntuleyici', 'description' => 'Sadece atanmis kaynaklari goruntuler', 'is_super_admin' => false],
        ])->mapWithKeys(fn (array $role) => [
            $role['code'] => Role::query()->updateOrCreate(['code' => $role['code']], $role),
        ]);

        $resources = collect([
            ['code' => 'dashboard', 'name' => 'YÃ¶netim Ã–zeti', 'type' => 'page'],
            ['code' => 'sales_main', 'name' => 'Genel SatÄ±ÅŸ', 'type' => 'page'],
            ['code' => 'sales_main_all', 'name' => 'SatÄ±ÅŸ YÃ¶netimi TÃ¼m Kapsamlar', 'type' => 'scope'],
            ['code' => 'sales_online', 'name' => 'Online / Perakende', 'type' => 'page'],
            ['code' => 'sales_bayi', 'name' => 'Bayi / Proje', 'type' => 'page'],
            ['code' => 'sales_representatives', 'name' => 'SatÄ±ÅŸ Temsilcisi GÃ¶rÃ¼nÃ¼mÃ¼', 'type' => 'page'],
            ['code' => 'stock', 'name' => 'Stok Listesi', 'type' => 'page'],
            ['code' => 'stock_critical', 'name' => 'Kritik Stoklar', 'type' => 'page'],
            ['code' => 'stock_warehouse', 'name' => 'Depo / Raf Durumu', 'type' => 'page'],
            ['code' => 'finance_cari_durum', 'name' => 'Cari Durum', 'type' => 'page'],
            ['code' => 'orders', 'name' => 'SipariÅŸ YÃ¶netimi', 'type' => 'page'],
            ['code' => 'orders_alinan', 'name' => 'AlÄ±nan SipariÅŸler', 'type' => 'page'],
            ['code' => 'orders_verilen', 'name' => 'Verilen SipariÅŸler', 'type' => 'page'],
            ['code' => 'cari', 'name' => 'Cari Liste', 'type' => 'page'],
            ['code' => 'cari_balance', 'name' => 'Cari Bakiye', 'type' => 'page'],
            ['code' => 'cari_detail', 'name' => 'Cari Detay ve Ekstre', 'type' => 'page'],
            ['code' => 'proforma', 'name' => 'Proforma Liste', 'type' => 'page'],
            ['code' => 'proforma_create', 'name' => 'Proforma OluÅŸtur', 'type' => 'page'],
            ['code' => 'proforma_detail', 'name' => 'Proforma Detay', 'type' => 'page'],
            ['code' => 'proforma_edit', 'name' => 'Proforma DÃ¼zenle', 'type' => 'page'],
            ['code' => 'admin_panel', 'name' => 'YÃ¶netim Paneli', 'type' => 'page'],
            ['code' => 'admin_users', 'name' => 'KullanÄ±cÄ±lar', 'type' => 'page'],
            ['code' => 'admin_pages', 'name' => 'Sayfalar', 'type' => 'page'],
            ['code' => 'admin_datasources', 'name' => 'Veri KaynaklarÄ±', 'type' => 'page'],
            ['code' => 'admin_logs', 'name' => 'Loglar', 'type' => 'page'],
            ['code' => 'customers', 'name' => 'Musteri Yonetimi', 'type' => 'page'],
            ['code' => 'user_admin', 'name' => 'Kullanici Yonetimi', 'type' => 'page'],
            ['code' => 'data_sources', 'name' => 'Veri Kaynaklari Yonetimi', 'type' => 'page'],
            ['code' => 'sales_main_dashboard', 'name' => 'SatÄ±ÅŸ YÃ¶netimi Veri KaynaÄŸÄ±', 'type' => 'data_source'],
            ['code' => 'stock_dashboard', 'name' => 'Stok Veri KaynaÄŸÄ±', 'type' => 'data_source'],
            ['code' => 'sales_bayi_proje_detail', 'name' => 'Bayi / Proje Veri KaynaÄŸÄ±', 'type' => 'data_source'],
            ['code' => 'sales_online_perakende_detail', 'name' => 'Online / Perakende Veri KaynaÄŸÄ±', 'type' => 'data_source'],
            ['code' => 'orders_dashboard', 'name' => 'SipariÅŸ Veri KaynaÄŸÄ±', 'type' => 'data_source'],
            ['code' => 'orders_alinan', 'name' => 'Alinan Siparis Veri Kaynagi', 'type' => 'data_source'],
            ['code' => 'orders_verilen', 'name' => 'Verilen Siparis Veri Kaynagi', 'type' => 'data_source'],
            ['code' => 'cari_list', 'name' => 'Cari Liste Veri KaynaÄŸÄ±', 'type' => 'data_source'],
            ['code' => 'cari_statement', 'name' => 'Cari Ekstre Veri KaynaÄŸÄ±', 'type' => 'data_source'],
            ['code' => 'customers_list', 'name' => 'Musteri Liste Veri Kaynagi', 'type' => 'data_source'],
            ['code' => 'customers_balance', 'name' => 'Musteri Bakiye Veri Kaynagi', 'type' => 'data_source'],
            ['code' => 'customer_detail', 'name' => 'Musteri Detay Veri Kaynagi', 'type' => 'data_source'],
            ['code' => 'customer_documents', 'name' => 'Musteri Evrak Veri Kaynagi', 'type' => 'data_source'],
            ['code' => 'customer_statement', 'name' => 'Musteri Ekstre Veri Kaynagi', 'type' => 'data_source'],
            ['code' => 'proforma_list', 'name' => 'Proforma Liste Veri KaynaÄŸÄ±', 'type' => 'data_source'],
            ['code' => 'proforma_detail', 'name' => 'Proforma Detay Veri Kaynagi', 'type' => 'data_source'],
            ['code' => 'proforma_draft', 'name' => 'Proforma Taslak Veri Kaynagi', 'type' => 'data_source'],
            ['code' => 'proforma_items', 'name' => 'Proforma Satir Veri Kaynagi', 'type' => 'data_source'],
            ['code' => 'proforma_customer_search', 'name' => 'Proforma Musteri Arama', 'type' => 'data_source'],
            ['code' => 'proforma_stock_search', 'name' => 'Proforma Stok Arama', 'type' => 'data_source'],
            ['code' => 'proforma_price_list', 'name' => 'Proforma Fiyat Listesi', 'type' => 'data_source'],
            ['code' => 'proforma_discount_defs', 'name' => 'Proforma Iskonto Tanimlari', 'type' => 'data_source'],
        ])->mapWithKeys(fn (array $resource) => [
            $resource['code'] => Resource::query()->updateOrCreate(['code' => $resource['code']], $resource),
        ]);

        $groups = collect([
            ['code' => 'executive', 'name' => 'YÃ¶netim', 'icon' => 'layout-grid', 'menu_order' => 10, 'active' => true],
            ['code' => 'sales', 'name' => 'SatÄ±ÅŸ YÃ¶netimi', 'icon' => 'chart-column', 'menu_order' => 20, 'active' => true],
            ['code' => 'stock', 'name' => 'Stok YÃ¶netimi', 'icon' => 'boxes', 'menu_order' => 30, 'active' => true],
            ['code' => 'orders', 'name' => 'SipariÅŸ YÃ¶netimi', 'icon' => 'shopping-cart', 'menu_order' => 40, 'active' => true],
            ['code' => 'cari', 'name' => 'Musteri Yonetimi', 'icon' => 'wallet', 'menu_order' => 50, 'active' => true],
            ['code' => 'proforma', 'name' => 'Proforma', 'icon' => 'folder-kanban', 'menu_order' => 60, 'active' => true],
            ['code' => 'administration', 'name' => 'Sistem YÃ¶netimi', 'icon' => 'shield', 'menu_order' => 70, 'active' => true],
        ])->mapWithKeys(fn (array $group) => [
            $group['code'] => MenuGroup::query()->updateOrCreate(['code' => $group['code']], $group),
        ]);

        $pages = collect([
            ['code' => 'dashboard', 'name' => 'YÃ¶netim Ã–zeti', 'route' => '/dashboard', 'component' => 'panel/page', 'layout_type' => 'admin', 'icon' => 'layout-grid', 'description' => 'Genel yÃ¶netim ve metadata Ã¶zet gÃ¶rÃ¼nÃ¼mÃ¼', 'resource_code' => 'dashboard', 'page_order' => 10, 'active' => true],
            ['code' => 'sales_main', 'name' => 'Genel SatÄ±ÅŸ', 'route' => '/sales/main', 'component' => 'panel/sales-main', 'layout_type' => 'module', 'icon' => 'chart-column', 'description' => 'Ana satÄ±ÅŸ dashboardu ve yÃ¶netim kapsamlarÄ±', 'resource_code' => 'sales_main', 'page_order' => 20, 'active' => true],
            ['code' => 'sales_online', 'name' => 'Online / Perakende', 'route' => '/sales/online', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'signal', 'description' => 'Online ve perakende satÄ±ÅŸ gÃ¶rÃ¼nÃ¼mÃ¼', 'resource_code' => 'sales_online', 'page_order' => 30, 'active' => true],
            ['code' => 'sales_bayi', 'name' => 'Bayi / Proje', 'route' => '/sales/bayi', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'store', 'description' => 'Bayi ve proje satÄ±ÅŸ gÃ¶rÃ¼nÃ¼mÃ¼', 'resource_code' => 'sales_bayi', 'page_order' => 40, 'active' => true],
            ['code' => 'sales_representatives', 'name' => 'SatÄ±ÅŸ Temsilcisi GÃ¶rÃ¼nÃ¼mÃ¼', 'route' => '/sales/representatives', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'users', 'description' => 'Temsilci bazlÄ± satÄ±ÅŸ kapsamlarÄ± ana SatÄ±ÅŸ YÃ¶netimi kapsam filtresinden yÃ¶netilir.', 'resource_code' => 'sales_representatives', 'page_order' => 50, 'active' => false],
            ['code' => 'stock', 'name' => 'Stok Listesi', 'route' => '/stock', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'boxes', 'description' => 'Stok listesi ve Ã¼rÃ¼n izleme ekranÄ±.', 'resource_code' => 'stock', 'page_order' => 60, 'active' => true],
            ['code' => 'stock_critical', 'name' => 'Kritik Stoklar', 'route' => '/stock/critical', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'boxes', 'description' => 'Kritik stok seviyeleri ve uyarÄ± listesi burada hazÄ±rlanÄ±r.', 'resource_code' => 'stock_critical', 'page_order' => 61, 'active' => true],
            ['code' => 'stock_warehouse', 'name' => 'Depo / Raf Durumu', 'route' => '/stock/warehouse', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'boxes', 'description' => 'Depo, raf ve lokasyon durumu bu modÃ¼l altÄ±nda izlenir.', 'resource_code' => 'stock_warehouse', 'page_order' => 62, 'active' => true],
            ['code' => 'orders', 'name' => 'SipariÅŸ YÃ¶netimi', 'route' => '/orders', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'shopping-cart', 'description' => 'SipariÅŸ operasyonlarÄ± iÃ§in genel gÃ¶rÃ¼nÃ¼m.', 'resource_code' => 'orders', 'page_order' => 70, 'active' => true],
            ['code' => 'orders_alinan', 'name' => 'AlÄ±nan SipariÅŸler', 'route' => '/orders/alinan', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'shopping-cart', 'description' => 'MÃ¼ÅŸterilerden alÄ±nan sipariÅŸler bu ekranda listelenecek.', 'resource_code' => 'orders_alinan', 'page_order' => 71, 'active' => true],
            ['code' => 'orders_verilen', 'name' => 'Verilen SipariÅŸler', 'route' => '/orders/verilen', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'shopping-cart', 'description' => 'TedarikÃ§i ve Ã¼retim tarafÄ±na verilen sipariÅŸler burada izlenecek.', 'resource_code' => 'orders_verilen', 'page_order' => 72, 'active' => true],
            ['code' => 'cari', 'name' => 'Musteri Yonetimi', 'route' => '/cari', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'wallet', 'description' => 'Musteri, bayi ve hesap bilgileri bu modul altinda yonetilir.', 'resource_code' => 'customers', 'page_order' => 80, 'active' => true],
            ['code' => 'cari_balance', 'name' => 'Musteri Bakiyesi', 'route' => '/cari/balance', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'wallet', 'description' => 'Musteri bakiye izleme ve risk gorunumu.', 'resource_code' => 'customers', 'page_order' => 81, 'active' => true],
            ['code' => 'cari_detail', 'name' => 'Musteri Detayi ve Ekstre', 'route' => '/cari/detail', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'wallet', 'description' => 'Musteri detay ve ekstre kirilimlari.', 'resource_code' => 'customers', 'page_order' => 82, 'active' => true],
            ['code' => 'proforma', 'name' => 'Proforma Liste', 'route' => '/proforma', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'folder-kanban', 'description' => 'Proforma kayÄ±tlarÄ±nÄ±n listeleneceÄŸi operasyon ekranÄ±.', 'resource_code' => 'proforma', 'page_order' => 90, 'active' => true],
            ['code' => 'proforma_create', 'name' => 'Proforma OluÅŸtur', 'route' => '/proforma/create', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'folder-kanban', 'description' => 'Yeni proforma taslaklarÄ± iÃ§in hazÄ±rlÄ±k ekranÄ±.', 'resource_code' => 'proforma_create', 'page_order' => 91, 'active' => true],
            ['code' => 'proforma_detail', 'name' => 'Proforma Detay', 'route' => '/proforma/detail', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'folder-kanban', 'description' => 'Proforma detay gÃ¶rÃ¼ntÃ¼leme iskeleti.', 'resource_code' => 'proforma_detail', 'page_order' => 92, 'active' => true],
            ['code' => 'proforma_edit', 'name' => 'Proforma DÃ¼zenle', 'route' => '/proforma/edit', 'component' => 'panel/page', 'layout_type' => 'module', 'icon' => 'folder-kanban', 'description' => 'Proforma dÃ¼zenleme akÄ±ÅŸÄ± iÃ§in placeholder ekran.', 'resource_code' => 'proforma_edit', 'page_order' => 93, 'active' => true],
            ['code' => 'finance_cari_durum', 'name' => 'Cari Durum', 'route' => '/finance/cari-durum', 'component' => 'panel/page', 'layout_type' => 'admin', 'icon' => 'wallet', 'description' => 'Cari durum ve finans yÃ¶netim gÃ¶rÃ¼nÃ¼mÃ¼', 'resource_code' => 'finance_cari_durum', 'page_order' => 100, 'active' => true],
            ['code' => 'admin_panel', 'name' => 'YÃ¶netim Paneli', 'route' => '/admin', 'component' => 'panel/admin/index', 'layout_type' => 'admin', 'icon' => 'shield', 'description' => 'Panel yÃ¶netim merkezi', 'resource_code' => 'admin_panel', 'page_order' => 110, 'active' => true],
            ['code' => 'admin_users', 'name' => 'KullanÄ±cÄ±lar', 'route' => '/admin/users', 'component' => 'panel/admin/users', 'layout_type' => 'admin', 'icon' => 'users', 'description' => 'KullanÄ±cÄ±, rol ve eriÅŸim yÃ¶netimi', 'resource_code' => 'user_admin', 'page_order' => 111, 'active' => true],
            ['code' => 'admin_pages', 'name' => 'Sayfalar', 'route' => '/admin/pages', 'component' => 'panel/admin/pages', 'layout_type' => 'admin', 'icon' => 'panel-left', 'description' => 'MenÃ¼, route ve sayfa konfigÃ¼rasyonu', 'resource_code' => 'admin_pages', 'page_order' => 112, 'active' => true],
            ['code' => 'admin_datasources', 'name' => 'Veri KaynaklarÄ±', 'route' => '/admin/datasources', 'component' => 'panel/admin/datasources', 'layout_type' => 'admin', 'icon' => 'database', 'description' => 'MSSQL, Postgres ve workflow metadata yÃ¶netimi', 'resource_code' => 'data_sources', 'page_order' => 113, 'active' => true],
            ['code' => 'admin_logs', 'name' => 'Loglar', 'route' => '/admin/logs', 'component' => 'panel/admin/logs', 'layout_type' => 'admin', 'icon' => 'scroll-text', 'description' => 'Aksiyon ve audit log kayÄ±tlarÄ±', 'resource_code' => 'admin_logs', 'page_order' => 114, 'active' => true],
        ])->mapWithKeys(fn (array $page) => [
            $page['code'] => Page::query()->updateOrCreate(['code' => $page['code']], $page),
        ]);

        $menuItems = [
            ['menu_group' => 'executive', 'page' => 'dashboard', 'label' => 'YÃ¶netim Ã–zeti', 'icon' => 'layout-grid', 'sort_order' => 10],
            ['menu_group' => 'sales', 'page' => 'sales_main', 'label' => 'Genel SatÄ±ÅŸ', 'icon' => 'chart-column', 'sort_order' => 20],
            ['menu_group' => 'sales', 'page' => 'sales_online', 'label' => 'Online / Perakende', 'icon' => 'signal', 'sort_order' => 30],
            ['menu_group' => 'sales', 'page' => 'sales_bayi', 'label' => 'Bayi / Proje', 'icon' => 'store', 'sort_order' => 40],
            ['menu_group' => 'stock', 'page' => 'stock', 'label' => 'Stok Listesi', 'icon' => 'boxes', 'sort_order' => 60],
            ['menu_group' => 'stock', 'page' => 'stock_critical', 'label' => 'Kritik Stoklar', 'icon' => 'boxes', 'sort_order' => 61],
            ['menu_group' => 'stock', 'page' => 'stock_warehouse', 'label' => 'Depo / Raf Durumu', 'icon' => 'boxes', 'sort_order' => 62],
            ['menu_group' => 'orders', 'page' => 'orders', 'label' => 'SipariÅŸ YÃ¶netimi', 'icon' => 'shopping-cart', 'sort_order' => 70],
            ['menu_group' => 'orders', 'page' => 'orders_alinan', 'label' => 'AlÄ±nan SipariÅŸler', 'icon' => 'shopping-cart', 'sort_order' => 71],
            ['menu_group' => 'orders', 'page' => 'orders_verilen', 'label' => 'Verilen SipariÅŸler', 'icon' => 'shopping-cart', 'sort_order' => 72],
            ['menu_group' => 'cari', 'page' => 'cari', 'label' => 'Musteri Listesi', 'icon' => 'wallet', 'sort_order' => 80],
            ['menu_group' => 'cari', 'page' => 'cari_balance', 'label' => 'Musteri Bakiyesi', 'icon' => 'wallet', 'sort_order' => 81],
            ['menu_group' => 'cari', 'page' => 'cari_detail', 'label' => 'Musteri Detay / Ekstre', 'icon' => 'wallet', 'sort_order' => 82],
            ['menu_group' => 'proforma', 'page' => 'proforma', 'label' => 'Proforma Liste', 'icon' => 'folder-kanban', 'sort_order' => 90],
            ['menu_group' => 'proforma', 'page' => 'proforma_create', 'label' => 'Proforma OluÅŸtur', 'icon' => 'folder-kanban', 'sort_order' => 91],
            ['menu_group' => 'proforma', 'page' => 'proforma_detail', 'label' => 'Proforma Detay', 'icon' => 'folder-kanban', 'sort_order' => 92],
            ['menu_group' => 'proforma', 'page' => 'proforma_edit', 'label' => 'Proforma DÃ¼zenle', 'icon' => 'folder-kanban', 'sort_order' => 93],
            ['menu_group' => 'executive', 'page' => 'finance_cari_durum', 'label' => 'Cari Durum', 'icon' => 'wallet', 'sort_order' => 100],
            ['menu_group' => 'administration', 'page' => 'admin_panel', 'label' => 'YÃ¶netim Paneli', 'icon' => 'shield', 'sort_order' => 110],
            ['menu_group' => 'administration', 'page' => 'admin_users', 'label' => 'KullanÄ±cÄ±lar', 'icon' => 'users', 'sort_order' => 111],
            ['menu_group' => 'administration', 'page' => 'admin_pages', 'label' => 'Sayfalar / Butonlar', 'icon' => 'panel-left', 'sort_order' => 112],
            ['menu_group' => 'administration', 'page' => 'admin_datasources', 'label' => 'Veri KaynaklarÄ±', 'icon' => 'database', 'sort_order' => 113],
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
                    'is_visible' => true,
                ],
            );
        }

        Page::query()->where('code', 'sales_representatives')->update(['active' => false]);
        PageMenu::query()
            ->where('page_id', $pages['sales_representatives']->id)
            ->update(['is_visible' => false]);

        $buttons = [
            ['page' => 'admin_panel', 'resource_code' => 'user_admin', 'label' => 'KullanÄ±cÄ±lara Git', 'code' => 'admin_panel_users', 'variant' => 'primary', 'action_type' => 'navigate', 'action_target' => '/admin/users', 'position' => 'page_top', 'sort_order' => 10],
            ['page' => 'admin_panel', 'resource_code' => 'data_sources', 'label' => 'Veri KaynaklarÄ±nÄ± YÃ¶net', 'code' => 'admin_panel_datasources', 'variant' => 'secondary', 'action_type' => 'navigate', 'action_target' => '/admin/datasources', 'position' => 'page_top', 'sort_order' => 20],
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
                'name' => 'SatÄ±ÅŸ YÃ¶netimi Dashboard',
                'db_type' => 'n8n_json',
                'query_template' => <<<'SQL'
DECLARE @date_from DATE = '{{date_from}}';
DECLARE @date_to DATE = '{{date_to}}';
DECLARE @detail_type NVARCHAR(10) = '{{detail_type}}';
DECLARE @rep_code NVARCHAR(20) = '{{rep_code}}';
-- Query template metadata panel.data_sources uzerinden okunur.
-- Gercek MSSQL executor bilerek baglanmamistir.
SQL,
                'allowed_params' => ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code'],
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
                'description' => 'SatÄ±ÅŸ YÃ¶netimi sayfasÄ± iÃ§in MSSQL metadata kaydÄ±',
            ],
        );

        if (trim($existingSalesMainQuery) !== '') {
            $dataSource->forceFill(['query_template' => $existingSalesMainQuery])->save();
        }

        PageConfig::query()->updateOrCreate(
            ['page_code' => 'sales_main'],
            [
                'layout_json' => [
                    'heroEyebrow' => 'SatÄ±ÅŸ kontrol merkezi',
                    'previewNotice' => 'Ã–nizleme verisi; canlÄ± endpoint henÃ¼z baÄŸlanmadÄ±.',
                    'moduleTabs' => [
                        ['label' => 'TÃ¼mÃ¼', 'href' => '/sales/main'],
                        ['label' => 'Ãœmit YÄ±ldÄ±z', 'href' => '/sales/main'],
                        ['label' => 'Salih Ä°mal', 'href' => '/sales/main'],
                        ['label' => 'Online / Perakende', 'href' => '/sales/online'],
                        ['label' => 'Bayi / Proje', 'href' => '/sales/bayi'],
                    ],
                    'topNav' => [
                        ['key' => 'sales', 'label' => 'SatÄ±ÅŸ YÃ¶netimi', 'href' => '/sales/main'],
                        ['key' => 'stock', 'label' => 'Stok YÃ¶netimi', 'href' => '/stock'],
                        ['key' => 'orders', 'label' => 'SipariÅŸ YÃ¶netimi', 'href' => '/orders'],
                        ['key' => 'cari', 'label' => 'Musteri Yonetimi', 'href' => '/cari'],
                        ['key' => 'proforma', 'label' => 'Proforma', 'href' => '/proforma'],
                    ],
                ],
                'filters_json' => [
                    'defaults' => ['grain' => 'week', 'detailType' => 'cari', 'scopeKey' => 'all'],
                    'grains' => [
                        ['key' => 'day', 'label' => 'GÃ¼nlÃ¼k'],
                        ['key' => 'week', 'label' => 'HaftalÄ±k'],
                        ['key' => 'month', 'label' => 'AylÄ±k'],
                        ['key' => 'year', 'label' => 'YÄ±llÄ±k'],
                    ],
                    'detailModes' => [
                        ['key' => 'cari', 'label' => 'Cari SatÄ±ÅŸ DetayÄ±'],
                        ['key' => 'urun', 'label' => 'ÃœrÃ¼n SatÄ±ÅŸ DetayÄ±'],
                    ],
                    'managementScopes' => [
                        ['key' => 'all', 'label' => 'TÃ¼mÃ¼', 'repCode' => null, 'allowAll' => true, 'salesView' => 'tumu', 'note' => 'TÃ¼m satÄ±ÅŸlar'],
                        ['key' => 'umit', 'label' => 'Ãœmit YÄ±ldÄ±z', 'repCode' => '0003', 'allowAll' => false, 'salesView' => 'kendi', 'note' => 'Temsilci kodu 0003'],
                        ['key' => 'salih', 'label' => 'Salih Imal', 'repCode' => '0024', 'allowAll' => false, 'salesView' => 'kendi', 'note' => 'Temsilci kodu 0024'],
                        ['key' => 'online-perakende', 'label' => 'Online / Perakende', 'repCode' => null, 'allowAll' => false, 'salesView' => 'kendi', 'navigateTo' => '/sales/online', 'note' => 'Online satis workflow gorunumu'],
                        ['key' => 'bayi-proje', 'label' => 'Bayi / Proje', 'repCode' => null, 'allowAll' => false, 'salesView' => 'kendi', 'navigateTo' => '/sales/bayi', 'note' => 'Bayi satis workflow gorunumu'],
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
                    'query_template' => '-- Query template bu workflow referansindan admin panelde yonetilecek: '.$metadataSource['workflow'],
                    'allowed_params' => ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code'],
                    'connection_meta' => [
                        'target' => $metadataSource['target'],
                        'reference_workflow' => $metadataSource['workflow'],
                    ],
                    'preview_payload' => [],
                    'active' => true,
                    'sort_order' => 20,
                    'description' => 'n8n workflow referansÄ± Laravel datasource metadata kaydÄ±na taÅŸÄ±ndÄ±. CanlÄ± veri baÄŸlantÄ±sÄ± bu aÅŸamada yapÄ±lmaz.',
                ],
            );

            PageConfig::query()->updateOrCreate(
                ['page_code' => $metadataSource['page_code']],
                [
                    'layout_json' => [
                        'heroEyebrow' => 'Workflow metadata modÃ¼lÃ¼',
                        'previewNotice' => 'Ã–nizleme verisi; canlÄ± endpoint henÃ¼z baÄŸlanmadÄ±.',
                    ],
                    'filters_json' => [
                        'defaults' => ['grain' => 'week', 'scopeKey' => 'all'],
                        'grains' => [
                            ['key' => 'day', 'label' => 'GÃ¼nlÃ¼k'],
                            ['key' => 'week', 'label' => 'HaftalÄ±k'],
                            ['key' => 'month', 'label' => 'AylÄ±k'],
                            ['key' => 'year', 'label' => 'YÄ±llÄ±k'],
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
            ['code' => 'sales_online_perakende_detail', 'name' => 'Online / Perakende Detay', 'description' => 'Online ve perakende satÄ±ÅŸ workflow metadata kaydÄ±.'],
            ['code' => 'sales_bayi_proje_detail', 'name' => 'Bayi / Proje Detay', 'description' => 'Bayi ve proje satÄ±ÅŸ workflow metadata kaydÄ±.'],
            ['code' => 'stock_dashboard', 'name' => 'Stok Dashboard', 'description' => 'Stok modÃ¼lÃ¼ iÃ§in n8n JSON metadata kaydÄ±.'],
            ['code' => 'stock_critical', 'name' => 'Kritik Stoklar', 'description' => 'Kritik stoklar iÃ§in placeholder veri kaynaÄŸÄ±.'],
            ['code' => 'stock_warehouse', 'name' => 'Depo / Raf Durumu', 'description' => 'Depo ve raf durumu iÃ§in placeholder veri kaynaÄŸÄ±.'],
            ['code' => 'orders_alinan', 'name' => 'AlÄ±nan SipariÅŸler', 'description' => 'AlÄ±nan sipariÅŸler iÃ§in placeholder veri kaynaÄŸÄ±.'],
            ['code' => 'orders_verilen', 'name' => 'Verilen SipariÅŸler', 'description' => 'Verilen sipariÅŸler iÃ§in placeholder veri kaynaÄŸÄ±.'],
            ['code' => 'cari_list', 'name' => 'Cari Liste', 'description' => 'Cari liste iÃ§in placeholder veri kaynaÄŸÄ±.'],
            ['code' => 'cari_balance', 'name' => 'Cari Bakiye', 'description' => 'Cari bakiye iÃ§in placeholder veri kaynaÄŸÄ±.'],
            ['code' => 'cari_statement', 'name' => 'Cari Ekstre', 'description' => 'Cari ekstre iÃ§in placeholder veri kaynaÄŸÄ±.'],
            ['code' => 'proforma_list', 'name' => 'Proforma Liste', 'description' => 'Proforma liste iÃ§in placeholder veri kaynaÄŸÄ±.'],
            ['code' => 'proforma_detail', 'name' => 'Proforma Detay', 'description' => 'Proforma detay iÃ§in placeholder veri kaynaÄŸÄ±.'],
        ] as $index => $sourceDefinition) {
            DataSource::query()->updateOrCreate(
                ['code' => $sourceDefinition['code']],
                [
                    'name' => $sourceDefinition['name'],
                    'db_type' => 'n8n_json',
                    'query_template' => '-- CanlÄ± SQL bu aÅŸamada eklenmedi. Query template panel.data_sources Ã¼zerinden yÃ¶netilecek.',
                    'allowed_params' => ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code'],
                    'connection_meta' => $n8nConnectionMeta,
                    'preview_payload' => [
                        'mode' => 'placeholder',
                        'message' => 'CanlÄ± veri kaynaÄŸÄ± henÃ¼z baÄŸlanmadÄ±.',
                    ],
                    'active' => true,
                    'sort_order' => 30 + $index,
                    'description' => $sourceDefinition['description'],
                ],
            );
        }

        $tabs = [
            'sales' => [
                ['label' => 'TÃ¼mÃ¼', 'href' => '/sales/main'],
                ['label' => 'Ãœmit YÄ±ldÄ±z', 'href' => '/sales/main'],
                ['label' => 'Salih Ä°mal', 'href' => '/sales/main'],
                ['label' => 'Online / Perakende', 'href' => '/sales/online'],
                ['label' => 'Bayi / Proje', 'href' => '/sales/bayi'],
            ],
            'stock' => [
                ['label' => 'Stok Listesi', 'href' => '/stock'],
                ['label' => 'Kritik Stoklar', 'href' => '/stock/critical'],
                ['label' => 'Depo / Raf Durumu', 'href' => '/stock/warehouse'],
            ],
            'orders' => [
                ['label' => 'SipariÅŸ YÃ¶netimi', 'href' => '/orders'],
                ['label' => 'AlÄ±nan SipariÅŸler', 'href' => '/orders/alinan'],
                ['label' => 'Verilen SipariÅŸler', 'href' => '/orders/verilen'],
            ],
            'cari' => [
                ['label' => 'Musteri Listesi', 'href' => '/cari'],
                ['label' => 'Musteri Bakiyesi', 'href' => '/cari/balance'],
                ['label' => 'Musteri Detay', 'href' => '/cari/detail'],
                ['label' => 'Musteri Ekstre', 'href' => '/cari/detail'],
            ],
            'proforma' => [
                ['label' => 'Proforma Liste', 'href' => '/proforma'],
                ['label' => 'Proforma OluÅŸtur', 'href' => '/proforma/create'],
                ['label' => 'Proforma Detay', 'href' => '/proforma/detail'],
                ['label' => 'Proforma DÃ¼zenle', 'href' => '/proforma/edit'],
            ],
        ];

        foreach ([
            'sales_online' => ['eyebrow' => 'SatÄ±ÅŸ YÃ¶netimi', 'tabs' => 'sales', 'datasource' => 'sales_online_perakende_detail'],
            'sales_bayi' => ['eyebrow' => 'SatÄ±ÅŸ YÃ¶netimi', 'tabs' => 'sales', 'datasource' => 'sales_bayi_proje_detail'],
            'stock' => ['eyebrow' => 'Stok YÃ¶netimi', 'tabs' => 'stock', 'datasource' => 'stock_dashboard'],
            'stock_critical' => ['eyebrow' => 'Stok YÃ¶netimi', 'tabs' => 'stock', 'datasource' => 'stock_critical'],
            'stock_warehouse' => ['eyebrow' => 'Stok YÃ¶netimi', 'tabs' => 'stock', 'datasource' => 'stock_warehouse'],
            'orders' => ['eyebrow' => 'SipariÅŸ YÃ¶netimi', 'tabs' => 'orders', 'datasource' => 'orders_alinan'],
            'orders_alinan' => ['eyebrow' => 'SipariÅŸ YÃ¶netimi', 'tabs' => 'orders', 'datasource' => 'orders_alinan'],
            'orders_verilen' => ['eyebrow' => 'SipariÅŸ YÃ¶netimi', 'tabs' => 'orders', 'datasource' => 'orders_verilen'],
            'cari' => ['eyebrow' => 'Musteri Yonetimi', 'tabs' => 'cari', 'datasource' => 'customers_list'],
            'cari_balance' => ['eyebrow' => 'Musteri Yonetimi', 'tabs' => 'cari', 'datasource' => 'customers_balance'],
            'cari_detail' => ['eyebrow' => 'Musteri Yonetimi', 'tabs' => 'cari', 'datasource' => 'customer_statement'],
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
                        'previewNotice' => 'CanlÄ± veri kaynaÄŸÄ± henÃ¼z baÄŸlanmadÄ±.',
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
                    'layout_json' => ['heroEyebrow' => 'YÃ¶netim modÃ¼lÃ¼'],
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
                            ], true),
                            'proforma' => in_array($resource->code, [
                                'dashboard',
                                'customers',
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
