<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningItem;
use App\Models\TechnicalServiceEarningPayment;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceSettlement;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class TechnicalServiceEarningSettlementSummaryService
{
    public function __construct(
        private readonly TechnicalServicePaymentOwnershipService $paymentOwnership,
    ) {
    }

    /**
     * @return Collection<int, TechnicalServiceSettlement>
     */
    public function settlementsFor(TechnicalServiceEarning $earning): Collection
    {
        $earning->loadMissing(['items.request.settlement.earningPayments']);

        return $earning->items
            ->map(fn (TechnicalServiceEarningItem $item): ?TechnicalServiceSettlement => $item->request?->settlement)
            ->filter(fn (?TechnicalServiceSettlement $settlement): bool => $settlement instanceof TechnicalServiceSettlement)
            ->unique('id')
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryFor(TechnicalServiceEarning $earning): array
    {
        $settlements = $this->settlementsFor($earning);
        $itemCount = $earning->items->count();
        $missingSettlementCount = max($itemCount - $settlements->count(), 0);
        $companyPayable = $this->money($settlements->sum(fn (TechnicalServiceSettlement $settlement): float => $this->money($settlement->company_payable_amount)));
        $companyPaid = $this->money($settlements->sum(fn (TechnicalServiceSettlement $settlement): float => $this->appliedCompanyPayoutTotal($settlement)));
        $companyRemaining = $this->money($settlements->sum(fn (TechnicalServiceSettlement $settlement): float => $this->remainingAmount($settlement)));
        $customerDirectAssumed = $this->money($settlements->sum(fn (TechnicalServiceSettlement $settlement): float => $this->money($settlement->customer_direct_assumed_paid_amount)));
        $customerCollection = $this->money($settlements->sum(fn (TechnicalServiceSettlement $settlement): float => $this->money($settlement->customer_collection_amount)));
        $payerState = $this->aggregatePayerState($settlements);
        $adminReviewCount = $settlements
            ->filter(fn (TechnicalServiceSettlement $settlement): bool => $this->isAdminReview($settlement))
            ->count();
        $excludedCount = $settlements
            ->filter(fn (TechnicalServiceSettlement $settlement): bool => $settlement->status === TechnicalServiceSettlement::STATUS_EXCLUDED)
            ->count();
        $paidCount = $settlements
            ->filter(fn (TechnicalServiceSettlement $settlement): bool => $this->remainingAmount($settlement) <= 0.0
                && $this->money($settlement->company_payable_amount) > 0.0)
            ->count();
        $partialPaidCount = $settlements
            ->filter(fn (TechnicalServiceSettlement $settlement): bool => $this->appliedCompanyPayoutTotal($settlement) > 0.0
                && $this->remainingAmount($settlement) > 0.0)
            ->count();

        [$canRecordPayout, $disabledReason] = $this->payoutAvailability(
            $settlements,
            $missingSettlementCount,
            $companyPayable,
            $companyRemaining,
            $adminReviewCount,
        );
        $state = $this->earningState(
            $settlements,
            $missingSettlementCount,
            $companyPayable,
            $companyPaid,
            $companyRemaining,
            $adminReviewCount,
            $excludedCount,
            $disabledReason,
        );

        return [
            'settlement_count' => $settlements->count(),
            'missing_settlement_count' => $missingSettlementCount,
            'reconciliation_missing' => $missingSettlementCount > 0 || $settlements->isEmpty(),
            'reconciliation_missing_reason' => $missingSettlementCount > 0 || $settlements->isEmpty()
                ? 'Ödeme için önce hakediş mutabakatı oluşturulmalı.'
                : null,
            'company_payable_amount' => $companyPayable,
            'company_paid_amount' => $companyPaid,
            'company_remaining_amount' => $companyRemaining,
            'customer_direct_assumed_paid_amount' => $customerDirectAssumed,
            'customer_collection_amount' => $customerCollection,
            'admin_review_count' => $adminReviewCount,
            'excluded_count' => $excludedCount,
            'paid_count' => $paidCount,
            'partial_paid_count' => $partialPaidCount,
            'can_record_payout' => $canRecordPayout,
            'payout_disabled_reason' => $disabledReason,
            'can_pay_company_payout' => $canRecordPayout,
            'settlement_status_key' => $state['key'],
            'settlement_status_label' => $state['label'],
            'settlement_disabled_reason' => $state['disabled_reason'],
            'payment_action_label' => $state['payment_action_label'],
            'payer_state_key' => $payerState['payer_state_key'],
            'payer_state_label' => $payerState['payer_state_label'],
            'payer_state_description' => $payerState['payer_state_description'],
            'payment_instruction_for_customer' => $payerState['payment_instruction_for_customer'],
            'customer_should_pay_technician' => $payerState['customer_should_pay_technician'],
            'company_collected_amount' => $payerState['company_collected_amount'],
            'company_collected_source' => $payerState['company_collected_source'],
            'active_customer_direct_to_technician_amount' => $payerState['active_customer_direct_to_technician_amount'] ?? 0,
            'pending_payment_total' => $payerState['pending_payment_total'],
            'cancelled_payment_total' => $payerState['cancelled_payment_total'],
        ];
    }

    public function decorate(TechnicalServiceEarning $earning, bool $withItems = false): TechnicalServiceEarning
    {
        $summary = $this->summaryFor($earning);

        $earning->setAttribute('settlement_summary', $summary);
        $earning->setAttribute('company_payable_amount', $summary['company_payable_amount']);
        $earning->setAttribute('company_paid_amount', $summary['company_paid_amount']);
        $earning->setAttribute('company_remaining_amount', $summary['company_remaining_amount']);
        $earning->setAttribute('customer_direct_assumed_paid_amount', $summary['customer_direct_assumed_paid_amount']);
        $earning->setAttribute('customer_collection_amount', $summary['customer_collection_amount']);
        $earning->setAttribute('can_record_payout', $summary['can_record_payout']);
        $earning->setAttribute('payout_disabled_reason', $summary['payout_disabled_reason']);
        $earning->setAttribute('can_pay_company_payout', $summary['can_pay_company_payout']);
        $earning->setAttribute('settlement_status_key', $summary['settlement_status_key']);
        $earning->setAttribute('settlement_status_label', $summary['settlement_status_label']);
        $earning->setAttribute('settlement_disabled_reason', $summary['settlement_disabled_reason']);
        $earning->setAttribute('payment_action_label', $summary['payment_action_label']);
        $earning->setAttribute('reconciliation_missing', $summary['reconciliation_missing']);
        $earning->setAttribute('reconciliation_missing_reason', $summary['reconciliation_missing_reason']);
        $earning->setAttribute('payer_state_key', $summary['payer_state_key']);
        $earning->setAttribute('payer_state_label', $summary['payer_state_label']);
        $earning->setAttribute('payer_state_description', $summary['payer_state_description']);
        $earning->setAttribute('payment_instruction_for_customer', $summary['payment_instruction_for_customer']);
        $earning->setAttribute('customer_should_pay_technician', $summary['customer_should_pay_technician']);
        $earning->setAttribute('company_collected_amount', $summary['company_collected_amount']);
        $earning->setAttribute('company_collected_source', $summary['company_collected_source']);
        $earning->setAttribute('pending_payment_total', $summary['pending_payment_total']);
        $earning->setAttribute('cancelled_payment_total', $summary['cancelled_payment_total']);
        $earning->syncOriginalAttributes([
            'settlement_summary',
            'company_payable_amount',
            'company_paid_amount',
            'company_remaining_amount',
            'customer_direct_assumed_paid_amount',
            'customer_collection_amount',
            'can_record_payout',
            'payout_disabled_reason',
            'can_pay_company_payout',
            'settlement_status_key',
            'settlement_status_label',
            'settlement_disabled_reason',
            'payment_action_label',
            'reconciliation_missing',
            'reconciliation_missing_reason',
            'payer_state_key',
            'payer_state_label',
            'payer_state_description',
            'payment_instruction_for_customer',
            'customer_should_pay_technician',
            'company_collected_amount',
            'company_collected_source',
            'pending_payment_total',
            'cancelled_payment_total',
        ]);

        if ($withItems) {
            $earning->items->each(function (TechnicalServiceEarningItem $item): void {
                $settlement = $item->request?->settlement;
                $item->setAttribute('settlement_summary', $settlement instanceof TechnicalServiceSettlement
                    ? $this->settlementPayload($settlement)
                    : null);
                $item->syncOriginalAttribute('settlement_summary');
            });
        }

        return $earning;
    }

    /**
     * @return array<string, mixed>
     */
    public function settlementPayload(TechnicalServiceSettlement $settlement): array
    {
        $companyPaid = $this->appliedCompanyPayoutTotal($settlement);
        $payerState = $this->payerStateForSettlement($settlement);

        return [
            'id' => $settlement->id,
            'status' => $settlement->status,
            'status_label' => $this->statusLabel($settlement),
            'request_code' => $settlement->request_code,
            'root_mrn' => $settlement->root_mrn,
            'labor_earning_amount' => $this->money($settlement->labor_earning_amount),
            'route_earning_amount' => $this->money($settlement->route_earning_amount),
            'technician_earning_total' => $this->money($settlement->technician_earning_total),
            'company_payable_amount' => $this->money($settlement->company_payable_amount),
            'company_paid_amount' => $companyPaid,
            'company_remaining_amount' => $this->remainingAmount($settlement),
            'customer_direct_to_technician_amount' => $this->money($settlement->customer_direct_to_technician_amount),
            'customer_direct_assumed_paid_amount' => $this->money($settlement->customer_direct_assumed_paid_amount),
            'customer_collection_amount' => $this->money($settlement->customer_collection_amount),
            'overpay_warning_amount' => $this->money($settlement->overpay_warning_amount),
            'overpay_requires_review' => (bool) $settlement->overpay_requires_review,
            'review_reason' => $settlement->review_reason,
            'review_decision' => $this->reviewDecisionPayload($settlement),
            'payment_context' => $this->paymentContext($settlement),
            'payer_state_key' => $payerState['payer_state_key'],
            'payer_state_label' => $payerState['payer_state_label'],
            'payer_state_description' => $payerState['payer_state_description'],
            'payment_instruction_for_customer' => $payerState['payment_instruction_for_customer'],
            'customer_should_pay_technician' => $payerState['customer_should_pay_technician'],
            'company_collected_amount' => $payerState['company_collected_amount'],
            'company_collected_source' => $payerState['company_collected_source'],
            'pending_payment_total' => $payerState['pending_payment_total'],
            'cancelled_payment_total' => $payerState['cancelled_payment_total'],
            'wp_payment_message_trigger' => $payerState['wp_payment_message_trigger'],
            'wp_payment_message_ready' => $payerState['wp_payment_message_ready'],
        ];
    }

    public function appliedCompanyPayoutTotal(TechnicalServiceSettlement $settlement): float
    {
        $settlement->loadMissing('earningPayments');

        /** @var EloquentCollection<int, TechnicalServiceEarningPayment> $payments */
        $payments = $settlement->earningPayments;

        return $this->money($payments
            ->filter(fn (TechnicalServiceEarningPayment $payment): bool => $payment->payment_type === TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT
                && $payment->status === TechnicalServiceEarningPayment::STATUS_APPLIED)
            ->sum(fn (TechnicalServiceEarningPayment $payment): float => $this->money($payment->amount)));
    }

    public function remainingAmount(TechnicalServiceSettlement $settlement): float
    {
        return max($this->money($settlement->company_payable_amount) - $this->appliedCompanyPayoutTotal($settlement), 0.0);
    }

    public function statusLabel(TechnicalServiceSettlement $settlement): string
    {
        return match ($settlement->status) {
            TechnicalServiceSettlement::STATUS_PARTIAL_PAID => 'Kısmi ödendi',
            TechnicalServiceSettlement::STATUS_PAID => 'Ödendi',
            TechnicalServiceSettlement::STATUS_EXCLUDED => 'Hakedişe dahil değil',
            TechnicalServiceSettlement::STATUS_ADMIN_REVIEW => 'Admin incelemesi',
            TechnicalServiceSettlement::STATUS_FINALIZED => 'Kesinleşti',
            TechnicalServiceSettlement::STATUS_SENT => 'Gönderildi',
            default => 'Taslak',
        };
    }

    private function isAdminReview(TechnicalServiceSettlement $settlement): bool
    {
        return $settlement->status === TechnicalServiceSettlement::STATUS_ADMIN_REVIEW
            || (bool) $settlement->overpay_requires_review;
    }

    /**
     * @param Collection<int, TechnicalServiceSettlement> $settlements
     * @return array{0: bool, 1: string|null}
     */
    private function payoutAvailability(
        Collection $settlements,
        int $missingSettlementCount,
        float $companyPayable,
        float $companyRemaining,
        int $adminReviewCount,
    ): array {
        if ($settlements->isEmpty() || $missingSettlementCount > 0) {
            return [false, 'Ödeme için önce hakediş mutabakatı oluşturulmalı.'];
        }

        if ($adminReviewCount > 0) {
            return [false, 'Admin incelemesi tamamlanmadan ödeme yapılamaz.'];
        }

        if ($companyPayable <= 0.0) {
            return [false, 'Ödenecek tutar yok.'];
        }

        if ($companyRemaining <= 0.0) {
            return [false, 'Hakediş ödemesi tamamlanmış.'];
        }

        return [true, null];
    }

    /**
     * @param Collection<int, TechnicalServiceSettlement> $settlements
     * @return array{key: string, label: string, disabled_reason: string|null, payment_action_label: string}
     */
    private function earningState(
        Collection $settlements,
        int $missingSettlementCount,
        float $companyPayable,
        float $companyPaid,
        float $companyRemaining,
        int $adminReviewCount,
        int $excludedCount,
        ?string $disabledReason,
    ): array {
        if ($settlements->isEmpty() || $missingSettlementCount > 0) {
            return [
                'key' => 'reconciliation_missing',
                'label' => 'Mutabakat oluşmadı',
                'disabled_reason' => 'Ödeme için önce hakediş mutabakatı oluşturulmalı.',
                'payment_action_label' => 'Mutabakat oluştur',
            ];
        }

        if ($adminReviewCount > 0) {
            return [
                'key' => 'admin_review',
                'label' => 'Admin incelemesi',
                'disabled_reason' => $disabledReason,
                'payment_action_label' => 'İncele',
            ];
        }

        if ($excludedCount > 0 && $excludedCount === $settlements->count()) {
            return [
                'key' => 'excluded',
                'label' => 'Hakedişe dahil değil',
                'disabled_reason' => 'Hakedişe dahil değil.',
                'payment_action_label' => 'Hakedişe dahil değil',
            ];
        }

        if ($companyPayable <= 0.0) {
            return [
                'key' => 'no_company_payable',
                'label' => 'Şirket ödemesi yok',
                'disabled_reason' => $disabledReason,
                'payment_action_label' => 'Ödenecek tutar yok',
            ];
        }

        if ($companyRemaining <= 0.0 && $companyPaid >= $companyPayable) {
            return [
                'key' => 'paid',
                'label' => 'Ödendi',
                'disabled_reason' => $disabledReason,
                'payment_action_label' => 'Ödendi',
            ];
        }

        if ($companyPaid > 0.0 && $companyRemaining > 0.0) {
            return [
                'key' => 'partial_paid',
                'label' => 'Kısmi ödendi',
                'disabled_reason' => null,
                'payment_action_label' => 'Kalanı öde',
            ];
        }

        return [
            'key' => 'payable',
            'label' => 'Ödenecek',
            'disabled_reason' => null,
            'payment_action_label' => 'Ödeme Yap',
        ];
    }

    /**
     * @param Collection<int, TechnicalServiceSettlement> $settlements
     * @return array<string, mixed>
     */
    private function aggregatePayerState(Collection $settlements): array
    {
        if ($settlements->isEmpty()) {
            return [
                'payer_state_key' => TechnicalServicePaymentOwnershipService::STATE_PAYMENT_DECISION_MISSING,
                'payer_state_label' => 'Ödeme yöntemi netleşmedi.',
                'payer_state_description' => 'Ödeme için önce online tahsilat veya müşterinin ustaya ödeyeceği tutar netleşmelidir.',
                'payment_instruction_for_customer' => 'Ödeme yöntemi netleşmeli.',
                'customer_should_pay_technician' => false,
                'company_collected_amount' => 0.0,
                'company_collected_source' => 'none',
                'pending_payment_total' => 0.0,
                'cancelled_payment_total' => 0.0,
            ];
        }

        $states = $settlements
            ->map(fn (TechnicalServiceSettlement $settlement): array => $this->payerStateForSettlement($settlement))
            ->values();
        $companyCollectedAmount = $this->money($states->sum('company_collected_amount'));
        $pendingTotal = $this->money($states->sum('pending_payment_total'));
        $cancelledTotal = $this->money($states->sum('cancelled_payment_total'));
        $sources = $states
            ->pluck('company_collected_source')
            ->filter(fn (mixed $source): bool => is_string($source) && $source !== '' && $source !== 'none')
            ->unique()
            ->values();

        if ($companyCollectedAmount > 0.0) {
            $source = $sources->count() > 1 ? 'mixed' : ($sources->first() ?: 'online');
            $key = $source === 'manual'
                ? TechnicalServicePaymentOwnershipService::STATE_COMPANY_COLLECTED_EXTERNAL
                : TechnicalServicePaymentOwnershipService::STATE_COMPANY_COLLECTED_ONLINE;

            return [
                'payer_state_key' => $key,
                'payer_state_label' => $key === TechnicalServicePaymentOwnershipService::STATE_COMPANY_COLLECTED_EXTERNAL
                    ? 'Dış ödeme alındı.'
                    : 'Ödeme şirket tarafından alındı.',
                'payer_state_description' => 'Müşteri tahsilatı şirket üzerinden kayıtlıdır; ustaya doğrudan ödeme bildirimi uygulanmaz.',
                'payment_instruction_for_customer' => 'Müşteri ustaya ödeme yapmayacak.',
                'customer_should_pay_technician' => false,
                'company_collected_amount' => $companyCollectedAmount,
                'company_collected_source' => $source,
                'pending_payment_total' => $pendingTotal,
                'cancelled_payment_total' => $cancelledTotal,
            ];
        }

        if ($pendingTotal > 0.0) {
            return [
                'payer_state_key' => TechnicalServicePaymentOwnershipService::STATE_PENDING_ONLINE_PAYMENT,
                'payer_state_label' => 'Online ödeme linki bekliyor.',
                'payer_state_description' => 'Ödeme alınmadan müşteri tahsilatı sayılmaz; bekleyen veya iptal edilen linkler tahsilata eklenmez.',
                'payment_instruction_for_customer' => 'Online ödeme sonucu bekleniyor.',
                'customer_should_pay_technician' => false,
                'company_collected_amount' => 0.0,
                'company_collected_source' => 'none',
                'pending_payment_total' => $pendingTotal,
                'cancelled_payment_total' => $cancelledTotal,
            ];
        }

        $customerPaysTechnician = $states
            ->contains(fn (array $state): bool => ($state['payer_state_key'] ?? null) === TechnicalServicePaymentOwnershipService::STATE_CUSTOMER_PAYS_TECHNICIAN);

        if ($customerPaysTechnician) {
            return [
                'payer_state_key' => TechnicalServicePaymentOwnershipService::STATE_CUSTOMER_PAYS_TECHNICIAN,
                'payer_state_label' => 'Ödeme müşteriden ustaya yapılacak.',
                'payer_state_description' => 'Müşteriye bildirilen tutar iş tamamlandığında ustaya ödenmiş varsayılır.',
                'payment_instruction_for_customer' => 'Müşteri ustaya ödeme yapacak.',
                'customer_should_pay_technician' => true,
                'company_collected_amount' => 0.0,
                'company_collected_source' => 'none',
                'pending_payment_total' => 0.0,
                'cancelled_payment_total' => $cancelledTotal,
            ];
        }

        return [
            'payer_state_key' => TechnicalServicePaymentOwnershipService::STATE_NO_PAYMENT_REQUIRED,
            'payer_state_label' => 'Bu işte ek ödeme gerekmiyor.',
            'payer_state_description' => 'Bu talepte müşteri ödeme talimatı yok.',
            'payment_instruction_for_customer' => 'Müşteriye ödeme talimatı yok.',
            'customer_should_pay_technician' => false,
            'company_collected_amount' => 0.0,
            'company_collected_source' => 'none',
            'pending_payment_total' => 0.0,
            'cancelled_payment_total' => $cancelledTotal,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payerStateForSettlement(TechnicalServiceSettlement $settlement): array
    {
        $settlement->loadMissing('request');

        if ($settlement->request) {
            return $this->paymentOwnership->summary($settlement->request, $settlement);
        }

        $customerCollection = $this->money($settlement->customer_collection_amount);
        $customerDirectAssumed = $this->money($settlement->customer_direct_assumed_paid_amount);

        if ($customerCollection > 0.0) {
            return [
                'payer_state_key' => TechnicalServicePaymentOwnershipService::STATE_COMPANY_COLLECTED_ONLINE,
                'payer_state_label' => 'Ödeme şirket tarafından alındı.',
                'payer_state_description' => 'Müşteri tahsilatı şirket üzerinden kayıtlıdır; ustaya doğrudan ödeme bildirimi uygulanmaz.',
                'payment_instruction_for_customer' => 'Müşteri ustaya ödeme yapmayacak.',
                'customer_should_pay_technician' => false,
                'company_collected_amount' => $customerCollection,
                'company_collected_source' => 'online',
                'pending_payment_total' => 0.0,
                'cancelled_payment_total' => 0.0,
                'wp_payment_message_trigger' => 'appointment_approval',
                'wp_payment_message_ready' => false,
            ];
        }

        if ($customerDirectAssumed > 0.0) {
            return [
                'payer_state_key' => TechnicalServicePaymentOwnershipService::STATE_CUSTOMER_PAYS_TECHNICIAN,
                'payer_state_label' => 'Ödeme müşteriden ustaya yapılacak.',
                'payer_state_description' => 'Müşteriye bildirilen tutar iş tamamlandığında ustaya ödenmiş varsayılır.',
                'payment_instruction_for_customer' => 'Müşteri ustaya ödeme yapacak.',
                'customer_should_pay_technician' => true,
                'company_collected_amount' => 0.0,
                'company_collected_source' => 'none',
                'pending_payment_total' => 0.0,
                'cancelled_payment_total' => 0.0,
                'wp_payment_message_trigger' => 'appointment_approval',
                'wp_payment_message_ready' => false,
            ];
        }

        return [
            'payer_state_key' => TechnicalServicePaymentOwnershipService::STATE_NO_PAYMENT_REQUIRED,
            'payer_state_label' => 'Bu işte ek ödeme gerekmiyor.',
            'payer_state_description' => 'Bu talepte müşteri ödeme talimatı yok.',
            'payment_instruction_for_customer' => 'Müşteriye ödeme talimatı yok.',
            'customer_should_pay_technician' => false,
            'company_collected_amount' => 0.0,
            'company_collected_source' => 'none',
            'pending_payment_total' => 0.0,
            'cancelled_payment_total' => 0.0,
            'wp_payment_message_trigger' => 'appointment_approval',
            'wp_payment_message_ready' => false,
        ];
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reviewDecisionPayload(TechnicalServiceSettlement $settlement): ?array
    {
        $metadata = is_array($settlement->metadata) ? $settlement->metadata : [];
        $decision = $metadata['admin_review'] ?? null;

        return is_array($decision) ? $decision : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentContext(TechnicalServiceSettlement $settlement): array
    {
        $rows = TechnicalServiceMountPayment::query()
            ->where('technical_service_request_id', $settlement->technical_service_request_id)
            ->get();

        $amountFor = fn (string $status): float => $this->money($rows
            ->where('status', $status)
            ->sum(fn (TechnicalServiceMountPayment $payment): float => $this->money($payment->amount)));

        return [
            'paid_total' => $amountFor(TechnicalServiceMountPayment::STATUS_PAID),
            'pending_total' => $amountFor(TechnicalServiceMountPayment::STATUS_PENDING),
            'cancelled_total' => $amountFor(TechnicalServiceMountPayment::STATUS_CANCELLED),
            'paid_count' => $rows->where('status', TechnicalServiceMountPayment::STATUS_PAID)->count(),
            'pending_count' => $rows->where('status', TechnicalServiceMountPayment::STATUS_PENDING)->count(),
            'cancelled_count' => $rows->where('status', TechnicalServiceMountPayment::STATUS_CANCELLED)->count(),
        ];
    }
}
