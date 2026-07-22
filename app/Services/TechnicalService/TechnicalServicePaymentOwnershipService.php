<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceSettlement;
use Illuminate\Support\Collection;

class TechnicalServicePaymentOwnershipService
{
    public const STATE_COMPANY_COLLECTED_ONLINE = 'company_collected_online';

    public const STATE_COMPANY_COLLECTED_EXTERNAL = 'company_collected_external';

    public const STATE_CUSTOMER_PAYS_TECHNICIAN = 'customer_pays_technician';

    public const STATE_PENDING_ONLINE_PAYMENT = 'pending_online_payment';

    public const STATE_NO_PAYMENT_REQUIRED = 'no_payment_required';

    public const STATE_PAYMENT_DECISION_MISSING = 'payment_decision_missing';

    /**
     * @param Collection<int, TechnicalServiceMountPayment>|null $payments
     * @return array<string, mixed>
     */
    public function summary(
        TechnicalServiceRequest $request,
        ?TechnicalServiceSettlement $settlement = null,
        ?Collection $payments = null,
    ): array {
        $rows = $this->paymentsForRequest($request, $payments);
        $paidRows = $rows
            ->filter(fn (TechnicalServiceMountPayment $payment): bool => $payment->status === TechnicalServiceMountPayment::STATUS_PAID)
            ->values();
        $pendingRows = $rows
            ->filter(fn (TechnicalServiceMountPayment $payment): bool => $payment->status === TechnicalServiceMountPayment::STATUS_PENDING)
            ->values();
        $cancelledRows = $rows
            ->filter(fn (TechnicalServiceMountPayment $payment): bool => $payment->status === TechnicalServiceMountPayment::STATUS_CANCELLED)
            ->values();

        $paidTotal = $this->money($paidRows->sum(fn (TechnicalServiceMountPayment $payment): float => $this->money($payment->amount)));
        $pendingTotal = $this->money($pendingRows->sum(fn (TechnicalServiceMountPayment $payment): float => $this->money($payment->amount)));
        $cancelledTotal = $this->money($cancelledRows->sum(fn (TechnicalServiceMountPayment $payment): float => $this->money($payment->amount)));
        $source = $this->companyCollectedSource($paidRows);
        $directAmount = $this->money($settlement?->customer_direct_to_technician_amount);
        $customerPaysTechnician = $paidTotal <= 0.0 && $directAmount > 0.0;
        $state = $this->stateFor($request, $paidTotal, $pendingTotal, $customerPaysTechnician, $source);
        $shouldPayTechnician = $state === self::STATE_CUSTOMER_PAYS_TECHNICIAN;

        return [
            'payer_state_key' => $state,
            'payer_state_label' => $this->stateLabel($state),
            'payer_state_description' => $this->stateDescription($state),
            'payment_instruction_for_customer' => $this->customerInstruction($state),
            'customer_should_pay_technician' => $shouldPayTechnician,
            'company_collected_amount' => $paidTotal,
            'company_collected_source' => $paidTotal > 0.0 ? $source : 'none',
            'customer_direct_to_technician_amount' => $directAmount,
            'active_customer_direct_to_technician_amount' => $shouldPayTechnician ? $directAmount : 0.0,
            'customer_direct_assumed_paid_amount' => $shouldPayTechnician
                ? $this->money($settlement?->customer_direct_assumed_paid_amount)
                : 0.0,
            'company_payable_amount' => $this->money($settlement?->company_payable_amount),
            'company_remaining_amount' => $this->money($settlement?->company_remaining_amount),
            'pending_payment_total' => $pendingTotal,
            'cancelled_payment_total' => $cancelledTotal,
            'paid_payment_count' => $paidRows->count(),
            'pending_payment_count' => $pendingRows->count(),
            'cancelled_payment_count' => $cancelledRows->count(),
            'wp_payment_message_trigger' => 'appointment_approval',
            'wp_payment_message_ready' => false,
        ];
    }

    /**
     * @param Collection<int, TechnicalServiceMountPayment>|null $payments
     * @return Collection<int, TechnicalServiceMountPayment>
     */
    public function paymentsForRequest(TechnicalServiceRequest $request, ?Collection $payments = null): Collection
    {
        $rows = $payments ?? TechnicalServiceMountPayment::query()
            ->where(function ($query) use ($request): void {
                $query->where('technical_service_request_id', $request->id);

                if ($request->mount_session_id !== null) {
                    $query->orWhere('technical_service_mount_session_id', $request->mount_session_id);
                }
            })
            ->get();

        return $rows
            ->filter(fn (mixed $payment): bool => $payment instanceof TechnicalServiceMountPayment)
            ->filter(fn (TechnicalServiceMountPayment $payment): bool => $this->belongsToRequest($payment, $request))
            ->unique(fn (TechnicalServiceMountPayment $payment): int => (int) $payment->id)
            ->values();
    }

