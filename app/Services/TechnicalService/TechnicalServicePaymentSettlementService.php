<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use Illuminate\Support\Facades\DB;
use Throwable;

class TechnicalServicePaymentSettlementService
{
    public function __construct(
        private readonly TechnicalServiceMessagingSettingsService $messagingSettings,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function markPaid(TechnicalServiceMountPayment $payment, array $payload = []): TechnicalServiceMountPayment
    {
        $claim = $this->messagingSettings->claimScopedLocalUatSandboxPaymentCallbackEffect(
            $payment,
            $payload,
        );
        if ($claim['duplicate']) {
            return $payment->refresh();
        }

        try {
            if (is_string($claim['claim_nonce'])) {
                return $this->messagingSettings->executeScopedLocalUatPaymentCallback(
                    $claim['claim_nonce'],
                    fn (TechnicalServiceMountPayment $lockedPayment): TechnicalServiceMountPayment => $this->settleLockedPayment(
                        $lockedPayment,
                        $payload,
                    ),
                );
            }

            return DB::transaction(function () use ($payment, $payload): TechnicalServiceMountPayment {
                $lockedPayment = TechnicalServiceMountPayment::query()
                    ->whereKey($payment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->settleLockedPayment($lockedPayment, $payload);
            });
        } catch (Throwable $exception) {
            if (is_string($claim['claim_nonce'])) {
                $this->messagingSettings->failScopedLocalUatEffect($claim['claim_nonce'], $exception);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $payload */
    private function settleLockedPayment(
        TechnicalServiceMountPayment $payment,
        array $payload,
    ): TechnicalServiceMountPayment {
        if ($payment->status === TechnicalServiceMountPayment::STATUS_PAID) {
            return $payment;
        }

        $rawPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $rawPayload['callback_payload'] = $payload;
        $providerReference = $this->paymentReferenceFromPayload($payload) ?: $payment->provider_reference;
        $providerPaymentReference = $this->paymentReferenceFromPayload([
            'provider_reference' => $payload['provider_payment_reference'] ?? null,
        ]) ?: $payment->provider_payment_reference;
        $providerTransactionReference = $this->paymentReferenceFromPayload([
            'provider_reference' => $payload['provider_transaction_reference'] ?? null,
        ]) ?: $payment->provider_transaction_reference;
        $providerReceiptReference = $this->paymentReferenceFromPayload([
            'provider_reference' => $payload['provider_receipt_reference'] ?? null,
        ]) ?: $payment->provider_receipt_reference;

        $payment->forceFill([
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'provider_reference' => $providerReference,
            'provider_payment_reference' => $providerPaymentReference,
            'provider_transaction_reference' => $providerTransactionReference,
            'provider_receipt_reference' => $providerReceiptReference,
            'paid_at' => $payment->paid_at ?? now(),
            'raw_payload' => $rawPayload,
        ])->save();

        $isCustomerCharge = ($rawPayload['source'] ?? null) === 'operation_customer_charge';
        $session = $payment->session;

        if (! $isCustomerCharge && $session instanceof TechnicalServiceMountSession) {
            $session->forceFill([
                'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
                'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_PAID_SINGLE_PRODUCT,
                'decision_status' => TechnicalServiceMountSession::DECISION_FORM_OPEN,
            ])->save();
        }

        $this->applyRequestPaymentApproval($payment);

        return $payment->fresh();
    }

    private function applyRequestPaymentApproval(TechnicalServiceMountPayment $payment): void
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        if (($payload['source'] ?? null) === 'operation_customer_charge') {
            $this->applyCustomerChargeApproval($payment, $payload);

            return;
        }

        $requestId = $payment->technical_service_request_id ?? ($payload['technical_service_request_id'] ?? null);

        if (! is_numeric($requestId)) {
            return;
        }

        $request = TechnicalServiceRequest::query()->find((int) $requestId);

        if (! $request instanceof TechnicalServiceRequest) {
            return;
        }

        $request->forceFill([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'mount_payment_label' => 'Montaj ödemesi alındı',
            'mount_payment_provider' => $payment->provider,
            'mount_payment_reference' => $payment->provider_reference,
            'mount_payment_paid_at' => $payment->paid_at ?? now(),
        ])->save();

        $this->markSerialsPaid($request, $payment, $payload);

        $providerReconciliation = ($payload['source'] ?? null) === 'provider_reconciliation';

        $request->events()->create([
            'event_type' => 'mount_payment_paid',
            'title' => $providerReconciliation ? 'Ödeme sağlayıcıdan ödeme doğrulandı' : 'Montaj ödemesi alındı',
            'note' => $providerReconciliation
                ? 'Ödeme provider reconciliation sonucu doğrulandı.'
                : 'Ödeme sağlayıcısı üzerinden montaj ödemesi onaylandı.',
            'from_status' => $request->workflow_status,
            'to_status' => $request->workflow_status,
            'author_user_id' => null,
            'metadata' => [
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'provider_reference' => $payment->provider_reference,
                'provider_payment_reference' => $payment->provider_payment_reference,
                'provider_transaction_reference' => $payment->provider_transaction_reference,
                'provider_receipt_reference' => $payment->provider_receipt_reference,
                'paid_at' => ($payment->paid_at ?? now())->toIso8601String(),
                'selected_serial_ids' => $payload['selected_serial_ids'] ?? [],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyCustomerChargeApproval(TechnicalServiceMountPayment $payment, array $payload): void
    {
        $requestId = $payment->technical_service_request_id ?? ($payload['technical_service_request_id'] ?? null);

        if (! is_numeric($requestId)) {
            return;
        }

        $request = TechnicalServiceRequest::query()->find((int) $requestId);

        if (! $request instanceof TechnicalServiceRequest) {
            return;
        }

        $partRequest = null;
        $partRequestId = $payload['part_request_id'] ?? null;
        if (is_numeric($partRequestId)) {
            $partRequest = TechnicalServicePartRequest::query()
                ->whereKey((int) $partRequestId)
                ->where('technical_service_request_id', $request->id)
                ->first();
        }

        if ($partRequest instanceof TechnicalServicePartRequest) {
            $metadata = is_array($partRequest->metadata) ? $partRequest->metadata : [];
            $customerCharge = is_array($metadata['customer_charge'] ?? null) ? $metadata['customer_charge'] : [];
            $paidAt = $payment->paid_at ?? now();
            $metadata['charge_status'] = TechnicalServiceMountPayment::STATUS_PAID;
            $metadata['payment_status'] = TechnicalServiceMountPayment::STATUS_PAID;
            $metadata['paid_amount'] = round((float) $payment->amount, 2);
            $metadata['paid_at'] = $paidAt->toISOString();
            $metadata['payment_reference'] = $payment->provider_reference;
            $metadata['provider_reference'] = $payment->provider_reference;
            $metadata['provider_payment_reference'] = $payment->provider_payment_reference;
            $metadata['provider_transaction_reference'] = $payment->provider_transaction_reference;
            $metadata['provider_receipt_reference'] = $payment->provider_receipt_reference;
            $metadata['payment_provider'] = $payment->provider;
            $metadata['customer_charge'] = [
                ...$customerCharge,
                'id' => $payment->id,
                'status' => TechnicalServiceMountPayment::STATUS_PAID,
                'status_label' => 'Ödendi',
                'total_amount' => round((float) $payment->amount, 2),
                'total_amount_label' => number_format((float) $payment->amount, 0, ',', '.').' TL',
                'provider' => $payment->provider,
                'provider_reference' => $payment->provider_reference,
                'payment_reference' => $payment->provider_reference,
                'provider_payment_reference' => $payment->provider_payment_reference,
                'provider_transaction_reference' => $payment->provider_transaction_reference,
                'provider_receipt_reference' => $payment->provider_receipt_reference,
                'paid_at' => $paidAt->toIso8601String(),
            ];
            $partRequest->forceFill(['metadata' => $metadata])->save();
        }

        $request->events()->create([
            'event_type' => 'customer_charge_paid',
            'title' => 'Müşteri servis/parça ödemesi alındı',
            'note' => 'Müşteri ödeme linki üzerinden servis/parça tahsilatı onaylandı.',
            'from_status' => $request->workflow_status,
            'to_status' => $request->workflow_status,
            'author_user_id' => null,
            'metadata' => [
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'charge_type' => $payload['charge_type'] ?? $payload['purpose'] ?? null,
                'service_amount' => (float) ($payload['service_amount'] ?? 0),
                'part_amount' => (float) ($payload['part_amount'] ?? 0),
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'part_request_id' => $partRequest?->id,
                'provider_reference' => $payment->provider_reference,
                'provider_payment_reference' => $payment->provider_payment_reference,
                'provider_transaction_reference' => $payment->provider_transaction_reference,
                'provider_receipt_reference' => $payment->provider_receipt_reference,
            ],
        ]);

        if ($partRequest instanceof TechnicalServicePartRequest) {
            $request->events()->create([
                'event_type' => 'part_request_payment_paid',
                'title' => 'Parça ödemesi alındı',
                'note' => $payment->provider_reference
                    ? 'Dekont / referans: '.$payment->provider_reference
                    : 'Müşteri ödeme linki üzerinden parça tahsilatı onaylandı.',
                'from_status' => $request->workflow_status,
                'to_status' => $request->workflow_status,
                'author_user_id' => null,
                'metadata' => [
                    'part_request_id' => $partRequest->id,
                    'payment_id' => $payment->id,
                    'provider' => $payment->provider,
                    'provider_reference' => $payment->provider_reference,
                    'provider_payment_reference' => $payment->provider_payment_reference,
                    'provider_transaction_reference' => $payment->provider_transaction_reference,
                    'provider_receipt_reference' => $payment->provider_receipt_reference,
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                    'paid_at' => ($payment->paid_at ?? now())->toIso8601String(),
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function paymentReferenceFromPayload(array $payload): ?string
    {
        foreach (['provider_reference', 'payment_reference', 'reference', 'receipt_no', 'dekont_no', 'transaction_id'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markSerialsPaid(TechnicalServiceRequest $request, TechnicalServiceMountPayment $payment, array $payload): void
    {
        $serialIds = collect($payload['selected_serial_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        $serialQuery = TechnicalServiceRequestSerial::query()
            ->where('technical_service_request_id', $request->id);

        if ($serialIds->isNotEmpty()) {
            $serialQuery->whereIn('id', $serialIds);
        } else {
            $serialQuery->where(function ($query): void {
                $query->where('customer_selected', true)
                    ->orWhere('operation_added', true)
                    ->orWhere('is_primary', true);
            });
        }

        $serialQuery->get()->each(function (TechnicalServiceRequestSerial $serial) use ($payment): void {
            $sourcePayload = is_array($serial->source_payload) ? $serial->source_payload : [];
            $sourcePayload['extra_mount_payment_status'] = TechnicalServiceMountPayment::STATUS_PAID;
            $sourcePayload['extra_mount_payment_id'] = $payment->id;
            $sourcePayload['sale_mount_status'] = TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL;
            $sourcePayload['mount_payment_status'] = TechnicalServiceMountSession::PAYMENT_PAID;
            $sourcePayload['mount_status_label'] = 'Montaj Dahil';

            $serial->forceFill([
                'operation_added' => true,
                'operation_added_at' => $serial->operation_added_at ?? now(),
                'source_payload' => $sourcePayload,
                'color_status' => 'green',
                'operation_note' => trim((string) $serial->operation_note) !== ''
                    ? $serial->operation_note.' | Ek ödeme onaylandı - Montaj Dahil'
                    : 'Ek ödeme onaylandı - Montaj Dahil',
            ])->save();
        });
    }
}
