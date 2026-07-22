<?php

namespace App\Services\TechnicalService;

use App\Models\B2B\B2BPartnerTechnician;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceSettlement;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

class TechnicalServiceSettlementCompletionService
{
    private const OVERPAY_REVIEW_REASON = 'Müşteriye bildirilen tutar usta hakedişinden yüksek.';

    private const MISSING_EARNING_REVIEW_REASON = 'Tamamlama sırasında hakediş verisi bulunamadı.';

    public function __construct(
        private readonly TechnicalServiceSettlementCalculator $calculator,
        private readonly TechnicalServicePaymentOwnershipService $paymentOwnership,
    ) {
    }

    public function apply(TechnicalServiceRequest $request, ?Authenticatable $user = null): TechnicalServiceSettlement
    {
        $request->loadMissing(['latestAssignmentOffer.technician', 'settlement', 'technicianRecord']);

        $settlement = TechnicalServiceSettlement::query()
            ->firstOrNew(['technical_service_request_id' => $request->id]);

        if (! $settlement->exists) {
            $settlement->created_by = $user?->getAuthIdentifier();
        }

        $paymentOwnership = $this->paymentOwnership->summary($request, $settlement->exists ? $settlement : null);
        $paidCustomerCollectionAmount = round((float) ($paymentOwnership['company_collected_amount'] ?? 0), 2);

        if ($this->isExcludedRequest($request)) {
            return $this->applyExcludedSettlement($request, $settlement, $paidCustomerCollectionAmount, $user);
        }

        $offer = $request->latestAssignmentOffer;
        $earning = $this->earningAmounts($request, $settlement, $offer);
        $companyCollectedPayment = $paidCustomerCollectionAmount > 0;
        $storedDirectAmount = $this->customerDirectAmount($settlement, $earning['technician_earning_total'], $companyCollectedPayment);
        $activeDirectAmount = $companyCollectedPayment ? 0.0 : $storedDirectAmount;
        $companyPaidAmount = round((float) ($settlement->exists ? $settlement->company_paid_amount : 0), 2);
        $calculation = $this->calculator->calculate(
            $earning['technician_earning_total'],
            $activeDirectAmount,
            $paidCustomerCollectionAmount,
        );
        $companyPayableAmount = round((float) $calculation['company_payable_amount'], 2);
        $overpayWarningAmount = round((float) $calculation['overpay_warning_amount'], 2);
        $missingEarning = ! $earning['has_source'] || $earning['technician_earning_total'] <= 0;
        $requiresReview = $overpayWarningAmount > 0 || $missingEarning;
        $status = $requiresReview
            ? TechnicalServiceSettlement::STATUS_ADMIN_REVIEW
            : TechnicalServiceSettlement::STATUS_FINALIZED;
        $reviewReason = $overpayWarningAmount > 0
            ? self::OVERPAY_REVIEW_REASON
            : ($missingEarning ? self::MISSING_EARNING_REVIEW_REASON : null);
        $completedAt = $this->completionTimestamp($request);
        $metadata = $this->mergedMetadata($settlement, [
            'source' => 'completion_hook',
            'earning_source' => $earning['source'],
            'mount_payment_collected' => $companyCollectedPayment,
            'payer_state_key' => $companyCollectedPayment
                ? ($paymentOwnership['payer_state_key'] ?? null)
                : ($activeDirectAmount > 0 ? TechnicalServicePaymentOwnershipService::STATE_CUSTOMER_PAYS_TECHNICIAN : ($paymentOwnership['payer_state_key'] ?? null)),
            'company_collected_source' => $paymentOwnership['company_collected_source'] ?? null,
            'paid_customer_collection_amount' => $paidCustomerCollectionAmount,
            'stored_customer_direct_to_technician_amount' => $storedDirectAmount,
            'active_customer_direct_to_technician_amount' => $activeDirectAmount,
            'direct_instruction_inactive' => $companyCollectedPayment && $storedDirectAmount > 0,
            'assignment_offer_id' => $offer?->id,
            'applied_at' => now()->toISOString(),
        ]);

        $settlement->fill([
            'root_request_id' => $request->parent_request_id ?: $request->id,
            'request_code' => $request->service_code,
            'root_mrn' => $request->root_mrn ?: $request->mrn,
            'technical_service_technician_id' => $earning['technician_id'],
            'b2b_partner_id' => $this->partnerIdForTechnicianId($earning['technician_id']) ?? $settlement->b2b_partner_id,
            'technical_service_assignment_offer_id' => $offer?->id ?? $settlement->technical_service_assignment_offer_id,
            'currency' => $earning['currency'],
            'labor_earning_amount' => $earning['labor_earning_amount'],
            'route_earning_amount' => $earning['route_earning_amount'],
            'technician_earning_total' => $earning['technician_earning_total'],
            'customer_collection_amount' => $paidCustomerCollectionAmount,
            'customer_direct_to_technician_amount' => $storedDirectAmount,
            'customer_direct_assumed_paid_amount' => $companyCollectedPayment ? 0 : $calculation['customer_direct_to_technician_amount'],
            'company_payable_amount' => $companyPayableAmount,
            'company_paid_amount' => $companyPaidAmount,
            'company_remaining_amount' => max(round($companyPayableAmount - $companyPaidAmount, 2), 0),
            'overpay_warning_amount' => $overpayWarningAmount,
            'status' => $status,
            'settlement_source' => 'completion_hook',
            'overpay_requires_review' => $requiresReview,
            'review_reason' => $reviewReason,
            'completed_at' => $completedAt,
            'finalized_at' => $status === TechnicalServiceSettlement::STATUS_FINALIZED
                ? ($settlement->finalized_at ?? $completedAt ?? now())
                : null,
            'excluded_at' => null,
            'updated_by' => $user?->getAuthIdentifier(),
            'metadata' => $metadata,
        ]);

        $settlement->save();

        return $settlement->refresh();
    }

