<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningPayment;
use App\Models\TechnicalServiceSettlement;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TechnicalServiceSettlementReviewService
{
    public const DECISION_APPROVE_DIFFERENCE = 'approve_difference';

    public const DECISION_CORRECT_DIRECT_AMOUNT = 'correct_direct_amount';

    public const DECISION_EXCLUDE = 'exclude';

    public function __construct(
        private readonly TechnicalServiceSettlementCalculator $calculator,
        private readonly TechnicalServiceEarningSettlementSummaryService $summary,
        private readonly TechnicalServicePaymentOwnershipService $paymentOwnership,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function resolveForEarning(
        TechnicalServiceEarning $earning,
        int $settlementId,
        string $decision,
        array $payload,
        ?Authenticatable $user = null,
    ): TechnicalServiceSettlement {
        return DB::transaction(function () use ($earning, $settlementId, $decision, $payload, $user): TechnicalServiceSettlement {
            $earning->loadMissing('items');
            $requestIds = $earning->items->pluck('technical_service_request_id')->filter()->map(fn ($id): int => (int) $id)->all();

            $settlement = TechnicalServiceSettlement::query()
                ->whereIn('technical_service_request_id', $requestIds)
                ->whereKey($settlementId)
                ->with(['earningPayments', 'request'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureReviewable($settlement);

            return match ($decision) {
                self::DECISION_APPROVE_DIFFERENCE => $this->approveDifference($settlement, $this->requiredReason($payload), $user),
                self::DECISION_CORRECT_DIRECT_AMOUNT => $this->correctDirectAmount($settlement, $payload, $user),
                self::DECISION_EXCLUDE => $this->exclude($settlement, $this->requiredReason($payload), $user),
                default => throw ValidationException::withMessages([
                    'decision' => 'Geçersiz inceleme kararı.',
                ]),
            };
        });
    }

    private function ensureReviewable(TechnicalServiceSettlement $settlement): void
    {
        if (in_array($settlement->status, [
            TechnicalServiceSettlement::STATUS_PAID,
            TechnicalServiceSettlement::STATUS_PARTIAL_PAID,
        ], true)) {
            throw ValidationException::withMessages([
                'settlement' => 'Ödeme kaydı olan hakediş mutabakatı bu akıştan değiştirilemez.',
            ]);
        }

        if ($this->appliedPayoutCount($settlement) > 0) {
            throw ValidationException::withMessages([
                'settlement' => 'Ödeme ledger kaydı olan hakediş mutabakatı bu akıştan değiştirilemez.',
            ]);
        }

        if ($settlement->status !== TechnicalServiceSettlement::STATUS_ADMIN_REVIEW && ! (bool) $settlement->overpay_requires_review) {
            throw ValidationException::withMessages([
                'settlement' => 'Bu hakediş mutabakatı admin incelemesinde değil.',
            ]);
        }
    }

    private function approveDifference(
        TechnicalServiceSettlement $settlement,
        string $reason,
        ?Authenticatable $user,
    ): TechnicalServiceSettlement {
        $companyPayable = $this->money($settlement->company_payable_amount);
        $companyPaid = $this->summary->appliedCompanyPayoutTotal($settlement);
        $remaining = max($companyPayable - $companyPaid, 0.0);

        $settlement->forceFill([
            'overpay_requires_review' => false,
            'status' => TechnicalServiceSettlement::STATUS_FINALIZED,
            'company_payable_amount' => $companyPayable,
            'company_paid_amount' => $companyPaid,
            'company_remaining_amount' => $remaining,
            'review_reason' => $this->reviewReason($settlement, $reason),
            'finalized_at' => $settlement->finalized_at ?? now(),
            'updated_by' => $user?->getAuthIdentifier(),
            'metadata' => $this->metadataWithDecision($settlement, self::DECISION_APPROVE_DIFFERENCE, $reason, $user),
        ])->save();

        $this->writeEvent($settlement, 'settlement_review_approved', 'Hakediş mutabakatı admin tarafından onaylandı', $reason, $user);

        return $settlement->refresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function correctDirectAmount(
        TechnicalServiceSettlement $settlement,
        array $payload,
        ?Authenticatable $user,
    ): TechnicalServiceSettlement {
        $reason = $this->requiredReason($payload);
        $directAmount = $this->money($payload['customer_direct_to_technician_amount'] ?? null);

        if ($directAmount < 0.0) {
            throw ValidationException::withMessages([
                'customer_direct_to_technician_amount' => 'Müşteriye bildirilecek tutar negatif olamaz.',
            ]);
        }

        $technicianEarning = $this->money($settlement->technician_earning_total);
        $settlement->loadMissing('request');
        $ownership = $settlement->request
            ? $this->paymentOwnership->summary($settlement->request, $settlement)
            : ['company_collected_amount' => $this->money($settlement->customer_collection_amount)];
        $customerCollection = $this->money($ownership['company_collected_amount'] ?? $settlement->customer_collection_amount);
        $activeDirectAmount = $customerCollection > 0.0 ? 0.0 : $directAmount;
        $calculation = $this->calculator->calculate($technicianEarning, $activeDirectAmount, $customerCollection);
        $companyPayable = $this->money($calculation['company_payable_amount']);
        $companyPaid = $this->summary->appliedCompanyPayoutTotal($settlement);

        if ($companyPaid > $companyPayable) {
            throw ValidationException::withMessages([
                'settlement' => 'Mevcut şirket ödeme ledger tutarı yeni ödenecek tutardan büyük. Düzeltme için ayrı muhasebe akışı gerekir.',
            ]);
        }

        $overpayWarning = $this->money($calculation['overpay_warning_amount']);
        $requiresReview = $overpayWarning > 0.0;
        $remaining = max($companyPayable - $companyPaid, 0.0);

        $settlement->forceFill([
            'customer_direct_to_technician_amount' => $directAmount,
            'customer_direct_assumed_paid_amount' => $customerCollection > 0.0 ? 0.0 : $directAmount,
            'company_payable_amount' => $companyPayable,
            'company_paid_amount' => $companyPaid,
            'company_remaining_amount' => $remaining,
            'overpay_warning_amount' => $overpayWarning,
            'overpay_requires_review' => $requiresReview,
            'status' => $requiresReview
                ? TechnicalServiceSettlement::STATUS_ADMIN_REVIEW
                : TechnicalServiceSettlement::STATUS_FINALIZED,
            'review_reason' => $requiresReview
                ? $this->reviewReason($settlement, 'Müşteriye bildirilen tutar usta hakedişinden yüksek. '.$reason)
                : $this->reviewReason($settlement, $reason),
            'finalized_at' => $requiresReview ? null : ($settlement->finalized_at ?? now()),
            'updated_by' => $user?->getAuthIdentifier(),
            'metadata' => $this->metadataWithDecision($settlement, self::DECISION_CORRECT_DIRECT_AMOUNT, $reason, $user, [
                'customer_direct_to_technician_amount' => $directAmount,
                'active_customer_direct_to_technician_amount' => $activeDirectAmount,
                'company_payable_amount' => $companyPayable,
                'overpay_warning_amount' => $overpayWarning,
                'requires_review_after_decision' => $requiresReview,
            ]),
        ])->save();

        $this->writeEvent($settlement, 'settlement_review_corrected', 'Hakediş mutabakatı tutarı düzeltildi', $reason, $user);

        return $settlement->refresh();
    }

    private function exclude(
        TechnicalServiceSettlement $settlement,
        string $reason,
        ?Authenticatable $user,
    ): TechnicalServiceSettlement {
        $settlement->forceFill([
            'status' => TechnicalServiceSettlement::STATUS_EXCLUDED,
            'company_payable_amount' => 0,
            'company_remaining_amount' => 0,
            'overpay_requires_review' => false,
            'review_reason' => $this->reviewReason($settlement, $reason),
            'excluded_at' => now(),
            'updated_by' => $user?->getAuthIdentifier(),
            'metadata' => $this->metadataWithDecision($settlement, self::DECISION_EXCLUDE, $reason, $user),
        ])->save();

        $this->writeEvent($settlement, 'settlement_review_excluded', 'Hakediş mutabakatı hakedişe dahil değil olarak işaretlendi', $reason, $user);

        return $settlement->refresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredReason(array $payload): string
    {
        $reason = trim((string) ($payload['reason'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Admin inceleme kararı için açıklama zorunludur.',
            ]);
        }

        return $reason;
    }

    private function appliedPayoutCount(TechnicalServiceSettlement $settlement): int
    {
        $settlement->loadMissing('earningPayments');

        return $settlement->earningPayments
            ->filter(fn (TechnicalServiceEarningPayment $payment): bool => $payment->payment_type === TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT
                && $payment->status === TechnicalServiceEarningPayment::STATUS_APPLIED)
            ->count();
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function metadataWithDecision(
        TechnicalServiceSettlement $settlement,
        string $decision,
        string $reason,
        ?Authenticatable $user,
        array $extra = [],
    ): array {
        $metadata = is_array($settlement->metadata) ? $settlement->metadata : [];
        $entry = array_merge([
            'decision' => $decision,
            'reason' => $reason,
            'reviewed_at' => now()->toISOString(),
            'reviewed_by' => $user?->getAuthIdentifier(),
            'reviewed_by_name' => $this->userDisplayName($user),
        ], $extra);
        $history = is_array($metadata['admin_review_history'] ?? null)
            ? $metadata['admin_review_history']
            : [];
        $history[] = $entry;
        $metadata['admin_review'] = $entry;
        $metadata['admin_review_history'] = $history;

        return $metadata;
    }

    private function reviewReason(TechnicalServiceSettlement $settlement, string $reason): string
    {
        $existing = trim((string) $settlement->review_reason);

        if ($existing === '') {
            return $reason;
        }

        if (str_contains($existing, $reason)) {
            return $existing;
        }

        return $existing."\n".$reason;
    }

    private function writeEvent(
        TechnicalServiceSettlement $settlement,
        string $eventType,
        string $title,
        string $reason,
        ?Authenticatable $user,
    ): void {
        $request = $settlement->request;

        if (! $request) {
            return;
        }

        $request->events()->create([
            'event_type' => $eventType,
            'title' => $title,
            'note' => $reason,
            'from_status' => $request->workflow_status,
            'to_status' => $request->workflow_status,
            'author_user_id' => $user?->getAuthIdentifier(),
            'metadata' => [
                'settlement_id' => $settlement->id,
                'status' => $settlement->status,
                'company_payable_amount' => $this->money($settlement->company_payable_amount),
                'customer_direct_to_technician_amount' => $this->money($settlement->customer_direct_to_technician_amount),
                'overpay_warning_amount' => $this->money($settlement->overpay_warning_amount),
            ],
        ]);
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function userDisplayName(?Authenticatable $user): ?string
    {
        if (! $user) {
            return null;
        }

        return (string) ($user->full_name ?? $user->name ?? $user->getAuthIdentifier());
    }
}
