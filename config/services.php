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
        'invoice_serials_mode' => env('TECHNICAL_SERVICE_INVOICE_SERIALS_MODE', 'disabled'),
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
