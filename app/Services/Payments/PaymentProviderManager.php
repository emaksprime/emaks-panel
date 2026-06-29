<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;
use InvalidArgumentException;

class PaymentProviderManager
{
    public function __construct(private readonly TechnicalServicePaymentProviderModeResolver $modeResolver) {}

    public function createPayment(TechnicalServiceMountPayment $payment): array
    {
        return $this->provider()->createPayment($payment);
    }

    public function updatePayment(TechnicalServiceMountPayment $payment): array
    {
        return $this->providerForPayment($payment)->updatePayment($payment);
    }

    public function cancelPayment(TechnicalServiceMountPayment $payment): array
    {
        return $this->providerForPayment($payment)->cancelPayment($payment);
    }

    public function syncPayment(TechnicalServiceMountPayment $payment): array
    {
        return $this->providerForPayment($payment)->syncPayment($payment);
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

    private function providerForPayment(TechnicalServiceMountPayment $payment): PaymentProviderInterface
    {
        return match (strtolower((string) ($payment->provider ?: $this->configuredProviderName()))) {
            'fake' => app(FakePaymentProvider::class),
            'iyzico', 'iyzico_sandbox', 'iyzico_live' => app(IyzicoPaymentProvider::class),
            default => throw new InvalidArgumentException('Desteklenmeyen odeme saglayicisi.'),
        };
    }
}
