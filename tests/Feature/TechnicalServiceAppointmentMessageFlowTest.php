<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMessageTemplate;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceAppointmentMessageDispatchService;
use App\Services\Messaging\TechnicalServiceManualE2ERunContext;
use App\Services\Messaging\TechnicalServiceMessageTemplateService;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Messaging\TechnicalServiceWorkflowMessageDispatchService;
use App\Services\Payments\TechnicalServicePaymentProviderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TechnicalServiceAppointmentMessageFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointment_message_flow_ops_appointment_approved_creates_customer_and_technician_dispatches_without_provider_call(): void
    {
        Http::fake();
        $this->configureMessaging([
            'appointment_approved_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
            'appointment_approved_technician' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        $actor = $this->admin();
        $request = $this->technicalServiceRequest([
            'scheduled_date' => '2026-07-08',
            'scheduled_time' => '14:00',
        ]);
        $action = $this->appointmentAction($request, '2026-07-08', '14:00', '16:00');

        $summary = app(TechnicalServiceAppointmentMessageDispatchService::class)->dispatchApproval(
            $request->refresh(),
            $action,
            $actor,
            ['slot' => ['date' => '2026-07-08', 'start_time' => '14:00', 'end_time' => '16:00']],
        );

        $this->assertSame('appointment_approved', $summary['event_type']);
        $this->assertSame(2, $summary['queued']);
        $this->assertSame(0, $summary['blocked']);
        $this->assertSame(0, $summary['duplicate_blocked']);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'appointment_approved_customer',
            'channel' => 'whatsapp',
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'customer',
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
            'test_redirect_applied' => true,
        ]);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'appointment_approved_technician',
            'channel' => 'whatsapp',
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'technician',
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
            'test_redirect_applied' => true,
        ]);

        $customer = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'appointment_approved_customer')
            ->firstOrFail();
        $technician = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'appointment_approved_technician')
            ->firstOrFail();

        $this->assertSame('9054***428', $customer->effective_target_phone_mask);
        $this->assertSame('905467647428', $customer->target_phone);
        $this->assertSame('905559998877', $customer->original_phone);
        $this->assertStringContainsString('13:00 - 19:00 arası', (string) ($customer->request_payload['body'] ?? ''));
        $this->assertStringNotContainsString('14:00 - 16:00', (string) ($customer->request_payload['body'] ?? ''));
        $this->assertStringContainsString('14:00 - 16:00', (string) ($technician->request_payload['body'] ?? ''));
        $this->assertStringContainsString('İş Kartı', (string) ($technician->request_payload['body'] ?? ''));
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'message_queued',
        ]);
        Http::assertNothingSent();
    }

    public function test_channel_policy_approval_whatsapp_and_sms_creates_two_dispatches_and_fallback_creates_primary_only(): void
    {
        $actor = $this->admin();
        $this->configureMessaging([
            'appointment_approved_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
        ]);
        $request = $this->technicalServiceRequest();
        $action = $this->appointmentAction($request);

        $summary = app(TechnicalServiceAppointmentMessageDispatchService::class)->dispatchApproval(
            $request->refresh(),
            $action,
            $actor,
            ['slot' => ['date' => '2026-07-08', 'start_time' => '14:00', 'end_time' => '16:00']],
        );
        $this->assertSame(2, $summary['queued']);
        $this->assertSame(['sms', 'whatsapp'], TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'appointment_approved_customer')
            ->pluck('channel')
            ->sort()
            ->values()
            ->all());

        $this->configureMessaging([
            'appointment_approved_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_primary_sms_fallback'],
        ]);
        $fallbackRequest = $this->technicalServiceRequest(['mrn' => 'MRN-FALLBACK-'.Str::upper(Str::random(5))]);
        $fallbackAction = $this->appointmentAction($fallbackRequest);

        $fallbackSummary = app(TechnicalServiceAppointmentMessageDispatchService::class)->dispatchApproval(
            $fallbackRequest->refresh(),
            $fallbackAction,
            $actor,
            ['slot' => ['date' => '2026-07-08', 'start_time' => '14:00', 'end_time' => '16:00']],
        );

        $this->assertSame(1, $fallbackSummary['queued']);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $fallbackRequest->id,
            'message_type' => 'appointment_approved_customer',
            'channel' => 'whatsapp',
            'channel_policy' => 'whatsapp_primary_sms_fallback',
        ]);
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $fallbackRequest->id,
            'message_type' => 'appointment_approved_customer',
            'channel' => 'sms',
        ]);
    }

    public function test_target_parity_controlled_smoke_plans_customer_and_technician_whatsapp_sms_to_role_targets(): void
    {
        $actor = $this->admin();
        $this->configureMessaging([
            'appointment_approved_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
            'appointment_approved_technician' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
        ]);
        $request = $this->technicalServiceRequest([
            'customer_name' => 'PR88 REL4E6 Test Müşteri',
            'customer_phone' => '05372081633',
            'mrn' => 'MRN-PR88-REL4E6-UNIT',
            'scheduled_date' => '2026-07-08',
            'scheduled_time' => '14:00',
        ]);
        $request->technicianRecord->forceFill(['phone' => '0546 764 74 28', 'phone_e164' => '905467647428'])->save();
        $action = $this->appointmentAction($request, '2026-07-08', '14:00', '16:00');

        $summary = app(TechnicalServiceAppointmentMessageDispatchService::class)->dispatchApproval(
            $request->refresh(),
            $action,
            $actor,
            [
                'slot' => ['date' => '2026-07-08', 'start_time' => '14:00', 'end_time' => '16:00', 'label' => 'OPS özel slot etiketi'],
                'metadata' => ['test_smoke' => true, 'pr88_rel' => 'REL4E6', 'smoke_run_id' => 'PR88-REL4E6-UNIT'],
                'controlled_smoke_targets' => [
                    'customer' => '905372081633',
                    'technician' => '905467647428',
                ],
            ],
        );

        $this->assertSame(4, $summary['queued']);

        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->orderBy('recipient_role')
            ->orderBy('channel')
            ->get();

        $this->assertCount(4, $dispatches);
        $customerTargets = $dispatches->where('recipient_role', 'customer')->pluck('target_phone')->unique()->values()->all();
        $technicianTargets = $dispatches->where('recipient_role', 'technician')->pluck('target_phone')->unique()->values()->all();
        $this->assertSame(['905372081633'], $customerTargets);
        $this->assertSame(['905467647428'], $technicianTargets);
        $this->assertSame(['sms', 'whatsapp'], $dispatches->where('recipient_role', 'customer')->pluck('channel')->sort()->values()->all());
        $this->assertSame(['sms', 'whatsapp'], $dispatches->where('recipient_role', 'technician')->pluck('channel')->sort()->values()->all());

        foreach ($dispatches->where('recipient_role', 'customer') as $dispatch) {
            $body = (string) ($dispatch->request_payload['body'] ?? '');
            $this->assertStringContainsString('MRN-PR88-REL4E6-UNIT', $body);
            $this->assertStringContainsString('13:00 - 19:00 arası', $body);
            $this->assertStringNotContainsString('OPS özel slot etiketi', $body);
            $this->assertStringNotContainsString('İş Kartı', $body);
            $this->assertSame('905372081633', data_get($dispatch->metadata, 'role_target_phone'));
        }

        foreach ($dispatches->where('recipient_role', 'technician') as $dispatch) {
            $body = (string) ($dispatch->request_payload['body'] ?? '');
            $this->assertStringContainsString('14:00 - 16:00', $body);
            $this->assertMatchesRegularExpression('/(?:İş Kartı|Kart\s+https?:\/\/)/u', $body);
            if ($dispatch->channel === 'sms') {
                $this->assertStringContainsString('/pj/'.$request->id, $body);
            } else {
                $this->assertStringContainsString('job_id='.$request->id, $body);
            }
            $this->assertStringNotContainsString('Sayın PR88 REL4E6 Test Müşteri', $body);
            $this->assertSame('905467647428', data_get($dispatch->metadata, 'role_target_phone'));
        }
    }

    public function test_rel4e_smoke_customer_body_contains_payment_note_and_technician_body_contains_earning_amounts(): void
    {
        $actor = $this->admin();
        $this->configureMessaging([
            'appointment_approved_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
            'appointment_approved_technician' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
        ]);
        $request = $this->technicalServiceRequest([
            'customer_name' => 'PR88 REL4E9 Test Müşteri',
            'customer_phone' => '05372081633',
            'mrn' => 'MRN-PR88-REL4E9-UNIT',
            'scheduled_date' => '2026-07-08',
            'scheduled_time' => '14:00',
            'technician_payment_amount' => 900,
            'travel_fee_amount' => 350,
        ]);
        $request->technicianRecord->forceFill(['phone' => '0546 764 74 28', 'phone_e164' => '905467647428'])->save();
        $action = $this->appointmentAction($request, '2026-07-08', '14:00', '16:00');

        $summary = app(TechnicalServiceAppointmentMessageDispatchService::class)->dispatchApproval(
            $request->refresh(),
            $action,
            $actor,
            [
                'slot' => ['date' => '2026-07-08', 'start_time' => '14:00', 'end_time' => '16:00'],
                'context' => [
                    'payer_state_key' => 'customer_pays_technician',
                    'customer_payment_amount' => 1250,
                    'customer_payment_note_text' => 'Ödemeler nakit ve havale kabul edilmektedir.',
                ],
                'metadata' => ['test_smoke' => true, 'pr88_rel' => 'REL4E9', 'smoke_run_id' => 'PR88-REL4E9-UNIT'],
                'controlled_smoke_targets' => [
                    'customer' => '905372081633',
                    'technician' => '905467647428',
                ],
            ],
        );

        $this->assertSame(4, $summary['queued']);

        $customerWhatsapp = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'appointment_approved_customer')
            ->where('channel', 'whatsapp')
            ->firstOrFail();
        $customerSms = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'appointment_approved_customer')
            ->where('channel', 'sms')
            ->firstOrFail();
        $technicianWhatsapp = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'appointment_approved_technician')
            ->where('channel', 'whatsapp')
            ->firstOrFail();

        $customerWhatsappBody = (string) ($customerWhatsapp->request_payload['body'] ?? '');
        $customerSmsBody = (string) ($customerSms->request_payload['body'] ?? '');
        $technicianWhatsappBody = (string) ($technicianWhatsapp->request_payload['body'] ?? '');

        $this->assertStringContainsString('MRN-PR88-REL4E9-UNIT', $customerWhatsappBody);
        $this->assertStringContainsString('13:00 - 19:00 arası', $customerWhatsappBody);
        $this->assertStringContainsString('Not: Ödemeler nakit ve havale kabul edilmektedir.', $customerWhatsappBody);
        $this->assertStringContainsString('Nakit/havale kabul edilir.', $customerSmsBody);
        $this->assertStringContainsString('Hakediş Özeti', $technicianWhatsappBody);
        $this->assertStringContainsString('İşçilik/Montaj: 900,00 TL', $technicianWhatsappBody);
        $this->assertStringContainsString('Yol: 350,00 TL', $technicianWhatsappBody);
        $this->assertStringContainsString('Toplam: 1.250,00 TL', $technicianWhatsappBody);
        $this->assertStringNotContainsString('Hakediş Özeti', $customerWhatsappBody);
    }

    public function test_manual_e2e_dispatches_are_tagged_and_allowlist_blocks_wrong_target(): void
    {
        $actor = $this->admin();
        $this->configureMessaging([
            'appointment_approved_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        $activeRunId = (string) $this->activateManualE2EFixture()['manual_e2e_active_run_id'];
        $request = $this->technicalServiceRequest([
            'customer_phone' => '05372081633',
            'mrn' => 'MRN-MANUAL-E2E-OK',
        ]);
        $action = $this->appointmentAction($request);

        $summary = app(TechnicalServiceAppointmentMessageDispatchService::class)->dispatchApproval(
            $request->refresh(),
            $action,
            $actor,
            [
                'slot' => ['date' => '2026-07-08', 'start_time' => '14:00', 'end_time' => '16:00'],
                'metadata' => ['test_smoke' => true],
                'controlled_smoke_targets' => ['customer' => '905372081633'],
            ],
        );

        $this->assertSame(1, $summary['queued']);
        $dispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'appointment_approved_customer')
            ->firstOrFail();
        $this->assertTrue((bool) data_get($dispatch->metadata, 'manual_e2e'));
        $this->assertSame($activeRunId, data_get($dispatch->metadata, 'smoke_run_id'));
        $this->assertSame('905372081633', data_get($dispatch->metadata, 'role_target_phone'));

        $blockedRequest = $this->technicalServiceRequest([
            'customer_phone' => '05330000000',
            'mrn' => 'MRN-MANUAL-E2E-BLOCK',
        ]);
        $blockedAction = $this->appointmentAction($blockedRequest);
        $blocked = app(TechnicalServiceAppointmentMessageDispatchService::class)->dispatchApproval(
            $blockedRequest->refresh(),
            $blockedAction,
            $actor,
            [
                'slot' => ['date' => '2026-07-08', 'start_time' => '14:00', 'end_time' => '16:00'],
                'metadata' => ['test_smoke' => true],
                'controlled_smoke_targets' => ['customer' => '905330000000'],
            ],
        );

        $this->assertSame(0, $blocked['queued']);
        $this->assertSame(1, $blocked['blocked']);
        $this->assertStringContainsString('allowlist', json_encode($blocked['blockers'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function test_ops_workflow_message_uses_whatsapp_only_to_configured_ops_phone(): void
    {
        $actor = $this->admin();
        $this->configureMessaging([
            'job_rejected_ops' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        $activeRunId = (string) $this->activateManualE2EFixture([
            'ops_whatsapp_enabled' => true,
        ])['manual_e2e_active_run_id'];
        $request = $this->technicalServiceRequest(['mrn' => 'MRN-OPS-WP-UNIT']);

        $dispatch = app(TechnicalServiceWorkflowMessageDispatchService::class)->queueSystemMessage(
            $request,
            'job_rejected_ops',
            'ops',
            'Usta işi reddetti. MRN: MRN-OPS-WP-UNIT. Neden: Test.',
            ['next_action_text' => 'OPS yeniden atama yapmalı.'],
            $actor,
            null,
            ['triggered_by' => 'partner_portal_job_rejected'],
        );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
        $this->assertSame('whatsapp', $dispatch->channel);
        $this->assertSame('evo_whatsapp', $dispatch->provider_key);
        $this->assertSame('ops', $dispatch->recipient_role);
        $this->assertSame('905467647428', $dispatch->target_phone);
        $this->assertTrue((bool) data_get($dispatch->metadata, 'manual_e2e'));
        $this->assertSame($activeRunId, data_get($dispatch->metadata, 'smoke_run_id'));
        $this->assertSame($activeRunId, data_get($dispatch->metadata, 'manual_e2e_run_id'));
        $this->assertSame('905467647428', data_get($dispatch->metadata, 'role_target_phone'));
    }

    public function test_ops_message_is_blocked_when_ops_whatsapp_false_and_ops_sms_is_never_planned(): void
    {
        Http::fake();
        $actor = $this->admin();
        $this->configureMessaging([
            'appointment_proposed_ops' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        $this->activateManualE2EFixture(['ops_whatsapp_enabled' => false]);
        $request = $this->technicalServiceRequest(['mrn' => 'MRN-OPS-DISABLED-UNIT']);

        $summary = app(TechnicalServiceWorkflowMessageDispatchService::class)->queueWorkflowDispatches(
            $request,
            'appointment_proposed_ops',
            'ops',
            ['next_action_text' => 'OPS randevu önerisini incelemeli.'],
            $actor,
        );

        $this->assertSame(0, $summary['queued']);
        $this->assertSame(1, $summary['suppressed']);
        $this->assertSame('channel_policy_disabled', $summary['blockers'][0]['code']);
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'recipient_role' => 'ops',
        ]);
        Http::assertNothingSent();
    }

    public function test_assignment_offer_current_modal_path_creates_technician_whatsapp_sms_dispatches_with_manual_e2e_metadata(): void
    {
        Http::fake();
        $actor = $this->admin();
        $this->configureMessaging([
            'assignment_offer_technician' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
        ]);
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'test_mode_enabled' => false,
            'manual_e2e_partner_portal_origin_enabled' => true,
            'manual_e2e_partner_portal_origin' => 'http://10.0.28.64:8000',
        ]);
        config()->set('services.partner_portal.public_url', 'http://10.0.28.64:8000');
        $activeRunId = (string) $this->activateManualE2EFixture()['manual_e2e_active_run_id'];
        $this->assertFalse(app(TechnicalServiceMessagingSettingsService::class)->payload()['global']['ops_whatsapp_enabled']);
        $technician = $this->technician([
            'name' => 'Test Usta',
            'phone' => '0546 764 74 28',
            'phone_e164' => '905467647428',
        ]);
        $this->linkTechnicianToPartner($technician);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REL4E12-ASSIGN',
            'technical_service_technician_id' => null,
            'customer_name' => 'REL4E12 Müşteri',
            'customer_phone' => '05372081633',
            'service_address' => 'REL4E12 test adresi Kadıköy İstanbul',
            'product_name' => 'REL4E12 Test Ürün',
            'operation_control_payload' => [
                'door_photos_checked' => 'compatible',
            ],
            'operation_control_checked_at' => now(),
            'operation_control_checked_by_user_id' => $actor->id,
        ]);

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'confirm_assignment' => true,
                'travel_round_trip_km' => 36,
                'assignment_offer' => [
                    'labor_amount' => 900,
                    'route_fee_amount' => 350,
                    'note' => 'REL4E12 atama testi.',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('request.assignment_offer.dispatch_status', TechnicalServiceMessageDispatch::STATUS_QUEUED);

        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'assignment_offer_technician')
            ->orderBy('channel')
            ->get();

        $this->assertCount(2, $dispatches);
        $this->assertSame(['sms', 'whatsapp'], $dispatches->pluck('channel')->all());
        $this->assertSame(['nac_sms', 'evo_whatsapp'], $dispatches->pluck('provider_key')->all());
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'recipient_role' => 'customer',
            'message_type' => 'assignment_offer_technician',
        ]);

        foreach ($dispatches as $dispatch) {
            $body = (string) ($dispatch->request_payload['body'] ?? '');
            $this->assertSame('technician', $dispatch->recipient_role);
            $this->assertSame('905467647428', $dispatch->target_phone);
            $this->assertTrue((bool) data_get($dispatch->metadata, 'manual_e2e'));
            $this->assertTrue((bool) data_get($dispatch->metadata, 'allowlisted_target'));
            $this->assertSame($activeRunId, data_get($dispatch->metadata, 'manual_e2e_run_id'));
            $this->assertSame('905467647428', data_get($dispatch->metadata, 'role_target_phone'));
            $this->assertStringContainsString('MRN-REL4E12-ASSIGN', $body);
            $this->assertStringContainsString('905372081633', $body);
            if ($dispatch->channel === 'sms') {
                $this->assertStringContainsString('REL4E12 Musteri', $body);
                $this->assertStringContainsString('Saat oner:', $body);
                $this->assertStringContainsString('/pj/'.$request->id, $body);
            } else {
                $this->assertStringContainsString('REL4E12 Müşteri', $body);
                $this->assertStringContainsString('randevu saati öneriniz', mb_strtolower($body, 'UTF-8'));
                $this->assertStringContainsString('job_id='.$request->id, $body);
            }
        }

        $whatsappBody = (string) ($dispatches->firstWhere('channel', 'whatsapp')->request_payload['body'] ?? '');
        $this->assertStringContainsString('Lütfen randevu saati öneriniz.', $whatsappBody);
        $this->assertStringContainsString('Hakediş Özeti', $whatsappBody);
        $this->assertStringContainsString('İşçilik/Montaj: 900,00 TL', $whatsappBody);
        $this->assertStringContainsString('Yol: 350,00 TL', $whatsappBody);
        $this->assertStringContainsString('Toplam: 1.250,00 TL', $whatsappBody);
        $smsBody = (string) ($dispatches->firstWhere('channel', 'sms')?->request_payload['body'] ?? '');
        $this->assertStringContainsString('Yeni Is', $smsBody);
        $this->assertStringContainsString('REL4E12 Test Urun', $smsBody);
        $this->assertStringContainsString('REL4E12 test adresi Kadikoy Istanbul', $smsBody);
        $this->assertStringContainsString('Top:1.250,00 TL', $smsBody);
        $this->assertStringContainsString('http://10.0.28.64:8000/pj/'.$request->id, $smsBody);
        $this->assertStringContainsString(
            'http://10.0.28.64:8000/partner/service-jobs?partner_id=',
            $whatsappBody,
        );
        foreach ($dispatches as $dispatch) {
            $this->assertSame(
                'manual_e2e_local',
                data_get($dispatch->request_payload, 'context.technician_job_card_origin_mode'),
            );
            $this->assertSame(
                'admin_manual_e2e_partner_portal_origin',
                data_get($dispatch->request_payload, 'context.technician_job_card_origin_source'),
            );
        }
        Http::assertNothingSent();
    }

    public function test_payment_link_send_creates_customer_whatsapp_and_sms_dispatches_without_provider_call(): void
    {
        Http::fake();
        $actor = $this->admin();
        $this->configureMessaging([
            'payment_link_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REL4E10-PAYMENT',
            'customer_phone' => '05372081633',
        ]);
        $session = $this->mountSession('REL4E10-PAYMENT');
        $request->forceFill(['mount_session_id' => $session->id])->save();
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'pay-rel4e10',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 1250,
            'currency' => 'TRY',
            'payment_url' => 'https://pay.example.test/mount/rel4e10',
            'raw_payload' => ['source' => 'rel4e10_test'],
        ]);

        $response = $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link");

        $response->assertOk()
            ->assertJsonPath('dispatches.queued', 2);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'payment_link_customer',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
        ]);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'payment_link_customer',
            'channel' => 'sms',
            'recipient_role' => 'customer',
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
        ]);

        $body = (string) TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'payment_link_customer')
            ->where('channel', 'whatsapp')
            ->firstOrFail()
            ->request_payload['body'];

        $this->assertStringContainsString('https://pay.example.test/mount/rel4e10', $body);
        $this->assertStringContainsString('1.250,00 TL', $body);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'mount_payment_link_send_requested',
        ]);
        Http::assertNothingSent();
    }

    public function test_srv_payment_link_customer_uses_srv_reference_without_internal_mrn(): void
    {
        Http::fake();
        $actor = $this->admin();
        $this->configureMessaging([
            'payment_link_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REL4E13B-INTERNAL',
            'service_code' => 'SRV-REL4E13B-PAY',
            'service_type' => 'Servis',
            'customer_phone' => '05372081633',
        ]);
        $session = $this->mountSession('REL4E13B-PAYMENT');
        $request->forceFill(['mount_session_id' => $session->id])->save();
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'pay-rel4e13b',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 140,
            'currency' => 'TRY',
            'payment_url' => 'https://pay.example.test/service/rel4e13b',
            'raw_payload' => ['source' => 'rel4e13b_srv_payment_test'],
        ]);

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link")
            ->assertOk()
            ->assertJsonPath('dispatches.queued', 2)
            ->assertJsonPath('dispatches.blocked', 0);

        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'payment_link_customer')
            ->orderBy('channel')
            ->get();

        $this->assertCount(2, $dispatches);
        foreach ($dispatches as $dispatch) {
            $body = (string) ($dispatch->request_payload['body'] ?? '');

            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
            $this->assertStringContainsString('SRV-REL4E13B-PAY numaralı servis', $body);
            $this->assertStringNotContainsString('MRN-REL4E13B-INTERNAL', $body);
            $this->assertStringNotContainsString('MRN:', $body);
            $this->assertSame($body, $dispatch->bodyForProvider());
            $this->assertSame(hash('sha256', $body), $dispatch->providerBodyHash());
        }

        Http::assertNothingSent();
    }

    public function test_stale_blocked_payment_link_dispatch_does_not_turn_first_send_into_resend(): void
    {
        Http::fake();
        $actor = $this->admin();
        $this->configureMessaging([
            'payment_link_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REL4E16-STALE-PAYMENT',
            'customer_phone' => '05372081633',
        ]);
        $session = $this->mountSession('REL4E16-STALE-PAYMENT');
        $request->forceFill(['mount_session_id' => $session->id])->save();
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'pay-rel4e16-stale',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 450,
            'currency' => 'TRY',
            'payment_url' => 'https://pay.example.test/mount/rel4e16-stale',
            'raw_payload' => ['source' => 'rel4e16_stale_payment_test'],
        ]);
        TechnicalServiceMessageDispatch::query()->create([
            'event' => 'payment_link_customer',
            'technical_service_request_id' => $request->id,
            'request_id' => $request->id,
            'message_type' => 'payment_link_customer',
            'channel' => 'whatsapp',
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'customer',
            'status' => TechnicalServiceMessageDispatch::STATUS_BLOCKED,
            'last_error_code' => 'public_url_missing',
            'metadata' => ['payment_id' => $payment->id],
        ]);

        $this->actingAs($actor)
            ->getJson("/api/technical-service/requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('request.sale_and_payment.mount_payments.latest.message_send_count', 0);

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link")
            ->assertOk()
            ->assertJsonPath('dispatches.queued', 2);

        $newDispatches = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('status', TechnicalServiceMessageDispatch::STATUS_QUEUED)
            ->get();
        $this->assertCount(2, $newDispatches);
        $this->assertTrue($newDispatches->every(fn (TechnicalServiceMessageDispatch $dispatch): bool => $dispatch->parent_dispatch_id === null && $dispatch->force_resend_reason === null
        ));
        Http::assertNothingSent();
    }

    public function test_legacy_sent_part_fee_dispatch_is_presented_as_resend_before_submit(): void
    {
        Http::fake();
        $actor = $this->admin();
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REL4E16-LEGACY-PART-SEND',
            'service_code' => 'SRV-REL4E16-LEGACY-PART-SEND',
            'service_type' => 'Servis',
            'customer_phone' => '05372081633',
        ]);
        $session = $this->mountSession('REL4E16-LEGACY-PART-SEND');
        $request->forceFill(['mount_session_id' => $session->id])->save();
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'pay-rel4e16-legacy-part',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 700,
            'currency' => 'TRY',
            'payment_url' => 'https://pay.example.test/service/rel4e16-legacy-part',
            'raw_payload' => [
                'source' => 'operation_customer_charge',
                'purpose' => 'part_payment',
                'charge_type' => 'part_payment',
                'part_request_id' => 987,
            ],
        ]);
        $sentAt = now()->subMinute()->startOfSecond();
        TechnicalServiceMessageDispatch::query()->create([
            'event' => 'part_fee_payment_link_customer',
            'technical_service_request_id' => $request->id,
            'request_id' => $request->id,
            'message_type' => 'part_fee_payment_link_customer',
            'channel' => 'whatsapp',
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'customer',
            'status' => TechnicalServiceMessageDispatch::STATUS_SENT,
            'sent_at' => $sentAt,
            'provider_message_id' => 'legacy-part-message-id',
            'metadata' => ['payment_id' => $payment->id],
        ]);

        $response = $this->actingAs($actor)
            ->getJson("/api/technical-service/requests/{$request->id}")
            ->assertOk();

        $this->assertSame(1, $response->json('request.sale_and_payment.customer_charges.latest.message_send_count'));
        $this->assertNotNull($response->json('request.sale_and_payment.customer_charges.latest.last_message_sent_at'));

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resend_reason']);

        Http::assertNothingSent();
    }

    public function test_part_fee_payment_link_send_uses_part_fee_type_and_duplicate_guard(): void
    {
        Http::fake();
        $actor = $this->admin();
        $this->configureMessaging([
            'part_fee_payment_link_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REL4E13C-INTERNAL',
            'service_code' => 'SRV-REL4E13C-PART',
            'service_type' => 'Servis',
            'customer_phone' => '05372081633',
        ]);
        $session = $this->mountSession('REL4E13C-PART');
        $request->forceFill(['mount_session_id' => $session->id])->save();
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'pay-rel4e13c-part',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 700,
            'currency' => 'TRY',
            'payment_url' => 'https://pay.example.test/service/rel4e13c-part',
            'raw_payload' => [
                'source' => 'operation_customer_charge',
                'purpose' => 'part_payment',
                'charge_type' => 'part_payment',
                'part_request_id' => 987,
            ],
        ]);

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link")
            ->assertOk()
            ->assertJsonPath('dispatches.queued', 2)
            ->assertJsonPath('dispatches.blocked', 0);

        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'part_fee_payment_link_customer')
            ->orderBy('channel')
            ->get();

        $this->assertCount(2, $dispatches);
        foreach ($dispatches as $dispatch) {
            $body = (string) ($dispatch->request_payload['body'] ?? '');

            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
            $this->assertStringContainsString('SRV-REL4E13C-PART numaralı servis', $body);
            $this->assertStringContainsString('700,00 TL', $body);
            $this->assertStringContainsString('https://pay.example.test/service/rel4e13c-part', $body);
            $this->assertStringNotContainsString('MRN-REL4E13C-INTERNAL', $body);
            $this->assertSame($body, $dispatch->bodyForProvider());
        }

        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'payment_link_customer',
        ]);

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resend_reason']);

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link", [
                'resend_reason' => 'Müşteri açıkça yeniden gönderim istedi.',
            ])
            ->assertOk()
            ->assertJsonPath('dispatches.queued', 2)
            ->assertJsonPath('dispatches.duplicate_blocked', 0);

        $resends = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'part_fee_payment_link_customer')
            ->whereNotNull('parent_dispatch_id')
            ->get();
        $this->assertCount(2, $resends);
        $this->assertTrue($resends->every(fn (TechnicalServiceMessageDispatch $dispatch): bool => $dispatch->force_resend_reason === 'Müşteri açıkça yeniden gönderim istedi.'
        ));

        Http::assertNothingSent();
    }

    public function test_payment_received_ops_trusted_paid_queues_ops_whatsapp_only(): void
    {
        Http::fake();
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'messaging_enabled' => true,
            'test_mode_enabled' => true,
            'shared_test_phone' => '0546 764 74 28',
            'ops_whatsapp_enabled' => true,
            'ops_whatsapp_phone' => '0546 764 74 28',
            'message_types' => [
                'payment_received_ops' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
            ],
        ]);
        $session = $this->mountSession('REL4E13B-PAID');
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REL4E13B-PAID',
            'service_code' => 'SRV-REL4E13B-PAID-001',
            'serial_number' => 'SN-REL4E13B-PAID',
            'customer_name' => 'REL4E13B Ödeme Müşteri',
            'customer_phone' => '05372081633',
            'mount_session_id' => $session->id,
        ]);
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'pay-rel4e13b-paid',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 140,
            'currency' => 'TRY',
            'payment_url' => 'https://pay.example.test/service/rel4e13b-paid',
            'raw_payload' => ['source' => 'rel4e13b_payment_received_ops_test'],
        ]);

        app(TechnicalServicePaymentProviderReconciliationService::class)->markPaidFromTrustedProvider($payment, [
            'provider' => 'fake',
            'provider_status' => 'paid',
            'payment_id' => 'provider-payment-rel4e13b',
            'conversation_id' => 'provider-conversation-rel4e13b',
        ]);

        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'payment_received_ops')
            ->orderBy('channel')
            ->get();

        $this->assertCount(1, $dispatches);
        $dispatch = $dispatches->first();
        $this->assertSame('whatsapp', $dispatch->channel);
        $this->assertSame('ops', $dispatch->recipient_role);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
        $this->assertStringContainsString('MRN-REL4E13B-PAID', $dispatch->bodyForProvider());
        $this->assertStringContainsString('REL4E13B Ödeme Müşteri', $dispatch->bodyForProvider());
        $this->assertStringContainsString('05372081633', $dispatch->bodyForProvider());
        $this->assertStringContainsString('SN-REL4E13B-PAID', $dispatch->bodyForProvider());
        $this->assertStringContainsString('140,00 TRY', $dispatch->bodyForProvider());
        $this->assertStringNotContainsString('Dekont: -', $dispatch->bodyForProvider());
        $this->assertSame(0, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'payment_received_ops')
            ->where('channel', 'sms')
            ->count());
        Http::assertNothingSent();
    }

    public function test_customer_approval_blocks_provider_dispatch_without_public_url(): void
    {
        $this->configureMessaging([
            'customer_approval_request' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
        ]);
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'test_mode_enabled' => false,
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REL4E10-APPROVAL',
            'customer_phone' => '05372081633',
        ]);

        $summary = app(TechnicalServiceWorkflowMessageDispatchService::class)->queueWorkflowDispatches(
            $request,
            'customer_approval_request',
            'customer',
            [
                'approval_url' => 'http://10.0.28.64:8000/service-job-confirmation/rel4e10',
                'confirmation_link' => 'http://10.0.28.64:8000/service-job-confirmation/rel4e10',
                'confirmation_link_sms' => 'http://10.0.28.64:8000/service-job-confirmation/rel4e10',
            ],
            $this->admin(),
            null,
            [
                'recipient_phone' => $request->customer_phone,
                'requires_public_url' => 'http://10.0.28.64:8000/service-job-confirmation/rel4e10',
            ],
        );

        $this->assertSame(0, $summary['queued']);
        $this->assertSame(2, $summary['blocked']);
        $this->assertStringContainsString('PARTNER_PORTAL_PUBLIC_URL', json_encode($summary['blockers'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'customer_approval_request',
            'status' => TechnicalServiceMessageDispatch::STATUS_BLOCKED,
            'last_error_code' => 'public_url_missing',
        ]);
    }

    public function test_part_request_creates_ops_dispatch_without_automatically_sending_part_fee_link(): void
    {
        Http::fake();
        $actor = $this->admin();
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'messaging_enabled' => true,
            'test_mode_enabled' => true,
            'shared_test_phone' => '0546 764 74 28',
            'ops_whatsapp_enabled' => true,
            'ops_whatsapp_phone' => '0546 764 74 28',
            'nac_sms' => [
                'enabled' => true,
                'sender' => 'EMAKS PRIME',
            ],
            'message_types' => [
                'part_request_ops' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
                'part_fee_payment_link_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
            ],
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REL4E10-PART',
            'customer_phone' => '05372081633',
        ]);
        $session = $this->mountSession('REL4E10-PART');
        $request->forceFill(['mount_session_id' => $session->id])->save();

        $response = $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/part-requests", [
                'part_name' => 'REL4E10 Kilit Gövdesi',
                'part_code' => 'REL4E10-PART',
                'quantity' => 1,
                'charge_decision' => 'chargeable',
                'service_amount' => 500,
                'part_amount' => 750,
                'note' => 'Parça değişimi gerekiyor.',
                'customer_message' => 'Parça ücreti için ödeme bağlantısı gönderilecek.',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'part_request_ops',
            'channel' => 'whatsapp',
            'recipient_role' => 'ops',
        ]);
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'part_fee_payment_link_customer',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
        ]);
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'part_fee_payment_link_customer',
            'channel' => 'sms',
            'recipient_role' => 'customer',
        ]);
        Http::assertNothingSent();
    }

    public function test_final_control_activation_warranty_customer_message_is_single_queue_message(): void
    {
        $actor = $this->admin();
        $this->configureMessaging([
            'activation_warranty_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REL4E13-FINAL',
            'customer_phone' => '05372081633',
            'serial_number' => 'REL4E13-SERIAL',
            'activation_code' => 'ACT-REL4E13',
        ]);

        $summary = app(TechnicalServiceWorkflowMessageDispatchService::class)->queueWorkflowDispatches(
            $request,
            'activation_warranty_customer',
            'customer',
            [
                'activation_code' => 'ACT-REL4E13',
                'warranty_started_at_formatted' => '08.07.2026',
                'warranty_ends_at_formatted' => '08.07.2028',
                'survey_link' => 'https://portal.example.test/service-job-confirmation/token?survey=1',
                'survey_link_sms' => 'https://e.ms/anket/13',
            ],
            $actor,
        );

        $this->assertSame(2, $summary['queued']);
        $this->assertSame(2, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'activation_warranty_customer')
            ->whereIn('provider_key', ['evo_whatsapp', 'nac_sms'])
            ->count());
        $this->assertSame(0, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->whereIn('message_type', ['final_control_completed_customer', 'activation_code_customer', 'warranty_started_customer'])
            ->count());

        $body = (string) TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'activation_warranty_customer')
            ->where('channel', 'whatsapp')
            ->firstOrFail()
            ->request_payload['body'];

        $this->assertStringContainsString('ACT-REL4E13', $body);
        $this->assertStringContainsString('REL4E13-SERIAL', $body);
        $this->assertStringContainsString('08.07.2026', $body);
        $this->assertStringContainsString('08.07.2028', $body);
        $this->assertStringContainsString('https://portal.example.test/service-job-confirmation/token?survey=1', $body);

        $controllerSource = file_get_contents(app_path('Http/Controllers/Api/TechnicalServicePartnerPortalOpsController.php')) ?: '';
        $this->assertStringContainsString("'activation_warranty_customer' =>", $controllerSource);
        $this->assertStringNotContainsString("'final_control_completed_customer' => $".'this->workflowMessages->queueWorkflowDispatches', $controllerSource);
        $this->assertStringNotContainsString("'activation_code_customer' => $".'this->workflowMessages->queueWorkflowDispatches', $controllerSource);
        $this->assertStringNotContainsString("'warranty_started_customer' => $".'this->workflowMessages->queueWorkflowDispatches', $controllerSource);
    }

    public function test_payment_link_send_button_and_amount_steps_are_queue_safe_in_frontend_source(): void
    {
        $detailSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx')) ?: '';
        $pageSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx')) ?: '';
        $compactDetailSource = preg_replace('/\s+/', '', $detailSource) ?? $detailSource;

        $this->assertStringContainsString('Linki müşteriye gönder', $detailSource);
        $this->assertStringContainsString('onMountPaymentSend(payment.id, { resend_reason: resendReason })', $detailSource);
        $this->assertStringContainsString('placeholder="Yeniden gönderim nedeni"', $detailSource);
        $this->assertStringContainsString('Ödeme linkini neden kaydıyla yeniden gönder', $detailSource);
        $this->assertStringContainsString('/payments/${paymentId}/send-link', $pageSource);
        $this->assertStringContainsString('step="1"', $detailSource);
        $this->assertStringContainsString('inputMode="decimal"', $detailSource);
        $this->assertStringContainsString('Firma tahsilat adresi, ödeme alan/EMAKS Prime firma adresidir. Müşteri servis adresinden farklıdır.', $detailSource);
        $this->assertStringContainsString('Müşteri servis adresi bu ödeme akışında ödeme alıcısı değildir', $detailSource);
        $blockedRecipientPhrases = [
            'Ürün tutarı için '.implode('', ['alıcı', 'nızın adresini almak zorunludur']),
            implode('', ['alıcı', 'nızın adresi']),
            implode(' ', ['alıcı', 'adresi']),
            implode(' ', ['Müşteri', 'adresi eksik']),
        ];
        foreach ($blockedRecipientPhrases as $blockedRecipientPhrase) {
            $this->assertStringNotContainsString($blockedRecipientPhrase, $detailSource);
            $this->assertStringNotContainsString($blockedRecipientPhrase, $pageSource);
        }
        $this->assertMatchesRegularExpression('/setEarningTotalOverrideByRequest\\(\\(?current\\)?=>\\(\\{\\.\\.\\.current,\\[requestStateKey\\]:nextValue,?\\}\\),?\\)/', $compactDetailSource);
        $this->assertStringContainsString('step="1"', $pageSource);
    }

    public function test_payment_card_actions_standard_pending_link_card_shows_open_copy_send_actions(): void
    {
        $detailSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx')) ?: '';
        $standardPendingCardStart = strpos($detailSource, 'label="Bekleyen link"');

        $this->assertNotFalse($standardPendingCardStart);

        $standardPendingCard = substr($detailSource, (int) $standardPendingCardStart, 5000);

        $this->assertStringContainsString('Ödeme linkini aç', $standardPendingCard);
        $this->assertStringContainsString('Linki kopyala', $standardPendingCard);
        $this->assertMatchesRegularExpression('/renderPaymentLinkSendAction\(\s*extraMountPayment\s*,?\s*\)/', $standardPendingCard);
    }

    public function test_technical_service_detail_dialog_has_accessible_description(): void
    {
        $pageSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx')) ?: '';
        $detailDialogStart = strpos($pageSource, 'DialogTitle className="text-base font-semibold text-slate-900">Talep Detayı');

        $this->assertNotFalse($detailDialogStart);

        $detailDialogSource = substr($pageSource, (int) $detailDialogStart, 500);

        $this->assertStringContainsString('DialogDescription className="sr-only"', $detailDialogSource);
        $this->assertStringContainsString('operasyon, ödeme, usta atama ve saha tamamlama detayları', $detailDialogSource);
    }

    public function test_part_received_ops_hook_is_queue_only_and_whatsapp_only_in_source(): void
    {
        $controllerSource = file_get_contents(app_path('Http/Controllers/Api/PartnerServiceJobController.php')) ?: '';
        $registrySource = file_get_contents(app_path('Services/Messaging/TechnicalServiceMessagingSettingsService.php')) ?: '';

        $this->assertStringContainsString("'part_received_ops'", $controllerSource);
        $this->assertStringContainsString("'triggered_by' => 'partner_portal_part_received'", $controllerSource);
        $this->assertStringContainsString("'part_received_ops' => [", $registrySource);
        $this->assertStringContainsString("'recipient_role' => 'ops'", $registrySource);
        $this->assertStringNotContainsString('sendMessage(', $controllerSource);
        $this->assertStringNotContainsString('sendSms(', $controllerSource);
    }

    public function test_template_source_dispatch_uses_active_db_template_over_default_and_matches_preview(): void
    {
        $actor = $this->admin();
        $this->configureMessaging([
            'appointment_approved_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        TechnicalServiceMessageTemplate::query()->create([
            'template_key' => 'appointment_approved_customer.whatsapp.default',
            'message_type' => 'appointment_approved_customer',
            'channel' => 'whatsapp',
            'provider_key' => null,
            'title' => 'REL4E9 DB template',
            'body' => "DB SOURCE {customer_name}\n{customer_appointment_action_phrase}\nPencere: {appointment_customer_window}",
            'active' => true,
            'locale' => 'tr',
            'version' => 9,
            'required_variables' => ['customer_name', 'customer_appointment_action_phrase', 'appointment_customer_window'],
            'optional_variables' => [],
            'validation_rules' => [],
            'metadata' => ['source' => 'test_db_template'],
        ]);
        $request = $this->technicalServiceRequest([
            'customer_name' => 'PR88 REL4E9 DB Müşteri',
            'mrn' => 'MRN-PR88-REL4E9-DB',
            'scheduled_date' => '2026-07-08',
            'scheduled_time' => '14:00',
        ]);
        $action = $this->appointmentAction($request, '2026-07-08', '14:00', '16:00');
        $context = ['appointment_time' => '14:00 - 16:00'];

        $preview = app(TechnicalServiceMessageTemplateService::class)->preview([
            'message_type' => 'appointment_approved_customer',
            'channel' => 'whatsapp',
            'request_id' => $request->id,
            'sample_context' => false,
            'context' => $context,
        ]);

        $summary = app(TechnicalServiceAppointmentMessageDispatchService::class)->dispatchApproval(
            $request->refresh(),
            $action,
            $actor,
            [
                'slot' => ['date' => '2026-07-08', 'start_time' => '14:00', 'end_time' => '16:00'],
                'context' => $context,
            ],
        );

        $this->assertSame(1, $summary['queued']);
        $dispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'appointment_approved_customer')
            ->firstOrFail();

        $this->assertSame('appointment_approved_customer.whatsapp.default', $dispatch->template_key);
        $this->assertSame(9, $dispatch->template_version);
        $this->assertStringStartsWith('DB SOURCE PR88 REL4E9 DB Müşteri', (string) ($dispatch->request_payload['body'] ?? ''));
        $this->assertSame($preview['rendered_body'], $dispatch->request_payload['body']);
        $this->assertFalse((bool) ($preview['template']['is_default'] ?? true));
    }

    public function test_duplicate_guard_reapproval_duplicate_blocked_for_same_appointment_event(): void
    {
        $this->configureMessaging([
            'appointment_approved_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        $actor = $this->admin();
        $request = $this->technicalServiceRequest();
        $action = $this->appointmentAction($request);
        $service = app(TechnicalServiceAppointmentMessageDispatchService::class);

        $first = $service->dispatchApproval($request->refresh(), $action, $actor, [
            'slot' => ['date' => '2026-07-08', 'start_time' => '14:00', 'end_time' => '16:00'],
        ]);
        $second = $service->dispatchApproval($request->refresh(), $action, $actor, [
            'slot' => ['date' => '2026-07-08', 'start_time' => '14:00', 'end_time' => '16:00'],
        ]);

        $this->assertSame(1, $first['queued']);
        $this->assertSame(1, $second['duplicate_blocked']);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'appointment_approved_customer',
            'status' => TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED,
        ]);
    }

    public function test_appointment_updated_message_flow_meaningful_change_dispatches_update_and_repeat_is_duplicate(): void
    {
        $this->configureMessaging([
            'appointment_updated_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        $actor = $this->admin();
        $request = $this->technicalServiceRequest([
            'scheduled_date' => '2026-07-08',
            'scheduled_time' => '15:00',
        ]);
        $action = $this->appointmentAction($request, '2026-07-08', '15:00', '17:00');
        $service = app(TechnicalServiceAppointmentMessageDispatchService::class);

        $summary = $service->dispatchUpdate($request->refresh(), $action, $actor, [
            'slot' => ['date' => '2026-07-08', 'start_time' => '15:00', 'end_time' => '17:00'],
        ]);
        $duplicate = $service->dispatchUpdate($request->refresh(), $action, $actor, [
            'slot' => ['date' => '2026-07-08', 'start_time' => '15:00', 'end_time' => '17:00'],
        ]);

        $this->assertSame(1, $summary['queued']);
        $this->assertSame(1, $duplicate['duplicate_blocked']);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'appointment_updated_customer',
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
        ]);
    }

    public function test_schedule_update_endpoint_preserves_approved_state_dispatches_four_messages_and_ignores_unchanged_repeat(): void
    {
        Http::fake();
        config()->set('services.partner_portal.public_url', 'https://technician-portal.example.test');
        $actor = $this->admin();
        $this->configureMessaging([
            'appointment_updated_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
            'appointment_updated_technician' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
        ]);
        $technician = $this->technician([
            'name' => 'Randevu Güncelleme Ustası',
            'phone' => '0546 764 74 28',
            'phone_e164' => '905467647428',
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REL4E16-SCHEDULE',
            'customer_phone' => '05372081633',
            'technical_service_technician_id' => $technician->id,
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => now()->subHour(),
            'scheduled_date' => '2026-07-08',
            'scheduled_time' => '10:00',
            'requires_reschedule' => true,
            'reschedule_reason' => 'Önceki operasyon notu korunmalı.',
        ]);
        $payload = [
            'scheduled_date' => '2026-07-09',
            'scheduled_time' => '14:30',
            'scheduled_time_end' => '16:00',
            'note' => 'Müşteri ve usta ile teyit edildi.',
        ];

        $response = $this->actingAs($actor)
            ->patchJson("/api/technical-service/requests/{$request->id}/schedule", $payload);
        $response
            ->assertOk()
            ->assertJsonPath('schedule_changed', true)
            ->assertJsonPath('request.workflow_status', 'Planlı')
            ->assertJsonPath('request.requires_reschedule', true)
            ->assertJsonPath('request.reschedule_reason', 'Önceki operasyon notu korunmalı.');
        $this->assertSame(
            4,
            $response->json('message_dispatches.queued'),
            json_encode($response->json('message_dispatches'), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->whereIn('message_type', ['appointment_updated_customer', 'appointment_updated_technician'])
            ->get();
        $this->assertCount(4, $dispatches);
        $this->assertEqualsCanonicalizing(
            ['appointment_updated_customer:sms', 'appointment_updated_customer:whatsapp', 'appointment_updated_technician:sms', 'appointment_updated_technician:whatsapp'],
            $dispatches->map(fn (TechnicalServiceMessageDispatch $dispatch): string => $dispatch->message_type.':'.$dispatch->channel)->all(),
        );
        $customerBodies = $dispatches->where('message_type', 'appointment_updated_customer')->map->bodyForProvider()->implode("\n");
        $technicianBodies = $dispatches->where('message_type', 'appointment_updated_technician')->map->bodyForProvider()->implode("\n");
        $this->assertStringContainsString('13:00 - 19:00 arası', $customerBodies);
        $this->assertStringContainsString('14:30 - 16:00', $technicianBodies);

        $event = $request->events()->where('event_type', 'schedule_updated')->latest('id')->firstOrFail();
        $this->assertSame($actor->id, $event->author_user_id);
        $this->assertSame($actor->id, data_get($event->metadata, 'actor_user_id'));
        $this->assertSame('technical_service_admin', data_get($event->metadata, 'source'));
        $this->assertSame('2026-07-08', data_get($event->metadata, 'previous_schedule.scheduled_date'));
        $this->assertSame('10:00', data_get($event->metadata, 'previous_schedule.scheduled_time'));
        $this->assertSame('2026-07-09', data_get($event->metadata, 'new_schedule.scheduled_date'));
        $this->assertSame('14:30', data_get($event->metadata, 'new_schedule.scheduled_time'));

        $this->actingAs($actor)
            ->patchJson("/api/technical-service/requests/{$request->id}/schedule", $payload)
            ->assertOk()
            ->assertJsonPath('schedule_changed', false)
            ->assertJsonPath('message_dispatches', null);
        $this->assertSame(4, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->whereIn('message_type', ['appointment_updated_customer', 'appointment_updated_technician'])
            ->count());
        Http::assertNothingSent();
    }

    public function test_new_request_creation_queues_ops_whatsapp_only_with_real_actor_and_no_duplicate(): void
    {
        Http::fake();
        $actor = $this->admin();
        $this->configureMessaging([
            'new_request_created_ops' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'ops_whatsapp_enabled' => true,
            'ops_whatsapp_phone' => '905467647428',
        ]);

        $response = $this->actingAs($actor)
            ->postJson('/api/technical-service/requests', [
                'customer_name' => 'REL4E16 Yeni Talep Müşteri',
                'customer_phone' => '05372081633',
                'customer_city' => 'İstanbul',
                'customer_district' => 'Kadıköy',
                'service_address' => 'Test Mah. Yeni Talep Sok. No:1',
                'product_name' => 'REL4E16 Test Kilit',
                'service_type' => 'Montaj',
                'description' => 'Yeni talep OPS bildirim testi.',
                'source_channel' => 'panel',
            ])
            ->assertCreated()
            ->assertJsonPath('message_dispatches.queued', 1)
            ->assertJsonPath('message_dispatches.blocked', 0);

        $requestId = (int) $response->json('request.id');
        $dispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $requestId)
            ->where('message_type', 'new_request_created_ops')
            ->firstOrFail();
        $this->assertSame('whatsapp', $dispatch->channel);
        $this->assertSame('ops', $dispatch->recipient_role);
        $this->assertStringContainsString('REL4E16 Yeni Talep Müşteri', $dispatch->bodyForProvider());
        $this->assertStringContainsString('REL4E16 Test Kilit', $dispatch->bodyForProvider());
        $this->assertSame(0, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $requestId)
            ->where('message_type', 'new_request_created_ops')
            ->where('channel', 'sms')
            ->count());
        $this->assertSame(1, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $requestId)
            ->where('message_type', 'new_request_created_ops')
            ->count());

        $event = TechnicalServiceRequest::query()->findOrFail($requestId)->events()->where('event_type', 'created')->firstOrFail();
        $this->assertSame($actor->id, $event->author_user_id);
        $this->assertSame($actor->id, data_get($event->metadata, 'actor_user_id'));
        $this->assertSame('technical_service_admin', data_get($event->metadata, 'source'));
        Http::assertNothingSent();
    }

    public function test_cancellation_is_atomic_requires_reason_queues_both_roles_and_repeat_is_noop(): void
    {
        Http::fake();
        $actor = $this->admin();
        $this->configureMessaging([
            'appointment_cancelled_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
            'appointment_cancelled_technician' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
        ]);
        $technician = $this->technician([
            'name' => 'İptal Test Ustası',
            'phone' => '0546 764 74 28',
            'phone_e164' => '905467647428',
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REL4E16-CANCEL',
            'customer_phone' => '05372081633',
            'technical_service_technician_id' => $technician->id,
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'scheduled_date' => '2026-07-09',
            'scheduled_time' => '14:30',
        ]);

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/status", ['status' => 'İptal'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note']);

        $response = $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'İptal',
                'note' => 'Müşteri randevunun iptalini istedi.',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', 'İptal')
            ->assertJsonPath('request.workflow_status', 'İptal');

        $this->assertFalse((bool) $response->json('duplicate_noop'));
        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->whereIn('message_type', ['appointment_cancelled_customer', 'appointment_cancelled_technician'])
            ->get();
        $this->assertCount(4, $dispatches);
        $this->assertTrue($dispatches->every(fn (TechnicalServiceMessageDispatch $dispatch): bool => str_contains($dispatch->bodyForProvider(), 'Müşteri randevunun iptalini istedi.')
        ));
        $request->refresh();
        $this->assertNotNull($request->cancelled_at);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'cancellation_confirmed',
            'author_user_id' => $actor->id,
        ]);

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'İptal',
                'note' => 'Aynı iptal tekrar gönderilmemeli.',
            ])
            ->assertOk()
            ->assertJsonPath('duplicate_noop', true);
        $this->assertSame(4, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->whereIn('message_type', ['appointment_cancelled_customer', 'appointment_cancelled_technician'])
            ->count());
        Http::assertNothingSent();
    }

    public function test_missing_technician_exact_time_blocks_technician_dispatch(): void
    {
        $this->configureMessaging([
            'appointment_approved_technician' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        $request = $this->technicalServiceRequest([
            'scheduled_date' => '2026-07-08',
            'scheduled_time' => '14:00',
        ]);
        $action = $this->appointmentAction($request, '2026-07-08', '14:00', null);

        $summary = app(TechnicalServiceAppointmentMessageDispatchService::class)->dispatchApproval(
            $request->refresh(),
            $action,
            $this->admin(),
            ['slot' => ['date' => '2026-07-08', 'start_time' => '14:00']],
        );

        $this->assertSame(0, $summary['queued']);
        $this->assertSame(1, $summary['blocked']);
        $this->assertStringContainsString('Usta mesajı için tam randevu saati gerekli', json_encode($summary['blockers'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'appointment_approved_technician',
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'message_dispatch_blocked',
        ]);
    }

    public function test_technician_assignment_does_not_create_customer_appointment_dispatch(): void
    {
        $this->configureMessaging([
            'appointment_approved_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        $request = $this->technicalServiceRequest(['technical_service_technician_id' => null]);
        $technician = $this->technician(['name' => 'Assignment Only Usta']);

        $request->forceFill([
            'technical_service_technician_id' => $technician->id,
            'workflow_status' => 'Usta Onayı Bekleyen',
            'status' => 'Atandı',
        ])->save();

        $this->assertSame(0, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'appointment_approved_customer')
            ->count());
    }

    /**
     * @param  array<string, array<string, mixed>>  $messageTypes
     */
    private function configureMessaging(array $messageTypes): void
    {
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'messaging_enabled' => true,
            'test_mode_enabled' => true,
            'shared_test_phone' => '0546 764 74 28',
            'nac_sms' => [
                'enabled' => true,
                'sender' => 'EMAKS PRIME',
            ],
            'message_types' => $messageTypes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function activateManualE2EFixture(array $overrides = []): array
    {
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->update([
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
            'ops_whatsapp_phone' => '905467647428',
        ]);
        $page = PageConfig::query()
            ->where('page_code', TechnicalServiceMessagingSettingsService::PAGE_CODE)
            ->firstOrFail();
        $layout = (array) $page->layout_json;
        $startedAt = now()->toImmutable();
        $context = [
            'manual_e2e_enabled' => true,
            'manual_e2e_active_run_id' => TechnicalServiceManualE2ERunContext::generateRunId($startedAt),
            'manual_e2e_started_at' => $startedAt->toIso8601String(),
            'manual_e2e_created_after' => $startedAt->toIso8601String(),
            'manual_e2e_expires_at' => $startedAt->addHours(4)->toIso8601String(),
            ...$overrides,
        ];
        foreach ($context as $key => $value) {
            Arr::set($layout, TechnicalServiceMessagingSettingsService::ROOT_KEY.'.'.$key, $value);
        }
        $page->forceFill(['layout_json' => $layout])->save();

        return $settings->payload()['global'];
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function technician(array $overrides = []): TechnicalServiceTechnician
    {
        return TechnicalServiceTechnician::query()->create([
            'name' => $overrides['name'] ?? 'REL4E Usta',
            'technician_type' => 'locksmith',
            'phone' => $overrides['phone'] ?? '+905551112233',
            'city' => 'İstanbul',
            'district' => 'Kadıköy',
            'active' => true,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function technicalServiceRequest(array $overrides = []): TechnicalServiceRequest
    {
        $technician = $overrides['technical_service_technician_id'] ?? false;
        if ($technician === false) {
            $technician = $this->technician()->id;
        }

        $request = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-REL4E-'.Str::upper(Str::random(6)),
            'customer_name' => 'REL4E Müşteri',
            'customer_phone' => '+905559998877',
            'customer_city' => 'İstanbul',
            'customer_district' => 'Kadıköy',
            'service_address' => 'REL4E test adresi',
            'product_name' => 'Test Ürün',
            'service_type' => 'Montaj',
            'technical_service_technician_id' => $technician,
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'scheduled_date' => '2026-07-08',
            'scheduled_time' => '14:00',
            'source_channel' => 'panel',
            ...$overrides,
        ]);

        if (is_numeric($technician)) {
            $this->linkTechnicianToPartner(
                TechnicalServiceTechnician::query()->findOrFail((int) $technician),
            );
        }

        return $request;
    }

    private function linkTechnicianToPartner(TechnicalServiceTechnician $technician): B2BPartnerTechnician
    {
        $existing = B2BPartnerTechnician::query()
            ->where('technical_service_technician_id', $technician->id)
            ->where('active', true)
            ->first();
        if ($existing instanceof B2BPartnerTechnician) {
            return $existing;
        }

        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'REL4E-LINK-'.Str::upper(Str::random(5)),
            'display_name' => $technician->name.' Partner',
            'active' => true,
        ]);
        B2BPartnerCapability::query()->create([
            'partner_id' => $partner->id,
            'capability' => B2BPartner::TYPE_LOCKSMITH,
            'active' => true,
        ]);

        return B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'is_primary' => true,
            'active' => true,
        ]);
    }

    private function appointmentAction(
        TechnicalServiceRequest $request,
        string $date = '2026-07-08',
        string $start = '14:00',
        ?string $end = '16:00',
    ): TechnicalServicePartnerJobAction {
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'REL4E-PARTNER-'.Str::upper(Str::random(5)),
            'display_name' => 'REL4E Partner',
            'active' => true,
        ]);

        return TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $request->id,
            'partner_id' => $partner->id,
            'user_id' => $this->admin()->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => [
                'slots' => [
                    array_filter([
                        'date' => $date,
                        'start_time' => $start,
                        'end_time' => $end,
                    ], fn (mixed $value): bool => $value !== null),
                ],
            ],
        ]);
    }

    private function mountSession(string $serial = 'REL4E-SESSION'): TechnicalServiceMountSession
    {
        $link = TechnicalServiceQrLink::query()->create([
            'token_hash' => TechnicalServiceQrLink::hashToken('qr-'.$serial),
            'public_token' => 'qr-'.$serial,
            'serial_number' => $serial,
            'product_name' => 'REL4E Test Ürün',
            'product_model' => 'REL4E-MODEL',
            'brand' => 'EMAKS',
            'link_type' => TechnicalServiceQrLink::TYPE_MANUAL_TEST,
            'status' => TechnicalServiceQrLink::STATUS_ACTIVE,
            'scan_count' => 0,
            'metadata' => ['source' => 'rel4e10_test'],
        ]);

        return TechnicalServiceMountSession::query()->create([
            'technical_service_qr_link_id' => $link->id,
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken('token-'.$serial),
            'serial_number' => $serial,
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_SINGLE_PRODUCT,
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
            'context_payload' => ['source' => 'rel4e10_test'],
        ]);
    }
}
