<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\PageConfig;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceEarningPayment;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceSettlement;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\B2B\B2BPartnerPortalDataService;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\TechnicalService\TechnicalServiceAssignmentSettlementService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TechnicalServiceAssignmentSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_save_creates_settlement_row(): void
    {
        [$request, $technician] = $this->assignmentFixture();

        $this->assign($request, $technician, [
            'customer_direct_to_technician_amount' => 1000,
        ])
            ->assertOk()
            ->assertJsonPath('request.settlement.technician_earning_total', 1500)
            ->assertJsonPath('request.settlement.customer_direct_to_technician_amount', 1000)
            ->assertJsonPath('request.settlement.company_payable_amount', 500)
            ->assertJsonPath('request.settlement.overpay_warning_amount', 0)
            ->assertJsonPath('request.settlement.customer_direct_assumed_paid_amount', 0);

        $settlement = TechnicalServiceSettlement::query()->where('technical_service_request_id', $request->id)->firstOrFail();

        $this->assertSame('1500.00', $settlement->technician_earning_total);
        $this->assertSame('1000.00', $settlement->customer_direct_to_technician_amount);
        $this->assertSame('500.00', $settlement->company_payable_amount);
        $this->assertSame('500.00', $settlement->company_remaining_amount);
        $this->assertFalse($settlement->overpay_requires_review);
    }

    public function test_assignment_save_updates_existing_settlement_row(): void
    {
        [$request, $technician] = $this->assignmentFixture();

        $this->assign($request, $technician, [
            'customer_direct_to_technician_amount' => 1000,
        ])->assertOk();

        $firstSettlementId = TechnicalServiceSettlement::query()->where('technical_service_request_id', $request->id)->value('id');

        $this->assign($request, $technician, [
            'customer_direct_to_technician_amount' => 900,
        ])
            ->assertOk()
            ->assertJsonPath('request.settlement.customer_direct_to_technician_amount', 900)
            ->assertJsonPath('request.settlement.company_payable_amount', 600);

        $this->assertSame(1, TechnicalServiceSettlement::query()->where('technical_service_request_id', $request->id)->count());
        $this->assertSame($firstSettlementId, TechnicalServiceSettlement::query()->where('technical_service_request_id', $request->id)->value('id'));
    }

    public function test_assignment_save_accepts_direct_amount_higher_than_earning_with_review_warning(): void
    {
        [$request, $technician] = $this->assignmentFixture();

        $this->assign($request, $technician, [
            'customer_direct_to_technician_amount' => 2000,
        ])
            ->assertOk()
            ->assertJsonPath('request.settlement.company_payable_amount', 0)
            ->assertJsonPath('request.settlement.overpay_warning_amount', 500)
            ->assertJsonPath('request.settlement.overpay_requires_review', true)
            ->assertJsonPath('request.settlement.status', TechnicalServiceSettlement::STATUS_ADMIN_REVIEW);
    }

    public function test_assignment_save_preserves_exact_small_difference_under_10(): void
    {
        [$request, $technician] = $this->assignmentFixture();

        $this->assign($request, $technician, [
            'customer_direct_to_technician_amount' => 1495,
        ])
            ->assertOk()
            ->assertJsonPath('request.settlement.company_payable_amount', 5)
            ->assertJsonPath('request.settlement.overpay_warning_amount', 0);
    }

    public function test_assignment_save_defaults_direct_amount_to_earning_when_customer_mount_payment_not_collected(): void
    {
        [$request, $technician] = $this->assignmentFixture();

        $this->assign($request, $technician)
            ->assertOk()
            ->assertJsonPath('request.settlement.customer_direct_to_technician_amount', 1500)
            ->assertJsonPath('request.settlement.company_payable_amount', 0);
    }

    public function test_assignment_save_defaults_direct_amount_to_zero_when_mount_payment_collected(): void
    {
        [$request, $technician] = $this->assignmentFixture();

        $this->paidMountPayment($request, 'REL3B2-PAID');

        $this->assign($request, $technician)
            ->assertOk()
            ->assertJsonPath('request.settlement.customer_collection_amount', 1500)
            ->assertJsonPath('request.settlement.customer_direct_to_technician_amount', 0)
            ->assertJsonPath('request.settlement.company_payable_amount', 1500);
    }

    public function test_assignment_save_rejects_nonzero_direct_amount_when_mount_payment_already_collected(): void
    {
        [$request, $technician] = $this->assignmentFixture();

        $this->paidMountPayment($request, 'REL3B2-PAID-REJECT');

        $this->assign($request, $technician, [
            'customer_direct_to_technician_amount' => 1,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assignment_offer.customer_direct_to_technician_amount');

        $this->assertDatabaseCount('technical_service_settlements', 0);
    }

    public function test_assignment_save_rejects_negative_customer_direct_amount(): void
    {
        [$request, $technician] = $this->assignmentFixture();

        $this->assign($request, $technician, [
            'customer_direct_to_technician_amount' => -1,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assignment_offer.customer_direct_to_technician_amount');
    }

    public function test_settlement_row_links_request_assignment_technician_partner(): void
    {
        [$request, $technician, $partner] = $this->assignmentFixture();

        $this->assign($request, $technician, [
            'customer_direct_to_technician_amount' => 1000,
        ])->assertOk();

        $settlement = TechnicalServiceSettlement::query()->where('technical_service_request_id', $request->id)->firstOrFail();

        $this->assertSame($request->id, $settlement->technical_service_request_id);
        $this->assertSame($technician->id, $settlement->technical_service_technician_id);
        $this->assertSame($partner->id, $settlement->b2b_partner_id);
        $this->assertNotNull($settlement->technical_service_assignment_offer_id);
    }

    public function test_message_dispatch_assignment_save_does_not_create_earning_payment_row_or_payment_instruction(): void
    {
        Http::fake();
        [$request, $technician] = $this->assignmentFixture();

        $this->assign($request, $technician, [
            'customer_direct_to_technician_amount' => 1000,
        ])
            ->assertOk()
            ->assertJsonPath('request.assignment_offer.dispatch_status', TechnicalServiceMessageDispatch::STATUS_SUPPRESSED);

        $this->assertDatabaseCount('technical_service_earning_payments', 0);
        $this->assertSame(1, TechnicalServiceMessageDispatch::query()->where('technical_service_request_id', $request->id)->count());
        $dispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->firstOrFail();
        $this->assertSame('null_local', $dispatch->provider_key);
        $this->assertSame('system', $dispatch->channel);
        $this->assertSame('appointment_approval', $dispatch->request_payload['context']['payment_message_trigger'] ?? null);
        $this->assertFalse((bool) ($dispatch->request_payload['context']['payment_instruction_included'] ?? true));
        Http::assertNothingSent();
    }

    public function test_assignment_popup_shows_customer_direct_to_technician_amount(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));

        $this->assertStringContainsString('Müşteriye bildirilecek ustaya ödeme tutarı', $source);
        $this->assertStringContainsString('Müşteriden montaj ödemesi alınmadıysa randevu mesajında bu tutar ustaya ödenecek olarak bildirilecek.', $source);
        $this->assertStringContainsString('Kalan şirket ödemesi', $source);
        $this->assertStringContainsString('Müşteriye bildirilen tutar usta hakedişinden yüksek. Admin incelemesi gerekecek.', $source);
        $this->assertStringContainsString('customer_direct_to_technician_amount', $source);
    }

    public function test_assignment_popup_lifecycle_preserves_draft_during_refresh_and_validation(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('const assignmentDraftRequestId = useRef<string | null>(null)', $source);
        $this->assertStringContainsString('const openAssignmentDialog = () =>', $source);
        $this->assertStringContainsString('const closeAssignmentDialog = () =>', $source);
        $this->assertStringContainsString('const handleAssignDialogOpenChange = (open: boolean) =>', $source);
        $this->assertStringContainsString('if (assignLoading || assignmentConfirmDialogOpen)', $source);
        $this->assertStringContainsString('onOpenChange={handleAssignDialogOpenChange}', $source);
        $this->assertStringContainsString('onAssign={openAssignmentDialog}', $source);
        $this->assertStringContainsString('assignmentDraftRequestId.current = null', $source);
        $this->assertStringNotContainsString('onAssign={() => setAssignDialogOpen(true)}', $source);
    }

    public function test_paid_extra_service_payment_prompts_company_payment_decision(): void
    {
        [$request, , , $offer] = $this->assignedSettlementFixture();
        $payment = $this->paidChargePayment($request, 'service_payment', 1000, '2026-08-10 09:00:00');

        $payload = app(TechnicalServiceAssignmentSettlementService::class)->companyPaymentDecisionPayload($request->refresh());

        $this->assertSame(1, $payload['eligible_count']);
        $this->assertFalse($payload['visit_count_used']);
        $this->assertSame($payment->id, $payload['eligible_items'][0]['payment_id']);
        $this->assertSame('service_payment', $payload['eligible_items'][0]['payment_purpose']);
        $this->assertSame(1000.0, $payload['eligible_items'][0]['eligible_amount']);
        $this->assertSame(app(TechnicalServiceAssignmentSettlementService::class)->canonicalEarningSnapshot($offer)['revision'], $payload['earning_revision']);
    }

    public function test_financial_workspace_counts_paid_only_and_exposes_late_allocation_without_mixing_part(): void
    {
        [$request] = $this->assignedSettlementFixture();
        $servicePayment = $this->paidChargePayment($request, 'service_payment', 600, '2026-08-10 09:00:00');
        $this->paidChargePayment($request, 'part_payment', 250, '2026-08-10 09:01:00', ['part_request_id' => 77]);
        $this->paidChargePayment($request, 'service_payment', 900, '2026-08-10 09:02:00')
            ->forceFill(['status' => TechnicalServiceMountPayment::STATUS_PENDING])
            ->save();

        $workspace = app(TechnicalServiceWorkflowService::class)->financialWorkspacePayload($request->refresh());
        $summary = $workspace['finance_summary']['current_visit'];

        $this->assertSame($request->id, $workspace['finance_summary']['scope']['request_id']);
        $this->assertSame(600.0, $summary['customer_collection']['extra_amount']);
        $this->assertSame(600.0, $summary['customer_collection']['service_total_amount']);
        $this->assertSame(250.0, $summary['customer_collection']['part_amount']);
        $this->assertSame(850.0, $summary['customer_collection']['total_amount']);
        $this->assertSame('allocation_pending', $summary['result_state']);
        $this->assertFalse($summary['net_margin']['is_definitive']);
        $this->assertSame('Hesap bekliyor', $summary['net_margin']['amount_label']);
        $this->assertSame(1, $summary['company_payment_decisions']['pending_decision_count']);
        $this->assertSame(600.0, $summary['company_payment_decisions']['pending_decision_amount']);
        $this->assertSame($servicePayment->id, $summary['company_payment_decisions']['eligible_items'][0]['payment_id']);
        $this->assertSame(0.0, $summary['locksmith_payout']['technician_paid_amount']);
        $this->assertSame(1500.0, $summary['locksmith_payout']['technician_remaining_amount']);

        $this->paidChargePayment($request, 'other_collection', 100, '2026-08-10 09:03:00');
        $classified = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($request->refresh())['finance_summary']['current_visit'];

        $this->assertSame(100.0, $classified['customer_collection']['unclassified_amount']);
        $this->assertSame(600.0, $classified['customer_collection']['service_total_amount']);
        $this->assertSame('classification_pending', $classified['result_state']);
        $this->assertFalse($classified['net_margin']['is_definitive']);
    }

    public function test_paid_route_payment_prompts_only_uncovered_route_excess(): void
    {
        [$request] = $this->assignedSettlementFixture();
        $first = $this->paidChargePayment($request, 'route_fee', 800, '2026-08-10 09:00:00');
        $second = $this->paidChargePayment($request, 'route_fee', 200, '2026-08-10 09:01:00');

        $items = collect(app(TechnicalServiceAssignmentSettlementService::class)
            ->companyPaymentDecisionPayload($request->refresh())['eligible_items'])
            ->keyBy('payment_id');

        $this->assertSame(500.0, $items[$first->id]['covered_amount']);
        $this->assertSame(300.0, $items[$first->id]['eligible_amount']);
        $this->assertSame(0.0, $items[$second->id]['covered_amount']);
        $this->assertSame(200.0, $items[$second->id]['eligible_amount']);
    }

    public function test_visit_count_does_not_change_company_payment_eligibility(): void
    {
        [$request] = $this->assignedSettlementFixture();
        $this->paidChargePayment($request, 'service_payment', 425, '2026-08-10 09:00:00');
        $service = app(TechnicalServiceAssignmentSettlementService::class);
        $before = $service->companyPaymentDecisionPayload($request->refresh());

        $request->forceFill(['service_sequence' => 9])->save();
        $after = $service->companyPaymentDecisionPayload($request->refresh());

        $this->assertSame(425.0, $before['eligible_items'][0]['eligible_amount']);
        $this->assertSame($before['eligible_items'], $after['eligible_items']);
        $this->assertFalse($after['visit_count_used']);
    }

    public function test_mount_and_part_payments_never_prompt(): void
    {
        [$request] = $this->assignedSettlementFixture();
        $this->paidChargePayment($request, 'mount_extra', 1000, '2026-08-10 09:00:00');
        $this->paidChargePayment($request, 'part_payment', 500, '2026-08-10 09:01:00', ['part_request_id' => 77]);

        $payload = app(TechnicalServiceAssignmentSettlementService::class)->companyPaymentDecisionPayload($request->refresh());

        $this->assertSame(0, $payload['eligible_count']);
    }

    public function test_pending_cancelled_failed_refunded_payments_never_prompt(): void
    {
        [$request] = $this->assignedSettlementFixture();
        foreach ([TechnicalServiceMountPayment::STATUS_PENDING, TechnicalServiceMountPayment::STATUS_CANCELLED, TechnicalServiceMountPayment::STATUS_FAILED] as $index => $status) {
            $this->paidChargePayment($request, 'service_payment', 100 + $index, "2026-08-10 09:0{$index}:00")
                ->forceFill(['status' => $status])
                ->save();
        }
        $this->paidChargePayment($request, 'service_payment', 900, '2026-08-10 09:05:00', ['refund_status' => 'REFUNDED']);

        $payload = app(TechnicalServiceAssignmentSettlementService::class)->companyPaymentDecisionPayload($request->refresh());

        $this->assertSame(0, $payload['eligible_count']);
    }

    public function test_customer_pays_technician_payment_never_prompts_company_payment(): void
    {
        [$request] = $this->assignedSettlementFixture();
        $this->paidChargePayment($request, 'service_payment', 1000, '2026-08-10 09:00:00', [
            'payer_state_key' => 'customer_pays_technician',
        ]);

        $payload = app(TechnicalServiceAssignmentSettlementService::class)->companyPaymentDecisionPayload($request->refresh());

        $this->assertSame(0, $payload['eligible_count']);
    }

    public function test_pay_technician_creates_one_payable_company_settlement_line(): void
    {
        [$request, , , , $settlement, $actor] = $this->assignedSettlementFixture();
        $payment = $this->paidChargePayment($request, 'service_payment', 1000, '2026-08-10 09:00:00');
        $collectionBefore = (float) $settlement->customer_collection_amount;

        $result = $this->decideCompanyPayments($request, $actor, [$payment->id => 'pay_technician']);
        $line = TechnicalServiceEarningPayment::query()
            ->where('payment_type', TechnicalServiceAssignmentSettlementService::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT)
            ->firstOrFail();

        $this->assertSame('decided', $result['status']);
        $this->assertSame(TechnicalServiceEarningPayment::STATUS_PENDING, $line->status);
        $this->assertSame('1000.00', $line->amount);
        $this->assertNull($line->paid_at);
        $this->assertSame($collectionBefore, (float) $settlement->fresh()->customer_collection_amount);
        $this->assertSame('2500.00', $settlement->fresh()->technician_earning_total);
        $this->assertDatabaseHas('technical_service_payment_settlement_allocations', [
            'technical_service_mount_payment_id' => $payment->id,
            'decision' => 'pay_technician',
            'settlement_line_id' => $line->id,
            'status' => 'active',
        ]);
    }

    public function test_retain_company_persists_decision_without_settlement_line(): void
    {
        [$request, , , , $settlement, $actor] = $this->assignedSettlementFixture();
        $payment = $this->paidChargePayment($request, 'service_payment', 1000, '2026-08-10 09:00:00');

        $this->decideCompanyPayments($request, $actor, [$payment->id => 'retain_company']);
        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->refresh(), true);

        $this->assertDatabaseHas('technical_service_payment_settlement_allocations', [
            'technical_service_mount_payment_id' => $payment->id,
            'decision' => 'retain_company',
            'settlement_line_id' => null,
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('technical_service_earning_payments', 0);
        $this->assertSame('1500.00', $settlement->fresh()->technician_earning_total);
        $this->assertSame(1000.0, data_get($payload, 'settlement.company_retained_amount'));
        $this->assertSame(0, data_get($payload, 'settlement.company_payment_decisions.pending_decision_count'));
    }

    public function test_frontend_amount_is_not_financial_authority(): void
    {
        [$request, , , , , $actor] = $this->assignedSettlementFixture();
        $payment = $this->paidChargePayment($request, 'service_payment', 875, '2026-08-10 09:00:00');
        $service = app(TechnicalServiceAssignmentSettlementService::class);
        $payload = $service->companyPaymentDecisionPayload($request->refresh());

        $service->applyCompanyPaymentDecisions($request, [[
            'payment_id' => $payment->id,
            'decision' => 'pay_technician',
            'amount' => 999999,
            'expected_earning_revision' => $payload['earning_revision'],
        ]], $actor);

        $this->assertDatabaseHas('technical_service_earning_payments', [
            'payment_type' => TechnicalServiceAssignmentSettlementService::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT,
            'amount' => '875.00',
        ]);
    }

    public function test_one_click_submits_exact_payment_decision_once(): void
    {
        [$request, , , , , $actor] = $this->assignedSettlementFixture();
        $payment = $this->paidChargePayment($request, 'service_payment', 600, '2026-08-10 09:00:00');
        $decisionPayload = app(TechnicalServiceAssignmentSettlementService::class)
            ->companyPaymentDecisionPayload($request->refresh());
        $command = [
            'company_payment_decisions' => [[
                'payment_id' => $payment->id,
                'decision' => 'pay_technician',
                'note' => 'Payment 198 CTA kararı',
                'expected_earning_revision' => $decisionPayload['earning_revision'],
            ]],
        ];

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/company-payment-decisions", $command)
            ->assertOk()
            ->assertJsonPath('status', 'decided')
            ->assertJsonPath('request.settlement.company_payment_decisions.pending_decision_count', 0)
            ->assertJsonPath('request.settlement.company_payment_decisions.decisions.0.payment_id', $payment->id)
            ->assertJsonPath('request.settlement.company_payment_decisions.decisions.0.decision', 'pay_technician');

        $this->assertDatabaseCount('technical_service_payment_settlement_allocations', 1);
        $this->assertSame(1, TechnicalServiceEarningPayment::query()
            ->where('payment_type', TechnicalServiceAssignmentSettlementService::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT)
            ->count());

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/company-payment-decisions", $command)
            ->assertOk()
            ->assertJsonPath('status', 'duplicate_noop');

        $this->assertDatabaseCount('technical_service_payment_settlement_allocations', 1);
        $this->assertSame(1, TechnicalServiceEarningPayment::query()
            ->where('payment_type', TechnicalServiceAssignmentSettlementService::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT)
            ->count());
    }

    public function test_duplicate_decision_creates_no_duplicate_settlement(): void
    {
        [$request, , , , , $actor] = $this->assignedSettlementFixture();
        $payment = $this->paidChargePayment($request, 'service_payment', 1000, '2026-08-10 09:00:00');
        $service = app(TechnicalServiceAssignmentSettlementService::class);
        $payload = $service->companyPaymentDecisionPayload($request->refresh());
        $decision = [[
            'payment_id' => $payment->id,
            'decision' => 'pay_technician',
            'expected_earning_revision' => $payload['earning_revision'],
        ]];

        $service->applyCompanyPaymentDecisions($request, $decision, $actor);
        $duplicate = $service->applyCompanyPaymentDecisions($request, $decision, $actor);

        $this->assertSame('duplicate_noop', $duplicate['status']);
        $this->assertDatabaseCount('technical_service_payment_settlement_allocations', 1);
        $this->assertSame(1, TechnicalServiceEarningPayment::query()->where('payment_type', TechnicalServiceAssignmentSettlementService::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT)->count());
    }

    public function test_multiple_eligible_payments_require_independent_decisions(): void
    {
        [$request, , , , , $actor] = $this->assignedSettlementFixture();
        $first = $this->paidChargePayment($request, 'service_payment', 400, '2026-08-10 09:00:00');
        $second = $this->paidChargePayment($request, 'service_payment', 600, '2026-08-10 09:01:00');
        $service = app(TechnicalServiceAssignmentSettlementService::class);
        $payload = $service->companyPaymentDecisionPayload($request->refresh());

        try {
            $service->applyCompanyPaymentDecisions($request, [[
                'payment_id' => $first->id,
                'decision' => 'pay_technician',
                'expected_earning_revision' => $payload['earning_revision'],
            ]], $actor);
            $this->fail('Eksik bağımsız karar kabul edilmemeliydi.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('technical_service_payment_settlement_allocations', 0);
        }

        $this->decideCompanyPayments($request, $actor, [
            $first->id => 'pay_technician',
            $second->id => 'retain_company',
        ]);
        $this->assertDatabaseCount('technical_service_payment_settlement_allocations', 2);
    }

    public function test_late_paid_payment_creates_adjustment_decision_not_history_rewrite(): void
    {
        [$request, , , $offer, , $actor] = $this->assignedSettlementFixture();
        $historical = app(TechnicalServiceAssignmentSettlementService::class)->canonicalEarningSnapshot($offer);
        $request->forceFill([
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => now(),
            'operation_control_payload' => ['completed_earning_snapshot' => $historical],
        ])->save();
        $payment = $this->paidChargePayment($request, 'service_payment', 350, '2026-08-10 10:00:00');

        $this->decideCompanyPayments($request, $actor, [$payment->id => 'pay_technician']);

        $this->assertEquals($historical, data_get($request->fresh()->operation_control_payload, 'completed_earning_snapshot'));
        $this->assertDatabaseHas('technical_service_earning_payments', [
            'payment_type' => TechnicalServiceAssignmentSettlementService::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT,
            'amount' => '350.00',
        ]);
    }

    public function test_reassignment_does_not_move_existing_company_payment(): void
    {
        [$request, , , $offer, , $actor] = $this->assignedSettlementFixture();
        $payment = $this->paidChargePayment($request, 'service_payment', 500, '2026-08-10 09:00:00');
        $this->decideCompanyPayments($request, $actor, [$payment->id => 'pay_technician']);
        $newTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Yeni Usta', 'first_name' => 'Yeni', 'last_name' => 'Usta', 'phone' => '905300000199', 'city' => 'Adana', 'active' => true,
        ]);

        $response = $this->assign($request, $newTechnician);

        $response->assertStatus(422);
        $this->assertDatabaseHas('technical_service_earning_payments', [
            'technical_service_assignment_offer_id' => $offer->id,
            'technical_service_technician_id' => $offer->technical_service_technician_id,
            'payment_type' => TechnicalServiceAssignmentSettlementService::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT,
        ]);
    }

    public function test_refund_before_payout_reverses_payable_line(): void
    {
        [$request, , , , $settlement, $actor] = $this->assignedSettlementFixture();
        $payment = $this->paidChargePayment($request, 'service_payment', 500, '2026-08-10 09:00:00');
        $this->decideCompanyPayments($request, $actor, [$payment->id => 'pay_technician']);

        $result = app(TechnicalServiceAssignmentSettlementService::class)
            ->reverseCompanyPaymentAllocation($payment, $actor, 'İade edildi');

        $this->assertSame('payable_reversed', $result['status']);
        $this->assertSame(0.0, (float) $settlement->fresh()->company_payment_amount);
        $this->assertDatabaseHas('technical_service_earning_payments', [
            'payment_type' => TechnicalServiceAssignmentSettlementService::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT,
            'status' => TechnicalServiceEarningPayment::STATUS_VOID,
        ]);
    }

    public function test_refund_after_payout_creates_negative_adjustment(): void
    {
        [$request, , , , $settlement, $actor] = $this->assignedSettlementFixture();
        $payment = $this->paidChargePayment($request, 'service_payment', 500, '2026-08-10 09:00:00');
        $this->decideCompanyPayments($request, $actor, [$payment->id => 'pay_technician']);
        $line = TechnicalServiceEarningPayment::query()->where('payment_type', TechnicalServiceAssignmentSettlementService::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT)->firstOrFail();
        $payout = new TechnicalServiceEarningPayment;
        $payout->forceFill([
            'technical_service_settlement_id' => $settlement->id,
            'technical_service_request_id' => $request->id,
            'technical_service_assignment_offer_id' => $line->technical_service_assignment_offer_id,
            'technical_service_technician_id' => $line->technical_service_technician_id,
            'currency' => 'TRY',
            'payment_type' => TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT,
            'source_company_payment_line_id' => $line->id,
            'amount' => 500,
            'status' => TechnicalServiceEarningPayment::STATUS_APPLIED,
            'paid_at' => now(),
        ])->save();

        $result = app(TechnicalServiceAssignmentSettlementService::class)
            ->reverseCompanyPaymentAllocation($payment, $actor, 'Ödeme sonrası iade');

        $this->assertSame('negative_adjustment_created', $result['status']);
        $this->assertDatabaseHas('technical_service_earning_payments', [
            'payment_type' => TechnicalServiceEarningPayment::TYPE_ADJUSTMENT,
            'source_company_payment_line_id' => $line->id,
            'amount' => '-500.00',
            'status' => TechnicalServiceEarningPayment::STATUS_APPLIED,
        ]);
    }

    public function test_earning_preview_message_and_read_model_use_same_company_payment_snapshot(): void
    {
        [$request, $technician, $partner, $offer, , $actor] = $this->assignedSettlementFixture();
        $payment = $this->paidChargePayment($request, 'service_payment', 1000, '2026-08-10 09:00:00');
        $this->decideCompanyPayments($request, $actor, [$payment->id => 'pay_technician']);
        $workflow = app(TechnicalServiceWorkflowService::class);
        $presentation = $workflow->technicianEarningPresentation($request->refresh(), $technician, $offer->refresh());
        $readModel = $workflow->serialize($request->refresh(), true);
        $partnerReadModel = app(B2BPartnerPortalDataService::class)->safeServiceJobSummary($request->refresh(), $partner);

        $this->assertSame(1000.0, $presentation['earning_snapshot']['company_payment_amount']);
        $this->assertSame(2500.0, $presentation['earning_snapshot']['total_amount']);
        $this->assertStringContainsString('Şirket ödemesi — Ek servis: 1.000,00 TL', $presentation['message_preview']);
        $this->assertSame(1000.0, data_get($readModel, 'settlement.company_payment_amount'));
        $this->assertSame(2500.0, data_get($readModel, 'settlement.technician_earning_total'));
        $this->assertSame(1000.0, data_get($partnerReadModel, 'earning_summary.company_payment_amount'));
        $this->assertSame(2500.0, data_get($partnerReadModel, 'earning_summary.total_amount'));
    }

    public function test_earning_message_uses_canonical_company_payment_snapshot(): void
    {
        [$request, $technician, , $offer, , $actor] = $this->assignedSettlementFixture();
        $payment = $this->paidChargePayment($request, 'service_payment', 600, '2026-08-10 09:00:00');
        $this->decideCompanyPayments($request, $actor, [$payment->id => 'pay_technician']);

        $presentation = app(TechnicalServiceWorkflowService::class)
            ->technicianEarningPresentation($request->refresh(), $technician, $offer->refresh());
        $snapshot = $presentation['earning_snapshot'];

        $this->assertSame(3, $snapshot['schema_version']);
        $this->assertSame(1000.0, $snapshot['labor_amount']);
        $this->assertSame(500.0, $snapshot['route_fee_amount']);
        $this->assertSame(600.0, $snapshot['company_payment_amount']);
        $this->assertSame(2100.0, $snapshot['total_amount']);
        $this->assertSame(0.0, $snapshot['technician_paid_amount']);
        $this->assertSame(2100.0, $snapshot['technician_remaining_amount']);
        $this->assertSame('company_collected_company_pays_technician', $snapshot['payer_state']);
        $this->assertSame('Şirket ödemesi', $snapshot['technician_payment_model_label']);
        $this->assertSame('EMAKS Prime', $snapshot['technician_payment_source_label']);
        $this->assertSame('payable', $snapshot['technician_payment_status_key']);
        $this->assertSame('Ödenecek', $snapshot['technician_payment_status_label']);
        $this->assertSame($snapshot['revision'], $snapshot['snapshot_hash']);
        $this->assertStringContainsString('Şirket ödemesi — Ek servis: 600,00 TL', $presentation['message_preview']);
        $this->assertStringContainsString('Toplam hakediş: 2.100,00 TL', $presentation['message_preview']);
        $this->assertStringContainsString('Ustaya ödeme kaynağı: EMAKS Prime', $presentation['message_preview']);
        $this->assertStringContainsString('Ustaya ödeme durumu: Ödenecek', $presentation['message_preview']);
    }

    public function test_customer_pays_technician_state_does_not_render_company_payment(): void
    {
        [$request, $technician, , $offer, $settlement] = $this->assignedSettlementFixture();
        $settlement->forceFill([
            'metadata' => [
                ...(is_array($settlement->metadata) ? $settlement->metadata : []),
                'payer_state_key' => 'customer_pays_technician',
            ],
        ])->save();

        $presentation = app(TechnicalServiceWorkflowService::class)
            ->technicianEarningPresentation($request->refresh(), $technician, $offer->refresh());
        $snapshot = $presentation['earning_snapshot'];

        $this->assertSame('customer_pays_technician', $snapshot['payer_state']);
        $this->assertSame('Müşteri', $snapshot['technician_payment_source_label']);
        $this->assertSame(0.0, $snapshot['company_payment_amount']);
        $this->assertSame([], $snapshot['company_payment_breakdown']);
        $this->assertStringNotContainsString('Şirket ödemesi —', $presentation['message_preview']);
        $this->assertStringContainsString('Ustaya ödeme kaynağı: Müşteri', $presentation['message_preview']);
    }

    public function test_corrective_resend_requires_reason(): void
    {
        [$request, $technician, $partner, $offer, , $actor] = $this->assignedSettlementFixture();
        $this->enableLocksmithJobCard($partner);
        $payment = $this->paidChargePayment($request, 'service_payment', 600, '2026-08-10 09:00:00');
        $this->decideCompanyPayments($request, $actor, [$payment->id => 'pay_technician']);
        $snapshot = app(TechnicalServiceWorkflowService::class)
            ->canonicalTechnicianEarningSnapshot($offer->refresh());
        $historical = $this->historicalWrongCompanyPaymentDispatch($request, $offer, $snapshot, $actor);

        $this->assertSame($snapshot['revision'], data_get($historical->metadata, 'earning_snapshot_revision'));

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/technicians/{$technician->id}/earnings-message", [
                'earning_revision' => $snapshot['revision'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('corrective_resend_reason')
            ->assertJsonPath(
                'errors.corrective_resend_reason.0',
                'Düzeltici yeniden gönderim nedeni zorunludur: Şirket ödemesi bileşeni ve ödeme durumu düzeltmesi',
            );

        $this->assertSame(1, TechnicalServiceMessageDispatch::query()
            ->where('message_type', 'earnings_message_technician')
            ->count());
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $historical->fresh()->status);
        $this->assertSame(1, $historical->fresh()->attempt_count);
        $this->assertStringContainsString('Toplam hakediş: 1.500,00 TL', $historical->fresh()->bodyForProvider());
    }

    public function test_duplicate_corrective_resend_creates_one_dispatch_per_channel(): void
    {
        [$request, $technician, $partner, $offer, , $actor] = $this->assignedSettlementFixture();
        $this->enableLocksmithJobCard($partner);
        $this->enableTwoChannelCorrectionDispatches($actor);
        $payment = $this->paidChargePayment($request, 'service_payment', 600, '2026-08-10 09:00:00');
        $this->decideCompanyPayments($request, $actor, [$payment->id => 'pay_technician']);
        $snapshot = app(TechnicalServiceWorkflowService::class)
            ->canonicalTechnicianEarningSnapshot($offer->refresh());
        $historicalWhatsApp = $this->historicalWrongCompanyPaymentDispatch(
            $request,
            $offer,
            $snapshot,
            $actor,
            'whatsapp',
        );
        $historicalSms = $this->historicalWrongCompanyPaymentDispatch(
            $request,
            $offer,
            $snapshot,
            $actor,
            'sms',
        );
        $messagingSnapshot = app(TechnicalServiceMessagingSettingsService::class)->workflowDispatchSnapshot();
        $earningPolicy = collect($messagingSnapshot['message_types'])
            ->firstWhere('key', 'earnings_message_technician');
        $this->assertTrue((bool) data_get($messagingSnapshot, 'global.messaging_enabled'));
        $this->assertSame('whatsapp_and_sms', $earningPolicy['channel_policy'] ?? null);
        $payload = [
            'earning_revision' => $snapshot['revision'],
            'corrective_resend_reason' => 'Şirket ödemesi bileşeni ve ödeme durumu düzeltmesi',
        ];

        $first = $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/technicians/{$technician->id}/earnings-message", $payload)
            ->assertOk()
            ->assertJsonPath('duplicate_noop', false)
            ->assertJsonPath('corrective_resend', true);
        $second = $this->postJson(
            "/api/technical-service/requests/{$request->id}/technicians/{$technician->id}/earnings-message",
            $payload,
        )
            ->assertOk()
            ->assertJsonPath('duplicate_noop', true);

        $this->assertSame($first->json('dispatch.id'), $second->json('dispatch.id'));
        $this->assertSame(4, TechnicalServiceMessageDispatch::query()
            ->where('message_type', 'earnings_message_technician')
            ->count());
        $correctiveDispatches = TechnicalServiceMessageDispatch::query()
            ->where('message_type', 'earnings_message_technician')
            ->where('force_resend', true)
            ->orderBy('channel')
            ->get();
        $this->assertSame(['sms', 'whatsapp'], $correctiveDispatches->pluck('channel')->all());
        $corrective = $correctiveDispatches->firstWhere('channel', 'whatsapp');
        $this->assertNotNull($corrective);
        $this->assertTrue($corrective->force_resend);
        $this->assertSame('Şirket ödemesi bileşeni ve ödeme durumu düzeltmesi', $corrective->force_resend_reason);
        $this->assertSame($snapshot['revision'], data_get($corrective->metadata, 'earning_snapshot_revision'));
        $this->assertSame($snapshot['snapshot_hash'], data_get($corrective->metadata, 'earning_snapshot_hash'));
        $this->assertSame(3, data_get($corrective->metadata, 'earning_message_contract_version'));
        $this->assertNotEmpty(data_get($corrective->metadata, 'corrective_resend_identity'));
        $this->assertSame(
            collect([$historicalWhatsApp->id, $historicalSms->id])->sort()->values()->all(),
            data_get($corrective->metadata, 'corrective_resend_source_dispatch_ids'),
        );
        foreach ([$historicalWhatsApp, $historicalSms] as $historical) {
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $historical->fresh()->status);
            $this->assertSame(1, $historical->fresh()->attempt_count);
        }
    }

    private function enableTwoChannelCorrectionDispatches(User $actor): void
    {
        config([
            'app.release_sha' => '7cd5bdce9924c397086f7bfa1e4adb497988bc5e',
            'services.partner_portal.public_url' => 'http://10.0.28.64:8000',
        ]);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->freezeManualE2E();
        $settings->update([
            'messaging_enabled' => true,
            'test_mode_enabled' => true,
            'customer_test_phone' => '905000000001',
            'technician_ops_test_phone' => '905000000002',
            'manual_e2e_allowlisted_phones' => ['905000000001', '905000000002'],
            'manual_e2e_partner_portal_origin_enabled' => true,
            'manual_e2e_partner_portal_origin' => 'http://10.0.28.64:8000',
            'active_provider' => 'evo_whatsapp',
            'provider_key' => 'evo_whatsapp',
            'evo_whatsapp' => [
                'direct_api_enabled' => true,
                'direct_api_base_url' => 'https://evo-api.example.test',
                'direct_api_instance_name' => 'earning-correction-test',
                'delay' => 0,
                'link_preview' => false,
            ],
            'nac_sms' => [
                'enabled' => true,
                'profile' => 'custom',
                'scheme' => 'https',
                'host' => 'nac.example.test',
                'port' => 443,
                'path' => '/sms/create',
                'request_shape' => 'legacy_working_minimal',
                'sender' => 'EMAKS TEST',
                'real_send_allowed' => true,
            ],
            'message_types' => [
                'earnings_message_technician' => [
                    'enabled' => true,
                    'channel_policy' => 'whatsapp_and_sms',
                    'whatsapp_mode' => 'test',
                    'sms_mode' => 'test',
                    'whatsapp_provider' => 'evo_whatsapp',
                    'sms_provider' => 'nac_sms',
                ],
            ],
        ]);

        foreach ([
            [TechnicalServiceMessagingSettingsService::PAGE_CODE, TechnicalServiceMessagingSettingsService::ROOT_KEY],
            [TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE, TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY],
        ] as [$pageCode, $root]) {
            $page = PageConfig::query()->where('page_code', $pageCode)->firstOrFail();
            $layout = (array) $page->layout_json;
            foreach (['evo_whatsapp', 'nac_sms'] as $provider) {
                Arr::set($layout, $root.'.providers.'.$provider, [
                    'enabled' => true,
                    'real_send_allowed' => true,
                    'test_send_allowed' => true,
                    'notes' => 'Fake earning correction provider.',
                ]);
            }
            $page->forceFill(['layout_json' => $layout])->save();
        }

        $settings->saveEvoWhatsappCredentials(['api_key' => 'earning-correction-test-key']);
        $settings->saveNacSmsCredentials(['username' => 'earning-correction-user', 'password' => 'earning-correction-pass']);
        $owner = 'earning-correction-worker-'.Str::lower(Str::random(12));
        $settings->registerOutboundWorkerLease($owner, now()->toImmutable(), now()->addHour()->toImmutable());
        $this->actingAs($actor)
            ->patchJson('/api/technical-service/messaging-settings', [
                'real_send_enabled' => true,
                'test_mode_enabled' => true,
            ])
            ->assertOk();
    }

    /**
     * @return array{0: TechnicalServiceRequest, 1: TechnicalServiceTechnician, 2: B2BPartner, 3: TechnicalServiceAssignmentOffer, 4: TechnicalServiceSettlement, 5: User}
     */
    private function assignedSettlementFixture(): array
    {
        [$request, $technician, $partner] = $this->assignmentFixture();
        $this->assign($request, $technician)->assertOk();

        return [
            $request->refresh(),
            $technician,
            $partner,
            TechnicalServiceAssignmentOffer::query()
                ->where('technical_service_request_id', $request->id)
                ->latest('id')
                ->firstOrFail(),
            TechnicalServiceSettlement::query()
                ->where('technical_service_request_id', $request->id)
                ->firstOrFail(),
            User::factory()->create(['role_code' => 'admin']),
        ];
    }

    /** @param array<string, mixed> $payloadOverrides */
    private function paidChargePayment(
        TechnicalServiceRequest $request,
        string $purpose,
        float $amount,
        string $paidAt,
        array $payloadOverrides = [],
    ): TechnicalServiceMountPayment {
        $payment = $this->paidMountPayment($request, 'COMPANY-CHARGE-'.uniqid());
        $payment->forceFill([
            'amount' => $amount,
            'paid_at' => $paidAt,
            'provider_paid_at' => $paidAt,
            'raw_payload' => array_merge([
                'source' => 'technical_service_additional_payment',
                'purpose' => $purpose,
                'charge_type' => $purpose,
                'payer_state_key' => 'company_collected_online',
            ], $payloadOverrides),
        ])->save();

        return $payment->refresh();
    }

    /**
     * @param  array<int, 'pay_technician'|'retain_company'>  $decisions
     * @return array<string, mixed>
     */
    private function decideCompanyPayments(TechnicalServiceRequest $request, User $actor, array $decisions): array
    {
        $service = app(TechnicalServiceAssignmentSettlementService::class);
        $payload = $service->companyPaymentDecisionPayload($request->refresh());

        return $service->applyCompanyPaymentDecisions(
            $request,
            collect($decisions)->map(fn (string $decision, int $paymentId): array => [
                'payment_id' => $paymentId,
                'decision' => $decision,
                'expected_earning_revision' => $payload['earning_revision'],
            ])->values()->all(),
            $actor,
        );
    }

    /** @param array<string, mixed> $snapshot */
    private function historicalWrongCompanyPaymentDispatch(
        TechnicalServiceRequest $request,
        TechnicalServiceAssignmentOffer $offer,
        array $snapshot,
        User $actor,
        string $channel = 'whatsapp',
    ): TechnicalServiceMessageDispatch {
        $oldRevision = (string) $snapshot['revision'];
        $historicalSnapshot = [
            ...$snapshot,
            'revision' => $oldRevision,
            'snapshot_hash' => $oldRevision,
        ];
        $oldBody = "Hakediş bilgisi\nToplam hakediş: 1.500,00 TL";

        return TechnicalServiceMessageDispatch::query()->create([
            'event' => 'earnings_message_technician',
            'technical_service_request_id' => $request->id,
            'technical_service_assignment_offer_id' => $offer->id,
            'request_id' => $request->id,
            'root_mrn' => $request->root_mrn ?: $request->mrn,
            'mrn' => $request->mrn,
            'srv' => $request->service_code,
            'message_type' => 'earnings_message_technician',
            'channel' => $channel,
            'provider_key' => $channel === 'sms' ? 'nac_sms' : 'evo_whatsapp',
            'recipient_role' => 'technician',
            'status' => TechnicalServiceMessageDispatch::STATUS_SENT,
            'attempt_count' => 1,
            'max_attempts' => 1,
            'rendered_body_hash' => hash('sha256', $oldBody),
            'payload_hash' => hash('sha256', 'old-company-payment-body|'.$channel),
            'idempotency_key' => hash('sha256', 'old-company-payment-dispatch|'.$request->id.'|'.$channel),
            'metadata' => [
                'assignment_offer_id' => $offer->id,
                'earning_snapshot_fingerprint' => $oldRevision,
                'earning_snapshot_revision' => $oldRevision,
                'earning_snapshot_hash' => $oldRevision,
                'earning_snapshot' => $historicalSnapshot,
            ],
            'request_payload' => [
                'body' => $oldBody,
                'rendered_body' => $oldBody,
            ],
            'created_by' => $actor->id,
            'sent_by' => $actor->id,
            'sent_at' => now()->subMinute(),
        ]);
    }

    private function enableLocksmithJobCard(B2BPartner $partner): void
    {
        config(['services.partner_portal.public_url' => 'https://dashboard.test']);
        B2BPartnerCapability::query()->firstOrCreate([
            'partner_id' => $partner->id,
            'capability' => B2BPartner::TYPE_LOCKSMITH,
        ], [
            'active' => true,
        ]);
    }

    /**
     * @return array{0: TechnicalServiceRequest, 1: TechnicalServiceTechnician, 2: B2BPartner}
     */
    private function assignmentFixture(): array
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'REL3B2 Usta',
            'first_name' => 'REL3B2',
            'last_name' => 'Usta',
            'phone' => '905300000100',
            'city' => 'Adana',
            'active' => true,
        ]);
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'REL3B2-'.uniqid(),
            'display_name' => 'REL3B2 Çilingir',
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'field_technician',
            'active' => true,
            'is_primary' => true,
        ]);

        $request = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-REL3B2-'.uniqid(),
            'root_mrn' => 'MRN-REL3B2-ROOT',
            'service_code' => 'SRV-REL3B2-001',
            'customer_name' => 'REL3B2 Müşteri',
            'customer_phone' => '905300000101',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'REL3B2 adres',
            'product_name' => 'Test Ürün',
            'product_model' => 'M1',
            'serial_number' => 'SN-REL3B2-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
            'source_channel' => 'panel',
        ]);

        return [$request, $technician, $partner];
    }

    private function paidMountPayment(TechnicalServiceRequest $request, string $reference): TechnicalServiceMountPayment
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => $request->serial_number,
            'product_name' => $request->product_name,
            'product_model' => $request->product_model,
        ]);

        $session = TechnicalServiceMountSession::query()->create([
            'technical_service_qr_link_id' => $link->id,
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken($reference),
            'serial_number' => $request->serial_number,
            'sale_mount_status' => TechnicalServiceMountSession::SALE_UNKNOWN,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
            'context_payload' => [],
        ]);

        $request->forceFill([
            'qr_link_id' => $link->id,
            'mount_session_id' => $session->id,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'mount_payment_provider' => 'fake',
            'mount_payment_reference' => $reference,
            'mount_payment_paid_at' => now(),
        ])->save();

        return TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => $reference,
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 1500,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => ['source' => 'public_mount_payment'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $offerOverrides
     */
    private function assign(TechnicalServiceRequest $request, TechnicalServiceTechnician $technician, array $offerOverrides = [])
    {
        $user = User::factory()->create(['role_code' => 'admin']);

        return $this->actingAs($user)->postJson("/api/technical-service/requests/{$request->id}/assign", [
            'technical_service_technician_id' => $technician->id,
            'travel_round_trip_km' => 12,
            'labor_amount' => 1000,
            'travel_amount' => 500,
            'earning_note' => 'REL3B2 hakediş',
            'confirm_assignment' => true,
            'assignment_offer' => array_merge([
                'labor_amount' => 1000,
                'route_fee_amount' => 500,
                'total_amount' => 1500,
                'currency' => 'TRY',
                'note' => 'REL3B2 hakediş',
            ], $offerOverrides),
        ]);
    }
}
