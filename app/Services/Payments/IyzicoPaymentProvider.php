<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;
use RuntimeException;

class IyzicoPaymentProvider implements PaymentProviderInterface
{
    public function createPayment(TechnicalServiceMountPayment $payment): array
    {
        $apiKey = config('payments.iyzico.api_key');
        $secretKey = config('payments.iyzico.secret_key');

        if (! filled($apiKey) || ! filled($secretKey)) {
            throw new RuntimeException('Iyzico odeme saglayicisi icin API anahtari tanimli degil.');
        }

        throw new RuntimeException('Iyzico odeme saglayicisi iskeleti hazir; sandbox/live entegrasyonu etkin degil.');
    }
}
