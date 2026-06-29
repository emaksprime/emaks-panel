<?php

namespace App\Services\Payments;

class TechnicalServicePaymentProviderModeResolver
{
    public const NOT_READY_MESSAGE = 'Gerçek ödeme sağlayıcısı hazır değil. API bilgileri ve bağlantı doğrulaması tamamlanmalı.';

    public function realProviderEnabled(): bool
    {
        return (bool) config('payments.real_provider_enabled', false);
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
        $provider = strtolower(trim((string) config('payments.provider_name', config('payments.provider', 'iyzico'))));

        return str_starts_with($provider, 'iyzico') ? 'iyzico' : $provider;
    }

    public function environment(): string
    {
        return strtolower((string) config('payments.environment', 'local'));
    }

    public function gatewayMode(): string
    {
        $mode = strtolower(trim((string) config('payments.gateway.mode', $this->environment())));

        return $mode === 'live' ? 'live' : 'sandbox';
    }

    public function gatewayConfigured(): bool
    {
        return filled(config('payments.gateway.url')) && filled(config('payments.gateway.token'));
    }

    public function gatewayHealthVerified(): bool
    {
        return (bool) config('payments.gateway.health_verified', false);
    }

    public function gatewayHttpEnabled(): bool
    {
        return (bool) config('payments.gateway.http_enabled', false);
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
            || ! $this->gatewayHttpEnabled()) {
            throw new TechnicalServicePaymentProviderDisabledException(self::NOT_READY_MESSAGE);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'real_provider_enabled' => $this->realProviderEnabled(),
            'active_provider' => $this->activeProviderName(),
            'configured_provider' => $this->configuredRealProviderName(),
            'mode' => $this->gatewayMode(),
            'gateway_configured' => $this->gatewayConfigured(),
            'gateway_health_verified' => $this->gatewayHealthVerified(),
            'gateway_http_enabled' => $this->gatewayHttpEnabled(),
            'ready' => $this->realProviderEnabled()
                && $this->configuredRealProviderName() === 'iyzico'
                && $this->gatewayConfigured()
                && $this->gatewayHealthVerified()
                && $this->gatewayHttpEnabled(),
        ];
    }
}
