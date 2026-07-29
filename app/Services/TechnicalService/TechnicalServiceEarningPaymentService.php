<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningPayment;
use App\Models\TechnicalServiceSettlement;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TechnicalServiceEarningPaymentService
{
    public function __construct(
        private readonly TechnicalServiceEarningSettlementSummaryService $summary,
    ) {
    }

    /**
     * @return array{earning: TechnicalServiceEarning, payments: array<int, TechnicalServiceEarningPayment>, summary: array<string, mixed>}
     */
    public function recordCompanyPayoutForEarning(
        int $earningId,
        float $amount,
        ?string $reason = null,
        ?string $reference = null,
        ?Authenticatable $user = null,
    ): array {
        $amount = $this->money($amount);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Ödenen tutar 0 TL’den büyük olmalıdır.',
            ]);
        }

        return DB::transaction(function () use ($earningId, $amount, $reason, $reference, $user): array {
            $earning = TechnicalServiceEarning::query()
                ->with(['period', 'items.request.settlement.earningPayments'])
                ->findOrFail($earningId);
            $settlementIds = $earning->items
                ->map(fn ($item): ?int => $item->request?->settlement?->id)
                ->filter()
                ->unique()
                ->values();

            if ($earning->items->isEmpty() || $settlementIds->count() !== $earning->items->count()) {
                throw ValidationException::withMessages([
                    'settlement' => 'Hakediş mutabakatı oluşmadan ödeme yapılamaz.',
                ]);
            }

            /** @var EloquentCollection<int, TechnicalServiceSettlement> $settlements */
            $settlements = TechnicalServiceSettlement::query()
                ->whereIn('id', $settlementIds)
                ->with('earningPayments')
                ->orderBy('completed_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $payableAdminReview = $settlements
                ->first(fn (TechnicalServiceSettlement $settlement): bool => $this->remainingAmount($settlement) > 0.0
                    && ($settlement->status === TechnicalServiceSettlement::STATUS_ADMIN_REVIEW
                        || (bool) $settlement->overpay_requires_review));

            if ($payableAdminReview instanceof TechnicalServiceSettlement) {
                throw ValidationException::withMessages([
                    'settlement' => 'Admin incelemesi gereken hakediş için ödeme yapılamaz.',
                ]);
            }

            $eligible = $settlements
                ->filter(fn (TechnicalServiceSettlement $settlement): bool => ! in_array($settlement->status, [
                    TechnicalServiceSettlement::STATUS_EXCLUDED,
                    TechnicalServiceSettlement::STATUS_ADMIN_REVIEW,
                ], true))
                ->filter(fn (TechnicalServiceSettlement $settlement): bool => $this->money($settlement->company_payable_amount) > 0.0)
                ->filter(fn (TechnicalServiceSettlement $settlement): bool => $this->remainingAmount($settlement) > 0.0)
                ->values();

            if ($eligible->isEmpty()) {
                throw ValidationException::withMessages([
                    'amount' => 'Ödenebilir şirket hakediş bakiyesi yok.',
                ]);
            }

            $totalRemaining = $this->money($eligible->sum(fn (TechnicalServiceSettlement $settlement): float => $this->remainingAmount($settlement)));

            if ($amount > $totalRemaining) {
                throw ValidationException::withMessages([
                    'amount' => 'Ödenen tutar kalan şirket ödemesinden büyük olamaz.',
                ]);
            }

            $itemIdsByRequest = $earning->items->mapWithKeys(
                fn ($item): array => [(int) $item->technical_service_request_id => $item->id],
            );
            $left = $amount;
            $payments = [];
            $batchReference = $reference ?: 'EARNING-'.$earning->id.'-'.now()->format('YmdHis');

            foreach ($eligible as $index => $settlement) {
                if ($left <= 0.0) {
                    break;
                }

                $allocation = min($this->remainingAmount($settlement), $left);
                $allocation = $this->money($allocation);

                if ($allocation <= 0.0) {
                    continue;
                }

                $payment = TechnicalServiceEarningPayment::query()->create([
                    'technical_service_settlement_id' => $settlement->id,
                    'technical_service_request_id' => $settlement->technical_service_request_id,
                    'technical_service_technician_id' => $settlement->technical_service_technician_id,
                    'b2b_partner_id' => $settlement->b2b_partner_id,
                    'currency' => $settlement->currency ?: 'TRY',
                    'payment_type' => TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT,
                    'amount' => $allocation,
                    'status' => TechnicalServiceEarningPayment::STATUS_APPLIED,
                    'paid_at' => now(),
                    'paid_by' => $user?->getAuthIdentifier(),
                    'paid_by_name' => $this->userDisplayName($user),
                    'reason' => $reason,
                    'reference' => $batchReference,
                    'metadata' => [
                        'source' => 'technical_service_earnings_page',
                        'earning_id' => $earning->id,
                        'period_id' => $earning->period_id,
                        'requested_amount' => $amount,
                        'allocation_index' => $index + 1,
                    ],
                ]);

                $settlement->technical_service_earning_item_id = $settlement->technical_service_earning_item_id
                    ?: ($itemIdsByRequest[(int) $settlement->technical_service_request_id] ?? null);
                $this->refreshSettlementPaymentTotals($settlement);
                $payments[] = $payment->fresh();
                $left = $this->money($left - $allocation);
            }

            $earning = $this->refreshEarningPaymentStatus($earning->fresh(['period', 'items.request.settlement.earningPayments']));
            $this->syncPeriodPaidStatus((int) $earning->period_id);

            return [
                'earning' => $this->summary->decorate($earning->fresh(['period', 'items.request.settlement.earningPayments']), true),
                'payments' => $payments,
                'summary' => $this->summary->summaryFor($earning),
            ];
        });
    }

    private function refreshSettlementPaymentTotals(TechnicalServiceSettlement $settlement): void
    {
        $settlement->load('earningPayments');
        $paidTotal = $this->summary->appliedCompanyPayoutTotal($settlement);
        $remaining = max($this->money($settlement->company_payable_amount) - $paidTotal, 0.0);
        $latestPaidAt = $settlement->earningPayments
            ->filter(fn (TechnicalServiceEarningPayment $payment): bool => $payment->payment_type === TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT
                && $payment->status === TechnicalServiceEarningPayment::STATUS_APPLIED)
            ->max('paid_at');

        $settlement->forceFill([
            'company_paid_amount' => $paidTotal,
            'company_remaining_amount' => $remaining,
            'status' => $remaining > 0.0
                ? TechnicalServiceSettlement::STATUS_PARTIAL_PAID
                : TechnicalServiceSettlement::STATUS_PAID,
            'paid_at' => $remaining > 0.0 ? null : $latestPaidAt,
            'metadata' => array_merge(is_array($settlement->metadata) ? $settlement->metadata : [], [
                'earning_payment' => [
                    'last_applied_at' => now()->toISOString(),
                    'company_paid_amount' => $paidTotal,
                    'company_remaining_amount' => $remaining,
                ],
            ]),
        ])->save();
    }

    private function refreshEarningPaymentStatus(TechnicalServiceEarning $earning): TechnicalServiceEarning
    {
        $summary = $this->summary->summaryFor($earning);
        $status = ((float) $summary['company_remaining_amount']) <= 0.0
            ? 'Ödendi'
            : 'Kısmi ödendi';

        $earning->forceFill([
            'status' => $status,
            'paid_at' => $status === 'Ödendi' ? now() : null,
        ])->save();

        return $earning->fresh(['period', 'items.request.settlement.earningPayments']);
    }

    private function syncPeriodPaidStatus(int $periodId): void
    {
        $period = \App\Models\TechnicalServiceEarningsPeriod::query()->find($periodId);

        if (! $period || in_array($period->status, ['paid', 'locked'], true)) {
            return;
        }

        $total = $period->earnings()->count();

        if ($total > 0 && $period->earnings()->where('status', '!=', 'Ödendi')->doesntExist()) {
            $period->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }
    }

    private function remainingAmount(TechnicalServiceSettlement $settlement): float
    {
        return $this->summary->remainingAmount($settlement);
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
