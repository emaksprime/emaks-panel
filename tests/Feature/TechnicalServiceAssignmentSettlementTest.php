<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\TechnicalServiceEarningPayment;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceSettlement;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            ->assertJsonPath('request.assignment_offer.dispatch_status', TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED);

        $this->assertDatabaseCount('technical_service_earning_payments', 0);
        $this->assertSame(1, TechnicalServiceMessageDispatch::query()->where('technical_service_request_id', $request->id)->count());
        $dispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->firstOrFail();
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
