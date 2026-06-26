<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceSettlement;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServiceSettlementCompletionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicalServiceSettlementCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-06-26 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_completion_settlement_applies_customer_direct_assumed_paid_when_company_payment_not_collected(): void
    {
        [$request, $technician, $offer] = $this->completionFixture();
        $this->settlement($request, $technician, $offer, customerDirectAmount: 1000);

        $this->complete($request)
            ->assertOk()
            ->assertJsonPath('request.settlement.status', TechnicalServiceSettlement::STATUS_FINALIZED)
            ->assertJsonPath('request.settlement.customer_direct_to_technician_amount', 1000)
            ->assertJsonPath('request.settlement.customer_direct_assumed_paid_amount', 1000)
            ->assertJsonPath('request.settlement.customer_collection_amount', 0)
            ->assertJsonPath('request.settlement.company_payable_amount', 500)
            ->assertJsonPath('request.settlement.company_remaining_amount', 500);

        $this->assertDatabaseCount('technical_service_earning_payments', 0);
        $this->assertDatabaseCount('technical_service_message_dispatches', 0);
    }

    public function test_completion_settlement_keeps_customer_direct_assumed_zero_when_company_payment_collected(): void
    {
        [$request, $technician, $offer] = $this->completionFixture();
        $this->settlement($request, $technician, $offer, customerDirectAmount: 0);
        $this->mountPayment($request, TechnicalServiceMountPayment::STATUS_PAID, 1500);

        $this->complete($request)
            ->assertOk()
            ->assertJsonPath('request.settlement.status', TechnicalServiceSettlement::STATUS_FINALIZED)
            ->assertJsonPath('request.settlement.customer_direct_to_technician_amount', 0)
            ->assertJsonPath('request.settlement.customer_direct_assumed_paid_amount', 0)
            ->assertJsonPath('request.settlement.customer_collection_amount', 1500)
            ->assertJsonPath('request.settlement.company_payable_amount', 1500)
            ->assertJsonPath('request.settlement.company_remaining_amount', 1500);
    }

    public function test_completion_settlement_customer_collection_excludes_pending_and_cancelled_links(): void
    {
        [$request, $technician, $offer] = $this->completionFixture();
        $this->settlement($request, $technician, $offer, customerDirectAmount: 1000);
        $this->mountPayment($request, TechnicalServiceMountPayment::STATUS_PENDING, 500);
        $this->mountPayment($request, TechnicalServiceMountPayment::STATUS_CANCELLED, 300);

        $this->complete($request)
            ->assertOk()
            ->assertJsonPath('request.settlement.customer_collection_amount', 0)
            ->assertJsonPath('request.settlement.customer_direct_assumed_paid_amount', 1000)
            ->assertJsonPath('request.settlement.company_payable_amount', 500);
    }

    public function test_completion_settlement_overpay_sets_admin_review_without_negative_payable(): void
    {
        [$request, $technician, $offer] = $this->completionFixture();
        $this->settlement($request, $technician, $offer, customerDirectAmount: 2000);

        $this->complete($request)
            ->assertOk()
            ->assertJsonPath('request.settlement.status', TechnicalServiceSettlement::STATUS_ADMIN_REVIEW)
            ->assertJsonPath('request.settlement.customer_direct_assumed_paid_amount', 2000)
            ->assertJsonPath('request.settlement.company_payable_amount', 0)
            ->assertJsonPath('request.settlement.company_remaining_amount', 0)
            ->assertJsonPath('request.settlement.overpay_warning_amount', 500)
            ->assertJsonPath('request.settlement.overpay_requires_review', true)
            ->assertJsonPath('request.settlement.review_reason', 'Müşteriye bildirilen tutar usta hakedişinden yüksek.');
    }

    public function test_completion_settlement_preserves_exact_difference_under_10_try(): void
    {
        [$request, $technician, $offer] = $this->completionFixture();
        $this->settlement($request, $technician, $offer, customerDirectAmount: 1495);

        $this->complete($request)
            ->assertOk()
            ->assertJsonPath('request.settlement.status', TechnicalServiceSettlement::STATUS_FINALIZED)
            ->assertJsonPath('request.settlement.company_payable_amount', 5)
            ->assertJsonPath('request.settlement.company_remaining_amount', 5)
            ->assertJsonPath('request.settlement.overpay_warning_amount', 0);
    }

    public function test_settlement_completion_is_idempotent_for_same_request(): void
    {
        [$request, $technician, $offer] = $this->completionFixture();
        $this->settlement($request, $technician, $offer, customerDirectAmount: 1000);

        $this->complete($request)->assertOk();
        app(TechnicalServiceSettlementCompletionService::class)->apply($request->fresh(), $this->adminUser());
        app(TechnicalServiceSettlementCompletionService::class)->apply($request->fresh(), $this->adminUser());

        $this->assertSame(1, TechnicalServiceSettlement::query()->where('technical_service_request_id', $request->id)->count());
        $this->assertDatabaseCount('technical_service_earning_payments', 0);
        $this->assertDatabaseCount('technical_service_message_dispatches', 0);

        $settlement = TechnicalServiceSettlement::query()->where('technical_service_request_id', $request->id)->firstOrFail();
        $this->assertSame('1000.00', $settlement->customer_direct_assumed_paid_amount);
        $this->assertSame('500.00', $settlement->company_remaining_amount);
    }

    public function test_completion_settlement_creates_settlement_if_missing_from_assignment_data(): void
    {
        [$request] = $this->completionFixture();

        $this->complete($request)
            ->assertOk()
            ->assertJsonPath('request.settlement.status', TechnicalServiceSettlement::STATUS_FINALIZED)
            ->assertJsonPath('request.settlement.technician_earning_total', 1500)
            ->assertJsonPath('request.settlement.customer_direct_to_technician_amount', 1500)
            ->assertJsonPath('request.settlement.customer_direct_assumed_paid_amount', 1500)
            ->assertJsonPath('request.settlement.company_payable_amount', 0);

        $this->assertSame(1, TechnicalServiceSettlement::query()->where('technical_service_request_id', $request->id)->count());
    }

    public function test_completion_settlement_excludes_cancelled_request_from_payable(): void
    {
        [$request, $technician, $offer] = $this->completionFixture([
            'status' => 'İptal',
            'workflow_status' => 'İptal',
            'field_status' => 'iptal',
        ]);
        $this->settlement($request, $technician, $offer, customerDirectAmount: 1000);

        app(TechnicalServiceSettlementCompletionService::class)->apply($request->fresh(), $this->adminUser());

        $settlement = TechnicalServiceSettlement::query()->where('technical_service_request_id', $request->id)->firstOrFail();
        $this->assertSame(TechnicalServiceSettlement::STATUS_EXCLUDED, $settlement->status);
        $this->assertSame('0.00', $settlement->customer_direct_assumed_paid_amount);
        $this->assertSame('0.00', $settlement->company_payable_amount);
        $this->assertSame('0.00', $settlement->company_remaining_amount);
        $this->assertNotNull($settlement->excluded_at);
    }

    /**
     * @param array<string, mixed> $requestOverrides
     * @return array{0: TechnicalServiceRequest, 1: TechnicalServiceTechnician, 2: TechnicalServiceAssignmentOffer}
     */
    private function completionFixture(array $requestOverrides = []): array
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'REL3B3 Usta',
            'first_name' => 'REL3B3',
            'last_name' => 'Usta',
            'phone' => '905300000200',
            'city' => 'Adana',
            'active' => true,
        ]);

        $request = TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-REL3B3-'.uniqid(),
            'root_mrn' => 'MRN-REL3B3-ROOT',
            'service_code' => 'SRV-REL3B3-001',
            'customer_name' => 'REL3B3 Müşteri',
            'customer_phone' => '905300000201',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'REL3B3 adres',
            'product_name' => 'Test Ürün',
            'product_model' => 'M1',
            'serial_number' => 'SN-REL3B3-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Randevulu',
            'workflow_status' => 'Sahada',
            'field_status' => 'sahada',
            'checklist_status' => 'tamamlandı',
            'checklist_payload' => $this->completedChecklist(),
            'before_photo_count' => 3,
            'after_photo_count' => 3,
            'general_photo_count' => 1,
            'photo_status' => 'tamamlandı',
            'document_status' => 'tamamlandı',
            'customer_closure_approval_status' => 'onaylandı',
            'customer_closure_approved_at' => CarbonImmutable::now()->subMinutes(15),
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ], $requestOverrides));

        $offer = TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 1000,
            'route_fee_amount' => 500,
            'total_amount' => 1500,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'note' => 'REL3B3 hakediş',
            'sent_by' => $this->adminUser()->id,
            'sent_at' => now(),
            'metadata' => [],
        ]);

        return [$request, $technician, $offer];
    }

    private function settlement(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        TechnicalServiceAssignmentOffer $offer,
        float $customerDirectAmount,
    ): TechnicalServiceSettlement {
        $companyPayable = max(1500 - $customerDirectAmount, 0);
        $overpay = max($customerDirectAmount - 1500, 0);

        return TechnicalServiceSettlement::query()->create([
            'technical_service_request_id' => $request->id,
            'root_request_id' => $request->parent_request_id ?: $request->id,
            'request_code' => $request->service_code,
            'root_mrn' => $request->root_mrn,
            'technical_service_technician_id' => $technician->id,
            'technical_service_assignment_offer_id' => $offer->id,
            'currency' => 'TRY',
            'labor_earning_amount' => 1000,
            'route_earning_amount' => 500,
            'technician_earning_total' => 1500,
            'customer_collection_amount' => 0,
            'customer_direct_to_technician_amount' => $customerDirectAmount,
            'customer_direct_assumed_paid_amount' => 0,
            'company_payable_amount' => $companyPayable,
            'company_paid_amount' => 0,
            'company_remaining_amount' => $companyPayable,
            'overpay_warning_amount' => $overpay,
            'overpay_requires_review' => $overpay > 0,
            'status' => $overpay > 0 ? TechnicalServiceSettlement::STATUS_ADMIN_REVIEW : TechnicalServiceSettlement::STATUS_CALCULATED,
            'settlement_source' => 'assignment_popup',
            'metadata' => ['source' => 'assignment_popup'],
        ]);
    }

    private function mountPayment(TechnicalServiceRequest $request, string $status, float $amount): TechnicalServiceMountPayment
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => $request->serial_number,
            'product_name' => $request->product_name,
            'product_model' => $request->product_model,
        ]);

        $session = TechnicalServiceMountSession::query()->create([
            'technical_service_qr_link_id' => $link->id,
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken('rel3b3-'.$status.'-'.$request->id),
            'serial_number' => $request->serial_number,
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => $status,
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
            'context_payload' => [],
        ]);

        $request->forceFill([
            'qr_link_id' => $link->id,
            'mount_session_id' => $session->id,
            'mount_payment_status' => $status,
            'mount_payment_provider' => 'fake',
            'mount_payment_reference' => 'REL3B3-'.$status,
            'mount_payment_paid_at' => $status === TechnicalServiceMountPayment::STATUS_PAID ? now() : null,
        ])->save();

        return TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'REL3B3-'.$status.'-'.uniqid(),
            'status' => $status,
            'amount' => $amount,
            'currency' => 'TRY',
            'payment_url' => 'https://dashboard.emaksprime.com.tr/mount-payment/rel3b3-'.$status,
            'paid_at' => $status === TechnicalServiceMountPayment::STATUS_PAID ? now() : null,
            'raw_payload' => [
                'source' => 'public_form_payment',
                'technical_service_request_id' => $request->id,
                'request_code' => $request->mrn,
                'serial_number' => $request->serial_number,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
            ],
        ]);
    }

    private function complete(TechnicalServiceRequest $request)
    {
        return $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field/complete", [
                'note' => 'REL3B3 tamamlandı.',
            ]);
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    /**
     * @return array<string, bool>
     */
    private function completedChecklist(): array
    {
        return [
            'Ürün seri numarası kontrol edildi' => true,
            'Kapı / montaj yeri kontrol edildi' => true,
            'Montaj uygunluğu kontrol edildi' => true,
            'Ürün çalışır durumda test edildi' => true,
            'Müşteriye kullanım bilgisi verildi' => true,
            'Garanti / servis formu bilgisi kontrol edildi' => true,
        ];
    }
}
