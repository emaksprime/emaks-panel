<?php

return [
    'brand' => env('PANEL_BRAND', 'Emaks Prime Panel'),
    'default_registration_role' => env('PANEL_DEFAULT_ROLE', 'admin'),
    'public_url' => rtrim(env('PANEL_PUBLIC_URL', env('APP_URL', 'https://dashboard.emaksprime.com.tr')), '/'),
    'api_base_url' => rtrim(env('PANEL_API_BASE_URL', rtrim(env('PANEL_PUBLIC_URL', env('APP_URL', 'https://dashboard.emaksprime.com.tr')), '/').'/api'), '/'),
    'webhook_base_url' => rtrim(env('PANEL_WEBHOOK_BASE_URL', rtrim(env('PANEL_PUBLIC_URL', env('APP_URL', 'https://dashboard.emaksprime.com.tr')), '/').'/api/workflows'), '/'),
    'workflow_urls' => [
        'sales_main' => env('PANEL_WORKFLOW_SALES_MAIN_URL', rtrim(env('PANEL_PUBLIC_URL', env('APP_URL', 'https://dashboard.emaksprime.com.tr')), '/').'/api/data/sales-main'),
        'sales_online' => env('PANEL_WORKFLOW_SALES_ONLINE_URL', rtrim(env('PANEL_PUBLIC_URL', env('APP_URL', 'https://dashboard.emaksprime.com.tr')), '/').'/api/workflows/sales-online/webhook'),
        'sales_bayi' => env('PANEL_WORKFLOW_SALES_BAYI_URL', rtrim(env('PANEL_PUBLIC_URL', env('APP_URL', 'https://dashboard.emaksprime.com.tr')), '/').'/api/workflows/sales-bayi/webhook'),
        'stock' => env('PANEL_WORKFLOW_STOCK_URL', rtrim(env('PANEL_PUBLIC_URL', env('APP_URL', 'https://dashboard.emaksprime.com.tr')), '/').'/api/workflows/stock/webhook'),
        'orders' => env('PANEL_WORKFLOW_ORDERS_URL', rtrim(env('PANEL_PUBLIC_URL', env('APP_URL', 'https://dashboard.emaksprime.com.tr')), '/').'/api/workflows/orders/webhook'),
    ],
];
