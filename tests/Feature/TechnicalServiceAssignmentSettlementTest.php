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
use App\Models\TechnicalServicePartRequest;
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
use Illuminate\Support\Facades\DB;
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
        $this->assertStringContainsString('if (assignLoading)', $source);
        $this->assertStringContainsString('onOpenChange={handleAssignDialogOpenChange}', $source);
        $this->assertStringContainsString('onAssign={openAssignmentDialog}', $source);
        $this->assertStringContainsString('onAssignSelectedTechnician={openAssignmentDialog}', $source);
        $this->assertStringContainsString('data-testid="assignment-final-preview"', $source);
        $this->assertStringContainsString('data-testid="assignment-confirm-button"', $source);
        $this->assertStringContainsString('Atamayı onayla ve mesajı hazırla', $source);
        $this->assertStringContainsString('assignmentDraftRequestId.current = null', $source);
        $this->assertStringNotContainsString('assignmentConfirmDialogOpen', $source);
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

    public function test_paid_payment_is_visible_in_its_exact_scope_and_part_is_not_service_difference(): void
    {
        [$root, $technician] = $this->assignmentFixture();
        $root->forceFill([
            'root_mrn' => $root->mrn,
            'service_code' => null,
        ])->save();
        $srv = TechnicalServiceRequest::query()->create([
            'mrn' => 'SRV-PAYMENT-SCOPE-001',
            'root_mrn' => $root->mrn,
            'parent_request_id' => $root->id,
            'service_code' => 'SRV-PAYMENT-SCOPE-001',
            'service_sequence' => 1,
            'customer_name' => $root->customer_name,
            'customer_phone' => $root->customer_phone,
            'customer_city' => $root->customer_city,
            'customer_district' => $root->customer_district,
            'service_address' => $root->service_address,
            'product_name' => $root->product_name,
            'serial_number' => $root->serial_number,
            'service_type' => 'Servis',
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ]);
        $partRequest = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $root->id,
            'root_request_id' => $root->id,
            'requested_by_technician_id' => $technician->id,
            'status' => TechnicalServicePartRequest::STATUS_SERVICE_VISIT_CREATED,
            'part_name' => 'Scope test parçası',
            'quantity' => 1,
            'requires_service_visit' => true,
            'service_visit_request_id' => $srv->id,
        ]);
        $payment = $this->paidMountPayment($root, 'SCOPE-20-'.uniqid());
        $payment->forceFill([
            'amount' => 20,
            'provider_payment_reference' => '37164237',
            'provider_transaction_reference' => '39067702',
            'raw_payload' => [
                'source' => 'operation_customer_charge',
                'purpose' => 'service_and_part_payment',
                'charge_type' => 'service_and_part_payment',
                'root_request_id' => $root->id,
                'part_request_id' => $partRequest->id,
                'service_amount' => 5,
                'part_amount' => 15,
                'total_amount' => 20,
            ],
        ])->save();
        $partRequest->forceFill(['metadata' => [
            'charge_decision' => 'chargeable',
            'service_amount' => 5,
            'part_amount' => 15,
            'total_amount' => 20,
            'customer_charge_payment_id' => $payment->id,
            'payment_id' => $payment->id,
        ]])->save();

        $summary = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($srv->refresh())['finance_summary'];
        $record = $summary['payment_records']['related_scope_rows'][0];

        $this->assertSame(0.0, $summary['current_visit']['customer_collection']['total_amount']);
        $this->assertSame(20.0, $summary['root_total']['customer_collection']['total_amount']);
        $this->assertSame(5.0, $summary['root_total']['customer_collection']['service_total_amount']);
        $this->assertSame(15.0, $summary['root_total']['customer_collection']['part_amount']);
        $this->assertSame(5.0, $summary['root_total']['net_margin']['amount']);
        $this->assertSame([], $summary['payment_records']['current_scope_rows']);
        $this->assertCount(1, $summary['payment_records']['root_scope_rows']);
        $this->assertSame(
            [$payment->id],
            collect($summary['payment_records']['root_scope_rows'])->pluck('id')->unique()->values()->all(),
        );
        $this->assertSame($payment->id, $record['id']);
        $this->assertSame($partRequest->id, $record['part_request_id']);
        $this->assertSame($srv->id, $record['srv_request_id']);
        $this->assertSame('related_part_request', $record['scope_relation']);
        $this->assertTrue($record['component_split_persisted']);
        $this->assertSame(
            sprintf('20 TL ödeme bu SRV’nin doğrudan tahsilatı değildir. Kök MRN üzerindeki Parça Talebi #%d kapsamında alınmıştır.', $partRequest->id),
            $record['scope_notice'],
        );
        $this->assertSame('37164237', $record['provider_payment_reference']);
        $this->assertSame('39067702', $record['provider_transaction_reference']);
    }

    public function test_payment_component_split_requires_persisted_allocation(): void
    {
        [$request] = $this->assignmentFixture();
        $payment = $this->paidMountPayment($request, 'UNALLOCATED-SPLIT-'.uniqid());
        $payment->forceFill([
            'amount' => 20,
            'raw_payload' => [
                'source' => 'operation_customer_charge',
                'purpose' => 'service_and_part_payment',
                'service_amount' => 5,
                'part_amount' => 15,
                'total_amount' => 20,
            ],
        ])->save();

        $records = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($request->refresh())['finance_summary']['payment_records']['root_scope_rows'];

        $this->assertCount(1, $records);
        $this->assertSame($payment->id, $records[0]['id']);
        $this->assertFalse($records[0]['component_split_persisted']);
        $this->assertNull($records[0]['part_request_id']);
        $this->assertSame(0.0, $records[0]['service_amount']);
        $this->assertSame(0.0, $records[0]['part_amount']);
        $this->assertSame(20.0, $records[0]['unclassified_amount']);
    }

    public function test_payment_component_split_comes_from_persisted_allocation(): void
    {
        [, $srv, $partRequest, $payment] = $this->paymentPartContextFixture();

        $record = collect(app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($srv)['finance_summary']['payment_records']['root_scope_rows'])
            ->firstWhere('id', $payment->id);

        $this->assertTrue($record['component_split_persisted']);
        $this->assertSame($payment->id, (int) data_get($partRequest->metadata, 'payment_id'));
        $this->assertSame(5.0, $record['service_component_amount']);
        $this->assertSame(15.0, $record['part_component_amount']);
        $this->assertSame(20.0, $record['total_amount']);
    }

    public function test_payment_167_projects_exact_part_request_context(): void
    {
        [$root, $srv, $partRequest, $payment] = $this->paymentPartContextFixture();

        $record = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($srv)['finance_summary']['payment_records']['related_scope_rows'][0];

        $this->assertSame($payment->id, $record['payment_id']);
        $this->assertSame($root->id, $record['root_request_id']);
        $this->assertSame($root->mrn, $record['root_mrn']);
        $this->assertSame($srv->id, $record['srv_request_id']);
        $this->assertSame($srv->service_code, $record['srv_request_code']);
        $this->assertSame($partRequest->id, $record['part_request_id']);
        $this->assertSame('REL-4E.15I Test Parça', $record['part_name']);
        $this->assertSame('Servis ve parça ücreti', $record['purpose_label']);
        $this->assertTrue($record['component_split_persisted']);
    }

    public function test_payment_modal_shows_part_and_service_components(): void
    {
        [, $srv, $partRequest, $payment] = $this->paymentPartContextFixture();

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($srv, false, false, true);
        $record = collect(data_get($payload, 'sale_and_payment.customer_charges.rows'))
            ->firstWhere('id', $payment->id);

        $this->assertIsArray($record);
        $this->assertSame($partRequest->id, $record['part_request_id']);
        $this->assertSame(5.0, $record['service_component_amount']);
        $this->assertSame(15.0, $record['part_component_amount']);
        $this->assertSame(20.0, $record['total_amount']);
        $this->assertSame(sprintf('Kök MRN / Parça Talebi #%d', $partRequest->id), $record['scope_label']);
    }

    public function test_root_finance_shows_total_customer_collection_20(): void
    {
        [, $srv] = $this->paymentPartContextFixture();

        $collection = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($srv)['finance_summary']['root_total']['customer_collection'];

        $this->assertSame(20.0, $collection['total_amount']);
        $this->assertSame(5.0, $collection['service_total_amount']);
        $this->assertSame(15.0, $collection['part_amount']);
    }

    public function test_part_component_is_excluded_from_service_operational_difference(): void
    {
        [, $srv, , $payment] = $this->paymentPartContextFixture();

        $summary = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($srv)['finance_summary'];
        $record = collect($summary['payment_records']['root_scope_rows'])->firstWhere('id', $payment->id);

        $this->assertSame(5.0, $record['operational_difference_included_amount']);
        $this->assertSame(15.0, $record['operational_difference_excluded_part_amount']);
        $this->assertSame(5.0, $summary['root_total']['net_margin']['amount']);
    }

    public function test_current_srv_does_not_hide_root_part_payment(): void
    {
        [, $srv, $partRequest, $payment] = $this->paymentPartContextFixture();

        $records = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($srv)['finance_summary']['payment_records'];

        $this->assertSame([], $records['current_scope_rows']);
        $this->assertCount(1, $records['related_scope_rows']);
        $this->assertSame($payment->id, $records['related_scope_rows'][0]['id']);
        $this->assertSame(
            sprintf('20 TL ödeme bu SRV’nin doğrudan tahsilatı değildir. Kök MRN üzerindeki Parça Talebi #%d kapsamında alınmıştır.', $partRequest->id),
            $records['related_scope_rows'][0]['scope_notice'],
        );
    }

    public function test_parent_part_summary_shows_payment_167(): void
    {
        [, $srv, $partRequest, $payment] = $this->paymentPartContextFixture();

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($srv, true, false, true);
        $part = collect(data_get($payload, 'service_visit_history.parent_part_requests'))
            ->firstWhere('id', $partRequest->id);

        $this->assertIsArray($part);
        $this->assertSame($payment->id, data_get($part, 'payment_context.payment_id'));
        $this->assertSame(5.0, data_get($part, 'payment_context.service_component_amount'));
        $this->assertSame(15.0, data_get($part, 'payment_context.part_component_amount'));
        $this->assertSame($srv->service_code, data_get($part, 'payment_context.srv_request_code'));
    }

    public function test_payment_167_state_and_references_are_unchanged(): void
    {
        [, $srv, , $payment] = $this->paymentPartContextFixture();
        $before = $payment->only(['status', 'amount', 'provider_payment_reference', 'provider_transaction_reference']);
        $paidAtBefore = $payment->paid_at?->toISOString();

        app(TechnicalServiceWorkflowService::class)->serialize($srv, true, true, true);

        $this->assertSame($before, $payment->fresh()->only(array_keys($before)));
        $this->assertSame($paidAtBefore, $payment->fresh()->paid_at?->toISOString());
    }

    public function test_payment_198_allocation_remains_unchanged(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
        [$request, , , , $settlement, $actor] = $this->assignedSettlementFixture();
        $payment = $this->paidChargePayment($request, 'service_payment', 600, '2026-08-10 09:00:00');
        $this->decideCompanyPayments($request, $actor, [$payment->id => 'pay_technician']);
        $allocation = DB::table('technical_service_payment_settlement_allocations')
            ->where('technical_service_mount_payment_id', $payment->id)
            ->first();
        $line = TechnicalServiceEarningPayment::query()->findOrFail($allocation->settlement_line_id);

        app(TechnicalServiceWorkflowService::class)->financialWorkspacePayload($request->refresh());

        $this->assertSame('pay_technician', $allocation->decision);
        $this->assertSame(600.0, (float) $line->fresh()->amount);
        $this->assertSame(TechnicalServiceEarningPayment::STATUS_PENDING, $line->fresh()->status);
        $this->assertNull($line->fresh()->paid_at);
        $this->assertSame(2100.0, (float) $settlement->fresh()->technician_earning_total);
    }

    public function test_root_mrn_resolves_current_mrn_scope(): void
    {
        [$root] = $this->assignmentFixture();

        $context = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($root)['finance_summary']['scope_context'];

        $this->assertSame('mrn', $context['current_record_type']);
        $this->assertSame('current_mrn', $context['current_scope_key']);
        $this->assertSame('Bu MRN', $context['current_scope_label']);
        $this->assertSame($root->mrn, $context['current_record_code']);
    }

    public function test_child_srv_resolves_current_srv_scope(): void
    {
        [$root, $srv] = $this->paymentPartContextFixture();

        $context = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($srv)['finance_summary']['scope_context'];

        $this->assertSame('srv', $context['current_record_type']);
        $this->assertSame('current_srv', $context['current_scope_key']);
        $this->assertSame('Bu SRV', $context['current_scope_label']);
        $this->assertSame($root->mrn, $context['root_mrn_code']);
    }

    public function test_record_type_uses_relationship_not_only_code_prefix(): void
    {
        [$root] = $this->assignmentFixture();
        $srv = $this->serviceVisitForRoot($root, 'REL-AUTH');
        $srv->forceFill([
            'mrn' => 'MRN-LEGACY-CHILD-'.uniqid(),
            'service_code' => null,
        ])->save();
        $service = app(TechnicalServiceWorkflowService::class);

        $rootContext = $service->financialWorkspacePayload($root)['finance_summary']['scope_context'];
        $srvContext = $service->financialWorkspacePayload($srv->refresh())['finance_summary']['scope_context'];

        $this->assertSame('mrn', $rootContext['current_record_type']);
        $this->assertStringStartsWith('SRV-', (string) $root->service_code);
        $this->assertSame('srv', $srvContext['current_record_type']);
        $this->assertStringStartsWith('MRN-', $srv->fresh()->mrn);
    }

    public function test_standalone_root_exposes_only_current_mrn_option(): void
    {
        [$root] = $this->assignmentFixture();

        $context = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($root)['finance_summary']['scope_context'];

        $this->assertFalse($context['root_total_available']);
        $this->assertFalse($context['has_descendants']);
        $this->assertSame([
            ['key' => 'current_mrn', 'label' => 'Bu MRN', 'available' => true],
        ], $context['scope_options']);
    }

    public function test_root_with_child_srv_exposes_root_total_option(): void
    {
        [$root] = $this->assignmentFixture();
        $this->serviceVisitForRoot($root, 'ROOT-CHILD');

        $context = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($root->refresh())['finance_summary']['scope_context'];

        $this->assertTrue($context['root_total_available']);
        $this->assertTrue($context['has_descendants']);
        $this->assertSame(['current_mrn', 'root_mrn_total'], collect($context['scope_options'])->pluck('key')->all());
    }

    public function test_root_with_part_request_exposes_root_total_option(): void
    {
        [$root, $technician] = $this->assignmentFixture();
        TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $root->id,
            'root_request_id' => $root->id,
            'requested_by_technician_id' => $technician->id,
            'status' => TechnicalServicePartRequest::STATUS_REQUESTED,
            'part_name' => 'Scope context parçası',
            'quantity' => 1,
            'requires_service_visit' => false,
        ]);

        $context = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($root->refresh())['finance_summary']['scope_context'];

        $this->assertTrue($context['root_total_available']);
        $this->assertTrue($context['has_descendants']);
        $this->assertSame(['current_mrn', 'root_mrn_total'], collect($context['scope_options'])->pluck('key')->all());
    }

    public function test_child_srv_exposes_current_srv_and_root_total(): void
    {
        [, $srv] = $this->paymentPartContextFixture();

        $context = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($srv)['finance_summary']['scope_context'];

        $this->assertTrue($context['root_total_available']);
        $this->assertSame(['current_srv', 'root_mrn_total'], collect($context['scope_options'])->pluck('key')->all());
        $this->assertSame(['Bu SRV', 'Kök MRN toplamı'], collect($context['scope_options'])->pluck('label')->all());
    }

    public function test_current_mrn_scope_excludes_child_srv_economics(): void
    {
        [$root] = $this->assignmentFixture();
        $srv = $this->serviceVisitForRoot($root, 'CUR-MRN');
        $this->paidChargePayment($root, 'service_payment', 100, '2026-08-12 09:00:00');
        $this->paidChargePayment($srv, 'service_payment', 200, '2026-08-12 09:01:00');

        $summary = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($root->refresh())['finance_summary'];

        $this->assertSame(100.0, $summary['current_visit']['customer_collection']['total_amount']);
        $this->assertSame(300.0, $summary['root_total']['customer_collection']['total_amount']);
    }

    public function test_current_srv_scope_excludes_root_and_sibling_economics(): void
    {
        [$root] = $this->assignmentFixture();
        $srv = $this->serviceVisitForRoot($root, 'CUR-SRV');
        $sibling = $this->serviceVisitForRoot($root, 'SIB-SRV');
        $this->paidChargePayment($root, 'service_payment', 100, '2026-08-12 09:00:00');
        $this->paidChargePayment($srv, 'service_payment', 200, '2026-08-12 09:01:00');
        $this->paidChargePayment($sibling, 'service_payment', 300, '2026-08-12 09:02:00');

        $summary = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($srv->refresh())['finance_summary'];

        $this->assertSame(200.0, $summary['current_visit']['customer_collection']['total_amount']);
        $this->assertSame(600.0, $summary['root_total']['customer_collection']['total_amount']);
    }

    public function test_root_total_aggregates_root_srv_and_part_once(): void
    {
        [$root, $srv, , $partPayment] = $this->paymentPartContextFixture();
        $rootPayment = $this->paidChargePayment($root, 'service_payment', 100, '2026-08-12 09:00:00', [
            'source' => 'operation_customer_charge',
        ]);
        $srvPayment = $this->paidChargePayment($srv, 'service_payment', 200, '2026-08-12 09:01:00', [
            'source' => 'operation_customer_charge',
        ]);

        $summary = app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($root->refresh())['finance_summary'];
        $rootRows = collect($summary['payment_records']['root_scope_rows']);

        $this->assertSame(100.0, $summary['current_visit']['customer_collection']['total_amount']);
        $this->assertSame(320.0, $summary['root_total']['customer_collection']['total_amount']);
        $this->assertSame(
            collect([$partPayment->id, $rootPayment->id, $srvPayment->id])->sort()->values()->all(),
            $rootRows->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame($rootRows->count(), $rootRows->pluck('id')->unique()->count());
    }

    public function test_payment_scope_label_uses_canonical_scope_context(): void
    {
        [$root] = $this->assignmentFixture();
        $srv = $this->serviceVisitForRoot($root, 'PAYMENT-LABEL');
        $rootPayment = $this->paidChargePayment($root, 'service_payment', 100, '2026-08-12 09:00:00', [
            'source' => 'operation_customer_charge',
        ]);
        $srvPayment = $this->paidChargePayment($srv, 'service_payment', 200, '2026-08-12 09:01:00', [
            'source' => 'operation_customer_charge',
        ]);
        $service = app(TechnicalServiceWorkflowService::class);

        $rootRows = collect($service->financialWorkspacePayload($root->refresh())['finance_summary']['payment_records']['root_scope_rows']);
        $srvRows = collect($service->financialWorkspacePayload($srv->refresh())['finance_summary']['payment_records']['root_scope_rows']);
        [, $partSrv, $partRequest, $partPayment] = $this->paymentPartContextFixture();
        $partRow = collect($service->financialWorkspacePayload($partSrv)['finance_summary']['payment_records']['root_scope_rows'])
            ->firstWhere('id', $partPayment->id);

        $this->assertSame('Bu MRN', $rootRows->firstWhere('id', $rootPayment->id)['scope_label']);
        $this->assertSame('Bu SRV', $srvRows->firstWhere('id', $srvPayment->id)['scope_label']);
        $this->assertSame('Kök MRN', $srvRows->firstWhere('id', $rootPayment->id)['scope_label']);
        $this->assertSame(sprintf('Kök MRN / Parça Talebi #%d', $partRequest->id), $partRow['scope_label']);
    }

    public function test_payment_167_projection_remains_unchanged(): void
    {
        [, $srv, $partRequest, $payment] = $this->paymentPartContextFixture();
        $before = $payment->only(['status', 'amount', 'provider_payment_reference', 'provider_transaction_reference']);

        $row = collect(app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($srv)['finance_summary']['payment_records']['root_scope_rows'])
            ->firstWhere('id', $payment->id);

        $this->assertSame($before, $payment->fresh()->only(array_keys($before)));
        $this->assertSame($partRequest->id, $row['part_request_id']);
        $this->assertSame(5.0, $row['service_component_amount']);
        $this->assertSame(15.0, $row['part_component_amount']);
        $this->assertSame(20.0, $row['total_amount']);
    }

    public function test_payment_195_projection_remains_unchanged(): void
    {
        [$root] = $this->assignmentFixture();
        $payment = $this->paidChargePayment($root, 'service_payment', 1000, '2026-08-12 09:00:00', [
            'source' => 'operation_customer_charge',
        ]);
        $payment->forceFill([
            'provider_payment_reference' => 'PAYMENT-195',
            'provider_transaction_reference' => 'TRANSACTION-195',
        ])->save();
        $before = $payment->only(['status', 'amount', 'provider_payment_reference', 'provider_transaction_reference']);

        $row = collect(app(TechnicalServiceWorkflowService::class)
            ->financialWorkspacePayload($root->refresh())['finance_summary']['payment_records']['root_scope_rows'])
            ->firstWhere('id', $payment->id);

        $this->assertSame($before, $payment->fresh()->only(array_keys($before)));
        $this->assertSame(1000.0, $row['amount']);
        $this->assertSame('Bu MRN', $row['scope_label']);
    }

    public function test_financial_scope_resolution_adds_no_n_plus_one(): void
    {
        [$root] = $this->assignmentFixture();
        $children = collect(range(1, 6))
            ->map(fn (int $index): TechnicalServiceRequest => $this->serviceVisitForRoot($root, 'NPLUS-'.$index));
        $service = app(TechnicalServiceWorkflowService::class);
        $service->preloadSerializationContext($children);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $contexts = $children->map(
            fn (TechnicalServiceRequest $request): array => $service
                ->financialWorkspacePayload($request)['finance_summary']['scope_context'],
        );

        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $scopeRelationshipQueries = $queries->filter(function (array $query): bool {
            $sql = Str::lower((string) ($query['query'] ?? ''));

            return str_contains($sql, 'technical_service_part_requests')
                || (str_contains($sql, 'technical_service_requests') && str_contains($sql, 'parent_request_id'));
        });

        $this->assertCount(6, $contexts);
        $this->assertTrue($contexts->every(fn (array $context): bool => $context['current_scope_key'] === 'current_srv'));
        $this->assertLessThanOrEqual(2, $scopeRelationshipQueries->count());
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
        $this->assertStringContainsString('Ek servis: 1.000,00 TL', $presentation['message_preview']);
        $this->assertStringContainsString('Toplam hakedişiniz: 2.500,00 TL', $presentation['message_preview']);
        $this->assertStringContainsString('Hakedişiniz EMAKS Prime tarafından yapılacaktır.', $presentation['message_preview']);
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
        $this->assertStringContainsString('Ek servis: 600,00 TL', $presentation['message_preview']);
        $this->assertStringContainsString('Toplam hakedişiniz: 2.100,00 TL', $presentation['message_preview']);
        $this->assertStringContainsString('Hakedişiniz EMAKS Prime tarafından yapılacaktır.', $presentation['message_preview']);
        $this->assertStringNotContainsString('Ödeme modeli', $presentation['message_preview']);
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
        $this->assertStringNotContainsString('Şirket ödemesi', $presentation['message_preview']);
        $this->assertStringContainsString('Hakedişiniz müşteri tarafından ödenecektir.', $presentation['message_preview']);
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
                'Düzeltici yeniden gönderim nedeni zorunludur: Hakediş mesajı metin ve satır düzeni düzeltmesi',
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
            'corrective_resend_reason' => 'Hakediş mesajı metin ve satır düzeni düzeltmesi',
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
        $this->assertSame('Hakediş mesajı metin ve satır düzeni düzeltmesi', $corrective->force_resend_reason);
        $this->assertSame($snapshot['revision'], data_get($corrective->metadata, 'earning_snapshot_revision'));
        $this->assertSame($snapshot['snapshot_hash'], data_get($corrective->metadata, 'earning_snapshot_hash'));
        $this->assertSame(4, data_get($corrective->metadata, 'earning_message_contract_version'));
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
     * @return array{0: TechnicalServiceRequest, 1: TechnicalServiceRequest, 2: TechnicalServicePartRequest, 3: TechnicalServiceMountPayment}
     */
    private function paymentPartContextFixture(): array
    {
        [$root, $technician] = $this->assignmentFixture();
        $root->forceFill([
            'root_mrn' => $root->mrn,
            'service_code' => null,
        ])->save();
        $srv = TechnicalServiceRequest::query()->create([
            'mrn' => 'SRV-PAYMENT-167-CONTEXT',
            'root_mrn' => $root->mrn,
            'parent_request_id' => $root->id,
            'service_code' => 'SRV-PAYMENT-167-CONTEXT',
            'service_sequence' => 1,
            'customer_name' => $root->customer_name,
            'customer_phone' => $root->customer_phone,
            'customer_city' => $root->customer_city,
            'customer_district' => $root->customer_district,
            'service_address' => $root->service_address,
            'product_name' => $root->product_name,
            'serial_number' => $root->serial_number,
            'service_type' => 'Servis',
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ]);
        $partRequest = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $root->id,
            'root_request_id' => $root->id,
            'requested_by_technician_id' => $technician->id,
            'status' => TechnicalServicePartRequest::STATUS_SERVICE_VISIT_CREATED,
            'part_name' => 'REL-4E.15I Test Parça',
            'quantity' => 1,
            'requires_service_visit' => true,
            'service_visit_request_id' => $srv->id,
        ]);
        $payment = $this->paidMountPayment($root, 'PAYMENT-167-'.uniqid());
        $payment->forceFill([
            'amount' => 20,
            'paid_at' => '2026-08-07 07:04:50',
            'provider_payment_reference' => '37164237',
            'provider_transaction_reference' => '39067702',
            'raw_payload' => [
                'source' => 'operation_customer_charge',
                'purpose' => 'service_and_part_payment',
                'charge_type' => 'service_and_part_payment',
                'root_request_id' => $root->id,
                'part_request_id' => $partRequest->id,
                'service_amount' => 5,
                'part_amount' => 15,
                'total_amount' => 20,
            ],
        ])->save();
        $partRequest->forceFill(['metadata' => [
            'charge_decision' => 'chargeable',
            'service_amount' => 5,
            'part_amount' => 15,
            'total_amount' => 20,
            'customer_charge_payment_id' => $payment->id,
            'payment_id' => $payment->id,
            'payment_status' => TechnicalServiceMountPayment::STATUS_PAID,
            'provider_payment_reference' => '37164237',
            'provider_transaction_reference' => '39067702',
        ]])->save();

        return [$root->refresh(), $srv->refresh(), $partRequest->refresh(), $payment->refresh()];
    }

    private function serviceVisitForRoot(TechnicalServiceRequest $root, string $suffix): TechnicalServiceRequest
    {
        $sequence = (int) TechnicalServiceRequest::query()
            ->where('parent_request_id', $root->id)
            ->max('service_sequence') + 1;
        $code = 'SRV-'.$suffix.'-'.uniqid();

        return TechnicalServiceRequest::query()->create([
            'mrn' => $code,
            'root_mrn' => $root->mrn,
            'parent_request_id' => $root->id,
            'service_code' => $code,
            'service_sequence' => $sequence,
            'customer_name' => $root->customer_name,
            'customer_phone' => $root->customer_phone,
            'customer_city' => $root->customer_city,
            'customer_district' => $root->customer_district,
            'service_address' => $root->service_address,
            'product_name' => $root->product_name,
            'product_model' => $root->product_model,
            'serial_number' => $root->serial_number,
            'service_type' => 'Servis',
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ]);
    }

    /**
     * @return array{0: TechnicalServiceRequest, 1: TechnicalServiceTechnician, 2: B2BPartner}
     */
    private function assignmentFixture(): array
    {
        config(['services.partner_portal.public_url' => 'https://dashboard.test']);

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
        B2BPartnerCapability::query()->create([
            'partner_id' => $partner->id,
            'capability' => B2BPartner::TYPE_LOCKSMITH,
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
            'location_map_url' => 'https://www.google.com/maps/search/?api=1&query=37.000%2C35.321',
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
        $currentOffer = TechnicalServiceAssignmentOffer::query()
            ->where('technical_service_request_id', $request->id)
            ->whereIn('status', [
                TechnicalServiceAssignmentOffer::STATUS_SENT,
                TechnicalServiceAssignmentOffer::STATUS_ACCEPTED,
                TechnicalServiceAssignmentOffer::STATUS_REVISED,
            ])
            ->latest('id')
            ->first();
        $payload = [
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
        ];
        if ($currentOffer instanceof TechnicalServiceAssignmentOffer) {
            $payload['expected_earning_revision'] = app(TechnicalServiceWorkflowService::class)
                ->canonicalTechnicianEarningSnapshot($currentOffer)['revision'];
        }

        return $this->actingAs($user)->postJson("/api/technical-service/requests/{$request->id}/assign", $payload);
    }
}
