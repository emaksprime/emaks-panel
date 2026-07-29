<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @php
            $assetVersion = '20260511';
            $panelPublicUrl = \App\Support\PartnerPortalPublicUrl::panelBaseUrl(request());
            $ogImageUrl = "{$panelPublicUrl}/og-emaks-prime.png?v={$assetVersion}";
        @endphp
        <meta name="description" content="Emaks Prime operasyon ve yönetim paneli">
        <meta property="og:title" content="Emaks Prime Operasyon Paneli">
        <meta property="og:description" content="Emaks Prime operasyon ve yönetim paneli">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ $panelPublicUrl }}">
        <meta property="og:image" content="{{ $ogImageUrl }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="Emaks Prime Operasyon Paneli">
        <meta name="twitter:description" content="Emaks Prime operasyon ve yönetim paneli">
        <meta name="twitter:image" content="{{ $ogImageUrl }}">
        <style>
            html {
                background-color: #f6f7fb;
            }
        </style>

        <link rel="icon" href="/favicon.ico?v={{ $assetVersion }}" sizes="any">
        <link rel="icon" href="/favicon.svg?v={{ $assetVersion }}" type="image/svg+xml">
        <link rel="icon" href="/android-chrome-192x192.png?v={{ $assetVersion }}" type="image/png" sizes="192x192">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v={{ $assetVersion }}">
        <link rel="manifest" href="/site.webmanifest?v={{ $assetVersion }}">
        <meta name="theme-color" content="#0d4e6f">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="min-h-screen font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
