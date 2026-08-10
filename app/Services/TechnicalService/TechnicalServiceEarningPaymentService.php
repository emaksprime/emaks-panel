<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningPayment;
use App\Models\TechnicalServiceEarningsPeriod;
use App\Models\TechnicalServiceSettlement;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TechnicalServiceEarningPaymentService
{
    public function __construct(
        private readonly TechnicalServiceEarningSettlementSummaryService $summary,
    ) {}

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

                $settlementPayments = $this->recordSettlementPayout(
                    $settlement,
                    $allocation,
                    $earning,
                    $amount,
                    $index,
                    $batchReference,
                    $reason,
                    $user,
                );

                $settlement->technical_service_earning_item_id = $settlement->technical_service_earning_item_id
                    ?: ($itemIdsByRequest[(int) $settlement->technical_service_request_id] ?? null);
                $this->refreshSettlementPaymentTotals($settlement);
                array_push($payments, ...$settlementPayments);
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

    /**
     * @return array<int, TechnicalServiceEarningPayment>
     */
    private function recordSettlementPayout(
        TechnicalServiceSettlement $settlement,
        float $amount,
        TechnicalServiceEarning $earning,
        float $requestedAmount,
        int $settlementIndex,
        string $batchReference,
        ?string $reason,
        ?Authenticatable $user,
    ): array {
        $settlement->load('earningPayments');
        $companyLines = $settlement->earningPayments
            ->filter(fn (TechnicalServiceEarningPayment $payment): bool => $payment->payment_type === TechnicalServiceAssignmentSettlementService::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT
                && in_array($payment->status, [
                    TechnicalServiceEarningPayment::STATUS_PENDING,
                    TechnicalServiceEarningPayment::STATUS_APPLIED,
                ], true))
            ->sortBy('id')
            ->values();
        $companyLineTotal = $this->money($companyLines->sum(fn (TechnicalServiceEarningPayment $line): float => $this->money($line->amount)));
        $metadata = is_array($settlement->metadata) ? $settlement->metadata : [];
        $basePayable = isset($metadata['base_company_payable_amount'])
            ? $this->money($metadata['base_company_payable_amount'])
            : max($this->money($settlement->company_payable_amount) - $companyLineTotal, 0.0);
        $basePaid = $this->money($settlement->earningPayments
            ->filter(fn (TechnicalServiceEarningPayment $payment): bool => $payment->payment_type === TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT
                && $payment->status === TechnicalServiceEarningPayment::STATUS_APPLIED
                && $payment->source_company_payment_line_id === null)
            ->sum(fn (TechnicalServiceEarningPayment $payment): float => $this->money($payment->amount)));
        $baseRemaining = max($this->money($basePayable - $basePaid), 0.0);
        $left = $this->money($amount);
        $payments = [];
        $bucketIndex = 0;

        if ($baseRemaining > 0 && $left > 0) {
            $allocation = $this->money(min($baseRemaining, $left));
            $payments[] = $this->createPayoutRow(
                $settlement,
                $allocation,
                null,
                $earning,
                $requestedAmount,
                $settlementIndex,
                ++$bucketIndex,
                $batchReference,
                $reason,
                $user,
            );
            $left = $this->money($left - $allocation);
        }

        foreach ($companyLines as $line) {
            if ($left <= 0) {
                break;
            }

            $linkedPaid = $this->money(TechnicalServiceEarningPayment::query()
                ->where('source_company_payment_line_id', $line->id)
                ->where('payment_type', TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT)
                ->where('status', TechnicalServiceEarningPayment::STATUS_APPLIED)
                ->sum('amount'));
            $lineRemaining = max($this->money($line->amount) - $linkedPaid, 0.0);
            if ($lineRemaining <= 0) {
                if ($line->status !== TechnicalServiceEarningPayment::STATUS_APPLIED) {
                    $line->forceFill([
                        'status' => TechnicalServiceEarningPayment::STATUS_APPLIED,
                        'paid_at' => $line->paid_at ?? now(),
                    ])->save();
                }

                continue;
            }

            $allocation = $this->money(min($lineRemaining, $left));
            $payments[] = $this->createPayoutRow(
                $settlement,
                $allocation,
                $line,
                $earning,
                $requestedAmount,
                $settlementIndex,
                ++$bucketIndex,
                $batchReference,
                $reason,
                $user,
            );
            $linkedPaid = $this->money($linkedPaid + $allocation);
            if ($linkedPaid >= $this->money($line->amount)) {
                $line->forceFill([
                    'status' => TechnicalServiceEarningPayment::STATUS_APPLIED,
                    'paid_at' => now(),
                    'metadata' => array_merge(is_array($line->metadata) ? $line->metadata : [], [
                        'status' => 'paid',
                        'paid_at' => now()->toISOString(),
                        'payout_reference' => $batchReference,
                    ]),
                ])->save();
            }
            $left = $this->money($left - $allocation);
        }

        if ($left > 0.0) {
            throw ValidationException::withMessages([
                'amount' => 'Hakediş ödeme tutarı canonical settlement satırlarına dağıtılamadı.',
            ]);
        }

        return array_map(
            fn (TechnicalServiceEarningPayment $payment): TechnicalServiceEarningPayment => $payment->fresh(),
            $payments,
        );
    }

    private function createPayoutRow(
        TechnicalServiceSettlement $settlement,
        float $amount,
        ?TechnicalServiceEarningPayment $companyLine,
        TechnicalServiceEarning $earning,
        float $requestedAmount,
        int $settlementIndex,
        int $bucketIndex,
        string $batchReference,
        ?string $reason,
        ?Authenticatable $user,
    ): TechnicalServiceEarningPayment {
        $payment = new TechnicalServiceEarningPayment;
        $payment->forceFill([
            'technical_service_settlement_id' => $settlement->id,
            'technical_service_request_id' => $settlement->technical_service_request_id,
            'technical_service_assignment_offer_id' => $companyLine?->technical_service_assignment_offer_id,
            'technical_service_technician_id' => $settlement->technical_service_technician_id,
            'b2b_partner_id' => $settlement->b2b_partner_id,
            'currency' => $settlement->currency ?: 'TRY',
            'payment_type' => TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT,
            'source_company_payment_line_id' => $companyLine?->id,
            'amount' => $amount,
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
                'requested_amount' => $requestedAmount,
                'allocation_index' => $settlementIndex + 1,
                'bucket_index' => $bucketIndex,
                'bucket' => $companyLine instanceof TechnicalServiceEarningPayment
                    ? 'company_payment'
                    : 'base_earning',
                'source_company_payment_line_id' => $companyLine?->id,
                'source_customer_payment_id' => data_get($companyLine?->metadata, 'payment_id'),
            ],
        ])->save();

        return $payment;
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
        $period = TechnicalServiceEarningsPeriod::query()->find($periodId);

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
