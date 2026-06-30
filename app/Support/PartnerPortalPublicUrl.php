<?php

namespace App\Support;

use Illuminate\Http\Request;
use InvalidArgumentException;

class PartnerPortalPublicUrl
{
    public static function baseUrl(): string
    {
        $configured = trim((string) config('services.partner_portal.public_url', ''));
        $fallback = trim((string) config('app.url', ''));

        return self::normalizeBaseUrl($configured !== '' ? $configured : $fallback) ?? '';
    }

    public static function publicAppBaseUrl(): string
    {
        return self::guardedConfiguredBaseUrl('public app', [
            'services.public_urls.app_url',
            'app.url',
        ]);
    }

    public static function panelBaseUrl(?Request $request = null): string
    {
        $requestBaseUrl = self::localRequestBaseUrl($request);

        if ($requestBaseUrl !== null) {
            return $requestBaseUrl;
        }

        return self::normalizeBaseUrl((string) config('panel.public_url'))
            ?? self::normalizeBaseUrl((string) config('app.url'))
            ?? '';
    }

    public static function panelApiBaseUrl(?Request $request = null): string
    {
        $requestBaseUrl = self::localRequestBaseUrl($request);

        if ($requestBaseUrl !== null) {
            return self::join($requestBaseUrl, '/api');
        }

        return self::normalizeBaseUrl((string) config('panel.api_base_url'))
            ?? self::join(self::panelBaseUrl($request), '/api');
    }

    public static function panelWebhookBaseUrl(?Request $request = null): string
    {
        $requestBaseUrl = self::localRequestBaseUrl($request);

        if ($requestBaseUrl !== null) {
            return self::join($requestBaseUrl, '/api/workflows');
        }

        return self::normalizeBaseUrl((string) config('panel.webhook_base_url'))
            ?? self::join(self::panelBaseUrl($request), '/api/workflows');
    }

    public static function panelHost(?Request $request = null): ?string
    {
        $host = parse_url(self::panelBaseUrl($request), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    public static function qrBaseUrl(?string $overrideBaseUrl = null): string
    {
        if ($overrideBaseUrl !== null && $overrideBaseUrl !== '') {
            return self::guardedBaseUrl('QR', $overrideBaseUrl);
        }

        return self::guardedConfiguredBaseUrl('QR', [
            'services.public_urls.qr_base_url',
            'services.public_urls.app_url',
            'app.url',
        ]);
    }

    public static function paymentBaseUrl(): string
    {
        return self::guardedConfiguredBaseUrl('payment', [
            'services.public_urls.payment_base_url',
            'services.public_urls.app_url',
            'app.url',
        ]);
    }

    public static function route(string $name, array $parameters = []): string
    {
        return self::url(route($name, $parameters, false));
    }

    public static function url(string $path): string
    {
        $base = self::baseUrl();

        return self::join($base, $path);
    }

    public static function qrUrl(string $path, ?string $overrideBaseUrl = null): string
    {
        return self::join(self::qrBaseUrl($overrideBaseUrl), $path);
    }

    public static function paymentUrl(string $path): string
    {
        return self::join(self::paymentBaseUrl(), $path);
    }

    public static function localRequestBaseUrl(?Request $request = null): ?string
    {
        if (! app()->environment('local', 'testing')) {
            return null;
        }

        $request ??= request();

        return self::normalizeBaseUrl($request->getSchemeAndHttpHost());
    }

    public static function normalizeBaseUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $normalized = $scheme.'://'.strtolower((string) $parts['host']);

        if (! empty($parts['port'])) {
            $normalized .= ':'.(int) $parts['port'];
        }

        if (! empty($parts['path'])) {
            $normalized .= '/'.trim((string) $parts['path'], '/');
        }

        return rtrim($normalized, '/');
    }

    public static function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '::1', '0.0.0.0'], true)) {
            return true;
        }

        if (str_starts_with($host, '127.')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        if (str_starts_with($host, '10.') || str_starts_with($host, '192.168.')) {
            return true;
        }

        if (preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $host) === 1) {
            return true;
        }

        return false;
    }

    private static function join(string $base, string $path): string
    {
        if ($base === '') {
            return $path;
        }

        return $base.'/'.ltrim($path, '/');
    }

    /**
     * @param list<string> $keys
     */
    private static function guardedConfiguredBaseUrl(string $context, array $keys): string
    {
        foreach ($keys as $key) {
            $baseUrl = self::normalizeBaseUrl((string) config($key, ''));

            if ($baseUrl !== null) {
                return self::guardedBaseUrl($context, $baseUrl);
            }
        }

        if (app()->environment('production')) {
            throw new InvalidArgumentException("{$context} public URL tanımlı değil.");
        }

        return '';
    }

    private static function guardedBaseUrl(string $context, string $baseUrl): string
    {
        $normalized = self::normalizeBaseUrl($baseUrl);

        if ($normalized === null) {
            if (app()->environment('production')) {
                throw new InvalidArgumentException("{$context} public URL geçersiz.");
            }

            return '';
        }

        if (app()->environment('production') && self::isLocalUrl($normalized)) {
            throw new InvalidArgumentException("Production {$context} public URL localhost veya özel ağ IP'si olamaz.");
        }

        return $normalized;
    }
}
