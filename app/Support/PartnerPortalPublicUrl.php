<?php

namespace App\Support;

use App\Services\ExternalEffects\ExternalExecutionControlPlaneService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

class PartnerPortalPublicUrl
{
    public const ENV_DEV = 'DEV';

    public const ENV_UAT = 'UAT';

    public const ENV_PROD = 'PROD';

    public const PROFILE_LOCAL = 'local_public';

    public const PROFILE_UAT = 'uat_public';

    public const PROFILE_PRODUCTION = 'production_public';

    public static function baseUrl(): string
    {
        return self::selectedOrigin('partner portal');
    }

    public static function publicAppBaseUrl(): string
    {
        return self::selectedOrigin('public app');
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
        $selected = self::selectedOrigin('QR');

        if ($overrideBaseUrl !== null && $overrideBaseUrl !== '') {
            $override = self::normalizeOrigin($overrideBaseUrl);
            $profile = self::profile();
            if ($override === null
                || ($profile['profile'] ?? null) !== 'local_public'
                || ! hash_equals($selected, $override)
            ) {
                throw new InvalidArgumentException('QR public URL override global public-origin profile ile eşleşmiyor.');
            }
        }

        return $selected;
    }

    public static function paymentBaseUrl(): string
    {
        return self::selectedOrigin('payment');
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

    /**
     * @return array<string, mixed>
     */
    public static function profile(): array
    {
        return app(ExternalExecutionControlPlaneService::class)->publicOriginProfile();
    }

    /**
     * @return array<string, mixed>
     */
    public static function resolveProfile(string $runtimeEnvironment, string $operatorMode, string $transitionState): array
    {
        $environment = self::environment($runtimeEnvironment);
        $current = self::profileFor($environment, $operatorMode, $transitionState);
        $routes = self::routeReadiness();

        return [
            ...$current,
            'runtime_environment' => $runtimeEnvironment,
            'routes' => $routes,
            'profiles' => [
                self::PROFILE_LOCAL => self::profileFor(self::ENV_DEV, ExternalExecutionControlPlaneService::MODE_LOCAL, ExternalExecutionControlPlaneService::STATE_LOCAL),
                self::PROFILE_UAT => self::profileFor(self::ENV_UAT, ExternalExecutionControlPlaneService::MODE_LIVE, ExternalExecutionControlPlaneService::STATE_LIVE),
                self::PROFILE_PRODUCTION => self::profileFor(self::ENV_PROD, ExternalExecutionControlPlaneService::MODE_LIVE, ExternalExecutionControlPlaneService::STATE_LIVE),
            ],
            'profile_fingerprint' => hash('sha256', json_encode([
                $current['environment'],
                $current['operator_mode'],
                $current['state'],
                $current['profile'],
                $current['origin'],
                $current['origin_source'],
                $current['ready'],
                $current['blocker_code'],
                $routes,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    public static function rebaseLegacyUrl(?string $storedUrl): ?string
    {
        $storedUrl = trim((string) $storedUrl);
        if ($storedUrl === '') {
            return null;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $storedUrl) === 1) {
            throw new InvalidArgumentException('[LEGACY_PUBLIC_URL_UNRESOLVABLE] Legacy public URL kontrol karakteri içeriyor.');
        }

        $parts = parse_url($storedUrl);
        if ($parts === false
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('[LEGACY_PUBLIC_URL_UNRESOLVABLE] Legacy public URL güvenli biçimde ayrıştırılamadı.');
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        if (! self::trustedPublicPath($path)) {
            if (isset($parts['scheme']) && ! self::isLocalUrl($storedUrl)) {
                return $storedUrl;
            }

            throw new InvalidArgumentException('[LEGACY_PUBLIC_URL_UNRESOLVABLE] Legacy public URL canonical public route taşımıyor.');
        }

        $rebased = self::join(self::selectedOrigin('legacy public URL'), $path);
        $query = trim((string) ($parts['query'] ?? ''));

        return $query === '' ? $rebased : $rebased.'?'.$query;
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

    public static function normalizeOrigin(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === ''
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
            || ! preg_match('#^https?://#i', $url)
        ) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || empty($parts['scheme'])
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))
        ) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '' || str_contains($host, '..')) {
            return null;
        }

        $origin = $scheme.'://'.$host;
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) {
                return null;
            }

            $origin .= ':'.$port;
        }

        return $origin;
    }

