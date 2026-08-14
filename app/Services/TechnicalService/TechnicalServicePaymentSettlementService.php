<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Payments\TechnicalServicePaymentReceiptNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class TechnicalServicePaymentSettlementService
{
    public function __construct(
        private readonly TechnicalServiceMessagingSettingsService $messagingSettings,
        private readonly TechnicalServicePaymentReceiptNotificationService $receiptNotificationService,
        private readonly TechnicalServicePaymentOrderContextService $orderContexts,
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
            $canonical = TechnicalServiceMountPayment::query()
                ->findOrFail((int) ($claim['duplicate_payment_id'] ?? 0))
                ->refresh();

            return $this->receiptNotificationService->notifyTrustedPaid($canonical, $payload);
        }

        try {
            if (is_string($claim['claim_nonce'])) {
                $paid = $this->messagingSettings->executeScopedLocalUatPaymentCallback(
                    $claim['claim_nonce'],
                    fn (TechnicalServiceMountPayment $lockedPayment): TechnicalServiceMountPayment => $this->settleLockedPayment(
                        $lockedPayment,
                        $payload,
                        true,
                    ),
                );
            } else {
                $paid = DB::transaction(function () use ($payment, $payload): TechnicalServiceMountPayment {
                    $lockedPayment = TechnicalServiceMountPayment::query()
                        ->whereKey($payment->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    return $this->settleLockedPayment($lockedPayment, $payload, false);
                });
            }

            return $this->receiptNotificationService->notifyTrustedPaid($paid, $payload);
        } catch (Throwable $exception) {
            if (is_string($claim['claim_nonce'])) {
                $this->messagingSettings->failScopedLocalUatEffect($claim['claim_nonce'], $exception);
            }

            throw $exception;
        }
    }

    public function recordManualPartPayment(
        TechnicalServiceRequest $request,
        TechnicalServicePartRequest $partRequest,
        TechnicalServiceMountSession $session,
        User $actor,
        string $explanation,
    ): TechnicalServiceMountPayment {
        $explanation = trim($explanation);
        if (mb_strlen($explanation) < 5) {
            throw ValidationException::withMessages([
                'explanation' => 'Açıklama en az 5 karakter olmalıdır.',
            ]);
        }

        $payment = DB::transaction(function () use ($request, $partRequest, $session, $actor, $explanation): TechnicalServiceMountPayment {
            $lockedRequest = TechnicalServiceRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedPartRequest = TechnicalServicePartRequest::query()
                ->whereKey($partRequest->getKey())
                ->where('technical_service_request_id', $lockedRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedSession = TechnicalServiceMountSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedPartRequest->isChargeable()) {
                throw ValidationException::withMessages([
                    'payment' => 'Yalnızca ücretli parça talebi için manuel tahsilat kaydedilebilir.',
                ]);
            }

            $payments = TechnicalServiceMountPayment::query()
                ->where('technical_service_request_id', $lockedRequest->getKey())
                ->lockForUpdate()
                ->get()
                ->filter(function (TechnicalServiceMountPayment $candidate) use ($lockedPartRequest): bool {
                    $payload = is_array($candidate->raw_payload) ? $candidate->raw_payload : [];

                    return ($payload['source'] ?? null) === 'operation_customer_charge'
                        && (int) ($payload['part_request_id'] ?? 0) === (int) $lockedPartRequest->getKey();
                });

            if ($payments->contains(fn (TechnicalServiceMountPayment $candidate): bool => $candidate->status === TechnicalServiceMountPayment::STATUS_PENDING)) {
                throw ValidationException::withMessages([
                    'payment' => 'Aktif ödeme bağlantısını iptal ettikten sonra manuel tahsilatı kaydedin.',
                ]);
            }

            $metadata = is_array($lockedPartRequest->metadata) ? $lockedPartRequest->metadata : [];
            $serviceTotalMinor = $this->moneyMinorUnits($metadata['service_amount'] ?? 0);
            $partTotalMinor = $this->moneyMinorUnits($metadata['part_amount'] ?? 0);
            if ($serviceTotalMinor + $partTotalMinor <= 0) {
                $partTotalMinor = $this->moneyMinorUnits($metadata['total_amount'] ?? 0);
            }
            if ($serviceTotalMinor + $partTotalMinor <= 0) {
                throw ValidationException::withMessages([
                    'payment' => 'Parça talebinin tahsil edilecek kalan tutarı bulunamadı.',
                ]);
            }

            $paidServiceMinor = 0;
            $paidPartMinor = 0;
            foreach ($payments->where('status', TechnicalServiceMountPayment::STATUS_PAID) as $paidPayment) {
                $payload = is_array($paidPayment->raw_payload) ? $paidPayment->raw_payload : [];
                $serviceMinor = $this->moneyMinorUnits($payload['service_amount'] ?? 0);
                $partMinor = $this->moneyMinorUnits($payload['part_amount'] ?? 0);
                if ($serviceMinor + $partMinor <= 0) {
                    $purpose = (string) ($payload['purpose'] ?? $payload['charge_type'] ?? '');
                    if (in_array($purpose, ['part_payment', 'part_charge'], true)) {
                        $partMinor = $this->moneyMinorUnits($paidPayment->amount);
                    } else {
                        $serviceMinor = $this->moneyMinorUnits($paidPayment->amount);
                    }
                }
                $paidServiceMinor += $serviceMinor;
                $paidPartMinor += $partMinor;
            }

            $remainingServiceMinor = max(0, $serviceTotalMinor - $paidServiceMinor);
            $remainingPartMinor = max(0, $partTotalMinor - $paidPartMinor);
            $remainingTotalMinor = $remainingServiceMinor + $remainingPartMinor;
            if ($remainingTotalMinor <= 0) {
                $existingManual = $payments
                    ->where('provider', 'manual')
                    ->where('status', TechnicalServiceMountPayment::STATUS_PAID)
                    ->sortByDesc('id')
                    ->first();
                if ($existingManual instanceof TechnicalServiceMountPayment) {
                    return $existingManual;
                }

                throw ValidationException::withMessages([
                    'payment' => 'Parça talebinin tahsilatı zaten tamamlanmış.',
                ]);
            }

            $idempotencyKey = hash('sha256', implode('|', [
                'manual-part-payment-v1',
                (string) $lockedRequest->getKey(),
                (string) $lockedPartRequest->getKey(),
                (string) $remainingTotalMinor,
                (string) ($paidServiceMinor + $paidPartMinor),
            ]));
            $rootMrn = trim((string) ($lockedRequest->root_mrn ?: $lockedRequest->mrn));
            $payment = TechnicalServiceMountPayment::query()->create([
                'technical_service_mount_session_id' => $lockedSession->getKey(),
                'technical_service_request_id' => $lockedRequest->getKey(),
                'provider' => 'manual',
                'provider_reference' => null,
                'provider_payment_reference' => null,
                'provider_transaction_reference' => null,
                'provider_receipt_reference' => null,
                'provider_sync_attempts' => 0,
                'status' => TechnicalServiceMountPayment::STATUS_PENDING,
                'amount' => $this->minorUnitsDecimal($remainingTotalMinor),
                'currency' => 'TRY',
                'payment_url' => null,
                'raw_payload' => [
                    'source' => 'operation_customer_charge',
                    'amount_source' => 'manual_part_collection',
                    'purpose' => 'part_charge',
                    'charge_type' => 'part_charge',
                    'technical_service_request_id' => $lockedRequest->getKey(),
                    'root_request_id' => $lockedPartRequest->root_request_id ?: ($lockedRequest->parent_request_id ?: $lockedRequest->getKey()),
                    'request_code' => $lockedRequest->service_code ?: $lockedRequest->mrn,
                    'mrn' => $lockedRequest->mrn,
                    'root_mrn' => $rootMrn,
                    'service_code' => $lockedRequest->service_code,
                    'part_request_id' => $lockedPartRequest->getKey(),
                    'part_name' => $lockedPartRequest->part_name,
                    'service_amount' => $this->minorUnitsDecimal($remainingServiceMinor),
                    'part_amount' => $this->minorUnitsDecimal($remainingPartMinor),
                    'total_amount' => $this->minorUnitsDecimal($remainingTotalMinor),
                    'note' => $explanation,
                    'created_by_user_id' => $actor->getKey(),
                    'created_by_name' => $actor->name,
                    'manual_confirmation' => [
                        'schema_version' => 1,
                        'idempotency_key' => $idempotencyKey,
                        'actor_user_id' => $actor->getKey(),
                        'actor_name_snapshot' => $actor->name,
                        'explanation' => $explanation,
                        'confirmed_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

            return $this->settleLockedPayment($payment, [
                'source' => 'manual_part_payment_confirmation',
                'provider' => 'manual',
            ], false);
        });

        return $this->receiptNotificationService->notifyTrustedPaid($payment, [
            'source' => 'manual_part_payment_confirmation',
            'provider' => 'manual',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function settleLockedPayment(
        TechnicalServiceMountPayment $payment,
        array $payload,
        bool $scopedLocalUat,
    ): TechnicalServiceMountPayment {
        if ($payment->status === TechnicalServiceMountPayment::STATUS_PAID) {
            if ($scopedLocalUat) {
                throw new ConflictHttpException(
                    'scoped_uat_callback_state_invalid: Paid callback yalnız exact stored idempotent history üzerinden no-op olabilir.',
                );
            }

            $this->orderContexts->markPaidWithinTransaction($payment);
            $this->receiptNotificationService->persistPaidReceiptIntentWithinTransaction($payment);

            return $payment;
        }
        if (($payload['source'] ?? null) === 'provider_reconciliation'
            && in_array($payment->status, [
                TechnicalServiceMountPayment::STATUS_FAILED,
                TechnicalServiceMountPayment::STATUS_CANCELLED,
                TechnicalServiceMountPayment::STATUS_EXPIRED,
            ], true)) {
            return $payment;
        }
        if ($scopedLocalUat && $payment->status !== TechnicalServiceMountPayment::STATUS_PENDING) {
            throw new ConflictHttpException(
                'scoped_uat_callback_state_invalid: Scoped UAT odemesi yalniz pending durumundan paid durumuna gecebilir.',
            );
        }

        $rawPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $rawPayload['callback_payload'] = $payload;
        if (is_array($payload['provider_reconciliation'] ?? null)) {
            $rawPayload['provider_reconciliation'] = $payload['provider_reconciliation'];
        }
        $providerReference = $this->paymentReferenceFromPayload(
            $payload,
            ['provider_reference', 'provider_token', 'token'],
        ) ?: $payment->provider_reference;
        $providerPaymentReference = $this->paymentReferenceFromPayload(
            $payload,
            ['provider_payment_reference', 'payment_reference', 'paymentId'],
        ) ?: $payment->provider_payment_reference;
        $providerTransactionReference = $this->paymentReferenceFromPayload(
            $payload,
            ['provider_transaction_reference', 'payment_transaction_id', 'paymentTransactionId', 'transaction_id'],
        ) ?: $payment->provider_transaction_reference;
        $providerReceiptReference = $this->paymentReferenceFromPayload(
            $payload,
            ['provider_receipt_reference', 'receipt_no', 'dekont_no'],
        ) ?: $payment->provider_receipt_reference;
        $manualSettlement = ($payload['source'] ?? null) === 'manual_part_payment_confirmation';

        $payment->forceFill([
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'provider' => is_scalar($payload['provider'] ?? null) && trim((string) $payload['provider']) !== ''
                ? trim((string) $payload['provider'])
                : $payment->provider,
            'provider_reference' => $providerReference,
            'provider_payment_reference' => $providerPaymentReference,
            'provider_transaction_reference' => $providerTransactionReference,
            'provider_receipt_reference' => $providerReceiptReference,
            'paid_at' => $payment->paid_at ?? now(),
            'provider_paid_at' => $payload['provider_paid_at'] ?? $payment->provider_paid_at,
            'provider_last_synced_at' => ($payload['source'] ?? null) === 'provider_reconciliation' ? now() : $payment->provider_last_synced_at,
            'provider_sync_attempts' => ($payload['source'] ?? null) === 'provider_reconciliation'
                ? max(0, (int) ($payment->provider_sync_attempts ?? 0)) + 1
                : $payment->provider_sync_attempts,
            'provider_last_sync_status' => ($payload['source'] ?? null) === 'provider_reconciliation'
                ? TechnicalServiceMountPayment::STATUS_PAID
                : $payment->provider_last_sync_status,
            'provider_last_sync_error' => ($payload['source'] ?? null) === 'provider_reconciliation'
                ? null
                : $payment->provider_last_sync_error,
            'provider_paid_confirmed_at' => $manualSettlement
                ? $payment->provider_paid_confirmed_at
                : ($payment->provider_paid_confirmed_at ?? now()),
            'raw_payload' => $rawPayload,
        ])->save();

        $paymentPurpose = strtolower(trim((string) ($rawPayload['purpose'] ?? $rawPayload['charge_type'] ?? '')));
        $isCustomerCharge = ($rawPayload['source'] ?? null) === 'operation_customer_charge'
            || $paymentPurpose === TechnicalServicePaymentOrderContextService::PURPOSE_PART_CHARGE;
        $session = $payment->session;

        if (! $isCustomerCharge && $session instanceof TechnicalServiceMountSession) {
            $session->forceFill([
                'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
                'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_PAID_SINGLE_PRODUCT,
                'decision_status' => TechnicalServiceMountSession::DECISION_FORM_OPEN,
            ])->save();
        }

        $this->applyRequestPaymentApproval($payment);
        $this->orderContexts->markPaidWithinTransaction($payment);
        $this->receiptNotificationService->persistPaidReceiptIntentWithinTransaction($payment);

        return $payment->fresh();
    }

    private function applyRequestPaymentApproval(TechnicalServiceMountPayment $payment): void
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $purpose = strtolower(trim((string) ($payload['purpose'] ?? $payload['charge_type'] ?? '')));
        if (($payload['source'] ?? null) === 'operation_customer_charge'
            || $purpose === TechnicalServicePaymentOrderContextService::PURPOSE_PART_CHARGE) {
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
        $manualConfirmation = is_array($payload['manual_confirmation'] ?? null) ? $payload['manual_confirmation'] : [];
        $manualExplanation = trim((string) ($manualConfirmation['explanation'] ?? ''));
        $manualActorId = is_numeric($manualConfirmation['actor_user_id'] ?? null)
            ? (int) $manualConfirmation['actor_user_id']
            : null;

        if ($partRequest instanceof TechnicalServicePartRequest) {
            $metadata = is_array($partRequest->metadata) ? $partRequest->metadata : [];
            $customerCharge = is_array($metadata['customer_charge'] ?? null) ? $metadata['customer_charge'] : [];
            $paidAt = $payment->paid_at ?? now();
            $receiptReference = trim((string) $payment->provider_receipt_reference) ?: null;
            $metadata['charge_status'] = TechnicalServiceMountPayment::STATUS_PAID;
            $metadata['payment_status'] = TechnicalServiceMountPayment::STATUS_PAID;
            $metadata['payment_id'] = $payment->id;
            $metadata['customer_charge_payment_id'] = $payment->id;
            $metadata['payment_url'] = $payment->payment_url;
            $metadata['paid_amount'] = round((float) $payment->amount, 2);
            $metadata['paid_at'] = $paidAt->toISOString();
            $metadata['payment_reference'] = $receiptReference;
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
                'payment_reference' => $receiptReference,
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
            'note' => $manualExplanation !== ''
                ? 'Manuel tahsilat: '.$manualExplanation
                : 'Müşteri ödeme linki üzerinden servis/parça tahsilatı onaylandı.',
            'from_status' => $request->workflow_status,
            'to_status' => $request->workflow_status,
            'author_user_id' => $manualActorId,
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
                'manual_confirmation' => $manualExplanation !== '' ? [
                    'actor_name_snapshot' => $manualConfirmation['actor_name_snapshot'] ?? null,
                    'explanation' => $manualExplanation,
                ] : null,
            ],
        ]);

        if ($partRequest instanceof TechnicalServicePartRequest) {
            $request->events()->create([
                'event_type' => 'part_request_payment_paid',
                'title' => 'Parça ödemesi alındı',
                'note' => $manualExplanation !== ''
                    ? 'Manuel tahsilat: '.$manualExplanation
                    : ($receiptReference
                        ? 'Dekont / referans: '.$receiptReference
                        : 'Müşteri ödeme linki üzerinden parça tahsilatı onaylandı.'),
                'from_status' => $request->workflow_status,
                'to_status' => $request->workflow_status,
                'author_user_id' => $manualActorId,
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

    private function moneyMinorUnits(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        return max(0, (int) round((float) $value * 100));
    }

    private function minorUnitsDecimal(int $minorUnits): string
    {
        return intdiv($minorUnits, 100).'.'.str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function paymentReferenceFromPayload(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
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
