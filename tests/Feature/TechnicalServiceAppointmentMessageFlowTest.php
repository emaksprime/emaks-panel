<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMessageTemplate;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceAppointmentMessageDispatchService;
use App\Services\Messaging\TechnicalServiceMessageTemplateService;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            $this->assertStringContainsString('job_id='.$request->id, $body);
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

        return TechnicalServiceRequest::query()->create([
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
}
