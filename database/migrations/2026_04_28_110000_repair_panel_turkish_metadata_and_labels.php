<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateByCode('panel.roles', [
            'admin' => ['name' => 'Admin', 'description' => 'Tam yetkili sistem yöneticisi'],
            'manager' => ['name' => 'Yönetici', 'description' => 'Yönetim görünümü ve geniş panel yetkisi'],
            'sales' => ['name' => 'Satış', 'description' => 'Satış ekip erişimi'],
            'stock' => ['name' => 'Stok', 'description' => 'Stok ekip erişimi'],
            'orders' => ['name' => 'Sipariş', 'description' => 'Sipariş ekip erişimi'],
            'customer' => ['name' => 'Müşteri', 'description' => 'Müşteri/CRM ekip erişimi'],
            'proforma' => ['name' => 'Proforma', 'description' => 'Proforma operasyon erişimi'],
            'viewer' => ['name' => 'Görüntüleyici', 'description' => 'Sadece atanmış kaynakları görüntüler'],
        ]);

        $this->updateByCode('panel.resources', [
            'dashboard' => ['name' => 'Yönetim Özeti'],
            'sales_main' => ['name' => 'Genel Satış'],
            'sales_main_all' => ['name' => 'Satış Yönetimi Tüm Kapsamlar'],
            'sales_representatives' => ['name' => 'Satış Temsilcisi Görünümü'],
            'orders' => ['name' => 'Sipariş Yönetimi'],
            'orders_alinan' => ['name' => 'Alınan Siparişler'],
            'orders_verilen' => ['name' => 'Verilen Siparişler'],
            'cari' => ['name' => 'Müşteri Listesi'],
            'cari_balance' => ['name' => 'Müşteri Bakiyesi'],
            'cari_detail' => ['name' => 'Müşteri Detayı ve Ekstre'],
            'cari_bilgi' => ['name' => 'Müşteri Bilgi Sayfa Erişimi'],
            'finance_cari_bilgi' => ['name' => 'Müşteri Bilgi'],
            'finance_cari_bilgi_all' => ['name' => 'Müşteri Bilgi Tüm Müşteriler'],
            'cari_bilgi_dashboard' => ['name' => 'Müşteri Bilgi Veri Kaynağı'],
            'admin_panel' => ['name' => 'Yönetim Paneli'],
            'admin_users' => ['name' => 'Kullanıcılar'],
            'admin_datasources' => ['name' => 'Veri Kaynakları'],
            'customers' => ['name' => 'Müşteri Yönetimi'],
            'user_admin' => ['name' => 'Kullanıcı Yönetimi'],
            'data_sources' => ['name' => 'Veri Kaynakları Yönetimi'],
            'sales_main_dashboard' => ['name' => 'Satış Yönetimi Veri Kaynağı'],
            'stock_dashboard' => ['name' => 'Stok Veri Kaynağı'],
            'sales_bayi_proje_detail' => ['name' => 'Bayi / Proje Veri Kaynağı'],
            'sales_online_perakende_detail' => ['name' => 'Online / Perakende Veri Kaynağı'],
            'orders_dashboard' => ['name' => 'Sipariş Veri Kaynağı'],
            'orders_alinan' => ['name' => 'Alınan Sipariş Veri Kaynağı'],
            'orders_verilen' => ['name' => 'Verilen Sipariş Veri Kaynağı'],
            'cari_list' => ['name' => 'Müşteri Liste Veri Kaynağı'],
            'cari_statement' => ['name' => 'Müşteri Ekstre Veri Kaynağı'],
            'customers_list' => ['name' => 'Müşteri Liste Veri Kaynağı'],
            'customers_balance' => ['name' => 'Müşteri Bakiye Veri Kaynağı'],
            'customer_detail' => ['name' => 'Müşteri Detay Veri Kaynağı'],
            'customer_documents' => ['name' => 'Müşteri Evrak Veri Kaynağı'],
            'customer_statement' => ['name' => 'Müşteri Ekstre Veri Kaynağı'],
            'proforma_list' => ['name' => 'Proforma Liste Veri Kaynağı'],
            'proforma_create' => ['name' => 'Proforma Oluştur'],
            'proforma_edit' => ['name' => 'Proforma Düzenle'],
            'proforma_customer_search' => ['name' => 'Proforma Müşteri Arama'],
            'proforma_items' => ['name' => 'Proforma Satır Veri Kaynağı'],
            'proforma_discount_defs' => ['name' => 'Proforma İskonto Tanımları'],
        ]);

        $this->updateByCode('panel.menu_groups', [
            'executive' => ['name' => 'Yönetim'],
            'sales' => ['name' => 'Satış Yönetimi'],
            'stock' => ['name' => 'Stok Yönetimi'],
            'orders' => ['name' => 'Sipariş Yönetimi'],
            'cari' => ['name' => 'Müşteri Yönetimi'],
            'administration' => ['name' => 'Sistem Yönetimi'],
        ]);

        $this->updateByCode('panel.pages', [
            'dashboard' => ['name' => 'Yönetim Özeti', 'description' => 'Genel yönetim ve metadata özet görünümü'],
            'sales_main' => ['name' => 'Genel Satış', 'description' => 'Ana satış dashboardu ve yönetim kapsamları'],
            'sales_online' => ['name' => 'Online / Perakende', 'description' => 'Online ve perakende satış görünümü'],
            'sales_bayi' => ['name' => 'Bayi / Proje', 'description' => 'Bayi ve proje satış görünümü'],
            'sales_representatives' => ['name' => 'Satış Temsilcisi Görünümü', 'description' => 'Temsilci bazlı satış kapsamları ana Satış Yönetimi kapsam filtresinden yönetilir.'],
            'stock' => ['name' => 'Stok Listesi', 'description' => 'Stok listesi ve ürün izleme ekranı.'],
            'stock_critical' => ['name' => 'Kritik Stoklar', 'description' => 'Kritik stok seviyeleri ve uyarı listesi burada hazırlanır.'],
            'stock_warehouse' => ['name' => 'Depo / Raf Durumu', 'description' => 'Depo, raf ve lokasyon durumu bu modül altında izlenir.'],
            'orders' => ['name' => 'Sipariş Yönetimi', 'description' => 'Sipariş operasyonları için genel görünüm.'],
            'orders_alinan' => ['name' => 'Alınan Siparişler', 'description' => 'Müşterilerden alınan siparişler bu ekranda listelenecek.'],
            'orders_verilen' => ['name' => 'Verilen Siparişler', 'description' => 'Tedarikçi ve üretim tarafına verilen siparişler burada izlenecek.'],
            'cari' => ['name' => 'Müşteri Yönetimi', 'description' => 'Müşteri, bayi ve hesap bilgileri bu modül altında yönetilir.'],
            'cari_balance' => ['name' => 'Müşteri Bakiyesi', 'description' => 'Müşteri bakiye izleme ve risk görünümü.'],
            'cari_detail' => ['name' => 'Müşteri Detayı ve Ekstre', 'description' => 'Müşteri detay ve ekstre özetleri.'],
            'cari_bilgi' => ['name' => 'Müşteri Bilgi', 'description' => 'Müşteri bakiye, açık sipariş ve genel durum takibi.'],
            'finance_cari_durum' => ['name' => 'Müşteri Durumu', 'description' => 'Müşteri durumu ve finans yönetim görünümü'],
            'admin_panel' => ['name' => 'Yönetim Paneli', 'description' => 'Panel yönetim merkezi'],
            'admin_users' => ['name' => 'Kullanıcılar', 'description' => 'Kullanıcı, rol ve erişim yönetimi'],
            'admin_pages' => ['name' => 'Sayfalar', 'description' => 'Menü, route ve sayfa konfigürasyonu'],
            'admin_datasources' => ['name' => 'Veri Kaynakları', 'description' => 'MSSQL, Postgres ve workflow metadata yönetimi'],
            'admin_logs' => ['name' => 'Loglar', 'description' => 'Aksiyon ve audit log kayıtları'],
            'proforma_create' => ['name' => 'Proforma Oluştur', 'description' => 'Yeni proforma taslakları için hazırlık ekranı.'],
            'proforma_edit' => ['name' => 'Proforma Düzenle', 'description' => 'Proforma düzenleme akışı için hazırlık ekranı.'],
        ]);

        $this->updatePageMenu();
        $this->updateButtons();
        $this->updateDataSources();
        $this->updateSalesMainConfig();
        $this->updateModuleConfigs();
    }

    public function down(): void
    {
        // Production metadata repair migration; intentionally no-op.
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function updateByCode(string $table, array $rows): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($rows as $code => $values) {
            DB::table($table)->where('code', $code)->update($values);
        }
    }

    private function updatePageMenu(): void
    {
        if (! Schema::hasTable('panel.page_menu') || ! Schema::hasTable('panel.pages')) {
            return;
        }

        foreach ([
            'dashboard' => 'Yönetim Özeti',
            'sales_main' => 'Genel Satış',
            'orders' => 'Sipariş Yönetimi',
            'orders_alinan' => 'Alınan Siparişler',
            'orders_verilen' => 'Verilen Siparişler',
            'cari' => 'Müşteri Listesi',
            'cari_balance' => 'Müşteri Bakiyesi',
            'cari_detail' => 'Müşteri Detayı / Ekstre',
            'cari_bilgi' => 'Müşteri Bilgi',
            'finance_cari_durum' => 'Müşteri Durumu',
            'admin_panel' => 'Yönetim Paneli',
            'admin_users' => 'Kullanıcılar',
            'admin_datasources' => 'Veri Kaynakları',
            'proforma_create' => 'Proforma Oluştur',
            'proforma_edit' => 'Proforma Düzenle',
        ] as $pageCode => $label) {
            $pageId = DB::table('panel.pages')->where('code', $pageCode)->value('id');

            if ($pageId) {
                DB::table('panel.page_menu')->where('page_id', $pageId)->update(['label' => $label]);
            }
        }
    }

    private function updateButtons(): void
    {
        $this->updateByCode('panel.buttons', [
            'admin_panel_users' => ['label' => 'Kullanıcılara Git'],
            'admin_panel_datasources' => ['label' => 'Veri Kaynaklarını Yönet'],
        ]);
    }

    private function updateDataSources(): void
    {
        $this->updateByCode('panel.data_sources', [
            'sales_main_dashboard' => ['name' => 'Satış Yönetimi Dashboard'],
            'sales_online_perakende_detail' => ['name' => 'Online / Perakende Detay'],
            'sales_bayi_proje_detail' => ['name' => 'Bayi / Proje Detay'],
            'stock_dashboard' => ['name' => 'Stok Dashboard'],
            'orders_alinan' => ['name' => 'Alınan Siparişler'],
            'orders_verilen' => ['name' => 'Verilen Siparişler'],
            'customers_list' => ['name' => 'Müşteri Listesi'],
            'customers_balance' => ['name' => 'Müşteri Bakiye Özeti'],
            'customer_detail' => ['name' => 'Müşteri Detay'],
            'customer_documents' => ['name' => 'Müşteri Evrak Detay'],
            'customer_statement' => ['name' => 'Müşteri Ekstre'],
            'cari_bilgi_dashboard' => ['name' => 'Müşteri Bilgi Dashboard', 'description' => 'Müşteri Bilgi ekranı için güvenli servis metadata kaydı.'],
            'proforma_customer_search' => ['name' => 'Proforma Müşteri Arama'],
            'proforma_items' => ['name' => 'Proforma Satırları'],
            'proforma_discount_defs' => ['name' => 'Proforma İskonto Tanımları'],
        ]);
    }

    private function updateSalesMainConfig(): void
    {
        if (! Schema::hasTable('panel.page_configs') || ! Schema::hasTable('panel.data_sources')) {
            return;
        }

        $dataSourceId = DB::table('panel.data_sources')->where('code', 'sales_main_dashboard')->value('id');

        DB::table('panel.page_configs')->where('page_code', 'sales_main')->update([
            'layout_json' => json_encode([
                'heroEyebrow' => 'Satış kontrol merkezi',
                'previewNotice' => 'Önizleme verisi; canlı endpoint henüz bağlanmadı.',
                'moduleTabs' => [
                    ['label' => 'Tümü', 'href' => '/sales/main'],
                    ['label' => 'Ümit Yıldız', 'href' => '/sales/main'],
                    ['label' => 'Salih İmal', 'href' => '/sales/main'],
                    ['label' => 'Online / Perakende', 'href' => '/sales/online'],
                    ['label' => 'Bayi / Proje', 'href' => '/sales/bayi'],
                ],
                'topNav' => $this->topNav(),
            ], JSON_UNESCAPED_UNICODE),
            'filters_json' => json_encode([
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
                    ['key' => 'online-perakende', 'label' => 'Online / Perakende', 'repCode' => null, 'allowAll' => false, 'salesView' => 'kendi', 'navigateTo' => '/sales/online', 'note' => 'Online satış workflow görünümü'],
                    ['key' => 'bayi-proje', 'label' => 'Bayi / Proje', 'repCode' => null, 'allowAll' => false, 'salesView' => 'kendi', 'navigateTo' => '/sales/bayi', 'note' => 'Bayi satış workflow görünümü'],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'datasource_id' => $dataSourceId,
        ]);
    }

    private function updateModuleConfigs(): void
    {
        if (! Schema::hasTable('panel.page_configs')) {
            return;
        }

        foreach ([
            'cari' => ['eyebrow' => 'Müşteri Yönetimi', 'tabs' => 'cari'],
            'cari_balance' => ['eyebrow' => 'Müşteri Yönetimi', 'tabs' => 'cari'],
            'cari_detail' => ['eyebrow' => 'Müşteri Yönetimi', 'tabs' => 'cari'],
            'cari_bilgi' => ['eyebrow' => 'Müşteri Yönetimi', 'tabs' => 'cari'],
            'sales_online' => ['eyebrow' => 'Satış Yönetimi', 'tabs' => 'sales'],
            'sales_bayi' => ['eyebrow' => 'Satış Yönetimi', 'tabs' => 'sales'],
            'stock' => ['eyebrow' => 'Stok Yönetimi', 'tabs' => 'stock'],
            'stock_critical' => ['eyebrow' => 'Stok Yönetimi', 'tabs' => 'stock'],
            'stock_warehouse' => ['eyebrow' => 'Stok Yönetimi', 'tabs' => 'stock'],
            'orders' => ['eyebrow' => 'Sipariş Yönetimi', 'tabs' => 'orders'],
            'orders_alinan' => ['eyebrow' => 'Sipariş Yönetimi', 'tabs' => 'orders'],
            'orders_verilen' => ['eyebrow' => 'Sipariş Yönetimi', 'tabs' => 'orders'],
        ] as $pageCode => $config) {
            DB::table('panel.page_configs')->where('page_code', $pageCode)->update([
                'layout_json' => json_encode([
                    'heroEyebrow' => $config['eyebrow'],
                    'previewNotice' => 'Canlı veri kaynağı henüz bağlanmadı.',
                    'moduleTabs' => $this->tabs()[$config['tabs']] ?? [],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        foreach (['admin_panel', 'admin_users', 'admin_pages', 'admin_datasources', 'admin_logs'] as $pageCode) {
            DB::table('panel.page_configs')->where('page_code', $pageCode)->update([
                'layout_json' => json_encode(['heroEyebrow' => 'Yönetim modülü'], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /**
     * @return array<int, array{key: string, label: string, href: string}>
     */
    private function topNav(): array
    {
        return [
            ['key' => 'sales', 'label' => 'Satış Yönetimi', 'href' => '/sales/main'],
            ['key' => 'stock', 'label' => 'Stok Yönetimi', 'href' => '/stock'],
            ['key' => 'orders', 'label' => 'Sipariş Yönetimi', 'href' => '/orders'],
            ['key' => 'cari', 'label' => 'Müşteri Yönetimi', 'href' => '/cari'],
            ['key' => 'proforma', 'label' => 'Proforma', 'href' => '/proforma'],
        ];
    }

    /**
     * @return array<string, array<int, array{label: string, href: string}>>
     */
    private function tabs(): array
    {
        return [
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
                ['label' => 'Depo / Raf Durumu', 'href' => '/stock/warehouse'],
            ],
            'orders' => [
                ['label' => 'Sipariş Yönetimi', 'href' => '/orders'],
                ['label' => 'Alınan Siparişler', 'href' => '/orders/alinan'],
                ['label' => 'Verilen Siparişler', 'href' => '/orders/verilen'],
            ],
            'cari' => [
                ['label' => 'Müşteri Listesi', 'href' => '/cari'],
                ['label' => 'Müşteri Bakiyesi', 'href' => '/cari/balance'],
                ['label' => 'Müşteri Detayı', 'href' => '/cari/detail'],
                ['label' => 'Müşteri Ekstre', 'href' => '/cari/detail'],
            ],
        ];
    }
};
