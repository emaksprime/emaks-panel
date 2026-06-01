<?php

return [
    'provider' => env('PAYMENT_PROVIDER', 'fake'),
    'environment' => env('PAYMENT_ENV', env('APP_ENV') === 'production' ? 'live' : 'local'),
    'enable_fake_approve' => env('PAYMENTS_ENABLE_FAKE_APPROVE', false),

    'iyzico' => [
        'base_url' => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
        'api_key' => env('IYZICO_API_KEY'),
        'secret_key' => env('IYZICO_SECRET_KEY'),
        'callback_url' => env('IYZICO_CALLBACK_URL'),
        'webhook_secret' => env('IYZICO_WEBHOOK_SECRET'),
    ],
];
