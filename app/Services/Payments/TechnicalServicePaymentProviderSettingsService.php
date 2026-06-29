<?php

namespace App\Services\Payments;

use App\Models\PageConfig;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class TechnicalServicePaymentProviderSettingsService
{
    public const PAGE_CODE = 'technical_service_admin';
    public const REAL_PROVIDER_ENABLED_KEY = 'technical_service.payment.real_provider_enabled';
    public const PROVIDER_KEY = 'technical_service.payment.provider';
    public const PROVIDER_MODE_KEY = 'technical_service.payment.provider_mode';

    public function __construct(private readonly TechnicalServicePaymentProviderCredentialService $credentialService) {}

    public function realProviderEnabled(): bool
    {
        return filter_var(
            Arr::get($this->layout(), self::REAL_PROVIDER_ENABLED_KEY, config('payments.real_provider_enabled', false)),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    public function configuredProvider(): string
    {
        $provider = strtolower(trim((string) Arr::get(
            $this->layout(),
            self::PROVIDER_KEY,
            config('payments.provider_name', config('payments.provider', 'iyzico')),
        )));

        return str_starts_with($provider, 'iyzico') ? 'iyzico' : ($provider === 'fake' ? 'fake' : $provider);
    }

    public function effectiveProvider(): string
    {
        return $this->realProviderEnabled() ? 'iyzico' : 'fake';
    }

    public function providerMode(): string
    {
        $mode = strtolower(trim((string) Arr::get(
            $this->layout(),
            self::PROVIDER_MODE_KEY,
            config('payments.gateway.mode', config('payments.environment', 'sandbox')),
        )));

        return $mode === 'live' ? 'live' : 'sandbox';
    }

    public function gatewayUrlConfigured(): bool
    {
        return filled(config('payments.gateway.url'));
    }

    public function gatewayTokenConfigured(): bool
    {
        return filled(config('payments.gateway.token'));
    }

    public function gatewayConfigured(): bool
    {
        return $this->gatewayUrlConfigured() && $this->gatewayTokenConfigured();
    }

    public function gatewayHealthVerified(): bool
    {
        return (bool) config('payments.gateway.health_verified', false);
    }

    public function gatewayHttpEnabled(): bool
    {
        return (bool) config('payments.gateway.http_enabled', false);
    }

    public function gatewayReady(): bool
    {
        return $this->gatewayConfigured()
            && $this->gatewayHealthVerified()
            && $this->gatewayHttpEnabled();
    }

    public function credentialsReady(): bool
    {
        return $this->credentialService->credentialsReady($this->providerMode());
    }

    public function providerSendReady(): bool
    {
        return (bool) config('payments.gateway.allow_provider_send', false);
    }

    public function canEnableRealProvider(): bool
    {
        return $this->gatewayReady()
            && $this->credentialsReady()
            && $this->providerSendReady();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $realEnabled = $this->realProviderEnabled();
        $providerMode = $this->providerMode();
        $canEnable = $this->canEnableRealProvider();
        $disabledReason = $canEnable ? null : $this->disabledReason();
        $credentialPayload = $this->credentialService->credentialPayload($providerMode);
        if (($credentialPayload['ready'] ?? false) === true && ! $this->providerSendReady()) {
            $credentialPayload['entry_status'] = 'API bilgileri tanımlı, gerçek ödeme gönderimi henüz aktif değil.';
            $credentialPayload['entry_message'] = 'API bilgileri encrypted saklanır; kayıttan sonra tam değer gösterilmez. Gerçek ödeme gönderimi REL-3C.5/aktivasyon tamamlanana kadar kapalı.';
        }

        return [
            'keys' => [
                'real_provider_enabled' => self::REAL_PROVIDER_ENABLED_KEY,
                'provider' => self::PROVIDER_KEY,
                'provider_mode' => self::PROVIDER_MODE_KEY,
            ],
            'real_provider_enabled' => $realEnabled,
            'provider' => $realEnabled ? 'iyzico' : 'fake',
            'configured_provider' => $this->configuredProvider(),
            'provider_mode' => $providerMode,
            'selected_provider_mode_label' => $this->selectedProviderModeLabel($providerMode),
            'effective_mode' => $this->effectiveModeKey($realEnabled, $providerMode),
            'effective_mode_label' => $this->effectiveModeLabel($realEnabled, $providerMode),
            'fake_active' => ! $realEnabled,
            'gateway' => [
                'url_configured' => $this->gatewayUrlConfigured(),
                'token_configured' => $this->gatewayTokenConfigured(),
                'health_verified' => $this->gatewayHealthVerified(),
                'http_enabled' => $this->gatewayHttpEnabled(),
                'provider_send_enabled' => $this->providerSendReady(),
                'ready' => $this->gatewayReady(),
                'mode' => $providerMode,
                'webhook_path' => (string) config('payments.gateway.webhook_path', 'panel-payment-provider-iyzico-runner-v1'),
            ],
            'credentials' => $credentialPayload,
            'can_enable_real_provider' => $canEnable,
            'disabled_reason' => $disabledReason,
            'health_status' => $this->healthStatus(),
            'secret_source' => ($credentialPayload['ready'] ?? false) === true ? 'encrypted_storage' : 'not_configured',
            'warning' => 'Gerçek ödeme aktifken fake ödeme kullanılmaz.',
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function update(array $values): array
    {
        $layout = $this->layout();

        $nextRealProviderEnabled = array_key_exists('real_provider_enabled', $values)
            ? (bool) $values['real_provider_enabled']
            : $this->realProviderEnabled();
        $nextProvider = $nextRealProviderEnabled ? 'iyzico' : 'fake';
        $nextMode = array_key_exists('provider_mode', $values)
            ? $this->normalizeMode($values['provider_mode'])
            : $this->providerMode();

        if ($nextRealProviderEnabled
            && (! $this->gatewayReady() || ! $this->credentialService->credentialsReady($nextMode) || ! $this->providerSendReady())) {
            throw ValidationException::withMessages([
                'real_provider_enabled' => TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE,
            ]);
        }

        Arr::set($layout, self::REAL_PROVIDER_ENABLED_KEY, $nextRealProviderEnabled);
        Arr::set($layout, self::PROVIDER_KEY, $nextProvider);
        Arr::set($layout, self::PROVIDER_MODE_KEY, $nextMode);

        $config = PageConfig::query()->firstOrCreate(
            ['page_code' => self::PAGE_CODE],
            ['layout_json' => []],
        );
        $config->forceFill(['layout_json' => $layout])->save();

        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    public function healthCheckPayload(): array
    {
        return [
            'settings' => $this->payload(),
            'health_check' => $this->healthStatus(),
        ];
    }

    /**
     * @return array{status:string,label:string,message:string}
     */
    private function healthStatus(): array
    {
        if (! $this->gatewayUrlConfigured()) {
            return [
                'status' => 'missing_gateway_url',
                'label' => 'Gateway URL eksik',
                'message' => 'Ödeme sağlayıcı gateway URL ayarı tanımlı değil.',
            ];
        }

        if (! $this->gatewayTokenConfigured()) {
            return [
                'status' => 'missing_gateway_token',
                'label' => 'Gateway token eksik',
                'message' => 'Ödeme sağlayıcı gateway token ayarı tanımlı değil.',
            ];
        }

        if (! $this->credentialsReady()) {
            return [
                'status' => 'missing_credentials',
                'label' => 'API bilgileri tanımsız',
                'message' => 'API bilgileri tanımlı olmadığı için gerçek ödeme aktif edilemez.',
            ];
        }

        if (! $this->gatewayHealthVerified()) {
            return [
                'status' => 'health_check_missing',
                'label' => 'Bağlantı doğrulaması bekliyor',
                'message' => 'Gateway bağlantısı henüz doğrulanmadı.',
            ];
        }

        if (! $this->gatewayHttpEnabled()) {
            return [
                'status' => 'gateway_http_disabled',
                'label' => 'Gateway HTTP kapalı',
                'message' => 'Gateway HTTP çağrısı açık değil.',
            ];
        }

        if (! $this->providerSendReady()) {
            return [
                'status' => 'provider_send_disabled',
                'label' => 'Gerçek ödeme gönderimi kapalı',
                'message' => 'API bilgileri tanımlı, gerçek ödeme gönderimi henüz aktif değil.',
            ];
        }

        return [
            'status' => 'ready',
            'label' => 'Hazır',
            'message' => 'Gateway ve API bilgileri hazır görünüyor.',
        ];
    }

    private function disabledReason(): string
    {
        if (! $this->gatewayReady()) {
            return TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE;
        }

        if (! $this->credentialsReady()) {
            return 'API bilgileri tanımlı olmadığı için gerçek ödeme aktif edilemez.';
        }

        if (! $this->providerSendReady()) {
            return 'API bilgileri tanımlı, gerçek ödeme gönderimi henüz aktif değil.';
        }

        return TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE;
    }

    private function effectiveModeKey(bool $realEnabled, string $providerMode): string
    {
        return $realEnabled ? 'iyzico_'.$providerMode : 'fake';
    }

    private function effectiveModeLabel(bool $realEnabled, string $providerMode): string
    {
        if (! $realEnabled) {
            return 'Fake / Yerel';
        }

        return $this->selectedProviderModeLabel($providerMode);
    }

    private function selectedProviderModeLabel(string $providerMode): string
    {
        return $providerMode === 'live' ? 'Iyzico Live' : 'Iyzico Sandbox';
    }

    private function normalizeMode(mixed $mode): string
    {
        return strtolower(trim((string) $mode)) === 'live' ? 'live' : 'sandbox';
    }

    /**
     * @return array<string, mixed>
     */
    private function layout(): array
    {
        $layout = PageConfig::query()
            ->where('page_code', self::PAGE_CODE)
            ->value('layout_json');

        return is_array($layout) ? $layout : [];
    }
}
