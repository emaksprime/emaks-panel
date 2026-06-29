<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;

class IyzicoPaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private readonly TechnicalServicePaymentProviderGateway $gateway,
        private readonly TechnicalServicePaymentProviderReconciliationService $reconciliationService,
    ) {}

    public function createPayment(TechnicalServiceMountPayment $payment): array
    {
        $response = $this->gateway->createLink($payment);

        $this->assertUsablePaymentLinkResponse($response);

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

    public function updatePayment(TechnicalServiceMountPayment $payment): array
    {
        $response = $this->gateway->updateLink($payment);

        $this->assertUsablePaymentLinkResponse($response);

        $payment->forceFill([
            'provider' => 'iyzico',
            'provider_reference' => $response->providerToken() ?: $payment->provider_reference,
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'payment_url' => $response->paymentUrl() ?: $payment->payment_url,
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

    public function cancelPayment(TechnicalServiceMountPayment $payment): array
    {
        $response = $this->gateway->cancelLink($payment);

        $this->assertProviderMutationResponse($response);

        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $payload['provider_gateway_cancel'] = $response->toArray();
        $payment->forceFill(['raw_payload' => $payload])->save();

        return [
            'provider_reference' => $payment->provider_reference,
            'payment_url' => $payment->payment_url,
            'status' => (string) $payment->status,
        ];
    }

    public function syncPayment(TechnicalServiceMountPayment $payment): array
    {
        $response = $this->gateway->syncStatus($payment);

        $this->assertProviderMutationResponse($response);

        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $payload['provider_gateway_sync'] = $response->toArray();
        $payment->forceFill(['raw_payload' => $payload])->save();
        $payment = $this->reconciliationService->handleProviderStatusResponse($payment->fresh(), $response);

        return [
            'provider_reference' => $payment->provider_reference,
            'payment_url' => $payment->payment_url,
            'status' => (string) $payment->status,
        ];
    }

    private function assertUsablePaymentLinkResponse(PaymentProviderGatewayResponse $response): void
    {
        $this->assertProviderMutationResponse($response);

        if ($response->paymentUrl() === null || $response->providerToken() === null) {
            throw new TechnicalServicePaymentProviderClientException('Gerçek ödeme sağlayıcısı kullanılabilir ödeme linki döndürmedi.');
        }
    }

    private function assertProviderMutationResponse(PaymentProviderGatewayResponse $response): void
    {
        if (! $response->ok()) {
            throw new TechnicalServicePaymentProviderClientException($response->errorMessage());
        }

        if ($response->dryRun() || $response->noSend()) {
            throw new TechnicalServicePaymentProviderClientException('Gerçek ödeme sağlayıcısı no-send/dry-run yanıtı döndü; gerçek ödeme işlemi yapılmadı.');
        }
    }
}
