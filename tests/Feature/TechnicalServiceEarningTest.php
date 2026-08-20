<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningPayment;
use App\Models\TechnicalServiceEarningsPeriod;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceSettlement;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServiceAssignmentSettlementService;
use App\Services\TechnicalService\TechnicalServiceEarningService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TechnicalServiceEarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_period_includes_only_completed_requests_by_installation_date(): void
    {
        CarbonImmutable::setTestNow('2026-05-10 12:00:00');
        $technician = $this->technician(['name' => 'Usta A', 'city' => 'Adana']);
        $included = $this->request([
            'mrn' => 'MRN-INCLUDED',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'completed_at' => '2026-04-30 10:00:00',
            'installation_completed_at' => '2026-05-02 10:00:00',
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 120,
            'travel_round_trip_km' => 42,
            'travel_billable_km' => 12,
        ]);
        $this->request([
            'mrn' => 'MRN-NOT-COMPLETED',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Randevulu',
            'completed_at' => null,
            'installation_completed_at' => '2026-05-03 10:00:00',
        ]);
        $this->request([
            'mrn' => 'MRN-OTHER-MONTH',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-01 10:00:00',
            'installation_completed_at' => '2026-04-29 10:00:00',
        ]);

        $period = app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);

        $this->assertSame(1, $period->earnings()->count());
        $earning = $period->earnings()->firstOrFail();
        $this->assertSame(1, $earning->items()->count());
        $this->assertDatabaseHas('technical_service_earning_items', [
            'technical_service_request_id' => $included->id,
            'mrn' => 'MRN-INCLUDED',
            'labor_amount' => '3000.00',
            'travel_fee_amount' => '120.00',
            'line_total' => '3120.00',
        ]);
        $this->assertSame('3120.00', $earning->fresh()->grand_total);
    }

    public function test_completed_at_is_used_as_fallback_and_empty_labor_amount_adds_note(): void
    {
        $technician = $this->technician(['name' => 'Usta B']);
        $this->request([
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => null,
            'technician_payment_amount' => null,
            'travel_fee_amount' => 75,
        ]);

        $period = app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);
        $item = $period->earnings()->firstOrFail()->items()->firstOrFail();

        $this->assertSame('2026-05-04', $item->job_date->toDateString());
        $this->assertSame('0.00', $item->labor_amount);
        $this->assertSame('75.00', $item->line_total);
        $this->assertSame('usta hizmet bedeli boş', $item->note);
    }

    public function test_recalculate_draft_period_does_not_duplicate_items(): void
    {
        $this->request([
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => null,
            'technician_payment_amount' => 3000,
        ]);

        $service = app(TechnicalServiceEarningService::class);
        $service->calculatePeriod(2026, 5);
        $service->calculatePeriod(2026, 5);

        $this->assertSame(1, TechnicalServiceEarning::query()->count());
        $this->assertDatabaseCount('technical_service_earning_items', 1);
        $this->assertDatabaseCount('technical_service_settlements', 1);
    }

    public function test_paid_or_locked_period_cannot_be_recalculated(): void
    {
        TechnicalServiceEarningsPeriod::query()->create([
            'year' => 2026,
            'month' => 5,
            'status' => 'paid',
        ]);

        $this->expectException(ValidationException::class);

        app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);
    }

    public function test_period_with_paid_earning_cannot_be_recalculated(): void
    {
        $this->request([
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => null,
            'technician_payment_amount' => 3000,
        ]);
        $service = app(TechnicalServiceEarningService::class);
        $period = $service->calculatePeriod(2026, 5);
        $earning = $period->earnings()->firstOrFail();
        $earning->update(['status' => 'Ödendi', 'paid_at' => now()]);

        $this->expectException(ValidationException::class);

        $service->calculatePeriod(2026, 5);
    }

    public function test_mark_paid_requires_amount_and_whatsapp_endpoint_work(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = $this->technician(['name' => 'Usta WhatsApp']);
        $request = $this->request([
            'mrn' => 'MRN-WA',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => '2026-05-04 11:00:00',
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 100,
        ]);
        $period = app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);
        $earning = $period->earnings()->firstOrFail();
        $item = $earning->items()->firstOrFail();
        $this->settlement($request, $technician, [
            'technical_service_earning_item_id' => $item->id,
            'company_payable_amount' => 3100,
            'company_remaining_amount' => 3100,
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid")
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid", [
                'amount' => 3100,
                'reference' => 'DEKONT-WA',
            ])
            ->assertOk()
            ->assertJsonPath('earning.status', 'Ödendi')
            ->assertJsonPath('earning.company_paid_amount', 3100)
            ->assertJsonPath('earning.company_remaining_amount', 0);

        $this->assertSame('paid', $period->fresh()->status);
        $this->assertDatabaseHas('technical_service_earning_payments', [
            'technical_service_request_id' => $request->id,
            'payment_type' => TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT,
            'amount' => '3100.00',
            'reference' => 'DEKONT-WA',
        ]);

        $this->actingAs($user)
            ->getJson("/api/technical-service/earnings/{$earning->id}/whatsapp-text")
            ->assertOk()
            ->assertJsonPath('text', fn (string $text) => str_contains($text, 'Merhaba Usta WhatsApp,')
                && str_contains($text, 'MRN-WA')
                && str_contains($text, 'Toplam hakediş: 3.100,00 TL'));
    }

    public function test_list_endpoint_returns_summary(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $this->request([
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => null,
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 100,
        ]);
        app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);

        $this->actingAs($user)
            ->getJson('/api/technical-service/earnings?year=2026&month=5')
            ->assertOk()
            ->assertJsonPath('summary.job_count', 1)
            ->assertJsonPath('summary.grand_total', 3100)
            ->assertJsonPath('summary.company_payable_total', 0);
    }

    public function test_earnings_row_without_settlement_shows_mutabakat_olusmadi(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $request = $this->request([
            'mrn' => 'MRN-MUTABAKAT-YOK',
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => '2026-05-04 11:00:00',
            'technician_payment_amount' => 1000,
        ]);
        $period = TechnicalServiceEarningsPeriod::query()->create([
            'year' => 2026,
            'month' => 5,
            'status' => 'draft',
        ]);
        $earning = TechnicalServiceEarning::query()->create([
            'period_id' => $period->id,
            'technician_name_snapshot' => 'Mutabakat Yok Usta',
            'status' => 'Kontrol Bekliyor',
            'job_count' => 1,
            'grand_total' => 1000,
        ]);
        $earning->items()->create([
            'technical_service_request_id' => $request->id,
            'mrn' => $request->mrn,
            'job_date' => '2026-05-04 11:00:00',
            'labor_amount' => 1000,
            'travel_round_trip_km' => 0,
            'travel_billable_km' => 0,
            'travel_fee_amount' => 0,
            'line_total' => 1000,
        ]);

        $this->actingAs($user)
            ->getJson('/api/technical-service/earnings?year=2026&month=5')
            ->assertOk()
            ->assertJsonPath('items.0.settlement_status_label', 'Mutabakat oluşmadı')
            ->assertJsonPath('items.0.reconciliation_missing', true)
            ->assertJsonPath('items.0.payment_action_label', 'Mutabakat oluştur')
            ->assertJsonPath('items.0.settlement_disabled_reason', 'Ödeme için önce hakediş mutabakatı oluşturulmalı.')
            ->assertJsonPath('summary.reconciliation_missing_count', 1);
    }

    public function test_earnings_row_with_payable_settlement_shows_odenecek(): void
    {
        [$user, $earning] = $this->earningWithSettlement(companyPayable: 1000);

        $this->actingAs($user)
            ->getJson('/api/technical-service/earnings?year=2026&month=5')
            ->assertOk()
            ->assertJsonPath('items.0.settlement_status_label', 'Ödenecek')
            ->assertJsonPath('items.0.payment_action_label', 'Ödeme Yap')
            ->assertJsonPath('items.0.can_pay_company_payout', true)
            ->assertJsonPath('summary.payable_count', 1)
            ->assertJsonPath('summary.company_payable_total', 1000);

        $this->assertSame('Kontrol Bekliyor', $earning->fresh()->status);
    }

    public function test_earnings_row_with_zero_company_payable_shows_sirket_odemesi_yok(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $this->request([
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => '2026-05-04 11:00:00',
            'technician_payment_amount' => 1000,
        ]);

        app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);

        $this->actingAs($user)
            ->getJson('/api/technical-service/earnings?year=2026&month=5')
            ->assertOk()
            ->assertJsonPath('items.0.settlement_status_label', 'Şirket ödemesi yok')
            ->assertJsonPath('items.0.payment_action_label', 'Ödenecek tutar yok')
            ->assertJsonPath('items.0.company_payable_amount', 0)
            ->assertJsonPath('summary.no_company_payable_count', 1);
    }

    public function test_earnings_row_with_admin_review_shows_admin_incelemesi(): void
    {
        [$user] = $this->earningWithSettlement(
            companyPayable: 0,
            settlementOverrides: [
                'status' => TechnicalServiceSettlement::STATUS_ADMIN_REVIEW,
                'technician_earning_total' => 1500,
                'customer_direct_to_technician_amount' => 2000,
                'customer_direct_assumed_paid_amount' => 2000,
                'company_payable_amount' => 0,
                'company_remaining_amount' => 0,
                'overpay_warning_amount' => 500,
                'overpay_requires_review' => true,
                'review_reason' => 'Müşteriye bildirilen tutar usta hakedişinden yüksek.',
            ],
        );

        $this->actingAs($user)
            ->getJson('/api/technical-service/earnings?year=2026&month=5')
            ->assertOk()
            ->assertJsonPath('items.0.settlement_status_label', 'Admin incelemesi')
            ->assertJsonPath('items.0.payment_action_label', 'İncele')
            ->assertJsonPath('items.0.settlement_disabled_reason', 'Admin incelemesi tamamlanmadan ödeme yapılamaz.')
            ->assertJsonPath('summary.admin_review_count', 1);
    }

    public function test_admin_can_approve_overpay_difference_without_creating_company_payout(): void
    {
        [$user, $earning, $request, $settlement] = $this->earningWithSettlement(
            companyPayable: 0,
            settlementOverrides: [
                'status' => TechnicalServiceSettlement::STATUS_ADMIN_REVIEW,
                'technician_earning_total' => 1500,
                'labor_earning_amount' => 1500,
                'customer_direct_to_technician_amount' => 2000,
                'customer_direct_assumed_paid_amount' => 2000,
                'company_payable_amount' => 0,
                'company_remaining_amount' => 0,
                'overpay_warning_amount' => 500,
                'overpay_requires_review' => true,
                'review_reason' => 'Müşteriye bildirilen tutar usta hakedişinden yüksek.',
            ],
        );

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/review", [
                'settlement_id' => $settlement->id,
                'decision' => 'approve_difference',
                'reason' => 'Müşteri tutarı operasyon tarafından onaylandı.',
            ])
            ->assertOk()
            ->assertJsonPath('earning.settlement_status_label', 'Şirket ödemesi yok')
            ->assertJsonPath('earning.payment_action_label', 'Ödenecek tutar yok');

        $settlement->refresh();
        $this->assertSame(TechnicalServiceSettlement::STATUS_FINALIZED, $settlement->status);
        $this->assertFalse((bool) $settlement->overpay_requires_review);
        $this->assertSame('500.00', $settlement->overpay_warning_amount);
        $this->assertSame('0.00', $settlement->company_payable_amount);
        $this->assertDatabaseCount('technical_service_earning_payments', 0);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'settlement_review_approved',
            'author_user_id' => $user->id,
        ]);
    }

    public function test_admin_can_correct_customer_direct_amount_and_recalculate_payable(): void
    {
        [$user, $earning, $request, $settlement] = $this->earningWithSettlement(
            companyPayable: 0,
            settlementOverrides: [
                'status' => TechnicalServiceSettlement::STATUS_ADMIN_REVIEW,
                'technician_earning_total' => 1500,
                'labor_earning_amount' => 1500,
                'customer_direct_to_technician_amount' => 2000,
                'customer_direct_assumed_paid_amount' => 2000,
                'company_payable_amount' => 0,
                'company_remaining_amount' => 0,
                'overpay_warning_amount' => 500,
                'overpay_requires_review' => true,
                'review_reason' => 'Müşteriye bildirilen tutar usta hakedişinden yüksek.',
            ],
        );

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/review", [
                'settlement_id' => $settlement->id,
                'decision' => 'correct_direct_amount',
                'customer_direct_to_technician_amount' => 1000,
                'reason' => 'Müşteri mesajındaki tutar düzeltildi.',
            ])
            ->assertOk()
            ->assertJsonPath('earning.settlement_status_label', 'Ödenecek')
            ->assertJsonPath('earning.company_payable_amount', 500)
            ->assertJsonPath('earning.company_remaining_amount', 500)
            ->assertJsonPath('earning.can_pay_company_payout', true);

        $settlement->refresh();
        $this->assertSame(TechnicalServiceSettlement::STATUS_FINALIZED, $settlement->status);
        $this->assertFalse((bool) $settlement->overpay_requires_review);
        $this->assertSame('1000.00', $settlement->customer_direct_to_technician_amount);
        $this->assertSame('500.00', $settlement->company_payable_amount);
        $this->assertSame('500.00', $settlement->company_remaining_amount);
        $this->assertSame('0.00', $settlement->overpay_warning_amount);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'settlement_review_corrected',
            'author_user_id' => $user->id,
        ]);
    }

    public function test_correcting_direct_above_earning_keeps_admin_review(): void
    {
        [$user, $earning, $request, $settlement] = $this->earningWithSettlement(
            companyPayable: 0,
            settlementOverrides: [
                'status' => TechnicalServiceSettlement::STATUS_ADMIN_REVIEW,
                'technician_earning_total' => 1500,
                'labor_earning_amount' => 1500,
                'customer_direct_to_technician_amount' => 2000,
                'customer_direct_assumed_paid_amount' => 2000,
                'company_payable_amount' => 0,
                'company_remaining_amount' => 0,
                'overpay_warning_amount' => 500,
                'overpay_requires_review' => true,
                'review_reason' => 'Müşteriye bildirilen tutar usta hakedişinden yüksek.',
            ],
        );

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/review", [
                'settlement_id' => $settlement->id,
                'decision' => 'correct_direct_amount',
                'customer_direct_to_technician_amount' => 1800,
                'reason' => 'Tutar düzeltildi ama fark sürüyor.',
            ])
            ->assertOk()
            ->assertJsonPath('earning.settlement_status_label', 'Admin incelemesi')
            ->assertJsonPath('earning.payment_action_label', 'İncele');

        $settlement->refresh();
        $this->assertSame(TechnicalServiceSettlement::STATUS_ADMIN_REVIEW, $settlement->status);
        $this->assertTrue((bool) $settlement->overpay_requires_review);
        $this->assertSame('300.00', $settlement->overpay_warning_amount);
        $this->assertSame('0.00', $settlement->company_payable_amount);
    }

    public function test_settlement_review_reason_is_required_and_negative_direct_amount_is_rejected(): void
    {
        [$user, $earning, $request, $settlement] = $this->earningWithSettlement(
            companyPayable: 0,
            settlementOverrides: [
                'status' => TechnicalServiceSettlement::STATUS_ADMIN_REVIEW,
                'technician_earning_total' => 1500,
                'customer_direct_to_technician_amount' => 2000,
                'company_payable_amount' => 0,
                'overpay_warning_amount' => 500,
                'overpay_requires_review' => true,
            ],
        );

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/review", [
                'settlement_id' => $settlement->id,
                'decision' => 'approve_difference',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/review", [
                'settlement_id' => $settlement->id,
                'decision' => 'correct_direct_amount',
                'customer_direct_to_technician_amount' => -1,
                'reason' => 'Negatif tutar deneniyor.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_direct_to_technician_amount');
    }

    public function test_admin_can_mark_excluded_settlement_with_reason_and_payment_remains_blocked(): void
    {
        [$user, $earning, $request, $settlement] = $this->earningWithSettlement(
            companyPayable: 0,
            settlementOverrides: [
                'status' => TechnicalServiceSettlement::STATUS_ADMIN_REVIEW,
                'technician_earning_total' => 1500,
                'customer_direct_to_technician_amount' => 2000,
                'company_payable_amount' => 0,
                'overpay_warning_amount' => 500,
                'overpay_requires_review' => true,
            ],
        );

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/review", [
                'settlement_id' => $settlement->id,
                'decision' => 'exclude',
                'reason' => 'Bu iş hakedişe dahil edilmeyecek.',
            ])
            ->assertOk()
            ->assertJsonPath('earning.settlement_status_label', 'Hakedişe dahil değil')
            ->assertJsonPath('earning.can_pay_company_payout', false);

        $settlement->refresh();
        $this->assertSame(TechnicalServiceSettlement::STATUS_EXCLUDED, $settlement->status);
        $this->assertSame('0.00', $settlement->company_payable_amount);
        $this->assertSame('0.00', $settlement->company_remaining_amount);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'settlement_review_excluded',
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid", ['amount' => 100])
            ->assertUnprocessable();
    }

    public function test_request_detail_payload_shows_resolved_review_summary(): void
    {
        [$user, $earning, $request, $settlement] = $this->earningWithSettlement(
            companyPayable: 0,
            settlementOverrides: [
                'status' => TechnicalServiceSettlement::STATUS_ADMIN_REVIEW,
                'technician_earning_total' => 1500,
                'customer_direct_to_technician_amount' => 2000,
                'company_payable_amount' => 0,
                'overpay_warning_amount' => 500,
                'overpay_requires_review' => true,
            ],
        );

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/review", [
                'settlement_id' => $settlement->id,
                'decision' => 'approve_difference',
                'reason' => 'Fark incelendi ve onaylandı.',
            ])
            ->assertOk();

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(['settlement']), true);

        $this->assertSame('approve_difference', $payload['settlement']['review_decision']['decision']);
        $this->assertSame('Farkı onayla', $payload['settlement']['review_decision']['decision_label']);
        $this->assertSame('Fark incelendi ve onaylandı.', $payload['settlement']['review_decision']['reason']);
        $this->assertSame($user->id, $payload['settlement']['review_decision']['reviewed_by']);
    }

    public function test_admin_review_frontend_sources_include_review_modal_and_request_detail_summary(): void
    {
        $earningsSource = file_get_contents(resource_path('js/pages/panel/technical-service-earnings.tsx'));
        $detailSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertStringContainsString('Hakediş mutabakatı incelemesi', $earningsSource);
        $this->assertStringContainsString('Farkı onayla', $earningsSource);
        $this->assertStringContainsString('Tutarları düzelt', $earningsSource);
        $this->assertStringContainsString('Hakedişe dahil değil', $earningsSource);
        $this->assertStringContainsString('Usta hakedişi revizyonu ayrı akıştan yapılır.', $earningsSource);
        $this->assertStringContainsString('selectedFinancialResultLabel', $detailSource);
        $this->assertStringContainsString('financialBlockedReason', $detailSource);
        $this->assertStringContainsString("settlement_review_approved: 'Hakediş mutabakatı onaylandı'", $detailSource);
    }

    public function test_hakedişe_dahil_degil_admin_review_decision_is_visible_in_frontend_sources(): void
    {
        $earningsSource = file_get_contents(resource_path('js/pages/panel/technical-service-earnings.tsx'));

        $this->assertStringContainsString('Hakedişe dahil değil', $earningsSource);
        $this->assertStringContainsString('Bu iş için şirket ödemesi kapatılır; ödeme geçmişi silinmez.', $earningsSource);
    }

    public function test_recalculate_creates_missing_settlements_for_period(): void
    {
        $technician = $this->technician(['name' => 'Mutabakat Usta']);
        $request = $this->request([
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => '2026-05-04 11:00:00',
            'technician_payment_amount' => 1000,
        ]);

        $service = app(TechnicalServiceEarningService::class);
        $period = $service->calculatePeriod(2026, 5);
        $item = $period->earnings()->firstOrFail()->items()->firstOrFail();

        $this->assertDatabaseHas('technical_service_settlements', [
            'technical_service_request_id' => $request->id,
            'technical_service_earning_item_id' => $item->id,
            'settlement_source' => 'completion_hook',
        ]);

        $service->calculatePeriod(2026, 5);

        $this->assertDatabaseCount('technical_service_settlements', 1);
        $this->assertDatabaseHas('technical_service_settlements', [
            'technical_service_request_id' => $request->id,
        ]);
    }

    public function test_recalculate_blocks_period_with_existing_company_payout_rows(): void
    {
        [$user, $earning] = $this->earningWithSettlement(companyPayable: 1000);

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid", ['amount' => 400])
            ->assertOk();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Ödenmiş hakediş içeren dönem yeniden hesaplanamaz.');

        app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);
    }

    public function test_earning_payment_creates_company_payout_row_and_sets_kismi_partial_paid(): void
    {
        [$user, $earning, $request] = $this->earningWithSettlement(companyPayable: 1000);

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid", [
                'amount' => 400,
                'reason' => 'Kısmi ödeme',
                'reference' => 'DEKONT-PARTIAL',
            ])
            ->assertOk()
            ->assertJsonPath('earning.status', 'Kısmi ödendi')
            ->assertJsonPath('earning.company_paid_amount', 400)
            ->assertJsonPath('earning.company_remaining_amount', 600)
            ->assertJsonPath('summary.company_remaining_amount', 600);

        $this->assertDatabaseHas('technical_service_earning_payments', [
            'technical_service_request_id' => $request->id,
            'amount' => '400.00',
            'status' => TechnicalServiceEarningPayment::STATUS_APPLIED,
            'reason' => 'Kısmi ödeme',
            'reference' => 'DEKONT-PARTIAL',
        ]);
        $this->assertDatabaseHas('technical_service_settlements', [
            'technical_service_request_id' => $request->id,
            'company_paid_amount' => '400.00',
            'company_remaining_amount' => '600.00',
            'status' => TechnicalServiceSettlement::STATUS_PARTIAL_PAID,
        ]);
        $this->assertDatabaseCount('technical_service_message_dispatches', 0);
        $this->assertDatabaseCount('technical_service_mount_payments', 0);
    }

    public function test_earning_payment_sets_paid_when_amount_equals_remaining(): void
    {
        [$user, $earning, $request] = $this->earningWithSettlement(companyPayable: 1000);

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid", [
                'amount' => 1000,
            ])
            ->assertOk()
            ->assertJsonPath('earning.status', 'Ödendi')
            ->assertJsonPath('earning.company_paid_amount', 1000)
            ->assertJsonPath('earning.company_remaining_amount', 0);

        $this->assertDatabaseHas('technical_service_settlements', [
            'technical_service_request_id' => $request->id,
            'company_paid_amount' => '1000.00',
            'company_remaining_amount' => '0.00',
            'status' => TechnicalServiceSettlement::STATUS_PAID,
        ]);
    }

    public function test_earning_payment_rejects_zero_and_amount_above_remaining(): void
    {
        [$user, $earning] = $this->earningWithSettlement(companyPayable: 1000);

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid", ['amount' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid", ['amount' => 1000.01])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_earning_payment_rejects_excluded_and_admin_review_settlements(): void
    {
        [$user, $excluded] = $this->earningWithSettlement(
            companyPayable: 1000,
            settlementOverrides: ['status' => TechnicalServiceSettlement::STATUS_EXCLUDED],
        );

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$excluded->id}/mark-paid", ['amount' => 100])
            ->assertUnprocessable();

        [$adminUser, $adminReview] = $this->earningWithSettlement(
            companyPayable: 1000,
            requestOverrides: ['mrn' => 'MRN-ADMIN-REVIEW'],
            settlementOverrides: [
                'status' => TechnicalServiceSettlement::STATUS_ADMIN_REVIEW,
                'overpay_requires_review' => true,
                'review_reason' => 'Müşteriye bildirilen tutar usta hakedişinden yüksek.',
            ],
        );

        $this->actingAs($adminUser)
            ->postJson("/api/technical-service/earnings/{$adminReview->id}/mark-paid", ['amount' => 100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settlement');
    }

    public function test_earning_payment_does_not_count_customer_direct_or_customer_links_as_company_paid(): void
    {
        [$user, $earning, $request] = $this->earningWithSettlement(companyPayable: 500, settlementOverrides: [
            'customer_direct_assumed_paid_amount' => 1000,
        ]);
        $session = $this->mountSession($request);
        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'PENDING-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 300,
            'currency' => 'TRY',
            'payment_url' => 'https://dashboard.emaksprime.com.tr/mount-payment/pending',
        ]);
        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'CANCELLED-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_CANCELLED,
            'amount' => 200,
            'currency' => 'TRY',
            'payment_url' => 'https://dashboard.emaksprime.com.tr/mount-payment/cancelled',
        ]);

        $this->actingAs($user)
            ->getJson('/api/technical-service/earnings?year=2026&month=5')
            ->assertOk()
            ->assertJsonPath('items.0.company_paid_amount', 0)
            ->assertJsonPath('items.0.company_remaining_amount', 500);

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid", ['amount' => 250])
            ->assertOk()
            ->assertJsonPath('earning.company_paid_amount', 250)
            ->assertJsonPath('earning.company_remaining_amount', 250);
    }

    public function test_earning_payment_duplicate_submit_is_guarded_by_remaining_balance(): void
    {
        [$user, $earning] = $this->earningWithSettlement(companyPayable: 500);

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid", ['amount' => 500])
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid", ['amount' => 500])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('technical_service_earning_payments', 1);
    }

    public function test_company_payment_line_is_paid_through_existing_earning_payout_process(): void
    {
        [$user, $earning, $request, $settlement] = $this->earningWithSettlement(
            companyPayable: 1500,
            settlementOverrides: [
                'labor_earning_amount' => 500,
                'technician_earning_total' => 1500,
                'company_payable_amount' => 1500,
                'company_remaining_amount' => 1500,
                'metadata' => [
                    'base_company_payable_amount' => 500,
                    'company_payment_amount' => 1000,
                ],
            ],
        );
        $line = new TechnicalServiceEarningPayment;
        $line->forceFill([
            'technical_service_settlement_id' => $settlement->id,
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $settlement->technical_service_technician_id,
            'currency' => 'TRY',
            'payment_type' => TechnicalServiceAssignmentSettlementService::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT,
            'amount' => 1000,
            'status' => TechnicalServiceEarningPayment::STATUS_PENDING,
            'metadata' => ['payment_id' => 9901, 'payment_purpose' => 'service_payment'],
        ])->save();

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid", [
                'amount' => 1500,
                'reference' => 'DEKONT-COMPANY-LINE',
            ])
            ->assertOk()
            ->assertJsonPath('earning.company_paid_amount', 1500)
            ->assertJsonPath('earning.company_remaining_amount', 0);

        $this->assertDatabaseHas('technical_service_earning_payments', [
            'payment_type' => TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT,
            'source_company_payment_line_id' => null,
            'amount' => '500.00',
            'status' => TechnicalServiceEarningPayment::STATUS_APPLIED,
        ]);
        $this->assertDatabaseHas('technical_service_earning_payments', [
            'payment_type' => TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT,
            'source_company_payment_line_id' => $line->id,
            'amount' => '1000.00',
            'status' => TechnicalServiceEarningPayment::STATUS_APPLIED,
        ]);
        $this->assertSame(TechnicalServiceEarningPayment::STATUS_APPLIED, $line->fresh()->status);
        $this->assertNotNull($line->fresh()->paid_at);
    }

    public function test_srv_and_parent_earnings_are_summed_from_latest_assignment_offers(): void
    {
        $technician = $this->technician(['name' => 'SRV Toplam Usta']);
        $parent = $this->request([
            'mrn' => 'MRN-SRV-EARNING-PARENT',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => '2026-05-04 11:00:00',
            'technician_payment_amount' => 9999,
            'travel_fee_amount' => 999,
        ]);
        $child = $this->request([
            'mrn' => 'SRV-SRV-EARNING-001',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-SRV-EARNING-001',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Montaj',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => '2026-05-06 11:00:00',
            'installation_completed_at' => '2026-05-06 11:00:00',
            'technician_payment_amount' => 8888,
            'travel_fee_amount' => 888,
        ]);
        $this->assignmentOffer($parent, [
            'labor_amount' => 2000,
            'route_fee_amount' => 100,
            'total_amount' => 2100,
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
        ]);
        $this->assignmentOffer($child, [
            'labor_amount' => 1500,
            'route_fee_amount' => 300,
            'total_amount' => 1800,
            'status' => TechnicalServiceAssignmentOffer::STATUS_REVISED,
        ]);

        $period = app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);
        $earning = $period->earnings()->firstOrFail()->fresh();
        $items = $earning->items()->orderBy('mrn')->get();

        $this->assertSame(2, $earning->job_count);
        $this->assertSame('3500.00', $earning->labor_total);
        $this->assertSame('400.00', $earning->travel_fee_total);
        $this->assertSame('3900.00', $earning->grand_total);
        $this->assertDatabaseHas('technical_service_earning_items', [
            'technical_service_request_id' => $parent->id,
            'labor_amount' => '2000.00',
            'travel_fee_amount' => '100.00',
            'line_total' => '2100.00',
        ]);
        $this->assertDatabaseHas('technical_service_earning_items', [
            'technical_service_request_id' => $child->id,
            'service_type' => 'Servis',
            'labor_amount' => '1500.00',
            'travel_fee_amount' => '300.00',
            'line_total' => '1800.00',
        ]);
        $this->assertEqualsCanonicalizing([$parent->mrn, $child->mrn], $items->pluck('mrn')->all());
    }

    public function test_cancelled_srv_is_dropped_from_recalculated_earnings(): void
    {
        $technician = $this->technician(['name' => 'SRV Iptal Usta']);
        $parent = $this->request([
            'mrn' => 'MRN-SRV-EARNING-CANCEL-PARENT',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => '2026-05-04 11:00:00',
        ]);
        $child = $this->request([
            'mrn' => 'SRV-SRV-EARNING-CANCEL-001',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_code' => 'SRV-SRV-EARNING-CANCEL-001',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'İptal',
            'workflow_status' => 'İptal',
            'completed_at' => '2026-05-06 11:00:00',
            'installation_completed_at' => '2026-05-06 11:00:00',
            'cancelled_at' => '2026-05-06 12:00:00',
        ]);
        $this->assignmentOffer($parent, [
            'labor_amount' => 2000,
            'route_fee_amount' => 100,
            'total_amount' => 2100,
        ]);
        $this->assignmentOffer($child, [
            'labor_amount' => 1500,
            'route_fee_amount' => 300,
            'total_amount' => 1800,
        ]);

        $period = app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);
        $earning = $period->earnings()->firstOrFail()->fresh();

        $this->assertSame(1, $earning->job_count);
        $this->assertSame('2100.00', $earning->grand_total);
        $this->assertDatabaseMissing('technical_service_earning_items', [
            'technical_service_request_id' => $child->id,
        ]);
    }

    public function test_cancel_review_request_is_earning_excluded_from_active_technician_earnings(): void
    {
        $technician = $this->technician(['name' => 'Cancel Review Usta']);
        $included = $this->request([
            'mrn' => 'MRN-CANCEL-REVIEW-INCLUDED',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => '2026-05-04 11:00:00',
        ]);
        $excluded = $this->request([
            'mrn' => 'MRN-CANCEL-REVIEW-EXCLUDED',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => '2026-05-06 11:00:00',
            'installation_completed_at' => '2026-05-06 11:00:00',
            'pending_reason' => TechnicalServiceWorkflowService::CANCELLATION_REVIEW_PENDING_REASON,
            'operation_control_payload' => [
                TechnicalServiceWorkflowService::CANCELLATION_REVIEW_KEY => [
                    'status' => 'pending',
                    'reason' => 'Müşteri iptal istedi',
                ],
            ],
        ]);
        $this->assignmentOffer($included, [
            'labor_amount' => 2000,
            'route_fee_amount' => 100,
            'total_amount' => 2100,
        ]);
        $this->assignmentOffer($excluded, [
            'labor_amount' => 1500,
            'route_fee_amount' => 300,
            'total_amount' => 1800,
        ]);

        $period = app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);
        $earning = $period->earnings()->firstOrFail()->fresh();

        $this->assertSame(1, $earning->job_count);
        $this->assertSame('2100.00', $earning->grand_total);
        $this->assertDatabaseMissing('technical_service_earning_items', [
            'technical_service_request_id' => $excluded->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function technician(array $overrides = []): TechnicalServiceTechnician
    {
        return TechnicalServiceTechnician::query()->create(array_merge([
            'name' => 'Test Usta',
            'first_name' => 'Test',
            'last_name' => 'Usta',
            'phone' => '905300000000',
            'city' => 'Adana',
            'active' => true,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function request(array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-TEST-'.uniqid(),
            'customer_name' => 'Test Müşteri',
            'customer_phone' => '905300000001',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'Test adres',
            'product_name' => 'Test Ürün',
            'product_model' => 'M1',
            'serial_number' => 'SN-TEST',
            'service_type' => 'Montaj',
            'status' => 'Tamamlandı',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'completed_at' => '2026-05-02 10:00:00',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assignmentOffer(TechnicalServiceRequest $request, array $overrides = []): TechnicalServiceAssignmentOffer
    {
        return TechnicalServiceAssignmentOffer::query()->create(array_merge([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $request->technical_service_technician_id,
            'labor_amount' => 1000,
            'route_fee_amount' => 0,
            'total_amount' => 1000,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $requestOverrides
     * @param  array<string, mixed>  $settlementOverrides
     * @return array{0: User, 1: TechnicalServiceEarning, 2: TechnicalServiceRequest, 3: TechnicalServiceSettlement}
     */
    private function earningWithSettlement(float $companyPayable, array $requestOverrides = [], array $settlementOverrides = []): array
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = $this->technician(['name' => 'REL3B4 Usta']);
        $request = $this->request(array_merge([
            'mrn' => 'MRN-REL3B4-'.uniqid(),
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => '2026-05-04 11:00:00',
            'technician_payment_amount' => $companyPayable,
            'travel_fee_amount' => 0,
        ], $requestOverrides));
        $period = app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);
        $earning = $period->earnings()->where('technical_service_technician_id', $technician->id)->firstOrFail();
        $item = $earning->items()->where('technical_service_request_id', $request->id)->firstOrFail();
        $settlement = $this->settlement($request, $technician, array_merge([
            'technical_service_earning_item_id' => $item->id,
            'technician_earning_total' => $companyPayable,
            'labor_earning_amount' => $companyPayable,
            'route_earning_amount' => 0,
            'customer_direct_to_technician_amount' => 0,
            'customer_direct_assumed_paid_amount' => 0,
            'company_payable_amount' => $companyPayable,
            'company_paid_amount' => 0,
            'company_remaining_amount' => $companyPayable,
            'status' => TechnicalServiceSettlement::STATUS_FINALIZED,
        ], $settlementOverrides));

        return [$user, $earning, $request, $settlement];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function settlement(TechnicalServiceRequest $request, TechnicalServiceTechnician $technician, array $overrides = []): TechnicalServiceSettlement
    {
        return TechnicalServiceSettlement::query()->updateOrCreate([
            'technical_service_request_id' => $request->id,
        ], array_merge([
            'technical_service_request_id' => $request->id,
            'root_request_id' => $request->parent_request_id ?: $request->id,
            'request_code' => $request->service_code,
            'root_mrn' => $request->root_mrn ?: $request->mrn,
            'technical_service_technician_id' => $technician->id,
            'currency' => 'TRY',
            'labor_earning_amount' => 1000,
            'route_earning_amount' => 0,
            'technician_earning_total' => 1000,
            'customer_direct_to_technician_amount' => 0,
            'customer_direct_assumed_paid_amount' => 0,
            'company_payable_amount' => 1000,
            'company_paid_amount' => 0,
            'company_remaining_amount' => 1000,
            'status' => TechnicalServiceSettlement::STATUS_FINALIZED,
            'settlement_source' => 'test',
            'completed_at' => $request->completed_at,
            'finalized_at' => $request->completed_at,
        ], $overrides));
    }

    private function mountSession(TechnicalServiceRequest $request): TechnicalServiceMountSession
    {
        $link = TechnicalServiceQrLink::query()->create([
            'token_hash' => TechnicalServiceQrLink::hashToken('REL3B4-'.$request->id),
            'public_token' => 'REL3B4-'.$request->id,
            'serial_number' => $request->serial_number,
            'product_name' => $request->product_name,
            'product_model' => $request->product_model,
            'link_type' => TechnicalServiceQrLink::TYPE_MANUAL_TEST,
            'status' => TechnicalServiceQrLink::STATUS_ACTIVE,
            'scan_count' => 0,
        ]);

        return TechnicalServiceMountSession::query()->create([
            'technical_service_qr_link_id' => $link->id,
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken('REL3B4-SESSION-'.$request->id),
            'serial_number' => $request->serial_number,
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_SINGLE_PRODUCT,
            'decision_status' => TechnicalServiceMountSession::DECISION_READY,
            'context_payload' => [
                'technical_service_request_id' => $request->id,
            ],
        ]);
    }
}
