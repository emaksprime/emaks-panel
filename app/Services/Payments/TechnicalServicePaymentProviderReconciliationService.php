<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Services\TechnicalService\TechnicalServicePaymentSettlementService;
use Illuminate\Support\Arr;

class TechnicalServicePaymentProviderReconciliationService
{
    public function __construct(
        private readonly TechnicalServicePaymentProviderGateway $gateway,
        private readonly TechnicalServicePaymentSettlementService $settlementService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildSyncStatusPayload(TechnicalServiceMountPayment $payment): array
    {
        return $this->gateway
            ->buildRequest(PaymentProviderGatewayRequest::OPERATION_SYNC_STATUS, $payment)
            ->toArray();
    }

    /**
     * @param PaymentProviderGatewayResponse|array<string, mixed> $response
     */
    public function handleProviderStatusResponse(
        TechnicalServiceMountPayment $payment,
        PaymentProviderGatewayResponse|array $response,
    ): TechnicalServiceMountPayment {
        $payload = $response instanceof PaymentProviderGatewayResponse
            ? $response->toArray()
            : PaymentProviderGatewayResponse::fromArray($response)->toArray();

        $payload = PaymentProviderGatewayResponse::redactProviderResponse($payload);

        if ((bool) ($payload['dry_run'] ?? false) || (bool) ($payload['no_send'] ?? false)) {
            return $this->storeReconciliationPayload($payment, $payload, 'no_send');
        }

        return match ($this->localStatusFromProvider($payload)) {
            TechnicalServiceMountPayment::STATUS_PAID => $this->markPaidFromTrustedProvider($payment, $payload),
            TechnicalServiceMountPayment::STATUS_CANCELLED => $this->markCancelledFromTrustedProvider($payment, $payload),
            TechnicalServiceMountPayment::STATUS_FAILED => $this->markFailedFromTrustedProvider($payment, $payload),
            default => $this->markPendingFromTrustedProvider($payment, $payload),
        };
    }

    /**
     * @param array<string, mixed> $providerResponse
     */
    public function markPaidFromTrustedProvider(
        TechnicalServiceMountPayment $payment,
        array $providerResponse,
    ): TechnicalServiceMountPayment {
        $providerResponse = PaymentProviderGatewayResponse::redactProviderResponse($providerResponse);

        if ($payment->status === TechnicalServiceMountPayment::STATUS_PAID) {
            return $this->storeReconciliationPayload($payment, $providerResponse, TechnicalServiceMountPayment::STATUS_PAID);
        }

        $reference = $this->providerReference($providerResponse) ?: $payment->provider_reference;
        $payload = $this->reconciliationPayload($payment, $providerResponse, TechnicalServiceMountPayment::STATUS_PAID);
        if ($reference !== null) {
            $payload['provider_reference'] = $reference;
        }

        $payment->forceFill([
            'provider' => $this->provider($providerResponse, $payment),
            'provider_reference' => $reference,
            'raw_payload' => $payload,
        ])->save();

        return $this->settlementService->markPaid($payment->fresh(), [
            'source' => 'provider_reconciliation',
            'provider' => $payment->provider,
            'provider_reference' => $reference,
            'provider_status' => $this->rawProviderStatus($providerResponse),
            'provider_response_redacted' => $providerResponse['provider_response_redacted'] ?? [],
        ]);
    }

    /**
     * @param array<string, mixed> $providerResponse
     */
    private function markCancelledFromTrustedProvider(
        TechnicalServiceMountPayment $payment,
        array $providerResponse,
    ): TechnicalServiceMountPayment {
        if ($payment->status === TechnicalServiceMountPayment::STATUS_PAID) {
            return $this->storeReconciliationPayload($payment, $providerResponse, TechnicalServiceMountPayment::STATUS_PAID);
        }

        $payment->forceFill([
            'status' => TechnicalServiceMountPayment::STATUS_CANCELLED,
            'raw_payload' => $this->reconciliationPayload($payment, $providerResponse, TechnicalServiceMountPayment::STATUS_CANCELLED),
        ])->save();

        $session = $payment->session;
        if ($session instanceof TechnicalServiceMountSession
            && $session->mount_payment_status !== TechnicalServiceMountSession::PAYMENT_PAID) {
            $session->forceFill(['mount_payment_status' => TechnicalServiceMountSession::PAYMENT_CANCELLED])->save();
        }

        return $payment->fresh();
    }

    /**
     * @param array<string, mixed> $providerResponse
     */
    private function markFailedFromTrustedProvider(
        TechnicalServiceMountPayment $payment,
        array $providerResponse,
    ): TechnicalServiceMountPayment {
        if (in_array($payment->status, [
            TechnicalServiceMountPayment::STATUS_PAID,
            TechnicalServiceMountPayment::STATUS_CANCELLED,
        ], true)) {
            return $this->storeReconciliationPayload($payment, $providerResponse, $payment->status);
        }

        $payment->forceFill([
            'status' => TechnicalServiceMountPayment::STATUS_FAILED,
            'raw_payload' => $this->reconciliationPayload($payment, $providerResponse, TechnicalServiceMountPayment::STATUS_FAILED),
        ])->save();

        return $payment->fresh();
    }

    /**
     * @param array<string, mixed> $providerResponse
     */
    private function markPendingFromTrustedProvider(
        TechnicalServiceMountPayment $payment,
        array $providerResponse,
    ): TechnicalServiceMountPayment {
        if ($payment->status === TechnicalServiceMountPayment::STATUS_PAID) {
            return $this->storeReconciliationPayload($payment, $providerResponse, TechnicalServiceMountPayment::STATUS_PAID);
        }

        $payment->forceFill([
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'raw_payload' => $this->reconciliationPayload($payment, $providerResponse, TechnicalServiceMountPayment::STATUS_PENDING),
        ])->save();

        return $payment->fresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function localStatusFromProvider(array $payload): string
    {
        $status = strtolower((string) ($payload['provider_status']
            ?? $payload['raw_status']
            ?? Arr::get($payload, 'provider_response_redacted.status')
            ?? Arr::get($payload, 'provider_response_redacted.paymentStatus')
            ?? Arr::get($payload, 'provider_response_redacted.data.status')
            ?? ''));

        return match ($status) {
            'paid', 'success', 'successful', 'succeeded', 'completed', 'complete', 'payment_success' => TechnicalServiceMountPayment::STATUS_PAID,
            'cancelled', 'canceled', 'passive', 'deleted', 'inactive' => TechnicalServiceMountPayment::STATUS_CANCELLED,
            'failed', 'failure', 'error', 'provider_error' => TechnicalServiceMountPayment::STATUS_FAILED,
            default => TechnicalServiceMountPayment::STATUS_PENDING,
        };
    }

    /**
     * @param array<string, mixed> $providerResponse
     * @return array<string, mixed>
     */
    private function reconciliationPayload(
        TechnicalServiceMountPayment $payment,
        array $providerResponse,
        string $localStatus,
    ): array {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $payload['provider_reconciliation'] = [
            'status' => $localStatus,
            'provider_status' => $this->rawProviderStatus($providerResponse),
            'provider_response_redacted' => $providerResponse['provider_response_redacted'] ?? [],
            'reconciled_at' => now()->toIso8601String(),
        ];

        return $payload;
    }

    /**
     * @param array<string, mixed> $providerResponse
     */
    private function storeReconciliationPayload(
        TechnicalServiceMountPayment $payment,
        array $providerResponse,
        string $localStatus,
    ): TechnicalServiceMountPayment {
        $payment->forceFill([
            'raw_payload' => $this->reconciliationPayload($payment, $providerResponse, $localStatus),
        ])->save();

        return $payment->fresh();
    }

    /**
     * @param array<string, mixed> $providerResponse
     */
    private function providerReference(array $providerResponse): ?string
    {
        foreach (['provider_token', 'provider_reference', 'token', 'payment_id'] as $key) {
            $value = $providerResponse[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $providerResponse
     */
    private function rawProviderStatus(array $providerResponse): ?string
    {
        $value = $providerResponse['provider_status']
            ?? $providerResponse['raw_status']
            ?? Arr::get($providerResponse, 'provider_response_redacted.status')
            ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param array<string, mixed> $providerResponse
     */
    private function provider(array $providerResponse, TechnicalServiceMountPayment $payment): string
    {
        $provider = $providerResponse['provider'] ?? $payment->provider ?? 'iyzico';

        return is_scalar($provider) && trim((string) $provider) !== '' ? trim((string) $provider) : 'iyzico';
    }
}
