<?php

namespace App\Services\Payments;

class TechnicalServicePaymentProviderModeResolver
{
    public const NOT_READY_MESSAGE = 'Gerçek ödeme sağlayıcısı hazır değil. API bilgileri ve bağlantı doğrulaması tamamlanmalı.';

    public function __construct(private readonly TechnicalServicePaymentProviderSettingsService $settings) {}

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

    public function credentialsReady(): bool
    {
        return $this->settings->credentialsReady();
    }

    public function providerSendReady(): bool
    {
        return $this->settings->providerSendReady();
    }

    public function shouldUseFakeProvider(): bool
    {
        return ! $this->realProviderEnabled();
    }

    public function assertReadyForRealProvider(): void
    {
        if (! $this->realProviderEnabled()
            || $this->configuredRealProviderName() !== 'iyzico'
            || ! $this->gatewayConfigured()
            || ! $this->gatewayHealthVerified()
            || ! $this->gatewayHttpEnabled()
            || ! $this->credentialsReady()
            || ! $this->providerSendReady()) {
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
            && $this->gatewayConfigured()
            && $this->gatewayHealthVerified()
            && $this->gatewayHttpEnabled()
            && $this->credentialsReady()
            && $this->providerSendReady();

        return [
            'real_provider_enabled' => $this->realProviderEnabled(),
            'active_provider' => $this->activeProviderName(),
            'configured_provider' => $this->configuredRealProviderName(),
            'mode' => $this->gatewayMode(),
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
}
