<?php

namespace App\Services\Payments;

class N8nIyzicoPaymentProviderClient implements PaymentProviderGatewayClient
{
    public function send(PaymentProviderGatewayRequest $request): PaymentProviderGatewayResponse
    {
        throw new TechnicalServicePaymentProviderDisabledException(
            TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE
        );
    }
}
