<?php

namespace App\Support;

class PartnerPortalPublicUrl
{
    public static function baseUrl(): string
    {
        $configured = trim((string) config('services.partner_portal.public_url', ''));
        $fallback = trim((string) config('app.url', ''));

        return rtrim($configured !== '' ? $configured : $fallback, '/');
    }

    public static function route(string $name, array $parameters = []): string
    {
        return self::url(route($name, $parameters, false));
    }

    public static function url(string $path): string
    {
        $base = self::baseUrl();

        if ($base === '') {
            return $path;
        }

        return $base.'/'.ltrim($path, '/');
    }

    public static function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }
}