    private function applyExcludedSettlement(
        TechnicalServiceRequest $request,
        TechnicalServiceSettlement $settlement,
        float $paidCustomerCollectionAmount,
        ?Authenticatable $user,
    ): TechnicalServiceSettlement {
        $offer = $request->latestAssignmentOffer;
        $earning = $this->earningAmounts($request, $settlement, $offer);
        $excludedAt = $this->completionTimestamp($request) ?? now();

        $settlement->fill([
            'root_request_id' => $request->parent_request_id ?: $request->id,
            'request_code' => $request->service_code,
            'root_mrn' => $request->root_mrn ?: $request->mrn,
            'technical_service_technician_id' => $earning['technician_id'],
            'b2b_partner_id' => $this->partnerIdForTechnicianId($earning['technician_id']) ?? $settlement->b2b_partner_id,
            'technical_service_assignment_offer_id' => $offer?->id ?? $settlement->technical_service_assignment_offer_id,
            'currency' => $earning['currency'],
            'labor_earning_amount' => $earning['labor_earning_amount'],
            'route_earning_amount' => $earning['route_earning_amount'],
            'technician_earning_total' => $earning['technician_earning_total'],
            'customer_collection_amount' => $paidCustomerCollectionAmount,
            'customer_direct_assumed_paid_amount' => 0,
            'company_payable_amount' => 0,
            'company_remaining_amount' => 0,
            'overpay_warning_amount' => 0,
            'status' => TechnicalServiceSettlement::STATUS_EXCLUDED,
            'settlement_source' => 'completion_hook',
            'overpay_requires_review' => false,
            'review_reason' => 'İptal nedeniyle hakedişe dahil değil.',
            'excluded_at' => $excludedAt,
            'completed_at' => $this->completionTimestamp($request),
            'updated_by' => $user?->getAuthIdentifier(),
            'metadata' => $this->mergedMetadata($settlement, [
                'source' => 'completion_hook',
                'earning_source' => $earning['source'],
                'excluded_from_payable' => true,
                'paid_customer_collection_amount' => $paidCustomerCollectionAmount,
                'applied_at' => now()->toISOString(),
            ]),
        ]);

        $settlement->save();

        return $settlement->refresh();
    }

