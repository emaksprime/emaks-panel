<?php

namespace App\Services\Payments;

interface PaymentProviderGatewayClient
{
    public function send(PaymentProviderGatewayRequest $request): PaymentProviderGatewayResponse;
}