    private function stateFor(
        TechnicalServiceRequest $request,
        float $paidTotal,
        float $pendingTotal,
        bool $customerPaysTechnician,
        string $source,
    ): string {
        if ($paidTotal > 0.0) {
            return $source === 'manual' ? self::STATE_COMPANY_COLLECTED_EXTERNAL : self::STATE_COMPANY_COLLECTED_ONLINE;
        }

        if ($pendingTotal > 0.0) {
            return self::STATE_PENDING_ONLINE_PAYMENT;
        }

        if ($customerPaysTechnician) {
            return self::STATE_CUSTOMER_PAYS_TECHNICIAN;
        }

        return $this->requiresPaymentDecision($request)
            ? self::STATE_PAYMENT_DECISION_MISSING
            : self::STATE_NO_PAYMENT_REQUIRED;
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_COMPANY_COLLECTED_ONLINE => 'Ödeme şirket tarafından alındı.',
            self::STATE_COMPANY_COLLECTED_EXTERNAL => 'Dış ödeme alındı.',
            self::STATE_CUSTOMER_PAYS_TECHNICIAN => 'Ödeme müşteriden ustaya yapılacak.',
            self::STATE_PENDING_ONLINE_PAYMENT => 'Online ödeme linki bekliyor.',
            self::STATE_NO_PAYMENT_REQUIRED => 'Bu işte ek ödeme gerekmiyor.',
            default => 'Ödeme yöntemi netleşmedi.',
        };
    }

    private function stateDescription(string $state): string
    {
        return match ($state) {
            self::STATE_COMPANY_COLLECTED_ONLINE => 'Müşteri ödemesi online alındı. Ustaya ödeme şirket tarafından hakediş mutabakatından takip edilecek.',
            self::STATE_COMPANY_COLLECTED_EXTERNAL => 'Müşteri ödemesi dış ödeme olarak alındı. Ustaya ödeme şirket tarafından hakediş mutabakatından takip edilecek.',
            self::STATE_CUSTOMER_PAYS_TECHNICIAN => 'Müşteriye bildirilecek tutar ustaya ödenecek tutardır; tamamlamada ustaya ödenmiş varsayılır.',
            self::STATE_PENDING_ONLINE_PAYMENT => 'Ödeme alınmadan müşteri tahsilatı sayılmaz; bekleyen veya iptal edilen linkler tahsilata eklenmez.',
            self::STATE_NO_PAYMENT_REQUIRED => 'Bu talepte müşteri ödeme talimatı yok.',
            default => 'Ödeme linki oluşturun veya müşterinin ustaya ödeyeceği tutarı belirleyin.',
        };
    }

    private function customerInstruction(string $state): string
    {
        return match ($state) {
            self::STATE_CUSTOMER_PAYS_TECHNICIAN => 'Müşteri ustaya ödeme yapacak.',
            self::STATE_COMPANY_COLLECTED_ONLINE,
            self::STATE_COMPANY_COLLECTED_EXTERNAL => 'Müşteri ustaya ödeme yapmayacak.',
            self::STATE_PENDING_ONLINE_PAYMENT => 'Online ödeme sonucu bekleniyor.',
            self::STATE_NO_PAYMENT_REQUIRED => 'Müşteriye ödeme talimatı yok.',
            default => 'Ödeme yöntemi netleşmeli.',
        };
    }

    /**
     * @param Collection<int, TechnicalServiceMountPayment> $paidRows
     */
    private function companyCollectedSource(Collection $paidRows): string
    {
        if ($paidRows->isEmpty()) {
            return 'none';
        }

        $sources = $paidRows
            ->map(fn (TechnicalServiceMountPayment $payment): string => $this->paymentCollectionSource($payment))
            ->unique()
            ->values();

        if ($sources->count() > 1) {
            return 'mixed';
        }

        return $sources->first() ?: 'online';
    }

    private function paymentCollectionSource(TechnicalServiceMountPayment $payment): string
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $provider = strtolower(trim((string) $payment->provider));
        $source = strtolower(trim((string) ($payload['source'] ?? '')));
        $amountSource = strtolower(trim((string) ($payload['amount_source'] ?? '')));

        if (in_array($provider, ['manual', 'external', 'cash', 'bank_transfer', 'eft', 'havale'], true)
            || in_array($source, ['external_payment', 'manual_external_payment', 'manual_payment', 'ops_manual_payment'], true)
            || in_array($amountSource, ['external_payment', 'manual_external_payment'], true)) {
            return 'manual';
        }

        return 'online';
    }

    private function belongsToRequest(TechnicalServiceMountPayment $payment, TechnicalServiceRequest $request): bool
    {
        if ((int) ($payment->technical_service_request_id ?? 0) === (int) $request->id) {
            return true;
        }

        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        if ((int) ($payload['technical_service_request_id'] ?? 0) === (int) $request->id) {
            return true;
        }

        return $request->source_channel === TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM
            && $request->mount_session_id !== null
            && (int) ($payment->technical_service_mount_session_id ?? 0) === (int) $request->mount_session_id
            && $payment->technical_service_request_id === null
            && in_array(($payload['source'] ?? null), ['public_mount_payment', 'public_form_payment'], true);
    }

    private function requiresPaymentDecision(TechnicalServiceRequest $request): bool
    {
        return $request->sale_mount_status === TechnicalServiceMountSession::SALE_MONTAJ_HARIC
            || in_array($request->mount_payment_status, [
                TechnicalServiceMountSession::PAYMENT_PENDING,
                TechnicalServiceMountSession::PAYMENT_FAILED,
                TechnicalServiceMountSession::PAYMENT_CANCELLED,
                TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
            ], true);
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
