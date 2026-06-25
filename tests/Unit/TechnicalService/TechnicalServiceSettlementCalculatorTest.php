<?php

namespace Tests\Unit\TechnicalService;

use App\Models\TechnicalServiceSettlement;
use App\Services\TechnicalService\TechnicalServiceSettlementCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TechnicalServiceSettlementCalculatorTest extends TestCase
{
    public function test_settlement_calculates_company_payable_when_direct_amount_lower(): void
    {
        $result = $this->calculator()->calculate(1500, 1000);

        $this->assertSame(1500.0, $result['technician_earning_total']);
        $this->assertSame(1000.0, $result['customer_direct_to_technician_amount']);
        $this->assertSame(1000.0, $result['customer_direct_assumed_paid_amount']);
        $this->assertSame(500.0, $result['company_payable_amount']);
        $this->assertSame(500.0, $result['company_remaining_amount']);
        $this->assertSame(0.0, $result['overpay_warning_amount']);
        $this->assertFalse($result['overpay_requires_review']);
        $this->assertSame(TechnicalServiceSettlement::STATUS_CALCULATED, $result['status']);
    }

    public function test_settlement_sets_zero_company_payable_when_direct_amount_higher(): void
    {
        $result = $this->calculator()->calculate(1500, 2000);

        $this->assertSame(0.0, $result['company_payable_amount']);
        $this->assertSame(0.0, $result['company_remaining_amount']);
    }

    public function test_settlement_sets_overpay_warning_when_direct_amount_higher(): void
    {
        $result = $this->calculator()->calculate(1500, 2000);

        $this->assertSame(500.0, $result['overpay_warning_amount']);
        $this->assertTrue($result['overpay_requires_review']);
        $this->assertSame(TechnicalServiceSettlement::STATUS_ADMIN_REVIEW, $result['status']);
    }

    public function test_settlement_does_not_write_off_difference_under_10_try(): void
    {
        $result = $this->calculator()->calculate(1500, 1495);

        $this->assertSame(5.0, $result['company_payable_amount']);
        $this->assertSame(5.0, $result['company_remaining_amount']);
        $this->assertFalse($result['overpay_requires_review']);
    }

    public function test_settlement_preserves_exact_small_difference(): void
    {
        $result = $this->calculator()->calculate('1500.35', '1499.70');

        $this->assertSame(0.65, $result['company_payable_amount']);
        $this->assertSame(0.65, $result['company_remaining_amount']);
    }

    public function test_settlement_rejects_negative_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator()->calculate(1500, -1);
    }

    public function test_settlement_initial_remaining_equals_company_payable(): void
    {
        $result = $this->calculator()->calculate(2400, 1800);

        $this->assertSame($result['company_payable_amount'], $result['company_remaining_amount']);
    }

    private function calculator(): TechnicalServiceSettlementCalculator
    {
        return new TechnicalServiceSettlementCalculator;
    }
}
