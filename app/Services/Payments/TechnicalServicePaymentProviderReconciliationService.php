<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Services\TechnicalService\TechnicalServicePaymentSettlementService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Throwable;

class TechnicalServicePaymentProviderReconciliationService
{
    public function __construct(
        private readonly TechnicalServicePaymentProviderGateway $gateway,
        private readonly TechnicalServicePaymentSettlementService $settlementService,
        private readonly TechnicalServicePaymentReceiptNotificationService $receiptNotificationService,
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
     * @param  PaymentProviderGatewayResponse|array<string, mixed>  $response
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

        return match ($this->localStatusFromProvider($payload, $payment)) {
            TechnicalServiceMountPayment::STATUS_PAID => $this->markPaidFromTrustedProvider($payment, $payload),
            TechnicalServiceMountPayment::STATUS_CANCELLED => $this->markCancelledFromTrustedProvider($payment, $payload),
            TechnicalServiceMountPayment::STATUS_FAILED => $this->markFailedFromTrustedProvider($payment, $payload),
            default => $this->markPendingFromTrustedProvider($payment, $payload),
        };
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    public function markPaidFromTrustedProvider(
        TechnicalServiceMountPayment $payment,
        array $providerResponse,
    ): TechnicalServiceMountPayment {
        $providerResponse = PaymentProviderGatewayResponse::redactProviderResponse($providerResponse);

        if ($payment->status === TechnicalServiceMountPayment::STATUS_PAID) {
            return $this->storeReconciliationPayload($payment, $providerResponse, TechnicalServiceMountPayment::STATUS_PAID);
        }

        if ($payment->status === TechnicalServiceMountPayment::STATUS_CANCELLED) {
            return $this->storeReconciliationPayload($payment, $providerResponse, TechnicalServiceMountPayment::STATUS_CANCELLED, [
                'blocked_reason' => 'paid_after_cancel_requires_admin_review',
            ]);
        }

        $reference = $this->providerReference($providerResponse) ?: $payment->provider_reference;
        $paymentReference = $this->providerPaymentReference($providerResponse);
        $transactionReference = $this->providerTransactionReference($providerResponse);
        $receiptReference = $this->providerReceiptReference($providerResponse);
        $providerPaidAt = $this->providerPaidAt($providerResponse);
        $payload = $this->reconciliationPayload($payment, $providerResponse, TechnicalServiceMountPayment::STATUS_PAID);
        if ($reference !== null) {
            $payload['provider_reference'] = $reference;
        }
        $payload['provider_payment_reference'] = $paymentReference;
        $payload['provider_transaction_reference'] = $transactionReference;
        $payload['provider_receipt_reference'] = $receiptReference;
        $payload['provider_paid_at'] = $providerPaidAt?->toIso8601String();

        $payment->forceFill([
            'provider' => $this->provider($providerResponse, $payment),
            'provider_reference' => $reference,
            'provider_payment_reference' => $paymentReference,
            'provider_transaction_reference' => $transactionReference,
            'provider_receipt_reference' => $receiptReference,
            'provider_paid_at' => $providerPaidAt,
            'raw_payload' => $payload,
        ] + $this->syncAuditAttributes($payment, TechnicalServiceMountPayment::STATUS_PAID))->save();

        $paidPayment = $this->settlementService->markPaid($payment->fresh(), [
            'source' => 'provider_reconciliation',
            'provider' => $payment->provider,
            'provider_reference' => $reference,
            'provider_payment_reference' => $paymentReference,
            'provider_transaction_reference' => $transactionReference,
            'provider_receipt_reference' => $receiptReference,
            'provider_paid_at' => $providerPaidAt?->toIso8601String(),
            'provider_status' => $this->rawProviderStatus($providerResponse),
            'provider_response_redacted' => $providerResponse['provider_response_redacted'] ?? [],
        ]);

        return $this->receiptNotificationService->notifyTrustedPaid($paidPayment, $providerResponse);
    }

    /**
     * @param  array<string, mixed>  $providerResponse
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
        ] + $this->syncAuditAttributes($payment, TechnicalServiceMountPayment::STATUS_CANCELLED))->save();

        $session = $payment->session;
        if ($session instanceof TechnicalServiceMountSession
            && $session->mount_payment_status !== TechnicalServiceMountSession::PAYMENT_PAID) {
            $session->forceFill(['mount_payment_status' => TechnicalServiceMountSession::PAYMENT_CANCELLED])->save();
        }

        return $payment->fresh();
    }

    /**
     * @param  array<string, mixed>  $providerResponse
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
        ] + $this->syncAuditAttributes(
            $payment,
            TechnicalServiceMountPayment::STATUS_FAILED,
            $this->syncErrorFromProvider($providerResponse, TechnicalServiceMountPayment::STATUS_FAILED),
        ))->save();

        return $payment->fresh();
    }

    /**
     * @param  array<string, mixed>  $providerResponse
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
        ] + $this->syncAuditAttributes($payment, TechnicalServiceMountPayment::STATUS_PENDING))->save();

        return $payment->fresh();
    }

    public function recordSyncFailure(
        TechnicalServiceMountPayment $payment,
        Throwable|string $error,
        string $source = 'scheduled_reconcile',
    ): TechnicalServiceMountPayment {
        $message = $this->redactedError($error);
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $payload['provider_reconciliation'] = array_merge(
            is_array($payload['provider_reconciliation'] ?? null) ? $payload['provider_reconciliation'] : [],
            [
                'status' => 'provider_error',
                'provider_status' => 'provider_error',
                'provider_response_redacted' => [],
                'reconciled_at' => now()->toIso8601String(),
                'source' => $source,
                'error_message' => $message,
            ],
        );

        $payment->forceFill([
            'raw_payload' => $payload,
        ] + $this->syncAuditAttributes($payment, 'provider_error', $message))->save();

        return $payment->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function localStatusFromProvider(array $payload, TechnicalServiceMountPayment $payment): string
    {
        if ($this->isIyzicoProvider($payload, $payment)) {
            return $this->localStatusFromIyzicoLink($payload, $payment);
        }

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
     * @param  array<string, mixed>  $payload
     */
    private function localStatusFromIyzicoLink(array $payload, TechnicalServiceMountPayment $payment): string
    {
        if (! (bool) ($payload['ok'] ?? true)) {
            return TechnicalServiceMountPayment::STATUS_FAILED;
        }

        if ($this->hasTrustedIyzicoLinkSoldSignal($payload, $payment)) {
            return TechnicalServiceMountPayment::STATUS_PAID;
        }

        $status = strtolower((string) ($payload['provider_status']
            ?? $payload['raw_status']
            ?? Arr::get($payload, 'provider_response_redacted.data.productStatus')
            ?? Arr::get($payload, 'provider_response_redacted.data.status')
            ?? Arr::get($payload, 'provider_response_redacted.productStatus')
            ?? Arr::get($payload, 'provider_response_redacted.status')
            ?? ''));

        return match ($status) {
            'cancelled', 'canceled', 'passive', 'deleted', 'inactive' => TechnicalServiceMountPayment::STATUS_CANCELLED,
            'failed', 'failure', 'error', 'provider_error' => TechnicalServiceMountPayment::STATUS_FAILED,
            default => TechnicalServiceMountPayment::STATUS_PENDING,
        };
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     * @return array<string, mixed>
     */
    private function reconciliationPayload(
        TechnicalServiceMountPayment $payment,
        array $providerResponse,
        string $localStatus,
        array $extra = [],
    ): array {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $payload['provider_reconciliation'] = array_merge([
            'status' => $localStatus,
            'provider_status' => $this->rawProviderStatus($providerResponse),
            'provider_payment_reference' => $this->providerPaymentReference($providerResponse),
            'provider_transaction_reference' => $this->providerTransactionReference($providerResponse),
            'provider_receipt_reference' => $this->providerReceiptReference($providerResponse),
            'provider_paid_at' => $this->providerPaidAt($providerResponse)?->toIso8601String(),
            'provider_response_redacted' => $providerResponse['provider_response_redacted'] ?? [],
            'reconciled_at' => now()->toIso8601String(),
        ], $extra);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function storeReconciliationPayload(
        TechnicalServiceMountPayment $payment,
        array $providerResponse,
        string $localStatus,
        array $extra = [],
    ): TechnicalServiceMountPayment {
        $syncStatus = is_scalar($extra['blocked_reason'] ?? null)
            ? (string) $extra['blocked_reason']
            : $localStatus;

        $payment->forceFill([
            'raw_payload' => $this->reconciliationPayload($payment, $providerResponse, $localStatus, $extra),
        ] + $this->syncAuditAttributes(
            $payment,
            $syncStatus,
            $this->syncErrorFromProvider($providerResponse, $syncStatus, $extra),
        ))->save();

        return $payment->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function syncAuditAttributes(
        TechnicalServiceMountPayment $payment,
        string $status,
        ?string $error = null,
    ): array {
        $attributes = [
            'provider_last_synced_at' => now(),
            'provider_sync_attempts' => max(0, (int) ($payment->provider_sync_attempts ?? 0)) + 1,
            'provider_last_sync_status' => $status,
            'provider_last_sync_error' => $error,
        ];

        if ($status === TechnicalServiceMountPayment::STATUS_PAID) {
            $attributes['provider_paid_confirmed_at'] = $payment->provider_paid_confirmed_at ?: now();
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     * @param  array<string, mixed>  $extra
     */
    private function syncErrorFromProvider(array $providerResponse, string $status, array $extra = []): ?string
    {
        if (is_scalar($extra['blocked_reason'] ?? null) && trim((string) $extra['blocked_reason']) !== '') {
            return $this->redactedError((string) $extra['blocked_reason']);
        }

        if (! in_array($status, [TechnicalServiceMountPayment::STATUS_FAILED, 'provider_error'], true)) {
            return null;
        }

        $message = $providerResponse['error_message']
            ?? $providerResponse['message']
            ?? Arr::get($providerResponse, 'provider_response_redacted.errorMessage')
            ?? Arr::get($providerResponse, 'provider_response_redacted.error_message')
            ?? null;

        return $this->redactedError(is_scalar($message) ? (string) $message : 'provider_error');
    }

    private function redactedError(Throwable|string $error): string
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        $message = trim($message) !== '' ? $message : 'provider_error';
        $patterns = [
            '/IYZWSv2\s+[A-Za-z0-9+\/=._:-]+/i',
            '/(Authorization|api[_-]?key|secret[_-]?key|password|smtp[_-]?password)\s*[:=]\s*[^,\s]+/i',
            '/(secret|password|credential|token)[A-Za-z0-9+\/=._:-]{8,}/i',
        ];

        return mb_substr(preg_replace($patterns, '$1 [redacted]', $message) ?? '[redacted]', 0, 500);
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function providerReference(array $providerResponse): ?string
    {
        foreach (['provider_token', 'provider_reference', 'token'] as $key) {
            $value = $providerResponse[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        foreach ([
            'provider_response_redacted.data.token',
            'provider_response_redacted.token',
        ] as $key) {
            $value = Arr::get($providerResponse, $key);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        $paymentId = $providerResponse['payment_id'] ?? null;
        if (is_scalar($paymentId) && trim((string) $paymentId) !== '') {
            return trim((string) $paymentId);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function providerPaymentReference(array $providerResponse): ?string
    {
        foreach ([
            'provider_payment_reference',
            'payment_reference',
            'paymentId',
            'provider_response_redacted.paymentId',
            'provider_response_redacted.payment_id',
            'provider_response_redacted.data.paymentId',
            'provider_response_redacted.data.payment_id',
            'provider_response_redacted.payments.0.paymentId',
            'provider_response_redacted.payments.0.payment_id',
        ] as $key) {
            $value = str_contains($key, '.') ? Arr::get($providerResponse, $key) : ($providerResponse[$key] ?? null);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function providerTransactionReference(array $providerResponse): ?string
    {
        foreach ([
            'provider_transaction_reference',
            'payment_transaction_id',
            'paymentTransactionId',
            'transaction_id',
            'provider_response_redacted.paymentTransactionId',
            'provider_response_redacted.payment_transaction_id',
            'provider_response_redacted.data.paymentTransactionId',
            'provider_response_redacted.data.payment_transaction_id',
            'provider_response_redacted.itemTransactions.0.paymentTransactionId',
            'provider_response_redacted.data.itemTransactions.0.paymentTransactionId',
            'provider_response_redacted.payments.0.itemTransactions.0.paymentTransactionId',
            'provider_response_redacted.hostReference',
            'provider_response_redacted.data.hostReference',
            'provider_response_redacted.payments.0.hostReference',
        ] as $key) {
            $value = str_contains($key, '.') ? Arr::get($providerResponse, $key) : ($providerResponse[$key] ?? null);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * Iyzico Link API does not document a receipt/dekont number in link detail/list responses.
     *
     * @param  array<string, mixed>  $providerResponse
     */
    private function providerReceiptReference(array $providerResponse): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function providerPaidAt(array $providerResponse): ?CarbonInterface
    {
        foreach ([
            'provider_paid_at',
            'paid_at',
            'paidAt',
            'provider_response_redacted.createdDate',
            'provider_response_redacted.data.createdDate',
            'provider_response_redacted.payments.0.createdDate',
        ] as $key) {
            $value = str_contains($key, '.') ? Arr::get($providerResponse, $key) : ($providerResponse[$key] ?? null);
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            try {
                return CarbonImmutable::parse((string) $value);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function rawProviderStatus(array $providerResponse): ?string
    {
        $value = $providerResponse['provider_status']
            ?? $providerResponse['raw_status']
            ?? Arr::get($providerResponse, 'provider_response_redacted.data.productStatus')
            ?? Arr::get($providerResponse, 'provider_response_redacted.data.status')
            ?? Arr::get($providerResponse, 'provider_response_redacted.status')
            ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function provider(array $providerResponse, TechnicalServiceMountPayment $payment): string
    {
        $provider = $providerResponse['provider'] ?? $payment->provider ?? 'iyzico';

        return is_scalar($provider) && trim((string) $provider) !== '' ? trim((string) $provider) : 'iyzico';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isIyzicoProvider(array $payload, TechnicalServiceMountPayment $payment): bool
    {
        return strtolower($this->provider($payload, $payment)) === 'iyzico';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasTrustedIyzicoLinkSoldSignal(array $payload, TechnicalServiceMountPayment $payment): bool
    {
        $linkPayload = $this->matchingIyzicoLinkPayload($payload, $payment);
        if ($linkPayload === null) {
            return false;
        }

        $soldCount = $this->numericValue(Arr::get($linkPayload, 'soldCount'));
        if ($soldCount === null || $soldCount <= 0) {
            return false;
        }

        if (! $this->conversationMatches($payload, $payment)) {
            return false;
        }

        if (! $this->amountMatches($linkPayload, $payment)) {
            return false;
        }

        return $this->currencyMatches($linkPayload, $payment);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function matchingIyzicoLinkPayload(array $payload, TechnicalServiceMountPayment $payment): ?array
    {
        $providerResponse = is_array($payload['provider_response_redacted'] ?? null)
            ? $payload['provider_response_redacted']
            : [];
        $data = Arr::get($providerResponse, 'data');

        if (is_array($data) && is_array($data['items'] ?? null)) {
            foreach ($data['items'] as $item) {
                if (is_array($item) && $this->tokenMatches($item, $payload, $payment)) {
                    return $item;
                }
            }

            return null;
        }

        if (is_array($data) && $this->tokenMatches($data, $payload, $payment)) {
            return $data;
        }

        if ($this->tokenMatches($providerResponse, $payload, $payment)) {
            return $providerResponse;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $payload
     */
    private function tokenMatches(array $candidate, array $payload, TechnicalServiceMountPayment $payment): bool
    {
        $expected = $this->stringValue($payment->provider_reference);
        if ($expected === null) {
            return false;
        }

        $token = $this->stringValue(Arr::get($candidate, 'token'))
            ?? $this->stringValue($payload['provider_token'] ?? null)
            ?? $this->stringValue($payload['provider_reference'] ?? null)
            ?? $this->stringValue(Arr::get($payload, 'provider_response_redacted.data.token'));

        return $token !== null && hash_equals($expected, $token);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function conversationMatches(array $payload, TechnicalServiceMountPayment $payment): bool
    {
        $actual = $this->stringValue($payload['conversation_id'] ?? null)
            ?? $this->stringValue(Arr::get($payload, 'provider_response_redacted.conversationId'));
        if ($actual === null) {
            return true;
        }

        $paymentPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $expected = $this->stringValue(Arr::get($paymentPayload, 'provider_gateway.conversation_id'))
            ?? 'payment:'.$payment->id;

        return hash_equals($expected, $actual);
    }

    /**
     * @param  array<string, mixed>  $linkPayload
     */
    private function amountMatches(array $linkPayload, TechnicalServiceMountPayment $payment): bool
    {
        $price = $this->numericValue(Arr::get($linkPayload, 'price'));
        if ($price === null) {
            return true;
        }

        return (int) round($price * 100) === (int) round((float) $payment->amount * 100);
    }

    /**
     * @param  array<string, mixed>  $linkPayload
     */
    private function currencyMatches(array $linkPayload, TechnicalServiceMountPayment $payment): bool
    {
        $currency = $this->stringValue(Arr::get($linkPayload, 'currencyCode'));
        if ($currency === null) {
            return true;
        }

        return strtoupper($currency) === strtoupper((string) ($payment->currency ?: 'TRY'));
    }

    private function numericValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
