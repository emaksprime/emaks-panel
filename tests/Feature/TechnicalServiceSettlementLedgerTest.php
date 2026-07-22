<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\TechnicalServiceEarningPayment;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceSettlement;
use App\Models\TechnicalServiceTechnician;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TechnicalServiceSettlementLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_settlement_tables_are_created(): void
    {
        $this->assertTrue(Schema::hasTable('technical_service_settlements'));
        $this->assertTrue(Schema::hasTable('technical_service_earning_payments'));
    }

    public function test_settlement_table_has_required_amount_columns(): void
    {
        foreach ([
            'labor_earning_amount',
            'route_earning_amount',
            'technician_earning_total',
            'customer_collection_amount',
            'customer_direct_to_technician_amount',
            'customer_direct_assumed_paid_amount',
            'company_payable_amount',
            'company_paid_amount',
            'company_remaining_amount',
            'overpay_warning_amount',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('technical_service_settlements', $column), $column);
        }
    }

    public function test_settlement_table_has_request_and_technician_indexes(): void
    {
        $indexes = $this->sqliteIndexNames('technical_service_settlements');

        $this->assertContains('ts_settlements_request_unique', $indexes);
        $this->assertContains('ts_settlements_technician_idx', $indexes);
    }

    public function test_earning_payments_table_has_required_columns(): void
    {
        foreach ([
            'technical_service_settlement_id',
            'technical_service_request_id',
            'technical_service_technician_id',
            'b2b_partner_id',
            'payment_type',
            'amount',
            'status',
            'paid_at',
            'paid_by',
            'paid_by_name',
            'reason',
            'reference',
            'metadata',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('technical_service_earning_payments', $column), $column);
        }
    }

    public function test_earning_payments_links_to_settlement(): void
    {
        $settlement = $this->settlement();

        $payment = TechnicalServiceEarningPayment::query()->create([
            'technical_service_settlement_id' => $settlement->id,
            'technical_service_request_id' => $settlement->technical_service_request_id,
            'technical_service_technician_id' => $settlement->technical_service_technician_id,
            'b2b_partner_id' => $settlement->b2b_partner_id,
            'payment_type' => TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT,
            'amount' => 500,
            'status' => TechnicalServiceEarningPayment::STATUS_APPLIED,
            'paid_at' => now(),
        ]);

        $this->assertSame($settlement->id, $payment->settlement->id);
        $this->assertSame(1, $settlement->earningPayments()->count());
    }

    public function test_settlement_has_many_earning_payments(): void
    {
        $this->assertInstanceOf(HasMany::class, (new TechnicalServiceSettlement)->earningPayments());
    }

    public function test_earning_payment_belongs_to_settlement(): void
    {
        $this->assertInstanceOf(BelongsTo::class, (new TechnicalServiceEarningPayment)->settlement());
    }

    public function test_settlement_metadata_casts_to_array(): void
    {
        $settlement = $this->settlement([
            'metadata' => ['source' => 'unit-test'],
        ]);

        $this->assertSame(['source' => 'unit-test'], $settlement->fresh()->metadata);
    }

    public function test_settlement_amounts_are_decimal_casted(): void
    {
        $settlement = $this->settlement([
            'technician_earning_total' => 1500,
            'customer_direct_to_technician_amount' => 1000,
            'company_payable_amount' => 500,
        ])->fresh();

        $this->assertSame('1500.00', $settlement->technician_earning_total);
        $this->assertSame('1000.00', $settlement->customer_direct_to_technician_amount);
        $this->assertSame('500.00', $settlement->company_payable_amount);
    }

    public function test_partial_payout_status_foundation_is_available_without_payment_rows(): void
    {
        $settlement = $this->settlement([
            'status' => TechnicalServiceSettlement::STATUS_PARTIAL_PAID,
            'company_payable_amount' => 500,
            'company_paid_amount' => 100,
            'company_remaining_amount' => 400,
        ])->fresh();

        $this->assertSame(TechnicalServiceSettlement::STATUS_PARTIAL_PAID, $settlement->status);
        $this->assertSame('100.00', $settlement->company_paid_amount);
        $this->assertSame('400.00', $settlement->company_remaining_amount);
        $this->assertDatabaseCount('technical_service_earning_payments', 0);
    }

    public function test_migration_does_not_backfill_existing_business_rows(): void
    {
        $this->request();

        $this->assertDatabaseCount('technical_service_requests', 1);
        $this->assertDatabaseCount('technical_service_settlements', 0);
        $this->assertDatabaseCount('technical_service_earning_payments', 0);
    }

    public function test_no_existing_requests_are_modified_by_migration(): void
    {
        $request = $this->request(['mrn' => 'MRN-REL3B1-UNCHANGED']);

        $this->assertSame('MRN-REL3B1-UNCHANGED', $request->fresh()->mrn);
        $this->assertDatabaseCount('technical_service_settlements', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function settlement(array $overrides = []): TechnicalServiceSettlement
    {
        $technician = $this->technician();
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'REL3B1-'.uniqid(),
            'display_name' => 'REL3B1 Çilingir',
            'technical_service_technician_id' => $technician->id,
            'active' => true,
        ]);
        $request = $this->request([
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);

        return TechnicalServiceSettlement::query()->create(array_merge([
            'technical_service_request_id' => $request->id,
            'request_code' => $request->service_code,
            'root_mrn' => $request->root_mrn,
            'technical_service_technician_id' => $technician->id,
            'b2b_partner_id' => $partner->id,
            'labor_earning_amount' => 1000,
            'route_earning_amount' => 500,
            'technician_earning_total' => 1500,
            'customer_direct_to_technician_amount' => 1000,
            'customer_direct_assumed_paid_amount' => 1000,
            'company_payable_amount' => 500,
            'company_remaining_amount' => 500,
            'status' => TechnicalServiceSettlement::STATUS_CALCULATED,
        ], $overrides));
    }

    private function technician(): TechnicalServiceTechnician
    {
        return TechnicalServiceTechnician::query()->create([
            'name' => 'REL3B1 Usta',
            'first_name' => 'REL3B1',
            'last_name' => 'Usta',
            'phone' => '905300000000',
            'city' => 'Adana',
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function request(array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-REL3B1-'.uniqid(),
            'root_mrn' => 'MRN-REL3B1-ROOT',
            'service_code' => 'SRV-REL3B1-001',
            'customer_name' => 'REL3B1 Müşteri',
            'customer_phone' => '905300000001',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'Test adres',
            'product_name' => 'Test Ürün',
            'product_model' => 'M1',
            'serial_number' => 'SN-REL3B1',
            'service_type' => 'Montaj',
            'status' => 'Tamamlandı',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'completed_at' => '2026-05-02 10:00:00',
        ], $overrides));
    }

    /**
     * @return array<int, string>
     */
    private function sqliteIndexNames(string $table): array
    {
        return array_map(
            static fn (object $index): string => (string) $index->name,
            DB::select("PRAGMA index_list('{$table}')"),
        );
    }
}
