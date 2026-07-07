<?php

namespace Tests\Feature;

use App\Models\IntegrationProviderCredential;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestEvent;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceMessageChannelPlanner;
use App\Services\Messaging\TechnicalServiceMessageDispatchLogService;
use App\Services\Messaging\TechnicalServiceMessageDispatchProcessor;
use App\Services\Messaging\TechnicalServiceMessageDispatchQueue;
use App\Services\Messaging\TechnicalServiceMessageDispatchStatusRegistry;
use App\Services\Messaging\TechnicalServiceMessageIdempotencyService;
use App\Services\Messaging\TechnicalServiceMessageProviderRouter;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Messaging\TechnicalServiceWorkflowMessageDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TechnicalServiceMessageDispatchQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_dispatch_schema_and_status_registry_contains_expected_statuses(): void
    {
        $dispatch = $this->enqueueDispatch();
        $statuses = app(TechnicalServiceMessageDispatchStatusRegistry::class)->statuses();

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
        $this->assertContains(TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED, $statuses);
        $this->assertContains(TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED, $statuses);
        $this->assertContains(TechnicalServiceMessageDispatch::STATUS_TEST_SENT, $statuses);
        $this->assertNotNull($dispatch->idempotency_key);
        $this->assertNotNull($dispatch->effective_target_phone_hash);
        $this->assertSame('9053***233', $dispatch->effective_target_phone_mask);
    }

    public function test_idempotency_duplicate_guard_blocks_duplicate_before_provider_call(): void
    {
        Http::fake();

        $first = $this->enqueueDispatch(['message_type' => 'appointment_approved_customer']);
        $second = $this->enqueueDispatch(['message_type' => 'appointment_approved_customer']);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $first->status);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED, $second->status);
        $this->assertSame($first->id, $second->metadata['duplicate_dispatch_id']);
        Http::assertNothingSent();
    }

    public function test_duplicate_guard_sent_message_not_requeued_and_failed_can_retry_if_policy_allows(): void
    {
        $sent = $this->enqueueDispatch(['message_type' => 'customer_approval_request']);
        $sent->forceFill(['status' => TechnicalServiceMessageDispatch::STATUS_SENT, 'sent_at' => now()])->save();

        $duplicate = $this->enqueueDispatch(['message_type' => 'customer_approval_request']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED, $duplicate->status);

        $failed = $this->enqueueDispatch(['message_type' => 'payment_link_customer', 'target_phone' => '05321112244']);
        $failed->forceFill(['status' => TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR])->save();

        $retry = $this->actingAs($this->admin())
            ->postJson("/api/technical-service/message-dispatches/{$failed->id}/retry")
            ->assertOk()
            ->json('dispatch');

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $retry['status']);
    }

    public function test_rate_limit_cooldown_blocks_same_recipient_message(): void
    {
        $idempotency = app(TechnicalServiceMessageIdempotencyService::class);
        TechnicalServiceMessageDispatch::query()->create([
            'event' => 'appointment_approved_customer',
            'message_type' => 'appointment_approved_customer',
            'channel' => 'whatsapp',
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'customer',
            'effective_target_phone_hash' => $idempotency->phoneHash('05321112233'),
            'effective_target_phone_mask' => $idempotency->maskPhone('05321112233'),
            'status' => TechnicalServiceMessageDispatch::STATUS_SENT,
            'sent_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        $dispatch = $this->enqueueDispatch([
            'message_type' => 'appointment_approved_customer',
            'channel' => 'whatsapp',
            'provider_key' => 'evo_whatsapp',
            'payload' => ['body' => 'second'],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)->processOne($dispatch->id, noExternal: true);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_COOLDOWN_BLOCKED, $result['status']);
    }

    public function test_whatsapp_and_sms_together_does_not_cooldown_second_channel(): void
    {
        $this->actingAs($this->admin());
        app(TechnicalServiceMessagingSettingsService::class)->update(['nac_sms' => ['enabled' => true]]);

        $idempotency = app(TechnicalServiceMessageIdempotencyService::class);
        TechnicalServiceMessageDispatch::query()->create([
            'event' => 'appointment_approved_customer',
            'message_type' => 'appointment_approved_customer',
            'channel' => 'whatsapp',
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'customer',
            'effective_target_phone_hash' => $idempotency->phoneHash('05321112233'),
            'effective_target_phone_mask' => $idempotency->maskPhone('05321112233'),
            'status' => TechnicalServiceMessageDispatch::STATUS_SENT,
            'sent_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        $smsDispatch = $this->enqueueDispatch([
            'message_type' => 'appointment_approved_customer',
            'channel' => 'sms',
            'provider_key' => 'nac_sms',
            'target_phone' => '05321112233',
            'payload' => ['body' => 'SMS birlikte gönderim gövdesi.'],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)->processOne($smsDispatch->id, noExternal: true);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED, $result['status']);
        $this->assertSame('no_external', $smsDispatch->fresh()->provider_status);
    }

    public function test_rate_limit_queue_paused_blocks_processing(): void
    {
        $this->actingAs($this->admin());
        app(TechnicalServiceMessagingSettingsService::class)->update(['queue_paused' => true]);

        $dispatch = $this->enqueueDispatch();
        $result = app(TechnicalServiceMessageDispatchProcessor::class)->processOne($dispatch->id, noExternal: true);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED, $result['status']);
        $this->assertStringContainsString('duraklatılmış', (string) $result['reason']);
    }

    public function test_provider_router_resolves_null_evo_nac_sms_and_blocks_voibot_without_n8n(): void
    {
        Http::fake();
        $router = app(TechnicalServiceMessageProviderRouter::class);
        $null = $this->enqueueDispatch(['provider_key' => 'null_local']);
        $evo = $this->enqueueDispatch(['provider_key' => 'evo_whatsapp', 'target_phone' => '05321112244', 'recipient_role' => 'test']);

        $this->actingAs($this->admin());
        app(TechnicalServiceMessagingSettingsService::class)->update(['nac_sms' => ['enabled' => true]]);
        $nac = $this->enqueueDispatch(['provider_key' => 'nac_sms', 'target_phone' => '05321112255', 'recipient_role' => 'test']);
        $voibot = $this->enqueueDispatch(['provider_key' => 'voibot_voice', 'target_phone' => '05321112266']);

        $this->assertSame('dry_run', $router->dispatch($null, true)['provider_status']);
        $this->assertSame('no_external', $router->dispatch($evo, true)['provider_status']);
        $this->assertSame('direct_laravel', $router->dispatch($nac, true)['response']['transport']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR, $router->dispatch($voibot, true)['status']);
        Http::assertNothingSent();
    }

    public function test_process_message_dispatches_command_dry_run_no_write_no_provider_call(): void
    {
        Http::fake();
        $dispatch = $this->enqueueDispatch();

        Artisan::call('technical-service:process-message-dispatches', [
            '--dry-run' => true,
            '--dispatch-id' => $dispatch->id,
        ]);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->fresh()->status);
        $this->assertStringContainsString('"dry_run": true', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_process_message_dispatches_command_processes_one_queued_dispatch_with_fake_provider(): void
    {
        $dispatch = $this->enqueueDispatch([
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'test',
        ]);

        Artisan::call('technical-service:process-message-dispatches', [
            '--dispatch-id' => $dispatch->id,
        ]);

        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_TEST_SENT, $dispatch->status);
        $this->assertSame(1, $dispatch->attempt_count);
        $this->assertSame('fake_accepted', $dispatch->provider_status);
        $this->assertSame('evo_whatsapp-fake-'.$dispatch->id, $dispatch->provider_message_id);
    }

    public function test_manual_e2e_worker_enforces_max_seconds_and_outputs_stop_reason(): void
    {
        Http::fake();

        Artisan::call('technical-service:process-message-dispatches', [
            '--worker-loop' => true,
            '--dry-run' => true,
            '--manual-e2e-only' => true,
            '--created-after' => now()->subMinute()->toIso8601String(),
            '--allowlisted-phone' => ['905372081633', '905467647428'],
            '--provider' => 'evo_whatsapp,nac_sms',
            '--max-seconds' => 1,
            '--sleep-seconds' => 1,
            '--stop-after-idle-cycles' => 100,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('manual_e2e_worker_started_at', $output);
        $this->assertStringContainsString('manual_e2e_worker_expires_at', $output);
        $this->assertStringContainsString('"stop_reason": "ttl_expired"', $output);
        Http::assertNothingSent();
    }

    public function test_manual_e2e_worker_requires_created_after(): void
    {
        Http::fake();

        Artisan::call('technical-service:process-message-dispatches', [
            '--worker-loop' => true,
            '--dry-run' => true,
            '--manual-e2e-only' => true,
            '--allowlisted-phone' => ['905372081633'],
            '--provider' => 'evo_whatsapp,nac_sms',
            '--max-seconds' => 1,
            '--sleep-seconds' => 0,
        ]);

        $this->assertStringContainsString('"stop_reason": "created_after_missing"', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_manual_e2e_worker_preview_filters_created_after_manual_metadata_and_allowlist(): void
    {
        Http::fake();
        $boundary = now()->subMinute();

        $old = $this->enqueueDispatch([
            'event' => 'manual_e2e_old',
            'message_type' => 'manual_e2e_old',
            'provider_key' => 'evo_whatsapp',
            'target_phone' => '05372081633',
            'metadata' => ['test_smoke' => true, 'manual_e2e' => true, 'smoke_run_id' => 'MANUAL-E2E-LIVE-TEST'],
            'payload' => ['body' => 'old manual e2e'],
        ]);
        $old->forceFill(['created_at' => now()->subHour(), 'updated_at' => now()->subHour()])->save();

        $nonManual = $this->enqueueDispatch([
            'event' => 'manual_e2e_non_manual',
            'message_type' => 'manual_e2e_non_manual',
            'provider_key' => 'evo_whatsapp',
            'target_phone' => '05372081633',
            'metadata' => ['test_smoke' => true, 'smoke_run_id' => 'MANUAL-E2E-LIVE-TEST'],
            'payload' => ['body' => 'non manual e2e'],
        ]);

        $unsafeTarget = $this->enqueueDispatch([
            'event' => 'manual_e2e_unsafe',
            'message_type' => 'manual_e2e_unsafe',
            'provider_key' => 'nac_sms',
            'target_phone' => '05321112233',
            'metadata' => ['test_smoke' => true, 'manual_e2e' => true, 'smoke_run_id' => 'MANUAL-E2E-LIVE-TEST'],
            'payload' => ['body' => 'unsafe target manual e2e'],
        ]);

        $current = $this->enqueueDispatch([
            'event' => 'manual_e2e_current',
            'message_type' => 'manual_e2e_current',
            'provider_key' => 'nac_sms',
            'target_phone' => '05372081633',
            'metadata' => ['test_smoke' => true, 'manual_e2e' => true, 'smoke_run_id' => 'MANUAL-E2E-LIVE-TEST'],
            'payload' => ['body' => 'current manual e2e'],
        ]);

        Artisan::call('technical-service:process-message-dispatches', [
            '--dry-run' => true,
            '--manual-e2e-only' => true,
            '--created-after' => $boundary->toIso8601String(),
            '--allowlisted-phone' => ['905372081633'],
            '--provider' => 'evo_whatsapp,nac_sms',
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('"count": 1', $output);
        $this->assertStringContainsString('"id": '.$current->id, $output);
        $this->assertStringNotContainsString('"id": '.$old->id, $output);
        $this->assertStringNotContainsString('"id": '.$nonManual->id, $output);
        $this->assertStringNotContainsString('"id": '.$unsafeTarget->id, $output);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $current->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_manual_e2e_worker_requires_real_send_enabled_when_requested(): void
    {
        Http::fake();
        $this->actingAs($this->admin());
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'manual_e2e_enabled' => true,
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
        ]);

        Artisan::call('technical-service:process-message-dispatches', [
            '--worker-loop' => true,
            '--dry-run' => true,
            '--manual-e2e-only' => true,
            '--created-after' => now()->subMinute()->toIso8601String(),
            '--allowlisted-phone' => ['905372081633'],
            '--provider' => 'evo_whatsapp,nac_sms',
            '--require-real-send-enabled' => true,
            '--max-seconds' => 1,
            '--sleep-seconds' => 0,
        ]);

        $this->assertStringContainsString('"stop_reason": "real_send_disabled"', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_manual_e2e_worker_requires_queue_not_paused_when_requested(): void
    {
        Http::fake();
        $this->actingAs($this->admin());
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'queue_paused' => true,
            'manual_e2e_enabled' => true,
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
        ]);

        Artisan::call('technical-service:process-message-dispatches', [
            '--worker-loop' => true,
            '--dry-run' => true,
            '--manual-e2e-only' => true,
            '--created-after' => now()->subMinute()->toIso8601String(),
            '--allowlisted-phone' => ['905372081633'],
            '--provider' => 'evo_whatsapp,nac_sms',
            '--require-queue-not-paused' => true,
            '--max-seconds' => 1,
            '--sleep-seconds' => 0,
        ]);

        $this->assertStringContainsString('"stop_reason": "queue_paused"', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_channel_policy_whatsapp_and_sms_and_fallback_planning(): void
    {
        $planner = app(TechnicalServiceMessageChannelPlanner::class);
        $base = ['message_type' => 'appointment_approved_customer', 'recipient_role' => 'customer'];

        $this->assertCount(1, $planner->plan('whatsapp_only', $base));
        $this->assertSame('sms', $planner->plan('sms_only', $base)[0]['channel']);
        $this->assertCount(2, $planner->plan('whatsapp_and_sms', $base));
        $fallback = $planner->plan('whatsapp_primary_sms_fallback', $base);
        $this->assertCount(1, $fallback);
        $this->assertSame('whatsapp', $fallback[0]['channel']);

        $failedWhatsapp = $this->enqueueDispatch([
            'channel_policy' => 'whatsapp_primary_sms_fallback',
            'channel' => 'whatsapp',
            'provider_key' => 'evo_whatsapp',
        ]);
        $failedWhatsapp->forceFill(['status' => TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR])->save();
        $this->assertSame('sms', $planner->fallbackAfter($failedWhatsapp)['channel']);

        $failedWhatsapp->forceFill(['status' => TechnicalServiceMessageDispatch::STATUS_SENT])->save();
        $this->assertNull($planner->fallbackAfter($failedWhatsapp));
    }

    public function test_force_resend_requires_reason_and_creates_parent_child_relation(): void
    {
        $dispatch = $this->enqueueDispatch(['target_phone' => '05321112277']);

        $this->actingAs($this->admin())
            ->postJson("/api/technical-service/message-dispatches/{$dispatch->id}/force-resend", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $copy = $this->actingAs($this->admin())
            ->postJson("/api/technical-service/message-dispatches/{$dispatch->id}/force-resend", [
                'reason' => 'Operasyon onaylı manuel tekrar',
            ])
            ->assertOk()
            ->json('dispatch');

        $this->assertSame($dispatch->id, $copy['parent_dispatch_id']);
        $this->assertTrue((bool) $copy['force_resend']);
        $this->assertSame('Operasyon onaylı manuel tekrar', $copy['force_resend_reason']);
    }

    public function test_queue_logs_mask_phone_hide_secrets_and_admin_queue_summary_visible(): void
    {
        $dispatch = $this->enqueueDispatch([
            'payload' => ['body' => 'Merhaba', 'password' => 'hidden'],
        ]);

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-dispatches')
            ->assertOk()
            ->json('message_dispatch_queue');

        $this->assertSame(1, $payload['summary']['queued']);
        $this->assertSame('9053***233', $payload['recent'][0]['target_masked']);
        $this->assertStringNotContainsString('hidden', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertSame($dispatch->id, $payload['recent'][0]['id']);
    }

    public function test_queue_logs_use_turkish_labels_and_hide_raw_enum_in_table_payload(): void
    {
        $this->enqueueDispatch([
            'message_type' => 'appointment_updated_technician',
            'channel' => 'sms',
            'provider_key' => 'nac_sms',
            'recipient_role' => 'technician',
            'target_phone' => '05321112288',
        ]);

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-dispatches')
            ->assertOk()
            ->json('message_dispatch_queue.recent.0');

        $this->assertSame('Kuyrukta', $payload['status_label']);
        $this->assertSame('Usta randevu güncelleme', $payload['message_type_label']);
        $this->assertSame('NAC SMS', $payload['provider_label']);
        $this->assertSame('SMS', $payload['channel_label']);
        $this->assertSame('Usta', $payload['recipient_role_label']);
        $this->assertSame('9053***288', $payload['target_masked']);
    }

    public function test_queue_log_filters_by_status_provider_channel_role_message_reference_phone_and_date(): void
    {
        $matching = $this->enqueueDispatch([
            'message_type' => 'appointment_approved_customer',
            'channel' => 'sms',
            'provider_key' => 'nac_sms',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'mrn' => 'MRN-FILTER-001',
        ]);
        $matching->forceFill([
            'created_at' => now('Europe/Istanbul')->setTime(10, 0)->timezone('UTC'),
            'updated_at' => now('Europe/Istanbul')->setTime(10, 0)->timezone('UTC'),
        ])->save();

        $this->enqueueDispatch([
            'message_type' => 'appointment_updated_technician',
            'channel' => 'whatsapp',
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'technician',
            'target_phone' => '05321112299',
            'mrn' => 'MRN-FILTER-OTHER',
        ]);

        $query = http_build_query([
            'status' => [TechnicalServiceMessageDispatch::STATUS_QUEUED],
            'provider' => ['nac_sms'],
            'channel' => ['sms'],
            'recipient_role' => ['customer'],
            'message_type' => ['appointment_approved_customer'],
            'date_from' => now('Europe/Istanbul')->toDateString(),
            'date_to' => now('Europe/Istanbul')->toDateString(),
            'q' => 'MRN-FILTER-001',
            'phone' => '905372081633',
        ]);

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-dispatches?'.$query)
            ->assertOk()
            ->json('message_dispatch_queue');

        $this->assertSame(1, $payload['pagination']['total']);
        $this->assertSame($matching->id, $payload['recent'][0]['id']);
        $this->assertSame('MRN-FILTER-001', $payload['recent'][0]['reference']);
        $this->assertNotNull($payload['recent'][0]['display_time']['human']);
    }

    public function test_queue_log_filters_legacy_payload_provider_channel_and_message_type(): void
    {
        $matching = TechnicalServiceMessageDispatch::query()->create([
            'event' => 'template_test_sms',
            'status' => TechnicalServiceMessageDispatch::STATUS_FAILED,
            'target_phone' => '05372081633',
            'effective_target_phone_mask' => '9053***633',
            'request_payload' => [
                'provider_key' => 'nac_sms',
                'channel' => 'sms',
                'message_type' => 'appointment_approved_customer',
                'body' => 'EMAKS Prime test mesajı',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TechnicalServiceMessageDispatch::query()->create([
            'event' => 'provider_test_whatsapp',
            'status' => TechnicalServiceMessageDispatch::STATUS_FAILED,
            'target_phone' => '05372081634',
            'effective_target_phone_mask' => '9053***634',
            'request_payload' => [
                'provider_key' => 'evo_whatsapp',
                'channel' => 'whatsapp',
                'message_type' => 'provider_test_whatsapp',
                'body' => 'WhatsApp test mesajı',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $query = http_build_query([
            'provider' => ['nac_sms'],
            'channel' => ['sms'],
            'message_type' => ['appointment_approved_customer'],
        ]);

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-dispatches?'.$query)
            ->assertOk()
            ->json('message_dispatch_queue');

        $this->assertSame(1, $payload['pagination']['total']);
        $this->assertSame($matching->id, $payload['recent'][0]['id']);
        $this->assertSame('NAC SMS', $payload['recent'][0]['provider_label']);
        $this->assertSame('SMS', $payload['recent'][0]['channel_label']);
        $this->assertSame('Müşteri randevu onayı', $payload['recent'][0]['message_type_label']);
    }

    public function test_queue_log_search_matches_payload_body_without_short_digit_phone_overmatch(): void
    {
        $matching = TechnicalServiceMessageDispatch::query()->create([
            'event' => 'template_test_sms',
            'status' => TechnicalServiceMessageDispatch::STATUS_FAILED,
            'target_phone' => '05372081633',
            'effective_target_phone_mask' => '9053***633',
            'request_payload' => [
                'provider_key' => 'nac_sms',
                'channel' => 'sms',
                'message_type' => 'appointment_approved_customer',
                'body' => 'EMAKS Prime MRN-REL4C-SEARCH numaralı randevunuz onaylandı.',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TechnicalServiceMessageDispatch::query()->create([
            'event' => 'template_test_sms',
            'status' => TechnicalServiceMessageDispatch::STATUS_FAILED,
            'target_phone' => '05321112244',
            'effective_target_phone_mask' => '9053***244',
            'request_payload' => [
                'provider_key' => 'nac_sms',
                'channel' => 'sms',
                'message_type' => 'appointment_approved_customer',
                'body' => 'EMAKS Prime farklı kayıt.',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-dispatches?q=MRN-REL4C-SEARCH')
            ->assertOk()
            ->json('message_dispatch_queue');

        $this->assertSame(1, $payload['pagination']['total']);
        $this->assertSame($matching->id, $payload['recent'][0]['id']);
    }

    public function test_dispatch_detail_modal_payload_shows_admin_full_phone_message_content_and_redacts_secrets(): void
    {
        $authHeader = 'Author'.'ization';
        $dispatch = $this->enqueueDispatch([
            'message_type' => 'template_test_sms',
            'channel' => 'sms',
            'provider_key' => 'nac_sms',
            'recipient_role' => 'test',
            'target_phone' => '05372081633',
            'payload' => [
                'body' => "EMAKS Prime\nRandevunuz onaylanmıştır.",
                $authHeader => 'Basic very-secret',
            ],
        ]);
        $dispatch->forceFill([
            'status' => TechnicalServiceMessageDispatch::STATUS_TEST_SENT,
            'sent_at' => now(),
            'provider_message_id' => 'PKG-123',
            'provider_response_redacted' => [
                'pkgID' => 'PKG-123',
                $authHeader => 'Basic very-secret',
                'password' => 'secret',
            ],
            'force_resend_reason' => 'Operasyon kontrollü test',
        ])->save();

        $detail = $this->actingAs($this->admin())
            ->getJson("/api/technical-service/message-dispatches/{$dispatch->id}")
            ->assertOk()
            ->json('dispatch');

        $this->assertSame('905372081633', $detail['target_phone_full']);
        $this->assertSame('9053***633', $detail['target_phone_masked']);
        $this->assertSame('SMS şablon testi', $detail['message_type_label']);
        $this->assertSame('Test gönderildi', $detail['status_label']);
        $this->assertStringContainsString('Randevunuz onaylanmıştır.', $detail['rendered_message_content']);
        $this->assertSame('dispatch.body_for_provider', $detail['message_content_source']);
        $this->assertNull($detail['message_content_missing_reason']);
        $this->assertSame('PKG-123', $detail['provider_message_id']);
        $this->assertSame('Operasyon kontrollü test', $detail['force_resend_reason']);
        $this->assertSame('[redacted]', $detail['provider_response_redacted'][$authHeader]);
        $this->assertSame('[redacted]', $detail['provider_response_redacted']['password']);
        $this->assertNotNull($detail['sent_at']['human']);
        $this->assertArrayHasKey('message_type', $detail['technical_keys']);
    }

    public function test_message_body_new_dispatch_stores_rendered_body_and_list_shows_short_message_preview(): void
    {
        $dispatch = $this->enqueueDispatch([
            'rendered_body' => "EMAKS Prime\nMüşteri randevu onayı mesaj gövdesi.",
            'payload' => null,
        ]);

        $this->assertSame(
            "EMAKS Prime\nMüşteri randevu onayı mesaj gövdesi.",
            $dispatch->fresh()->request_payload['body'],
        );
        $this->assertSame(
            "EMAKS Prime\nMüşteri randevu onayı mesaj gövdesi.",
            $dispatch->fresh()->request_payload['rendered_body'],
        );

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-dispatches')
            ->assertOk()
            ->json('message_dispatch_queue.recent.0');

        $this->assertStringContainsString('Müşteri randevu onayı', $payload['message_preview']);
        $this->assertStringNotContainsString("\n", $payload['message_preview']);
    }

    public function test_legacy_dispatch_without_body_shows_clear_missing_content_reason(): void
    {
        $legacy = TechnicalServiceMessageDispatch::query()->create([
            'event' => 'legacy_test',
            'message_type' => 'legacy_test',
            'status' => TechnicalServiceMessageDispatch::STATUS_SENT,
            'target_phone' => '905372081633',
            'effective_target_phone_mask' => '9053***633',
            'request_payload' => [],
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $detail = $this->actingAs($this->admin())
            ->getJson("/api/technical-service/message-dispatches/{$legacy->id}")
            ->assertOk()
            ->json('dispatch');

        $this->assertSame(
            'Bu kayıt eski/test kaydıdır; mesaj içeriği o dönemde saklanmamış.',
            $detail['message_content_missing_reason'],
        );
        $this->assertStringContainsString('eski/test kaydıdır', $detail['rendered_message_content']);
        $this->assertSame('Eski kayıt / sağlayıcı bilgisi yok', $detail['provider_label']);
        $this->assertSame('Eski kayıt / kanal bilgisi yok', $detail['channel_label']);
        $this->assertSame('Eski kayıt / template bilgisi yok', $detail['template_label']);
        $this->assertSame('Eski kayıt / idempotency bilgisi yok', $detail['idempotency_label']);
    }

    public function test_queue_pagination_limits_default_50_and_honors_per_page_cap(): void
    {
        for ($index = 0; $index < 55; $index++) {
            $this->enqueueDispatch([
                'message_type' => 'appointment_approved_customer',
                'target_phone' => '0532111'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'payload' => ['body' => 'Sayfalanacak dispatch '.$index],
            ]);
        }

        $default = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-dispatches')
            ->assertOk()
            ->json('message_dispatch_queue');

        $this->assertCount(50, $default['recent']);
        $this->assertSame(50, $default['pagination']['per_page']);
        $this->assertSame(55, $default['pagination']['total']);

        $limited = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-dispatches?per_page=120')
            ->assertOk()
            ->json('message_dispatch_queue');

        $this->assertSame(100, $limited['pagination']['per_page']);
    }

    public function test_non_admin_detail_masks_full_phone(): void
    {
        $dispatch = $this->enqueueDispatch([
            'target_phone' => '05372081633',
            'payload' => ['body' => 'Admin olmayan kullanıcı tam telefonu görmemeli.'],
        ]);
        $user = User::factory()->create(['role_code' => 'operator']);

        $detail = app(TechnicalServiceMessageDispatchLogService::class)
            ->detail($dispatch->fresh(), $user);

        $this->assertNull($detail['target_phone_full']);
        $this->assertSame('9053***633', $detail['target_phone_masked']);
        $this->assertStringContainsString('tam telefonu görmemeli', $detail['rendered_message_content']);
    }

    public function test_sent_provider_dispatch_attempt_count_incremented_in_log_detail(): void
    {
        $dispatch = $this->enqueueDispatch([
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'test',
        ]);

        Artisan::call('technical-service:process-message-dispatches', [
            '--dispatch-id' => $dispatch->id,
        ]);

        $detail = $this->actingAs($this->admin())
            ->getJson("/api/technical-service/message-dispatches/{$dispatch->id}")
            ->assertOk()
            ->json('dispatch');

        $this->assertSame(1, $detail['attempt_count']);
        $this->assertSame('evo_whatsapp-fake-'.$dispatch->id, $detail['provider_message_id']);
    }

    public function test_queue_log_active_filter_chips_visible_and_clear_filters_control_exists(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-admin.tsx')) ?: '';

        $this->assertStringContainsString('activeQueueFilterChips', $source);
        $this->assertStringContainsString('Filtreleri temizle', $source);
        $this->assertStringContainsString('toggleQueueMultiFilter', $source);
        $this->assertStringNotContainsString('multiple', Str::between($source, 'KUYRUK / LOGLAR', 'Tarih / Saat'));
    }

    public function test_queue_log_refresh_manual_and_auto_controls_preserve_filters_and_modal_state(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-admin.tsx')) ?: '';

        $this->assertStringContainsString('Yenile', $source);
        $this->assertStringContainsString('Otomatik yenile', $source);
        $this->assertStringContainsString('Son yenileme:', $source);
        $this->assertStringContainsString('Güncelleniyor...', $source);
        $this->assertStringContainsString('queueAutoRefreshEnabled', $source);
        $this->assertStringContainsString('queueBackgroundRefreshing', $source);
        $this->assertStringContainsString('queueLastRefreshedAt', $source);
        $this->assertStringContainsString('queueRefreshError', $source);
        $this->assertStringContainsString('AbortController', $source);
        $this->assertStringContainsString('window.setInterval', $source);
        $this->assertStringContainsString("activeAdminSection !== 'queue'", $source);
        $this->assertStringContainsString('loadDispatchQueue(queueFilters, { silent: true })', $source);
        $this->assertStringContainsString('void loadDispatchQueue(queueFilters)', $source);
        $this->assertStringNotContainsString('setSelectedDispatchDetail(null)', Str::between($source, 'window.setInterval', 'const openDispatchDetail'));
    }

    public function test_queue_log_refresh_does_not_call_provider(): void
    {
        Http::fake();
        $this->enqueueDispatch([
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
        ]);

        $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-dispatches?status[]=queued')
            ->assertOk()
            ->assertJsonPath('message_dispatch_queue.pagination.total', 1);

        Http::assertNothingSent();
    }

    public function test_queue_log_list_does_not_load_full_provider_response_or_secret_in_recent_rows(): void
    {
        $dispatch = $this->enqueueDispatch();
        $dispatch->forceFill([
            'provider_response_redacted' => [
                'body' => str_repeat('x', 2000),
                'Basic Auth' => 'secret',
            ],
        ])->save();

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-dispatches')
            ->assertOk()
            ->json('message_dispatch_queue');

        $encoded = json_encode($payload['recent'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Basic Auth', $encoded);
        $this->assertStringNotContainsString(str_repeat('x', 120), $encoded);
    }

    public function test_job_rejected_ops_workflow_message_queues_system_dispatch_without_provider_call(): void
    {
        Http::fake();
        $admin = $this->admin();
        $this->actingAs($admin);
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'test_mode_enabled' => true,
            'shared_test_phone' => '905467647428',
        ]);
        $request = $this->technicalServiceRequest(['mrn' => 'MRN-WF-REJECT']);
        $dispatch = app(TechnicalServiceWorkflowMessageDispatchService::class)->queueSystemMessage(
            $request,
            'job_rejected_ops',
            'ops',
            'Usta işi reddetti. MRN: MRN-WF-REJECT. Neden: Saat uygun değil.',
            ['rejection_reason' => 'Saat uygun değil.', 'next_action_text' => 'OPS yeniden atama yapmalı.'],
            $admin,
            null,
            ['triggered_by' => 'partner_portal_job_rejected'],
        );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
        $this->assertSame('system', $dispatch->channel);
        $this->assertSame('null_local', $dispatch->provider_key);
        $this->assertSame('ops', $dispatch->recipient_role);
        $this->assertSame('905467647428', $dispatch->target_phone);
        $this->assertTrue((bool) $dispatch->test_mode);
        $this->assertTrue((bool) data_get($dispatch->metadata, 'workflow_message_queue_only'));
        $this->assertFalse((bool) data_get($dispatch->metadata, 'external_provider_call'));
        $this->assertStringContainsString('Saat uygun değil', (string) data_get($dispatch->request_payload, 'body'));
        Http::assertNothingSent();
    }

    public function test_queue_log_turkish_labels_cover_workflow_message_events(): void
    {
        foreach ([
            'completion_submitted_ops' => 'Usta işi tamamladı / OPS kontrol',
            'support_request_ops' => 'Destek talebi',
            'job_rejected_ops' => 'Usta işi reddetti',
            'price_revision_requested_ops' => 'Fiyat revizyon talebi',
            'price_revision_response_technician' => 'Usta hakediş revizyon cevabı',
            'part_request_ops' => 'Parça talebi',
            'part_fee_payment_link_customer' => 'Parça ücreti ödeme bağlantısı',
            'appointment_cancelled_customer' => 'Müşteri randevu iptali',
            'appointment_cancelled_technician' => 'Usta randevu iptali',
        ] as $messageType => $label) {
            $this->enqueueDispatch([
                'message_type' => $messageType,
                'event' => $messageType,
                'channel' => 'system',
                'provider_key' => 'null_local',
                'recipient_role' => 'ops',
                'target_phone' => '05321112'.str_pad((string) (30 + count(TechnicalServiceMessageDispatch::all())), 3, '0', STR_PAD_LEFT),
                'payload' => ['body' => $label],
            ]);
        }

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-dispatches')
            ->assertOk()
            ->json('message_dispatch_queue.recent');

        $labels = collect($payload)->pluck('message_type_label', 'message_type');
        $this->assertSame('Usta işi tamamladı / OPS kontrol', $labels['completion_submitted_ops']);
        $this->assertSame('Destek talebi', $labels['support_request_ops']);
        $this->assertSame('Usta işi reddetti', $labels['job_rejected_ops']);
        $this->assertSame('Fiyat revizyon talebi', $labels['price_revision_requested_ops']);
        $this->assertSame('Usta hakediş revizyon cevabı', $labels['price_revision_response_technician']);
        $this->assertSame('Parça talebi', $labels['part_request_ops']);
        $this->assertSame('Parça ücreti ödeme bağlantısı', $labels['part_fee_payment_link_customer']);
        $this->assertSame('Müşteri randevu iptali', $labels['appointment_cancelled_customer']);
        $this->assertSame('Usta randevu iptali', $labels['appointment_cancelled_technician']);
    }

    public function test_workflow_coverage_matrix_lists_known_events_with_queue_or_documented_gap(): void
    {
        $matrix = [
            'appointment_approved' => ['hook' => 'TechnicalServiceAppointmentMessageDispatchService::dispatchApproval', 'queue_only' => true],
            'appointment_updated' => ['hook' => 'TechnicalServiceAppointmentMessageDispatchService::dispatchUpdate', 'queue_only' => true],
            'technician_assignment_saved' => ['hook' => 'assignment_offer_technician queue dispatch only', 'queue_only' => true],
            'job_rejected' => ['hook' => 'job_rejected_ops system dispatch', 'queue_only' => true],
            'completion_submitted' => ['hook' => 'completion_submitted_ops system dispatch', 'queue_only' => true],
            'customer_approval_request' => ['hook' => 'customer_approval_request system dispatch', 'queue_only' => true],
            'earning_hakedis' => ['hook' => 'earnings_message_technician system dispatch', 'queue_only' => true],
            'part_request' => ['hook' => 'part_request_ops label/log coverage; OPS part service event exists', 'queue_only' => true],
            'part_fee_payment_link' => ['hook' => 'payment link creation exists; automatic send remains future gated', 'queue_only' => true],
            'support_request' => ['hook' => 'support_request_ops system dispatch', 'queue_only' => true],
            'price_revision_requested' => ['hook' => 'price_revision_requested_ops system dispatch', 'queue_only' => true],
            'appointment_cancelled' => ['hook' => 'template/log label exists; active automatic dispatch is future gated', 'queue_only' => false],
        ];

        foreach (['appointment_approved', 'appointment_updated', 'job_rejected', 'completion_submitted', 'customer_approval_request', 'earning_hakedis', 'support_request', 'price_revision_requested'] as $event) {
            $this->assertTrue($matrix[$event]['queue_only'], $event.' must be queue-only.');
            $this->assertNotSame('', $matrix[$event]['hook']);
        }

        $this->assertArrayHasKey('appointment_cancelled', $matrix);
        $this->assertFalse($matrix['appointment_cancelled']['queue_only']);
    }

    public function test_modal_flow_existing_actions_have_queue_only_dispatch_or_documented_gap(): void
    {
        Http::fake();
        $admin = $this->admin();
        $request = $this->technicalServiceRequest(['mrn' => 'PR88-REL4E3-MATRIX']);

        foreach ([
            'customer_approval_request' => 'Müşteri onay linki kuyruğa alındı.',
            'earnings_message_technician' => 'Usta hakediş mesajı kuyruğa alındı.',
            'price_revision_response_technician' => 'Hakediş revizyon cevabı kuyruğa alındı.',
        ] as $messageType => $body) {
            app(TechnicalServiceWorkflowMessageDispatchService::class)->queueSystemMessage(
                $request,
                $messageType,
                str_contains($messageType, 'technician') ? 'technician' : 'customer',
                $body,
                ['source' => 'PR88-REL4E3'],
                $admin,
                null,
                [
                    'recipient_phone' => '905372081633',
                    'triggered_by' => 'rel4e3_modal_flow_test',
                    'metadata' => ['test_smoke' => true, 'prefix' => 'PR88-REL4E3'],
                ],
            );
        }

        $this->assertSame(3, TechnicalServiceMessageDispatch::query()
            ->where('triggered_by', 'rel4e3_modal_flow_test')
            ->where('provider_key', 'null_local')
            ->where('channel', 'system')
            ->where('status', TechnicalServiceMessageDispatch::STATUS_QUEUED)
            ->count());
        Http::assertNothingSent();
    }

    public function test_allowlist_real_smoke_blocks_non_allowlisted_phone_before_provider_call(): void
    {
        Http::fake();
        $dispatch = $this->enqueueDispatch([
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05321112233',
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905372081633']);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $result['status']);
        $this->assertSame('allowlist_blocked', $dispatch->fresh()->last_error_code);
        Http::assertNothingSent();
    }

    public function test_provider_router_blocks_manual_e2e_when_real_send_disabled(): void
    {
        Http::fake();
        $this->actingAs($this->admin());
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'manual_e2e_enabled' => true,
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
        ]);

        $dispatch = $this->enqueueDispatch([
            'event' => 'manual_e2e_real_send_guard',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => 'PR88 manual E2E müşteri WhatsApp mesajı.'],
            'metadata' => [
                'test_smoke' => true,
                'manual_e2e' => true,
                'smoke_run_id' => 'MANUAL-E2E-LIVE-TEST',
            ],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905372081633'], options: [
                'manual_e2e_only' => true,
                'smoke_run_id' => 'MANUAL-E2E-LIVE-TEST',
            ]);

        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR, $result['status']);
        $this->assertSame('manual_e2e_guard_blocked', $dispatch->last_error_code);
        $this->assertStringContainsString('Gerçek gönderim kapalı', (string) $dispatch->last_error_message_redacted);
        Http::assertNothingSent();
    }

    public function test_provider_router_blocks_manual_e2e_when_queue_paused(): void
    {
        Http::fake();
        $this->actingAs($this->admin());
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'queue_paused' => true,
            'manual_e2e_enabled' => true,
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
        ]);

        $dispatch = $this->enqueueDispatch([
            'event' => 'manual_e2e_queue_guard',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'nac_sms',
            'channel' => 'sms',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => 'PR88 manual E2E müşteri SMS mesajı.'],
            'metadata' => [
                'test_smoke' => true,
                'manual_e2e' => true,
                'smoke_run_id' => 'MANUAL-E2E-LIVE-TEST',
            ],
        ]);

        $result = app(TechnicalServiceMessageProviderRouter::class)
            ->dispatch($dispatch, noExternal: false, allowlistedPhones: ['905372081633']);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR, $result['status']);
        $this->assertSame('manual_e2e_guard_blocked', $result['provider_status']);
        $this->assertStringContainsString('kuyruğu duraklatılmış', (string) $result['error']);
        Http::assertNothingSent();
    }

    public function test_process_selected_dispatch_requires_dispatch_id_for_allowlisted_external_smoke(): void
    {
        Http::fake();
        $selected = $this->enqueueDispatch([
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'metadata' => ['test_smoke' => true, 'pr88_rel' => 'REL4E5', 'smoke_run_id' => 'PR88-REL4E5-UNIT'],
        ]);

        Artisan::call('technical-service:process-message-dispatches', [
            '--allowlisted-phone' => ['905372081633'],
        ]);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $selected->fresh()->status);
        $this->assertStringContainsString('tekil dispatch-id zorunlu', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_provider_target_payload_evo_uses_dispatch_effective_target_and_dispatch_body_with_allowlist(): void
    {
        $this->configureEvoDirectApi();
        Http::fake([
            'https://evo-api.example.test/message/sendText/evolution_exchange' => Http::response(['messageId' => 'EVO-REL4E5-OK'], 200),
        ]);

        $body = 'REL-4E5 kontrollü WhatsApp smoke mesajı.';
        $dispatch = $this->enqueueDispatch([
            'event' => 'appointment_approved_customer',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => $body],
            'metadata' => ['test_smoke' => true, 'pr88_rel' => 'REL4E5', 'smoke_run_id' => 'PR88-REL4E5-UNIT'],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905372081633'], options: [
                'smoke_run_id' => 'PR88-REL4E5-UNIT',
                'expected_body_token' => 'REL-4E5',
            ]);

        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $result['status']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $dispatch->status);
        $this->assertSame('EVO-REL4E5-OK', $dispatch->provider_message_id);
        $this->assertSame(hash('sha256', $body), data_get($dispatch->provider_response_redacted, 'provider_payload_body_hash'));
        $this->assertTrue((bool) data_get($dispatch->provider_response_redacted, 'provider_payload_body_matches_dispatch'));
        $this->assertSame('appointment_approved_customer', data_get($dispatch->provider_response_redacted, 'provider_request_event'));
        $this->assertSame('appointment_approved_customer', data_get($dispatch->provider_response_redacted, 'provider_request_dispatch_event'));
        $this->assertSame('evolution_direct_api', data_get($dispatch->provider_response_redacted, 'provider_request_transport_event'));
        $this->assertSame('905372081633', data_get($dispatch->provider_response_redacted, 'provider_request_target_phone'));
        $this->assertSame('customer', data_get($dispatch->provider_response_redacted, 'provider_request_target_type'));
        $this->assertSame('customer', data_get($dispatch->provider_response_redacted, 'provider_request_recipient_role'));
        $this->assertStringNotContainsString('evo-secret-key', json_encode($dispatch->provider_response_redacted, JSON_THROW_ON_ERROR));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://evo-api.example.test/message/sendText/evolution_exchange'
            && $request['number'] === '905372081633'
            && $request['text'] === $body
            && $request['linkPreview'] === false);
    }

    public function test_provider_target_payload_evo_uses_technician_effective_target_for_technician_dispatch(): void
    {
        $this->configureEvoDirectApi();
        Http::fake([
            'https://evo-api.example.test/message/sendText/evolution_exchange' => Http::response(['messageId' => 'EVO-REL4E6-TECH-OK'], 200),
        ]);

        $body = "EMAKS Prime Teknik Servis\n\nYeni iş kartı hazır.\n\nRandevu\n03.07.2026 14:00 - 16:00\n\nİş Kartı\nhttps://panel.test/partner/service-jobs?job_id=99\nPR88-REL4E6";
        $dispatch = $this->enqueueDispatch([
            'event' => 'appointment_approved_technician',
            'message_type' => 'appointment_approved_technician',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'technician',
            'target_phone' => '05467647428',
            'payload' => [
                'body' => $body,
                'context' => [
                    'customer_phone' => '905372081633',
                    'technician_phone' => '905467647428',
                ],
            ],
            'metadata' => ['test_smoke' => true, 'pr88_rel' => 'REL4E6', 'smoke_run_id' => 'PR88-REL4E6-UNIT'],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905467647428'], options: [
                'smoke_run_id' => 'PR88-REL4E6-UNIT',
                'expected_body_token' => 'PR88-REL4E6',
                'role_target_phones' => ['technician' => '905467647428'],
            ]);

        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $result['status']);
        $this->assertSame('EVO-REL4E6-TECH-OK', $dispatch->provider_message_id);
        $this->assertSame('appointment_approved_technician', data_get($dispatch->provider_response_redacted, 'provider_request_event'));
        $this->assertSame('appointment_approved_technician', data_get($dispatch->provider_response_redacted, 'provider_request_dispatch_event'));
        $this->assertSame('evolution_direct_api', data_get($dispatch->provider_response_redacted, 'provider_request_transport_event'));
        $this->assertSame('905467647428', data_get($dispatch->provider_response_redacted, 'provider_request_target_phone'));
        $this->assertSame('technician', data_get($dispatch->provider_response_redacted, 'provider_request_target_type'));
        $this->assertSame('technician', data_get($dispatch->provider_response_redacted, 'provider_request_recipient_role'));
        $this->assertStringNotContainsString('evo-secret-key', json_encode($dispatch->provider_response_redacted, JSON_THROW_ON_ERROR));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://evo-api.example.test/message/sendText/evolution_exchange'
            && $request['number'] === '905467647428'
            && $request['text'] === $body);
    }

    public function test_evo_direct_client_posts_to_send_text_endpoint_with_apikey_and_dispatch_payload(): void
    {
        $this->configureEvoDirectApi(['delay' => 4, 'link_preview' => true]);
        Http::fake([
            'https://evo-api.example.test/message/sendText/evolution_exchange' => Http::response(['key' => ['id' => 'EVO-DIRECT-ID']], 200),
        ]);

        $body = "PR88-REL4E8 müşteri WhatsApp\nSatır iki korunmalı.";
        $dispatch = $this->enqueueDispatch([
            'event' => 'appointment_approved_customer',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => $body],
            'metadata' => ['test_smoke' => true, 'pr88_rel' => 'REL4E8', 'smoke_run_id' => 'PR88-REL4E8-DIRECT'],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905372081633'], options: [
                'smoke_run_id' => 'PR88-REL4E8-DIRECT',
                'expected_body_token' => 'PR88-REL4E8',
            ]);

        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $result['status']);
        $this->assertSame('EVO-DIRECT-ID', $dispatch->provider_message_id);
        $this->assertSame('evolution_direct_api', data_get($dispatch->provider_response_redacted, 'transport'));
        $this->assertStringNotContainsString('evo-secret-key', json_encode($dispatch->provider_response_redacted, JSON_THROW_ON_ERROR));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://evo-api.example.test/message/sendText/evolution_exchange'
            && $request->hasHeader('apikey')
            && $request->header('apikey')[0] === 'evo-secret-key'
            && $request['number'] === '905372081633'
            && $request['text'] === $body
            && $request['delay'] === 4
            && $request['linkPreview'] === true);
    }

    public function test_provider_target_blocks_evo_queue_n8n_webhook_when_direct_api_profile_missing(): void
    {
        config(['services.evolution.n8n_webhook_url' => 'https://evo.example.test/send']);
        Http::fake();

        $dispatch = $this->enqueueDispatch([
            'event' => 'appointment_approved_customer',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => 'PR88-REL4E7 müşteri WhatsApp mesajı.'],
            'metadata' => ['test_smoke' => true, 'pr88_rel' => 'REL4E7', 'smoke_run_id' => 'PR88-REL4E7-N8N-BLOCK'],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905372081633'], options: [
                'smoke_run_id' => 'PR88-REL4E7-N8N-BLOCK',
                'expected_body_token' => 'PR88-REL4E7',
            ]);

        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR, $result['status']);
        $this->assertSame('evo_direct_api_missing', $dispatch->last_error_code);
        $this->assertStringContainsString('n8n webhook ile hedefi garanti etmiyor', $dispatch->last_error_message_redacted);
        Http::assertNothingSent();
    }

    public function test_business_dispatch_still_blocked_when_real_send_disabled_without_test_smoke(): void
    {
        config(['services.evolution.n8n_webhook_url' => 'https://evo.example.test/send']);
        Http::fake();

        $dispatch = $this->enqueueDispatch([
            'event' => 'appointment_approved_customer',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => 'Business mesajı smoke metadata olmadan gitmemeli.'],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905372081633']);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $result['status']);
        $this->assertSame('stale_or_invalid_smoke_dispatch', $dispatch->fresh()->last_error_code);
        Http::assertNothingSent();
    }

    public function test_dispatch_body_provider_call_blocked_when_body_contains_required_dash_sentinels(): void
    {
        config(['services.evolution.n8n_webhook_url' => 'https://evo.example.test/send']);
        Http::fake();

        $dispatch = $this->enqueueDispatch([
            'event' => 'appointment_approved_customer',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => "EMAKS Prime Teknik Servis\nSayın -, montaj randevunuz onaylandı.\nRandevu tarihi: -"],
            'metadata' => ['test_smoke' => true, 'pr88_rel' => 'REL4E5', 'smoke_run_id' => 'PR88-REL4E5-DASH'],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905372081633'], options: [
                'smoke_run_id' => 'PR88-REL4E5-DASH',
            ]);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $result['status']);
        $this->assertSame('invalid_dispatch_body', $dispatch->fresh()->last_error_code);
        $this->assertStringContainsString('Sayın -', $dispatch->fresh()->last_error_message_redacted);
        Http::assertNothingSent();
    }

    public function test_role_body_consistency_customer_role_with_technician_body_is_blocked(): void
    {
        config(['services.evolution.n8n_webhook_url' => 'https://evo.example.test/send']);
        Http::fake();

        $dispatch = $this->enqueueDispatch([
            'event' => 'appointment_approved_customer',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => "EMAKS Prime Teknik Servis\nYeni iş kartı hazır.\nİş Kartı\nhttps://panel.test/job/1"],
            'metadata' => ['test_smoke' => true, 'pr88_rel' => 'REL4E6', 'smoke_run_id' => 'PR88-REL4E6-ROLE'],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905372081633'], options: [
                'smoke_run_id' => 'PR88-REL4E6-ROLE',
            ]);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $result['status']);
        $this->assertSame('role_body_mismatch', $dispatch->fresh()->last_error_code);
        Http::assertNothingSent();
    }

    public function test_role_body_consistency_technician_role_with_customer_body_is_blocked(): void
    {
        config(['services.evolution.n8n_webhook_url' => 'https://evo.example.test/send']);
        Http::fake();

        $dispatch = $this->enqueueDispatch([
            'event' => 'appointment_approved_technician',
            'message_type' => 'appointment_approved_technician',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'technician',
            'target_phone' => '05467647428',
            'payload' => ['body' => 'Sayın PR88 REL4E6 Test Müşteri, montaj randevunuz onaylanmıştır.'],
            'metadata' => ['test_smoke' => true, 'pr88_rel' => 'REL4E6', 'smoke_run_id' => 'PR88-REL4E6-ROLE'],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905467647428'], options: [
                'smoke_run_id' => 'PR88-REL4E6-ROLE',
            ]);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $result['status']);
        $this->assertSame('role_body_mismatch', $dispatch->fresh()->last_error_code);
        Http::assertNothingSent();
    }

    public function test_target_parity_selected_smoke_blocks_role_target_mismatch(): void
    {
        config(['services.evolution.n8n_webhook_url' => 'https://evo.example.test/send']);
        Http::fake();

        $dispatch = $this->enqueueDispatch([
            'event' => 'appointment_approved_customer',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05467647428',
            'payload' => ['body' => 'EMAKS Prime PR88-REL4E6 customer body.'],
            'metadata' => [
                'test_smoke' => true,
                'pr88_rel' => 'REL4E6',
                'smoke_run_id' => 'PR88-REL4E6-TARGET',
                'role_target_phone' => '905372081633',
            ],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905372081633', '905467647428'], options: [
                'smoke_run_id' => 'PR88-REL4E6-TARGET',
                'expected_body_token' => 'PR88-REL4E6',
            ]);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $result['status']);
        $this->assertSame('stale_or_invalid_smoke_dispatch', $dispatch->fresh()->last_error_code);
        $this->assertStringContainsString('rol için beklenen allowlist hedefiyle eşleşmiyor', $dispatch->fresh()->last_error_message_redacted);
        Http::assertNothingSent();
    }

    public function test_stale_dispatch_selected_smoke_processing_rejects_stale_rel4c_dispatch(): void
    {
        config(['services.evolution.n8n_webhook_url' => 'https://evo.example.test/send']);
        Http::fake();

        $dispatch = $this->enqueueDispatch([
            'event' => 'appointment_approved_customer',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => 'EMAKS Prime MRN-REL4C-0001 numaralı eski smoke mesajı.'],
            'metadata' => ['test_smoke' => true, 'pr88_rel' => 'REL4E4', 'smoke_run_id' => 'PR88-REL4E4-OLD'],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905372081633'], options: [
                'smoke_run_id' => 'PR88-REL4E5-CURRENT',
                'expected_body_token' => 'PR88-REL4E5',
            ]);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $result['status']);
        $this->assertSame('stale_or_invalid_smoke_dispatch', $dispatch->fresh()->last_error_code);
        $this->assertStringContainsString('stale dispatch', $dispatch->fresh()->last_error_message_redacted);
        Http::assertNothingSent();
    }

    public function test_provider_payload_selected_nac_smoke_uses_direct_laravel_dispatch_body_and_stores_pkgid(): void
    {
        $this->actingAs($this->admin());
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'nac_sms' => [
                'enabled' => true,
                'profile' => 'legacy_working_http_9587',
                'request_shape' => 'legacy_working_minimal',
                'scheme' => 'http',
                'host' => 'smslogin.nac.com.tr',
                'port' => 9587,
                'path' => '/sms/create',
                'sender' => 'EMAKS PRIME',
                'validity' => 60,
            ],
        ]);
        IntegrationProviderCredential::query()->create([
            'scope' => IntegrationProviderCredential::SCOPE_TECHNICAL_SERVICE,
            'provider' => 'nac_sms',
            'profile_key' => IntegrationProviderCredential::PROFILE_DEFAULT,
            'mode' => IntegrationProviderCredential::MODE_LIVE,
            'username_encrypted' => 'nac-user',
            'password_encrypted' => 'nac-pass',
            'credentials_status' => IntegrationProviderCredential::STATUS_CONFIGURED,
        ]);
        Http::fake([
            'http://smslogin.nac.com.tr:9587/sms/create' => Http::response(['err' => null, 'data' => ['pkgID' => 987654]], 200),
        ]);

        $dispatch = $this->enqueueDispatch([
            'event' => 'appointment_approved_customer',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'nac_sms',
            'channel' => 'sms',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => 'EMAKS Prime REL-4E5 randevunuz onaylanmıştır. Aralık: 13:00 - 19:00 arası.'],
            'metadata' => ['test_smoke' => true, 'pr88_rel' => 'REL4E5', 'smoke_run_id' => 'PR88-REL4E5-NAC'],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905372081633'], options: [
                'smoke_run_id' => 'PR88-REL4E5-NAC',
                'expected_body_token' => 'REL-4E5',
            ]);

        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $result['status']);
        $this->assertSame('987654', $dispatch->provider_message_id);
        $this->assertSame('987654', (string) data_get($dispatch->provider_response_redacted, 'pkgID'));
        $this->assertSame(hash('sha256', 'EMAKS Prime REL-4E5 randevunuz onaylanmıştır. Aralık: 13:00 - 19:00 arası.'), data_get($dispatch->provider_response_redacted, 'provider_payload_body_hash'));
        $this->assertTrue((bool) data_get($dispatch->provider_response_redacted, 'provider_payload_body_matches_dispatch'));
        $this->assertSame('905372081633', data_get($dispatch->provider_response_redacted, 'provider_request_target_phone'));
        $this->assertSame('customer', data_get($dispatch->provider_response_redacted, 'provider_request_target_type'));
        $this->assertStringNotContainsString('nac-pass', json_encode($dispatch->provider_response_redacted, JSON_THROW_ON_ERROR));
        Http::assertSent(fn ($request): bool => $request->url() === 'http://smslogin.nac.com.tr:9587/sms/create'
            && $request['number'] === 905372081633
            && $request['content'] === 'EMAKS Prime REL-4E5 randevunuz onaylanmıştır. Aralık: 13:00 - 19:00 arası.'
            && $request['encoding'] === 1);

        $detail = $this->actingAs($this->admin())
            ->getJson("/api/technical-service/message-dispatches/{$dispatch->id}")
            ->assertOk()
            ->json('dispatch');
        $this->assertTrue($detail['provider_payload_body_matches_dispatch']);
        $this->assertSame(data_get($dispatch->provider_response_redacted, 'provider_payload_body_hash'), $detail['provider_payload_body_hash']);
        $this->assertSame('EMAKS Prime REL-4E5 randevunuz onaylanmıştır. Aralık: 13:00 - 19:00 arası.', $detail['provider_request_preview']);
    }

    public function test_queue_processor_processes_only_selected_dispatch_ids(): void
    {
        $selected = $this->enqueueDispatch([
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'test',
            'target_phone' => '05372081633',
        ]);
        $other = $this->enqueueDispatch([
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'test',
            'target_phone' => '05372081634',
        ]);

        Artisan::call('technical-service:process-message-dispatches', [
            '--dispatch-id' => $selected->id,
        ]);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_TEST_SENT, $selected->fresh()->status);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $other->fresh()->status);
    }

    public function test_fallback_sms_dispatch_created_after_whatsapp_provider_failure(): void
    {
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-FALLBACK-SMOKE',
            'scheduled_date' => '2026-07-08',
            'scheduled_time' => '14:00',
        ]);
        $dispatch = $this->enqueueDispatch([
            'request_id' => $request->id,
            'technical_service_request_id' => $request->id,
            'message_type' => 'appointment_approved_customer',
            'channel' => 'whatsapp',
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'customer',
            'channel_policy' => 'whatsapp_primary_sms_fallback',
            'target_phone' => '05372081633',
            'metadata' => ['test_smoke' => true, 'allowlisted_target' => true, 'pr88_rel' => 'REL4E5', 'smoke_run_id' => 'PR88-REL4E5-FALLBACK', 'prefix' => 'PR88-REL4E5'],
            'payload' => [
                'body' => 'WhatsApp primary body',
                'context' => [
                    'appointment_date' => '2026-07-08',
                    'appointment_start_time' => '14:00',
                    'appointment_end_time' => '16:00',
                    'appointment_time_range' => '14:00 - 16:00',
                ],
            ],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)->processOne($dispatch->id);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR, $result['status']);
        $this->assertNotNull($result['fallback_dispatch_id']);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'id' => $result['fallback_dispatch_id'],
            'parent_dispatch_id' => $dispatch->id,
            'channel' => 'sms',
            'provider_key' => 'nac_sms',
            'message_type' => 'appointment_approved_customer',
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
        ]);
        $fallback = TechnicalServiceMessageDispatch::query()->findOrFail($result['fallback_dispatch_id']);
        $this->assertStringContainsString('13:00 - 19:00 arası', (string) data_get($fallback->request_payload, 'body'));
        $this->assertTrue((bool) data_get($fallback->metadata, 'test_smoke'));
        $this->assertSame('REL4E5', data_get($fallback->metadata, 'pr88_rel'));
        $this->assertSame('PR88-REL4E5-FALLBACK', data_get($fallback->metadata, 'smoke_run_id'));
        $this->assertNotSame((string) data_get($dispatch->request_payload, 'body'), (string) data_get($fallback->request_payload, 'body'));
    }

    public function test_part_fee_payment_link_dispatch_if_action_exists_is_future_gated_and_labeled(): void
    {
        Http::fake();
        $dispatch = $this->enqueueDispatch([
            'message_type' => 'part_fee_payment_link_customer',
            'event' => 'part_fee_payment_link_customer',
            'channel' => 'system',
            'provider_key' => 'null_local',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => [
                'body' => 'Parça ücreti ödeme bağlantısı açık aksiyonla kuyruğa alınır; link oluşturmak tek başına canlı mesaj göndermez.',
            ],
        ]);

        $detail = $this->actingAs($this->admin())
            ->getJson("/api/technical-service/message-dispatches/{$dispatch->id}")
            ->assertOk()
            ->json('dispatch');

        $this->assertSame('Parça ücreti ödeme bağlantısı', $detail['message_type_label']);
        $this->assertSame('Null Local', $detail['provider_label']);
        $this->assertStringContainsString('canlı mesaj göndermez', $detail['rendered_message_content']);
        Http::assertNothingSent();
    }

    public function test_cancellation_dispatch_if_action_exists_is_labeled_but_not_auto_triggered(): void
    {
        foreach ([
            'appointment_cancelled_customer' => 'Müşteri randevu iptali',
            'appointment_cancelled_technician' => 'Usta randevu iptali',
        ] as $messageType => $label) {
            $dispatch = $this->enqueueDispatch([
                'message_type' => $messageType,
                'event' => $messageType,
                'channel' => 'system',
                'provider_key' => 'null_local',
                'payload' => ['body' => $label.' future-gated queue audit.'],
            ]);

            $detail = $this->actingAs($this->admin())
                ->getJson("/api/technical-service/message-dispatches/{$dispatch->id}")
                ->assertOk()
                ->json('dispatch');

            $this->assertSame($label, $detail['message_type_label']);
            $this->assertStringContainsString('future-gated', $detail['rendered_message_content']);
        }
    }

    public function test_no_direct_provider_call_from_workflow_message_hooks(): void
    {
        Http::fake();
        $admin = $this->admin();
        $request = $this->technicalServiceRequest(['mrn' => 'MRN-NO-PROVIDER']);

        foreach ([
            'completion_submitted_ops',
            'support_request_ops',
            'job_rejected_ops',
            'price_revision_requested_ops',
        ] as $messageType) {
            app(TechnicalServiceWorkflowMessageDispatchService::class)->queueSystemMessage(
                $request,
                $messageType,
                'ops',
                "Queue-only {$messageType}",
                ['source' => 'test_no_direct_provider'],
                $admin,
                null,
                ['triggered_by' => 'test_no_direct_provider'],
            );
        }

        $this->assertSame(4, TechnicalServiceMessageDispatch::query()->where('provider_key', 'null_local')->count());
        $this->assertSame(4, TechnicalServiceMessageDispatch::query()->where('channel', 'system')->count());
        Http::assertNothingSent();
    }

    public function test_price_revision_requested_ops_workflow_message_queues_system_dispatch(): void
    {
        Http::fake();
        $admin = $this->admin();
        $request = $this->technicalServiceRequest(['mrn' => 'MRN-PRICE-REV']);

        $dispatch = app(TechnicalServiceWorkflowMessageDispatchService::class)->queueSystemMessage(
            $request,
            'price_revision_requested_ops',
            'ops',
            'Usta hakediş revize talebi oluşturdu. MRN: MRN-PRICE-REV.',
            [
                'actor_name' => 'Test Usta',
                'old_amount_formatted' => '1.000,00 TL',
                'requested_amount_formatted' => '1.500,00 TL',
                'revision_reason' => 'Yol bedeli değişti.',
            ],
            $admin,
            null,
            ['triggered_by' => 'partner_portal_price_revision_request'],
        );

        $this->assertSame('price_revision_requested_ops', $dispatch->message_type);
        $this->assertSame('null_local', $dispatch->provider_key);
        $this->assertSame('system', $dispatch->channel);
        $this->assertStringContainsString('revize talebi', (string) data_get($dispatch->request_payload, 'body'));
        Http::assertNothingSent();
    }

    public function test_operation_history_logs_message_status_changes_without_secret(): void
    {
        $request = $this->technicalServiceRequest();
        $dispatch = $this->enqueueDispatch([
            'request_id' => $request->id,
            'technical_service_request_id' => $request->id,
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'test',
            'payload' => ['body' => 'Merhaba', 'password' => 'hidden'],
        ]);

        app(TechnicalServiceMessageDispatchProcessor::class)->processOne($dispatch->id);

        $events = TechnicalServiceRequestEvent::query()
            ->where('technical_service_request_id', $request->id)
            ->pluck('metadata')
            ->map(fn (mixed $metadata): array => is_array($metadata) ? $metadata : [])
            ->all();

        $this->assertNotEmpty($events);
        $this->assertStringNotContainsString('hidden', json_encode($events, JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'message_sent',
        ]);
    }

    public function test_business_dispatch_not_created_by_appointment_approval_yet(): void
    {
        $this->assertSame(0, TechnicalServiceMessageDispatch::query()->where('message_type', 'appointment_approved_customer')->count());
        $this->assertSame(0, TechnicalServiceMessageDispatch::query()->where('recipient_role', 'customer')->count());
    }

    private function enqueueDispatch(array $overrides = []): TechnicalServiceMessageDispatch
    {
        return app(TechnicalServiceMessageDispatchQueue::class)->enqueue([
            'event' => 'rel4d_test_dispatch',
            'message_type' => 'rel4d_test_dispatch',
            'channel' => 'whatsapp',
            'provider_key' => 'null_local',
            'recipient_role' => 'customer',
            'target_phone' => '05321112233',
            'payload' => ['body' => 'REL-4D safe fake message'],
            ...$overrides,
        ], $overrides['actor'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureEvoDirectApi(array $overrides = []): void
    {
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->update([
            'evo_whatsapp' => [
                'direct_api_enabled' => true,
                'direct_api_base_url' => 'https://evo-api.example.test',
                'direct_api_instance_name' => 'evolution_exchange',
                'delay' => 0,
                'link_preview' => false,
                ...$overrides,
            ],
        ]);
        $settings->saveEvoWhatsappCredentials(['api_key' => 'evo-secret-key']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    private function technicalServiceRequest(array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-REL4D-'.Str::upper(Str::random(6)),
            'customer_name' => 'REL4D Müşteri',
            'customer_phone' => '05321112233',
            'customer_city' => 'İstanbul',
            'customer_district' => 'Kadıköy',
            'service_address' => 'Test adresi',
            'product_name' => 'Test Ürün',
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            ...$overrides,
        ]);
    }
}
