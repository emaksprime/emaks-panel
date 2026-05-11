<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AuditLogger
{
    /**
     * @var array<int, string>
     */
    private array $sensitiveFragments = [
        'password',
        'passwd',
        'token',
        'secret',
        'api_key',
        'apikey',
        'cookie',
        'csrf',
        '_token',
        'session',
        'authorization',
        'bearer',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function log(?User $user, string $action, array $payload = [], ?Request $request = null): void
    {
        try {
            $payload = $this->normalizedPayload($action, $payload, $request);

            if ($this->shouldSkipDuplicate($user, $action, $payload)) {
                return;
            }

            AuditLog::query()->create([
                'user_id' => $user?->id,
                'action' => $action,
                'payload' => $payload,
                'created_at' => now('UTC'),
            ]);
        } catch (Throwable) {
            // Audit logging should never block panel rendering or authentication flows.
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizedPayload(string $action, array $payload, ?Request $request): array
    {
        $sanitized = $this->sanitize($payload);
        $agent = $this->parseUserAgent($request?->userAgent());

        return array_filter([
            ...$sanitized,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'device_type' => $agent['device_type'],
            'browser' => $agent['browser'],
            'browser_version' => $agent['browser_version'],
            'platform' => $agent['platform'],
            'path' => $sanitized['path'] ?? $request?->path(),
            'method' => $sanitized['method'] ?? $request?->method(),
            'route_name' => $request?->route()?->getName(),
            'safe_url' => $this->safeUrl($request),
            'page' => $sanitized['page'] ?? $this->pageFromRequest($request),
            'action_label' => $sanitized['action_label'] ?? $this->actionLabel($action),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitize(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            $keyString = (string) $key;

            if ($this->isSensitiveKey($keyString)) {
                $clean[$keyString] = '***';

                continue;
            }

            if (is_array($value)) {
                $clean[$keyString] = $this->sanitize($value);

                continue;
            }

            if (is_string($value) && strlen($value) > 2000) {
                $clean[$keyString] = substr($value, 0, 2000).'...';

                continue;
            }

            $clean[$keyString] = $value;
        }

        return $clean;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach ($this->sensitiveFragments as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{device_type: string, browser: string, browser_version: string|null, platform: string}
     */
    private function parseUserAgent(?string $userAgent): array
    {
        $userAgent = (string) $userAgent;
        $platform = match (true) {
            stripos($userAgent, 'Windows') !== false => 'Windows',
            stripos($userAgent, 'Mac OS') !== false || stripos($userAgent, 'Macintosh') !== false => 'macOS',
            stripos($userAgent, 'Android') !== false => 'Android',
            stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPad') !== false => 'iOS',
            stripos($userAgent, 'Linux') !== false => 'Linux',
            default => 'Bilinmiyor',
        };
        $device = match (true) {
            stripos($userAgent, 'Mobile') !== false || stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'Android') !== false => 'Mobil',
            stripos($userAgent, 'Tablet') !== false || stripos($userAgent, 'iPad') !== false => 'Tablet',
            $userAgent !== '' => 'Masaüstü',
            default => 'Bilinmiyor',
        };

        foreach ([
            'Edge' => '/Edg\/([0-9.]+)/',
            'Chrome' => '/Chrome\/([0-9.]+)/',
            'Firefox' => '/Firefox\/([0-9.]+)/',
            'Safari' => '/Version\/([0-9.]+).*Safari/',
        ] as $browser => $pattern) {
            if (preg_match($pattern, $userAgent, $matches) === 1) {
                return [
                    'device_type' => $device,
                    'browser' => $browser,
                    'browser_version' => $matches[1] ?? null,
                    'platform' => $platform,
                ];
            }
        }

        return [
            'device_type' => $device,
            'browser' => $userAgent !== '' ? 'Diğer' : 'Bilinmiyor',
            'browser_version' => null,
            'platform' => $platform,
        ];
    }

    private function safeUrl(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $query = $this->sanitize($request->query());

        return $request->url().($query === [] ? '' : '?'.http_build_query($query));
    }

    private function pageFromRequest(?Request $request): ?string
    {
        $path = $request?->path();

        if ($path === null) {
            return null;
        }

        return match (true) {
            str_contains($path, 'sales') => 'sales_main',
            str_contains($path, 'stock') => 'stock',
            str_contains($path, 'orders') => 'orders',
            str_contains($path, 'admin/logs') => 'admin_logs',
            str_contains($path, 'admin/users') => 'admin_users',
            str_contains($path, 'admin/datasources') => 'admin_datasources',
            str_contains($path, 'admin/pages') => 'admin_pages',
            str_contains($path, 'admin') => 'admin_panel',
            default => null,
        };
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'panel.page.view' => 'Sayfa görüntüledi',
            'admin.user.save' => 'Kullanıcı kaydetti',
            'admin.user.clone' => 'Kullanıcı kopyaladı',
            'admin.datasource.save' => 'Veri kaynağı kaydetti',
            'admin.datasource.test' => 'Veri kaynağı test etti',
            'admin.page.save' => 'Sayfa kaydetti',
            'sales.customer.search' => 'Müşteri aradı',
            'sales.data.view', 'sales.data.filter' => 'Satış verisi filtreledi',
            default => $action,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shouldSkipDuplicate(?User $user, string $action, array $payload): bool
    {
        if (! in_array($action, ['sales.customer.search', 'sales.data.filter'], true)) {
            return false;
        }

        $fingerprint = sha1(json_encode([
            'user_id' => $user?->id,
            'action' => $action,
            'search' => $payload['search'] ?? $payload['query'] ?? '',
            'customer_filter' => $payload['customer_filter'] ?? $payload['cari_filter'] ?? '',
            'product_filter' => $payload['product_filter'] ?? '',
            'scope_key' => $payload['scope_key'] ?? '',
            'brand_filter' => $payload['brand_filter'] ?? '',
            'category_filter' => $payload['category_filter'] ?? '',
        ], JSON_THROW_ON_ERROR));

        $cacheKey = 'audit-dedupe:'.$fingerprint;

        if (Cache::has($cacheKey)) {
            return true;
        }

        Cache::put($cacheKey, true, now()->addSeconds(30));

        return false;
    }
}
