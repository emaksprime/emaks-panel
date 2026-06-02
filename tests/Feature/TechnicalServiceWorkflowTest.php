<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceAssignmentArchive;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceCustomerConfirmation;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\TechnicalServiceRouteQuote;
use App\Models\TechnicalServiceTechnician;
use App\Models\TechnicalServiceMountSession;
use App\Models\User;
use App\Services\TechnicalService\MikroInvoiceSerialsService;
use App\Services\TechnicalService\MountRequestSubmitService;
use App\Services\TechnicalService\TechnicalServicePaymentStatusResolver;
use App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
            'city' => 'Adana',
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

    public function test_mount_excluded_multi_product_assignment_requires_acknowledgement(): void
    {
        $user = $this->adminUser();
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Çoklu Ürün Ustası',
            'phone' => '+905551111114',
            'city' => 'Adana',
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
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'mount_exclusion_acknowledged',
                'mount_exclusion_note',
            ]);
    }

    public function test_mount_excluded_multi_product_assignment_persists_acknowledgement(): void
    {
        $user = $this->adminUser();
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Onaylı Çoklu Ürün Ustası',
            'phone' => '+905551111115',
            'city' => 'Adana',
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
            'city' => 'Adana',
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
            'city' => 'Adana',
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
            'city' => 'Adana',
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
            ->assertJsonPath('request.operation_control.payment_checked', 'yes')
            ->assertJsonPath('request.operation_control.door_photos_checked', 'compatible')
            ->assertJsonPath('request.assignment_blockers.messages', []);

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

    public function test_assign_endpoint_uses_selected_technician_and_returns_fresh_payload(): void
    {
        $user = $this->adminUser();
        $staleTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Eski Usta',
            'phone' => '+905551111111',
            'city' => 'Adana',
            'active' => true,
        ]);
        $selectedTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Seçilen Usta',
            'phone' => '+905552222222',
            'city' => 'Adana',
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
            'city' => 'Adana',
            'active' => true,
        ]);
        $newTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Yeni Son Kontrol Ustası',
            'phone' => '+905551111222',
            'city' => 'Adana',
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
            ->assertJsonPath('request.customer_closure_approval_status', null)
            ->assertJsonPath('request.checklist_status', null);

        $request->refresh();
        $this->assertSame('Usta Onayı Bekleyen', $request->workflow_status);
        $this->assertSame('Atandı', $request->status);
        $this->assertNull($request->technician_approved_at);
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

    public function test_manual_review_reassign_dispatches_assignment_whatsapp_when_real_send_enabled(): void
    {
        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.real_send_enabled' => true,
            'services.partner_portal.public_url' => 'https://dashboard.test',
        ]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['message' => 'Workflow was started'], 200),
        ]);

        $user = $this->adminUser();
        $oldTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Eski Onaylı Usta',
            'phone' => '+905551110000',
            'city' => 'İstanbul',
            'active' => true,
        ]);
        $newTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Yeni Mesaj Ustası',
            'phone' => '+905552220000',
            'city' => 'İstanbul',
            'active' => true,
        ]);
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'REASSIGN-MESSAGE',
            'display_name' => 'Reassign Message Locksmith',
            'active' => true,
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-MANUAL-WP',
            'status' => 'Devam Ediyor',
            'workflow_status' => 'Müşteri Kapanış Onayı Bekleyen',
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
            ->assertJsonPath('request.assignment_offer.metadata.message_dispatch.status', TechnicalServiceMessageDispatch::STATUS_SENT);

        $request->refresh();
        $this->assertNull($request->technician_approved_at);

        $dispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('event', 'assignment_offer_technician')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $dispatch->status);
        $this->assertSame('905467647428', $dispatch->target_phone);
        $this->assertStringStartsWith('https://dashboard.test/partner/service-jobs?job_id=', (string) data_get($dispatch->request_payload, 'job_link'));

        Http::assertSent(fn ($httpRequest): bool => $httpRequest->url() === 'https://n8n.test/webhook/emaks/evo/send-message'
            && $httpRequest['target_phone'] === '905467647428'
            && $httpRequest['event'] === 'assignment_offer_technician'
            && str_starts_with((string) $httpRequest['job_link'], 'https://dashboard.test/partner/service-jobs?job_id='));
    }

    public function test_rejected_job_can_be_sent_to_same_technician_again_and_clears_active_rejection(): void
    {
        $user = $this->adminUser();
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Tekrar Gönderilen Usta',
            'phone' => '+905551111223',
            'city' => 'Adana',
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
            'city' => 'Adana',
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

    public function test_assignment_is_blocked_until_payment_and_door_photo_controls_are_complete(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technician_name' => 'Test Usta',
                'travel_round_trip_km' => 12,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'operation_control.payment_checked',
                'operation_control.door_photos_checked',
            ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/operation-control", [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'unreviewed',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technician_name' => 'Test Usta',
                'travel_round_trip_km' => 12,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['operation_control.door_photos_checked']);
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
            'location_formatted_address' => 'Caferağa Mahallesi, Kadıköy/İstanbul',
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
            'product_name' => 'E10 Kilit',
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
            'product_name' => 'DDL720 Kilit',
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
            'product_name' => 'DDL720 Kilit',
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
            ->assertJsonPath('request.cost_delta', -120)
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
            ]))
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

    public function test_invoice_serial_recheck_endpoint_updates_operation_payload(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'serial_number' => 'SN-PRIMARY',
        ]);
        $this->app->instance(
            MikroInvoiceSerialsService::class,
            new class extends MikroInvoiceSerialsService {
                public function forSerial(string $serialNo): array
                {
                    $rows = $this->normalizeRows([
                        [
                            'Faturadaki Seri No' => 'SN-PRIMARY',
                            'Stok Adı' => 'E10-AKILLI KAPI KİLİDİ-SİYAH (70LİK KİLİT)',
                            'Bu Fatura Bu Seri İçin Son Satış mı' => 'Evet',
                            'Satış Cari Grup Adı' => 'Perakende Son Müşteri',
                        ],
                        [
                            'Faturadaki Seri No' => 'SN-RETURNED',
                            'Stok Adı' => 'PHILIPS DDL720 Akıllı Kilit',
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
            'product_name' => 'Local Alias Kilit',
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
            'product_name' => 'E10 Kilit',
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
            'product_name' => 'E10 Kilit',
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
            'product_name' => 'DDL720 Kilit',
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
            'product_name' => 'E20 Kilit',
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
            'product_name' => 'E20 Kilit',
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
            'product_name' => 'E20 Kilit',
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
            'product_name' => 'DDL720 Kilit',
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
            'product_name' => 'DDL720 Kilit',
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
            'Servis atanabilir',
            'Ödeme aşaması',
            'Ödeme tutarı',
            'Ödeme referansı',
            'Faturadaki diğer serileri gör',
            'Talep edilen seriler',
            'Aynı faturadaki diğer seriler',
            'Müşteriye gösterilmeyen seriler',
            'İade gelen seriler',
            'Müşteriye gösterilmedi',
            'Tekrar kontrol et',
            'Tüm uygun serileri montaja ekle',
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
            'Kabul / red',
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
            $this->assertStringContainsString($expectedText, $detailsSource);
        }

        $this->assertStringNotContainsString('Montaj / Servis Durumu', $detailsSource);
        $this->assertStringNotContainsString('Operasyon Kontrolü', $detailsSource);
        $this->assertStringNotContainsString('Ek Operasyon Kontrolleri', $detailsSource);
        $this->assertStringNotContainsString('Stok Kodu', $detailsSource);
        $this->assertStringNotContainsString('Stok kodu', $detailsSource);
        $this->assertStringNotContainsString('Bayi/Proje - otomatik eklenemez', $detailsSource);
        $this->assertStringNotContainsString('Müşteri tercihi', $detailsSource);
        $this->assertStringNotContainsString('Usta adres/Plus Code var, gerçek koordinat eksik', $detailsSource);
        $this->assertStringNotContainsString('Usta adres bilgisi eksik', $detailsSource);
        $this->assertStringNotContainsString('Usta koordinatı eksik olduğu için yol hesabı yapılamadı', $detailsSource);

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

    private function adminUser(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $timestamps
     */
    private function technicalServiceRequest(array $overrides = [], array $timestamps = []): TechnicalServiceRequest
    {
        $request = TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-TEST-'.uniqid(),
            'customer_name' => 'Test Müşteri',
            'customer_phone' => '5550000000',
            'customer_city' => 'Adana',
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
     * @param array<string, mixed> $attributes
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
