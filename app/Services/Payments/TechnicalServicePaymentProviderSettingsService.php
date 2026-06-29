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
    public const CREDENTIAL_SOURCE_DISABLED = 'disabled';
    public const CREDENTIAL_SOURCE_N8N_ENV = 'n8n_env';
    public const CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED_PENDING_BRIDGE = 'laravel_encrypted_pending_bridge';

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
        return $this->credentialsReadyForSelectedSource();
    }

    public function providerSendReady(?string $mode = null): bool
    {
        $mode = $mode !== null ? $this->normalizeMode($mode) : $this->providerMode();

        return $this->providerSendEnabled()
            && $this->gatewayReady()
            && $this->credentialSource($mode) === self::CREDENTIAL_SOURCE_N8N_ENV
            && $this->credentialsReadyForSelectedSource($mode);
    }

    public function canEnableRealProvider(): bool
    {
        return $this->canEnableRealProviderForMode($this->providerMode());
    }

    public function providerSendEnabled(): bool
    {
        return (bool) config('payments.gateway.allow_provider_send', false);
    }

    public function credentialSource(?string $mode = null): string
    {
        $configured = strtolower(trim((string) config('payments.gateway.credential_source', self::CREDENTIAL_SOURCE_DISABLED)));

        if ($configured === self::CREDENTIAL_SOURCE_N8N_ENV) {
            return self::CREDENTIAL_SOURCE_N8N_ENV;
        }

        if ($configured === self::CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED_PENDING_BRIDGE) {
            return self::CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED_PENDING_BRIDGE;
        }

        if ($this->laravelEncryptedCredentialsReady($mode ?? $this->providerMode())) {
            return self::CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED_PENDING_BRIDGE;
        }

        return self::CREDENTIAL_SOURCE_DISABLED;
    }

    public function laravelEncryptedCredentialsReady(?string $mode = null): bool
    {
        return $this->credentialService->credentialsReady($mode ?? $this->providerMode());
    }

    public function n8nEnvCredentialsReady(): bool
    {
        return (bool) config('payments.gateway.n8n_env_credentials_ready', config('payments.gateway.credentials_ready', false));
    }

    public function credentialsReadyForSelectedSource(?string $mode = null): bool
    {
        return match ($this->credentialSource($mode ?? $this->providerMode())) {
            self::CREDENTIAL_SOURCE_N8N_ENV => $this->n8nEnvCredentialsReady(),
            default => false,
        };
    }

    public function canEnableRealProviderForMode(?string $mode = null): bool
    {
        $mode = $mode !== null ? $this->normalizeMode($mode) : $this->providerMode();

        return $this->gatewayReady()
            && $this->credentialSource($mode) === self::CREDENTIAL_SOURCE_N8N_ENV
            && $this->credentialsReadyForSelectedSource($mode)
            && $this->providerSendReady($mode);
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
        $credentialBridge = $this->credentialBridgePayload($providerMode);
        $readiness = $this->readinessPayload($providerMode, $canEnable, $disabledReason);
        if (($credentialPayload['ready'] ?? false) === true
            && $credentialBridge['source'] === self::CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED_PENDING_BRIDGE) {
            $credentialPayload['entry_status'] = 'API bilgileri tanımlı; n8n imza köprüsü hazır değil.';
            $credentialPayload['entry_message'] = 'API bilgileri Laravel’de encrypted saklanır; kayıttan sonra tam değer gösterilmez. n8n imza/çağrı köprüsü hazır olana kadar gerçek ödeme gönderimi kapalıdır.';
        } elseif (($credentialPayload['ready'] ?? false) === true && ! $this->providerSendReady()) {
            $credentialPayload['entry_status'] = 'API bilgileri tanımlı, gerçek ödeme gönderimi henüz aktif değil.';
            $credentialPayload['entry_message'] = 'API bilgileri encrypted saklanır; kayıttan sonra tam değer gösterilmez. Gerçek ödeme gönderimi aktivasyon tamamlanana kadar kapalı.';
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
                'provider_send_enabled' => $this->providerSendEnabled(),
                'provider_send_ready' => $this->providerSendReady(),
                'ready' => $this->gatewayReady(),
                'mode' => $providerMode,
                'webhook_path' => (string) config('payments.gateway.webhook_path', 'panel-payment-provider-iyzico-runner-v1'),
            ],
            'credentials' => $credentialPayload,
            'credential_bridge' => $credentialBridge,
            'readiness' => $readiness,
            'sandbox_activation_checklist' => $this->sandboxActivationChecklist($credentialBridge, $readiness),
            'can_enable_real_provider' => $canEnable,
            'disabled_reason' => $disabledReason,
            'health_status' => $this->healthStatus(),
            'secret_source' => $credentialBridge['source'],
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

        if ($nextRealProviderEnabled && ! $this->canEnableRealProviderForMode($nextMode)) {
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
            if ($this->credentialSource() === self::CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED_PENDING_BRIDGE) {
                return [
                    'status' => 'credential_bridge_missing',
                    'label' => 'n8n imza köprüsü eksik',
                    'message' => 'API bilgileri Laravel’de encrypted olarak kayıtlı; n8n imza köprüsü henüz aktif değil.',
                ];
            }

            if ($this->credentialSource() === self::CREDENTIAL_SOURCE_N8N_ENV) {
                return [
                    'status' => 'n8n_env_credentials_unverified',
                    'label' => 'n8n/Coolify Secrets doğrulanmalı',
                    'message' => 'n8n/Coolify Secrets doğrulanmadan gerçek ödeme aktif edilemez.',
                ];
            }

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
                'message' => 'Provider send readiness tamamlanmadan gerçek ödeme gönderimi açılmaz.',
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
            return match ($this->credentialSource()) {
                self::CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED_PENDING_BRIDGE => 'API bilgileri Laravel’de encrypted olarak kayıtlı; n8n imza köprüsü henüz aktif değil.',
                self::CREDENTIAL_SOURCE_N8N_ENV => 'n8n/Coolify Secrets doğrulanmalı.',
                default => 'API bilgileri tanımlı olmadığı için gerçek ödeme aktif edilemez.',
            };
        }

        if (! $this->providerSendReady()) {
            return 'Gerçek ödeme gönderimi kapalı.';
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
    private function credentialBridgePayload(string $providerMode): array
    {
        $source = $this->credentialSource($providerMode);
        $laravelEncryptedReady = $this->laravelEncryptedCredentialsReady($providerMode);
        $n8nEnvReady = $this->n8nEnvCredentialsReady();
        $sourceReady = $this->credentialsReadyForSelectedSource($providerMode);
        $safeForSend = $source === self::CREDENTIAL_SOURCE_N8N_ENV && $sourceReady;

        return [
            'source' => $source,
            'source_label' => $this->credentialSourceLabel($source),
            'laravel_encrypted_credentials_saved' => $laravelEncryptedReady,
            'n8n_env_credentials_ready' => $n8nEnvReady,
            'credentials_ready_for_selected_source' => $sourceReady,
            'safe_for_provider_send' => $safeForSend,
            'status' => $this->credentialBridgeStatus($source, $sourceReady, $laravelEncryptedReady, $n8nEnvReady),
            'message' => $this->credentialBridgeMessage($source, $sourceReady, $laravelEncryptedReady, $n8nEnvReady),
            'normal_item_json_secret_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readinessPayload(string $providerMode, bool $canEnable, ?string $disabledReason): array
    {
        return [
            'effective_mode' => $this->effectiveModeKey($this->realProviderEnabled(), $providerMode),
            'selected_provider' => $this->configuredProvider(),
            'selected_mode' => $providerMode,
            'real_provider_enabled' => $this->realProviderEnabled(),
            'credential_source' => $this->credentialSource($providerMode),
            'credentials_saved' => $this->laravelEncryptedCredentialsReady($providerMode),
            'credentials_ready_for_selected_source' => $this->credentialsReadyForSelectedSource($providerMode),
            'gateway_url_configured' => $this->gatewayUrlConfigured(),
            'gateway_token_configured' => $this->gatewayTokenConfigured(),
            'gateway_ready' => $this->gatewayReady(),
            'provider_send_enabled' => $this->providerSendEnabled(),
            'provider_send_ready' => $this->providerSendReady(),
            'can_enable_real_provider' => $canEnable,
            'disabled_reason' => $disabledReason,
            'next_required_action' => $canEnable ? 'Gerçek ödeme sandbox aktivasyonu için Burhan onayı ve kontrollü health check gerekir.' : $this->nextRequiredAction(),
        ];
    }

    /**
     * @param array<string, mixed> $credentialBridge
     * @param array<string, mixed> $readiness
     * @return array<int, array{key:string,label:string,ready:bool}>
     */
    private function sandboxActivationChecklist(array $credentialBridge, array $readiness): array
    {
        return [
            [
                'key' => 'credentials',
                'label' => 'Credential source güvenli ve seçili mod için hazır',
                'ready' => (bool) ($credentialBridge['credentials_ready_for_selected_source'] ?? false),
            ],
            [
                'key' => 'gateway',
                'label' => 'Gateway URL/token/health/http readiness tamam',
                'ready' => (bool) ($readiness['gateway_ready'] ?? false),
            ],
            [
                'key' => 'provider_send',
                'label' => 'Provider send açık ve güvenli credential source ile eşleşiyor',
                'ready' => (bool) ($readiness['provider_send_ready'] ?? false),
            ],
            [
                'key' => 'no_real_call_this_phase',
                'label' => 'REL-3C.5 boyunca gerçek/sandbox Iyzico çağrısı yapılmaz',
                'ready' => true,
            ],
        ];
    }

    private function credentialSourceLabel(string $source): string
    {
        return match ($source) {
            self::CREDENTIAL_SOURCE_N8N_ENV => 'n8n/Coolify Secrets',
            self::CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED_PENDING_BRIDGE => 'Laravel encrypted credentials - bridge bekliyor',
            default => 'Not configured',
        };
    }

    private function credentialBridgeStatus(
        string $source,
        bool $sourceReady,
        bool $laravelEncryptedReady,
        bool $n8nEnvReady,
    ): string {
        if ($source === self::CREDENTIAL_SOURCE_N8N_ENV) {
            return $sourceReady ? 'ready' : 'n8n_env_unverified';
        }

        if ($source === self::CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED_PENDING_BRIDGE && $laravelEncryptedReady) {
            return 'pending_bridge';
        }

        return $n8nEnvReady ? 'source_not_selected' : 'missing';
    }

    private function credentialBridgeMessage(
        string $source,
        bool $sourceReady,
        bool $laravelEncryptedReady,
        bool $n8nEnvReady,
    ): string {
        if ($source === self::CREDENTIAL_SOURCE_N8N_ENV) {
            return $sourceReady
                ? 'n8n/Coolify Secrets hazır işaretli; Laravel secret değerini response veya payload olarak göndermez.'
                : 'n8n/Coolify Secrets doğrulanmalı.';
        }

        if ($source === self::CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED_PENDING_BRIDGE && $laravelEncryptedReady) {
            return 'API bilgileri Laravel’de encrypted olarak kayıtlı; n8n imza/çağrı köprüsü henüz aktif değil.';
        }

        if ($n8nEnvReady) {
            return 'n8n/Coolify Secrets hazır işaretli ancak credential source n8n_env seçili değil.';
        }

        return 'Credential source yapılandırılmadı.';
    }

    private function nextRequiredAction(): string
    {
        if (! $this->gatewayUrlConfigured()) {
            return 'Gateway URL tanımlanmalı.';
        }

        if (! $this->gatewayTokenConfigured()) {
            return 'Gateway token tanımlanmalı.';
        }

        if ($this->credentialSource() === self::CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED_PENDING_BRIDGE) {
            return 'Laravel encrypted credentials için güvenli n8n imza köprüsü tasarlanmalı veya n8n_env source doğrulanmalı.';
        }

        if ($this->credentialSource() === self::CREDENTIAL_SOURCE_N8N_ENV && ! $this->n8nEnvCredentialsReady()) {
            return 'n8n/Coolify Secrets doğrulanmalı.';
        }

        if (! $this->gatewayHealthVerified()) {
            return 'Gateway readiness health doğrulaması yapılmalı.';
        }

        if (! $this->gatewayHttpEnabled()) {
            return 'Gateway HTTP çağrısı kontrollü şekilde açılmalı.';
        }

        if (! $this->providerSendEnabled()) {
            return 'Provider send explicit olarak açılmalı.';
        }

        return TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE;
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
