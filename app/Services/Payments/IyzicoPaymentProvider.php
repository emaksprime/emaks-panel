<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;

class IyzicoPaymentProvider implements PaymentProviderInterface
{
    public function __construct(private readonly TechnicalServicePaymentProviderGateway $gateway) {}

    public function createPayment(TechnicalServiceMountPayment $payment): array
    {
        $response = $this->gateway->createLink($payment);

        if (! $response->ok()) {
            throw new TechnicalServicePaymentProviderClientException($response->errorMessage());
        }

        $payment->forceFill([
            'provider' => 'iyzico',
            'provider_reference' => $response->providerToken(),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'payment_url' => $response->paymentUrl(),
            'raw_payload' => array_merge(is_array($payment->raw_payload) ? $payment->raw_payload : [], [
                'provider_gateway' => $response->toArray(),
            ]),
        ])->save();

        return [
            'provider_reference' => (string) $payment->provider_reference,
            'payment_url' => (string) $payment->payment_url,
            'status' => (string) $payment->status,
        ];
    }
}
