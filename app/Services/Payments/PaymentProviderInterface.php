<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;

interface PaymentProviderInterface
{
    /**
     * @return array{provider_reference:string,payment_url:string,status:string}
     */
    public function createPayment(TechnicalServiceMountPayment $payment): array;

    /**
     * @return array{provider_reference:string|null,payment_url:string|null,status:string}
     */
    public function updatePayment(TechnicalServiceMountPayment $payment): array;

    /**
     * @return array{provider_reference:string|null,payment_url:string|null,status:string}
     */
    public function cancelPayment(TechnicalServiceMountPayment $payment): array;

    /**
     * @return array{provider_reference:string|null,payment_url:string|null,status:string}
     */
    public function syncPayment(TechnicalServiceMountPayment $payment): array;
}
