<?php

namespace App\Services\TechnicalService;

use App\Models\B2B\B2BPartnerTechnician;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceMountPayment;
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
        private readonly TechnicalServicePaymentStatusResolver $paymentStatusResolver,
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
        $paymentStatus = $this->paymentStatusResolver->resolve($request);
        $customerCollectionAmount = $this->paidCustomerCollectionAmount($request);
        $mountPaymentCollected = $customerCollectionAmount > 0 || (bool) ($paymentStatus['is_paid'] ?? false);
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
                'payment_status_source' => $paymentStatus['source'] ?? null,
                'payment_status_label' => $paymentStatus['stage_label'] ?? null,
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

    private function paidCustomerCollectionAmount(TechnicalServiceRequest $request): float
    {
        $query = TechnicalServiceMountPayment::query()
            ->where('status', TechnicalServiceMountPayment::STATUS_PAID)
            ->where(function ($query) use ($request): void {
                $query->where('technical_service_request_id', $request->id);

                if ($request->mount_session_id !== null) {
                    $query->orWhere('technical_service_mount_session_id', $request->mount_session_id);
                }
            });

        return round((float) $query
            ->get()
            ->filter(function (TechnicalServiceMountPayment $payment) use ($request): bool {
                $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

                if (($payload['source'] ?? null) === 'operation_customer_charge') {
                    return false;
                }

                return (int) ($payment->technical_service_request_id ?? 0) === (int) $request->id
                    || (int) ($payload['technical_service_request_id'] ?? 0) === (int) $request->id
                    || (
                        $request->mount_session_id !== null
                        && (int) ($payment->technical_service_mount_session_id ?? 0) === (int) $request->mount_session_id
                        && $payment->technical_service_request_id === null
                        && in_array(($payload['source'] ?? null), ['public_mount_payment', 'public_form_payment'], true)
                    );
            })
            ->sum(fn (TechnicalServiceMountPayment $payment): float => (float) $payment->amount), 2);
    }
}
