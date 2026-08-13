<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\PageConfig;
use App\Models\TechnicalServiceAssignmentOffer;
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
use App\Services\Messaging\TechnicalServiceMessageContextBuilder;
use App\Services\Messaging\TechnicalServiceMessageTemplateService;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Messaging\TechnicalServiceWorkflowMessageDispatchService;
use App\Services\Payments\TechnicalServicePaymentProviderReconciliationService;
use App\Services\TechnicalService\TechnicalServiceAssignmentSettlementService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithExternalExecutionControlPlane;
use Tests\TestCase;

class TechnicalServiceAppointmentMessageFlowTest extends TestCase
{
    use InteractsWithExternalExecutionControlPlane, RefreshDatabase;

    public function test_appointment_message_flow_ops_appointment_approved_creates_customer_and_technician_dispatches_without_provider_call(): void
    {
        Http::fake();
        $this->configureGuardedLiveMessaging([
            'appointment_approved_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
            'appointment_approved_technician' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        $actor = $this->admin();
        $request = $this->technicalServiceRequest([
            'scheduled_date' => '2026-07-08',
            'scheduled_time' => '14:00',
            'technician_payment_amount' => 100,
            'travel_fee_amount' => 1406.50,
        ]);
        $offer = TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $request->technical_service_technician_id,
            'labor_amount' => 100,
            'route_fee_amount' => 400,
            'total_amount' => 500,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
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
            'test_redirect_applied' => false,
        ]);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'appointment_approved_technician',
            'channel' => 'whatsapp',
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'technician',
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
            'test_redirect_applied' => false,
        ]);

        $customer = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'appointment_approved_customer')
            ->firstOrFail();
        $technician = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'appointment_approved_technician')
            ->firstOrFail();

        $this->assertSame('9055***877', $customer->effective_target_phone_mask);
        $this->assertSame('905559998877', $customer->target_phone);
        $this->assertSame('905559998877', $customer->original_phone);
        $this->assertTrue((bool) data_get($customer->metadata, 'manual_e2e'));
        $this->assertTrue((bool) data_get($technician->metadata, 'manual_e2e'));
        $this->assertStringContainsString('13:00 - 19:00 arası', (string) ($customer->request_payload['body'] ?? ''));
        $this->assertStringNotContainsString('14:00 - 16:00', (string) ($customer->request_payload['body'] ?? ''));
        $this->assertStringContainsString('14:00 - 16:00', (string) ($technician->request_payload['body'] ?? ''));
        $this->assertStringContainsString('İş Kartı', (string) ($technician->request_payload['body'] ?? ''));
        $this->assertStringNotContainsString('İşçilik', (string) ($customer->request_payload['body'] ?? ''));
        $this->assertStringNotContainsString('Yol', (string) ($customer->request_payload['body'] ?? ''));
        $this->assertStringNotContainsString('Hakediş', (string) ($customer->request_payload['body'] ?? ''));
        $this->assertStringContainsString('İşçilik/Montaj: 100,00 TL', (string) ($technician->request_payload['body'] ?? ''));
        $this->assertStringContainsString('Yol: 400,00 TL', (string) ($technician->request_payload['body'] ?? ''));
        $this->assertStringContainsString('Toplam: 500,00 TL', (string) ($technician->request_payload['body'] ?? ''));
        $this->assertStringNotContainsString('1.406,50 TL', (string) ($technician->request_payload['body'] ?? ''));
        $this->assertSame(
            app(TechnicalServiceAssignmentSettlementService::class)->canonicalEarningSnapshot($offer)['revision'],
            data_get($technician->metadata, 'earning_revision'),
        );
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'message_queued',
        ]);
        Http::assertNothingSent();
    }

    public function test_customer_appointment_message_contains_no_earning_values(): void
    {
        $templates = app(TechnicalServiceMessageTemplateService::class);

        foreach (['whatsapp', 'sms'] as $channel) {
            $preview = $templates->preview([
                'message_type' => 'appointment_approved_customer',
                'channel' => $channel,
                'provider_key' => $channel === 'sms' ? 'nac_sms' : 'evo_whatsapp',
            ]);
            $body = (string) ($preview['rendered_body'] ?? '');

            $this->assertTrue((bool) ($preview['preview_ready'] ?? false), json_encode($preview['blockers'] ?? []));
            $this->assertStringNotContainsString('İşçilik', $body);
            $this->assertStringNotContainsString('Yol hakedişi', $body);
            $this->assertStringNotContainsString('Toplam hakediş', $body);
            $this->assertStringNotContainsString('Şirket ödemesi', $body);
            $this->assertStringNotContainsString('Ödeme modeli', $body);
        }
    }

    public function test_technician_appointment_message_uses_only_persisted_earning(): void
    {
        Http::fake();
        $this->configureGuardedLiveMessaging([
            'appointment_approved_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
            'appointment_approved_technician' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        $request = $this->technicalServiceRequest([
            'scheduled_date' => '2026-07-08',
            'scheduled_time' => '14:00',
            'technician_payment_amount' => 7777,
            'travel_fee_amount' => 8888,
        ]);

        app(TechnicalServiceAppointmentMessageDispatchService::class)->dispatchApproval(
            $request->refresh(),
            $this->appointmentAction($request, '2026-07-08', '14:00', '16:00'),
            $this->admin(),
            ['slot' => ['date' => '2026-07-08', 'start_time' => '14:00', 'end_time' => '16:00']],
        );

        $dispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'appointment_approved_technician')
            ->firstOrFail();
        $body = (string) ($dispatch->request_payload['body'] ?? '');

        $this->assertStringNotContainsString('7.777', $body);
        $this->assertStringNotContainsString('8.888', $body);
        $this->assertStringContainsString('Hakediş bilgisi paneldeki iş kartında görülebilir.', $body);
        Http::assertNothingSent();
    }

    public function test_channel_policy_approval_whatsapp_and_sms_creates_two_dispatches_and_fallback_creates_primary_only(): void
    {
        $actor = $this->admin();
        $this->configureGuardedLiveMessaging([
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
        $this->assertSame(2, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->delete());

        $this->configureGuardedLiveMessaging([
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
        $this->configureGuardedLiveMessaging([
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
        $this->configureGuardedLiveMessaging([
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
        Http::preventStrayRequests();
        $actor = $this->admin();
        $this->configureMessaging([
            'appointment_approved_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        $prepared = $this->activateManualE2EFixture();
        $activeRunId = (string) $prepared['manual_e2e_active_run_id'];
        $this->assertSame(TechnicalServiceMessagingSettingsService::MANUAL_E2E_PHASE_PREPARED, $prepared['manual_e2e_phase']);
        $this->assertTrue($prepared['manual_e2e_enabled']);
        $this->assertFalse($prepared['real_send_enabled']);
        $this->assertFalse($prepared['test_mode_enabled']);
        $this->assertTrue($prepared['queue_paused']);
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
        $this->assertSame(0, $summary['blocked']);
        $dispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'appointment_approved_customer')
            ->firstOrFail();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
        $this->assertSame(0, $dispatch->attempt_count);
        $this->assertNull($dispatch->sent_at);
        $this->assertNull($dispatch->provider_message_id);
        $this->assertTrue((bool) data_get($dispatch->metadata, 'manual_e2e'));
        $this->assertTrue((bool) data_get($dispatch->metadata, 'test_smoke'));
        $this->assertTrue((bool) data_get($dispatch->metadata, 'allowlisted_target'));
        $this->assertSame($activeRunId, data_get($dispatch->metadata, 'smoke_run_id'));
        $this->assertSame($activeRunId, data_get($dispatch->metadata, 'manual_e2e_run_id'));
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
        $this->assertSame('manual_e2e_target_not_allowlisted', $blocked['blockers'][0]['code']);
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $blockedRequest->id,
            'message_type' => 'appointment_approved_customer',
        ]);
        Http::assertNothingSent();
    }

    public function test_non_manual_appointment_dispatch_remains_blocked_when_real_send_disabled(): void
    {
        Http::preventStrayRequests();
        $actor = $this->admin();
        $this->configureMessaging([
            'appointment_approved_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ]);
        app(TechnicalServiceMessagingSettingsService::class)->freezeManualE2E();
        $request = $this->technicalServiceRequest([
            'customer_phone' => '05372081633',
            'mrn' => 'MRN-NON-MANUAL-REAL-SEND-OFF',
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

        $this->assertSame(0, $summary['queued']);
        $this->assertSame(1, $summary['blocked']);
        $this->assertSame('real_send_disabled', $summary['blockers'][0]['code']);
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'appointment_approved_customer',
        ]);
        Http::assertNothingSent();
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

    public function test_assignment_whatsapp_and_sms_have_explicit_template_keys_and_same_earning_snapshot(): void
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
            'serial_number' => 'REL4E12-SERIAL-001',
            'operation_control_payload' => [
                'door_photos_checked' => 'compatible',
            ],
            'operation_control_checked_at' => now(),
            'operation_control_checked_by_user_id' => $actor->id,
        ]);

        $response = $this->actingAs($actor)
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
            ->assertOk();
        $this->assertSame(
            TechnicalServiceMessageDispatch::STATUS_QUEUED,
            data_get($response->json(), 'request.assignment_offer.dispatch_status'),
            $response->getContent(),
        );

        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'assignment_offer_technician')
            ->orderBy('channel')
            ->get();

        $this->assertCount(2, $dispatches);
        $this->assertSame(['sms', 'whatsapp'], $dispatches->pluck('channel')->all());
        $this->assertSame(['nac_sms', 'evo_whatsapp'], $dispatches->pluck('provider_key')->all());
        $smsContext = (array) data_get($dispatches->firstWhere('channel', 'sms')->request_payload, 'context', []);
        $whatsappContext = (array) data_get($dispatches->firstWhere('channel', 'whatsapp')->request_payload, 'context', []);
        foreach ([
            'mrn_or_srv',
            'customer_name',
            'customer_phone',
            'service_address',
            'maps_url',
            'product_name',
            'serial_no',
            'labor_amount_formatted',
            'route_fee_formatted',
            'total_amount_formatted',
            'technician_earning_total_formatted',
            'technician_job_card_url',
            'operation_note',
        ] as $contextKey) {
            $this->assertSame($whatsappContext[$contextKey] ?? null, $smsContext[$contextKey] ?? null, $contextKey);
        }
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'recipient_role' => 'customer',
            'message_type' => 'assignment_offer_technician',
        ]);

        foreach ($dispatches as $dispatch) {
            $body = (string) ($dispatch->request_payload['body'] ?? '');
            $this->assertSame('technician', $dispatch->recipient_role);
            $this->assertSame('assignment_offer_technician.'.$dispatch->channel.'.default', $dispatch->template_key);
            $this->assertNotNull($dispatch->template_version);
            $this->assertSame('905467647428', $dispatch->target_phone);
            $this->assertTrue((bool) data_get($dispatch->metadata, 'manual_e2e'));
            $this->assertTrue((bool) data_get($dispatch->metadata, 'allowlisted_target'));
            $this->assertSame($activeRunId, data_get($dispatch->metadata, 'manual_e2e_run_id'));
            $this->assertSame('905467647428', data_get($dispatch->metadata, 'role_target_phone'));
            $this->assertSame('905372081633', data_get($dispatch->request_payload, 'context.customer_phone'));
            $this->assertSame('REL4E12 test adresi Kadıköy İstanbul', data_get($dispatch->request_payload, 'context.service_address'));
            $this->assertStringContainsString('MRN-REL4E12-ASSIGN', $body);
            if ($dispatch->channel === 'sms') {
                $this->assertStringContainsString('REL4E12 Musteri', $body);
                $this->assertStringContainsString('/pj/'.$request->id, $body);
            } else {
                $this->assertStringContainsString('905372081633', $body);
                $this->assertStringContainsString('REL4E12 Müşteri', $body);
                $this->assertStringContainsString('randevu saati öneriniz', mb_strtolower($body, 'UTF-8'));
                $this->assertStringContainsString('job_id='.$request->id, $body);
            }
        }

        $whatsappBody = (string) ($dispatches->firstWhere('channel', 'whatsapp')->request_payload['body'] ?? '');
        $this->assertStringContainsString('Lütfen randevu saati öneriniz.', $whatsappBody);
        $this->assertStringContainsString('Hakediş Özeti', $whatsappBody);
        $this->assertStringContainsString('İşçilik/Montaj: 900 TL', $whatsappBody);
        $this->assertStringContainsString('Yol: 350 TL', $whatsappBody);
        $this->assertStringContainsString('Toplam: 1.250 TL', $whatsappBody);
        $smsBody = (string) ($dispatches->firstWhere('channel', 'sms')?->request_payload['body'] ?? '');
        $this->assertStringContainsString('Yeni is', $smsBody);
        $this->assertStringContainsString('REL4E12 Test Urun', $smsBody);
        $this->assertStringContainsString('Toplam hakedis: 1.250 TL', $smsBody);
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
                'services.partner_portal.public_url',
                data_get($dispatch->request_payload, 'context.technician_job_card_origin_source'),
            );
        }
        Http::assertNothingSent();
    }

    public function test_assignment_maps_url_uses_customer_coordinates_and_ignores_client_override(): void
    {
        $request = $this->technicalServiceRequest([
            'location_latitude' => '37.8980452',
            'location_longitude' => '29.1855785',
            'location_map_url' => 'https://maps.app.goo.gl/stored-customer-location',
            'service_address' => 'Merkez No:21',
            'customer_district' => 'Pamukkale',
            'customer_city' => 'Denizli',
        ]);
        $expected = 'https://www.google.com/maps/search/?api=1&query=37.8980452%2C29.1855785';

        $context = app(TechnicalServiceMessageContextBuilder::class)->build(
            'assignment_offer_technician',
            'whatsapp',
            [
                'request_id' => $request->id,
                'sample_context' => false,
                'context' => [
                    'maps_url' => 'https://www.google.com/maps/search/?api=1&query=39.9334%2C32.8597',
                ],
            ],
        )['context'];

        $this->assertSame($expected, $context['maps_url']);
        $this->assertStringNotContainsString('39.9334', (string) $context['maps_url']);
    }

    public function test_assignment_address_maps_url_matches_preview_and_dispatch_context_without_provider_call(): void
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
        $this->activateManualE2EFixture();
        $technician = $this->technician([
            'name' => 'Maps Test Usta',
            'phone' => '0546 764 74 28',
            'phone_e164' => '905467647428',
        ]);
        $this->linkTechnicianToPartner($technician);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-MAPS-ADDRESS-001',
            'technical_service_technician_id' => null,
            'service_address' => 'Merkez No:21',
            'customer_city' => 'Denizli',
            'customer_district' => 'Pamukkale',
            'location_latitude' => null,
            'location_longitude' => null,
            'location_map_url' => null,
            'serial_number' => 'MAPS-SERIAL-001',
            'operation_control_payload' => ['door_photos_checked' => 'compatible'],
            'operation_control_checked_at' => now(),
            'operation_control_checked_by_user_id' => $actor->id,
        ]);
        $expectedMapsUrl = 'https://www.google.com/maps/search/?api=1&query='
            .rawurlencode('Merkez No:21, Pamukkale, Denizli, Türkiye');

        $response = $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'confirm_assignment' => true,
                'travel_round_trip_km' => 36,
                'assignment_offer' => [
                    'labor_amount' => 900,
                    'route_fee_amount' => 350,
                    'note' => 'Server maps URL testi.',
                ],
            ])
            ->assertOk();

        $this->assertSame(
            $expectedMapsUrl,
            data_get($response->json(), 'request.location.map_url'),
        );
        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'assignment_offer_technician')
            ->orderBy('channel')
            ->get();
        $this->assertCount(2, $dispatches);

        foreach ($dispatches as $dispatch) {
            $this->assertSame($expectedMapsUrl, data_get($dispatch->request_payload, 'context.maps_url'));
        }

        $whatsappBody = (string) data_get($dispatches->firstWhere('channel', 'whatsapp')?->request_payload, 'body', '');
        $this->assertStringContainsString("Harita:\n{$expectedMapsUrl}\n", $whatsappBody);
        $this->assertSame(1, substr_count($whatsappBody, $expectedMapsUrl));
        Http::assertNothingSent();
    }

    public function test_assignment_maps_url_uses_validated_stored_url_before_address(): void
    {
        $storedUrl = 'https://maps.app.goo.gl/customer-location-token';
        $request = $this->technicalServiceRequest([
            'location_latitude' => null,
            'location_longitude' => null,
            'location_map_url' => $storedUrl,
            'service_address' => 'Adres fallback kullanılmamalı',
            'customer_district' => 'Pamukkale',
            'customer_city' => 'Denizli',
        ]);

        $context = app(TechnicalServiceMessageContextBuilder::class)->build(
            'assignment_offer_technician',
            'whatsapp',
            [
                'request_id' => $request->id,
                'sample_context' => false,
                'context' => ['maps_url' => null],
            ],
        )['context'];

        $this->assertSame($storedUrl, $context['maps_url']);
    }

    public function test_true_missing_assignment_location_returns_actionable_turkish_error(): void
    {
        Http::fake();
        $actor = $this->admin();
        $technician = $this->technician([
            'name' => 'Konum Test Usta',
            'phone' => '0546 764 74 28',
            'phone_e164' => '905467647428',
        ]);
        $this->linkTechnicianToPartner($technician);
        $request = $this->technicalServiceRequest([
            'technical_service_technician_id' => null,
            'service_address' => ' ',
            'customer_city' => 'Denizli',
            'customer_district' => 'Pamukkale',
            'location_latitude' => null,
            'location_longitude' => null,
            'location_formatted_address' => null,
            'location_map_url' => null,
            'operation_control_payload' => ['door_photos_checked' => 'compatible'],
            'operation_control_checked_at' => now(),
            'operation_control_checked_by_user_id' => $actor->id,
        ]);

        $response = $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'confirm_assignment' => true,
                'travel_round_trip_km' => 0,
                'assignment_offer' => [
                    'labor_amount' => 900,
                    'route_fee_amount' => 0,
                    'note' => 'Konum eksikliği testi.',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignment_offer');

        $this->assertSame(
            'Müşteri adresi veya konumu eksik. Atamadan önce müşteri bilgilerini tamamlayın.',
            $response->json('errors.assignment_offer.0'),
        );
        $this->assertStringNotContainsString('maps_url', (string) $response->json('message'));
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'assignment_offer_technician',
        ]);
        Http::assertNothingSent();
    }

    public function test_payment_link_send_creates_customer_whatsapp_and_sms_dispatches_without_provider_call(): void
    {
        Http::fake();
        config(['services.partner_portal.public_url' => 'https://pay.example.test']);
        $actor = $this->admin();
        $this->configureGuardedLiveMessaging([
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
            'payment_url' => 'https://pay.example.test/mount-payment/pay-rel4e10',
            'raw_payload' => ['source' => 'rel4e10_test'],
        ]);

        $response = $this->actingAs($actor)
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                $this->paymentLinkSendPayload($payment),
            );

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

        $this->assertTrue(TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'payment_link_customer')
            ->get()
            ->every(fn (TechnicalServiceMessageDispatch $dispatch): bool => $dispatch->target_phone === '905372081633'
                && ! $dispatch->test_redirect_applied));

        $body = (string) TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'payment_link_customer')
            ->where('channel', 'whatsapp')
            ->firstOrFail()
            ->request_payload['body'];

        $this->assertStringContainsString('https://pay.example.test/mount-payment/pay-rel4e10', $body);
        $this->assertStringContainsString('1.250,00 TL', $body);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'mount_payment_link_send_requested',
        ]);
        Http::assertNothingSent();
    }

    public function test_send_requires_exact_payment_id(): void
    {
        [$actor, $request, $payment] = $this->paymentLinkFixture();

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link", [
                'send_request_id' => Str::uuid()->toString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_id']);

        $this->assertSame(0, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->count());
        Http::assertNothingSent();
    }

    public function test_distinct_pending_payment_is_sendable_when_another_payment_is_paid(): void
    {
        [$actor, $request, $pending] = $this->paymentLinkFixture([
            'amount' => 1000,
            'raw_payload' => [
                'source' => 'operation_customer_charge',
                'purpose' => 'service_payment',
                'charge_type' => 'service_payment',
            ],
        ]);
        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $pending->technical_service_mount_session_id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'paid-distinct-obligation',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3000,
            'currency' => 'TRY',
            'payment_url' => 'https://pay.example.test/mount-payment/paid-distinct-obligation',
            'paid_at' => now(),
            'raw_payload' => [
                'source' => 'manual_mount_payment',
                'purpose' => 'manual_mount_payment',
                'charge_type' => 'manual_mount_payment',
            ],
        ]);

        $response = $this->actingAs($actor)
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$pending->id}/send-link",
                $this->paymentLinkSendPayload($pending),
            )
            ->assertOk()
            ->assertJsonPath('dispatches.queued', 2)
            ->assertJsonPath('dispatches.blocked', 0);

        $this->assertStringContainsString('1.000,00 TL tutarındaki Ek servis', (string) $response->json('message'));
        $this->assertSame(2, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('metadata->payment_id', $pending->id)
            ->count());
        Http::assertNothingSent();
    }

    public function test_paid_selected_payment_creates_no_dispatch(): void
    {
        [$actor, $request, $payment] = $this->paymentLinkFixture([
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->actingAs($actor)
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                $this->paymentLinkSendPayload($payment),
            )
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment.0', 'Bu ödeme zaten tahsil edildi; bağlantı yeniden gönderilemez.');

        $this->assertSame(0, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->count());
    }

    public function test_cancelled_selected_payment_creates_no_dispatch(): void
    {
        [$actor, $request, $payment] = $this->paymentLinkFixture([
            'status' => TechnicalServiceMountPayment::STATUS_CANCELLED,
        ]);

        $this->actingAs($actor)
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                $this->paymentLinkSendPayload($payment),
            )
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment.0', 'Bu ödeme bağlantısı iptal edildi; yeniden gönderilemez.');

        $this->assertSame(0, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->count());
    }

    public function test_ambiguous_payment_identity_fails_before_dispatch_creation(): void
    {
        [$actor, $request, $payment] = $this->paymentLinkFixture();

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link", [
                'payment_id' => $payment->id + 1,
                'send_request_id' => Str::uuid()->toString(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment_id.0', 'Gönderilecek ödeme bağlantısı belirlenemedi. Lütfen aktif ödeme kaydını seçin.');

        $this->assertSame(0, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->count());
    }

    public function test_send_uses_canonical_amount_purpose_and_url(): void
    {
        [$actor, $request, $payment] = $this->paymentLinkFixture([
            'amount' => 3000,
            'payment_url' => 'https://pay.example.test/mount-payment/canonical-selected-link',
            'raw_payload' => [
                'source' => 'operation_customer_charge',
                'purpose' => 'service_payment',
                'charge_type' => 'service_payment',
            ],
        ]);

        $response = $this->actingAs($actor)
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                [
                    ...$this->paymentLinkSendPayload($payment),
                    'amount' => 1,
                    'purpose' => 'manual_mount_payment',
                    'payment_url' => 'https://evil.example.test/not-authority',
                ],
            )
            ->assertOk()
            ->assertJsonPath('payment.id', $payment->id)
            ->assertJsonPath('payment.purpose', 'service_payment')
            ->assertJsonPath('payment.purpose_label', 'Ek servis')
            ->assertJsonPath('payment.link_token', 'canonical-selected-link');

        $this->assertStringContainsString('3.000,00 TL tutarındaki Ek servis', (string) $response->json('message'));
        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('metadata->payment_id', $payment->id)
            ->get();
        $this->assertCount(2, $dispatches);
        foreach ($dispatches as $dispatch) {
            $body = (string) data_get($dispatch->request_payload, 'body');
            $this->assertStringContainsString('3.000,00 TL', $body);
            $this->assertStringContainsString('https://pay.example.test/mount-payment/canonical-selected-link', $body);
            $this->assertStringNotContainsString('evil.example.test', $body);
            $this->assertSame($payment->id, data_get($dispatch->metadata, 'payment_id'));
            $this->assertSame('service_payment', data_get($dispatch->metadata, 'payment_purpose'));
        }
    }

    public function test_duplicate_send_creates_one_dispatch_per_channel(): void
    {
        [$actor, $request, $payment] = $this->paymentLinkFixture();
        $sendRequestId = Str::uuid()->toString();
        $payload = $this->paymentLinkSendPayload($payment, null, $sendRequestId);

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link", $payload)
            ->assertOk()
            ->assertJsonPath('dispatches.queued', 2);
        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link", $payload)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        $this->assertSame(1, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('channel', 'whatsapp')
            ->count());
        $this->assertSame(1, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('channel', 'sms')
            ->count());
    }

    public function test_resend_requires_reason(): void
    {
        [$actor, $request, $payment] = $this->paymentLinkFixture();

        $this->actingAs($actor)
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                $this->paymentLinkSendPayload($payment),
            )
            ->assertOk();
        $this->actingAs($actor)
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                $this->paymentLinkSendPayload($payment),
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resend_reason']);

        $this->assertSame(2, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->count());
    }

    public function test_duplicate_resend_creates_one_dispatch_per_channel(): void
    {
        [$actor, $request, $payment] = $this->paymentLinkFixture();
        $this->actingAs($actor)
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                $this->paymentLinkSendPayload($payment),
            )
            ->assertOk();

        $resendRequestId = Str::uuid()->toString();
        $resend = $this->paymentLinkSendPayload($payment, 'Müşteri yeniden gönderim istedi.', $resendRequestId);
        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link", $resend)
            ->assertOk()
            ->assertJsonPath('dispatches.queued', 2);
        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link", $resend)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        $this->assertSame(2, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('channel', 'whatsapp')
            ->count());
        $this->assertSame(2, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('channel', 'sms')
            ->count());
    }

    public function test_payment_link_send_uses_customer_test_recipient_in_test_mode(): void
    {
        Http::fake();
        config(['services.partner_portal.public_url' => 'https://pay.example.test']);
        $actor = $this->admin();
        $this->configureRoleBasedCustomerMessaging(true);
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'message_types' => [
                'payment_link_customer' => [
                    'enabled' => true,
                    'channel_policy' => 'whatsapp_and_sms',
                ],
            ],
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-REL4E10-PAYMENT-TEST-MODE',
            'customer_phone' => '05559998877',
        ]);
        $session = $this->mountSession('REL4E10-PAYMENT-TEST-MODE');
        $request->forceFill(['mount_session_id' => $session->id])->save();
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'pay-rel4e10-test-mode',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 1250,
            'currency' => 'TRY',
            'payment_url' => 'https://pay.example.test/mount-payment/pay-rel4e10-test-mode',
            'raw_payload' => ['source' => 'rel4e10_test_mode'],
        ]);

        $this->actingAs($actor)
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                $this->paymentLinkSendPayload($payment),
            )
            ->assertOk()
            ->assertJsonPath('dispatches.queued', 2);

        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'payment_link_customer')
            ->get();
        $this->assertCount(2, $dispatches);
        $this->assertTrue($dispatches->every(
            fn (TechnicalServiceMessageDispatch $dispatch): bool => $dispatch->recipient_role === 'customer'
                && $dispatch->original_phone === '905559998877'
                && $dispatch->target_phone === '905372081633'
                && $dispatch->test_redirect_applied,
        ));
        Http::assertNothingSent();
    }

    public function test_cancelled_or_expired_link_cannot_be_sent(): void
    {
        Http::fake();
        $actor = $this->admin();
        $request = $this->technicalServiceRequest(['customer_phone' => '05372081633']);
        $session = $this->mountSession('REL4E10-TERMINAL-PAYMENT');
        $request->forceFill(['mount_session_id' => $session->id])->save();

        foreach ([TechnicalServiceMountPayment::STATUS_CANCELLED, TechnicalServiceMountPayment::STATUS_EXPIRED] as $status) {
            $payment = TechnicalServiceMountPayment::query()->create([
                'technical_service_mount_session_id' => $session->id,
                'technical_service_request_id' => $request->id,
                'provider' => 'fake',
                'provider_reference' => "pay-rel4e10-{$status}",
                'status' => $status,
                'amount' => 1250,
                'currency' => 'TRY',
                'payment_url' => "https://pay.example.test/mount-payment/pay-rel4e10-{$status}",
                'raw_payload' => ['source' => 'rel4e10_terminal_send_guard'],
            ]);

            $this->actingAs($actor)
                ->postJson(
                    "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                    $this->paymentLinkSendPayload($payment),
                )
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['payment']);
        }

        $this->assertSame(0, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->whereIn('message_type', ['payment_link_customer', 'part_fee_payment_link_customer'])
            ->count());
        Http::assertNothingSent();
    }

    public function test_srv_payment_link_customer_uses_srv_reference_without_internal_mrn(): void
    {
        Http::fake();
        config(['services.partner_portal.public_url' => 'https://pay.example.test']);
        $actor = $this->admin();
        $this->configureGuardedLiveMessaging([
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
            'payment_url' => 'https://pay.example.test/mount-payment/pay-rel4e13b',
            'raw_payload' => ['source' => 'rel4e13b_srv_payment_test'],
        ]);

        $this->actingAs($actor)
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                $this->paymentLinkSendPayload($payment),
            )
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
        config(['services.partner_portal.public_url' => 'https://pay.example.test']);
        $actor = $this->admin();
        $this->configureGuardedLiveMessaging([
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
            'payment_url' => 'https://pay.example.test/mount-payment/pay-rel4e16-stale',
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
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                $this->paymentLinkSendPayload($payment),
            )
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
        config(['services.partner_portal.public_url' => 'https://pay.example.test']);
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
            'payment_url' => 'https://pay.example.test/mount-payment/pay-rel4e16-legacy-part',
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
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                $this->paymentLinkSendPayload($payment),
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resend_reason']);

        Http::assertNothingSent();
    }

    public function test_part_fee_payment_link_send_uses_part_fee_type_and_duplicate_guard(): void
    {
        Http::fake();
        config(['services.partner_portal.public_url' => 'https://pay.example.test']);
        $actor = $this->admin();
        $this->configureGuardedLiveMessaging([
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
            'payment_url' => 'https://pay.example.test/mount-payment/pay-rel4e13c-part',
            'raw_payload' => [
                'source' => 'operation_customer_charge',
                'purpose' => 'part_payment',
                'charge_type' => 'part_payment',
                'part_request_id' => 987,
            ],
        ]);

        $this->actingAs($actor)
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                $this->paymentLinkSendPayload($payment),
            )
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
            $this->assertStringContainsString('https://pay.example.test/mount-payment/pay-rel4e13c-part', $body);
            $this->assertStringNotContainsString('MRN-REL4E13C-INTERNAL', $body);
            $this->assertSame($body, $dispatch->bodyForProvider());
        }

        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'payment_link_customer',
        ]);

        $this->actingAs($actor)
            ->postJson(
                "/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link",
                $this->paymentLinkSendPayload($payment),
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resend_reason']);

        $this->actingAs($actor)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/send-link", [
                ...$this->paymentLinkSendPayload($payment),
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
        config(['services.partner_portal.public_url' => 'https://pay.example.test']);
        $this->configureGuardedLiveMessaging([
            'payment_received_ops' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ], [
            'ops_whatsapp_enabled' => true,
            'ops_whatsapp_phone' => '0546 764 74 28',
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
            'payment_url' => 'https://pay.example.test/mount-payment/pay-rel4e13b-paid',
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
        $this->configureGuardedLiveMessaging([
            'customer_approval_request' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
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

    public function test_configured_local_lan_origin_is_ready_for_guarded_customer_confirmation_dispatch(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config([
            'services.partner_portal.public_url' => 'http://10.0.28.64:8000',
            'services.public_urls.app_url' => null,
            'services.public_urls.qr_base_url' => null,
            'services.public_urls.payment_base_url' => null,
        ]);

        $service = app(TechnicalServiceWorkflowMessageDispatchService::class);

        $this->assertTrue($service->publicUrlReadyForDispatch(
            'http://10.0.28.64:8000/service-job-confirmation/local-proof',
        ));
        $this->assertFalse($service->publicUrlReadyForDispatch(
            'http://127.0.0.1:8000/service-job-confirmation/local-proof',
        ));
        $this->assertFalse($service->publicUrlReadyForDispatch(
            'http://10.0.28.65:8000/service-job-confirmation/local-proof',
        ));
    }

    public function test_workflow_dispatch_reuses_one_settings_snapshot_for_enqueue_metadata(): void
    {
        $source = file_get_contents(app_path('Services/Messaging/TechnicalServiceWorkflowMessageDispatchService.php'));

        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            '/public function queueWorkflowDispatches\(.*?public function queueSystemMessage\(/s',
            $source,
        );
        preg_match(
            '/public function queueWorkflowDispatches\((.*?)public function queueSystemMessage\(/s',
            $source,
            $method,
        );

        $this->assertSame(0, substr_count($method[1], '$this->settings->payload()'));
        $this->assertSame(1, substr_count($method[1], '$this->settings->workflowDispatchSnapshot()'));
        $this->assertStringContainsString("'real_send_enabled_at_enqueue' => \$realSendEnabledAtEnqueue", $source);
    }

    public function test_appointment_dispatch_uses_workflow_snapshot_without_full_admin_readiness(): void
    {
        $source = file_get_contents(app_path('Services/Messaging/TechnicalServiceAppointmentMessageDispatchService.php'));

        $this->assertIsString($source);
        preg_match(
            '/private function createDispatches\((.*?)private function createPlannedDispatch\(/s',
            $source,
            $method,
        );

        $this->assertArrayHasKey(1, $method);
        $this->assertSame(1, substr_count($method[1], '$this->settings->workflowDispatchSnapshot()'));
        $this->assertSame(0, substr_count($method[1], '$this->settings->payload()'));
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

    public function test_final_control_activation_warranty_customer_message_does_not_invent_survey_route(): void
    {
        $actor = $this->admin();
        $this->configureGuardedLiveMessaging([
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
                'survey_link' => null,
                'survey_link_sms' => null,
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
        $this->assertStringNotContainsString('survey=1', $body);

        $controllerSource = file_get_contents(app_path('Http/Controllers/Api/TechnicalServicePartnerPortalOpsController.php')) ?: '';
        $this->assertStringContainsString("'activation_warranty_customer' =>", $controllerSource);
        $this->assertStringContainsString('$surveyLink = null;', $controllerSource);
        $this->assertStringNotContainsString('completionSurveyLink', $controllerSource);
        $this->assertStringNotContainsString('customer_completion_survey_link_logged', $controllerSource);
        $this->assertStringNotContainsString("'final_control_completed_customer' => $".'this->workflowMessages->queueWorkflowDispatches', $controllerSource);
        $this->assertStringNotContainsString("'activation_code_customer' => $".'this->workflowMessages->queueWorkflowDispatches', $controllerSource);
        $this->assertStringNotContainsString("'warranty_started_customer' => $".'this->workflowMessages->queueWorkflowDispatches', $controllerSource);
    }

    public function test_payment_link_send_button_and_amount_steps_are_queue_safe_in_frontend_source(): void
    {
        $detailSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx')) ?: '';
        $actionsSource = file_get_contents(resource_path('js/components/technical-service/PendingPaymentLinkActions.tsx')) ?: '';
        $pageSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx')) ?: '';
        $compactDetailSource = preg_replace('/\s+/', '', $detailSource) ?? $detailSource;

        $this->assertStringContainsString('Linki gönder', $actionsSource);
        $this->assertStringContainsString('onMountPaymentSend(payment.id, { resend_reason: resendReason })', $detailSource);
        $this->assertStringContainsString('placeholder="Yeniden gönderim nedeni"', $detailSource);
        $this->assertStringContainsString('Yeniden gönderim nedeni en az 3 karakter olmalıdır.', $detailSource);
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
        $actionsSource = file_get_contents(resource_path('js/components/technical-service/PendingPaymentLinkActions.tsx')) ?: '';
        $standardPendingCardStart = strpos($detailSource, 'label="Bekleyen link"');

        $this->assertNotFalse($standardPendingCardStart);

        $standardPendingCard = substr($detailSource, (int) $standardPendingCardStart, 5000);

        $this->assertStringContainsString('renderPendingPaymentLinkActions(extraMountPayment, pendingPaymentSurface)', $standardPendingCard);
        $this->assertStringContainsString('Linki aç', $actionsSource);
        $this->assertStringContainsString('Linki kopyala', $actionsSource);
        $this->assertStringContainsString('Linki gönder', $actionsSource);
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
        $this->configureGuardedLiveMessaging([
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
        $this->configureGuardedLiveMessaging([
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
        $this->configureGuardedLiveMessaging([
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
        $this->configureGuardedLiveMessaging([
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
        $this->configureGuardedLiveMessaging([
            'new_request_created_ops' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
        ], [
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

    public function test_mount_request_customer_whatsapp_and_sms_use_same_customer_role(): void
    {
        [$request, $dispatches] = $this->queueMountRequestCustomerMessages();

        $this->assertCount(2, $dispatches);
        $this->assertSame(['sms', 'whatsapp'], $dispatches->pluck('channel')->sort()->values()->all());
        $this->assertTrue($dispatches->every(
            fn (TechnicalServiceMessageDispatch $dispatch): bool => $dispatch->recipient_role === 'customer',
        ));
        $this->assertTrue($dispatches->every(
            fn (TechnicalServiceMessageDispatch $dispatch): bool => $dispatch->original_phone === '905559998877',
        ));
        $this->assertSame($request->id, $dispatches->first()->technical_service_request_id);
        Http::assertNothingSent();
    }

    public function test_mount_request_sms_uses_customer_test_phone(): void
    {
        [, $dispatches] = $this->queueMountRequestCustomerMessages();
        $sms = $dispatches->firstWhere('channel', 'sms');
        $whatsapp = $dispatches->firstWhere('channel', 'whatsapp');

        $this->assertInstanceOf(TechnicalServiceMessageDispatch::class, $sms);
        $this->assertInstanceOf(TechnicalServiceMessageDispatch::class, $whatsapp);
        $this->assertSame('905372081633', $sms->target_phone);
        $this->assertSame('905372081633', $whatsapp->target_phone);
        $this->assertNotSame('905467647428', $sms->target_phone);
        $this->assertTrue((bool) $sms->test_redirect_applied);
        Http::assertNothingSent();
    }

    public function test_historical_blocked_dispatches_are_not_replayed(): void
    {
        $historicalRequest = $this->technicalServiceRequest(['mrn' => 'MRN-HISTORICAL-BLOCKED']);
        $historical = TechnicalServiceMessageDispatch::query()->create([
            'event' => 'mount_request_created_customer',
            'technical_service_request_id' => $historicalRequest->id,
            'request_id' => $historicalRequest->id,
            'message_type' => 'mount_request_created_customer',
            'channel' => 'sms',
            'provider_key' => 'nac_sms',
            'recipient_role' => 'customer',
            'original_phone' => '905559998877',
            'target_phone' => '905467647428',
            'status' => TechnicalServiceMessageDispatch::STATUS_BLOCKED,
            'attempt_count' => 0,
            'last_error_code' => 'template_blocked',
        ]);

        $this->queueMountRequestCustomerMessages();

        $historical->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $historical->status);
        $this->assertSame(0, $historical->attempt_count);
        $this->assertNull($historical->provider_message_id);
        $this->assertNull($historical->started_at);
        Http::assertNothingSent();
    }

    public function test_duplicate_business_event_creates_one_dispatch_per_channel(): void
    {
        Http::fake();
        $this->configureRoleBasedCustomerMessaging(true);
        $actor = $this->admin();
        $request = $this->technicalServiceRequest(['technical_service_technician_id' => null]);
        $service = app(TechnicalServiceWorkflowMessageDispatchService::class);

        $first = $service->queueWorkflowDispatches($request, 'mount_request_created_customer', 'customer', [], $actor);
        $second = $service->queueWorkflowDispatches($request, 'mount_request_created_customer', 'customer', [], $actor);

        $this->assertTrue((bool) $first['provider_policy_attempted']);
        $this->assertSame(2, (int) $second['duplicate_blocked']);
        $this->assertSame(2, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'mount_request_created_customer')
            ->where('status', '!=', TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED)
            ->count());
        $this->assertSame(2, TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'mount_request_created_customer')
            ->where('status', TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED)
            ->count());
        $this->assertSame(['sms', 'whatsapp'], TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'mount_request_created_customer')
            ->where('status', '!=', TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED)
            ->pluck('channel')->sort()->values()->all());
        Http::assertNothingSent();
    }

    public function test_technician_cancel_messages_pass_role_body_validation(): void
    {
        Http::fake();
        config()->set('services.partner_portal.public_url', 'https://technician-portal.example.test');
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
        $technicianDispatches = $dispatches->where('recipient_role', 'technician')->values();
        $this->assertCount(2, $technicianDispatches);
        foreach ($technicianDispatches as $technicianDispatch) {
            $this->assertSame(
                [],
                $technicianDispatch->roleBodyValidationErrors(),
                $technicianDispatch->channel.': '.$technicianDispatch->bodyForProvider(),
            );
        }
        $technicianWhatsapp = $technicianDispatches->firstWhere('channel', 'whatsapp');
        $technicianSms = $technicianDispatches->firstWhere('channel', 'sms');
        $this->assertInstanceOf(TechnicalServiceMessageDispatch::class, $technicianWhatsapp);
        $this->assertInstanceOf(TechnicalServiceMessageDispatch::class, $technicianSms);
        $this->assertSame('905467647428', $technicianWhatsapp->target_phone);
        $this->assertSame('905467647428', $technicianSms->target_phone);
        $this->assertStringContainsString('Müşteri randevunun iptalini istedi.', $technicianWhatsapp->bodyForProvider());
        $this->assertStringContainsString('İş Kartı', $technicianWhatsapp->bodyForProvider());
        $this->assertMatchesRegularExpression('/https?:\/\/[^\s]+/', $technicianWhatsapp->bodyForProvider());
        $this->assertMatchesRegularExpression('/(?:^|\R)Kart\s+https?:\/\/[^\s]+/u', $technicianSms->bodyForProvider());
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
     * @return array{0: TechnicalServiceRequest, 1: Collection<int, TechnicalServiceMessageDispatch>}
     */
    private function queueMountRequestCustomerMessages(): array
    {
        Http::fake();
        $this->configureRoleBasedCustomerMessaging();
        $actor = $this->admin();
        $request = $this->technicalServiceRequest([
            'technical_service_technician_id' => null,
            'customer_phone' => '+905559998877',
            'product_name' => 'Akilli Kilit Plus',
            'product_model' => 'EK-2026',
        ]);

        app(TechnicalServiceWorkflowMessageDispatchService::class)->queueWorkflowDispatches(
            $request,
            'mount_request_created_customer',
            'customer',
            [],
            $actor,
        );

        return [
            $request,
            TechnicalServiceMessageDispatch::query()
                ->where('technical_service_request_id', $request->id)
                ->where('message_type', 'mount_request_created_customer')
                ->get(),
        ];
    }

    private function configureRoleBasedCustomerMessaging(bool $realSend = false): void
    {
        config([
            'app.release_sha' => 'd086045e3013d1a7f0472b95f0193a1f35951d13',
            'services.evolution.allow_unit_test_http_fake' => true,
        ]);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->freezeManualE2E();
        $settings->update([
            'messaging_enabled' => true,
            'real_send_enabled' => false,
            'test_mode_enabled' => true,
            'shared_test_phone' => '0537 208 16 33',
            'customer_test_phone' => '0537 208 16 33',
            'technician_ops_test_phone' => '0546 764 74 28',
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
            'manual_e2e_partner_portal_origin_enabled' => true,
            'manual_e2e_partner_portal_origin' => 'http://10.0.28.64:8000',
            'max_auto_retries' => 0,
            'active_provider' => 'evo_whatsapp',
            'provider_key' => 'evo_whatsapp',
            'evo_whatsapp' => [
                'direct_api_enabled' => true,
                'direct_api_base_url' => 'https://evo-api.example.test',
                'direct_api_instance_name' => 'role-routing-fixture',
            ],
            'nac_sms' => [
                'enabled' => true,
                'profile' => 'custom',
                'scheme' => 'https',
                'host' => 'nac.example.test',
                'port' => 443,
                'path' => '/sms/create',
                'request_shape' => 'legacy_working_minimal',
                'sender' => 'EMAKS PRIME',
                'real_send_allowed' => true,
            ],
            'message_types' => [
                'mount_request_created_customer' => [
                    'enabled' => true,
                    'channel_policy' => 'whatsapp_and_sms',
                ],
            ],
        ]);

        if ($realSend) {
            $this->enableExecutionModeProviders();
            $settings->saveEvoWhatsappCredentials(['api_key' => 'role-routing-evo-key']);
            $settings->saveNacSmsCredentials(['username' => 'role-routing-nac-user', 'password' => 'role-routing-nac-pass']);
            $settings->update([
                'real_send_enabled' => true,
                'test_mode_enabled' => true,
            ]);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $messageTypes
     */
    private function configureGuardedLiveMessaging(array $messageTypes, array $overrides = []): void
    {
        Http::preventStrayRequests();
        $admin = $this->admin();
        $this->actingAs($admin);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->freezeManualE2E();
        if (($settings->executionModePayload()['mode'] ?? null) !== TechnicalServiceMessagingSettingsService::OUTBOUND_EXECUTION_MODE_LOCAL) {
            $settings->transitionExecutionMode(
                TechnicalServiceMessagingSettingsService::OUTBOUND_EXECUTION_MODE_LOCAL,
                'Appointment fixture settings are being refreshed safely.',
                $admin,
                (int) $settings->executionModePayload()['revision'],
            );
        }

        $messageTypes = array_map(
            fn (array $messageType): array => [
                'real_send_allowed' => true,
                ...$messageType,
            ],
            $messageTypes,
        );
        $this->configureMessaging($messageTypes);

        $settings->update([
            'test_mode_enabled' => false,
            'manual_e2e_allowlisted_phones' => [
                '905372081633',
                '905467647428',
                '905559998877',
                '905551112233',
            ],
            'manual_e2e_partner_portal_origin_enabled' => true,
            'manual_e2e_partner_portal_origin' => 'http://10.10.10.10:8000',
            'active_provider' => 'evo_whatsapp',
            'provider_key' => 'evo_whatsapp',
            'evo_whatsapp' => [
                'direct_api_enabled' => true,
                'direct_api_base_url' => 'https://evo-api.example.test',
                'direct_api_instance_name' => 'appointment-live-fixture',
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
            ...$overrides,
        ]);
        $this->enableExecutionModeProviders();
        $settings->saveEvoWhatsappCredentials(['api_key' => 'fixture-evo-api-key']);
        $settings->saveNacSmsCredentials(['username' => 'fixture-nac-user', 'password' => 'fixture-nac-password']);
        $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
        $settings->prepareManualE2E();
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
        Http::preventStrayRequests();
        $admin = $this->admin();
        $this->actingAs($admin);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $opsWhatsappEnabled = (bool) $settings->payload()['global']['ops_whatsapp_enabled'];
        $settings->freezeManualE2E();
        $settings->update([
            'messaging_enabled' => true,
            'test_mode_enabled' => false,
            'shared_test_phone' => '905467647428',
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
            'manual_e2e_partner_portal_origin_enabled' => true,
            'manual_e2e_partner_portal_origin' => 'http://10.0.28.64:8000',
            'active_provider' => 'evo_whatsapp',
            'provider_key' => 'evo_whatsapp',
            'evo_whatsapp' => [
                'direct_api_enabled' => true,
                'direct_api_base_url' => 'https://evo-api.example.test',
                'direct_api_instance_name' => 'manual-e2e-fixture',
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
                'assignment_offer_technician' => [
                    'enabled' => true,
                    'real_send_allowed' => true,
                    'channel_policy' => 'whatsapp_and_sms',
                ],
            ],
            'ops_whatsapp_enabled' => $opsWhatsappEnabled,
            'ops_whatsapp_phone' => '905467647428',
            ...$overrides,
        ]);
        $this->enableExecutionModeProviders();
        $settings->saveEvoWhatsappCredentials(['api_key' => 'fixture-evo-api-key']);
        $settings->saveNacSmsCredentials(['username' => 'fixture-nac-user', 'password' => 'fixture-nac-password']);
        $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
        $payload = $settings->prepareManualE2E();
        $global = $payload['global'];
        $lifecycleLayout = PageConfig::query()
            ->where('page_code', TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE)
            ->value('layout_json');

        $this->assertSame(TechnicalServiceMessagingSettingsService::MANUAL_E2E_PHASE_PREPARED, $global['manual_e2e_phase']);
        $this->assertTrue($global['manual_e2e_enabled']);
        $this->assertFalse($global['real_send_enabled']);
        $this->assertFalse($global['test_mode_enabled']);
        $this->assertTrue($global['queue_paused']);
        $this->assertNotSame('', (string) data_get(
            $lifecycleLayout,
            TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY.'.manual_e2e_run_snapshot.allowlist_fingerprint',
        ));

        return $global;
    }

    private function enableExecutionModeProviders(): void
    {
        foreach ([
            [
                'page_code' => TechnicalServiceMessagingSettingsService::PAGE_CODE,
                'root' => TechnicalServiceMessagingSettingsService::ROOT_KEY,
            ],
            [
                'page_code' => TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE,
                'root' => TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY,
            ],
        ] as $target) {
            $page = PageConfig::query()->where('page_code', $target['page_code'])->firstOrFail();
            $layout = (array) $page->layout_json;
            Arr::set($layout, $target['root'].'.providers.evo_whatsapp', [
                'enabled' => true,
                'real_send_allowed' => true,
                'test_send_allowed' => true,
                'notes' => 'Synthetic execution-mode fixture provider.',
            ]);
            $page->forceFill(['layout_json' => $layout])->save();
        }
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    /** @return array{payment_id:int,send_request_id:string,resend_reason:string|null} */
    private function paymentLinkSendPayload(
        TechnicalServiceMountPayment $payment,
        ?string $resendReason = null,
        ?string $sendRequestId = null,
    ): array {
        return [
            'payment_id' => (int) $payment->id,
            'send_request_id' => $sendRequestId ?? Str::uuid()->toString(),
            'resend_reason' => $resendReason,
        ];
    }

    /**
     * @param  array<string, mixed>  $paymentOverrides
     * @param  array<string, mixed>  $requestOverrides
     * @return array{0:User,1:TechnicalServiceRequest,2:TechnicalServiceMountPayment}
     */
    private function paymentLinkFixture(array $paymentOverrides = [], array $requestOverrides = []): array
    {
        Http::fake();
        config(['services.partner_portal.public_url' => 'https://pay.example.test']);
        $actor = $this->admin();
        $this->configureGuardedLiveMessaging([
            'payment_link_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
            'part_fee_payment_link_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_and_sms'],
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-SELECTED-PAYMENT-'.Str::upper(Str::random(6)),
            'customer_phone' => '05372081633',
            ...$requestOverrides,
        ]);
        $session = $this->mountSession('SELECTED-PAYMENT-'.Str::upper(Str::random(6)));
        $request->forceFill(['mount_session_id' => $session->id])->save();
        $rawPayload = [
            'source' => 'operation_customer_charge',
            'purpose' => 'service_payment',
            'charge_type' => 'service_payment',
            ...((array) ($paymentOverrides['raw_payload'] ?? [])),
        ];
        unset($paymentOverrides['raw_payload']);
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'selected-payment-'.Str::lower(Str::random(8)),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 1000,
            'currency' => 'TRY',
            'payment_url' => 'https://pay.example.test/mount-payment/selected-payment-link',
            'raw_payload' => $rawPayload,
            ...$paymentOverrides,
        ]);

        return [$actor, $request, $payment];
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