    /**
     * @return array{
     *     labor_earning_amount: float,
     *     route_earning_amount: float,
     *     technician_earning_total: float,
     *     technician_id: int|null,
     *     currency: string,
     *     source: string,
     *     has_source: bool
     * }
     */
    private function earningAmounts(
        TechnicalServiceRequest $request,
        TechnicalServiceSettlement $settlement,
        ?TechnicalServiceAssignmentOffer $offer,
    ): array {
        if ($settlement->exists && (float) $settlement->technician_earning_total > 0) {
            return [
                'labor_earning_amount' => round((float) $settlement->labor_earning_amount, 2),
                'route_earning_amount' => round((float) $settlement->route_earning_amount, 2),
                'technician_earning_total' => round((float) $settlement->technician_earning_total, 2),
                'technician_id' => $settlement->technical_service_technician_id ?? $request->technical_service_technician_id,
                'currency' => $this->currency($settlement->currency),
                'source' => 'existing_settlement',
                'has_source' => true,
            ];
        }

        if ($offer instanceof TechnicalServiceAssignmentOffer) {
            $laborAmount = round((float) $offer->labor_amount, 2);
            $routeAmount = round((float) $offer->route_fee_amount, 2);
            $totalAmount = (float) $offer->total_amount > 0
                ? round((float) $offer->total_amount, 2)
                : round($laborAmount + $routeAmount, 2);

            return [
                'labor_earning_amount' => $laborAmount,
                'route_earning_amount' => $routeAmount,
                'technician_earning_total' => $totalAmount,
                'technician_id' => $offer->technical_service_technician_id ?? $request->technical_service_technician_id,
                'currency' => $this->currency($offer->currency),
                'source' => 'assignment_offer',
                'has_source' => $totalAmount > 0,
            ];
        }

        $laborAmount = round((float) ($request->technician_payment_amount ?? 0), 2);

        return [
            'labor_earning_amount' => $laborAmount,
            'route_earning_amount' => 0.0,
            'technician_earning_total' => $laborAmount,
            'technician_id' => $request->technical_service_technician_id,
            'currency' => 'TRY',
            'source' => $laborAmount > 0 ? 'request_technician_payment_amount' : 'missing',
            'has_source' => $laborAmount > 0,
        ];
    }

    private function customerDirectAmount(
        TechnicalServiceSettlement $settlement,
        float $technicianEarningTotal,
        bool $companyCollectedPayment,
    ): float {
        if ($settlement->exists) {
            return round((float) $settlement->customer_direct_to_technician_amount, 2);
        }

        return $companyCollectedPayment ? 0.0 : round($technicianEarningTotal, 2);
    }

    private function isExcludedRequest(TechnicalServiceRequest $request): bool
    {
        $status = $this->normalize((string) $request->status);
        $workflowStatus = $this->normalize((string) $request->workflow_status);

        return str_contains($status, 'iptal')
            || str_contains($workflowStatus, 'iptal')
            || str_contains($status, 'cancel')
            || str_contains($workflowStatus, 'cancel');
    }

    private function partnerIdForTechnicianId(?int $technicianId): ?int
    {
        if ($technicianId === null) {
            return null;
        }

        $partnerId = B2BPartnerTechnician::query()
            ->where('technical_service_technician_id', $technicianId)
            ->where('active', true)
            ->whereIn('relationship_type', ['owner', 'field_technician'])
            ->value('partner_id');

        return $partnerId !== null ? (int) $partnerId : null;
    }

    /**
     * @param array<string, mixed> $completionMetadata
     * @return array<string, mixed>
     */
    private function mergedMetadata(TechnicalServiceSettlement $settlement, array $completionMetadata): array
    {
        $metadata = is_array($settlement->metadata) ? $settlement->metadata : [];
        $metadata['completion_hook'] = $completionMetadata;

        return $metadata;
    }

    private function completionTimestamp(TechnicalServiceRequest $request): mixed
    {
        return $request->completed_at
            ?? $request->field_completed_at
            ?? $request->installation_completed_at
            ?? null;
    }

    private function currency(?string $currency): string
    {
        $normalized = strtoupper(substr(trim((string) $currency), 0, 8));

        return $normalized !== '' ? $normalized : 'TRY';
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->value();
    }
}
