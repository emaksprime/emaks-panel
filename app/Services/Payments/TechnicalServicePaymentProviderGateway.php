<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceRequest;
use Illuminate\Support\Arr;

class TechnicalServicePaymentProviderGateway
{
    public function __construct(
        private readonly TechnicalServicePaymentProviderModeResolver $modeResolver,
        private readonly PaymentProviderGatewayClient $client,
    ) {}

    public function healthCheck(): PaymentProviderGatewayResponse
    {
        if (! $this->modeResolver->realProviderEnabled()) {
            return PaymentProviderGatewayResponse::disabled('Gerçek ödeme sağlayıcısı devre dışı.');
        }

        if (! $this->modeResolver->gatewayConfigured() || ! $this->modeResolver->gatewayHealthVerified()) {
            return PaymentProviderGatewayResponse::disabled(TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE);
        }

        return PaymentProviderGatewayResponse::fromArray([
            'ok' => false,
            'provider' => 'iyzico',
            'mode' => $this->modeResolver->gatewayMode(),
            'operation' => PaymentProviderGatewayRequest::OPERATION_PROVIDER_HEALTH_CHECK,
            'error_code' => 'http_disabled',
            'error_message' => TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE,
            'provider_response_redacted' => [],
        ]);
    }

    public function createLink(TechnicalServiceMountPayment $payment): PaymentProviderGatewayResponse
    {
        return $this->send($this->buildRequest(PaymentProviderGatewayRequest::OPERATION_CREATE_LINK, $payment));
    }

    public function updateLink(TechnicalServiceMountPayment $payment): PaymentProviderGatewayResponse
    {
        return $this->send($this->buildRequest(PaymentProviderGatewayRequest::OPERATION_UPDATE_LINK, $payment));
    }

    public function cancelLink(TechnicalServiceMountPayment $payment): PaymentProviderGatewayResponse
    {
        return $this->send($this->buildRequest(PaymentProviderGatewayRequest::OPERATION_CANCEL_LINK, $payment));
    }

    public function getLink(TechnicalServiceMountPayment $payment): PaymentProviderGatewayResponse
    {
        return $this->send($this->buildRequest(PaymentProviderGatewayRequest::OPERATION_GET_LINK, $payment));
    }

    public function syncStatus(TechnicalServiceMountPayment $payment): PaymentProviderGatewayResponse
    {
        return $this->send($this->buildRequest(PaymentProviderGatewayRequest::OPERATION_SYNC_STATUS, $payment));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function buildRequest(string $operation, TechnicalServiceMountPayment $payment, array $metadata = []): PaymentProviderGatewayRequest
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $request = $payment->technicalServiceRequest;
        $requestId = $payment->technical_service_request_id ?: Arr::get($payload, 'technical_service_request_id');
        $requestCode = $this->stringValue(Arr::get($payload, 'request_code'))
            ?: $this->stringValue(Arr::get($payload, 'mrn'))
            ?: $request?->mrn;
        $rootMrn = $this->stringValue(Arr::get($payload, 'root_mrn'))
            ?: $request?->root_mrn
            ?: $requestCode;
        $serialNo = $this->stringValue(Arr::get($payload, 'serial_number'))
            ?: $request?->serial_number;
        $customerName = $this->stringValue(Arr::get($payload, 'customer_name'))
            ?: $request?->customer_name;
        $customerPhone = $this->stringValue(Arr::get($payload, 'customer_phone'))
            ?: $request?->customer_phone;
        $customerEmail = $this->stringValue(Arr::get($payload, 'customer_email'))
            ?: $this->requestEmail($request);

        return new PaymentProviderGatewayRequest(
            provider: 'iyzico',
            mode: $this->modeResolver->gatewayMode(),
            operation: $operation,
            paymentId: (string) $payment->id,
            requestId: $requestId !== null ? (string) $requestId : null,
            requestCode: $requestCode,
            rootMrn: $rootMrn,
            serialNo: $serialNo,
            customer: [
                'name' => $customerName,
                'phone' => $customerPhone,
                'email' => $customerEmail,
            ],
            amount: number_format((float) $payment->amount, 2, '.', ''),
            currency: strtoupper((string) ($payment->currency ?: 'TRY')),
            description: $this->description($requestCode, $serialNo, $payment),
            conversationId: 'payment:'.$payment->id,
            idempotencyKey: $this->idempotencyKey($operation, $payment),
            callbackUrl: $this->stringValue(config('payments.iyzico.callback_url')),
            returnUrl: null,
            metadata: $this->safeMetadata(array_merge($payload, $metadata)),
        );
    }

    private function send(PaymentProviderGatewayRequest $request): PaymentProviderGatewayResponse
    {
        $this->modeResolver->assertReadyForRealProvider();

        return $this->client->send($request);
    }

    private function description(?string $requestCode, ?string $serialNo, TechnicalServiceMountPayment $payment): string
    {
        $parts = array_filter([
            $requestCode ? 'Talep: '.$requestCode : null,
            $serialNo ? 'Seri: '.$serialNo : null,
            'Ödeme: '.$payment->id,
        ]);

        return implode(' / ', $parts);
    }

    private function idempotencyKey(string $operation, TechnicalServiceMountPayment $payment): string
    {
        return implode(':', [
            'technical_service_payment',
            (string) $payment->id,
            $operation,
            'v1',
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function safeMetadata(array $metadata): array
    {
        return PaymentProviderGatewayResponse::redactProviderResponse($metadata);
    }

    private function requestEmail(?TechnicalServiceRequest $request): ?string
    {
        if (! $request instanceof TechnicalServiceRequest) {
            return null;
        }

        $payload = is_array($request->qr_context_payload) ? $request->qr_context_payload : [];

        return $this->stringValue(Arr::get($payload, 'customer.email'));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
