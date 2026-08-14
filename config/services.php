<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'google' => [
        'routes_api_key' => env('GOOGLE_ROUTES_API_KEY'),
        'geocoding_api_key' => env('GOOGLE_GEOCODING_API_KEY'),
        'places_api_key' => env('GOOGLE_PLACES_API_KEY'),
        'routes_fee_per_km' => env('TECHNICAL_SERVICE_ROUTE_FEE_PER_KM', null),
    ],

    'technical_service' => [
        'invoice_serials_mode' => env('TECHNICAL_SERVICE_INVOICE_SERIALS_MODE', 'gateway'),
        'payment_order_context_test_stock' => env('TECHNICAL_SERVICE_PAYMENT_ORDER_CONTEXT_TEST_STOCK', false),
    ],

    'mikro_api' => [
        'server_timezone' => env('MIKRO_SERVER_TIMEZONE', 'Europe/Istanbul'),
        'allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('MIKRO_ALLOWED_PRIVATE_HOSTS', '')),
        ))),
    ],

    'public_urls' => [
        'app_url' => env('PUBLIC_APP_URL'),
        'qr_base_url' => env('PUBLIC_QR_BASE_URL'),
        'payment_base_url' => env('PUBLIC_PAYMENT_BASE_URL'),
        'profiles' => [
            'uat_public' => [
                'environment' => env('PUBLIC_UAT_PROFILE_ENVIRONMENT'),
                'origin' => env('PUBLIC_UAT_PROFILE_ORIGIN'),
                'active' => env('PUBLIC_UAT_PROFILE_ACTIVE', false),
                'revision' => (int) env('PUBLIC_UAT_PROFILE_REVISION', 0),
            ],
            'production_public' => [
                'environment' => env('PUBLIC_PRODUCTION_PROFILE_ENVIRONMENT'),
                'origin' => env('PUBLIC_PRODUCTION_PROFILE_ORIGIN'),
                'active' => env('PUBLIC_PRODUCTION_PROFILE_ACTIVE', false),
                'revision' => (int) env('PUBLIC_PRODUCTION_PROFILE_REVISION', 0),
            ],
        ],
        'trusted_payment_provider_origins' => array_values(array_filter(array_map(
            static fn (string $origin): string => trim($origin),
            explode(',', (string) env('PUBLIC_PAYMENT_PROVIDER_ORIGINS', '')),
        ))),
    ],

    'partner_portal' => [
        'public_url' => env('PARTNER_PORTAL_PUBLIC_URL', env('PUBLIC_APP_URL')),
    ],

    'evolution' => [
        'n8n_webhook_url' => env('EVOLUTION_N8N_WEBHOOK_URL'),
        'test_mode' => env('EVOLUTION_TEST_MODE', true),
        'test_phone' => env('EVOLUTION_TEST_PHONE', '905467647428'),
        'real_send_enabled' => env('EVOLUTION_REAL_SEND_ENABLED', false),
        'allow_test_fixture_send' => env('EVOLUTION_ALLOW_TEST_FIXTURE_SEND', false),
        'allow_browser_smoke_send' => env('EVOLUTION_ALLOW_BROWSER_SMOKE_SEND', false),
        'allow_unit_test_http_fake' => false,
        'idempotency_window_minutes' => env('EVOLUTION_IDEMPOTENCY_WINDOW_MINUTES', 30),
        'target_min_seconds' => env('EVOLUTION_TARGET_MIN_SECONDS', 5),
        'test_phone_min_seconds' => env('EVOLUTION_TEST_PHONE_MIN_SECONDS', 20),
        'test_phone_window_minutes' => env('EVOLUTION_TEST_PHONE_WINDOW_MINUTES', 10),
        'test_phone_window_max' => env('EVOLUTION_TEST_PHONE_WINDOW_MAX', 5),
        'test_phone_daily_max' => env('EVOLUTION_TEST_PHONE_DAILY_MAX', 20),
        'customer_approval_text' => env(
            'TECHNICAL_SERVICE_CUSTOMER_APPROVAL_TEXT',
            'Montaj işleminin tamamlandığını ve montaj sonrası görünür hasar veya kusur bulunmadığını kontrol ederek onay verebilirsiniz.'
        ),
        'customer_approval_legal_note' => 'Müşteri onay metni canlıya geçmeden önce hukuk onayı gerektirir.',
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
