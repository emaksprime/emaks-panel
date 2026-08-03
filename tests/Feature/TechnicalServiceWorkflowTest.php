<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\PageConfig;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceCustomerConfirmation;
use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningItem;
use App\Models\TechnicalServiceEarningsPeriod;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\TechnicalServiceRouteQuote;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\B2B\B2BPartnerPortalDataService;
use App\Services\Payments\FakePaymentProvider;
use App\Services\Payments\TechnicalServicePaymentProviderCredentialService;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use App\Services\TechnicalService\MikroInvoiceSerialsService;
use App\Services\TechnicalService\MountRequestSubmitService;
use App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter;
use App\Services\TechnicalService\TechnicalServicePartRequestService;
use App\Services\TechnicalService\TechnicalServicePaymentSettlementService;
use App\Services\TechnicalService\TechnicalServicePaymentStatusResolver;
use App\Services\TechnicalService\TechnicalServiceServiceVisitService;
use App\Services\TechnicalService\TechnicalServiceUiLabelService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\TechnicalServiceSyntheticDataFactory;
use Tests\TestCase;

class TechnicalServiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-05-09 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_workflow_service_initializes_legacy_status_and_computes_sla_and_next_action(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
        ], [
            'created_at' => CarbonImmutable::now()->subHours(30),
            'updated_at' => CarbonImmutable::now()->subHours(30),
        ]);

        $service = app(TechnicalServiceWorkflowService::class);
        $service->initializeRequest($request);

        $this->assertSame('Yeni Talep', $service->currentWorkflowStatus($request));
        $this->assertSame('Yeni', $request->status);
        $this->assertSame(TechnicalServiceWorkflowService::SLA_OVERDUE, $request->sla_status);
        $this->assertNotNull($request->sla_due_at);
        $this->assertNotEmpty($request->next_action);
    }

    public function test_workflow_service_rejects_invalid_transition(): void
    {
        $service = app(TechnicalServiceWorkflowService::class);

        $this->expectException(ValidationException::class);

        $service->assertTransitionAllowed('Yeni Talep', 'Tamamlandı');
    }

    public function test_schedule_endpoint_validates_time_and_updates_workflow_with_audit_log(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Müşteri Onayladı',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/schedule", [
                'scheduled_date' => '2026-05-10',
                'scheduled_time' => '25:99',
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/schedule", [
                'scheduled_date' => '2026-05-10',
                'scheduled_time' => '14:30',
                'note' => 'Müşteriyle teyit edildi',
            ])
            ->assertOk()
            ->assertJsonPath('request.scheduled_time', '14:30');

        $request->refresh();

        $this->assertNotNull($request->scheduled_at);
        $this->assertSame('2026-05-10', $request->scheduled_date?->toDateString());
        $this->assertDatabaseHas('technical_service_audit_logs', [
            'entity_type' => 'technical_service_request',
            'entity_id' => $request->id,
            'action_type' => 'schedule_updated',
            'user_id' => $user->id,
        ]);
    }

    public function test_appointment_time_attention_labels_are_computed_without_movement_statuses(): void
    {
        $service = app(TechnicalServiceWorkflowService::class);
        $activeRequest = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'scheduled_at' => CarbonImmutable::now()->subHour(),
            'scheduled_date' => CarbonImmutable::now()->toDateString(),
            'scheduled_time' => CarbonImmutable::now()->subHour()->format('H:i'),
        ]);
        $overdueRequest = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'scheduled_at' => CarbonImmutable::now()->subHours(13),
            'scheduled_date' => CarbonImmutable::now()->subHours(13)->toDateString(),
            'scheduled_time' => CarbonImmutable::now()->subHours(13)->format('H:i'),
        ]);
        $completedRequest = $this->technicalServiceRequest([
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => CarbonImmutable::now(),
            'scheduled_at' => CarbonImmutable::now()->subHours(13),
        ]);

        $activePayload = $service->serialize($activeRequest->fresh(), true);
        $overduePayload = $service->serialize($overdueRequest->fresh(), true);
        $completedPayload = $service->serialize($completedRequest->fresh(), true);

        $this->assertSame('Usta müşteride', data_get($activePayload, 'attention.attention_reason'));
        $this->assertSame(8, data_get($activePayload, 'attention.sort_priority'));
        $this->assertSame('İş kapanışı için usta ile iletişime geçin', data_get($overduePayload, 'attention.attention_reason'));
        $this->assertSame(1, data_get($overduePayload, 'attention.sort_priority'));
        $this->assertNotSame('Usta müşteride', data_get($completedPayload, 'attention.attention_reason'));
        $this->assertNotSame('İş kapanışı için usta ile iletişime geçin', data_get($completedPayload, 'attention.attention_reason'));
    }

    public function test_ops_presenter_marks_part_request_as_ops_action(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => CarbonImmutable::now(),
        ]);
        TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $request->id,
            'status' => TechnicalServicePartRequest::STATUS_OPS_REVIEW,
            'part_name' => 'Test parça',
            'quantity' => 1,
        ]);

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());
        $tagLabels = collect($state['display_tags'])->pluck('label')->all();

        $this->assertSame('ops', $state['action_owner']);
        $this->assertSame('high', $state['action_priority']);
        $this->assertTrue($state['requires_ops_action']);
        $this->assertFalse($state['requires_technician_action']);
        $this->assertSame('Parça talebi operasyon incelemesinde', $state['action_label']);
        $this->assertSame('Usta yedek parça talep etti. Operasyon karar vermeli.', $state['action_hint']);
        $this->assertSame('Operasyon', $state['action_owner_label']);
        $this->assertSame('part_or_repeat', $state['action_bucket']);
        $this->assertSame('warning', $state['card_tone']);
        $this->assertContains('ops_action', $state['action_filter_keys']);
        $this->assertContains('part_or_repeat', $state['action_filter_keys']);
        $this->assertIsInt($state['action_priority_score']);
        $this->assertContains('OPS aksiyonu: Parça talebi operasyon incelemesinde', $tagLabels);
        $this->assertSame('Parça talebi operasyon incelemesinde', TechnicalServicePartRequest::partnerLabelForStatus(TechnicalServicePartRequest::STATUS_OPS_REVIEW));
    }

    public function test_part_request_duplicate_is_rejected_for_technician_once(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Parça Talep Ustası',
            'phone' => '+905550001122',
            'city' => 'Sentetik Sehir 001',
            'district' => 'Seyhan',
            'is_active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technical_service_technician_id' => $technician->id,
        ]);
        $service = app(TechnicalServicePartRequestService::class);

        $service->createFromPartnerSupport($request, $this->adminUser(), $this->partnerJobAction($request, [
            'action' => TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
        ]), [
            'type' => 'spare_part',
            'description' => 'SyntheticPerson038 göbeği gerekiyor.',
            'product_name' => 'SyntheticPerson038 göbeği',
            'quantity' => 1,
        ]);

        $this->expectException(ValidationException::class);

        $service->createFromPartnerSupport($request->fresh(), $this->adminUser(), $this->partnerJobAction($request, [
            'action' => TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
        ]), [
            'type' => 'spare_part',
            'description' => 'Aynı parça tekrar istendi.',
            'product_name' => 'SyntheticPerson038 göbeği',
            'quantity' => 1,
        ]);
    }

    public function test_chargeable_part_payment_required_card_is_customer_owned_and_blocks_shipment(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => CarbonImmutable::now(),
        ]);
        $partRequest = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $request->id,
            'status' => TechnicalServicePartRequest::STATUS_APPROVED,
            'part_name' => 'Ücretli kart okuyucu',
            'quantity' => 1,
            'metadata' => [
                'charge_decision' => 'chargeable',
                'charge_status' => TechnicalServiceMountPayment::STATUS_PENDING,
                'part_amount' => 1250,
                'total_amount' => 1250,
            ],
        ]);

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());

        $this->assertSame('customer', $state['action_owner']);
        $this->assertSame('Müşteri parça ödemesi bekleniyor', $state['action_label']);
        $this->assertSame('Müşteri parça ödemesi bekleniyor', $state['display_action_label']);
        $this->assertContains('customer_waiting', $state['action_filter_keys']);
        $this->assertContains('part_or_repeat', $state['action_filter_keys']);
        $this->assertSame('info', $state['card_tone']);
        $this->assertSame('Müşteri parça ödemesi bekleniyor', $partRequest->fresh()->statusLabel());

        $this->expectException(ValidationException::class);

        app(TechnicalServicePartRequestService::class)->transition($partRequest->fresh(), TechnicalServicePartRequest::STATUS_SENT, $this->adminUser());
    }

    public function test_free_part_status_allows_shipping_without_payment_block(): void
    {
        $request = $this->technicalServiceRequest();
        $partRequest = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $request->id,
            'status' => TechnicalServicePartRequest::STATUS_APPROVED,
            'part_name' => 'Garanti parçası',
            'quantity' => 1,
            'metadata' => [
                'charge_decision' => 'free',
                'charge_status' => 'none',
            ],
        ]);

        $serialized = app(TechnicalServicePartRequestService::class)->serialize($partRequest->fresh());

        $this->assertFalse($serialized['is_payment_required']);
        $this->assertTrue($serialized['can_ship']);

        $updated = app(TechnicalServicePartRequestService::class)->transition($partRequest->fresh(), TechnicalServicePartRequest::STATUS_SENT, $this->adminUser());

        $this->assertSame(TechnicalServicePartRequest::STATUS_SENT, $updated->status);
        $this->assertNotNull($updated->sent_at);
    }

    public function test_part_shipped_card_is_technician_owned_in_part_or_repeat_filter(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => CarbonImmutable::now(),
        ]);
        TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $request->id,
            'status' => TechnicalServicePartRequest::STATUS_SENT,
            'part_name' => 'Gönderilen parça',
            'quantity' => 1,
        ]);

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());

        $this->assertSame('technician', $state['action_owner']);
        $this->assertSame('Parça gönderildi; usta teslim almalı', $state['action_label']);
        $this->assertSame('neutral', $state['card_tone']);
        $this->assertContains('technician_action', $state['action_filter_keys']);
        $this->assertContains('part_or_repeat', $state['action_filter_keys']);
    }

    public function test_part_received_status_is_ops_owned_for_repeat_service_decision(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => CarbonImmutable::now(),
        ]);
        TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $request->id,
            'status' => TechnicalServicePartRequest::STATUS_RECEIVED,
            'part_name' => 'Teslim alınan parça',
            'quantity' => 1,
        ]);

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());

        $this->assertSame('ops', $state['action_owner']);
        $this->assertSame('Parça teslim alındı; tekrar servis kararını verin', $state['action_label']);
        $this->assertSame('part_or_repeat', $state['action_bucket']);
        $this->assertContains('ops_action', $state['action_filter_keys']);
        $this->assertContains('part_or_repeat', $state['action_filter_keys']);
    }

    public function test_part_service_required_srv_created_flow_uses_canonical_code_and_parent_history(): void
    {
        CarbonImmutable::setTestNow('2026-06-16 09:30:00');
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-2606MP030001',
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'operation_control_payload' => [
                'door_photos_checked' => 'pending',
                'payment_checked' => 'pending',
            ],
        ]);
        $partRequest = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $request->id,
            'status' => TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED,
            'part_name' => 'Parça sonrası servis',
            'quantity' => 1,
            'requires_service_visit' => true,
        ]);

        $child = app(TechnicalServicePartRequestService::class)->createServiceVisit($partRequest->fresh(), $this->adminUser(), 'spare_part');

        $this->assertSame('SRV-2606MP030001-001', $child->mrn);
        $this->assertSame('MRN-2606MP030001', $child->root_mrn);
        $this->assertSame($request->id, $child->parent_request_id);
        $this->assertNull($child->operation_control_payload);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'part_request_srv_created',
            'title' => 'Parça sonrası servis oluşturuldu',
        ]);
        $this->assertSame(TechnicalServicePartRequest::STATUS_SERVICE_VISIT_CREATED, $partRequest->fresh()->status);
    }

    public function test_part_history_uses_turkish_labels_without_raw_status(): void
    {
        $this->assertSame('Parça tedarikte', TechnicalServicePartRequest::labelForStatus(TechnicalServicePartRequest::STATUS_ORDERED));
        $this->assertSame('Parça sonrası servis gerekli', TechnicalServicePartRequest::labelForStatus(TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED));
        $this->assertSame('Parça sonrası servis oluşturuldu', TechnicalServiceUiLabelService::actionLabel('part_request_srv_created'));
        $this->assertSame('Parça ödemesi alındı', TechnicalServiceUiLabelService::actionLabel('part_request_payment_paid'));
        $this->assertNotSame('part_request_srv_created', TechnicalServiceUiLabelService::actionLabel('part_request_srv_created'));
    }

    public function test_cancel_status_atomically_finishes_cancel_with_one_reason(): void
    {
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-CANCEL-REVIEW-FIRST',
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
        ]);

        $response = $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'İptal',
                'note' => 'Müşteri iptal istedi.',
            ])
            ->assertOk();

        $request->refresh();
        $this->assertNotNull($request->cancelled_at);
        $this->assertSame('İptal', $request->status);
        $this->assertSame('İptal', $request->workflow_status);
        $this->assertSame('Müşteri iptal istedi.', $request->cancellation_reason);
        $this->assertFalse((bool) $response->json('request.operational_state.is_cancellation_review'));
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'cancellation_confirmed',
            'title' => 'İş iptal edildi',
        ]);
    }

    public function test_srv_missing_photo_does_not_block_all_ops_actions(): void
    {
        $parent = $this->technicalServiceRequest([
            'mrn' => 'MRN-SRV-MISSING-PHOTO-PARENT',
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);
        $srv = $this->technicalServiceRequest([
            'mrn' => 'MRN-SRV-MISSING-PHOTO-CHILD',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_code' => 'SRV-MISSING-PHOTO-001',
            'service_type' => 'Servis',
            'workflow_status' => 'Eksik Bilgi / Fotoğraf Bekleyen',
            'status' => 'Devam Ediyor',
            'missing_info_reason' => 'Dış kapı fotoğrafı eksik.',
            'document_status' => 'bekleniyor',
            'photo_status' => 'bekleniyor',
        ]);

        $actions = app(TechnicalServiceWorkflowService::class)->allowedActionsFor($srv);

        $this->assertArrayHasKey('mark_missing_info', $actions);
        $this->assertArrayHasKey('missing_info_reviewed', $actions);
        $this->assertArrayHasKey('customer_called', $actions);
        $this->assertArrayHasKey('customer_unreachable', $actions);
        $this->assertArrayHasKey('cancel', $actions);
        $this->assertSame("SRV'yi İptal Et", $actions['cancel']['label']);
    }

    public function test_srv_missing_photo_can_be_marked_reviewed_by_ops(): void
    {
        $srv = $this->technicalServiceRequest([
            'mrn' => 'MRN-SRV-MISSING-PHOTO-REVIEWED',
            'service_code' => 'SRV-MISSING-PHOTO-002',
            'service_type' => 'Servis',
            'workflow_status' => 'Eksik Bilgi / Fotoğraf Bekleyen',
            'status' => 'Devam Ediyor',
            'missing_info_reason' => 'Fotoğraf yanlışlıkla eksik işaretlendi.',
            'document_status' => 'bekleniyor',
            'photo_status' => 'bekleniyor',
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$srv->id}/workflow", [
                'action' => 'missing_info_reviewed',
                'note' => 'Fotoğraf kontrol edildi.',
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Müşteri Aranacak');

        $srv->refresh();
        $this->assertNull($srv->missing_info_reason);
        $this->assertSame('tamam', $srv->document_status);
        $this->assertSame('tamam', $srv->photo_status);
        $actions = app(TechnicalServiceWorkflowService::class)->allowedActionsFor($srv->fresh());
        $this->assertArrayHasKey('cancel', $actions);
        $this->assertSame("SRV'yi İptal Et", $actions['cancel']['label']);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $srv->id,
            'event_type' => 'missing_info_reviewed',
            'title' => 'Eksik fotoğraf kontrol edildi',
        ]);
    }

    public function test_ops_can_cancel_active_srv_child_with_reason_without_cancelling_root_mrn(): void
    {
        $parent = $this->technicalServiceRequest([
            'mrn' => 'MRN-SRV-CANCEL-PARENT',
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);
        $srv = $this->technicalServiceRequest([
            'mrn' => 'MRN-SRV-CANCEL-CHILD',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_code' => 'SRV-CANCEL-001',
            'service_type' => 'Servis',
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'technician_approval_status' => 'onayladı',
        ]);
        $offer = TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $srv->id,
            'technical_service_technician_id' => null,
            'labor_amount' => 3000,
            'route_fee_amount' => 500,
            'total_amount' => 3500,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$srv->id}/status", [
                'status' => 'İptal',
                'note' => 'SRV yanlışlıkla açıldı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'İptal');

        $this->assertSame('Planlı', $parent->fresh()->workflow_status);
        $this->assertNull($parent->fresh()->cancelled_at);
        $this->assertNotNull($srv->fresh()->cancelled_at);
        $this->assertSame(TechnicalServiceAssignmentOffer::STATUS_CANCELLED, $offer->fresh()->status);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $srv->id,
            'event_type' => 'cancellation_confirmed',
            'title' => 'İş iptal edildi',
        ]);
    }

    public function test_completed_srv_cancel_is_blocked_with_clear_reason(): void
    {
        $srv = $this->technicalServiceRequest([
            'mrn' => 'MRN-SRV-COMPLETED-CANCEL-BLOCKED',
            'service_code' => 'SRV-CANCEL-002',
            'service_type' => 'Servis',
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now(),
        ]);

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$srv->id}/status", [
                'status' => 'İptal',
                'note' => 'Yanlışlıkla iptal denemesi.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('Tamamlandı', $srv->fresh()->workflow_status);
        $this->assertNull($srv->fresh()->cancelled_at);
    }

    public function test_confirming_cancel_review_finalizes_cancel_and_cancels_assignment_offer(): void
    {
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-CANCEL-REVIEW-CONFIRM',
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
        ]);
        $offer = TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => null,
            'labor_amount' => 3000,
            'route_fee_amount' => 500,
            'total_amount' => 3500,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);
        app(TechnicalServiceWorkflowService::class)->startCancellationReview(
            $request,
            ['note' => 'İptal incelemesi açıldı.'],
            $this->adminUser(),
        );

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'İptal',
                'note' => 'İptal onaylandı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', 'İptal')
            ->assertJsonPath('request.workflow_status', 'İptal')
            ->assertJsonPath('request.operational_state.ops_column', 'cancelled');

        $request->refresh();
        $this->assertNotNull($request->cancelled_at);
        $this->assertNull($request->pending_reason);
        $this->assertSame(TechnicalServiceAssignmentOffer::STATUS_CANCELLED, $offer->fresh()->status);
        $this->assertSame('excluded_from_payable', $offer->fresh()->metadata['cancellation_exclusion']['status'] ?? null);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'cancellation_confirmed',
            'title' => 'İş iptal edildi',
        ]);
    }

    public function test_reopen_from_cancel_review_requires_reason_and_keeps_original_mrn(): void
    {
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-CANCEL-REVIEW-REOPEN',
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
        ]);
        app(TechnicalServiceWorkflowService::class)->startCancellationReview(
            $request,
            ['note' => 'Yanlış iptal akışı.'],
            $this->adminUser(),
        );

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'Yeni',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reopen_reason');

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'Yeni',
                'reopen_reason' => 'Operasyon düzeltmesi',
                'reopen_note' => 'İptal talebi geri alındı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.mrn', 'MRN-CANCEL-REVIEW-REOPEN')
            ->assertJsonPath('request.status', 'Yeni')
            ->assertJsonPath('request.workflow_status', 'Yeni Talep')
            ->assertJsonPath('request.operational_state.is_cancellation_review', false);

        $request->refresh();
        $this->assertNull($request->cancelled_at);
        $this->assertNull($request->pending_reason);
        $this->assertSame('MRN-CANCEL-REVIEW-REOPEN', $request->mrn);
        $this->assertSame(1, (int) $request->reopen_count);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'technical_service_request_reopened',
        ]);
    }

    public function test_hidden_cancel_request_goes_to_cancelled_column_and_not_ops_action_filter(): void
    {
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-HIDDEN-CANCEL',
            'status' => 'İptal',
            'workflow_status' => 'İptal',
            'cancelled_at' => now(),
        ]);

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());

        $this->assertSame('cancelled', $state['ops_column']);
        $this->assertSame('completed', $state['dashboard_action_owner']);
        $this->assertSame('none', $state['action_owner']);
        $this->assertFalse($state['active_action_required']);
        $this->assertSame('muted', $state['card_tone']);
        $this->assertNotContains('ops_action', $state['action_filter_keys']);
        $this->assertNotContains('technician_action', $state['action_filter_keys']);
    }

    public function test_cancel_context_cancel_summary_current_stage_payload_for_cancelled_request(): void
    {
        $technicianFixture = TechnicalServiceSyntheticDataFactory::locksmith(47);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => $technicianFixture['name'],
            'phone' => $technicianFixture['phone_e164'],
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'SRV-CANCEL-CONTEXT-001',
            'root_mrn' => 'MRN-CANCEL-CONTEXT',
            'service_code' => 'SRV-CANCEL-CONTEXT-001',
            'customer_name' => 'Aslan Selamet',
            'customer_phone' => '+905121312313',
            'customer_city' => 'Niğde',
            'customer_district' => 'Bor',
            'product_name' => 'DDL720',
            'serial_number' => 'W720-CANCEL-CONTEXT',
            'activation_code' => '363183',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technicianFixture['name'],
            'scheduled_at' => CarbonImmutable::parse('2026-06-20 10:00:00'),
            'status' => 'İptal',
            'workflow_status' => 'İptal',
            'cancelled_at' => CarbonImmutable::parse('2026-06-19 11:00:00'),
            'cancellation_reason' => 'Müşteri iptal istedi.',
        ]);
        $request->events()->create([
            'event_type' => 'cancel',
            'title' => 'İptal Et',
            'note' => 'Müşteri iptal istedi.',
            'from_status' => 'Planlı',
            'to_status' => 'İptal',
            'author_user_id' => $this->adminUser()->id,
        ]);

        $this->actingAs($this->adminUser())
            ->getJson("/api/technical-service/requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('request.cancel_context.exists', true)
            ->assertJsonPath('request.cancel_context.is_cancelled', true)
            ->assertJsonPath('request.cancel_context.cancelled_code', 'SRV-CANCEL-CONTEXT-001')
            ->assertJsonPath('request.cancel_context.customer_name', 'Aslan Selamet')
            ->assertJsonPath('request.cancel_context.last_technician_name', $technicianFixture['name'])
            ->assertJsonPath('request.cancel_context.cancel_reason', 'Müşteri iptal istedi.')
            ->assertJsonPath('request.cancel_context.previous_stage_label', 'Servis atandı')
            ->assertJsonPath('request.cancel_context.current_stage_label', 'İptal edildi')
            ->assertJsonPath('request.cancel_context.earning_excluded', true)
            ->assertJsonPath('request.cancel_context.earning_excluded_label', 'İptal nedeniyle hakedişe dahil değil')
            ->assertJsonPath('request.cancel_context.summary', 'İş iptal edildi. Hakedişe dahil değil.');
    }

    public function test_reopened_request_payload_includes_previous_cancel_context(): void
    {
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-CANCEL-CONTEXT-REOPEN',
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
        ]);
        app(TechnicalServiceWorkflowService::class)->startCancellationReview(
            $request,
            ['note' => 'Yanlış iptal akışı.'],
            $this->adminUser(),
        );

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'Yeni',
                'reopen_reason' => 'Operasyon düzeltmesi',
                'reopen_note' => 'İptal talebi geri alındı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.cancel_context.exists', true)
            ->assertJsonPath('request.cancel_context.is_reopened', true)
            ->assertJsonPath('request.cancel_context.previous_cancelled_code', 'MRN-CANCEL-CONTEXT-REOPEN')
            ->assertJsonPath('request.cancel_context.current_stage_label', 'Yeniden açıldı')
            ->assertJsonPath('request.cancel_context.summary', 'Önceki iş MRN-CANCEL-CONTEXT-REOPEN iptal edildi. Şu an: Yeniden açıldı.');
    }

    public function test_request_detail_get_does_not_create_route_quotes(): void
    {
        $request = $this->technicalServiceRequest([
            'customer_city' => 'Sentetik Sehir 015',
            'customer_district' => 'Beylikova',
            'location_latitude' => 39.686,
            'location_longitude' => 31.205,
        ]);

        $beforeCount = TechnicalServiceRouteQuote::query()->count();

        $this->actingAs($this->adminUser())
            ->getJson("/api/technical-service/requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('request.id', $request->id);

        $this->assertSame($beforeCount, TechnicalServiceRouteQuote::query()->count());
    }

    public function test_cancel_context_does_not_fuzzy_link_unrelated_requests(): void
    {
        $this->technicalServiceRequest([
            'mrn' => 'SRV-CANCEL-CONTEXT-UNRELATED',
            'root_mrn' => 'MRN-CANCEL-CONTEXT-OTHER',
            'customer_name' => 'Aynı Müşteri',
            'serial_number' => 'SAME-SERIAL',
            'status' => 'İptal',
            'workflow_status' => 'İptal',
            'cancelled_at' => now(),
        ]);
        $active = $this->technicalServiceRequest([
            'mrn' => 'MRN-CANCEL-CONTEXT-ACTIVE',
            'customer_name' => 'Aynı Müşteri',
            'serial_number' => 'SAME-SERIAL',
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
        ]);

        $this->actingAs($this->adminUser())
            ->getJson("/api/technical-service/requests/{$active->id}")
            ->assertOk()
            ->assertJsonPath('request.cancel_context', null);
    }

    public function test_technician_rejected_action_overrides_generic_overdue_closure_action(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'reddedildi',
            'scheduled_at' => CarbonImmutable::now()->subHours(13),
            'scheduled_date' => CarbonImmutable::now()->subHours(13)->toDateString(),
            'scheduled_time' => CarbonImmutable::now()->subHours(13)->format('H:i'),
        ]);
        $this->partnerJobAction($request, [
            'action' => TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => [
                'reason' => 'time_not_suitable',
                'reason_label' => 'Zaman uygun değil',
            ],
            'note' => 'Uygun değil.',
        ]);

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());
        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame('Usta işi reddetti. Acil yeniden atama gerekli.', $state['attention_reason']);
        $this->assertSame('Usta işi reddetti. Acil yeniden atama gerekli.', $state['action_reason']);
        $this->assertSame('Usta işi reddetti', $state['action_title']);
        $this->assertSame(1, $state['sort_priority']);
        $this->assertSame('ops', $state['action_owner']);
        $this->assertSame('ops', $state['dashboard_action_owner']);
        $this->assertSame('critical', $state['action_priority']);
        $this->assertSame(1, $state['action_priority_score']);
        $this->assertSame('ops_action', $state['action_bucket']);
        $this->assertSame('danger', $state['card_tone']);
        $this->assertContains('ops_action', $state['action_filter_keys']);
        $this->assertContains('technician_rejected', $state['action_filter_keys']);
        $this->assertContains('reassignment_required', $state['action_filter_keys']);
        $this->assertNotSame('İş kapanışı için usta ile iletişime geçin', $state['attention_reason']);

        $this->assertSame('ops', data_get($payload, 'action_owner'));
        $this->assertSame(1, data_get($payload, 'action_priority'));
        $this->assertSame('ops_action', data_get($payload, 'action_bucket'));
        $this->assertSame('danger', data_get($payload, 'card_tone'));
        $this->assertSame('Usta işi reddetti', data_get($payload, 'action_title'));
        $this->assertSame('Usta işi reddetti. Acil yeniden atama gerekli.', data_get($payload, 'action_reason'));
        $this->assertContains('technician_rejected', data_get($payload, 'action_filter_keys'));
    }

    public function test_ops_presenter_marks_appointment_change_and_revisit_as_ops_actions(): void
    {
        foreach ([
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED => 'Usta randevu değişikliği istiyor',
            TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => 'Tekrar ziyaret talebi incelenmeli',
        ] as $action => $expectedLabel) {
            $request = $this->technicalServiceRequest([
                'status' => 'Randevulu',
                'workflow_status' => 'Planlı',
                'technician_approval_status' => 'onayladı',
                'technician_approved_at' => CarbonImmutable::now(),
            ]);
            $this->partnerJobAction($request, [
                'action' => $action,
                'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
                'payload' => [],
            ]);

            $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());

            $this->assertSame('ops', $state['action_owner']);
            $this->assertSame('high', $state['action_priority']);
            $this->assertTrue($state['requires_ops_action']);
            $this->assertSame($expectedLabel, $state['action_label']);
        }
    }

    public function test_ops_presenter_marks_appointment_confirmed_as_technician_action(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => CarbonImmutable::now(),
            'scheduled_at' => CarbonImmutable::now()->addDay(),
            'scheduled_date' => CarbonImmutable::now()->addDay()->toDateString(),
            'scheduled_time' => '14:00',
        ]);

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());

        $this->assertSame('technician', $state['action_owner']);
        $this->assertFalse($state['requires_ops_action']);
        $this->assertTrue($state['requires_technician_action']);
        $this->assertSame('Fotoğraf bekleniyor', $state['action_label']);
        $this->assertSame('Usta', $state['action_owner_label']);
        $this->assertSame('technician_action', $state['action_bucket']);
        $this->assertSame('neutral', $state['card_tone']);
        $this->assertContains('technician_action', $state['action_filter_keys']);
        $this->assertContains('scheduled', $state['action_filter_keys']);
    }

    public function test_ops_presenter_does_not_mark_customer_waiting_as_ops_action(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => CarbonImmutable::now(),
            'scheduled_at' => CarbonImmutable::now()->addDay(),
        ]);
        foreach (['before_photo', 'after_photo', 'warranty_document_photo'] as $fieldCode) {
            TechnicalServiceRequestUpload::query()->create([
                'technical_service_request_id' => $request->id,
                'category' => TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT,
                'field_code' => $fieldCode,
                'original_name' => $fieldCode.'.jpg',
                'path' => 'technical-service/test/'.$fieldCode.'.jpg',
                'mime' => 'image/jpeg',
                'size' => 100,
            ]);
        }

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());

        $this->assertSame('customer', $state['action_owner']);
        $this->assertFalse($state['requires_ops_action']);
        $this->assertFalse($state['requires_technician_action']);
        $this->assertTrue($state['requires_customer_action']);
        $this->assertSame('Müşteri onayı bekliyor', $state['action_label']);
        $this->assertSame('Müşteri', $state['action_owner_label']);
        $this->assertSame('customer_waiting', $state['action_bucket']);
        $this->assertSame('info', $state['card_tone']);
        $this->assertContains('customer_waiting', $state['action_filter_keys']);
    }

    public function test_action_owner_marks_completed_cards_as_closed(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => CarbonImmutable::now(),
        ]);

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());

        $this->assertSame('none', $state['action_owner']);
        $this->assertSame('completed', $state['dashboard_action_owner']);
        $this->assertSame('Kapalı', $state['action_owner_label']);
        $this->assertSame('completed', $state['action_bucket']);
        $this->assertSame('success', $state['card_tone']);
        $this->assertContains('completed', $state['action_filter_keys']);
    }

    public function test_ops_completion_final_control_approval_finalizes_draft_earning_snapshot_and_preserves_labor_route_total(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $request = $this->technicalServiceRequest([
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => CarbonImmutable::now(),
            'technician_payment_amount' => 1200,
            'travel_fee_amount' => 365.75,
            'operation_control_payload' => [
                'completed_earning_snapshot' => [
                    'labor_amount' => 1200,
                    'route_fee_amount' => 365.75,
                    'total_amount' => 1565.75,
                    'status' => null,
                    'payout_status' => 'draft',
                    'status_label' => 'Hakediş yok',
                    'payout_status_label' => 'Önerilen / taslak hakediş',
                ],
            ],
        ]);

        $finalized = app(TechnicalServiceWorkflowService::class)
            ->finalizeCompletedEarningSnapshotForOpsPayoutApproval($request->fresh(), $admin);
        $snapshot = $finalized->operation_control_payload['completed_earning_snapshot'];

        $this->assertSame('finalized', $snapshot['status']);
        $this->assertSame('Kesinleşti', $snapshot['status_label']);
        $this->assertSame('confirmed', $snapshot['payout_status']);
        $this->assertSame('Onaylanan usta hakedişi', $snapshot['payout_status_label']);
        $this->assertSame(1200, $snapshot['labor_amount']);
        $this->assertSame(365.75, $snapshot['route_fee_amount']);
        $this->assertSame(1565.75, $snapshot['total_amount']);
        $this->assertSame($admin->id, $snapshot['finalized_by_user_id']);
    }

    public function test_kanban_payload_and_source_include_ops_action_filter_contract(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => CarbonImmutable::now(),
        ]);
        $this->partnerJobAction($request, [
            'action' => TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => ['slot' => '14:00-15:00'],
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame('ops', data_get($payload, 'operational_state.action_owner'));
        $this->assertSame('high', data_get($payload, 'operational_state.action_priority'));
        $this->assertTrue(data_get($payload, 'operational_state.requires_ops_action'));
        $this->assertFalse(data_get($payload, 'operational_state.requires_technician_action'));
        $this->assertSame('Usta randevu önerdi', data_get($payload, 'operational_state.action_label'));
        $this->assertSame('ops', data_get($payload, 'action_owner'));
        $this->assertSame('Operasyon', data_get($payload, 'action_owner_label'));
        $this->assertIsInt(data_get($payload, 'action_priority'));
        $this->assertSame('ops_action', data_get($payload, 'action_bucket'));
        $this->assertSame('warning', data_get($payload, 'card_tone'));
        $this->assertContains('ops_action', data_get($payload, 'action_filter_keys'));

        $boardSource = file_get_contents(resource_path('js/components/technical-service/TechnicalServiceKanbanBoard.tsx')) ?: '';
        $cardSource = file_get_contents(resource_path('js/components/technical-service/TechnicalServiceKanbanCard.tsx')) ?: '';

        $this->assertStringContainsString('ACTION_FILTERS', $boardSource);
        $this->assertStringContainsString('requires_ops_action', $boardSource);
        $this->assertStringContainsString('OPS aksiyonu', $boardSource);
        $this->assertStringContainsString('Usta bekleniyor', $boardSource);
        $this->assertStringContainsString('Müşteri bekleniyor', $boardSource);
        $this->assertStringContainsString('Parça / tekrar servis', $boardSource);
        $this->assertStringContainsString('Planlı / randevulu', $boardSource);
        $this->assertStringContainsString('Tamamlanan', $boardSource);
        $this->assertStringContainsString('actionOwnerSortPriority', $boardSource);
        $this->assertStringContainsString('filteredColumns', $boardSource);
        $this->assertStringContainsString('cardTone', $cardSource);
        $this->assertStringContainsString('requires_ops_action', $cardSource);
        $this->assertStringContainsString('Sahip:', $cardSource);
    }

    public function test_appointment_approved_is_not_completed_in_canonical_state(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => CarbonImmutable::now(),
            'scheduled_at' => CarbonImmutable::now()->addDay(),
            'scheduled_date' => CarbonImmutable::now()->addDay()->toDateString(),
            'scheduled_time' => '14:00',
            'photo_status' => 'tamamlandı',
            'document_status' => 'tamamlandı',
            'checklist_status' => 'tamamlandı',
        ]);
        $this->partnerJobAction($request, [
            'action' => TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
            'payload' => ['slot' => '14:00-15:00'],
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertFalse(data_get($payload, 'operational_state.is_completed'));
        $this->assertSame('assigned', data_get($payload, 'operational_state.ops_column'));
        $this->assertSame('appointment_confirmed', data_get($payload, 'operational_state.partner_column'));
        $this->assertNotSame('Tamamlandı', data_get($payload, 'operational_state.display_action_label'));
        $this->assertNotContains('Tamamlandı', collect(data_get($payload, 'display_tags', []))->pluck('label'));
    }

    public function test_customer_approval_is_not_completed_without_completion_submission(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => CarbonImmutable::now(),
            'scheduled_at' => CarbonImmutable::now()->addDay(),
            'customer_closure_approval_status' => 'onaylandı',
            'customer_closure_approved_at' => CarbonImmutable::now(),
        ]);
        $request->customerConfirmations()->create([
            'token' => 'customer-approval-token-'.uniqid(),
            'status' => TechnicalServiceCustomerConfirmation::STATUS_APPROVED,
            'approved_at' => CarbonImmutable::now(),
            'payload' => [],
        ]);
        $this->partnerJobAction($request, [
            'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_CONFIRMED,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
            'payload' => [],
        ]);

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());

        $this->assertFalse($state['is_completed']);
        $this->assertSame('assigned', $state['ops_column']);
        $this->assertSame('appointment_confirmed', $state['partner_column']);
    }

    public function test_completion_submitted_goes_to_final_check_not_completed(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => CarbonImmutable::now(),
            'scheduled_at' => CarbonImmutable::now()->addDay(),
            'customer_closure_approval_status' => 'onaylandı',
            'customer_closure_approved_at' => CarbonImmutable::now(),
            'checklist_status' => 'tamamlandı',
        ]);
        foreach (['before_photo', 'after_photo', 'warranty_document_photo'] as $fieldCode) {
            TechnicalServiceRequestUpload::query()->create([
                'technical_service_request_id' => $request->id,
                'category' => TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT,
                'field_code' => $fieldCode,
                'original_name' => $fieldCode.'.jpg',
                'path' => 'technical-service/test/'.$fieldCode.'.jpg',
                'mime' => 'image/jpeg',
                'size' => 100,
            ]);
        }
        $this->partnerJobAction($request, [
            'action' => TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => ['ops_final_check_required' => true],
            'note' => 'İşlem tamamlandı, son kontrol bekler.',
        ]);

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());

        $this->assertFalse($state['is_completed']);
        $this->assertTrue($state['is_pending_final_check']);
        $this->assertSame('final_check', $state['ops_column']);
        $this->assertSame('final_check', $state['partner_column']);
        $this->assertSame('Son kontrol bekliyor', $state['display_action_label']);
    }

    public function test_resolved_completion_submission_does_not_force_final_check(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'PlanlÄ±',
            'technician_approval_status' => 'onayladÄ±',
            'technician_approved_at' => CarbonImmutable::now(),
            'scheduled_at' => CarbonImmutable::now()->addDay(),
            'scheduled_date' => CarbonImmutable::now()->addDay()->toDateString(),
            'scheduled_time' => '14:00',
        ]);
        $this->partnerJobAction($request, [
            'action' => TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
            'payload' => [
                'ops_final_check_required' => true,
                'resolved_by_reassignment' => true,
            ],
            'note' => 'Eski ziyaret tamamlamasÄ±.',
        ]);

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());

        $this->assertFalse($state['is_pending_final_check']);
        $this->assertSame('assigned', $state['ops_column']);
        $this->assertSame('appointment_confirmed', $state['partner_column']);
    }

    public function test_reopened_job_ignores_previous_completion_submission_for_final_check(): void
    {
        $completedAt = CarbonImmutable::now()->subHour();
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => CarbonImmutable::now()->subDay(),
            'scheduled_at' => CarbonImmutable::now()->addDay(),
            'scheduled_date' => CarbonImmutable::now()->addDay()->toDateString(),
            'scheduled_time' => '10:00',
            'completed_at' => $completedAt,
            'field_completed_at' => $completedAt,
            'technician_completed_at' => $completedAt,
            'reopened_at' => CarbonImmutable::now(),
            'customer_closure_approval_status' => 'onaylandı',
            'customer_closure_approved_at' => $completedAt,
        ]);
        $this->partnerJobAction($request, [
            'action' => TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
            'payload' => [
                'ops_final_check_required' => true,
                'ops_final_check' => [
                    'approved_at' => $completedAt->toIso8601String(),
                    'approved_by_user_id' => 1,
                ],
            ],
            'note' => 'Eski ziyaret tamamlaması.',
        ]);

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());

        $this->assertFalse($state['is_completed']);
        $this->assertFalse($state['is_pending_final_check']);
        $this->assertSame('assigned', $state['ops_column']);
        $this->assertSame('appointment_confirmed', $state['partner_column']);
        $this->assertSame('Fotoğraf eksik', $state['display_action_label']);
    }

    public function test_reopened_partner_job_treats_old_photos_and_otp_as_history_only(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Reopen Smoke Usta',
            'technician_type' => 'locksmith',
            'active' => true,
        ]);
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'REOPEN-HISTORY-'.uniqid(),
            'display_name' => 'Reopen History Partner',
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'is_primary' => true,
            'active' => true,
        ]);

        $completedAt = CarbonImmutable::now()->subHour();
        $reopenedAt = CarbonImmutable::now();
        $request = $this->technicalServiceRequest([
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'technical_service_technician_id' => $technician->id,
            'scheduled_at' => CarbonImmutable::now()->addDay(),
            'scheduled_date' => CarbonImmutable::now()->addDay()->toDateString(),
            'scheduled_time' => '10:00',
            'completed_at' => $completedAt,
            'field_completed_at' => $completedAt,
            'technician_completed_at' => $completedAt,
            'reopened_at' => $reopenedAt,
            'customer_closure_approval_status' => 'onaylandı',
            'customer_closure_approved_at' => $completedAt,
        ]);

        foreach (['before_photo', 'after_photo', 'warranty_document_photo'] as $fieldCode) {
            $upload = TechnicalServiceRequestUpload::query()->create([
                'technical_service_request_id' => $request->id,
                'field_code' => $fieldCode,
                'category' => TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT,
                'original_name' => $fieldCode.'.jpg',
                'path' => 'technical-service/old/'.$fieldCode.'.jpg',
                'mime' => 'image/jpeg',
                'size' => 1024,
                'review_status' => 'accepted',
            ]);
            $upload->forceFill([
                'created_at' => $completedAt,
                'updated_at' => $completedAt,
            ])->saveQuietly();
        }

        $confirmation = TechnicalServiceCustomerConfirmation::query()->create([
            'technical_service_request_id' => $request->id,
            'token' => 'old-token-'.uniqid(),
            'status' => TechnicalServiceCustomerConfirmation::STATUS_APPROVED,
            'approved_at' => $completedAt,
            'expires_at' => CarbonImmutable::now()->addDays(7),
            'payload' => [],
        ]);
        $confirmation->forceFill([
            'created_at' => $completedAt,
            'updated_at' => $completedAt,
        ])->saveQuietly();

        $oldOtpAction = TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $request->id,
            'partner_id' => $partner->id,
            'user_id' => User::factory()->create(['role_code' => 'b2b_locksmith'])->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
            'payload' => ['approval_url' => 'https://example.test/old-token'],
            'note' => 'Eski ziyaret onayı.',
        ]);
        $oldOtpAction->forceFill([
            'created_at' => $completedAt,
            'updated_at' => $completedAt,
        ])->saveQuietly();

        $summary = app(B2BPartnerPortalDataService::class)->safeServiceJobSummary($request->fresh(), $partner);

        $this->assertTrue($summary['can_upload_photos']);
        $this->assertTrue($summary['can_request_customer_otp']);
        $this->assertFalse($summary['completion_requirements']['photos_ready']);
        $this->assertFalse($summary['completion_requirements']['customer_confirmation_ready']);
        $this->assertSame(0, $summary['completion_requirements']['door_photos_uploaded']);
        $this->assertCount(0, $summary['photos']);
        $this->assertCount(3, $summary['previous_photos']);
        $this->assertNull($summary['customer_otp_request']);
        $this->assertNull($summary['customer_confirmation']);
        $this->assertSame('photo_waiting', $summary['action_state']);
    }

    public function test_applied_completion_with_ops_final_check_is_not_pending_final_check(): void
    {
        $completedAt = CarbonImmutable::now();
        $request = $this->technicalServiceRequest([
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => $completedAt,
            'field_completed_at' => $completedAt,
            'technician_completed_at' => $completedAt,
            'customer_closure_approval_status' => 'onaylandı',
            'customer_closure_approved_at' => $completedAt,
        ]);
        $this->partnerJobAction($request, [
            'action' => TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
            'payload' => [
                'ops_final_check_required' => true,
                'ops_final_check' => [
                    'approved_at' => $completedAt->toIso8601String(),
                    'approved_by_user_id' => 1,
                ],
            ],
        ]);

        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($request->fresh());

        $this->assertTrue($state['is_completed']);
        $this->assertFalse($state['is_pending_final_check']);
        $this->assertSame('completed', $state['ops_column']);
        $this->assertSame('completed', $state['partner_column']);
    }

    public function test_ops_final_complete_moves_ops_and_partner_to_completed_without_old_appointment_tag(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => CarbonImmutable::now(),
            'scheduled_at' => CarbonImmutable::now()->subDay(),
            'scheduled_date' => CarbonImmutable::now()->subDay()->toDateString(),
            'scheduled_time' => '10:00',
        ]);
        $this->partnerJobAction($request, [
            'action' => TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
            'payload' => ['slot' => '10:00-11:00'],
        ]);
        $request->events()->create([
            'event_type' => 'field_completed',
            'title' => 'Saha işi tamamlandı',
            'from_status' => 'Planlı',
            'to_status' => 'Tamamlandı',
            'metadata' => [],
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);
        $tagLabels = collect(data_get($payload, 'display_tags', []))->pluck('label')->all();

        $this->assertTrue(data_get($payload, 'operational_state.is_completed'));
        $this->assertSame('completed', data_get($payload, 'operational_state.ops_column'));
        $this->assertSame('completed', data_get($payload, 'operational_state.partner_column'));
        $this->assertContains('Tamamlandı', $tagLabels);
        $this->assertNotContains('Aksiyon: Randevu onaylandı', $tagLabels);
    }

    public function test_completed_mrn_freezes_assigned_locksmith_and_ops_completed_parent_payout_snapshot_on_completion(): void
    {
        $user = $this->adminUser();
        $technicianFixture = TechnicalServiceSyntheticDataFactory::locksmith(28);
        $newTechnicianFixture = TechnicalServiceSyntheticDataFactory::locksmith(29);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => $technicianFixture['name'],
            'phone' => $technicianFixture['phone_e164'],
            'city' => $technicianFixture['city'],
            'district' => 'Synthetic District A',
            'active' => true,
        ]);
        $newTechnician = TechnicalServiceTechnician::query()->create([
            'name' => $newTechnicianFixture['name'],
            'phone' => $newTechnicianFixture['phone_e164'],
            'city' => $newTechnicianFixture['city'],
            'district' => 'Synthetic District B',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-COMPLETED-SNAPSHOT',
            'status' => 'Sahada',
            'workflow_status' => 'Sahada',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'technician_payment_amount' => 1800,
            'travel_fee_amount' => 120,
            'before_photo_count' => 3,
            'after_photo_count' => 3,
            'general_photo_count' => 1,
            'document_status' => 'tamamlandı',
            'checklist_status' => 'tamamlandı',
            'customer_closure_approval_status' => 'onaylandı',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 1800,
            'route_fee_amount' => 120,
            'total_amount' => 1920,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/workflow", [
                'action' => 'complete',
                'note' => 'Saha işi tamamlandı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Tamamlandı')
            ->assertJsonPath('request.finance_summary.current_visit.completed_earning_snapshot.technician_name', $technicianFixture['name'])
            ->assertJsonPath('request.finance_summary.current_visit.locksmith_payout.total_amount', 1920)
            ->assertJsonPath('request.finance_summary.current_visit.locksmith_payout.payment_status_label', 'Hakediş ödeme kaydı yok');

        $completed = $request->fresh();
        $snapshot = $completed->operation_control_payload['completed_earning_snapshot'] ?? null;

        $this->assertIsArray($snapshot);
        $this->assertSame($technician->id, $snapshot['technical_service_technician_id']);
        $this->assertSame($technicianFixture['name'], $snapshot['technician_name']);
        $this->assertEquals(1800.0, $snapshot['labor_amount']);
        $this->assertEquals(120.0, $snapshot['route_fee_amount']);
        $this->assertEquals(1920.0, $snapshot['total_amount']);
        $this->assertSame('confirmed', $snapshot['payout_status']);
        $this->assertSame('not_recorded', $snapshot['payment_status']);

        $completed->forceFill([
            'technical_service_technician_id' => $newTechnician->id,
            'technician_name' => $newTechnician->name,
            'technician_payment_amount' => 99,
            'travel_fee_amount' => 1,
        ])->save();

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($completed->fresh(), true);

        $this->assertSame($technicianFixture['name'], data_get($payload, 'finance_summary.current_visit.technician_name'));
        $this->assertEquals(1800.0, data_get($payload, 'finance_summary.current_visit.locksmith_payout.labor_amount'));
        $this->assertEquals(120.0, data_get($payload, 'finance_summary.current_visit.locksmith_payout.route_fee_amount'));
        $this->assertEquals(1920.0, data_get($payload, 'finance_summary.current_visit.locksmith_payout.total_amount'));
        $this->assertSame('Hakediş ödeme kaydı yok', data_get($payload, 'finance_summary.current_visit.locksmith_payout.payment_status_label'));
    }

    public function test_earning_breakdown_rows_include_technician_name_for_parent_and_srv(): void
    {
        $user = $this->adminUser();
        $parentFixture = TechnicalServiceSyntheticDataFactory::locksmith(28);
        $childFixture = TechnicalServiceSyntheticDataFactory::locksmith(46);
        $parentTechnician = TechnicalServiceTechnician::query()->create([
            'name' => $parentFixture['name'],
            'phone' => $parentFixture['phone_e164'],
            'city' => $parentFixture['city'],
            'district' => 'Synthetic District A',
            'active' => true,
        ]);
        $childTechnician = TechnicalServiceTechnician::query()->create([
            'name' => $childFixture['name'],
            'phone' => $childFixture['phone_e164'],
            'city' => $childFixture['city'],
            'district' => 'Synthetic District B',
            'active' => true,
        ]);
        $parent = $this->technicalServiceRequest([
            'mrn' => 'MRN-EARNING-BREAKDOWN',
            'status' => 'Sahada',
            'workflow_status' => 'Sahada',
            'technical_service_technician_id' => $parentTechnician->id,
            'technician_name' => $parentTechnician->name,
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 0,
            'before_photo_count' => 3,
            'after_photo_count' => 3,
            'general_photo_count' => 1,
            'document_status' => 'tamamlandı',
            'checklist_status' => 'tamamlandı',
            'customer_closure_approval_status' => 'onaylandı',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $parent->id,
            'technical_service_technician_id' => $parentTechnician->id,
            'labor_amount' => 3000,
            'route_fee_amount' => 0,
            'total_amount' => 3000,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$parent->id}/workflow", [
                'action' => 'complete',
                'note' => 'Parent MRN tamamlandı.',
            ])
            ->assertOk();

        $child = $this->technicalServiceRequest([
            'mrn' => 'MRN-EARNING-BREAKDOWN-SRV',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-EARNING-BREAKDOWN-001',
            'service_visit_reason' => 'revisit',
            'status' => 'Planlı',
            'workflow_status' => 'Planlı',
            'technical_service_technician_id' => $childTechnician->id,
            'technician_name' => $childTechnician->name,
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 0,
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $child->id,
            'technical_service_technician_id' => $childTechnician->id,
            'labor_amount' => 3000,
            'route_fee_amount' => 0,
            'total_amount' => 3000,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($child->fresh(), true);
        $rows = collect(data_get($payload, 'earning_breakdown.rows'));
        $parentRow = $rows->firstWhere('id', $parent->id);
        $childRow = $rows->firstWhere('id', $child->id);

        $this->assertSame($parentFixture['name'], $parentRow['technician_name'] ?? null);
        $this->assertSame('completed_earning_snapshot', $parentRow['technician_source'] ?? null);
        $this->assertSame($childFixture['name'], $childRow['technician_name'] ?? null);
        $this->assertSame('assignment_offer', $childRow['technician_source'] ?? null);
        $this->assertTrue((bool) data_get($payload, 'earning_breakdown.root_total.is_multi_technician'));
        $this->assertSame([$parentFixture['name'], $childFixture['name']], data_get($payload, 'earning_breakdown.root_total.technician_names'));
        $this->assertEquals(6000.0, data_get($payload, 'earning_breakdown.root_total.total_amount'));
    }

    public function test_ops_index_serialization_avoids_finance_n_plus_one_queries(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Performans Ustası',
            'phone' => '+905551111199',
            'city' => 'Sentetik Sehir 021',
            'district' => 'Kadıköy',
            'active' => true,
        ]);
        $period = TechnicalServiceEarningsPeriod::query()->create([
            'year' => 2026,
            'month' => 6,
            'status' => 'Onaylandı',
            'calculated_at' => now(),
            'approved_at' => now(),
            'paid_at' => now(),
        ]);
        $earning = TechnicalServiceEarning::query()->create([
            'period_id' => $period->id,
            'technical_service_technician_id' => $technician->id,
            'technician_name_snapshot' => $technician->name,
            'city_snapshot' => 'Sentetik Sehir 021',
            'job_count' => 16,
            'installation_count' => 8,
            'service_count' => 8,
            'labor_total' => 24000,
            'travel_fee_total' => 800,
            'grand_total' => 24800,
            'status' => 'Onaylandı',
            'paid_at' => now(),
        ]);
        $listIds = collect();

        for ($index = 1; $index <= 8; $index++) {
            $parent = $this->technicalServiceRequest([
                'mrn' => 'MRN-PERF-NPLUS-'.$index,
                'service_type' => 'Montaj',
                'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
                'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
                'mount_payment_provider' => 'fake',
                'mount_payment_reference' => 'perf-parent-'.$index,
                'mount_payment_paid_at' => now(),
                'technical_service_technician_id' => $technician->id,
                'technician_name' => $technician->name,
                'technician_payment_amount' => 2000,
                'travel_fee_amount' => 100,
            ]);
            $parentSession = $this->mountSessionForRequest($parent);
            TechnicalServiceMountPayment::query()->create([
                'technical_service_mount_session_id' => $parentSession->id,
                'technical_service_request_id' => $parent->id,
                'provider' => 'fake',
                'provider_reference' => 'perf-parent-payment-'.$index,
                'status' => TechnicalServiceMountPayment::STATUS_PAID,
                'amount' => 3000,
                'currency' => 'TRY',
                'paid_at' => now(),
                'raw_payload' => ['source' => 'public_form_payment'],
            ]);
            TechnicalServiceAssignmentOffer::query()->create([
                'technical_service_request_id' => $parent->id,
                'technical_service_technician_id' => $technician->id,
                'labor_amount' => 2000,
                'route_fee_amount' => 100,
                'total_amount' => 2100,
                'currency' => 'TRY',
                'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
                'sent_at' => now(),
            ]);
            TechnicalServiceEarningItem::query()->create([
                'earning_id' => $earning->id,
                'technical_service_request_id' => $parent->id,
                'mrn' => $parent->mrn,
                'job_date' => now(),
                'customer_city' => 'Sentetik Sehir 021',
                'customer_district' => 'Kadıköy',
                'service_type' => 'Montaj',
                'product_name' => $parent->product_name,
                'serial_number' => $parent->serial_number,
                'labor_amount' => 2000,
                'travel_round_trip_km' => 0,
                'travel_billable_km' => 0,
                'travel_fee_amount' => 100,
                'line_total' => 2100,
            ]);

            $child = $this->technicalServiceRequest([
                'mrn' => 'SRV-PERF-NPLUS-'.$index,
                'parent_request_id' => $parent->id,
                'root_mrn' => $parent->mrn,
                'service_sequence' => 1,
                'service_code' => 'SRV-PERF-NPLUS-'.$index,
                'service_visit_reason' => 'revisit',
                'service_type' => 'Servis',
                'technical_service_technician_id' => $technician->id,
                'technician_name' => $technician->name,
                'technician_payment_amount' => 1000,
                'travel_fee_amount' => 100,
            ]);
            $childSession = $this->mountSessionForRequest($child);
            TechnicalServiceMountPayment::query()->create([
                'technical_service_mount_session_id' => $childSession->id,
                'technical_service_request_id' => $child->id,
                'provider' => 'fake',
                'provider_reference' => 'perf-child-charge-'.$index,
                'status' => TechnicalServiceMountPayment::STATUS_PAID,
                'amount' => 500,
                'currency' => 'TRY',
                'paid_at' => now(),
                'raw_payload' => [
                    'source' => 'operation_customer_charge',
                    'purpose' => 'service_payment',
                    'service_amount' => 500,
                    'part_amount' => 0,
                    'total_amount' => 500,
                ],
            ]);
            TechnicalServiceAssignmentOffer::query()->create([
                'technical_service_request_id' => $child->id,
                'technical_service_technician_id' => $technician->id,
                'labor_amount' => 1000,
                'route_fee_amount' => 100,
                'total_amount' => 1100,
                'currency' => 'TRY',
                'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
                'sent_at' => now(),
            ]);
            TechnicalServiceEarningItem::query()->create([
                'earning_id' => $earning->id,
                'technical_service_request_id' => $child->id,
                'mrn' => $child->mrn,
                'job_date' => now(),
                'customer_city' => 'Sentetik Sehir 021',
                'customer_district' => 'Kadıköy',
                'service_type' => 'Servis',
                'product_name' => $child->product_name,
                'serial_number' => $child->serial_number,
                'labor_amount' => 1000,
                'travel_round_trip_km' => 0,
                'travel_billable_km' => 0,
                'travel_fee_amount' => 100,
                'line_total' => 1100,
            ]);

            $listIds->push($child->id);
        }

        $items = TechnicalServiceRequest::query()
            ->whereIn('id', $listIds->all())
            ->orderBy('id')
            ->get();
        $service = app(TechnicalServiceWorkflowService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $service->preloadSerializationContext($items);
        $payloads = $items
            ->map(fn (TechnicalServiceRequest $request): array => $service->serialize($request))
            ->values();

        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(8, $payloads);
        $this->assertSame(3500.0, data_get($payloads->first(), 'finance_summary.root_total.customer_collection.total_amount'));
        $this->assertSame(3200.0, data_get($payloads->first(), 'earning_breakdown.root_total.total_amount'));
        $this->assertLessThan(220, $queries->count());
        $this->assertLessThanOrEqual(6, $queries->filter(fn (array $query): bool => str_contains($query['query'], 'technical_service_mount_payments'))->count());
        $this->assertLessThanOrEqual(2, $queries->filter(fn (array $query): bool => str_contains($query['query'], 'technical_service_earning_items'))->count());
        $this->assertLessThanOrEqual(8, $queries->filter(fn (array $query): bool => str_contains($query['query'], 'technical_service_requests') && str_contains($query['query'], 'parent_request_id'))->count());
    }

    public function test_dashboard_list_normalizes_legacy_question_mark_turkish_product_labels(): void
    {
        $admin = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-DASHBOARD-MOJIBAKE',
            'customer_city' => '?stanbul',
            'customer_district' => 'Kad?k?y',
            'product_name' => 'Acceptance Ak?ll? Kap? Kilidi',
            'product_model' => 'Kap? Model',
            'scheduled_at' => CarbonImmutable::now()->setTime(10, 0),
            'scheduled_date' => CarbonImmutable::now()->toDateString(),
            'scheduled_time' => '10:00',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/technical-service/operations-dashboard')
            ->assertOk();

        $item = collect($response->json('today_appointments'))->firstWhere('mrn', $request->mrn);

        $this->assertIsArray($item);
        $this->assertSame('Acceptance Akıllı Kapı Kilidi', $item['product_name']);
        $this->assertSame('Kapı Model', $item['product_model']);
        $this->assertSame('İstanbul', $item['customer_city']);
        $this->assertSame('Kadıköy', $item['customer_district']);
        $this->assertContains('İstanbul', collect($response->json('city_summary'))->pluck('city')->all());
        $this->assertNotContains('?stanbul', collect($response->json('city_summary'))->pluck('city')->all());

        $encoded = json_encode($item, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Ak?ll?', $encoded);
        $this->assertStringNotContainsString('Kap?', $encoded);
    }

    public function test_ops_index_serialization_keeps_display_labels_normalized_after_perf_preload(): void
    {
        $request = $this->technicalServiceRequest([
            'product_name' => 'Acceptance Ak?ll? Kap? Kilidi',
            'product_model' => 'Kap? Model',
        ]);

        $items = TechnicalServiceRequest::query()->whereKey($request->id)->get();
        $service = app(TechnicalServiceWorkflowService::class);
        $service->preloadSerializationContext($items);

        $payload = $service->serialize($items->first(), true);

        $this->assertSame('Acceptance Akıllı Kapı Kilidi', $payload['product_name']);
        $this->assertSame('Kapı Model', $payload['product_model']);
        $this->assertSame('Acceptance Akıllı Kapı Kilidi', $payload['product']['product_name']);
        $this->assertSame('Kapı Model', $payload['product']['product_model']);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Ak?ll?', $encoded);
        $this->assertStringNotContainsString('Kap?', $encoded);
    }

    public function test_workflow_endpoint_rejects_invalid_action_for_current_status(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/workflow", [
                'action' => 'complete',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('workflow_status');
    }

    public function test_assign_endpoint_allows_new_request_to_wait_for_technician_approval(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technician_name' => 'Test Usta',
                'travel_round_trip_km' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen');

        $request->refresh();

        $this->assertSame('Usta Onayı Bekleyen', $request->workflow_status);
        $this->assertSame('bekliyor', $request->technician_approval_status);
    }

    public function test_assign_endpoint_allows_customer_confirmation_pending_request_to_wait_for_technician_approval(): void
    {
        $user = $this->adminUser();
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Müşteri Onayı Ustası',
            'phone' => '+905551111113',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Müşteri Onayı Bekleyen',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        $quote = TechnicalServiceRouteQuote::query()->create([
            'technical_service_request_id' => $request->id,
            'technician_id' => $technician->id,
            'distance_meters' => 61330,
            'distance_km' => 61.33,
            'threshold_km' => 30,
            'extra_km' => 31.33,
            'fee_per_km' => 10,
            'fee_amount' => 313.3,
            'travel_fee_required' => true,
            'provider' => TechnicalServiceRouteQuote::PROVIDER_GOOGLE_ROUTES,
            'status' => TechnicalServiceRouteQuote::STATUS_CALCULATED,
            'raw_payload' => [
                'one_way_distance_meters' => 30660,
                'round_trip_distance_meters' => 61330,
            ],
            'calculated_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'route_quote_id' => $quote->id,
            ])
            ->assertOk()
            ->assertJsonPath('request.technical_service_technician_id', $technician->id)
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen')
            ->assertJsonPath('request.technician_approval_status', 'bekliyor')
            ->assertJsonPath('request.route_quote.id', $quote->id)
            ->assertJsonPath('request.route_quote.fee_amount', 313.3);

        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'technician_updated',
            'title' => 'Usta bilgisi güncellendi',
        ]);
    }

    public function test_assignment_save_rejects_completed_request(): void
    {
        $user = $this->adminUser();
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Kapalı İş Ustası',
            'phone' => '+905551111119',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'travel_round_trip_km' => 12,
                'labor_amount' => 1000,
                'travel_amount' => 100,
                'confirm_assignment' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $this->assertDatabaseMissing('technical_service_assignment_offers', [
            'technical_service_request_id' => $request->id,
        ]);
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
        ]);
    }

    public function test_mount_excluded_multi_product_assignment_does_not_require_hidden_acknowledgement(): void
    {
        $user = $this->adminUser();
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Çoklu Ürün Ustası',
            'phone' => '+905551111114',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Müşteri Onayı Bekleyen',
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        $this->addSelectedSerial($request, 'SN-MAIN');
        $this->addSelectedSerial($request, 'SN-SECOND', false);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'travel_round_trip_km' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('request.technical_service_technician_id', $technician->id)
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen')
            ->assertJsonPath('request.operation_control.mount_exclusion_acknowledgement.required', false);
    }

    public function test_mount_excluded_multi_product_assignment_persists_acknowledgement(): void
    {
        $user = $this->adminUser();
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Onaylı Çoklu Ürün Ustası',
            'phone' => '+905551111115',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Müşteri Onayı Bekleyen',
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        $this->addSelectedSerial($request, 'SN-MAIN');
        $this->addSelectedSerial($request, 'SN-SECOND', false);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'travel_round_trip_km' => 12,
                'mount_exclusion_acknowledged' => true,
                'mount_exclusion_note' => 'Çoklu ürün talebi, ödeme operasyon tarafından takip edilecek.',
            ])
            ->assertOk()
            ->assertJsonPath('request.technical_service_technician_id', $technician->id)
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen')
            ->assertJsonPath('request.operation_control.mount_exclusion_acknowledgement.acknowledged', true)
            ->assertJsonPath('request.operation_control.mount_exclusion_acknowledgement.note', 'Çoklu ürün talebi, ödeme operasyon tarafından takip edilecek.');

        $request->refresh();

        $this->assertTrue($request->operation_control_payload['mount_exclusion_acknowledgement']['acknowledged'] ?? false);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'mount_exclusion_acknowledged',
            'title' => 'Montaj hariç çoklu ürün operasyon onayı alındı.',
        ]);
    }

    public function test_mount_excluded_multi_product_assignment_skips_acknowledgement_when_payment_paid(): void
    {
        $user = $this->adminUser();
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Ödemeli Çoklu Ürün Ustası',
            'phone' => '+905551111116',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Müşteri Onayı Bekleyen',
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'mount_payment_provider' => 'fake',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        $this->addSelectedSerial($request, 'SN-MAIN');
        $this->addSelectedSerial($request, 'SN-SECOND', false);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'travel_round_trip_km' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('request.technical_service_technician_id', $technician->id)
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen')
            ->assertJsonPath('request.operation_control.mount_exclusion_acknowledgement.required', false)
            ->assertJsonPath('request.sale_and_payment.mount_payment_received', true);
    }

    public function test_payment_resolver_uses_paid_operation_payment_over_mikro_excluded_signal(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        $this->addSelectedSerial($request, 'SN-MAIN');
        $this->addSelectedSerial($request, 'SN-SECOND', false);
        $session = $this->mountSessionForRequest($request);

        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3000,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'reason' => 'route_fee',
            ],
        ]);

        $resolved = app(TechnicalServicePaymentStatusResolver::class)->resolve($request->fresh());
        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertTrue($resolved['is_paid']);
        $this->assertFalse($resolved['requires_payment']);
        $this->assertSame('operation_payment_link', $resolved['source']);
        $this->assertSame(3000.0, $resolved['amount']);
        $this->assertSame($payment->id, $resolved['latest_payment_id']);
        $this->assertTrue($payload['sale_and_payment']['mount_payment_received']);
        $this->assertTrue($payload['sale_and_payment']['payment_status']['is_paid']);
        $this->assertFalse($payload['operation_control']['mount_exclusion_acknowledgement']['required']);
        $this->assertSame([], $payload['assignment_blockers']['messages']);
    }

    public function test_customer_service_part_charge_payment_stays_separate_from_mount_payment(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'mount_payment_label' => 'Montaj ödemesi bekleniyor',
        ]);
        $session = $this->mountSessionForRequest($request);

        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-service-part-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 1250,
            'currency' => 'TRY',
            'raw_payload' => [
                'source' => 'operation_customer_charge',
                'purpose' => 'service_and_part_payment',
                'service_amount' => 1000,
                'part_amount' => 250,
                'total_amount' => 1250,
                'message_template' => 'Servis ve parça ödeme linki.',
            ],
        ]);

        app(TechnicalServicePaymentSettlementService::class)->markPaid($payment);

        $request->refresh();
        $session->refresh();
        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PENDING, $request->mount_payment_status);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PENDING, $session->mount_payment_status);
        $this->assertSame(1250.0, $payload['sale_and_payment']['customer_charges']['paid_total_amount']);
        $this->assertSame(1000.0, $payload['sale_and_payment']['customer_charges']['paid_service_amount']);
        $this->assertSame(250.0, $payload['sale_and_payment']['customer_charges']['paid_part_amount']);
        $this->assertSame('Ödendi', $payload['sale_and_payment']['customer_charges']['latest']['status_label']);
        $this->assertSame('Servis ve parça ödeme linki.', $payload['sale_and_payment']['customer_charges']['latest']['message_template']);
        $this->assertStringContainsString('Servis ve parça ödeme linki.', $payload['sale_and_payment']['customer_charges']['latest']['message_text']);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'customer_charge_paid',
        ]);
    }

    public function test_service_part_charge_paid_does_not_override_paid_mount_payment_summary(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'mount_payment_label' => 'Form üzerinden ödeme alındı',
            'mount_payment_provider' => 'fake',
            'mount_payment_reference' => 'fake-paid-3500',
            'mount_payment_paid_at' => now(),
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 500,
        ]);
        $session = $this->mountSessionForRequest($request);

        $mountPayment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-3500',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3500,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => ['source' => 'public_form_payment'],
        ]);

        $customerCharge = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-service-part-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 1750,
            'currency' => 'TRY',
            'raw_payload' => [
                'source' => 'operation_customer_charge',
                'purpose' => 'service_and_part_payment',
                'service_amount' => 1000,
                'part_amount' => 750,
                'total_amount' => 1750,
                'message_template' => 'Servis ve parça ödeme linki.',
            ],
        ]);

        app(TechnicalServicePaymentSettlementService::class)->markPaid($customerCharge);

        $resolved = app(TechnicalServicePaymentStatusResolver::class)->resolve($request->fresh());
        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame($mountPayment->id, $resolved['latest_payment_id']);
        $this->assertSame(3500.0, $resolved['amount']);
        $this->assertSame('public_form_payment', $resolved['source']);
        $this->assertSame(3500.0, $payload['sale_and_payment']['paid_amount']);
        $this->assertSame('3.500 TL', $payload['sale_and_payment']['paid_amount_label']);
        $this->assertSame(3500.0, $payload['sale_and_payment']['payment_summary']['mount']['amount']);
        $this->assertSame(1000.0, $payload['sale_and_payment']['payment_summary']['service']['amount']);
        $this->assertSame(750.0, $payload['sale_and_payment']['payment_summary']['part']['amount']);
        $this->assertSame(5250.0, $payload['sale_and_payment']['payment_summary']['total_customer_collection']);
        $this->assertSame('5.250 TL', $payload['sale_and_payment']['payment_summary']['total_customer_collection_label']);
        $this->assertSame(1750.0, $payload['sale_and_payment']['customer_charges']['paid_total_amount']);
        $this->assertSame(1000.0, $payload['service_customer_payment']);
        $this->assertSame(750.0, $payload['part_customer_payment']);
        $this->assertSame(5250.0, $payload['total_customer_collected']);
        $this->assertSame(1750.0, $payload['cost_delta']);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PAID, $request->fresh()->mount_payment_status);
        $this->assertSame('Form üzerinden ödeme alındı', $request->fresh()->mount_payment_label);
    }

    public function test_customer_collection_sums_multiple_paid_mount_payments_and_excludes_pending_links(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'mount_payment_label' => 'Montaj ödemesi bekleniyor',
        ]);
        $session = $this->mountSessionForRequest($request);

        $paidMainPayment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-mount-'.uniqid(),
            'provider_payment_reference' => 'provider-paid-1000',
            'provider_transaction_reference' => 'transaction-paid-1000',
            'provider_receipt_reference' => null,
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 1000,
            'currency' => 'TRY',
            'payment_url' => 'https://dashboard.emaksprime.com.tr/mount-payment/paid-main',
            'paid_at' => now(),
            'raw_payload' => [
                'source' => 'public_form_payment',
                'technical_service_request_id' => $request->id,
                'request_code' => $request->mrn,
                'serial_number' => $request->serial_number,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
            ],
        ]);

        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-extra-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 500,
            'currency' => 'TRY',
            'payment_url' => 'https://dashboard.emaksprime.com.tr/mount-payment/paid-extra',
            'paid_at' => now(),
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'purpose' => 'manual_mount_payment',
                'amount_source' => 'manual_ops_amount',
                'technical_service_request_id' => $request->id,
                'request_code' => $request->mrn,
                'serial_number' => $request->serial_number,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
            ],
        ]);

        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 700,
            'currency' => 'TRY',
            'payment_url' => 'https://dashboard.emaksprime.com.tr/mount-payment/pending-extra',
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'purpose' => 'manual_mount_payment',
                'amount_source' => 'manual_ops_amount',
                'technical_service_request_id' => $request->id,
                'request_code' => $request->mrn,
                'serial_number' => $request->serial_number,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
            ],
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame(1500.0, (float) $payload['sale_and_payment']['mount_payments']['paid_total_amount']);
        $this->assertSame(700.0, (float) $payload['sale_and_payment']['mount_payments']['pending_total_amount']);
        $this->assertCount(2, $payload['sale_and_payment']['mount_payments']['paid_rows']);
        $this->assertCount(1, $payload['sale_and_payment']['mount_payments']['pending_rows']);
        $this->assertSame(1500.0, (float) $payload['sale_and_payment']['payment_summary']['total_customer_collection']);
        $this->assertSame(1500.0, (float) $payload['finance_summary']['current_visit']['customer_collection']['total_amount']);
        $this->assertSame(1000.0, (float) $payload['finance_summary']['current_visit']['customer_collection']['mount_amount']);
        $this->assertSame(500.0, (float) $payload['finance_summary']['current_visit']['customer_collection']['extra_amount']);
        $this->assertSame(1500.0, (float) $payload['total_customer_collected']);

        $paidMainRow = collect($payload['sale_and_payment']['mount_payments']['paid_rows'])
            ->firstWhere('id', $paidMainPayment->id);

        $this->assertSame('provider-paid-1000', $paidMainRow['provider_payment_reference'] ?? null);
        $this->assertSame('transaction-paid-1000', $paidMainRow['provider_transaction_reference'] ?? null);
        $this->assertNull($paidMainRow['provider_receipt_reference'] ?? null);
    }

    public function test_payment_cancel_marks_pending_link_cancelled_and_excludes_it_from_pending_and_paid_totals(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'mount_payment_label' => 'Montaj ödemesi bekleniyor',
        ]);
        $session = $this->mountSessionForRequest($request);
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-pending-cancel-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 700,
            'currency' => 'TRY',
            'payment_url' => 'https://dashboard.emaksprime.com.tr/mount-payment/pending-cancel',
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'purpose' => 'manual_mount_payment',
                'amount_source' => 'manual_ops_amount',
                'technical_service_request_id' => $request->id,
                'request_code' => $request->mrn,
                'root_mrn' => $request->root_mrn,
                'serial_number' => $request->serial_number,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
            ],
        ]);

        $response = $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/cancel", [
                'reason' => 'OPS test iptali',
            ])
            ->assertOk();

        $payment->refresh();
        $payload = $response->json('request');

        $this->assertSame(TechnicalServiceMountPayment::STATUS_CANCELLED, $payment->status);
        $this->assertSame('OPS test iptali', $payment->raw_payload['cancellation_reason']);
        $this->assertNotEmpty($payment->raw_payload['cancelled_at']);
        $this->assertSame($request->mrn, $payment->raw_payload['request_code']);
        $this->assertSame($request->serial_number, $payment->raw_payload['serial_number']);
        $this->assertSame($request->customer_name, $payment->raw_payload['customer_name']);
        $this->assertSame($request->customer_phone, $payment->raw_payload['customer_phone']);
        $this->assertSame(0.0, (float) $payload['sale_and_payment']['mount_payments']['pending_total_amount']);
        $this->assertSame(0.0, (float) $payload['sale_and_payment']['mount_payments']['paid_total_amount']);
        $this->assertSame(700.0, (float) $payload['sale_and_payment']['mount_payments']['cancelled_total_amount']);
        $this->assertCount(0, $payload['sale_and_payment']['mount_payments']['pending_rows']);
        $this->assertCount(1, $payload['sale_and_payment']['mount_payments']['cancelled_rows']);
        $this->assertSame(0.0, (float) $payload['finance_summary']['current_visit']['customer_collection']['total_amount']);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_CANCELLED, $request->fresh()->mount_payment_status);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'mount_payment_link_cancelled',
        ]);
    }

    public function test_actual_cancel_caller_uses_manager_result_and_cannot_resave_concurrent_paid_state(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
        ]);
        $session = $this->mountSessionForRequest($request);
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'actual-cancel-race-reference',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 700,
            'currency' => 'TRY',
            'payment_url' => 'http://10.0.28.64:8000/mount-payment/actual-cancel-race-reference',
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'purpose' => 'manual_mount_payment',
                'technical_service_request_id' => $request->id,
                'request_code' => $request->mrn,
            ],
        ]);
        $provider = $this->partialMock(FakePaymentProvider::class, function ($mock) use ($payment): void {
            $mock->shouldReceive('cancelPayment')->once()->andReturnUsing(function () use ($payment): array {
                TechnicalServiceMountPayment::query()->whereKey($payment->id)->update([
                    'status' => TechnicalServiceMountPayment::STATUS_PAID,
                    'paid_at' => now(),
                    'provider_paid_confirmed_at' => now(),
                ]);

                return [
                    'provider_reference' => 'actual-cancel-race-reference',
                    'payment_url' => 'http://10.0.28.64:8000/mount-payment/actual-cancel-race-reference',
                    'status' => TechnicalServiceMountPayment::STATUS_PENDING,
                ];
            });
        });
        $this->app->instance(FakePaymentProvider::class, $provider);

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/cancel", [
                'reason' => 'Synthetic concurrent paid race',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $fresh = $payment->fresh();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $fresh->status);
        $this->assertNotNull($fresh->paid_at);
        $this->assertArrayNotHasKey('cancellation_reason', (array) $fresh->raw_payload);
        $this->assertSame(0, $request->events()->where('event_type', 'mount_payment_link_cancelled')->count());
    }

    public function test_cancelled_payment_link_remains_in_history_and_second_cancel_is_idempotent(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
        ]);
        $session = $this->mountSessionForRequest($request);
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'status' => TechnicalServiceMountPayment::STATUS_CANCELLED,
            'amount' => 400,
            'currency' => 'TRY',
            'payment_url' => 'https://dashboard.emaksprime.com.tr/mount-payment/cancelled-history',
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'request_code' => $request->mrn,
                'serial_number' => $request->serial_number,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'cancelled_at' => now()->toISOString(),
                'cancellation_reason' => 'Önceki iptal',
            ],
        ]);

        $response = $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/cancel")
            ->assertOk();

        $payload = $response->json('request');

        $this->assertSame(TechnicalServiceMountPayment::STATUS_CANCELLED, $payment->fresh()->status);
        $this->assertSame('Ödeme linki zaten iptal edilmiş.', $response->json('message'));
        $this->assertSame(400.0, (float) $payload['sale_and_payment']['mount_payments']['cancelled_total_amount']);
        $this->assertCount(1, $payload['sale_and_payment']['mount_payments']['cancelled_rows']);
        $this->assertSame($request->mrn, $payload['sale_and_payment']['mount_payments']['cancelled_rows'][0]['request_code']);
        $this->assertSame($request->serial_number, $payload['sale_and_payment']['mount_payments']['cancelled_rows'][0]['serial_number']);
    }

    public function test_cancelling_paid_payment_is_rejected_without_mutating_paid_total(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
        ]);
        $session = $this->mountSessionForRequest($request);
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-cancel-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 900,
            'currency' => 'TRY',
            'paid_at' => now(),
            'payment_url' => 'https://dashboard.emaksprime.com.tr/mount-payment/paid-no-cancel',
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'request_code' => $request->mrn,
                'serial_number' => $request->serial_number,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
            ],
        ]);

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(900.0, (float) $payload['sale_and_payment']['mount_payments']['paid_total_amount']);
        $this->assertSame(0.0, (float) $payload['sale_and_payment']['mount_payments']['pending_total_amount']);
        $this->assertSame(0.0, (float) $payload['sale_and_payment']['mount_payments']['cancelled_total_amount']);
    }

    public function test_extra_payment_multiple_payment_state_can_create_additional_pending_link_without_increasing_collected_total(): void
    {
        app(TechnicalServicePaymentProviderSettingsService::class)->update([
            'company_recipient' => [
                'company_address' => 'Test firma tahsilat adresi',
            ],
        ]);

        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'mount_payment_label' => 'Montaj ödemesi alındı',
            'mount_payment_provider' => 'fake',
            'mount_payment_reference' => 'fake-paid-1000',
            'mount_payment_paid_at' => now(),
        ]);
        $session = $this->mountSessionForRequest($request);
        $paidPayment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-1000',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 1000,
            'currency' => 'TRY',
            'payment_url' => 'https://dashboard.emaksprime.com.tr/mount-payment/paid-existing',
            'paid_at' => now(),
            'raw_payload' => [
                'source' => 'public_form_payment',
                'technical_service_request_id' => $request->id,
                'request_code' => $request->mrn,
                'serial_number' => $request->serial_number,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
            ],
        ]);

        $response = $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/payments/mount-extra-payment", [
                'amount' => 450,
                'currency' => 'TRY',
                'purpose' => 'manual_mount_payment',
                'reason' => 'manual_extra',
                'note' => 'Ek ödeme linki',
            ])
            ->assertCreated();

        $pendingPaymentId = $response->json('payment.id');
        $pendingPayment = TechnicalServiceMountPayment::query()->findOrFail($pendingPaymentId);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $pendingPayment->status);
        $this->assertSame($request->id, $pendingPayment->technical_service_request_id);
        $this->assertSame('operation_extra_mount_fee', $pendingPayment->raw_payload['source']);
        $this->assertSame($request->mrn, $pendingPayment->raw_payload['request_code']);
        $this->assertSame($request->serial_number, $pendingPayment->raw_payload['serial_number']);
        $this->assertSame($request->customer_name, $pendingPayment->raw_payload['customer_name']);
        $this->assertSame($request->customer_phone, $pendingPayment->raw_payload['customer_phone']);
        $this->assertSame('Test firma tahsilat adresi', $pendingPayment->raw_payload['payment_recipient']['company_address']);
        $this->assertSame('technical_service_payment_provider_settings', $pendingPayment->raw_payload['payment_recipient_address_source']);
        $this->assertSame('service_address', $pendingPayment->raw_payload['customer_address_role']);

        $payload = $response->json('request');

        $this->assertSame(1000.0, (float) $payload['sale_and_payment']['mount_payments']['paid_total_amount']);
        $this->assertSame(450.0, (float) $payload['sale_and_payment']['mount_payments']['pending_total_amount']);
        $this->assertSame(1000.0, (float) $payload['sale_and_payment']['payment_summary']['total_customer_collection']);
        $this->assertSame($paidPayment->id, $payload['sale_and_payment']['mount_payments']['latest_paid']['id']);
        $this->assertSame($pendingPayment->id, $payload['sale_and_payment']['mount_payments']['latest_pending']['id']);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $paidPayment->fresh()->status);
    }

    public function test_payment_recipient_company_address_is_used_and_customer_address_stays_service_address(): void
    {
        app(TechnicalServicePaymentProviderSettingsService::class)->update([
            'company_recipient' => [
                'company_title' => 'EMAKS Test Ltd.',
                'company_address' => 'Firma tahsilat test adresi',
                'tax_number' => '1111111111',
            ],
        ]);

        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'service_address' => 'Müşteri servis adresi',
        ]);
        $this->mountSessionForRequest($request);

        $response = $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/payments/mount-extra-payment", [
                'amount' => 140,
                'currency' => 'TRY',
                'purpose' => 'manual_mount_payment',
                'reason' => 'manual_extra',
            ])
            ->assertCreated();

        $payment = TechnicalServiceMountPayment::query()->findOrFail($response->json('payment.id'));

        $this->assertSame('Firma tahsilat test adresi', $payment->raw_payload['payment_recipient']['company_address']);
        $this->assertSame('1111111111', $payment->raw_payload['payment_recipient']['tax_number']);
        $this->assertSame('technical_service_payment_provider_settings', $payment->raw_payload['payment_recipient_address_source']);
        $this->assertSame('Müşteri servis adresi', $payment->raw_payload['customer_service_address']);
        $this->assertSame('service_address', $payment->raw_payload['customer_address_role']);
    }

    public function test_missing_company_address_blocks_real_payment_link_before_provider_call(): void
    {
        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('sandbox', 'TEST_SANDBOX_API_KEY', 'TEST_SANDBOX_SECRET_KEY', $this->adminUser());
        app(TechnicalServicePaymentProviderSettingsService::class)->update([
            'real_provider_enabled' => true,
            'provider_mode' => 'sandbox',
        ]);
        Http::fake();

        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
        ]);
        $this->mountSessionForRequest($request);

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/payments/mount-extra-payment", [
                'amount' => 140,
                'currency' => 'TRY',
                'purpose' => 'manual_mount_payment',
                'reason' => 'manual_extra',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_address'])
            ->assertJsonFragment([
                'company_address' => [TechnicalServicePaymentProviderSettingsService::COMPANY_RECIPIENT_ADDRESS_MISSING_MESSAGE],
            ]);

        Http::assertNothingSent();
        $this->assertSame(0, TechnicalServiceMountPayment::query()->count());
    }

    public function test_missing_paid_payment_falls_back_safely_without_fake_collection(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'mount_payment_label' => 'Montaj ödemesi alındı',
            'service_type' => 'Montaj',
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 120,
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame('Ödendi', $payload['sale_and_payment']['payment_status_label']);
        $this->assertNull($payload['sale_and_payment']['paid_amount']);
        $this->assertNull($payload['sale_and_payment']['payment_summary']['mount']['amount']);
        $this->assertNull($payload['sale_and_payment']['payment_summary']['total_customer_collection']);
        $this->assertFalse($payload['sale_and_payment']['payment_summary']['has_mount_collection']);
        $this->assertSame(3000.0, $payload['customer_fee']);
        $this->assertNull($payload['total_customer_collected']);
        $this->assertNull($payload['cost_delta']);
        $this->assertSame(0.0, $payload['finance_summary']['current_visit']['customer_collection']['total_amount']);
        $this->assertFalse($payload['finance_summary']['current_visit']['customer_collection']['has_collection']);
    }

    public function test_srv_code_uses_root_mrn_body_and_sequence(): void
    {
        $parent = $this->technicalServiceRequest([
            'mrn' => 'MRN-2606MP030001',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);

        $child = app(TechnicalServiceServiceVisitService::class)
            ->createServiceVisitFromRequest($parent, $this->adminUser(), 'revisit', [
                'copy_operation_control' => false,
            ]);

        $this->assertSame('SRV-2606MP030001-001', $child->mrn);
        $this->assertSame('SRV-2606MP030001-001', $child->service_code);
        $this->assertSame(1, $child->service_sequence);
    }

    public function test_second_srv_increments_sequence(): void
    {
        $parent = $this->technicalServiceRequest(['mrn' => 'MRN-2606MP030001']);
        $service = app(TechnicalServiceServiceVisitService::class);

        $first = $service->createServiceVisitFromRequest($parent, $this->adminUser(), 'revisit');
        $second = $service->createServiceVisitFromRequest($parent->fresh(), $this->adminUser(), 'revisit');

        $this->assertSame('SRV-2606MP030001-001', $first->service_code);
        $this->assertSame('SRV-2606MP030001-002', $second->service_code);
        $this->assertSame(2, $second->service_sequence);
    }

    public function test_srv_child_mrn_is_unique(): void
    {
        $parent = $this->technicalServiceRequest(['mrn' => 'MRN-2606MP030001']);
        $this->technicalServiceRequest([
            'mrn' => 'SRV-2606MP030001-001',
            'service_code' => 'SRV-2606MP030001-001',
            'root_mrn' => $parent->mrn,
            'service_sequence' => null,
        ]);

        $child = app(TechnicalServiceServiceVisitService::class)
            ->createServiceVisitFromRequest($parent, $this->adminUser(), 'revisit');

        $this->assertSame('SRV-2606MP030001-002', $child->mrn);
        $this->assertSame('SRV-2606MP030001-002', $child->service_code);
    }

    public function test_srv_child_keeps_root_mrn(): void
    {
        $parent = $this->technicalServiceRequest(['mrn' => 'MRN-2606MP030001']);

        $child = app(TechnicalServiceServiceVisitService::class)
            ->createServiceVisitFromRequest($parent, $this->adminUser(), 'revisit');

        $this->assertSame($parent->id, $child->parent_request_id);
        $this->assertSame('MRN-2606MP030001', $child->root_mrn);
    }

    public function test_srv_child_does_not_inherit_parent_completion_gate(): void
    {
        $parent = $this->technicalServiceRequest([
            'mrn' => 'MRN-2606MP030001',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
                'completed_earning_snapshot' => ['total_amount' => 3000],
            ],
            'operation_control_checked_at' => now(),
        ]);

        $child = app(TechnicalServiceServiceVisitService::class)
            ->createServiceVisitFromRequest($parent, $this->adminUser(), 'revisit', [
                'copy_operation_control' => false,
            ]);

        $this->assertNull($child->operation_control_payload);
        $this->assertNull($child->operation_control_checked_at);
    }

    public function test_existing_srv_values_are_not_migrated(): void
    {
        $legacy = $this->technicalServiceRequest([
            'mrn' => 'SRV-LEGACY-OLD-1',
            'service_code' => 'SRV-LEGACY-OLD-1',
            'root_mrn' => 'MRN-LEGACY-OLD',
            'service_sequence' => 1,
        ]);
        $parent = $this->technicalServiceRequest(['mrn' => 'MRN-2606MP030001']);

        app(TechnicalServiceServiceVisitService::class)
            ->createServiceVisitFromRequest($parent, $this->adminUser(), 'revisit');

        $this->assertSame('SRV-LEGACY-OLD-1', $legacy->fresh()->mrn);
        $this->assertSame('SRV-LEGACY-OLD-1', $legacy->fresh()->service_code);
    }

    public function test_presenter_normalizes_legacy_mojibake_system_messages(): void
    {
        $legacy = 'Partner portal tamamlama gÃƒÂ¶nderimi operasyon tarafÃ„Â±ndan onaylandÃ„Â±.';

        $this->assertSame(
            'Partner portal tamamlama gönderimi operasyon tarafından onaylandı.',
            TechnicalServiceUiLabelService::cleanDisplayText($legacy)
        );
    }

    public function test_ops_service_part_payment_link_button_is_rendered_as_action(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('data-testid="service-part-payment-action"', $source);
        $this->assertStringContainsString('aria-label="Servis/parça ödeme linki oluştur"', $source);
        $this->assertStringContainsString('Servis/parça ödeme linki oluştur', $source);
        $this->assertStringContainsString('onClick={openCustomerChargeModal}', $source);
        $this->assertStringContainsString('const customerChargeModal = customerChargeModalOpen', $source);
        $this->assertStringContainsString('{customerChargeModal}', $source);
        $this->assertStringContainsString('Mesaj metnini kopyala', $source);
        $this->assertStringContainsString('WhatsApp mesajını aç', $source);
        $this->assertStringContainsString('const renderPaymentLinkSendAction', $source);
        $this->assertGreaterThanOrEqual(6, substr_count($source, 'renderPaymentLinkSendAction('));
        $this->assertMatchesRegularExpression('/renderPaymentLinkSendAction\\(\\s*latestCustomerCharge\\s*,?\\s*\\)/', $source);
        $this->assertMatchesRegularExpression('/renderPaymentLinkSendAction\\(\\s*extraMountPayment\\s*,?\\s*\\)/', $source);
        $this->assertStringContainsString('const partRequestPaymentId = partRequest.payment_id ?? partRequest.customer_charge?.id ?? null', $source);
        $this->assertStringContainsString('id: partRequestPaymentId', $source);
        $this->assertStringContainsString('Servis ödemesi', $source);
        $this->assertStringContainsString('Parça ödemesi', $source);
        $this->assertStringContainsString('Müşteriden alınan servis ücreti', $source);
        $this->assertStringContainsString('Müşteriden alınan parça ücreti', $source);
        $this->assertSame(1, substr_count($source, 'aria-label="Servis/parça ödeme linki oluştur"'));

        $actionPosition = strpos($source, 'data-testid="service-part-payment-action"');
        $operationPanelPosition = strpos($source, "'Operasyon ve Montaj Kontrolü'");

        $this->assertNotFalse($actionPosition);
        $this->assertNotFalse($operationPanelPosition);
        $this->assertLessThan($operationPanelPosition, $actionPosition);
    }

    public function test_ops_mount_payment_link_primary_action_opens_modal_before_backend_call(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('const handleCreatePaymentLinkAction = () =>', $source);
        $this->assertStringContainsString('openPaymentLinkModal()', $source);
        $this->assertStringContainsString("if (action === 'create_payment_link')", $source);
        $this->assertStringContainsString('void handleCreatePaymentLinkAction()', $source);
        $this->assertStringNotContainsString('await handleExtraPaymentCreate()', $source);
        $this->assertStringContainsString('İlk tıklama sadece bu pencereyi açar; ödeme linki yalnızca tutar onaylandıktan sonra oluşturulur.', $source);
        $this->assertStringContainsString('Ödeme Al', $source);
        $this->assertStringContainsString('Ödeme linki tutarı', $source);
    }

    public function test_payment_source_for_mount_payment_link_is_explicit_and_not_route_fee_default(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $compactSource = preg_replace('/\s+/', ' ', $source) ?? $source;

        $this->assertStringContainsString('const existingPendingPaymentAmount = typeof latestPendingMountPayment?.amount', $compactSource);
        $this->assertStringContainsString('const extraPayment = existingPendingPaymentAmountInput', $compactSource);
        $this->assertStringContainsString('const paymentAmount = existingPendingPaymentAmountInput', $compactSource);
        $this->assertStringContainsString('Tutar kaynağı: Manuel giriş gerekli', $source);
        $this->assertStringContainsString('Tutar kaynağı: Mevcut ödeme kaydı', $source);
        $this->assertStringContainsString('Tutar kaynağı: Operasyon manuel girişi', $source);
        $this->assertStringContainsString('Ödeme tutarı net değil. Link oluşturmak için tutar girin.', $source);
        $this->assertStringNotContainsString('const extraPayment = hasRouteCostEvidence ? numericInputValue(routeFeeAmount) :', $source);
    }

    public function test_ops_detail_hides_legacy_control_blocks_by_default(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $compactSource = preg_replace('/\s+/', ' ', $source) ?? $source;
        $this->assertStringContainsString('showMountExcludedApprovalBlock', $source);
        $this->assertStringContainsString('showAddressControlBlock', $source);
        $this->assertStringContainsString('mountExclusionAckRequired && showMountExcludedApprovalBlock', $compactSource);
        $this->assertStringContainsString('{showPaymentControl ? (', $compactSource);
        $this->assertStringNotContainsString('showPaymentControl && showPaymentMountControlBlock', $source);
        $this->assertStringContainsString('showAddressControl && showAddressControlBlock', $compactSource);
        $this->assertStringContainsString('show_mount_excluded_approval_block: false', $source);
        $this->assertStringContainsString('show_payment_mount_control_block: false', $source);
        $this->assertStringContainsString('show_address_control_block: false', $source);
    }

    public function test_assignment_section_shows_customer_pays_technician_compact_card(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('shouldShowCustomerPaysTechnicianCard', $source);
        $this->assertStringContainsString('Ödeme şirket tarafından alındı.', $source);
        $this->assertStringContainsString('Dış ödeme alındı.', $source);
        $this->assertStringContainsString('Ödeme müşteriden ustaya yapılacak.', $source);
        $this->assertStringContainsString('Online ödeme linki bekliyor.', $source);
        $this->assertStringContainsString('Ödeme alınmadan müşteri tahsilatı sayılmaz', $source);
        $this->assertStringContainsString('şirketin kalan ödemesi hakediş mutabakatında takip edilir', $source);
        $this->assertStringContainsString('Müşteriye bildirilecek tutar', $source);
        $this->assertStringContainsString('Şirket ödemesi', $source);
        $this->assertStringContainsString('data-testid="bottom-payment-link-action"', $source);
        $this->assertStringContainsString('onClick={handleBottomPaymentLinkAction}', $source);
    }

    public function test_payment_action_label_renders_bottom_bar_when_payment_management_is_relevant(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;
        $this->assertStringContainsString('shouldShowFooterPaymentLinkAction', $source);
        $this->assertStringContainsString('data-testid="bottom-payment-link-action"', $source);
        $this->assertStringContainsString("paidOnlinePaymentLink||pendingOnlinePaymentLink?'default':'outline'", $compactSource);
        $this->assertStringContainsString('Ödeme Düzenle', $source);
        $this->assertStringContainsString('Ödeme Al', $source);
        $this->assertStringContainsString('paymentActionRelevantByWorkflow', $source);
        $this->assertStringContainsString('hasPaymentManagementContext', $source);
        $this->assertStringNotContainsString('Ödeme alındı; bu fazda ödenmiş link düzenlenmez.', $source);
    }

    public function test_payment_link_payment_modal_bottom_action_opens_without_backend_create(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;
        $this->assertStringContainsString('const handleBottomPaymentLinkAction = () =>', $source);
        $this->assertStringContainsString('openPaymentLinkModal()', $source);
        $this->assertStringContainsString('onClick={handleBottomPaymentLinkAction}', $source);
        $this->assertStringContainsString('paymentLinkEditorModal', $source);
        $this->assertStringContainsString("routeFeeEditorOpen&&routeFeeEditorMode==='payment_link'", $compactSource);
        $this->assertStringContainsString("routeFeeEditorOpen&&routeFeeEditorMode!=='payment_link'", $compactSource);
        $bottomHandler = substr($source, strpos($source, 'const handleBottomPaymentLinkAction = () =>'), 260);
        $this->assertStringNotContainsString('handleExtraPaymentCreate', $bottomHandler);
    }

    public function test_payment_link_copy_button_uses_copy_url_and_clipboard_fallback(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));
        $clipboardSource = file_get_contents(resource_path('js/lib/clipboard.ts'));

        $this->assertIsString($source);
        $this->assertIsString($clipboardSource);
        $this->assertStringContainsString("import { copyTextToClipboard } from '@/lib/clipboard'", $source);
        $this->assertStringContainsString('function paymentLinkCopyUrl(', $source);
        $this->assertStringContainsString('payment?.copy_url ?? payment?.payment_url', $source);
        $this->assertStringContainsString('function copyTextWithTextarea(text: string): boolean', $clipboardSource);
        $this->assertStringContainsString('async function clipboardMatchesText(text: string): Promise<boolean | null>', $clipboardSource);
        $this->assertStringContainsString('navigator.clipboard?.writeText', $clipboardSource);
        $this->assertStringContainsString("document.execCommand('copy')", $clipboardSource);
        $this->assertStringContainsString('textarea.setSelectionRange(0, textarea.value.length)', $clipboardSource);
        $this->assertStringContainsString('if (verification !== false)', $clipboardSource);
        $this->assertStringContainsString('if (textareaVerification === true)', $clipboardSource);
        $this->assertStringNotContainsString('verified ?? true', $clipboardSource);
        $this->assertStringContainsString("status: 'manual'", $clipboardSource);
        $this->assertStringContainsString("setPaymentLinkCopyMessage('Kopyalanacak link yok.')", $source);
        $this->assertStringContainsString('paymentLinkCopyTarget', $source);
        $this->assertStringContainsString('renderPaymentLinkCopyFeedback(paymentLinkCopyUrl(payment))', $source);
        $this->assertStringContainsString('renderPaymentLinkCopyFeedback(paymentLinkCopyUrl(extraMountPayment))', $source);
        $this->assertStringContainsString('paymentLinkManualCopyValue', $source);
        $this->assertStringContainsString('Otomatik kopyalanamadı;', $source);
        $this->assertStringNotContainsString('Manuel kopyalama', $source);
        $this->assertStringContainsString('Kopyalandı — ${successMessage}', $source);
        $this->assertStringContainsString('role="status"', $source);
        $this->assertStringContainsString('aria-live="polite"', $source);
        $this->assertStringContainsString('referenceCopyMessage', $source);
        $this->assertStringContainsString('referenceManualCopyValue', $source);
        $this->assertStringContainsString('customerApprovalManualCopyValue', $source);
        $this->assertStringContainsString('customerChargeManualCopyValue', $source);
        $this->assertStringContainsString('Otomatik kopyalanamadı; metni manuel kopyalayın.', $source);
        $this->assertStringContainsString('function paymentProviderLabel(', $source);
        $this->assertStringContainsString('function paymentProviderReferenceRows(', $source);
        $this->assertStringContainsString('Provider ödeme referansı', $source);
        $this->assertStringContainsString('Provider işlem referansı', $source);
        $this->assertStringContainsString('Dekont referansı', $source);
        $this->assertStringContainsString('Sağlayıcı tarafından dönmedi', $source);
        $this->assertStringContainsString('Ödeme linkini aç', $source);
        $this->assertStringContainsString('renderPaymentLinkSendAction(', $source);
        $this->assertStringContainsString('copyPaymentLinkValue(', $source);
        $this->assertStringContainsString('paymentLinkCopyUrl(', $source);
        $this->assertStringNotContainsString("onClick={() => void navigator.clipboard?.writeText(payment.payment_url ?? '')}", $source);
        $this->assertStringNotContainsString("onClick={() => void navigator.clipboard?.writeText(extraMountPayment.payment_url ?? '')}", $source);
    }

    public function test_copy_actions_use_global_clipboard_helper(): void
    {
        $detailSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));
        $hookSource = file_get_contents(resource_path('js/hooks/use-clipboard.ts'));
        $portalSource = file_get_contents(resource_path('js/pages/partner/portal-shell.tsx'));

        $this->assertIsString($detailSource);
        $this->assertIsString($hookSource);
        $this->assertIsString($portalSource);
        $this->assertStringContainsString("import { copyTextToClipboard } from '@/lib/clipboard'", $detailSource);
        $this->assertStringContainsString("import { copyTextToClipboard } from '@/lib/clipboard';", $hookSource);
        $this->assertStringContainsString("import { copyTextToClipboard } from '@/lib/clipboard'", $portalSource);
        $this->assertStringNotContainsString('navigator.clipboard?.writeText', $detailSource);
        $this->assertStringNotContainsString('navigator.clipboard.writeText', $detailSource);
        $compactDetailSource = preg_replace('/\s+/', '', $detailSource) ?? $detailSource;
        $this->assertStringContainsString('copyReferenceValue(displayMrn??request.mrn', $compactDetailSource);
        $this->assertStringContainsString("copyReferenceValue(request.serialNumber,'Serinokopyalandı.'", $compactDetailSource);
        $this->assertStringContainsString("copyReferenceValue(request.phone,'Telefonkopyalandı.'", $compactDetailSource);
        $this->assertStringContainsString("copyReferenceValue(locationInfo.map_url,'Haritalinkikopyalandı.'", $compactDetailSource);
        $this->assertStringContainsString('Seri noyu kopyala', $detailSource);
        $this->assertStringContainsString('Telefonu kopyala', $detailSource);
        $this->assertStringContainsString('Harita linkini kopyala', $detailSource);
        $this->assertStringContainsString('copyReferenceValue(displayedEarningMessageText', $compactDetailSource);
    }

    public function test_payment_link_modal_stays_inside_detail_dialog_focus_scope_and_uses_iyzico_open_copy_wording(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;
        $this->assertStringNotContainsString("import { createPortal } from 'react-dom'", $source);
        $this->assertStringNotContainsString('createPortal(paymentLinkEditorModal, document.body)', $source);
        $this->assertStringContainsString('pointer-events-auto fixed inset-0 z-[110]', $source);
        $this->assertStringContainsString('z-[110]', $source);
        $this->assertStringContainsString('IyzicoSandboxödemeekranıaçılacak.', $compactSource);
        $this->assertStringContainsString('Ödemeyapıldıktansonradurumkontrolü/reconciliationilegüncellenecek.', $compactSource);
        $this->assertMatchesRegularExpression('/\\{renderPaymentProviderReferences\\(payment,?\\)\\}/', $compactSource);
        $this->assertStringContainsString('{paymentLinkEditorModal}', $source);
        $this->assertStringNotContainsString('{paymentLinkEditorPortal}', $source);
    }

    public function test_pointer_events_restored_for_payment_modal_action_buttons(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;
        $this->assertStringContainsString('pointer-events-auto fixed inset-0 z-[110]', $source);
        $this->assertStringNotContainsString('createPortal(paymentLinkEditorModal, document.body)', $source);
        $this->assertStringContainsString('{paymentLinkEditorModal}', $source);
        $this->assertStringContainsString("payment.payment_action_kind==='open_provider_url'", $compactSource);
        $this->assertStringContainsString('Ödeme linkini aç', $source);
        $this->assertMatchesRegularExpression('/onClick=\\{\\(\\)=>voidcopyPaymentLinkValue\\(paymentLinkCopyUrl\\(payment,?\\),?\\)\\}/', $compactSource);
        $this->assertMatchesRegularExpression('/renderPaymentLinkSendAction\\(payment,?\\)/', $compactSource);
        $this->assertMatchesRegularExpression('/onClick=\\{\\(\\)=>voidhandlePendingPaymentCancel\\(payment,?\\)\\}/', $compactSource);
    }

    public function test_action_buttons_use_can_open_payment_url_can_copy_payment_url_can_cancel_payment_flags(): void
    {
        $presenter = file_get_contents(app_path('Services/TechnicalService/TechnicalServicePaymentActionPresenter.php'));
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($presenter);
        $this->assertIsString($source);
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;
        $this->assertStringContainsString("'can_open_payment_url' => \$canOpenProviderUrl", $presenter);
        $this->assertStringContainsString("'can_copy_payment_url' => \$canCopy", $presenter);
        $this->assertStringContainsString("'can_cancel_payment' => \$isPending", $presenter);
        $this->assertStringContainsString("'payment_action_kind' => \$actionKind", $presenter);
        $this->assertStringContainsString("'provider_payment_reference' => \$payment->provider_payment_reference", $presenter);
        $this->assertStringContainsString("'provider_transaction_reference' => \$payment->provider_transaction_reference", $presenter);
        $this->assertStringContainsString("'provider_receipt_reference' => \$payment->provider_receipt_reference", $presenter);
        $this->assertStringContainsString("'provider_last_synced_at' => \$payment->provider_last_synced_at?->toISOString()", $presenter);
        $this->assertStringContainsString("'provider_sync_attempts' => (int) (\$payment->provider_sync_attempts ?? 0)", $presenter);
        $this->assertStringContainsString("'provider_sync_message' => \$syncWaiting", $presenter);
        $this->assertStringContainsString("payment.payment_action_kind==='open_provider_url'", $compactSource);
        $this->assertStringContainsString('Ödeme linkini aç', $source);
        $this->assertMatchesRegularExpression('/renderPaymentLinkSendAction\\(payment,?\\)/', $compactSource);
        $this->assertStringContainsString('payment?.provider_sync_message', $source);
        $this->assertMatchesRegularExpression('/handlePendingPaymentSync\\(payment,?\\)/', $compactSource);
        $this->assertStringContainsString('Durumu Kontrol Et', $source);

        $pageSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));
        $this->assertIsString($pageSource);
        $this->assertStringContainsString('handleMountPaymentSync', $pageSource);
        $this->assertStringContainsString('sync_provider=1', $pageSource);
    }

    public function test_other_technicians_modal_keeps_first_four_visible(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;
        $spacedCompactSource = preg_replace('/\s+/', ' ', $source) ?? $source;
        $this->assertStringContainsString('topTechnicianSuggestions=technicianSuggestions.slice(0,4)', $compactSource);
        $this->assertStringContainsString('remainingTechnicianSuggestions=technicianSuggestions.slice(4)', $compactSource);
        $this->assertStringContainsString('otherTechniciansModalOpenByRequest', $source);
        $this->assertStringContainsString('otherTechniciansModal', $source);
        $this->assertStringContainsString('Diğer ustalar', $source);
        $this->assertStringContainsString('İlk 4 öneri ekranda kalır; kalan ustaları buradan seçin.', $spacedCompactSource);
        $this->assertMatchesRegularExpression('/topTechnicianSuggestions\.map\(\s*\(?technician\)?\s*=>\s*renderTechnicianSuggestionCard\(\s*technician\s*,?\s*\)/s', $source);
        $this->assertMatchesRegularExpression('/remainingTechnicianSuggestions\.map\(\s*\(?technician\)?\s*=>\s*renderTechnicianSuggestionCard\(\s*technician\s*,?\s*\)/s', $source);

        $pageSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));
        $this->assertIsString($pageSource);
        $this->assertStringContainsString('return technicianAssignmentInsights', $pageSource);
        $this->assertStringNotContainsString('const visible = technicianAssignmentInsights.slice(0, 4)', $pageSource);
    }

    public function test_payment_link_modal_can_open_without_selected_technician_for_manual_mount_payment(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;
        $this->assertStringContainsString("routeFeeEditorMode === 'payment_link' || selectedTechnician", $source);
        $this->assertStringContainsString("routeFeeEditorMode !== 'payment_link' && !selectedTechnician", $source);
        $this->assertStringContainsString("reason:routeFeeEditorMode==='payment_link'?'manual_extra':'route_fee'", $compactSource);
        $this->assertStringContainsString("purpose:routeFeeEditorMode==='payment_link'?'manual_mount_payment':'route_fee'", $compactSource);
    }

    public function test_service_part_payment_page_uses_tl_label_not_try(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-payment.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString("currency === 'TRY' ? 'TL' : currency", $source);
        $this->assertStringContainsString('Servis ücreti', $source);
        $this->assertStringContainsString('Parça ücreti', $source);
        $this->assertStringContainsString("actionKind === 'open_provider_url'", $source);
        $this->assertStringContainsString("actionKind === 'fake_complete'", $source);
        $this->assertStringContainsString('Fake/Yerel ödeme simülasyonu. Bu buton gerçek Iyzico tahsilatı yapmaz.', $source);
        $this->assertStringContainsString('Iyzico Sandbox ödeme ekranı açılacak.', $source);
        $this->assertStringContainsString('Ödeme yapıldıktan sonra durum kontrolü/reconciliation ile güncellenecek.', $source);
        $this->assertStringContainsString('href={copyUrl}', $source);
        $this->assertStringNotContainsString('} ${currency}`', $source);
    }

    public function test_open_provider_url_and_fake_complete_actions_are_separated_on_public_payment_page(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-payment.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString("actionKind === 'open_provider_url'", $source);
        $this->assertStringContainsString("actionKind === 'fake_complete'", $source);
        $this->assertStringContainsString('href={copyUrl}', $source);
        $this->assertStringContainsString('Fake/Yerel ödeme simülasyonu. Bu buton gerçek Iyzico tahsilatı yapmaz.', $source);
        $this->assertStringContainsString('Iyzico Sandbox ödeme ekranı açılacak.', $source);
    }

    public function test_ops_payload_groups_parent_and_srv_earning_breakdown(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'SRV Hakediş Ustası',
            'phone' => '+905551111118',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $parent = $this->technicalServiceRequest([
            'mrn' => 'MRN-WORKFLOW-EARNING-PARENT',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        $child = $this->technicalServiceRequest([
            'mrn' => 'SRV-WORKFLOW-EARNING-001',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-WORKFLOW-EARNING-001',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Montaj',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $parent->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 2000,
            'route_fee_amount' => 100,
            'total_amount' => 2100,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $child->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 1500,
            'route_fee_amount' => 300,
            'total_amount' => 1800,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($child->fresh(), true);

        $this->assertSame(2, $payload['earning_breakdown']['root_total']['job_count']);
        $this->assertSame(3500.0, $payload['earning_breakdown']['root_total']['labor_amount']);
        $this->assertSame(400.0, $payload['earning_breakdown']['root_total']['route_fee_amount']);
        $this->assertSame(3900.0, $payload['earning_breakdown']['root_total']['total_amount']);
        $this->assertSame('Servis', $payload['earning_breakdown']['current_visit']['kind_label']);
        $this->assertSame(1800.0, $payload['earning_breakdown']['current_visit']['total_amount']);
        $this->assertSame(['Montaj', 'Servis'], collect($payload['earning_breakdown']['rows'])->pluck('kind_label')->all());
    }

    public function test_multiple_srv_approve_selected_visits_excludes_unchecked_from_payout_total(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Çoklu SRV Onay Ustası',
            'phone' => '+905551111128',
            'city' => 'Sentetik Sehir 004 / SYNTHETIC',
            'active' => true,
        ]);
        $parent = $this->technicalServiceRequest([
            'mrn' => 'MRN-MULTI-SRV-PAYOUT',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        $firstSrv = $this->technicalServiceRequest([
            'mrn' => 'SRV-MULTI-PAYOUT-001',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-MULTI-PAYOUT-001',
            'service_visit_reason' => 'spare_part',
            'service_type' => 'Servis',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        $secondSrv = $this->technicalServiceRequest([
            'mrn' => 'SRV-MULTI-PAYOUT-002',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 2,
            'service_code' => 'SRV-MULTI-PAYOUT-002',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Servis',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);

        foreach ([[$parent, 2000, 100], [$firstSrv, 1500, 300], [$secondSrv, 900, 200]] as [$request, $labor, $route]) {
            TechnicalServiceAssignmentOffer::query()->create([
                'technical_service_request_id' => $request->id,
                'technical_service_technician_id' => $technician->id,
                'labor_amount' => $labor,
                'route_fee_amount' => $route,
                'total_amount' => $labor + $route,
                'currency' => 'TRY',
                'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
                'sent_at' => now(),
            ]);
        }

        $pendingPayload = app(TechnicalServiceWorkflowService::class)->serialize($firstSrv->fresh(), true);
        $this->assertTrue($pendingPayload['earning_breakdown']['root_total']['payout_approval_required']);
        $this->assertSame('pending', $pendingPayload['earning_breakdown']['root_total']['payout_approval_status']);
        $this->assertSame(5000.0, $pendingPayload['earning_breakdown']['root_total']['total_amount']);

        $parent->forceFill([
            'operation_control_payload' => [
                'ops_final_payout_approval' => [
                    'approved_request_ids' => [$parent->id, $firstSrv->id],
                    'excluded_request_ids' => [$secondSrv->id],
                    'approved_at' => now()->toISOString(),
                    'approved_by_user_id' => null,
                ],
            ],
        ])->save();

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($firstSrv->fresh(), true);
        $rows = collect($payload['earning_breakdown']['rows']);
        $this->assertSame(3900.0, $payload['earning_breakdown']['root_total']['total_amount']);
        $this->assertTrue($rows->firstWhere('id', $parent->id)['payout_included']);
        $this->assertTrue($rows->firstWhere('id', $firstSrv->id)['payout_included']);
        $this->assertFalse($rows->firstWhere('id', $secondSrv->id)['payout_included']);
        $this->assertSame('Hakedişten çıkarıldı', $rows->firstWhere('id', $secondSrv->id)['payout_approval_status_label']);
    }

    public function test_srv_assignment_does_not_require_parent_mount_or_door_checks(): void
    {
        $parent = $this->technicalServiceRequest([
            'mrn' => 'MRN-SRV-GATE-PARENT',
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'operation_control_payload' => [
                'payment_checked' => 'unreviewed',
                'door_photos_checked' => 'unreviewed',
            ],
        ]);
        $child = $this->technicalServiceRequest([
            'mrn' => 'SRV-SRV-GATE-001',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-SRV-GATE-001',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Servis',
            'operation_control_payload' => [
                'payment_checked' => 'unreviewed',
                'door_photos_checked' => 'unreviewed',
            ],
        ]);

        $service = app(TechnicalServiceWorkflowService::class);
        $service->assertOperationControlsAllowAssignment($child->fresh());
        $payload = $service->serialize($child->fresh(), true);

        $this->assertTrue($payload['visible_sections']['is_service_visit']);
        $this->assertFalse($payload['visible_sections']['operation_mount_controls']);
        $this->assertFalse($payload['visible_sections']['payment_control']);
        $this->assertFalse($payload['visible_sections']['door_photo_control']);
        $this->assertFalse($payload['operation_control']['applies_to_assignment']);
        $this->assertFalse($payload['operation_control']['payment_required_for_assignment']);
        $this->assertFalse($payload['operation_control']['show_mount_controls']);
        $this->assertFalse($payload['operation_control']['show_payment_control']);
        $this->assertFalse($payload['operation_control']['show_door_photo_control']);
        $this->assertFalse($payload['assignment_blockers']['applies_to_assignment']);
        $this->assertFalse($payload['assignment_blockers']['payment_required_for_assignment']);
        $this->assertSame([], $payload['assignment_blockers']['messages']);
        $this->assertSame('assign_technician', $payload['next_action_payload']['code']);
        $this->assertSame('assign_technician', $payload['next_action_payload']['primary_action']);
        $this->assertSame('Usta Ata', $payload['next_action_payload']['title']);
        $this->assertStringNotContainsString('Kapı', $payload['next_action_payload']['title']);
        $this->assertStringNotContainsString('Kapı', $payload['next_action_payload']['description']);
    }

    public function test_srv_history_uses_real_action_labels_not_generic_islem_kaydi(): void
    {
        $parent = $this->technicalServiceRequest([
            'mrn' => 'MRN-SRV-HISTORY-LABEL',
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
        ]);
        $parent->events()->create([
            'event_type' => 'legacy_custom_srv_note',
            'title' => 'Parça sonrası servis oluşturuldu',
            'note' => 'Ops parça akışından SRV açtı.',
            'from_status' => 'Tamamlandı',
            'to_status' => 'Tamamlandı',
            'metadata' => [],
        ]);
        $child = $this->technicalServiceRequest([
            'mrn' => 'SRV-SRV-HISTORY-LABEL-001',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-SRV-HISTORY-LABEL-001',
            'service_visit_reason' => 'spare_part',
            'service_type' => 'Servis',
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($child->fresh(), true);
        $event = $payload['service_visit_history']['parent_events'][0] ?? null;

        $this->assertIsArray($event);
        $this->assertSame('Parça sonrası servis oluşturuldu', $event['title_label']);
        $this->assertSame('Ops parça akışından SRV açtı.', $event['note']);
        $this->assertNotSame('İşlem kaydı', $event['title_label']);
    }

    public function test_service_visit_history_root_mrn_history_mrn_srv_history_includes_root_mrn_and_all_sibling_srvs(): void
    {
        $root = $this->technicalServiceRequest([
            'mrn' => 'MRN-SRV-HISTORY-ROOT',
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now(),
        ]);
        $first = $this->technicalServiceRequest([
            'mrn' => 'SRV-SRV-HISTORY-ROOT-001',
            'parent_request_id' => $root->id,
            'root_mrn' => $root->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-SRV-HISTORY-ROOT-001',
            'service_visit_reason' => 'revisit',
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);
        $second = $this->technicalServiceRequest([
            'mrn' => 'SRV-SRV-HISTORY-ROOT-002',
            'parent_request_id' => $first->id,
            'root_mrn' => $root->mrn,
            'service_sequence' => 2,
            'service_code' => 'SRV-SRV-HISTORY-ROOT-002',
            'service_visit_reason' => 'spare_part',
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);
        $third = $this->technicalServiceRequest([
            'mrn' => 'SRV-SRV-HISTORY-ROOT-003',
            'parent_request_id' => $second->id,
            'root_mrn' => $root->mrn,
            'service_sequence' => 3,
            'service_code' => 'SRV-SRV-HISTORY-ROOT-003',
            'service_visit_reason' => 'spare_part',
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($third->fresh(), true);
        $history = $payload['service_visit_history'];
        $records = collect($history['history_records']);

        $this->assertSame($root->mrn, $history['root_request']['mrn']);
        $this->assertSame($second->mrn, $history['direct_parent_request']['mrn']);
        $this->assertSame([
            $root->mrn,
            $first->mrn,
            $second->mrn,
            $third->mrn,
        ], $records->pluck('mrn')->all());
        $this->assertSame([
            'root_mrn',
            'srv',
            'srv',
            'srv',
        ], $records->pluck('type')->all());
        $this->assertTrue($records->firstWhere('mrn', $third->mrn)['is_current']);
        $this->assertFalse($records->firstWhere('mrn', $root->mrn)['is_current']);
        $this->assertSame([
            $root->mrn,
            $first->mrn,
            $second->mrn,
            $third->mrn,
        ], collect($payload['mrn_srv_history']['items'])->pluck('code')->all());
    }

    public function test_parent_chain_srv_detail_history_handles_missing_root_without_fake_row(): void
    {
        $orphanParent = $this->technicalServiceRequest([
            'mrn' => 'SRV-MISSING-ROOT-PARENT-001',
            'root_mrn' => 'MRN-MISSING-ROOT',
            'service_sequence' => 1,
            'service_code' => 'SRV-MISSING-ROOT-PARENT-001',
            'service_visit_reason' => 'revisit',
        ]);
        $child = $this->technicalServiceRequest([
            'mrn' => 'SRV-MISSING-ROOT-CHILD-002',
            'parent_request_id' => $orphanParent->id,
            'root_mrn' => 'MRN-MISSING-ROOT',
            'service_sequence' => 2,
            'service_code' => 'SRV-MISSING-ROOT-CHILD-002',
            'service_visit_reason' => 'spare_part',
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($child->fresh(), true);
        $records = collect($payload['service_visit_history']['history_records']);

        $this->assertNull($payload['service_visit_history']['root_request']);
        $this->assertNull($payload['mrn_srv_history']['root_request']);
        $this->assertFalse($records->contains(fn (array $record): bool => $record['mrn'] === 'MRN-MISSING-ROOT'));
        $this->assertSame([
            $orphanParent->mrn,
            $child->mrn,
        ], $records->pluck('mrn')->all());
    }

    public function test_partner_srv_history_shows_root_mrn_context(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Partner SRV Geçmiş Ustası',
            'phone' => '+905551111811',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'PARTNER-SRV-HISTORY',
            'display_name' => 'Partner SRV History',
            'active' => true,
        ]);
        $partner->capabilities()->create([
            'capability' => B2BPartner::TYPE_LOCKSMITH,
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $root = $this->technicalServiceRequest([
            'mrn' => 'MRN-PARTNER-SRV-HISTORY',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        $first = $this->technicalServiceRequest([
            'mrn' => 'SRV-PARTNER-SRV-HISTORY-001',
            'parent_request_id' => $root->id,
            'root_mrn' => $root->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-PARTNER-SRV-HISTORY-001',
            'service_visit_reason' => 'revisit',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        $second = $this->technicalServiceRequest([
            'mrn' => 'SRV-PARTNER-SRV-HISTORY-002',
            'parent_request_id' => $first->id,
            'root_mrn' => $root->mrn,
            'service_sequence' => 2,
            'service_code' => 'SRV-PARTNER-SRV-HISTORY-002',
            'service_visit_reason' => 'spare_part',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);

        $method = new \ReflectionMethod(B2BPartnerPortalDataService::class, 'serviceVisitContext');
        $method->setAccessible(true);
        $context = $method->invoke(app(B2BPartnerPortalDataService::class), $second->fresh('parentRequest'));

        $this->assertIsArray($context);
        $this->assertSame($root->mrn, $context['root_mrn']);
        $this->assertSame($root->mrn, $context['root_request']['mrn']);
        $this->assertSame([
            $root->mrn,
            $first->mrn,
            $second->mrn,
        ], collect($context['history_records'])->pluck('mrn')->all());
    }

    public function test_visit_start_block_hidden_when_no_start_timestamp(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('const selectedHistoryStartTimestamp =', $source);
        $this->assertStringContainsString('{selectedHistoryStartTimestamp ? (', $source);
        $this->assertStringNotContainsString("dateTimeOrEmpty(selectedHistoryRecord.technician_arrived_at ?? selectedHistoryRecord.field_started_at, 'Kayıt yok')", $source);
    }

    public function test_srv_finance_summary_keeps_current_visit_and_root_totals_separate(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'SRV Finans Ustası',
            'phone' => '+905551111119',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $parent = $this->technicalServiceRequest([
            'mrn' => 'MRN-SRV-FINANCE-PARENT',
            'service_type' => 'Montaj',
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'mount_payment_provider' => 'fake',
            'mount_payment_reference' => 'parent-mount-paid',
            'mount_payment_paid_at' => now(),
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        $parentSession = $this->mountSessionForRequest($parent);
        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $parentSession->id,
            'technical_service_request_id' => $parent->id,
            'provider' => 'fake',
            'provider_reference' => 'parent-mount-paid',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3000,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => ['source' => 'public_form_payment'],
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $parent->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 1000,
            'route_fee_amount' => 100,
            'total_amount' => 1100,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);
        $child = $this->technicalServiceRequest([
            'mrn' => 'SRV-SRV-FINANCE-001',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-SRV-FINANCE-001',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Servis',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        $childSession = $this->mountSessionForRequest($child);
        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $childSession->id,
            'technical_service_request_id' => $child->id,
            'provider' => 'fake',
            'provider_reference' => 'srv-service-part-paid',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 750,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => [
                'source' => 'operation_customer_charge',
                'purpose' => 'service_and_part_payment',
                'service_amount' => 500,
                'part_amount' => 250,
                'total_amount' => 750,
            ],
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $child->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 600,
            'route_fee_amount' => 80,
            'total_amount' => 680,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($child->fresh(), true);

        $this->assertNull($payload['sale_and_payment']['payment_summary']['mount']['amount']);
        $this->assertFalse($payload['sale_and_payment']['payment_summary']['has_mount_collection']);
        $this->assertTrue($payload['sale_and_payment']['payment_summary']['has_service_charge']);
        $this->assertTrue($payload['sale_and_payment']['payment_summary']['has_part_charge']);
        $this->assertSame(750.0, $payload['sale_and_payment']['payment_summary']['total_customer_collection']);
        $this->assertSame(500.0, $payload['service_customer_payment']);
        $this->assertSame(250.0, $payload['part_customer_payment']);
        $this->assertSame(750.0, $payload['total_customer_collected']);
        $this->assertSame(680.0, $payload['earning_breakdown']['current_visit']['total_amount']);
        $this->assertSame(1780.0, $payload['earning_breakdown']['root_total']['total_amount']);
    }

    public function test_warranty_srv_labor_is_locksmith_payout_not_customer_collection_and_warranty_service_shows_zero_customer_collection(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Garanti SRV Ustası',
            'phone' => '+905551111120',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $parent = $this->technicalServiceRequest([
            'mrn' => 'MRN-WARRANTY-FINANCE-PARENT',
            'service_type' => 'Montaj',
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'mount_payment_provider' => 'fake',
            'mount_payment_reference' => 'parent-paid-3500',
            'mount_payment_paid_at' => now(),
        ]);
        $parentSession = $this->mountSessionForRequest($parent);
        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $parentSession->id,
            'technical_service_request_id' => $parent->id,
            'provider' => 'fake',
            'provider_reference' => 'parent-paid-3500',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3500,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => ['source' => 'public_form_payment'],
        ]);
        $child = $this->technicalServiceRequest([
            'mrn' => 'SRV-WARRANTY-FINANCE-001',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-WARRANTY-FINANCE-001',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Servis',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $child->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 1800,
            'route_fee_amount' => 0,
            'total_amount' => 1800,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($child->fresh(), true);

        $this->assertSame(0.0, $payload['finance_summary']['current_visit']['customer_collection']['total_amount']);
        $this->assertSame('0 TL', $payload['finance_summary']['current_visit']['customer_collection']['total_amount_label']);
        $this->assertSame(0.0, $payload['finance_summary']['current_visit_customer_collection']['total_amount']);
        $this->assertSame(1800.0, $payload['finance_summary']['current_visit']['locksmith_payout']['labor_amount']);
        $this->assertSame(1800.0, $payload['finance_summary']['current_visit']['locksmith_payout']['total_amount']);
        $this->assertSame(1800.0, $payload['finance_summary']['current_visit_locksmith_payout']['total_amount']);
        $this->assertSame('confirmed', $payload['finance_summary']['current_visit']['locksmith_payout']['payout_status']);
        $this->assertSame('Onaylanan usta hakedişi', $payload['finance_summary']['current_visit']['locksmith_payout']['payout_status_label']);
        $this->assertSame(1800.0, $payload['finance_summary']['current_visit']['confirmed_locksmith_payout']['total_amount']);
        $this->assertNull($payload['finance_summary']['current_visit']['draft_locksmith_payout']);
        $this->assertSame(-1800.0, $payload['finance_summary']['current_visit']['net_margin']['amount']);
        $this->assertTrue($payload['finance_summary']['current_visit']['warranty_covered']);
        $this->assertSame('Garanti kapsamında - müşteriden servis/parça tahsilatı yok', $payload['finance_summary']['current_visit']['warranty_note']);
        $this->assertSame('Usta hakedişi operasyon maliyeti olarak hesaplandı', $payload['finance_summary']['current_visit']['operation_cost_note']);
        $this->assertSame(0.0, $payload['finance_summary']['current_visit']['warranty_customer_charge']['total_amount']);
        $this->assertSame(1800.0, $payload['finance_summary']['current_visit']['operation_cost']['total_amount']);
        $this->assertTrue($payload['finance_summary']['current_visit']['operation_cost']['applies']);
        $this->assertSame(1800.0, $payload['finance_summary']['current_visit_operation_cost']['total_amount']);
        $this->assertSame(3500.0, $payload['finance_summary']['root_total']['customer_collection']['total_amount']);
        $this->assertSame(1800.0, $payload['finance_summary']['root_total']['locksmith_payout']['total_amount']);
        $this->assertSame(1700.0, $payload['finance_summary']['root_total']['net_margin']['amount']);
    }

    public function test_draft_assignment_labor_is_not_displayed_as_confirmed_payout(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Taslak Hakediş Ustası',
            'phone' => '+905551111122',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'SRV-DRAFT-PAYOUT-001',
            'service_sequence' => 1,
            'service_code' => 'SRV-DRAFT-PAYOUT-001',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Servis',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'technician_payment_amount' => 1800,
            'travel_fee_amount' => 120,
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);
        $payout = $payload['finance_summary']['current_visit']['locksmith_payout'];

        $this->assertSame(0.0, $payload['finance_summary']['current_visit']['customer_collection']['total_amount']);
        $this->assertSame(1800.0, $payout['labor_amount']);
        $this->assertSame(120.0, $payout['route_fee_amount']);
        $this->assertSame(1920.0, $payout['total_amount']);
        $this->assertSame('draft', $payout['payout_status']);
        $this->assertSame('Önerilen / taslak hakediş', $payout['payout_status_label']);
        $this->assertTrue($payout['is_draft']);
        $this->assertFalse($payout['is_confirmed']);
        $this->assertNull($payload['finance_summary']['current_visit']['confirmed_locksmith_payout']);
        $this->assertSame(1920.0, $payload['finance_summary']['current_visit']['draft_locksmith_payout']['total_amount']);
    }

    public function test_confirmed_assignment_payout_displays_as_confirmed_locksmith_earning(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Onaylı Hakediş Ustası',
            'phone' => '+905551111123',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'SRV-CONFIRMED-PAYOUT-001',
            'service_sequence' => 1,
            'service_code' => 'SRV-CONFIRMED-PAYOUT-001',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Servis',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 1800,
            'route_fee_amount' => 120,
            'total_amount' => 1920,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);
        $payout = $payload['finance_summary']['current_visit']['locksmith_payout'];

        $this->assertSame(0.0, $payload['finance_summary']['current_visit']['customer_collection']['total_amount']);
        $this->assertSame(1920.0, $payout['total_amount']);
        $this->assertSame('confirmed', $payout['payout_status']);
        $this->assertSame('Onaylanan usta hakedişi', $payout['payout_status_label']);
        $this->assertTrue($payout['is_confirmed']);
        $this->assertFalse($payout['is_draft']);
        $this->assertSame(1920.0, $payload['finance_summary']['current_visit']['confirmed_locksmith_payout']['total_amount']);
        $this->assertNull($payload['finance_summary']['current_visit']['draft_locksmith_payout']);
    }

    public function test_route_travel_fee_is_payout_not_customer_collection(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Yol Hakediş Ustası',
            'phone' => '+905551111121',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $child = $this->technicalServiceRequest([
            'mrn' => 'SRV-ROUTE-PAYOUT-001',
            'service_sequence' => 1,
            'service_code' => 'SRV-ROUTE-PAYOUT-001',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Servis',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $child->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 1800,
            'route_fee_amount' => 120,
            'total_amount' => 1920,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($child->fresh(), true);

        $this->assertSame(0.0, $payload['finance_summary']['current_visit']['customer_collection']['total_amount']);
        $this->assertSame(1800.0, $payload['finance_summary']['current_visit']['locksmith_payout']['labor_amount']);
        $this->assertSame(120.0, $payload['finance_summary']['current_visit']['locksmith_payout']['route_fee_amount']);
        $this->assertSame(1920.0, $payload['finance_summary']['current_visit']['locksmith_payout']['total_amount']);
        $this->assertSame(0.0, $payload['finance_summary']['current_visit']['customer_collection']['total_amount']);
        $this->assertSame(1920.0, $payload['finance_summary']['current_visit']['operation_cost']['total_amount']);
        $this->assertSame(-1920.0, $payload['finance_summary']['current_visit']['net_margin']['amount']);
    }

    public function test_user_facing_status_labels_have_no_mojibake(): void
    {
        $dirty = 'M??teri Planl? Tamamland? iÅŸ FotoÄŸraf Ã‡ilingir';

        $clean = TechnicalServiceUiLabelService::cleanDisplayText($dirty);

        $this->assertSame('Müşteri Planlı Tamamlandı iş Fotoğraf Çilingir', $clean);
        foreach (['M??teri', 'Planl?', 'Tamamland?', 'iÅŸ', 'FotoÄŸ', 'Ã‡'] as $brokenToken) {
            $this->assertStringNotContainsString($brokenToken, $clean);
        }
    }

    public function test_payment_block_shows_paid_amount(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'mount_payment_label' => 'Form üzerinden ödeme alındı',
            'mount_payment_provider' => 'fake',
            'mount_payment_reference' => 'fake-paid-3500',
            'mount_payment_paid_at' => now(),
        ]);
        $session = $this->mountSessionForRequest($request);

        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-3500',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3500,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => ['source' => 'public_form_payment'],
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame(3500.0, $payload['sale_and_payment']['paid_amount']);
        $this->assertSame('3.500 TL', $payload['sale_and_payment']['paid_amount_label']);
        $this->assertSame('fake-paid-3500', $payload['sale_and_payment']['payment_reference']);
        $this->assertNotNull($payload['sale_and_payment']['payment_paid_at']);
    }

    public function test_payment_block_translates_paid_status(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame('Ödendi', $payload['sale_and_payment']['payment_status_label']);
    }

    public function test_payment_block_keeps_ops_payment_check_separate(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'operation_control_payload' => [
                'payment_checked' => 'unreviewed',
            ],
        ]);
        $session = $this->mountSessionForRequest($request);

        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-unreviewed',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3500,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => ['source' => 'public_form_payment'],
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame('Ödendi', $payload['sale_and_payment']['payment_status_label']);
        $this->assertSame('Kontrol edilmedi', $payload['sale_and_payment']['ops_payment_check_label']);
        $this->assertSame('unreviewed', $payload['operation_control']['payment_checked']);
    }

    public function test_paid_amount_matches_earning_customer_collection(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 0,
        ]);
        $session = $this->mountSessionForRequest($request);

        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-total',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3500,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => ['source' => 'public_form_payment'],
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame(3500.0, $payload['sale_and_payment']['paid_amount']);
        $this->assertSame(3500.0, $payload['total_customer_collected']);
        $this->assertSame(500.0, $payload['cost_delta']);
    }

    public function test_raw_payment_status_is_not_rendered_in_ops_payload(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));
        $this->assertIsString($source);

        $this->assertStringNotContainsString('hint={saleAndPayment?.mount_payment_status', $source);
        $this->assertStringContainsString('Tahsilat durumu', $source);
        $this->assertStringContainsString('Alınan ödeme tutarı', $source);
        $this->assertStringContainsString('Operasyon ödeme kontrolü', $source);
    }

    public function test_assignment_popup_uses_canonical_finance_collection_not_service_default(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('financeSummary: request.finance_summary ?? null', $source);
        $this->assertStringContainsString('const modalFinanceCustomerCollection = modalCurrentFinance?.customer_collection ?? null', $source);
        $this->assertStringContainsString('const activeModalFinancePayout = modalFinancePayoutMatchesSelection ? modalFinancePayout : null', $source);
        $this->assertStringContainsString('modalFinanceCustomerCollection?.total_amount_label', $source);
        $this->assertStringContainsString('Ödeme kaydı yok', $source);
        $this->assertStringNotContainsString('?? modalPayment.customerAmount', $source);
        $this->assertStringNotContainsString('const assignmentTechnicianLaborAmount = typeof modalFinancePayout?.labor_amount', $source);
        $this->assertStringNotContainsString('Müşteriden alınan montaj ödemesi', $source);
    }

    public function test_service_request_detail_uses_missing_payment_label_for_absent_collection(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('totalCustomerCollectionDisplayLabel', $source);
        $this->assertStringContainsString('financeRootCustomerCollectionDisplayLabel', $source);
        $this->assertStringContainsString('Ödeme kaydı yok', $source);
    }

    public function test_selected_technician_change_recomputes_draft_payout_and_route_fee(): void
    {
        $panelSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));
        $detailSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));
        $this->assertIsString($panelSource);
        $this->assertIsString($detailSource);
        $compactDetailSource = preg_replace('/\s+/', ' ', $detailSource) ?? $detailSource;

        $this->assertStringContainsString('resetAssignmentDraftForTechnicianChange', $panelSource);
        $this->assertStringContainsString('routeQuoteAutoRequestSeq.current += 1', $panelSource);
        $this->assertStringContainsString("setAssignOfferRouteFeeAmount('')", $panelSource);
        $this->assertStringContainsString('const selectedTechnicianMatchesRequest = selectedTechnicianIdString', $compactDetailSource);
        $this->assertStringContainsString('const storedRouteCostMatchesSelection = selectedTechnicianMatchesRequest || assignmentOfferMatchesSelectedTechnician', $compactDetailSource);
        $this->assertStringContainsString('const activeFinanceLocksmithPayout = financePayoutMatchesSelectedTechnician ? financeLocksmithPayout : null', $compactDetailSource);
    }

    public function test_unassigned_detail_does_not_promote_stale_assignment_offer_to_active_payout(): void
    {
        $detailSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));
        $this->assertIsString($detailSource);
        $compactDetailSource = preg_replace('/\s+/', ' ', $detailSource) ?? $detailSource;

        $this->assertStringContainsString(': requestTechnicianIdString', $compactDetailSource);
        $this->assertStringContainsString('? !assignmentOfferTechnicianIdString || assignmentOfferTechnicianIdString === requestTechnicianIdString', $compactDetailSource);
        $this->assertStringContainsString('? !financePayoutTechnicianIdString || financePayoutTechnicianIdString === requestTechnicianIdString', $compactDetailSource);
        $this->assertMatchesRegularExpression(
            '/const\s+hasPayoutTechnicianContext\s*=\s*Boolean\(\s*selectedTechnician\s*\|\|\s*requestTechnicianIdString\s*\|\|\s*activeAssignmentOffer\s*\|\|\s*activeFinanceLocksmithPayout\s*,?\s*\)/s',
            $detailSource,
        );
        $this->assertStringContainsString("!hasPayoutTechnicianContext ? 'Usta seçilmedi'", $compactDetailSource);
        $this->assertMatchesRegularExpression(
            '/const\s+showFinanceCollectionMetrics\s*=\s*!hasAssignmentChange\s*&&\s*Boolean\(\s*requestTechnicianIdString\s*\|\|\s*activeFinanceLocksmithPayout\s*\|\|\s*activeAssignmentOffer\s*,?\s*\)/s',
            $detailSource,
        );
        $this->assertStringContainsString('{showFinanceCollectionMetrics && earningBreakdown?.root_total ? (', $compactDetailSource);
    }

    public function test_stale_route_quote_for_previous_technician_is_ignored(): void
    {
        $panelSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));
        $detailSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));
        $this->assertIsString($panelSource);
        $this->assertIsString($detailSource);

        $this->assertStringContainsString('routeQuoteLatestSelection.current.technicianId !== submittedTechnicianId', $panelSource);
        $this->assertStringContainsString('routeQuoteActiveForSelection(modalRouteQuote, assignTechnicianOption, selectedAssignTechnicianRecord, modalRequest)', $panelSource);
        $this->assertStringContainsString('routeQuoteStaleForSelectedTechnician', $detailSource);
        $this->assertStringContainsString('Seçili usta değiştiği için yol hakedişi yeniden hesaplanmalı.', $detailSource);
    }

    public function test_partner_portal_refresh_reconciles_job_after_customer_approval(): void
    {
        $source = file_get_contents(resource_path('js/pages/partner/portal-shell.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString("window.addEventListener('focus', refreshVisibleJobs)", $source);
        $this->assertStringContainsString("document.addEventListener('visibilitychange', refreshVisibleJobs)", $source);
        $this->assertStringContainsString('refreshJobs(true, true)', $source);
    }

    public function test_activation_serial_context_is_exposed_to_ops_and_partner_payloads(): void
    {
        $workflowSource = file_get_contents(app_path('Services/TechnicalService/TechnicalServiceWorkflowService.php'));
        $partnerSource = file_get_contents(app_path('Services/B2B/B2BPartnerPortalDataService.php'));
        $portalSource = file_get_contents(resource_path('js/pages/partner/portal-shell.tsx'));
        $detailSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));
        $this->assertIsString($workflowSource);
        $this->assertIsString($partnerSource);
        $this->assertIsString($portalSource);
        $this->assertIsString($detailSource);

        $this->assertStringContainsString("'activation_code' => ".'$'.'request->activation_code', $workflowSource);
        $this->assertStringContainsString('private function serviceJobSerialContext', $partnerSource);
        $this->assertStringContainsString("'activation_code' => ".'$'.'this->firstFilled('.'$'.'request->activation_code', $partnerSource);
        $this->assertStringContainsString('const serviceJobSerialLabel = (job: ServiceJob): string =>', $portalSource);
        $this->assertStringContainsString('Aktivasyon / seri', $portalSource);
        $this->assertStringContainsString('Aktivasyon Kodu', $detailSource);
    }

    public function test_ops_finance_ui_labels_payout_as_cost_not_customer_payment(): void
    {
        $detailSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));
        $panelSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));
        $this->assertIsString($detailSource);
        $this->assertIsString($panelSource);

        $this->assertStringContainsString('Usta Hakedişi / Operasyon Maliyeti', $detailSource);
        $this->assertStringContainsString('ödeme/tahsilat değildir', $detailSource);
        $this->assertStringContainsString('Önerilen / taslak usta hakedişi', $detailSource);
        $this->assertStringContainsString('Onaylanan usta hakedişi', $detailSource);
        $this->assertStringContainsString('Bu tutar müşteri tahsilatı değildir', $panelSource);
        $this->assertStringContainsString('Onaylanacak usta hakedişi', $panelSource);
        $this->assertStringContainsString('Net operasyon farkı', $panelSource);
    }

    public function test_payment_resolver_uses_paid_serial_payload_over_mikro_excluded_signal(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
        ]);
        $this->addSelectedSerial($request, 'SN-MAIN');
        $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-PAID',
            'product_name' => 'Test Ürün',
            'customer_selected' => true,
            'customer_visible' => true,
            'customer_selectable' => true,
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'green',
            'source_payload' => [
                'mount_payment_status' => TechnicalServiceMountPayment::STATUS_PAID,
                'mount_status_label' => 'Montaj Dahil',
            ],
        ]);

        $resolved = app(TechnicalServicePaymentStatusResolver::class)->resolve($request->fresh());

        $this->assertTrue($resolved['is_paid']);
        $this->assertFalse($resolved['requires_payment']);
        $this->assertSame('request_serial_payload', $resolved['source']);
    }

    public function test_payment_resolver_keeps_unpaid_mikro_excluded_signal_as_payment_required(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
        ]);
        $this->addSelectedSerial($request, 'SN-MAIN');
        $this->addSelectedSerial($request, 'SN-SECOND', false);

        $resolved = app(TechnicalServicePaymentStatusResolver::class)->resolve($request->fresh());

        $this->assertFalse($resolved['is_paid']);
        $this->assertTrue($resolved['requires_payment']);
        $this->assertSame('mikro_initial_sale', $resolved['source']);
    }

    public function test_paid_operation_payment_skips_mount_exclusion_acknowledgement_on_assign(): void
    {
        $user = $this->adminUser();
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Operasyon Ödemeli Usta',
            'phone' => '+905551111117',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Müşteri Onayı Bekleyen',
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        $this->addSelectedSerial($request, 'SN-MAIN');
        $this->addSelectedSerial($request, 'SN-SECOND', false);
        $session = $this->mountSessionForRequest($request);

        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3000,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'reason' => 'route_fee',
            ],
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'travel_round_trip_km' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('request.technical_service_technician_id', $technician->id)
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen')
            ->assertJsonPath('request.operation_control.mount_exclusion_acknowledgement.required', false)
            ->assertJsonPath('request.sale_and_payment.mount_payment_received', true)
            ->assertJsonPath('request.sale_and_payment.payment_status.is_paid', true);
    }

    public function test_next_action_does_not_block_paid_payment_and_returns_assign_action(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Aksiyon Ustası',
            'phone' => '+905551111118',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Müşteri Onayı Bekleyen',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        $this->addSelectedSerial($request, 'SN-MAIN');
        $this->addSelectedSerial($request, 'SN-SECOND', false);
        $session = $this->mountSessionForRequest($request);
        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3000,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'reason' => 'route_fee',
            ],
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame('assign_technician', $payload['next_action_payload']['code']);
        $this->assertSame('assign_technician', $payload['next_action_payload']['primary_action']);
        $this->assertStringNotContainsString('alınmadığı', $payload['next_action_payload']['description']);
    }

    public function test_operation_control_patch_persists_and_unlocks_assignment(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/operation-control", [
                'payment_checked' => 'yes',
                'address_checked' => 'yes',
                'door_photos_checked' => 'compatible',
                'missing_info' => 'no',
                'customer_call_required' => 'no',
                'schedule_update_required' => 'no',
                'note' => 'Operasyon kontrolü tamamlandı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.id', $request->id)
            ->assertJsonPath('request.assignment_blockers.messages', [])
            ->assertJsonPath('operation_control_update.id', $request->id)
            ->assertJsonPath('operation_control_update.operation_control.payment_checked', 'yes')
            ->assertJsonPath('operation_control_update.operation_control.door_photos_checked', 'compatible')
            ->assertJsonPath('operation_control_update.assignment_blockers.messages', [])
            ->assertJsonPath('operation_control_update.assignment_blockers.door_photo_check_required', false);

        $request->refresh();

        $this->assertSame('yes', $request->operation_control_payload['payment_checked'] ?? null);
        $this->assertSame('compatible', $request->operation_control_payload['door_photos_checked'] ?? null);
        $this->assertSame($user->id, $request->operation_control_checked_by_user_id);
        $this->assertNotNull($request->operation_control_checked_at);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technician_name' => 'Test Usta',
                'travel_round_trip_km' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen');
    }

    public function test_operation_control_note_action_uses_lightweight_response_payload(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'address_checked' => 'yes',
                'door_photos_checked' => 'unreviewed',
            ],
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/operation-control", [
                'note' => 'Sadece operasyon notu güncellendi.',
            ])
            ->assertOk()
            ->assertJsonMissingPath('request')
            ->assertJsonPath('operation_control_update.id', $request->id)
            ->assertJsonPath('operation_control_update.operation_control.note', 'Sadece operasyon notu güncellendi.')
            ->assertJsonStructure([
                'operation_control_update' => [
                    'id',
                    'operation_control',
                    'assignment_blockers',
                    'allowed_workflow_actions',
                    'allowed_workflow_transitions',
                    'operational_state',
                    'visible_sections',
                    'next_action',
                    'next_action_payload',
                ],
            ]);
    }

    public function test_door_compatible_operation_control_response_refreshes_assignment_ready_request_payload(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'address_checked' => 'yes',
                'door_photos_checked' => 'unreviewed',
            ],
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/operation-control", [
                'door_photos_checked' => 'compatible',
            ])
            ->assertOk()
            ->assertJsonPath('request.id', $request->id)
            ->assertJsonPath('request.operation_control.door_photos_checked', 'compatible')
            ->assertJsonPath('request.assignment_blockers.door_photo_check_required', false)
            ->assertJsonPath('request.assignment_blockers.messages', [])
            ->assertJsonPath('operation_control_update.assignment_blockers.door_photo_check_required', false);
    }

    public function test_payment_yes_operation_control_response_refreshes_assignment_ready_request_payload(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'operation_control_payload' => [
                'payment_checked' => 'unreviewed',
                'address_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/operation-control", [
                'payment_checked' => 'yes',
            ])
            ->assertOk()
            ->assertJsonPath('request.id', $request->id)
            ->assertJsonPath('request.operation_control.payment_checked', 'yes')
            ->assertJsonPath('request.assignment_blockers.payment_check_required', false)
            ->assertJsonPath('request.assignment_blockers.messages', [])
            ->assertJsonPath('operation_control_update.assignment_blockers.messages', []);
    }

    public function test_operation_control_note_action_performance_query_budget(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'address_checked' => 'yes',
                'door_photos_checked' => 'unreviewed',
            ],
        ]);
        $queryCount = 0;

        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/operation-control", [
                'note' => 'Sadece operasyon notu güncellendi.',
            ])
            ->assertOk()
            ->assertJsonMissingPath('request')
            ->assertJsonPath('operation_control_update.operation_control.note', 'Sadece operasyon notu güncellendi.');

        $this->assertLessThan(80, $queryCount, "Operation-control note action used {$queryCount} queries.");
    }

    public function test_assign_endpoint_uses_selected_technician_and_returns_fresh_payload(): void
    {
        $user = $this->adminUser();
        $staleTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Eski Usta',
            'phone' => '+905551111111',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $selectedTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Seçilen Usta',
            'phone' => '+905552222222',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'technical_service_technician_id' => $staleTechnician->id,
            'technician_name' => $staleTechnician->name,
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        $quote = TechnicalServiceRouteQuote::query()->create([
            'technical_service_request_id' => $request->id,
            'technician_id' => $selectedTechnician->id,
            'origin_latitude' => 41,
            'origin_longitude' => 29,
            'destination_latitude' => 41.1,
            'destination_longitude' => 29.1,
            'distance_meters' => 45000,
            'distance_km' => 45,
            'duration_seconds' => 1800,
            'threshold_km' => 30,
            'extra_km' => 15,
            'fee_per_km' => 10,
            'fee_amount' => 150,
            'travel_fee_required' => true,
            'provider' => 'google_routes',
            'status' => 'calculated',
            'raw_payload' => [
                'one_way_distance_meters' => 22500,
                'round_trip_distance_meters' => 45000,
            ],
            'calculated_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $selectedTechnician->id,
                'route_quote_id' => $quote->id,
                'travel_round_trip_km' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('request.technical_service_technician_id', $selectedTechnician->id)
            ->assertJsonPath('request.technician_name', $selectedTechnician->name)
            ->assertJsonPath('request.technician_phone', $selectedTechnician->phone)
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen')
            ->assertJsonPath('request.technician_approval_status', 'bekliyor')
            ->assertJsonPath('request.route_quote.id', $quote->id)
            ->assertJsonPath('request.route_quote.technician_id', $selectedTechnician->id)
            ->assertJsonPath('request.route_quote.fee_amount', 150);

        $request->refresh();

        $this->assertSame($selectedTechnician->id, $request->technical_service_technician_id);
        $this->assertSame($selectedTechnician->name, $request->technician_name);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'technician_updated',
            'title' => 'Usta bilgisi güncellendi',
        ]);
    }

    public function test_review_job_can_be_reassigned_from_closure_pending_without_invalid_transition(): void
    {
        $user = $this->adminUser();
        $oldTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Eski Son Kontrol Ustası',
            'phone' => '+905551111221',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $newTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Yeni Son Kontrol Ustası',
            'phone' => '+905551111222',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'REASSIGN-CLOSURE',
            'display_name' => 'Reassign Closure Locksmith',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REASSIGN-CLOSURE',
            'status' => 'Devam Ediyor',
            'workflow_status' => 'Müşteri Kapanış Onayı Bekleyen',
            'technical_service_technician_id' => $oldTechnician->id,
            'technician_name' => $oldTechnician->name,
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => now()->subHours(2),
            'scheduled_at' => now()->subDay(),
            'scheduled_date' => now()->subDay()->toDateString(),
            'scheduled_time' => '14:00',
            'field_completed_at' => now()->subHour(),
            'checklist_status' => 'tamamlandı',
            'document_status' => 'tamamlandı',
            'photo_status' => 'tamamlandı',
            'customer_closure_approval_status' => 'onaylandı',
            'customer_closure_approved_at' => now()->subMinutes(30),
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        $completionAction = TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $request->id,
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'technical_service_technician_id' => $oldTechnician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => ['note' => 'Son kontrol bekliyor.'],
        ]);
        $confirmation = TechnicalServiceCustomerConfirmation::query()->create([
            'technical_service_request_id' => $request->id,
            'token' => 'closure-reassign-token',
            'status' => TechnicalServiceCustomerConfirmation::STATUS_PENDING,
            'payload' => ['source' => 'test'],
        ]);
        $oldOffer = TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $oldTechnician->id,
            'labor_amount' => 1000,
            'route_fee_amount' => 100,
            'total_amount' => 1100,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_by' => $user->id,
            'sent_at' => now()->subHour(),
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $newTechnician->id,
                'travel_round_trip_km' => 20,
                'note' => 'Son kontrolden yeniden atama.',
                'assignment_offer' => [
                    'labor_amount' => 1200,
                    'route_fee_amount' => 80,
                    'total_amount' => 1280,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen')
            ->assertJsonPath('request.status', 'Atandı')
            ->assertJsonPath('request.technical_service_technician_id', $newTechnician->id)
            ->assertJsonPath('request.technician_approved_at', null)
            ->assertJsonPath('request.scheduled_at', null)
            ->assertJsonPath('request.customer_closure_approval_status', null)
            ->assertJsonPath('request.checklist_status', null);

        $request->refresh();
        $this->assertSame('Usta Onayı Bekleyen', $request->workflow_status);
        $this->assertSame('Atandı', $request->status);
        $this->assertNull($request->technician_approved_at);
        $this->assertNull($request->scheduled_at);
        $this->assertNull($request->scheduled_date);
        $this->assertNull($request->scheduled_time);
        $this->assertNull($request->customer_closure_approval_status);
        $this->assertNull($request->field_completed_at);

        $this->assertDatabaseHas('technical_service_assignment_archives', [
            'technical_service_request_id' => $request->id,
            'old_technician_id' => $oldTechnician->id,
            'new_technician_id' => $newTechnician->id,
        ]);
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'id' => $completionAction->id,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
        ]);
        $this->assertDatabaseHas('technical_service_customer_confirmations', [
            'id' => $confirmation->id,
            'status' => TechnicalServiceCustomerConfirmation::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('technical_service_assignment_offers', [
            'id' => $oldOffer->id,
            'status' => TechnicalServiceAssignmentOffer::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('technical_service_assignment_offers', [
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $newTechnician->id,
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'total_amount' => 1280,
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'reassign_after_review',
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'reassign_after_review_resolved',
        ]);
    }

    public function test_manual_review_reassign_prepares_assignment_message_without_real_whatsapp(): void
    {
        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.real_send_enabled' => true,
            'services.evolution.allow_unit_test_http_fake' => true,
            'services.partner_portal.public_url' => 'https://dashboard.test',
        ]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['message' => 'Workflow was started'], 200),
        ]);

        $user = $this->adminUser();
        $oldTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Eski Onaylı Usta',
            'phone' => '+905551110000',
            'city' => 'Sentetik Sehir 021',
            'active' => true,
        ]);
        $newTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Yeni Mesaj Ustası',
            'phone' => '+905552220000',
            'city' => 'Sentetik Sehir 021',
            'active' => true,
        ]);
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'REASSIGN-MESSAGE',
            'display_name' => 'Reassign Message Locksmith',
            'active' => true,
        ]);
        B2BPartnerCapability::query()->create([
            'partner_id' => $partner->id,
            'capability' => B2BPartner::TYPE_LOCKSMITH,
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $newTechnician->id,
            'relationship_type' => 'field_technician',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-MANUAL-WP',
            'status' => 'Devam Ediyor',
            'workflow_status' => 'Müşteri Kapanış Onayı Bekleyen',
            'product_name' => 'E10 SyntheticPerson038',
            'product_model' => 'Plus',
            'serial_number' => 'SN-ASSIGN-MSG',
            'activation_code' => 'ACT-ASSIGN-MSG',
            'stock_code' => 'STK-INTERNAL-ONLY',
            'technical_service_technician_id' => $oldTechnician->id,
            'technician_name' => $oldTechnician->name,
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => now()->subHours(3),
            'customer_closure_approval_status' => 'reddedildi',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $request->id,
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'technical_service_technician_id' => $oldTechnician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => ['customer_note' => 'Müşteri tekrar istedi.'],
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $newTechnician->id,
                'travel_round_trip_km' => 12,
                'assignment_offer' => [
                    'labor_amount' => 1500,
                    'route_fee_amount' => 100,
                    'total_amount' => 1600,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen')
            ->assertJsonPath('request.status', 'Atandı')
            ->assertJsonPath('request.technician_approved_at', null)
            ->assertJsonPath('request.assignment_offer.metadata.message_dispatch.status', TechnicalServiceMessageDispatch::STATUS_SUPPRESSED);

        $request->refresh();
        $this->assertNull($request->technician_approved_at);

        $dispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('event', 'assignment_offer_technician')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED, $dispatch->status);
        $this->assertSame('null_local', $dispatch->provider_key);
        $this->assertSame('system', $dispatch->channel);
        $this->assertFalse((bool) data_get($dispatch->metadata, 'provider_send_attempted'));
        $this->assertTrue((bool) data_get($dispatch->metadata, 'null_local_system_recorded'));
        $this->assertSame('905467647428', $dispatch->target_phone);
        $this->assertStringStartsWith('https://dashboard.test/partner/service-jobs?', (string) data_get($dispatch->request_payload, 'context.job_link'));
        $this->assertStringContainsString('partner_id='.$partner->id, (string) data_get($dispatch->request_payload, 'context.job_link'));
        $this->assertStringContainsString('job_id='.$request->id, (string) data_get($dispatch->request_payload, 'context.job_link'));
        $this->assertStringContainsString('Ürün: E10 SyntheticPerson038 / Plus', (string) data_get($dispatch->request_payload, 'message_text'));
        $this->assertStringContainsString('Seri: SN-ASSIGN-MSG', (string) data_get($dispatch->request_payload, 'message_text'));
        $this->assertStringContainsString('Aktivasyon: ACT-ASSIGN-MSG', (string) data_get($dispatch->request_payload, 'message_text'));
        $this->assertStringNotContainsString('STK-INTERNAL-ONLY', (string) data_get($dispatch->request_payload, 'message_text'));
        $this->assertStringContainsString('İşçilik: 1.500 TL', (string) data_get($dispatch->request_payload, 'message_text'));
        $this->assertStringContainsString('Yol: 100 TL', (string) data_get($dispatch->request_payload, 'message_text'));
        $this->assertStringContainsString('Toplam: 1.600 TL', (string) data_get($dispatch->request_payload, 'message_text'));
        $this->assertStringNotContainsString('TRY', (string) data_get($dispatch->request_payload, 'message_text'));

        Http::assertNothingSent();
    }

    public function test_rejected_job_can_be_sent_to_same_technician_again_and_clears_active_rejection(): void
    {
        $user = $this->adminUser();
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Tekrar Gönderilen Usta',
            'phone' => '+905551111223',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'REASSIGN-SAME',
            'display_name' => 'Reassign Same Locksmith',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'status' => 'Atandı',
            'workflow_status' => 'Usta Onayı Bekleyen',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        $rejection = TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $request->id,
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => ['reason' => 'time_not_suitable'],
            'note' => 'Uygun değil.',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'travel_round_trip_km' => 10,
                'note' => 'Aynı ustaya tekrar gönderildi.',
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen')
            ->assertJsonPath('request.technical_service_technician_id', $technician->id);

        $this->assertNotSame('Usta reddetti', data_get($response->json('request.attention'), 'reason'));

        $this->assertDatabaseHas('technical_service_assignment_archives', [
            'technical_service_request_id' => $request->id,
            'old_technician_id' => $technician->id,
            'new_technician_id' => $technician->id,
        ]);
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'id' => $rejection->id,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
        ]);
    }

    public function test_technician_earning_message_endpoint_records_audit_without_payment_side_effect(): void
    {
        $user = $this->adminUser();
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Hakediş Ustası',
            'phone_e164' => '+905551234567',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Usta Onayı Bekleyen',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 150,
            'mount_payment_status' => 'paid',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/technicians/{$technician->id}/earnings-message", [
                'labor_amount' => 3000,
                'route_fee_amount' => 150,
                'total_amount' => 3200,
                'manual_override' => true,
                'note' => 'Operasyon düzeltmesi',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('request.sale_and_payment.technician_earning_message.status', 'sent')
            ->assertJsonPath('request.sale_and_payment.technician_earning_message.total_amount', 3150)
            ->assertJsonPath('request.sale_and_payment.technician_earning_message.submitted_total_amount', 3200)
            ->assertJsonPath('request.sale_and_payment.technician_earning_message.total_amount_corrected', true)
            ->assertJsonPath('request.sale_and_payment.mount_payment_status', 'paid')
            ->assertJsonPath('request.mount_payment_status', 'paid')
            ->assertJson(fn ($json) => $json
                ->whereType('message_text', 'string')
                ->whereType('whatsapp_url', 'string')
                ->etc()
            );

        $request->refresh();

        $this->assertSame('paid', $request->mount_payment_status);
        $this->assertSame('sent', $request->operation_control_payload['technician_earning_message']['status'] ?? null);
        $this->assertEquals(3150.0, $request->operation_control_payload['technician_earning_message']['total_amount'] ?? null);
        $this->assertStringContainsString('Toplam hakediş: 3.150,00 TL', $request->operation_control_payload['technician_earning_message']['message_text'] ?? '');
        $this->assertStringNotContainsString('3.200,00 TL', $request->operation_control_payload['technician_earning_message']['message_text'] ?? '');
        $this->assertDatabaseHas('technical_service_assignment_offers', [
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => '3000.00',
            'route_fee_amount' => '150.00',
            'total_amount' => '3150.00',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'technician_earning_message_sent',
            'title' => 'Hakediş bilgisi gönderildi',
        ]);
        $this->assertDatabaseHas('technical_service_audit_logs', [
            'entity_type' => 'technical_service_request',
            'entity_id' => $request->id,
            'action_type' => 'technician_earning_message_sent',
        ]);
    }

    public function test_technician_revision_offer_visible_in_ops_detail_without_overwriting_approved_earning(): void
    {
        $technicianFixture = TechnicalServiceSyntheticDataFactory::locksmith(48);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => $technicianFixture['name'],
            'phone_e164' => $technicianFixture['phone_e164'],
            'active' => true,
        ]);
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'REVISION-OFFER-PARTNER',
            'display_name' => 'Revizyon Teklif Partner',
            'active' => true,
        ]);
        $portalUser = User::factory()->create(['role_code' => 'b2b_locksmith']);
        $request = $this->technicalServiceRequest([
            'status' => 'Atandı',
            'workflow_status' => 'Usta Onayı Bekleyen',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 3000,
            'route_fee_amount' => 1000,
            'total_amount' => 4000,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now()->subHour(),
        ]);
        TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $request->id,
            'partner_id' => $partner->id,
            'user_id' => $portalUser->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => [
                'labor_amount' => 3000,
                'route_fee_amount' => 1967.40,
                'note' => 'Yol pahalı',
                'submitted_at' => now()->toISOString(),
                'ops_review_required' => true,
            ],
            'note' => 'Yol pahalı',
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->refresh(), true);
        $opsState = app(TechnicalServiceOperationalStatePresenter::class)->present($request->refresh());

        $this->assertTrue($payload['technician_revision_offer']['exists']);
        $this->assertSame('pending', $payload['technician_revision_offer']['status']);
        $this->assertSame($technicianFixture['name'], $payload['technician_revision_offer']['technician_name']);
        $this->assertSame(3000.0, $payload['technician_revision_offer']['labor_earning']);
        $this->assertSame(1967.4, $payload['technician_revision_offer']['route_earning']);
        $this->assertSame(4967.4, $payload['technician_revision_offer']['total_earning']);
        $this->assertSame('Yol pahalı', $payload['technician_revision_offer']['note']);
        $this->assertSame(4000.0, $payload['assignment_offer']['total_amount']);
        $this->assertSame('ops', $opsState['dashboard_action_owner']);
        $this->assertSame('ops_action', $opsState['action_bucket']);
        $this->assertSame('Hakediş revize talebi', $opsState['display_action_label']);
    }

    public function test_partner_portal_revision_payout_revision_resolves_after_ops_earning_update_and_shows_appointment_state(): void
    {
        $user = $this->adminUser();
        $technicianFixture = TechnicalServiceSyntheticDataFactory::locksmith(48);
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'REVISION-PARTNER',
            'display_name' => 'Revizyon Partner',
            'active' => true,
        ]);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => $technicianFixture['name'],
            'phone_e164' => $technicianFixture['phone_e164'],
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'field_technician',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'status' => 'Atandı',
            'workflow_status' => 'Usta Onayı Bekleyen',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        $offer = TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 3000,
            'route_fee_amount' => 1000,
            'total_amount' => 4000,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now()->subHour(),
        ]);
        $revision = TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $request->id,
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => [
                'labor_amount' => 3000,
                'route_fee_amount' => 1967.40,
                'note' => 'Yol pahalı',
                'submitted_at' => now()->toISOString(),
                'ops_review_required' => true,
            ],
            'note' => 'Yol pahalı',
        ]);
        TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $request->id,
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => [
                'slots' => [['date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00']],
            ],
            'note' => 'Yarın uygunum.',
        ]);

        $response = $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/assignment-offers/{$offer->id}", [
                'labor_amount' => 3000,
                'route_fee_amount' => 1967.40,
                'total_amount' => 4967.40,
                'note' => 'Revize yanıtlandı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.assignment_offer.total_amount', 4967.4)
            ->assertJsonPath('request.technician_revision_offer.status', 'resolved');

        $this->assertSame('appointment_proposed', $response->json('request.operational_state.attention.action'));
        $this->assertSame('Usta randevu önerdi', $response->json('request.display_action_label'));
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'id' => $revision->id,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'assignment_offer_revised',
            'title' => 'Hakediş revize talebi yanıtlandı',
        ]);
        $revisionEvent = $request->events()->where('event_type', 'assignment_offer_revised')->latest('id')->firstOrFail();
        $this->assertSame($user->id, (int) $revisionEvent->author_user_id);
        $this->assertSame($user->id, (int) data_get($revisionEvent->metadata, 'actor_user_id'));
        $this->assertSame('technical_service_admin', data_get($revisionEvent->metadata, 'source'));
        $this->assertNotNull(data_get($revisionEvent->metadata, 'occurred_at_istanbul'));

        $partnerPayload = app(B2BPartnerPortalDataService::class)->safeServiceJobSummary($request->refresh(), $partner);

        $this->assertSame(4967.4, $partnerPayload['assignment_offer']['total_amount']);
        $this->assertSame('appointment_proposed_waiting', $partnerPayload['action_state']);
        $this->assertSame('Randevu önerildi', $partnerPayload['next_action']);
        $this->assertNotSame('price_revision_requested', $partnerPayload['action_state']);
        $this->assertSame(TechnicalServicePartnerJobAction::STATUS_APPLIED, $partnerPayload['price_revision_request']['status']);
    }

    public function test_resolved_earning_revision_is_not_ops_action_without_new_pending_review(): void
    {
        $technicianFixture = TechnicalServiceSyntheticDataFactory::locksmith(48);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => $technicianFixture['name'],
            'active' => true,
        ]);
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'RESOLVED-REVISION-PARTNER',
            'display_name' => 'Çözülmüş Revizyon Partner',
            'active' => true,
        ]);
        $portalUser = User::factory()->create(['role_code' => 'b2b_locksmith']);
        $request = $this->technicalServiceRequest([
            'status' => 'Atandı',
            'workflow_status' => 'Usta Onayı Bekleyen',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        $action = TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $request->id,
            'partner_id' => $partner->id,
            'user_id' => $portalUser->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => [
                'labor_amount' => 3000,
                'route_fee_amount' => 500,
                'note' => 'Revize',
            ],
            'note' => 'Revize',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 3000,
            'route_fee_amount' => 500,
            'total_amount' => 3500,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_REVISED,
            'sent_at' => now()->subHours(2),
            'metadata' => [
                'revised_at' => now()->toISOString(),
                'resolved_price_revision_action_ids' => [$action->id],
            ],
        ]);

        $opsState = app(TechnicalServiceOperationalStatePresenter::class)->present($request->refresh());

        $this->assertNotSame('ops', $opsState['dashboard_action_owner']);
        $this->assertNotSame('ops_action', $opsState['action_bucket']);
        $this->assertNotSame('Hakediş revize talebi', $opsState['display_action_label']);
    }

    public function test_assignment_is_blocked_until_payment_and_door_photo_controls_are_complete(): void
    {
        PageConfig::query()->updateOrCreate(
            ['page_code' => 'technical_service_admin'],
            ['layout_json' => [
                'technical_service' => [
                    'qr' => [
                        'pre_form_payment_for_mount_excluded_enabled' => true,
                    ],
                ],
            ]],
        );

        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technician_name' => 'Test Usta',
                'travel_round_trip_km' => 12,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'payment_decision',
                'operation_control.door_photos_checked',
            ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/operation-control", [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'unreviewed',
            ])
            ->assertOk();

        $session = $this->mountSessionForRequest($request);
        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-pending-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 1500,
            'currency' => 'TRY',
            'payment_url' => 'https://dashboard.emaksprime.com.tr/mount-payment/pending-control',
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technician_name' => 'Test Usta',
                'travel_round_trip_km' => 12,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['operation_control.door_photos_checked']);
    }

    public function test_assignment_allows_unreviewed_payment_check_when_payment_is_not_required(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'operation_control_payload' => [
                'payment_checked' => 'unreviewed',
                'door_photos_checked' => 'compatible',
            ],
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technician_name' => 'Ödeme Gerekmeyen Usta',
                'travel_round_trip_km' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen')
            ->assertJsonPath('request.assignment_blockers.payment_required_for_assignment', false)
            ->assertJsonPath('request.assignment_blockers.payment_check_required', false)
            ->assertJsonPath('request.assignment_blockers.messages', []);
    }

    public function test_assignment_gate_payload_matches_canonical_payment_requirement(): void
    {
        $service = app(TechnicalServiceWorkflowService::class);
        $notRequired = $this->technicalServiceRequest([
            'operation_control_payload' => [
                'payment_checked' => 'unreviewed',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        $required = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'operation_control_payload' => [
                'payment_checked' => 'unreviewed',
                'door_photos_checked' => 'compatible',
            ],
        ]);

        $notRequiredPayload = $service->serialize($notRequired->fresh(), true);
        $disabledSettingPayload = $service->serialize($required->fresh(), true);

        $this->assertFalse($notRequiredPayload['operation_control']['payment_required_for_assignment']);
        $this->assertFalse($notRequiredPayload['assignment_blockers']['payment_required_for_assignment']);
        $this->assertFalse($notRequiredPayload['assignment_blockers']['payment_check_required']);
        $this->assertSame([], $notRequiredPayload['assignment_blockers']['messages']);

        $this->assertFalse($disabledSettingPayload['operation_control']['pre_form_payment_control_enabled']);
        $this->assertFalse($disabledSettingPayload['operation_control']['show_payment_control']);
        $this->assertFalse($disabledSettingPayload['operation_control']['payment_required_for_assignment']);
        $this->assertFalse($disabledSettingPayload['assignment_blockers']['payment_required_for_assignment']);
        $this->assertFalse($disabledSettingPayload['assignment_blockers']['payment_check_required']);
        $this->assertSame('select_technician', $disabledSettingPayload['next_action_payload']['code']);
        $this->assertSame([], $disabledSettingPayload['assignment_blockers']['messages']);

        PageConfig::query()->updateOrCreate(
            ['page_code' => 'technical_service_admin'],
            ['layout_json' => [
                'technical_service' => [
                    'qr' => [
                        'pre_form_payment_for_mount_excluded_enabled' => true,
                    ],
                ],
            ]],
        );

        $requiredPayload = $service->serialize($required->fresh(), true);

        $this->assertTrue($requiredPayload['operation_control']['pre_form_payment_control_enabled']);
        $this->assertTrue($requiredPayload['operation_control']['show_payment_control']);
        $this->assertTrue($requiredPayload['operation_control']['payment_required_for_assignment']);
        $this->assertTrue($requiredPayload['assignment_blockers']['payment_required_for_assignment']);
        $this->assertTrue($requiredPayload['assignment_blockers']['payment_check_required']);
        $this->assertSame('payment_required', $requiredPayload['next_action_payload']['code']);
        $this->assertSame(
            ['Ödeme yöntemi netleşmeden atama güncellenemez. Ödeme linki oluşturun veya müşterinin ustaya ödeyeceği tutarı belirleyin.'],
            $requiredPayload['assignment_blockers']['messages'],
        );
    }

    public function test_assignment_update_allowed_when_pending_payment_link_exists_with_pre_form_payment_enabled(): void
    {
        PageConfig::query()->updateOrCreate(
            ['page_code' => 'technical_service_admin'],
            ['layout_json' => [
                'technical_service' => [
                    'qr' => [
                        'pre_form_payment_for_mount_excluded_enabled' => true,
                    ],
                ],
            ]],
        );

        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'operation_control_payload' => [
                'payment_checked' => 'unreviewed',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        $session = $this->mountSessionForRequest($request);

        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-pending-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 1500,
            'currency' => 'TRY',
            'payment_url' => 'https://dashboard.emaksprime.com.tr/mount-payment/pending-test',
            'raw_payload' => [
                'source' => 'operation_manual_amount',
                'purpose' => 'manual_mount_payment',
            ],
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertTrue($payload['operation_control']['pre_form_payment_control_enabled']);
        $this->assertSame('pending_online_payment', $payload['assignment_blockers']['payment_decision']);
        $this->assertFalse($payload['assignment_blockers']['payment_required_for_assignment']);
        $this->assertFalse($payload['assignment_blockers']['payment_check_required']);
        $this->assertSame([], $payload['assignment_blockers']['messages']);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technician_name' => 'Bekleyen Link Ustası',
                'travel_round_trip_km' => 12,
                'labor_earning_amount' => 1000,
                'route_earning_amount' => 500,
                'customer_direct_to_technician_amount' => 0,
                'confirm_assignment' => true,
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen')
            ->assertJsonPath('request.assignment_blockers.payment_decision', 'pending_online_payment')
            ->assertJsonPath('request.assignment_blockers.payment_check_required', false);
    }

    public function test_contact_log_endpoint_advances_customer_contact_workflow(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Müşteri Aranacak',
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/contact-log", [
                'action' => 'customer_called',
                'contact_method' => 'telefon',
                'note' => 'Müşteri ile ilk görüşme yapıldı',
            ])
            ->assertOk();

        $request->refresh();

        $this->assertNotNull($request->customer_contacted_at);
        $this->assertNotNull($request->customer_contact_status);
        $this->assertDatabaseHas('technical_service_audit_logs', [
            'entity_type' => 'technical_service_request',
            'entity_id' => $request->id,
            'action_type' => 'customer_called',
        ]);
    }

    public function test_audit_logs_endpoint_returns_workflow_history(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Atandı',
            'workflow_status' => 'Usta Onayı Bekleyen',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/workflow", [
                'action' => 'technician_revision_requested',
                'note' => 'Usta yeni tarih talep etti',
                'technician_revision_note' => 'Öğleden sonra uygun',
            ])
            ->assertOk();

        $response = $this->actingAs($user)
            ->getJson("/api/technical-service/requests/{$request->id}/audit-logs")
            ->assertOk()
            ->json('items');

        $this->assertIsArray($response);
        $this->assertNotEmpty($response);
        $this->assertSame('technician_revision_requested', $response[0]['action_type'] ?? null);
    }

    public function test_summary_includes_workflow_status_counts(): void
    {
        $this->technicalServiceRequest([
            'status' => 'Devam Ediyor',
            'workflow_status' => 'Parça Bekleniyor',
        ]);
        $this->technicalServiceRequest([
            'status' => 'Devam Ediyor',
            'workflow_status' => 'Belge / Fotoğraf Bekleyen',
        ]);

        $payload = $this->actingAs($this->adminUser())
            ->getJson('/api/technical-service/summary')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('workflow_status_counts', $payload);
        $this->assertIsArray($payload['workflow_status_counts']);
        $this->assertSame(2, array_sum($payload['workflow_status_counts']));
    }

    public function test_show_endpoint_returns_detail_payload_and_tolerates_missing_audit_log_table(): void
    {
        Storage::fake('public');
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Atandı',
            'workflow_status' => 'Usta Onayı Bekleyen',
            'travel_round_trip_km' => 42,
            'travel_billable_km' => 12,
            'travel_fee_amount' => 120,
            'technician_payment_amount' => 3000,
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'qr_link_id' => 10,
            'mount_session_id' => 20,
            'brand' => 'EMAKS PRIME',
            'stock_code' => 'STK-001',
            'activation_code' => '275023',
            'sale_mount_status' => 'montaj_haric',
            'mount_payment_status' => 'paid',
            'mount_payment_label' => 'Montaj ödemesi alındı',
            'mount_payment_reference' => 'fake-reference',
            'invoice_display_no' => 'FAT/123',
            'dispatch_display_no' => 'IRS/456',
            'order_display_no' => 'SIP/789',
            'location_latitude' => 40.9876543,
            'location_longitude' => 29.1234567,
            'location_formatted_address' => 'Caferağa Mahallesi, Kadıköy/Sentetik Sehir 021',
            'location_map_url' => 'https://www.google.com/maps?q=40.9876543,29.1234567',
            'building_no' => '12',
            'apartment_no' => 'A',
            'door_no' => '5',
            'floor_no' => '2',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        Storage::disk('public')->put('technical-service/requests/test/front.jpg', 'fake image');
        $request->uploads()->create([
            'field_code' => 'door_front_photo',
            'category' => TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO,
            'original_name' => 'front.jpg',
            'path' => 'technical-service/requests/test/front.jpg',
            'mime' => 'image/jpeg',
            'size' => 123456,
        ]);
        $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-SELECTED',
            'product_name' => 'E10 SyntheticPerson038',
            'customer_selected' => true,
            'customer_visible' => true,
            'customer_selectable' => true,
            'is_primary' => true,
            'is_returned' => false,
            'is_current_latest_sale' => true,
            'color_status' => 'green',
        ]);
        $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-HIDDEN',
            'product_name' => 'DDL720 SyntheticPerson038',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'dealer_or_partner',
            'is_returned' => false,
            'color_status' => 'orange',
        ]);
        $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-RETURNED',
            'product_name' => 'DDL720 SyntheticPerson038',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'returned',
            'is_returned' => true,
            'return_note' => 'İADE GELMİŞ',
            'return_date' => '2026-05-14',
            'return_document_no' => 'IAD/10',
            'color_status' => 'red',
        ]);

        Schema::dropIfExists('technical_service_audit_logs');

        $this->actingAs($user)
            ->getJson("/api/technical-service/requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('request.id', $request->id)
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen')
            ->assertJsonPath('request.customer_fee', 3000)
            ->assertJsonPath('request.technician_fee', 3000)
            ->assertJsonPath('request.travel_fee', 120)
            ->assertJsonPath('request.total_technician_cost', 3120)
            ->assertJsonPath('request.total_customer_collected', null)
            ->assertJsonPath('request.cost_delta', null)
            ->assertJsonPath('request.qr_source.source_channel', TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM)
            ->assertJsonPath('request.product.activation_code', '275023')
            ->assertJsonPath('request.product.stock_code', 'STK-001')
            ->assertJsonPath('request.sale_and_payment.sale_mount_label', 'Montaj Hariç')
            ->assertJsonPath('request.sale_and_payment.mount_payment_label', 'Montaj ödemesi alındı')
            ->assertJsonPath('request.sale_and_payment.payment_reference', 'fake-reference')
            ->assertJsonPath('request.documents.invoice_display_no', 'FAT/123')
            ->assertJsonPath('request.operation_control.payment_checked', 'yes')
            ->assertJsonPath('request.operation_control.door_photos_checked', 'compatible')
            ->assertJsonPath('request.assignment_blockers.messages', [])
            ->assertJsonPath('request.location.shared', true)
            ->assertJsonPath('request.location.map_url', 'https://www.google.com/maps?q=40.9876543,29.1234567')
            ->assertJsonPath('request.door_photos.0.field_code', 'door_front_photo')
            ->assertJsonPath('request.door_photos.0.category', TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO)
            ->assertJsonPath('request.door_photos.0.preview_url', route('api.technical-service.requests.uploads.show', [
                'technicalServiceRequest' => $request->id,
                'upload' => $request->uploads()->firstOrFail()->id,
            ], false))
            ->assertJsonPath('request.invoice_serials.selected_serials.0.serial_number', 'SN-SELECTED')
            ->assertJsonPath('request.invoice_serials.selected_serials.0.color_status', 'green')
            ->assertJsonPath('request.invoice_serials.hidden_serials.0.serial_number', 'SN-HIDDEN')
            ->assertJsonPath('request.invoice_serials.hidden_serials.0.hidden_reason_label', 'Müşteriye gösterilmedi - sorumluluk kodu: Boş')
            ->assertJsonPath('request.invoice_serials.returned_serials.0.serial_number', 'SN-RETURNED')
            ->assertJsonPath('request.invoice_serials.returned_serials.0.color_status', 'red')
            ->assertJsonPath('request.invoice_serials.has_returned', true)
            ->assertJsonPath('request.audit_logs_unavailable', true);

        $this->actingAs($user)
            ->get(route('api.technical-service.requests.uploads.show', [
                'technicalServiceRequest' => $request->id,
                'upload' => $request->uploads()->firstOrFail()->id,
            ]))
            ->assertOk();
    }

    public function test_ops_related_serials_are_bounded_in_detail_payload(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-SERIAL-BOUND',
        ]);

        foreach (range(1, 35) as $index) {
            $request->requestSerials()->create([
                'mrn' => $request->mrn,
                'serial_number' => sprintf('OPS-RELATED-%03d', $index),
                'product_name' => 'Ops Seri Test',
                'customer_selected' => false,
                'operation_added' => false,
                'customer_visible' => true,
                'customer_selectable' => true,
                'is_returned' => false,
            ]);
        }

        $this->actingAs($user)
            ->getJson("/api/technical-service/requests/{$request->id}")
            ->assertOk()
            ->assertJsonCount(20, 'request.invoice_serials.other_serials')
            ->assertJsonCount(20, 'request.invoice_serials.all_invoice_serials')
            ->assertJsonPath('request.invoice_serials.other_serial_count', 35)
            ->assertJsonPath('request.invoice_serials.all_invoice_serial_count', 35)
            ->assertJsonPath('request.invoice_serials.display_limit', 20);
    }

    public function test_invoice_serial_recheck_endpoint_updates_operation_payload(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'serial_number' => 'SN-PRIMARY',
        ]);
        $this->app->instance(
            MikroInvoiceSerialsService::class,
            new class extends MikroInvoiceSerialsService
            {
                public function forSerial(string $serialNo): array
                {
                    $rows = $this->normalizeRows([
                        [
                            'Faturadaki Seri No' => 'SN-PRIMARY',
                            'Stok Adı' => 'E10-AKILLI KAPI KİLİDİ-SİYAH (70LİK SyntheticPerson038)',
                            'Bu Fatura Bu Seri İçin Son Satış mı' => 'Evet',
                            'Satış Cari Grup Adı' => 'Perakende Son Müşteri',
                        ],
                        [
                            'Faturadaki Seri No' => 'SN-RETURNED',
                            'Stok Adı' => 'PHILIPS DDL720 Akıllı SyntheticPerson038',
                            'İade Notu' => 'İADE GELMİŞ',
                            'İade Tarihi' => '14.05.2026',
                            'İade Evrak Seri' => 'IAD',
                            'İade Evrak Sıra' => '12',
                            'Bu Fatura Bu Seri İçin Son Satış mı' => 'Evet',
                            'Satış Cari Grup Adı' => 'Perakende Son Müşteri',
                        ],
                    ], $serialNo);

                    return [
                        'rows' => $rows,
                        'all_invoice_serials' => $rows,
                        'selectable_customer_serials' => array_values(array_filter($rows, fn (array $row): bool => (bool) $row['customer_selectable'])),
                        'returned_serials' => array_values(array_filter($rows, fn (array $row): bool => (bool) $row['is_returned'])),
                        'meta' => [],
                        'request' => [],
                    ];
                }
            },
        );

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/recheck")
            ->assertOk()
            ->assertJsonPath('request.invoice_serials.selected_serials.0.serial_number', 'SN-PRIMARY')
            ->assertJsonPath('request.invoice_serials.returned_serials.0.serial_number', 'SN-RETURNED')
            ->assertJsonPath('request.invoice_serials.returned_serials.0.color_status', 'red');
    }

    public function test_invoice_serial_recheck_uses_fixture_alias_and_preserves_requested_primary(): void
    {
        config(['services.technical_service.invoice_serials_mode' => 'fixture']);
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'serial_number' => 'W720CWS05E250918A00705',
            'product_name' => 'Local Alias SyntheticPerson038',
            'product_model' => 'FIXTURE',
            'brand' => 'EMAKS PRIME',
            'qr_context_payload' => [
                'invoice_serials' => [
                    'all_invoice_serials' => [],
                    'selectable_customer_serials' => [],
                    'returned_serials' => [],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/recheck")
            ->assertOk()
            ->assertJsonPath('request.invoice_serials.selected_serials.0.serial_number', 'W720CWS05E250918A00705')
            ->assertJsonPath('request.invoice_serials.other_serials.0.serial_number', 'TEST-SERIAL-001')
            ->assertJsonPath('request.invoice_serials.other_serials.1.serial_number', 'TEST-SERIAL-002')
            ->assertJsonPath('request.invoice_serials.returned_serials.0.serial_number', 'TEST-SERIAL-003')
            ->assertJsonPath('request.invoice_serials.hidden_serials.0.serial_number', 'TEST-SERIAL-004')
            ->assertJsonPath('request.invoice_serials.has_multi_product', true);
    }

    public function test_invoice_serial_operation_add_remove_and_add_all_actions(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'customer_phone' => '+905372081655',
            'serial_number' => 'SN-PRIMARY',
        ]);
        $primary = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-PRIMARY',
            'product_name' => 'E10 SyntheticPerson038',
            'customer_selected' => true,
            'customer_visible' => true,
            'customer_selectable' => true,
            'operation_added' => true,
            'customer_phone' => $request->customer_phone,
            'linked_mrn' => $request->mrn,
            'is_primary' => true,
            'is_returned' => false,
            'color_status' => 'green',
        ]);
        $addable = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-ADDABLE',
            'product_name' => 'E10 SyntheticPerson038',
            'customer_selected' => false,
            'customer_visible' => true,
            'customer_selectable' => true,
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'orange',
        ]);
        $dealer = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-DEALER',
            'product_name' => 'DDL720 SyntheticPerson038',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'dealer_or_partner',
            'invoice_customer_type' => TechnicalServiceRequestSerial::CUSTOMER_DEALER_OR_PARTNER,
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'orange',
            'source_payload' => [
                'responsibility_code' => 'BAYİ SATIŞ',
                'normalized_responsibility_code' => 'BAYI SATIS',
                'is_responsibility_blocked' => true,
            ],
        ]);
        $project = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-PROJE',
            'product_name' => 'E20 SyntheticPerson038',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'responsibility_code_blocked',
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'orange',
            'source_payload' => [
                'responsibility_code' => 'PROJE',
                'normalized_responsibility_code' => 'PROJE',
                'is_responsibility_blocked' => true,
            ],
        ]);
        $gr = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-GR',
            'product_name' => 'E20 SyntheticPerson038',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'responsibility_code_blocked',
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'orange',
            'source_payload' => [
                'responsibility_code' => 'GR',
                'normalized_responsibility_code' => 'GR',
                'is_responsibility_blocked' => true,
            ],
        ]);
        $emptyResponsibility = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-EMPTY-RESP',
            'product_name' => 'E20 SyntheticPerson038',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'responsibility_code_blocked',
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'orange',
            'source_payload' => [
                'responsibility_code' => null,
                'normalized_responsibility_code' => null,
                'is_responsibility_blocked' => true,
            ],
        ]);
        $returned = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-RETURNED',
            'product_name' => 'DDL720 SyntheticPerson038',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'returned',
            'is_primary' => false,
            'is_returned' => true,
            'color_status' => 'red',
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$addable->id}/add")
            ->assertOk()
            ->assertJsonPath('request.invoice_serials.added_serial_count', 1)
            ->assertJsonPath('request.invoice_serials.addable_serial_count', 4);

        $addable->refresh();
        $this->assertTrue($addable->operation_added);
        $this->assertSame('green', $addable->color_status);
        $this->assertSame($request->mrn, $addable->linked_mrn);
        $this->assertSame($request->customer_phone, $addable->customer_phone);

        $this->actingAs($user)
            ->deleteJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$addable->id}/remove")
            ->assertOk()
            ->assertJsonPath('request.invoice_serials.added_serial_count', 0)
            ->assertJsonPath('request.invoice_serials.addable_serial_count', 5);

        $this->assertFalse($addable->refresh()->operation_added);
        $this->assertSame('Operasyon tarafından çıkarıldı', $addable->operation_note);

        $this->actingAs($user)
            ->deleteJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$primary->id}/remove")
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$returned->id}/add")
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$dealer->id}/add")
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$project->id}/add")
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$gr->id}/add")
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$emptyResponsibility->id}/add")
            ->assertOk();

        $bulkDealer = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-BULK-DEALER',
            'product_name' => 'DDL720 SyntheticPerson038',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'responsibility_code_blocked',
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'orange',
            'source_payload' => [
                'responsibility_code' => 'BAYİ SATIŞ',
                'normalized_responsibility_code' => 'BAYI SATIS',
                'is_responsibility_blocked' => true,
            ],
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/add-all")
            ->assertOk()
            ->assertJsonPath('request.invoice_serials.added_serial_count', 6)
            ->assertJsonPath('request.invoice_serials.addable_serial_count', 0)
            ->assertJsonPath('request.invoice_serials.returned_serial_count', 1);

        $this->assertTrue($addable->refresh()->operation_added);
        $this->assertTrue($dealer->refresh()->operation_added);
        $this->assertTrue($project->refresh()->operation_added);
        $this->assertTrue($gr->refresh()->operation_added);
        $this->assertTrue($emptyResponsibility->refresh()->operation_added);
        $this->assertTrue($bulkDealer->refresh()->operation_added);
        $this->assertFalse($returned->refresh()->operation_added);
    }

    public function test_fixture_invoice_serials_allow_ops_to_add_blocked_non_returned_rows_but_not_returned_rows(): void
    {
        config(['services.technical_service.invoice_serials_mode' => 'fixture']);
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'serial_number' => 'TEST-SERIAL-001',
            'customer_phone' => '+905551112233',
        ]);
        $result = app(MikroInvoiceSerialsService::class)->forSerial('TEST-SERIAL-001');

        app(MountRequestSubmitService::class)->syncRequestSerials(
            $request,
            $result['all_invoice_serials'],
            ['TEST-SERIAL-001'],
        );

        $returned = $request->requestSerials()->where('serial_number', 'TEST-SERIAL-003')->firstOrFail();
        $dealer = $request->requestSerials()->where('serial_number', 'TEST-SERIAL-004')->firstOrFail();
        $project = $request->requestSerials()->where('serial_number', 'TEST-SERIAL-005')->firstOrFail();
        $gr = $request->requestSerials()->where('serial_number', 'TEST-SERIAL-006')->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$returned->id}/add")
            ->assertUnprocessable();

        foreach ([$dealer, $project, $gr] as $serial) {
            $this->actingAs($user)
                ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$serial->id}/add")
                ->assertOk();

            $this->assertTrue($serial->refresh()->operation_added);
        }

        $this->assertFalse($returned->refresh()->operation_added);

        $bulkRequest = $this->technicalServiceRequest([
            'serial_number' => 'TEST-SERIAL-001',
            'customer_phone' => '+905551112233',
        ]);
        app(MountRequestSubmitService::class)->syncRequestSerials(
            $bulkRequest,
            $result['all_invoice_serials'],
            ['TEST-SERIAL-001'],
        );

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$bulkRequest->id}/invoice-serials/add-all")
            ->assertOk()
            ->assertJsonPath('request.invoice_serials.added_serial_count', 4)
            ->assertJsonPath('request.invoice_serials.addable_serial_count', 0)
            ->assertJsonPath('request.invoice_serials.returned_serial_count', 1);

        $this->assertTrue($bulkRequest->requestSerials()->where('serial_number', 'TEST-SERIAL-002')->firstOrFail()->operation_added);
        $this->assertTrue($bulkRequest->requestSerials()->where('serial_number', 'TEST-SERIAL-004')->firstOrFail()->operation_added);
        $this->assertTrue($bulkRequest->requestSerials()->where('serial_number', 'TEST-SERIAL-005')->firstOrFail()->operation_added);
        $this->assertTrue($bulkRequest->requestSerials()->where('serial_number', 'TEST-SERIAL-006')->firstOrFail()->operation_added);
        $this->assertFalse($bulkRequest->requestSerials()->where('serial_number', 'TEST-SERIAL-003')->firstOrFail()->operation_added);
    }

    public function test_frontend_contains_qr_operation_control_and_assignment_guard_labels(): void
    {
        $detailsSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));
        $cardSource = file_get_contents(resource_path('js/components/technical-service/TechnicalServiceKanbanCard.tsx'));
        $panelSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));

        $this->assertIsString($detailsSource);
        $this->assertIsString($cardSource);
        $this->assertIsString($panelSource);
        $compactDetailsSource = preg_replace('/\s+/', '', $detailsSource) ?? $detailsSource;

        foreach ([
            'Operasyon ve Montaj Kontrolü',
            'Ödeme / Montaj Bloğu',
            'Adres Kontrol Bloğu',
            'Randevu Kontrol Bloğu',
            'Ödeme kontrol edildi mi?',
            'Ödeme kontrol edilmedi',
            'Kapı görselleri bakıldı mı?',
            'Kapı görseli kontrol edilmedi',
            'Randevu tarihi güncellenecek mi?',
            'Usta atama engelleri',
            'Henüz kapı fotoğrafı yüklenmedi',
            'Haritada aç',
            'Konum paylaşıldı',
            'Kapı Ön Yüzü',
            'Satış montaj durumu',
            'Montaj ödeme durumu',
            'Montaj hariç / çoklu ürün onayı',
            'Bu işte montaj ödemesi henüz alınmadı; operasyon onayıyla servis ataması yapılacak.',
            'Bu onay tamamlanmadan servis atanamaz.',
            'Sıradaki Operasyon Aksiyonu',
            'Teknik detaylar',
            'Kapı görselleri kontrolü',
            'Montaj ödeme kontrolü',
            'backendAssignmentBlockersAvailable',
            'canonicalPaymentRequiresPayment && operationControl.payment_checked',
            'Servis atanabilir',
            'Ödeme aşaması',
            'Ödeme tutarı',
            'Ödeme referansı',
            'Diğer serileri kontrol et',
            'Talep edilen seriler',
            'Aynı faturadaki diğer seriler',
            'Müşteriye gösterilmeyen seriler',
            'İade gelen seriler',
            'Müşteriye gösterilmedi',
            'Serileri kontrol et',
            'Tüm uygun serileri montaja ekle',
            'Seri, ürün, model, marka veya fatura ara',
            'Bu aramada seri bulunamadı. Serileri kontrol et ile Mikro sorgusunu yenileyin.',
            'hasAnyFilteredInvoiceSerial',
            'showInvoiceSerialNoSearchResult',
            'Montaja ekle',
            'Çıkar',
            'İade - eklenemez',
            'Müşteriye gösterilmedi - sorumluluk kodu',
            'Ana seri - çıkarılamaz',
            'Son satış durumu',
            'Bu faturadaki güncel satış',
            'Bu fatura son satış değil',
            'Son satış kontrolü doğrulanamadı',
            'Son satış kontrolü çelişkili',
            'assignmentSubmitDisabled',
            'routeFeeEditorMessage',
            'Servis onay durumu',
            'Hakedi',
            'Maliyet',
            'Farkl',
            'Seçili seriler için farkl',
            'Önce usta seçin',
            'warning_labels',
            'serial.warning_labels?.length',
            'border-amber-300 bg-amber-100',
            'invoiceSerialsOpen',
            'fieldCompletionOpen',
            'preview_url ?? photo.download_url ?? photo.url',
            'Görüntü açılamadı',
        ] as $expectedText) {
            $this->assertTrue(
                str_contains($detailsSource, $expectedText)
                    || str_contains($compactDetailsSource, preg_replace('/\s+/', '', $expectedText) ?? $expectedText),
                "Expected ServiceRequestDetails.tsx to contain {$expectedText}."
            );
        }

        $this->assertStringNotContainsString('Montaj / Servis Durumu', $detailsSource);
        $this->assertStringNotContainsString('Aramaya uygun seri bulunamadı.', $detailsSource);
        $this->assertStringNotContainsString('Operasyon Kontrolü', $detailsSource);
        $this->assertStringNotContainsString('Ek Operasyon Kontrolleri', $detailsSource);
        $this->assertStringNotContainsString('Stok Kodu', $detailsSource);
        $this->assertStringNotContainsString('Stok kodu', $detailsSource);
        $this->assertStringNotContainsString('Bayi/Proje - otomatik eklenemez', $detailsSource);
        $this->assertStringNotContainsString('Müşteri tercihi', $detailsSource);
        $this->assertStringNotContainsString('Usta adres/Plus Code var, gerçek koordinat eksik', $detailsSource);
        $this->assertStringNotContainsString('Usta adres bilgisi eksik', $detailsSource);
        $this->assertStringNotContainsString('Usta koordinatı eksik olduğu için yol hesabı yapılamadı', $detailsSource);
        $this->assertStringContainsString("{ value: 'compatible', label: 'Uyumlu', tone: 'positive' }", $detailsSource);
        $this->assertStringContainsString("operationControlChange('door_photos_checked', value", $detailsSource);

        $approvalStateStart = strpos($detailsSource, 'const technicianApprovalState =');
        $approvalStateEnd = strpos($detailsSource, 'const parseEventTimestamp =');
        $this->assertIsInt($approvalStateStart);
        $this->assertIsInt($approvalStateEnd);
        $approvalStateSource = substr($detailsSource, $approvalStateStart, $approvalStateEnd - $approvalStateStart);
        $this->assertStringContainsString('approvalFieldText', $approvalStateSource);
        $this->assertStringNotContainsString('request.operationalState?.action_label', $approvalStateSource);
        $this->assertStringNotContainsString('request.operationalState?.display_action_label', $approvalStateSource);

        foreach ([
            'visibleTechnicianAssignmentInsights',
            'submittedTechnicianOption',
            'loadRequests({ silent: true, preserveSelection: true })',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $panelSource);
        }

        foreach ([
            'Aksiyon:',
            'Ödeme gerekli',
            'Usta seçilmeli',
            'Usta onayı bekliyor',
            'Son kontrol bekliyor',
            'Fotoğraf eksik',
            'Müşteri onayı bekliyor',
            'Çoklu ürün talebi',
            'Eklenebilir seri',
            'İade seri var',
            'border-orange-300 bg-orange-100 text-orange-950',
            'border-rose-300 bg-rose-100 text-rose-950',
            'border-blue-200 bg-blue-100 text-blue-900',
            'BadgeIconMark',
            'important: true',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $cardSource);
        }

        foreach ([
            'QR Montaj Formu',
            'Montaj ödemesi alındı',
            'Montaja eklenen',
            'Usta Atandı',
            'Usta Onayladı',
        ] as $removedCardTag) {
            $this->assertStringNotContainsString($removedCardTag, $cardSource);
        }
    }

    public function test_door_compatible_frontend_uses_lightweight_update_without_full_reload(): void
    {
        $panelSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));

        $this->assertIsString($panelSource);
        $this->assertStringContainsString('operation_control_update', $panelSource);
        $this->assertStringContainsString('applyOperationControlUpdate', $panelSource);
        $this->assertStringContainsString('optimisticOperationControlUpdate', $panelSource);
        $this->assertStringContainsString('previousRequestsSnapshot', $panelSource);

        $handlerStart = strpos($panelSource, 'const handleOperationControlChange = async');
        $handlerEnd = strpos($panelSource, 'const handleInvoiceSerialRecheck = async');

        $this->assertIsInt($handlerStart);
        $this->assertIsInt($handlerEnd);

        $handlerSource = substr($panelSource, $handlerStart, $handlerEnd - $handlerStart);

        $this->assertStringNotContainsString('loadRequests(', $handlerSource);
        $this->assertStringNotContainsString('loadSummary(', $handlerSource);
        $this->assertStringContainsString('operation_control_update', $handlerSource);
    }

    public function test_serial_family_other_serial_search_no_result_message_is_panel_scoped(): void
    {
        $detailsSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($detailsSource);
        $compactDetailsSource = preg_replace('/\s+/', ' ', $detailsSource) ?? $detailsSource;
        $this->assertStringContainsString('filterInvoiceSerials', $detailsSource);
        $this->assertStringContainsString('invoiceSerialMatchesSearch', $detailsSource);
        $this->assertStringContainsString('canonicalInvoiceSerialRows', $detailsSource);
        $this->assertStringContainsString('allSearchableInvoiceSerials', $detailsSource);
        $this->assertStringContainsString('sourceHiddenInvoiceSerials = hasCanonicalInvoiceSerialRows', $detailsSource);
        $this->assertStringContainsString('filteredRequestedInvoiceSerials', $detailsSource);
        $this->assertStringContainsString('filteredHiddenInvoiceSerials', $detailsSource);
        $this->assertStringContainsString('filteredAllSearchableInvoiceSerials', $detailsSource);
        $this->assertStringContainsString('hasAnyFilteredInvoiceSerial', $detailsSource);
        $this->assertStringContainsString('showInvoiceSerialNoSearchResult = invoiceSerialSearchActive && !invoiceSerialRecheckInFlight && allSearchableInvoiceSerials.length > 0 && !hasAnyFilteredInvoiceSerial', $compactDetailsSource);
        $this->assertStringContainsString('Seri, ürün, model, marka veya fatura ara', $detailsSource);
        $this->assertStringContainsString('Bu aramada seri bulunamadı. Serileri kontrol et ile Mikro sorgusunu yenileyin.', $compactDetailsSource);
        $this->assertStringNotContainsString('Aramaya uygun seri bulunamadı.', $detailsSource);
    }

    public function test_payment_modal_payment_amount_requires_positive_amount_before_link_create(): void
    {
        $detailsSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($detailsSource);
        $compactDetailsSource = preg_replace('/\s+/', ' ', $detailsSource) ?? $detailsSource;
        $this->assertStringContainsString('Yeni ek ödeme linki tutarı', $compactDetailsSource);
        $this->assertStringContainsString('Ödeme linki için ödeme tutarını girin. Tutar 0 TL üzerinde olmalı.', $compactDetailsSource);
        $this->assertStringContainsString('Ödeme tutarı net değil. Link oluşturmak için tutar girin.', $compactDetailsSource);
        $this->assertStringContainsString('Ödeme Al', $detailsSource);
        $this->assertStringContainsString('Ödeme Düzenle', $detailsSource);
        $this->assertStringContainsString('Ödenmiş kayıtlar salt okunur. Ek tahsilat gerekiyorsa yeni ödeme linki oluşturabilirsiniz.', $compactDetailsSource);
        $this->assertStringContainsString('Toplam alınan ödeme', $detailsSource);
        $this->assertStringContainsString('Bekleyen ödeme linkleri', $detailsSource);
        $this->assertStringContainsString('İptal edilen linkler', $detailsSource);
        $this->assertStringContainsString('Ödenmiş tahsilatlar', $detailsSource);
        $this->assertStringContainsString('Bekleyen linkler', $detailsSource);
        $this->assertStringContainsString('İptal et', $detailsSource);
        $this->assertStringNotContainsString('Ödeme alındı; bu link bu fazda düzenlenemez.', $detailsSource);
        $this->assertStringNotContainsString('Ödeme alındı; bu fazda ödenmiş link düzenlenmez.', $detailsSource);
        $this->assertStringContainsString('paymentLinkActionLabel', $detailsSource);
        $this->assertStringContainsString('Tutar kaynağı: Manuel giriş gerekli', $detailsSource);
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $timestamps
     */
    private function technicalServiceRequest(array $overrides = [], array $timestamps = []): TechnicalServiceRequest
    {
        $request = TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-TEST-'.uniqid(),
            'customer_name' => 'Test Müşteri',
            'customer_phone' => '5550000000',
            'customer_city' => 'Sentetik Sehir 001',
            'customer_district' => 'Seyhan',
            'service_address' => 'Test adres',
            'product_name' => 'Test Ürün',
            'serial_number' => 'SN-TEST-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ], $overrides));

        if ($timestamps !== []) {
            foreach ($timestamps as $column => $value) {
                $request->{$column} = $value;
            }

            $request->saveQuietly();
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function partnerJobAction(TechnicalServiceRequest $request, array $attributes): TechnicalServicePartnerJobAction
    {
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'STATE-PARTNER-'.uniqid(),
            'display_name' => 'State Partner '.uniqid(),
            'active' => true,
        ]);

        return TechnicalServicePartnerJobAction::query()->create(array_merge([
            'technical_service_request_id' => $request->id,
            'partner_id' => $partner->id,
            'user_id' => User::factory()->create(['role_code' => 'b2b_locksmith'])->id,
            'payload' => [],
        ], $attributes));
    }

    private function addSelectedSerial(TechnicalServiceRequest $request, string $serialNumber, bool $primary = true): TechnicalServiceRequestSerial
    {
        return $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => $serialNumber,
            'product_name' => 'Test Ürün',
            'customer_selected' => true,
            'customer_visible' => true,
            'customer_selectable' => true,
            'is_primary' => $primary,
            'is_returned' => false,
            'is_current_latest_sale' => true,
            'color_status' => 'green',
        ]);
    }

    private function mountSessionForRequest(TechnicalServiceRequest $request): TechnicalServiceMountSession
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => $request->serial_number,
            'product_name' => $request->product_name,
            'product_model' => $request->product_model,
            'brand' => $request->brand,
        ]);

        $session = TechnicalServiceMountSession::query()->create([
            'technical_service_qr_link_id' => $link->id,
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken('session-'.$request->id.'-'.uniqid()),
            'serial_number' => $request->serial_number,
            'sale_mount_status' => $request->sale_mount_status ?? TechnicalServiceMountSession::SALE_UNKNOWN,
            'mount_payment_status' => $request->mount_payment_status,
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
            'context_payload' => [],
        ]);

        $request->forceFill([
            'qr_link_id' => $link->id,
            'mount_session_id' => $session->id,
        ])->save();

        return $session;
    }
}
