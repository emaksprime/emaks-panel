<?php

namespace App\Services\Payments;

class TechnicalServicePaymentProviderModeResolver
{
    public const NOT_READY_MESSAGE = 'Gerçek ödeme sağlayıcısı hazır değil. API bilgileri, mod ve canlı/sandbox hazırlığı tamamlanmalı.';

    public function __construct(
        private readonly TechnicalServicePaymentProviderSettingsService $settings,
        private readonly TechnicalServicePaymentProviderTransportResolver $transportResolver,
    ) {}

    public function realProviderEnabled(): bool
    {
        return $this->settings->realProviderEnabled();
    }

    public function activeProviderName(): string
    {
        if (! $this->realProviderEnabled()) {
            return 'fake';
        }

        return $this->configuredRealProviderName();
    }

    public function configuredRealProviderName(): string
    {
        $provider = $this->settings->configuredProvider();

        return str_starts_with($provider, 'iyzico') ? 'iyzico' : $provider;
    }

    public function environment(): string
    {
        return strtolower((string) config('payments.environment', 'local'));
    }

    public function gatewayMode(): string
    {
        return $this->settings->providerMode();
    }

    public function gatewayConfigured(): bool
    {
        return $this->settings->gatewayConfigured();
    }

    public function gatewayHealthVerified(): bool
    {
        return $this->settings->gatewayHealthVerified();
    }

    public function gatewayHttpEnabled(): bool
    {
        return $this->settings->gatewayHttpEnabled();
    }

    public function credentialsReady(?string $mode = null): bool
    {
        return $mode === null
            ? $this->settings->credentialsReady()
            : $this->settings->laravelEncryptedCredentialsReady($mode);
    }

    public function providerSendReady(?string $mode = null): bool
    {
        return $this->settings->providerSendReady($mode);
    }

    public function shouldUseFakeProvider(): bool
    {
        return ! $this->realProviderEnabled();
    }

    public function assertReadyForRealProvider(?string $mode = null): void
    {
        $mode = $this->normalizeMode($mode ?? $this->gatewayMode());

        if ($mode === 'live' && ! $this->settings->liveSendApproved()) {
            throw new TechnicalServicePaymentProviderDisabledException(
                TechnicalServicePaymentProviderSettingsService::LIVE_SEND_APPROVAL_MESSAGE
            );
        }

        if (! $this->realProviderEnabled()
            || $this->configuredRealProviderName() !== 'iyzico'
            || ! $this->transportResolver->directLaravel()
            || ! $this->credentialsReady($mode)
            || ! $this->providerSendReady($mode)) {
            throw new TechnicalServicePaymentProviderDisabledException(self::NOT_READY_MESSAGE);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $ready = $this->realProviderEnabled()
            && $this->configuredRealProviderName() === 'iyzico'
            && $this->transportResolver->directLaravel()
            && $this->credentialsReady()
            && $this->providerSendReady();

        return [
            'real_provider_enabled' => $this->realProviderEnabled(),
            'active_provider' => $this->activeProviderName(),
            'configured_provider' => $this->configuredRealProviderName(),
            'mode' => $this->gatewayMode(),
            'provider_transport' => $this->transportResolver->activeTransport(),
            'gateway_configured' => $this->gatewayConfigured(),
            'gateway_health_verified' => $this->gatewayHealthVerified(),
            'gateway_http_enabled' => $this->gatewayHttpEnabled(),
            'credential_source' => $this->settings->credentialSource(),
            'laravel_encrypted_credentials_ready' => $this->settings->laravelEncryptedCredentialsReady(),
            'n8n_env_credentials_ready' => $this->settings->n8nEnvCredentialsReady(),
            'credentials_ready' => $this->credentialsReady(),
            'provider_send_ready' => $this->providerSendReady(),
            'ready' => $ready,
        ];
    }

    private function normalizeMode(string $mode): string
    {
        return strtolower(trim($mode)) === 'live' ? 'live' : 'sandbox';
    }
}
