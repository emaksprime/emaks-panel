<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceSettlement;
use InvalidArgumentException;

class TechnicalServiceSettlementCalculator
{
    /**
     * @return array{
     *     technician_earning_total: float,
     *     customer_collection_amount: float,
     *     customer_direct_to_technician_amount: float,
     *     customer_direct_assumed_paid_amount: float,
     *     company_payable_amount: float,
     *     company_remaining_amount: float,
     *     overpay_warning_amount: float,
     *     overpay_requires_review: bool,
     *     status: string
     * }
     */
    public function calculate(
        int|float|string $technicianEarningTotal,
        int|float|string $customerDirectToTechnicianAmount,
        int|float|string $customerCollectionAmount = 0,
    ): array {
        $earningTotal = $this->money($technicianEarningTotal, 'technician earning total');
        $directAmount = $this->money($customerDirectToTechnicianAmount, 'customer direct-to-technician amount');
        $customerCollection = $this->money($customerCollectionAmount, 'customer collection amount');

        $companyPayable = round(max($earningTotal - $directAmount, 0), 2);
        $overpayWarning = round(max($directAmount - $earningTotal, 0), 2);
        $requiresReview = $overpayWarning > 0;

        return [
            'technician_earning_total' => $earningTotal,
            'customer_collection_amount' => $customerCollection,
            'customer_direct_to_technician_amount' => $directAmount,
            'customer_direct_assumed_paid_amount' => $directAmount,
            'company_payable_amount' => $companyPayable,
            'company_remaining_amount' => $companyPayable,
            'overpay_warning_amount' => $overpayWarning,
            'overpay_requires_review' => $requiresReview,
            'status' => $requiresReview
                ? TechnicalServiceSettlement::STATUS_ADMIN_REVIEW
                : TechnicalServiceSettlement::STATUS_CALCULATED,
        ];
    }

    private function money(int|float|string $value, string $label): float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Invalid {$label}.");
        }

        $amount = round((float) $value, 2);

        if ($amount < 0) {
            throw new InvalidArgumentException("{$label} cannot be negative.");
        }

        return $amount;
    }
}
