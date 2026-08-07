<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Services\Messaging\TechnicalServiceWorkflowMessageDispatchService;
use App\Services\TechnicalService\TechnicalServicePaymentSettlementService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class TechnicalServicePaymentProviderReconciliationService
{
    public function __construct(
        private readonly TechnicalServicePaymentProviderGateway $gateway,
        private readonly TechnicalServicePaymentSettlementService $settlementService,
        private readonly TechnicalServicePaymentReceiptNotificationService $receiptNotificationService,
        private readonly TechnicalServiceWorkflowMessageDispatchService $workflowMessages,
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
     * Read-only verification for an exact Iyzico payment result. Provider reads
     * are allowed, but no local reconciliation state is written.
     *
     * @return array<string, mixed>
     */
    public function verifyExactProviderPaymentResult(
        TechnicalServiceMountPayment $payment,
        string $providerPaymentReference,
    ): array {
        return $this->exactProviderPaymentResult($payment, $providerPaymentReference)['proof'];
    }

    /**
     * @return array{payment:TechnicalServiceMountPayment,proof:array<string,mixed>}
     */
    public function reconcileExactProviderPaymentResult(
        TechnicalServiceMountPayment $payment,
        string $providerPaymentReference,
    ): array {
        $result = $this->exactProviderPaymentResult($payment, $providerPaymentReference);
        $providerResponse = $result['provider_response'];
        $providerResponse['meta'] = array_merge(
            is_array($providerResponse['meta'] ?? null) ? $providerResponse['meta'] : [],
            ['exact_reconciliation' => $result['proof']],
        );

        return [
            'payment' => $this->markPaidFromTrustedProvider($payment->fresh() ?? $payment, $providerResponse, false),
            'proof' => $result['proof'],
        ];
    }

    /**
     * @return array{proof:array<string,mixed>,provider_response:array<string,mixed>}
     */
    private function exactProviderPaymentResult(
        TechnicalServiceMountPayment $payment,
        string $providerPaymentReference,
    ): array {
        $payment = $payment->fresh() ?? $payment;
        $providerPaymentReference = trim($providerPaymentReference);
        if ($providerPaymentReference === '') {
            $this->exactReconciliationMismatch('provider_payment_reference_missing');
        }
        if (! in_array($payment->status, [
            TechnicalServiceMountPayment::STATUS_PENDING,
            TechnicalServiceMountPayment::STATUS_PAID,
        ], true)) {
            $this->exactReconciliationMismatch('local_payment_not_reconcilable');
        }
        if (strtolower(trim((string) $payment->provider)) !== 'iyzico') {
            $this->exactReconciliationMismatch('provider_family_mismatch');
        }
        if ($this->paymentProviderMode($payment) !== 'sandbox') {
            $this->exactReconciliationMismatch('provider_mode_not_sandbox');
        }
        if ($this->stringValue($payment->provider_payment_reference) !== null
            && ! hash_equals((string) $payment->provider_payment_reference, $providerPaymentReference)) {
            $this->exactReconciliationMismatch('stored_provider_payment_reference_mismatch');
        }

        $expectedConversation = 'payment:'.$payment->id;
        $expectedToken = $this->stringValue($payment->provider_reference);
        if ($expectedToken === null) {
            $this->exactReconciliationMismatch('stored_provider_link_token_missing');
        }
        $businessIdentity = $this->assertExactPaymentBusinessIdentity($payment);
        $this->assertStoredSuccessfulProviderSession($payment, $expectedToken, $expectedConversation);

        $linkResponse = $this->gateway->syncStatus($payment)->toArray();
        $reportingResponse = $this->gateway
            ->reconcilePayment($payment, $providerPaymentReference)
            ->toArray();

        $linkPayload = is_array($linkResponse['provider_response_redacted'] ?? null)
            ? $linkResponse['provider_response_redacted']
            : [];
        if (! (bool) ($linkResponse['ok'] ?? false)
            || (string) ($linkResponse['operation'] ?? '') !== PaymentProviderGatewayRequest::OPERATION_SYNC_STATUS
            || strtolower((string) ($linkResponse['provider'] ?? '')) !== 'iyzico'
            || strtolower((string) ($linkResponse['mode'] ?? '')) !== 'sandbox') {
            $this->exactReconciliationMismatch('provider_link_response_invalid');
        }
        if (! hash_equals($expectedToken, (string) Arr::get($linkPayload, 'data.token', ''))
            || ! hash_equals($expectedConversation, (string) Arr::get($linkPayload, 'data.conversationId', ''))
            || (int) Arr::get($linkPayload, 'data.soldCount', 0) < 1
            || ! $this->amountEquals(Arr::get($linkPayload, 'data.price'), $payment->amount)
            || strtoupper((string) Arr::get($linkPayload, 'data.currencyCode', '')) !== strtoupper((string) $payment->currency)) {
            $this->exactReconciliationMismatch('provider_link_identity_mismatch');
        }

        $reportPayload = is_array($reportingResponse['provider_response_redacted'] ?? null)
            ? $reportingResponse['provider_response_redacted']
            : [];
        $payments = is_array($reportPayload['payments'] ?? null) ? array_values($reportPayload['payments']) : [];
        if (! (bool) ($reportingResponse['ok'] ?? false)
            || (string) ($reportingResponse['operation'] ?? '') !== PaymentProviderGatewayRequest::OPERATION_RECONCILE_PAYMENT
            || strtolower((string) ($reportingResponse['provider'] ?? '')) !== 'iyzico'
            || strtolower((string) ($reportingResponse['mode'] ?? '')) !== 'sandbox'
            || count($payments) !== 1
            || ! is_array($payments[0])) {
            $this->exactReconciliationMismatch('provider_reporting_response_invalid');
        }

        $reportedPayment = $payments[0];
        $reportedReference = $this->stringValue($reportedPayment['paymentId'] ?? null);
        $reportedConversation = $this->stringValue($reportedPayment['paymentConversationId'] ?? null);
        $reportedCurrency = strtoupper((string) ($reportedPayment['currency'] ?? ''));
        $reportedStatus = strtolower((string) ($reportedPayment['paymentStatus'] ?? ''));
        $refundStatus = strtoupper((string) ($reportedPayment['refundStatus'] ?? 'NOT_REFUNDED'));
        if ($reportedReference === null
            || ! hash_equals($providerPaymentReference, $reportedReference)
            || $reportedConversation === null
            || ! hash_equals($expectedConversation, $reportedConversation)
            || ! in_array($reportedStatus, ['1', 'paid', 'success', 'successful'], true)
            || $refundStatus !== 'NOT_REFUNDED'
            || ! $this->amountEquals($reportedPayment['price'] ?? null, $payment->amount)
            || ! $this->amountEquals($reportedPayment['paidPrice'] ?? null, $payment->amount)
            || $reportedCurrency !== strtoupper((string) $payment->currency)) {
            $this->exactReconciliationMismatch('provider_reporting_identity_mismatch');
        }

        $transactions = is_array($reportedPayment['itemTransactions'] ?? null)
            ? array_values($reportedPayment['itemTransactions'])
            : [];
        $transaction = collect($transactions)->first(fn (mixed $item): bool => is_array($item)
            && in_array(strtolower((string) ($item['transactionStatus'] ?? '')), ['2', 'paid', 'success', 'successful'], true)
            && $this->stringValue($item['paymentTransactionId'] ?? null) !== null);
        if (! is_array($transaction)) {
            $this->exactReconciliationMismatch('provider_transaction_identity_missing');
        }
        if (array_key_exists('price', $transaction)
            && ! $this->amountEquals($transaction['price'], $payment->amount)) {
            $this->exactReconciliationMismatch('provider_transaction_amount_mismatch');
        }

        $transactionReference = (string) $transaction['paymentTransactionId'];
        if (! hash_equals(
            $providerPaymentReference,
            (string) ($reportingResponse['provider_payment_reference'] ?? ''),
        ) || ! hash_equals(
            $transactionReference,
            (string) ($reportingResponse['provider_transaction_reference'] ?? ''),
        )) {
            $this->exactReconciliationMismatch('normalized_provider_reference_mismatch');
        }

        $proof = [
            'payment_id' => (int) $payment->id,
            'request_id' => (int) $payment->technical_service_request_id,
            'request_code' => $businessIdentity['request_code'],
            'part_request_id' => $businessIdentity['part_request_id'],
            'provider' => 'iyzico',
            'provider_mode' => 'sandbox',
            'provider_payment_reference' => $providerPaymentReference,
            'provider_transaction_reference' => $transactionReference,
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'currency' => strtoupper((string) $payment->currency),
            'provider_paid_at' => $this->stringValue($reportedPayment['createdDate'] ?? null),
            'link_sold_count' => (int) Arr::get($linkPayload, 'data.soldCount', 0),
            'token_sha256' => hash('sha256', $expectedToken),
            'conversation_sha256' => hash('sha256', $expectedConversation),
            'stored_successful_session' => true,
            'identity_match' => true,
        ];

        return [
            'proof' => $proof,
            'provider_response' => $reportingResponse,
        ];
    }

    /** @return array{request_code:string,part_request_id:int|null} */
    private function assertExactPaymentBusinessIdentity(TechnicalServiceMountPayment $payment): array
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $request = $payment->technicalServiceRequest()->first();
        if (! $request instanceof TechnicalServiceRequest
            || (int) ($payload['technical_service_request_id'] ?? 0) !== (int) $request->id) {
            $this->exactReconciliationMismatch('payment_request_identity_mismatch');
        }

        $requestCode = $this->stringValue($payload['request_code'] ?? $payload['mrn'] ?? null);
        if ($requestCode === null || ! hash_equals((string) $request->mrn, $requestCode)) {
            $this->exactReconciliationMismatch('payment_mrn_identity_mismatch');
        }
        if (array_key_exists('total_amount', $payload)
            && ! $this->amountEquals($payload['total_amount'], $payment->amount)) {
            $this->exactReconciliationMismatch('payment_business_amount_mismatch');
        }

        $partRequestId = $payload['part_request_id'] ?? null;
        if ($partRequestId === null) {
            if (($payload['source'] ?? null) === 'operation_customer_charge') {
                $this->exactReconciliationMismatch('part_request_identity_missing');
            }

            return ['request_code' => $requestCode, 'part_request_id' => null];
        }
        if (! is_numeric($partRequestId)) {
            $this->exactReconciliationMismatch('part_request_identity_invalid');
        }

        $partRequest = TechnicalServicePartRequest::query()
            ->whereKey((int) $partRequestId)
            ->where('technical_service_request_id', $request->id)
            ->first();
        $partMetadata = $partRequest instanceof TechnicalServicePartRequest && is_array($partRequest->metadata)
            ? $partRequest->metadata
            : [];
        $partPaymentId = $partMetadata['payment_id'] ?? $partMetadata['customer_charge_payment_id'] ?? null;
        if (! $partRequest instanceof TechnicalServicePartRequest
            || ! is_numeric($partPaymentId)
            || (int) $partPaymentId !== (int) $payment->id) {
            $this->exactReconciliationMismatch('part_request_payment_identity_mismatch');
        }

        return ['request_code' => $requestCode, 'part_request_id' => (int) $partRequest->id];
    }

    private function assertStoredSuccessfulProviderSession(
        TechnicalServiceMountPayment $payment,
        string $expectedToken,
        string $expectedConversation,
    ): void {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $session = Arr::get($payload, 'provider_gateway');
        if (! is_array($session)
            || ! (bool) ($session['ok'] ?? false)
            || (string) ($session['operation'] ?? '') !== PaymentProviderGatewayRequest::OPERATION_CREATE_LINK
            || strtolower((string) ($session['provider'] ?? '')) !== 'iyzico'
            || strtolower((string) ($session['mode'] ?? '')) !== 'sandbox'
            || (string) ($session['payment_id'] ?? '') !== (string) $payment->id
            || ! $this->amountEquals($session['amount'] ?? null, $payment->amount)
            || strtoupper((string) ($session['currency'] ?? '')) !== strtoupper((string) $payment->currency)
            || ! hash_equals($expectedConversation, (string) ($session['conversation_id'] ?? ''))
            || ! hash_equals($expectedToken, (string) ($session['provider_token'] ?? ''))
            || (int) ($session['status_code'] ?? 0) < 200
            || (int) ($session['status_code'] ?? 0) >= 300) {
            $this->exactReconciliationMismatch('stored_successful_provider_session_invalid');
        }
    }

    private function paymentProviderMode(TechnicalServiceMountPayment $payment): string
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $mode = Arr::get($payload, 'provider_mode')
            ?? Arr::get($payload, 'provider_decision.provider_mode')
            ?? Arr::get($payload, 'provider_gateway.mode');

        return strtolower(trim((string) $mode));
    }

    private function amountEquals(mixed $actual, mixed $expected): bool
    {
        return is_numeric($actual)
            && is_numeric($expected)
            && (int) round((float) $actual * 100) === (int) round((float) $expected * 100);
    }

    private function exactReconciliationMismatch(string $reason): never
    {
        throw new TechnicalServicePaymentProviderClientException(
            'EXACT_PAYMENT_RECONCILIATION_REJECTED: '.$reason,
        );
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
        bool $queueOpsDispatch = true,
    ): TechnicalServiceMountPayment {
        $providerResponse = PaymentProviderGatewayResponse::redactProviderResponse($providerResponse);
        $reference = $this->providerReference($providerResponse) ?: $payment->provider_reference;
        $paymentReference = $this->providerPaymentReference($providerResponse);
        $transactionReference = $this->providerTransactionReference($providerResponse);
        $receiptReference = $this->providerReceiptReference($providerResponse);
        $providerPaidAt = $this->providerPaidAt($providerResponse);
        $payload = $this->reconciliationPayload($payment, $providerResponse, TechnicalServiceMountPayment::STATUS_PAID);
        if ($reference !== null) {
            $payload['provider_reference'] = $reference;
        }
        if ($paymentReference !== null) {
            $payload['provider_payment_reference'] = $paymentReference;
        }
        if ($transactionReference !== null) {
            $payload['provider_transaction_reference'] = $transactionReference;
        }
        $payload['provider_receipt_reference'] = $receiptReference;
        $payload['provider_paid_at'] = $providerPaidAt?->toIso8601String();

        $blocked = DB::transaction(function () use ($payment, $providerResponse): ?TechnicalServiceMountPayment {
            $locked = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status === TechnicalServiceMountPayment::STATUS_PAID) {
                return $this->storeReconciliationPayloadWithinTransaction(
                    $locked,
                    $providerResponse,
                    TechnicalServiceMountPayment::STATUS_PAID,
                );
            }
            if (in_array($locked->status, [
                TechnicalServiceMountPayment::STATUS_FAILED,
                TechnicalServiceMountPayment::STATUS_CANCELLED,
                TechnicalServiceMountPayment::STATUS_EXPIRED,
            ], true)) {
                return $this->storeReconciliationPayloadWithinTransaction(
                    $locked,
                    $providerResponse,
                    (string) $locked->status,
                    ['blocked_reason' => $locked->status === TechnicalServiceMountPayment::STATUS_CANCELLED
                        ? 'paid_after_cancel_requires_admin_review'
                        : 'paid_after_terminal_requires_admin_review'],
                );
            }

            return null;
        });
        if ($blocked instanceof TechnicalServiceMountPayment) {
            return $this->receiptNotificationService->notifyTrustedPaid($blocked, $providerResponse);
        }

        $settlementPayload = [
            'source' => 'provider_reconciliation',
            'provider' => $this->provider($providerResponse, $payment),
            'provider_reference' => $reference,
            'provider_receipt_reference' => $receiptReference,
            'provider_paid_at' => $providerPaidAt?->toIso8601String(),
            'provider_status' => $this->rawProviderStatus($providerResponse),
            'provider_response_redacted' => $providerResponse['provider_response_redacted'] ?? [],
            'provider_reconciliation' => $payload['provider_reconciliation'] ?? [],
        ];
        if ($paymentReference !== null) {
            $settlementPayload['provider_payment_reference'] = $paymentReference;
        }
        if ($transactionReference !== null) {
            $settlementPayload['provider_transaction_reference'] = $transactionReference;
        }

        $paidPayment = $this->settlementService->markPaid($payment->fresh(), $settlementPayload);

        if ($queueOpsDispatch) {
            $this->queuePaymentReceivedOpsDispatch($paidPayment->fresh(), $providerResponse);
        }

        return $paidPayment->fresh();
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function queuePaymentReceivedOpsDispatch(
        TechnicalServiceMountPayment $payment,
        array $providerResponse,
    ): void {
        $request = $payment->technicalServiceRequest()->first();
        if (! $request instanceof TechnicalServiceRequest) {
            return;
        }

        $currency = strtoupper((string) ($payment->currency ?: 'TRY'));
        $amountLabel = number_format(round((float) $payment->amount, 2), 2, ',', '.').' '.$currency;
        $paidAt = $payment->paid_at ?: $payment->provider_paid_at;

        $this->workflowMessages->queueWorkflowDispatches(
            $request->refresh(),
            'payment_received_ops',
            'ops',
            [
                'payment_link' => $payment->payment_url,
                'payment_amount_formatted' => $amountLabel,
                'payment_status_label' => 'Ödendi',
                'provider_payment_reference' => $payment->provider_payment_reference,
                'provider_transaction_reference' => $payment->provider_transaction_reference,
                'provider_receipt_reference' => $payment->provider_receipt_reference,
            ],
            null,
            null,
            [
                'triggered_by' => 'provider_payment_reconciliation_paid',
                'event_version' => 'payment-received:'.$payment->id.':'.($paidAt?->timestamp ?? 'missing'),
                'metadata' => [
                    'payment_id' => $payment->id,
                    'provider' => $payment->provider,
                    'provider_reference' => $payment->provider_reference,
                    'provider_status' => $this->rawProviderStatus($providerResponse),
                    'workflow_event' => 'payment_received_ops',
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function markCancelledFromTrustedProvider(
        TechnicalServiceMountPayment $payment,
        array $providerResponse,
    ): TechnicalServiceMountPayment {
        $payment = $this->transitionProviderStatus(
            $payment,
            $providerResponse,
            TechnicalServiceMountPayment::STATUS_CANCELLED,
        );

        $session = $payment->session;
        if ($payment->status === TechnicalServiceMountPayment::STATUS_CANCELLED
            && $session instanceof TechnicalServiceMountSession
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
        return $this->transitionProviderStatus(
            $payment,
            $providerResponse,
            TechnicalServiceMountPayment::STATUS_FAILED,
        );
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function markPendingFromTrustedProvider(
        TechnicalServiceMountPayment $payment,
        array $providerResponse,
    ): TechnicalServiceMountPayment {
        return $this->transitionProviderStatus(
            $payment,
            $providerResponse,
            TechnicalServiceMountPayment::STATUS_PENDING,
        );
    }

    public function recordSyncFailure(
        TechnicalServiceMountPayment $payment,
        Throwable|string $error,
        string $source = 'scheduled_reconcile',
    ): TechnicalServiceMountPayment {
        return DB::transaction(function () use ($payment, $error, $source): TechnicalServiceMountPayment {
            $locked = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $message = $this->redactedError($error);
            $payload = is_array($locked->raw_payload) ? $locked->raw_payload : [];
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
            $locked->forceFill([
                'raw_payload' => $payload,
            ] + $this->syncAuditAttributes($locked, 'provider_error', $message))->save();

            return $locked->fresh();
        });
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
        $reconciliation = [
            'status' => $localStatus,
            'provider_status' => $this->rawProviderStatus($providerResponse),
            'provider_receipt_reference' => $this->providerReceiptReference($providerResponse),
            'provider_paid_at' => $this->providerPaidAt($providerResponse)?->toIso8601String(),
            'provider_response_redacted' => $providerResponse['provider_response_redacted'] ?? [],
            'reconciled_at' => now()->toIso8601String(),
        ];
        $paymentReference = $this->providerPaymentReference($providerResponse);
        $transactionReference = $this->providerTransactionReference($providerResponse);
        if ($paymentReference !== null) {
            $reconciliation['provider_payment_reference'] = $paymentReference;
        }
        if ($transactionReference !== null) {
            $reconciliation['provider_transaction_reference'] = $transactionReference;
        }
        $payload['provider_reconciliation'] = array_merge($reconciliation, $extra);

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
        return DB::transaction(function () use ($payment, $providerResponse, $localStatus, $extra): TechnicalServiceMountPayment {
            $locked = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return $this->storeReconciliationPayloadWithinTransaction($locked, $providerResponse, $localStatus, $extra);
        });
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     * @param  array<string, mixed>  $extra
     */
    private function storeReconciliationPayloadWithinTransaction(
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
     * Reconciliation can enrich a terminal row but may only change status
     * while the locked canonical payment is still pending.
     *
     * @param  array<string, mixed>  $providerResponse
     */
    private function transitionProviderStatus(
        TechnicalServiceMountPayment $payment,
        array $providerResponse,
        string $requestedStatus,
    ): TechnicalServiceMountPayment {
        return DB::transaction(function () use ($payment, $providerResponse, $requestedStatus): TechnicalServiceMountPayment {
            $locked = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $currentStatus = (string) $locked->status;
            $terminal = in_array($currentStatus, [
                TechnicalServiceMountPayment::STATUS_PAID,
                TechnicalServiceMountPayment::STATUS_FAILED,
                TechnicalServiceMountPayment::STATUS_CANCELLED,
                TechnicalServiceMountPayment::STATUS_EXPIRED,
            ], true);
            $nextStatus = $terminal ? $currentStatus : $requestedStatus;
            $locked->forceFill([
                'status' => $nextStatus,
                'raw_payload' => $this->reconciliationPayload($locked, $providerResponse, $nextStatus),
            ] + $this->syncAuditAttributes(
                $locked,
                $nextStatus,
                $this->syncErrorFromProvider($providerResponse, $nextStatus),
            ))->save();

            return $locked->fresh();
        });
    }

    public function preserveTerminalStateAfterProviderMutation(
        TechnicalServiceMountPayment $payment,
        string $statusBeforeProvider,
    ): TechnicalServiceMountPayment {
        return DB::transaction(function () use ($payment, $statusBeforeProvider): TechnicalServiceMountPayment {
            $locked = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $paidEvidence = $locked->paid_at !== null
                || $locked->provider_paid_confirmed_at !== null;
            $terminalBefore = in_array($statusBeforeProvider, [
                TechnicalServiceMountPayment::STATUS_PAID,
                TechnicalServiceMountPayment::STATUS_FAILED,
                TechnicalServiceMountPayment::STATUS_CANCELLED,
                TechnicalServiceMountPayment::STATUS_EXPIRED,
            ], true);
            $protectedStatus = $paidEvidence
                ? TechnicalServiceMountPayment::STATUS_PAID
                : ($terminalBefore ? $statusBeforeProvider : null);
            if ($protectedStatus !== null && $locked->status !== $protectedStatus) {
                $locked->forceFill(['status' => $protectedStatus])->save();
            }

            return $locked->fresh();
        });
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
