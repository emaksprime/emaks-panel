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

        if (! $this->modeResolver->credentialsReady()
            || ! $this->modeResolver->providerSendReady()) {
            return PaymentProviderGatewayResponse::disabled(TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE);
        }

        return PaymentProviderGatewayResponse::fromArray([
            'ok' => true,
            'provider' => 'iyzico',
            'mode' => $this->modeResolver->gatewayMode(),
            'operation' => PaymentProviderGatewayRequest::OPERATION_PROVIDER_HEALTH_CHECK,
            'provider_status' => 'direct_laravel_ready',
            'provider_response_redacted' => [],
            'meta' => [
                'transport' => TechnicalServicePaymentProviderTransportResolver::TRANSPORT_DIRECT_LARAVEL,
            ],
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

    public function reconcilePayment(
        TechnicalServiceMountPayment $payment,
        string $providerPaymentReference,
    ): PaymentProviderGatewayResponse {
        return $this->send($this->buildRequest(
            PaymentProviderGatewayRequest::OPERATION_RECONCILE_PAYMENT,
            $payment,
            ['provider_payment_reference' => trim($providerPaymentReference)],
        ));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function buildRequest(string $operation, TechnicalServiceMountPayment $payment, array $metadata = []): PaymentProviderGatewayRequest
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $mode = $this->paymentMode($payment);
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

        $providerMetadata = array_merge($payload, $metadata);
        if (isset($providerMetadata['source'])) {
            $providerMetadata['payment_source'] = $providerMetadata['source'];
        }

        return new PaymentProviderGatewayRequest(
            provider: 'iyzico',
            mode: $mode,
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
            metadata: $this->safeMetadata(array_merge($providerMetadata, [
                'source' => 'technical_service',
                'environment' => $this->modeResolver->environment(),
                'gateway_mode' => $mode,
                'payment_provider' => 'iyzico',
                'provider_reference' => $payment->provider_reference,
                'payment_url' => $payment->payment_url,
                'provider_transport' => TechnicalServicePaymentProviderTransportResolver::TRANSPORT_DIRECT_LARAVEL,
                'payment_operation' => $operation,
            ])),
            dryRun: (bool) config('payments.gateway.dry_run', false),
            noSend: (bool) config('payments.gateway.no_send', false),
            allowProviderSend: (bool) config('payments.gateway.allow_provider_send', false),
        );
    }

    private function send(PaymentProviderGatewayRequest $request): PaymentProviderGatewayResponse
    {
        $this->modeResolver->assertReadyForRealProvider($request->mode());

        return $this->client->send($request);
    }

    private function paymentMode(TechnicalServiceMountPayment $payment): string
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $mode = Arr::get($payload, 'provider_mode')
            ?? Arr::get($payload, 'provider_decision.provider_mode')
            ?? Arr::get($payload, 'provider_gateway.mode')
            ?? $this->modeResolver->gatewayMode();

        return strtolower((string) $mode) === 'live' ? 'live' : 'sandbox';
    }

    private function description(?string $requestCode, ?string $serialNo, TechnicalServiceMountPayment $payment): string
    {
        $parts = array_filter([
            'EMAKS Teknik Servis',
            $requestCode ? 'MRN '.$requestCode : null,
            $serialNo ? 'Seri '.$serialNo : null,
            'Ödeme '.$payment->id,
        ]);

        return implode(' - ', $parts);
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
     * @param  array<string, mixed>  $metadata
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
