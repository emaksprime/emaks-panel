<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;
use InvalidArgumentException;

class PaymentProviderManager
{
    public function createPayment(TechnicalServiceMountPayment $payment): array
    {
        return $this->provider()->createPayment($payment);
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
        $provider = $this->configuredProviderName();

        return str_starts_with($provider, 'iyzico') ? 'iyzico' : $provider;
    }

    public function environment(): string
    {
        return strtolower((string) config('payments.environment', 'local'));
    }

    private function configuredProviderName(): string
    {
        return strtolower((string) config('payments.provider', 'fake'));
    }
}