    public static function isPrivateLanOrigin(?string $url): bool
    {
        $origin = self::normalizeOrigin($url);
        if ($origin === null) {
            return false;
        }

        $host = (string) parse_url($origin, PHP_URL_HOST);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return str_starts_with($host, '10.')
            || str_starts_with($host, '192.168.')
            || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $host) === 1;
    }

    public static function isLoopbackOrigin(?string $url): bool
    {
        $origin = self::normalizeOrigin($url);
        if ($origin === null) {
            return false;
        }

        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));

        return in_array($host, ['localhost', '::1', '0.0.0.0'], true)
            || str_starts_with($host, '127.');
    }

    public static function isPublicHttpsUrl(?string $url): bool
    {
        $baseUrl = self::normalizeBaseUrl($url);

        return $baseUrl !== null
            && strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) === 'https'
            && ! self::isLocalUrl($baseUrl);
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

    private static function selectedOrigin(string $context): string
    {
        $profile = self::profile();
        $origin = self::normalizeOrigin(is_string($profile['origin'] ?? null) ? $profile['origin'] : null);
        if (! (bool) ($profile['ready'] ?? false) || $origin === null) {
            $code = is_string($profile['blocker_code'] ?? null) ? $profile['blocker_code'] : 'PUBLIC_ORIGIN_NOT_READY';

            throw new InvalidArgumentException("[{$code}] {$context} public origin profile hazır değil.");
        }

        return $origin;
    }

    private static function trustedPublicPath(string $path): bool
    {
        return preg_match('#^/service-job-confirmation/[A-Za-z0-9._~-]+(?:/(?:approve|reject))?$#', $path) === 1
            || preg_match('#^/mount-request/[A-Za-z0-9._~-]+(?:/(?:check|form|payment|multi-product|multi-products|invoice-serials/check|submit))?$#', $path) === 1
            || preg_match('#^/mount-payment/(?:fake/)?[A-Za-z0-9._~-]+(?:/(?:approve|fake-approve))?$#', $path) === 1
            || preg_match('#^/pj/[1-9][0-9]*$#', $path) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private static function profileFor(string $environment, string $operatorMode, string $transitionState): array
    {
        $profile = match ($environment) {
            self::ENV_PROD => self::PROFILE_PRODUCTION,
            self::ENV_UAT => $operatorMode === ExternalExecutionControlPlaneService::MODE_LIVE
                ? self::PROFILE_UAT
                : self::PROFILE_LOCAL,
            self::ENV_DEV => self::PROFILE_LOCAL,
            default => null,
        };
        $httpsRequired = in_array($profile, [self::PROFILE_UAT, self::PROFILE_PRODUCTION], true);
        $candidate = self::originCandidate($profile);
        $origin = self::normalizeOrigin($candidate['value']);
        $privateLan = $origin !== null && self::isPrivateLanOrigin($origin);
        $loopback = $origin !== null && self::isLoopbackOrigin($origin);
        $publicHttps = $origin !== null && self::isPublicHttpsUrl($origin);
        $blocker = self::profileBlocker(
            $environment,
            $operatorMode,
            $transitionState,
            $profile,
            $origin,
            $publicHttps,
        );

        return [
            'environment' => $environment,
            'operator_mode' => $operatorMode,
            'state' => $transitionState,
            'execution_mode' => strtoupper($transitionState),
            'profile' => $profile,
            'origin' => $origin,
            'origin_source' => $candidate['source'],
            'ready' => $blocker === null,
            'blocker_code' => $blocker['code'] ?? null,
            'blocker_message' => $blocker['message'] ?? null,
            'https_required' => $httpsRequired,
            'private_lan' => $privateLan,
            'loopback' => $loopback,
            'lan_reachable' => $profile === self::PROFILE_LOCAL ? $privateLan : null,
            'phone_reachable' => $profile === self::PROFILE_LOCAL ? ($privateLan || $publicHttps) : $publicHttps,
            'external_effects_allowed' => $operatorMode === ExternalExecutionControlPlaneService::MODE_LIVE
                && $transitionState === ExternalExecutionControlPlaneService::STATE_LIVE,
        ];
    }

    /**
     * @return array{source:?string,value:?string}
     */
    private static function originCandidate(?string $profile): array
    {
        $keys = match ($profile) {
            self::PROFILE_LOCAL => [
                'services.partner_portal.public_url',
                'services.public_urls.qr_base_url',
                'services.public_urls.payment_base_url',
                'services.public_urls.app_url',
                'app.url',
            ],
            self::PROFILE_UAT => [
                'services.partner_portal.public_url',
                'services.public_urls.app_url',
            ],
            self::PROFILE_PRODUCTION => [
                'services.public_urls.app_url',
                'services.partner_portal.public_url',
                'app.url',
            ],
            default => [],
        };

        foreach ($keys as $key) {
            $value = trim((string) config($key, ''));
            if ($value !== '') {
                return ['source' => $key, 'value' => $value];
            }
        }

        return ['source' => null, 'value' => null];
    }

    /**
     * @return array{code:string,message:string}|null
     */
    private static function profileBlocker(
        string $environment,
        string $operatorMode,
        string $transitionState,
        ?string $profile,
        ?string $origin,
        bool $publicHttps,
    ): ?array {
        if (! in_array($environment, [self::ENV_DEV, self::ENV_UAT, self::ENV_PROD], true) || $profile === null) {
            return self::blocked('PUBLIC_ENVIRONMENT_UNKNOWN', 'Public origin ortam kimliği DEV, UAT veya PROD olarak doğrulanamadı.');
        }

        if (! in_array($operatorMode, [ExternalExecutionControlPlaneService::MODE_LOCAL, ExternalExecutionControlPlaneService::MODE_LIVE], true)
            || ! in_array($transitionState, [
                ExternalExecutionControlPlaneService::STATE_LOCAL,
                ExternalExecutionControlPlaneService::STATE_ACTIVATING,
                ExternalExecutionControlPlaneService::STATE_LIVE,
                ExternalExecutionControlPlaneService::STATE_FREEZING,
                ExternalExecutionControlPlaneService::STATE_BLOCKED,
            ], true)
        ) {
            return self::blocked('PUBLIC_EXECUTION_STATE_INVALID', 'Public origin execution state doğrulanamadı.');
        }

        if (in_array($transitionState, [
            ExternalExecutionControlPlaneService::STATE_ACTIVATING,
            ExternalExecutionControlPlaneService::STATE_FREEZING,
            ExternalExecutionControlPlaneService::STATE_BLOCKED,
        ], true)) {
            return self::blocked('PUBLIC_ORIGIN_TRANSITION_NOT_READY', 'Public origin geçiş durumu tamamlanmadan link üretilemez.');
        }

        if (($operatorMode === ExternalExecutionControlPlaneService::MODE_LOCAL && $transitionState !== ExternalExecutionControlPlaneService::STATE_LOCAL)
            || ($operatorMode === ExternalExecutionControlPlaneService::MODE_LIVE && $transitionState !== ExternalExecutionControlPlaneService::STATE_LIVE)
        ) {
            return self::blocked('PUBLIC_EXECUTION_STATE_MISMATCH', 'Public origin operator modu ile transition state eşleşmiyor.');
        }

        if ($origin === null) {
            return match ($profile) {
                self::PROFILE_UAT => self::blocked('PUBLIC_UAT_HTTPS_MISSING', 'UAT LIVE için UAT ortamına ait public HTTPS origin zorunlu.'),
                self::PROFILE_PRODUCTION => self::blocked('PUBLIC_PRODUCTION_HTTPS_MISSING', 'PROD için production public HTTPS origin zorunlu.'),
                default => self::blocked('PUBLIC_ORIGIN_MISSING_OR_INVALID', 'Seçili environment profile için geçerli public origin tanımlı değil.'),
            };
        }

        if ($profile === self::PROFILE_LOCAL) {
            $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
            if ($scheme === 'http' && ! self::isLocalUrl($origin)) {
                return self::blocked('LOCAL_PUBLIC_HTTP_NOT_PRIVATE', 'HTTP local public origin yalnız loopback veya private LAN üzerinde kullanılabilir.');
            }

            return null;
        }

        if (! $publicHttps) {
            return self::blocked(
                $profile === self::PROFILE_UAT ? 'PUBLIC_UAT_HTTPS_MISSING' : 'PUBLIC_PRODUCTION_HTTPS_MISSING',
                $profile === self::PROFILE_UAT
                    ? 'UAT LIVE için UAT ortamına ait public HTTPS origin zorunlu.'
                    : 'PROD için production public HTTPS origin zorunlu.',
            );
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function routeReadiness(): array
    {
        return [
            'customer_confirmation' => self::routeReadinessItem('service-job-confirmation.show'),
            'qr_mount' => self::routeReadinessItem('mount-request.show', ['get_contract' => 'read_only']),
            'payment' => self::routeReadinessItem('mount-payment.show'),
            'technician_job' => self::routeReadinessItem('partner.service-jobs.short', ['authentication' => 'required']),
            'survey' => [
                'ready' => false,
                'route' => null,
                'blocker_code' => 'SURVEY_PUBLIC_ROUTE_NOT_IMPLEMENTED',
            ],
            'warranty' => [
                'ready' => false,
                'route' => null,
                'blocker_code' => 'WARRANTY_PUBLIC_ROUTE_NOT_IMPLEMENTED',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function routeReadinessItem(string $name, array $extra = []): array
    {
        $ready = Route::has($name);

        return [
            'ready' => $ready,
            'route' => $ready ? $name : null,
            'blocker_code' => $ready ? null : 'PUBLIC_ROUTE_NOT_IMPLEMENTED',
            ...$extra,
        ];
    }

    /**
     * @return array{code:string,message:string}
     */
    private static function blocked(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    private static function environment(string $runtimeEnvironment): string
    {
        return match ($runtimeEnvironment) {
            'production' => self::ENV_PROD,
            'staging' => self::ENV_UAT,
            'local', 'testing' => self::ENV_DEV,
            default => 'UNKNOWN',
        };
    }

    /**
     * @param  list<string>  $keys
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
