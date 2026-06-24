<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;
use App\Support\PartnerPortalPublicUrl;
use Illuminate\Support\Str;

class FakePaymentProvider implements PaymentProviderInterface
{
    public function createPayment(TechnicalServiceMountPayment $payment): array
    {
        $reference = $payment->provider_reference ?: 'fake_'.Str::random(40);
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $payload['provider_environment'] = config('payments.environment', 'local');

        $payment->forceFill([
            'provider' => 'fake',
            'provider_reference' => $reference,
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'payment_url' => PartnerPortalPublicUrl::paymentUrl(route('mount-payment.show', ['token' => $reference], false)),
            'raw_payload' => $payload,
        ])->save();

        return [
            'provider_reference' => $reference,
            'payment_url' => (string) $payment->payment_url,
            'status' => $payment->status,
        ];
    }
}
