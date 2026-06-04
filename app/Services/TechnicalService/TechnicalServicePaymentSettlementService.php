<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;

class TechnicalServicePaymentSettlementService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function markPaid(TechnicalServiceMountPayment $payment, array $payload = []): TechnicalServiceMountPayment
    {
        $rawPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $rawPayload['callback_payload'] = $payload;

        $payment->forceFill([
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
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

        $request->events()->create([
            'event_type' => 'mount_payment_paid',
            'title' => 'Montaj ödemesi alındı',
            'note' => 'Ödeme sağlayıcısı üzerinden montaj ödemesi onaylandı.',
            'from_status' => $request->workflow_status,
            'to_status' => $request->workflow_status,
            'author_user_id' => null,
            'metadata' => [
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'selected_serial_ids' => $payload['selected_serial_ids'] ?? [],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
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
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
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
