<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;
use InvalidArgumentException;

class PaymentProviderManager
{
    public function __construct(
        private readonly TechnicalServicePaymentProviderModeResolver $modeResolver,
        private readonly TechnicalServicePaymentProviderTransportResolver $transportResolver,
    ) {}

    public function createPayment(TechnicalServiceMountPayment $payment): array
    {
        $this->stampProviderDecision($payment, $this->providerName());

        return $this->provider()->createPayment($payment->refresh());
    }

    public function updatePayment(TechnicalServiceMountPayment $payment): array
    {
        $this->stampProviderDecision($payment, $this->providerNameForPayment($payment));

        return $this->providerForPayment($payment->refresh())->updatePayment($payment->refresh());
    }

    public function cancelPayment(TechnicalServiceMountPayment $payment): array
    {
        $this->stampProviderDecision($payment, $this->providerNameForPayment($payment));

        return $this->providerForPayment($payment->refresh())->cancelPayment($payment->refresh());
    }

    public function syncPayment(TechnicalServiceMountPayment $payment): array
    {
        $this->stampProviderDecision($payment, $this->providerNameForPayment($payment));

        return $this->providerForPayment($payment->refresh())->syncPayment($payment->refresh());
    }

    public function provider(): PaymentProviderInterface
    {
        return match ($this->configuredProviderName()) {
            'fake' => app(FakePaymentProvider::class),
            'iyzico', 'iyzico_sandbox', 'iyzico_live' => app(IyzicoPaymentProvider::class),
            default => throw new InvalidArgumentException('Desteklenmeyen odeme saglayicisi.'),
        };
    }

    public function providerName(): string
    {
        return $this->modeResolver->activeProviderName();
    }

    public function environment(): string
    {
        return $this->modeResolver->environment();
    }

    private function configuredProviderName(): string
    {
        return $this->modeResolver->activeProviderName();
    }

    private function providerNameForPayment(TechnicalServiceMountPayment $payment): string
    {
        return strtolower((string) ($payment->provider ?: $this->configuredProviderName()));
    }

    private function providerForPayment(TechnicalServiceMountPayment $payment): PaymentProviderInterface
    {
        return match ($this->providerNameForPayment($payment)) {
            'fake' => app(FakePaymentProvider::class),
            'iyzico', 'iyzico_sandbox', 'iyzico_live' => app(IyzicoPaymentProvider::class),
            default => throw new InvalidArgumentException('Desteklenmeyen odeme saglayicisi.'),
        };
    }

    private function stampProviderDecision(TechnicalServiceMountPayment $payment, string $provider): void
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $provider = str_starts_with($provider, 'iyzico') ? 'iyzico' : $provider;
        $transport = $provider === 'fake'
            ? 'fake_local'
            : $this->transportResolver->activeTransport();

        $payload['provider_decision'] = [
            'provider' => $provider,
            'provider_mode' => $provider === 'fake' ? 'local' : $this->modeResolver->gatewayMode(),
            'provider_transport' => $transport,
            'environment' => $this->environment(),
            'real_provider_enabled' => $this->modeResolver->realProviderEnabled(),
            'decided_at' => now()->toIso8601String(),
        ];
        $payload['provider_mode'] = $payload['provider_decision']['provider_mode'];
        $payload['provider_transport'] = $transport;
        $payload['provider_environment'] = $this->environment();

        $payment->forceFill([
            'provider' => $provider,
            'raw_payload' => $payload,
        ])->save();
    }
}
