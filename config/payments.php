<?php

return [
    'provider' => env('PAYMENT_PROVIDER', 'fake'),
    'environment' => env('PAYMENT_ENV', env('APP_ENV') === 'production' ? 'live' : 'local'),
    'enable_fake_approve' => env('PAYMENTS_ENABLE_FAKE_APPROVE', false),
    'default_mode' => env('PAYMENT_PROVIDER_DEFAULT_MODE', 'fake'),
    'provider_name' => env('PAYMENT_PROVIDER_NAME', 'iyzico'),
    'provider_transport' => env('PAYMENT_PROVIDER_TRANSPORT', 'direct_laravel'),
    'real_provider_enabled' => env('PAYMENT_REAL_PROVIDER_ENABLED', false),
    'gateway' => [
        'url' => env('PAYMENT_PROVIDER_GATEWAY_URL'),
        'token' => env('PAYMENT_PROVIDER_GATEWAY_TOKEN'),
        'mode' => env('PAYMENT_PROVIDER_GATEWAY_MODE', env('PAYMENT_ENV', 'sandbox')),
        'webhook_path' => env('PAYMENT_PROVIDER_GATEWAY_WEBHOOK_PATH', 'panel-payment-provider-iyzico-runner-v1'),
        'health_verified' => env('PAYMENT_PROVIDER_GATEWAY_HEALTH_VERIFIED', false),
        'http_enabled' => env('PAYMENT_PROVIDER_GATEWAY_HTTP_ENABLED', false),
        'credentials_ready' => env('PAYMENT_PROVIDER_GATEWAY_CREDENTIALS_READY', false),
        'credential_source' => env('PAYMENT_PROVIDER_CREDENTIAL_SOURCE', 'disabled'),
        'n8n_env_credentials_ready' => env('PAYMENT_PROVIDER_N8N_ENV_CREDENTIALS_READY', false),
        'dry_run' => env('PAYMENT_PROVIDER_GATEWAY_DRY_RUN', false),
        'no_send' => env('PAYMENT_PROVIDER_GATEWAY_NO_SEND', false),
        'allow_provider_send' => env('PAYMENT_PROVIDER_GATEWAY_ALLOW_SEND', false),
    ],

    'iyzico' => [
        'callback_url' => env('IYZICO_CALLBACK_URL'),
        'live_send_approved' => env('IYZICO_LIVE_SEND_APPROVED', false),
        'outbound_ip' => env('IYZICO_OUTBOUND_IP'),
        'ip_whitelist_confirmed' => env('IYZICO_IP_WHITELIST_CONFIRMED', false),
        'timeout' => env('IYZICO_HTTP_TIMEOUT', 10),
        'connect_timeout' => env('IYZICO_HTTP_CONNECT_TIMEOUT', 3),
        'link_image_base64' => env('IYZICO_LINK_IMAGE_BASE64'),
    ],
];
