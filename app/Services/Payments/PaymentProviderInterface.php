<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;

interface PaymentProviderInterface
{
    /**
     * @return array{provider_reference:string,payment_url:string,status:string}
     */
    public function createPayment(TechnicalServiceMountPayment $payment): array;
}
