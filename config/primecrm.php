<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PrimeCRM External Service Integration
    |--------------------------------------------------------------------------
    |
    | PrimeCRM is treated as a separate Coolify-deployable service. The Laravel
    | panel does not embed ASP.NET/IIS files; it only knows the public service
    | URL and the module path map needed for navigation and future API bridges.
    |
    */
    'enabled' => (bool) env('PRIMECRM_ENABLED', false),
    'base_url' => rtrim((string) env('PRIMECRM_BASE_URL', ''), '/'),
    'launch_mode' => env('PRIMECRM_LAUNCH_MODE', 'external'),
    'modules' => [
        'sales_main' => ['label' => 'Genel Satış', 'path' => '/Sales', 'capability' => 'Sales'],
        'sales_online' => ['label' => 'Online / Perakende', 'path' => '/Sales', 'capability' => 'Sales'],
        'sales_bayi' => ['label' => 'Bayi / Proje', 'path' => '/Sales', 'capability' => 'Sales'],
        'sales_representatives' => ['label' => 'Satış Temsilcisi Görünümü', 'path' => '/Sales', 'capability' => 'Sales'],
        'stock' => ['label' => 'Stok Listesi', 'path' => '/Stock', 'capability' => 'Stock'],
        'stock_critical' => ['label' => 'Kritik Stoklar', 'path' => '/Stock', 'capability' => 'Stock'],
        'stock_warehouse' => ['label' => 'Depo / Raf Durumu', 'path' => '/Stock', 'capability' => 'Stock'],
        'orders' => ['label' => 'Sipariş Yönetimi', 'path' => '/Orders', 'capability' => 'Orders'],
        'orders_alinan' => ['label' => 'Alınan Siparişler', 'path' => '/Orders/Alinan', 'capability' => 'Orders'],
        'orders_verilen' => ['label' => 'Verilen Siparişler', 'path' => '/Orders/Verilen', 'capability' => 'Orders'],
        'cari' => ['label' => 'Cari Liste', 'path' => '/Cari', 'capability' => 'CariSearch'],
        'cari_balance' => ['label' => 'Cari Bakiye', 'path' => '/CariBalance', 'capability' => 'CariBalance'],
        'cari_detail' => ['label' => 'Cari Detay / Ekstre', 'path' => '/Cari', 'capability' => 'CariSearch'],
        'proforma' => ['label' => 'Proforma Liste', 'path' => '/Proforma', 'capability' => 'Proforma'],
        'proforma_create' => ['label' => 'Proforma Oluştur', 'path' => '/Proforma/Create', 'capability' => 'Proforma'],
        'proforma_detail' => ['label' => 'Proforma Detay', 'path' => '/Proforma/Detail', 'capability' => 'Proforma'],
        'proforma_edit' => ['label' => 'Proforma Düzenle', 'path' => '/Proforma/Edit', 'capability' => 'Proforma'],
    ],
];
