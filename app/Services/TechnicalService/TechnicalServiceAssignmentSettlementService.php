<?php

namespace App\Services\TechnicalService;

use App\Models\B2B\B2BPartnerTechnician;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRouteQuote;
use App\Models\TechnicalServiceSettlement;
use App\Models\TechnicalServiceTechnician;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TechnicalServiceAssignmentSettlementService
{
    public function __construct(
        private readonly TechnicalServiceSettlementCalculator $calculator,
        private readonly TechnicalServicePaymentOwnershipService $paymentOwnership,
    ) {
    }

    public function persistForAssignment(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        TechnicalServiceAssignmentOffer $offer,
        ?TechnicalServiceRouteQuote $routeQuote,
        float $laborAmount,
        float $routeFeeAmount,
        ?float $customerDirectAmount,
        ?Authenticatable $user = null,
    ): TechnicalServiceSettlement {
        $ownership = $this->paymentOwnership->summary($request);
        $customerCollectionAmount = round((float) ($ownership['company_collected_amount'] ?? 0), 2);
        $mountPaymentCollected = $customerCollectionAmount > 0;
        $technicianEarningTotal = round($laborAmount + $routeFeeAmount, 2);
        $directAmount = $customerDirectAmount;

        if ($mountPaymentCollected) {
            if (($directAmount ?? 0.0) > 0) {
                throw ValidationException::withMessages([
                    'assignment_offer.customer_direct_to_technician_amount' => 'Müşteriden montaj ödemesi alındığı için ustaya doğrudan ödeme tutarı 0 olmalıdır.',
                ]);
            }

            $directAmount = 0.0;
        } elseif ($directAmount === null) {
            $directAmount = $technicianEarningTotal;
        }

        try {
            $calculation = $this->calculator->calculate(
                $technicianEarningTotal,
                $directAmount,
                $mountPaymentCollected ? $customerCollectionAmount : 0,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'assignment_offer.customer_direct_to_technician_amount' => $exception->getMessage(),
            ]);
        }

        $settlement = TechnicalServiceSettlement::query()
            ->firstOrNew(['technical_service_request_id' => $request->id]);

        if (! $settlement->exists) {
            $settlement->created_by = $user?->getAuthIdentifier();
        }

        $partnerId = $this->partnerIdForTechnician($technician);
        $overpayRequiresReview = (bool) $calculation['overpay_requires_review'];

        $settlement->fill([
            'root_request_id' => $request->parent_request_id ?: $request->id,
            'request_code' => $request->service_code,
            'root_mrn' => $request->root_mrn ?: $request->mrn,
            'technical_service_technician_id' => $technician->id,
            'b2b_partner_id' => $partnerId,
            'technical_service_assignment_offer_id' => $offer->id,
            'currency' => strtoupper(substr((string) ($offer->currency ?: 'TRY'), 0, 8)) ?: 'TRY',
            'labor_earning_amount' => round($laborAmount, 2),
            'route_earning_amount' => round($routeFeeAmount, 2),
            'technician_earning_total' => $calculation['technician_earning_total'],
            'customer_collection_amount' => $calculation['customer_collection_amount'],
            'customer_direct_to_technician_amount' => $calculation['customer_direct_to_technician_amount'],
            'customer_direct_assumed_paid_amount' => 0,
            'company_payable_amount' => $calculation['company_payable_amount'],
            'company_paid_amount' => $settlement->exists ? (float) $settlement->company_paid_amount : 0,
            'company_remaining_amount' => $calculation['company_remaining_amount'],
            'overpay_warning_amount' => $calculation['overpay_warning_amount'],
            'status' => $calculation['status'],
            'settlement_source' => 'assignment_popup',
            'overpay_requires_review' => $overpayRequiresReview,
            'review_reason' => $overpayRequiresReview
                ? 'Müşteriye bildirilen tutar usta hakedişinden yüksek.'
                : null,
            'updated_by' => $user?->getAuthIdentifier(),
            'metadata' => [
                'source' => 'assignment_popup',
                'mount_payment_collected' => $mountPaymentCollected,
                'payer_state_key' => $ownership['payer_state_key'] ?? null,
                'company_collected_source' => $ownership['company_collected_source'] ?? null,
                'route_quote_id' => $routeQuote?->id,
                'assignment_offer_id' => $offer->id,
            ],
        ]);

        $settlement->save();

        return $settlement->refresh();
    }

    private function partnerIdForTechnician(TechnicalServiceTechnician $technician): ?int
    {
        $partnerId = B2BPartnerTechnician::query()
            ->where('technical_service_technician_id', $technician->id)
            ->where('active', true)
            ->whereIn('relationship_type', ['owner', 'field_technician'])
            ->value('partner_id');

        return $partnerId !== null ? (int) $partnerId : null;
    }

}
