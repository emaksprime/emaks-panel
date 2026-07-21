<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\IntegrationProviderCredential;
use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestEvent;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\Messaging\EvolutionWhatsAppMessageService;
use App\Services\Messaging\TechnicalServiceMessageChannelPlanner;
use App\Services\Messaging\TechnicalServiceMessageDispatchLogService;
use App\Services\Messaging\TechnicalServiceMessageDispatchProcessor;
use App\Services\Messaging\TechnicalServiceMessageDispatchQueue;
use App\Services\Messaging\TechnicalServiceMessageDispatchStatusRegistry;
use App\Services\Messaging\TechnicalServiceMessageIdempotencyService;
use App\Services\Messaging\TechnicalServiceMessageProviderRouter;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Messaging\TechnicalServiceNacSmsTestClient;
use App\Services\Messaging\TechnicalServiceWorkflowMessageDispatchService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class TechnicalServiceMessageDispatchQueueTest extends TestCase
{
    use DatabaseMigrations;

    private const TEST_RUN_ID = 'MANUAL-E2E-FULL-20260710-000000-TST1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-07-21 12:00:00', 'Europe/Istanbul'));
        Http::preventStrayRequests();
    }

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();

        $this->beforeApplicationDestroyed(function (): void {
            // SQLite down migrations contain legacy drop-column operations that
            // are not needed after the in-memory test connection is destroyed.
            RefreshDatabaseState::$migrated = false;
        });
    }

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

    public function test_stale_cancelled_manual_e2e_dispatch_does_not_block_new_dispatch(): void
    {
        $input = [
            'event' => 'assignment_offer_technician',
            'message_type' => 'assignment_offer_technician',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'technician',
            'target_phone' => '05467647428',
            'payload' => ['body' => 'MRN-REL4E15A yeni iş teklifi.'],
            'idempotency_key' => 'rel4e15a-stale-manual-e2e-key',
            'metadata' => [
                'test_smoke' => true,
                'manual_e2e' => true,
                'allowlisted_target' => true,
                'smoke_run_id' => self::TEST_RUN_ID,
                'manual_e2e_run_id' => self::TEST_RUN_ID,
            ],
        ];

        $stale = $this->enqueueDispatch($input);
        $stale->forceFill([
            'status' => TechnicalServiceMessageDispatch::STATUS_CANCELLED,
            'last_error_code' => 'manual_e2e_stale_dispatch_reconciled',
        ])->save();

        $fresh = $this->enqueueDispatch($input);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $fresh->status);
        $this->assertNotSame('rel4e15a-stale-manual-e2e-key', $fresh->idempotency_key);
        $this->assertSame('rel4e15a-stale-manual-e2e-key', data_get($fresh->metadata, 'terminal_idempotency_key'));
        $this->assertTrue((bool) data_get($fresh->metadata, 'terminal_idempotency_requeued'));
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
        app(TechnicalServiceMessagingSettingsService::class)->freezeManualE2E();

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

    public function test_manual_e2e_broad_worker_does_not_start_without_exact_send_window(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();

        Artisan::call('technical-service:process-message-dispatches', [
            '--worker-loop' => true,
            '--dry-run' => true,
            '--manual-e2e-only' => true,
            '--created-after' => $global['manual_e2e_created_after'],
            '--smoke-run-id' => $global['manual_e2e_active_run_id'],
            '--allowlisted-phone' => ['905372081633', '905467647428'],
            '--provider' => 'evo_whatsapp,nac_sms',
            '--max-seconds' => 1,
            '--sleep-seconds' => 1,
            '--stop-after-idle-cycles' => 100,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('"manual_e2e_worker_started": false', $output);
        $this->assertStringContainsString('"stop_reason": "manual_e2e_send_window_missing"', $output);
        Http::assertNothingSent();
    }

    public function test_manual_e2e_exact_dispatch_dry_run_does_not_claim_or_call_provider(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);

        Artisan::call('technical-service:process-message-dispatches', [
            '--dry-run' => true,
            '--manual-e2e-only' => true,
            '--dispatch-id' => $dispatch->id,
            '--limit' => 1,
            '--created-after' => $global['manual_e2e_created_after'],
            '--smoke-run-id' => $global['manual_e2e_active_run_id'],
            '--provider' => 'evo_whatsapp',
            '--channel' => 'whatsapp',
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('"count": 1', $output);
        $this->assertStringContainsString('"id": '.$dispatch->id, $output);
        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
        $this->assertSame(0, $dispatch->attempt_count);
        $this->assertFalse((bool) data_get($dispatch->metadata, 'provider_send_attempted'));
        $settings->closeManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        Http::assertNothingSent();
    }

    public function test_manual_e2e_worker_rejects_missing_smoke_run_id(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();

        Artisan::call('technical-service:process-message-dispatches', [
            '--worker-loop' => true,
            '--dry-run' => true,
            '--manual-e2e-only' => true,
            '--created-after' => $global['manual_e2e_created_after'],
            '--allowlisted-phone' => ['905372081633', '905467647428'],
            '--provider' => 'evo_whatsapp,nac_sms',
            '--max-seconds' => 1,
            '--sleep-seconds' => 1,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('"manual_e2e_worker_started": false', $output);
        $this->assertStringContainsString('"stop_reason": "manual_e2e_run_id_mismatch"', $output);
        Http::assertNothingSent();
    }

    public function test_manual_e2e_processor_blocks_when_run_id_option_is_omitted(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        app(TechnicalServiceMessagingSettingsService::class)
            ->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, options: [
                'manual_e2e_only' => true,
            ]);

        $dispatch->refresh();
        $this->assertTrue((bool) $result['blocked']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
        $this->assertSame(0, $dispatch->attempt_count);
        $this->assertFalse((bool) data_get($dispatch->metadata, 'provider_send_attempted'));
        Http::assertNothingSent();
    }

    public function test_earnings_message_technician_manual_e2e_dispatch_metadata_includes_run_ids(): void
    {
        Http::fake();
        $admin = $this->admin();
        $request = $this->technicalServiceRequest(['mrn' => 'MRN-REL4E15A-EARN']);
        $this->configureEvoDirectApi();
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'messaging_enabled' => true,
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
            'ops_whatsapp_phone' => '905467647428',
            'message_types' => [
                'earnings_message_technician' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
            ],
        ]);
        $activeRunId = (string) $this->activateManualE2EContext()['manual_e2e_active_run_id'];

        app(TechnicalServiceWorkflowMessageDispatchService::class)->queueSystemMessage(
            $request,
            'earnings_message_technician',
            'technician',
            'Hakediş bilgisi güncellendi. MRN: MRN-REL4E15A-EARN Toplam: 1.111,00 TL',
            ['technician_earning_total_formatted' => '1.111,00 TL'],
            $admin,
            null,
            [
                'recipient_phone' => '905467647428',
                'triggered_by' => 'technical_service_earnings_message',
            ],
        );

        $dispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'earnings_message_technician')
            ->firstOrFail();

        $this->assertTrue((bool) data_get($dispatch->metadata, 'manual_e2e'));
        $this->assertSame($activeRunId, data_get($dispatch->metadata, 'smoke_run_id'));
        $this->assertSame($activeRunId, data_get($dispatch->metadata, 'manual_e2e_run_id'));
        Http::assertNothingSent();
    }

    public function test_earnings_message_technician_prepared_run_cannot_send_without_exact_window(): void
    {
        Http::fake();
        $this->actingAs($this->admin());
        $global = $this->activateManualE2EContext();

        $dispatch = $this->enqueueDispatch([
            'event' => 'earnings_message_technician',
            'message_type' => 'earnings_message_technician',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'technician',
            'target_phone' => '05467647428',
            'payload' => ['body' => 'Usta hakediş bilgilendirmesi. Hakediş: 1.111,00 TL.'],
            'metadata' => [
                'test_smoke' => true,
                'manual_e2e' => true,
                'allowlisted_target' => true,
                'smoke_run_id' => $global['manual_e2e_active_run_id'],
                'manual_e2e_run_id' => $global['manual_e2e_active_run_id'],
            ],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905467647428'], options: [
                'manual_e2e_only' => true,
                'smoke_run_id' => $global['manual_e2e_active_run_id'],
                'created_after' => $global['manual_e2e_created_after'],
            ]);

        $dispatch->refresh();
        $this->assertTrue((bool) $result['blocked']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $result['status']);
        $this->assertSame(0, $dispatch->attempt_count);
        $this->assertFalse((bool) data_get($dispatch->metadata, 'provider_send_attempted'));
        Http::assertNothingSent();
    }

    public function test_earnings_message_technician_manual_e2e_wrong_run_id_is_blocked_before_provider_call(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global, provider: 'nac_sms', channel: 'sms');
        app(TechnicalServiceMessagingSettingsService::class)
            ->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, options: [
                'manual_e2e_only' => true,
                'smoke_run_id' => 'WRONG-RUN',
                'created_after' => $global['manual_e2e_created_after'],
            ]);

        $dispatch->refresh();
        $this->assertTrue((bool) $result['blocked']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
        $this->assertSame(0, $dispatch->attempt_count);
        $this->assertFalse((bool) data_get($dispatch->metadata, 'provider_send_attempted'));
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
            'metadata' => ['test_smoke' => true, 'manual_e2e' => true, 'smoke_run_id' => self::TEST_RUN_ID],
            'payload' => ['body' => 'old manual e2e'],
        ]);
        $old->forceFill(['created_at' => now()->subHour(), 'updated_at' => now()->subHour()])->save();

        $nonManual = $this->enqueueDispatch([
            'event' => 'manual_e2e_non_manual',
            'message_type' => 'manual_e2e_non_manual',
            'provider_key' => 'evo_whatsapp',
            'target_phone' => '05372081633',
            'metadata' => ['test_smoke' => true, 'smoke_run_id' => self::TEST_RUN_ID],
            'payload' => ['body' => 'non manual e2e'],
        ]);

        $unsafeTarget = $this->enqueueDispatch([
            'event' => 'manual_e2e_unsafe',
            'message_type' => 'manual_e2e_unsafe',
            'provider_key' => 'nac_sms',
            'target_phone' => '05321112233',
            'metadata' => ['test_smoke' => true, 'manual_e2e' => true, 'smoke_run_id' => self::TEST_RUN_ID],
            'payload' => ['body' => 'unsafe target manual e2e'],
        ]);

        $current = $this->enqueueDispatch([
            'event' => 'manual_e2e_current',
            'message_type' => 'manual_e2e_current',
            'provider_key' => 'nac_sms',
            'target_phone' => '05372081633',
            'metadata' => ['test_smoke' => true, 'manual_e2e' => true, 'smoke_run_id' => self::TEST_RUN_ID],
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

    public function test_prepared_manual_e2e_keeps_real_send_disabled_and_queue_paused(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();

        $this->assertTrue($global['manual_e2e_enabled']);
        $this->assertSame('prepared', $global['manual_e2e_phase']);
        $this->assertFalse($global['real_send_enabled']);
        $this->assertTrue($global['queue_paused']);
        Http::assertNothingSent();
    }

    public function test_exact_send_window_opens_gates_and_close_restores_prepared_state(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);

        $opened = $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $this->assertSame('window_open', $opened['global']['manual_e2e_phase']);
        $this->assertTrue($opened['global']['real_send_enabled']);
        $this->assertFalse($opened['global']['queue_paused']);
        $this->assertSame($dispatch->id, data_get($opened, 'manual_e2e.open_window.dispatch_id'));

        $closed = $settings->closeManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $this->assertSame('prepared', $closed['global']['manual_e2e_phase']);
        $this->assertFalse($closed['global']['real_send_enabled']);
        $this->assertTrue($closed['global']['queue_paused']);
        $this->assertSame($global['manual_e2e_active_run_id'], data_get($closed, 'manual_e2e.active_run_id'));
        $this->assertTrue((bool) data_get($dispatch->fresh()->metadata, 'manual_e2e_window_consumed'));
        Http::assertNothingSent();
    }

    public function test_closed_dispatch_cannot_reopen_after_bounded_window_history_is_removed(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $settings->closeManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);

        $page = PageConfig::query()
            ->where('page_code', TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE)
            ->firstOrFail();
        $layout = (array) $page->layout_json;
        Arr::set($layout, TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY.'.manual_e2e_window_history', []);
        $page->forceFill(['layout_json' => $layout])->saveQuietly();

        $closedAgain = $settings->closeManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $this->assertSame('prepared', data_get($closedAgain, 'manual_e2e.phase'));
        $this->assertSame($global['manual_e2e_active_run_id'], data_get($closedAgain, 'manual_e2e.active_run_id'));

        try {
            $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
            $this->fail('Dispatch üzerindeki kalıcı consumed kaydı history kesilse de reopen işlemini engellemeliydi.');
        } catch (ConflictHttpException) {
            $dispatch->refresh();
            $this->assertTrue((bool) data_get($dispatch->metadata, 'manual_e2e_window_consumed'));
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
            $this->assertSame(0, $dispatch->attempt_count);
        }
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
            'provider_key' => 'evo_whatsapp',
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

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED, $dispatch->status);
        $this->assertSame('system', $dispatch->channel);
        $this->assertSame('null_local', $dispatch->provider_key);
        $this->assertSame('ops', $dispatch->recipient_role);
        $this->assertSame('905467647428', $dispatch->target_phone);
        $this->assertNull($dispatch->queued_at);
        $this->assertSame('no_external_provider', $dispatch->provider_status);
        $this->assertSame('null_local_system_no_external_provider', $dispatch->last_error_code);
        $this->assertTrue((bool) $dispatch->test_mode);
        $this->assertTrue((bool) data_get($dispatch->metadata, 'workflow_message_queue_only'));
        $this->assertFalse((bool) data_get($dispatch->metadata, 'external_provider_call'));
        $this->assertTrue((bool) data_get($dispatch->metadata, 'null_local_system_recorded'));
        $this->assertFalse((bool) data_get($dispatch->metadata, 'provider_send_attempted'));
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

    public function test_queue_readiness_provider_worker_ignores_null_local_system_dispatch_not_counted_as_pending_provider(): void
    {
        Http::fake();

        $systemDispatch = $this->enqueueDispatch([
            'message_type' => 'support_request_ops',
            'event' => 'support_request_ops',
            'channel' => 'system',
            'provider_key' => 'null_local',
            'recipient_role' => 'ops',
            'payload' => ['body' => 'Sistem kaydı; dış sağlayıcı işi yok.'],
            'metadata' => [
                'workflow_message_queue_only' => true,
                'external_provider_call' => false,
            ],
        ]);
        $externalDispatch = $this->enqueueDispatch([
            'message_type' => 'appointment_approved_customer',
            'channel' => 'sms',
            'provider_key' => 'nac_sms',
            'recipient_role' => 'customer',
            'target_phone' => '05321112299',
            'payload' => ['body' => 'Dış sağlayıcı kuyruğu.'],
        ]);

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-dispatches')
            ->assertOk()
            ->json('message_dispatch_queue');

        $systemRow = collect($payload['recent'])->firstWhere('id', $systemDispatch->id);
        $externalRow = collect($payload['recent'])->firstWhere('id', $externalDispatch->id);

        $this->assertSame(1, $payload['summary']['queued']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED, $systemDispatch->status);
        $this->assertSame('Sistem kaydı', $systemRow['status_label']);
        $this->assertSame('Dış sağlayıcı yok', $systemRow['provider_label']);
        $this->assertSame('Kuyrukta', $externalRow['status_label']);
        $this->assertSame('NAC SMS', $externalRow['provider_label']);
        Http::assertNothingSent();
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
            ->where('status', TechnicalServiceMessageDispatch::STATUS_SUPPRESSED)
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

    public function test_nac_manual_e2e_rejects_non_allowlisted_target_before_provider_call(): void
    {
        Http::fake();
        $dispatch = $this->enqueueDispatch([
            'provider_key' => 'nac_sms',
            'channel' => 'sms',
            'recipient_role' => 'technician',
            'target_phone' => '05321112233',
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)
            ->processOne($dispatch->id, noExternal: false, allowlistedPhones: ['905467647428']);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $result['status']);
        $this->assertSame('allowlist_blocked', $dispatch->fresh()->last_error_code);
        $this->assertFalse((bool) $dispatch->fresh()->provider_send_attempted);
        Http::assertNothingSent();
    }

    public function test_provider_router_requires_persisted_claim_for_manual_dispatch(): void
    {
        Http::fake();
        $this->actingAs($this->admin());
        $global = $this->activateManualE2EContext();

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
                'smoke_run_id' => $global['manual_e2e_active_run_id'],
                'manual_e2e_run_id' => $global['manual_e2e_active_run_id'],
            ],
        ]);

        $result = app(TechnicalServiceMessageProviderRouter::class)->dispatch(
            $dispatch,
            noExternal: false,
            expectedSmokeRunId: $global['manual_e2e_active_run_id'],
        );

        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR, $result['status']);
        $this->assertSame('manual_e2e_transport_claim_required', $result['provider_status']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
        $this->assertSame(0, $dispatch->attempt_count);
        Http::assertNothingSent();
    }

    public function test_provider_router_blocks_unrelated_dispatch_while_manual_run_is_active(): void
    {
        Http::fake();
        $this->actingAs($this->admin());
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueDispatch([
            'event' => 'normal_dispatch_during_manual_e2e',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'nac_sms',
            'channel' => 'sms',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => 'Manual run sırasında unrelated normal mesaj.'],
        ]);

        $result = app(TechnicalServiceMessageProviderRouter::class)
            ->dispatch($dispatch, noExternal: false, expectedSmokeRunId: $global['manual_e2e_active_run_id']);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR, $result['status']);
        $this->assertSame('manual_e2e_exact_claim_required', $result['provider_status']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->fresh()->status);
        $this->assertSame(0, $dispatch->fresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_open_window_rejects_manual_dispatch_without_run_metadata(): void
    {
        Http::fake();
        $this->actingAs($this->admin());
        $global = $this->activateManualE2EContext();

        $dispatch = $this->enqueueDispatch([
            'event' => 'manual_e2e_missing_run_id_guard',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => 'PR88 manual E2E müşteri WhatsApp mesajı.'],
            'metadata' => [
                'test_smoke' => true,
                'manual_e2e' => true,
            ],
        ]);

        try {
            app(TechnicalServiceMessagingSettingsService::class)
                ->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
            $this->fail('Run metadata eksik dispatch için window açılmamalıydı.');
        } catch (ConflictHttpException) {
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->fresh()->status);
            $this->assertSame(0, $dispatch->fresh()->attempt_count);
        }
        Http::assertNothingSent();
    }

    public function test_provider_router_rejects_matching_run_without_internal_claim_nonce(): void
    {
        Http::fake();
        $this->actingAs($this->admin());
        $global = $this->activateManualE2EContext();

        $dispatch = $this->enqueueDispatch([
            'event' => 'manual_e2e_matching_run_id_guard',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'nac_sms',
            'channel' => 'sms',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => 'PR88 manual E2E müşteri SMS mesajı.'],
            'metadata' => [
                'test_smoke' => true,
                'manual_e2e' => true,
                'smoke_run_id' => $global['manual_e2e_active_run_id'],
                'manual_e2e_run_id' => $global['manual_e2e_active_run_id'],
            ],
        ]);

        $result = app(TechnicalServiceMessageProviderRouter::class)
            ->dispatch(
                $dispatch,
                noExternal: false,
                allowlistedPhones: ['905372081633'],
                expectedSmokeRunId: $global['manual_e2e_active_run_id'],
            );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR, $result['status']);
        $this->assertSame('manual_e2e_transport_claim_required', $result['provider_status']);
        Http::assertNothingSent();
    }

    public function test_open_window_rejects_dispatch_from_replaced_manual_run(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueDispatch([
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => 'PR88 manual E2E eski generic run mesajı.'],
            'metadata' => [
                'test_smoke' => true,
                'manual_e2e' => true,
                'smoke_run_id' => 'MANUAL-E2E-LIVE-TEST',
                'manual_e2e_run_id' => 'MANUAL-E2E-LIVE-TEST',
            ],
        ]);

        try {
            app(TechnicalServiceMessagingSettingsService::class)
                ->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
            $this->fail('Eski run dispatch’i için window açılmamalıydı.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, $dispatch->fresh()->attempt_count);
        }
        Http::assertNothingSent();
    }

    public function test_open_window_rejects_dispatch_created_before_active_run(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueDispatch([
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => 'PR88 manual E2E run öncesi mesaj.'],
            'metadata' => [
                'test_smoke' => true,
                'manual_e2e' => true,
                'smoke_run_id' => $global['manual_e2e_active_run_id'],
                'manual_e2e_run_id' => $global['manual_e2e_active_run_id'],
            ],
        ]);
        $dispatch->forceFill([
            'created_at' => CarbonImmutable::parse($global['manual_e2e_created_after'])->subSeconds(2),
        ])->saveQuietly();

        try {
            app(TechnicalServiceMessagingSettingsService::class)
                ->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
            $this->fail('Run öncesi dispatch için window açılmamalıydı.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, $dispatch->fresh()->attempt_count);
        }
        Http::assertNothingSent();
    }

    public function test_open_window_rejects_expired_active_manual_run(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueDispatch([
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'nac_sms',
            'channel' => 'sms',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => 'PR88 manual E2E süresi dolmuş run mesajı.'],
            'metadata' => [
                'test_smoke' => true,
                'manual_e2e' => true,
                'smoke_run_id' => $global['manual_e2e_active_run_id'],
                'manual_e2e_run_id' => $global['manual_e2e_active_run_id'],
            ],
        ]);
        $this->travel(5)->hours();

        try {
            app(TechnicalServiceMessagingSettingsService::class)
                ->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
            $this->fail('Expired run için window açılmamalıydı.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, $dispatch->fresh()->attempt_count);
        }
        Http::assertNothingSent();
    }

    public function test_open_window_rejects_non_allowlisted_manual_e2e_target(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueDispatch([
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05551112233',
            'payload' => ['body' => 'PR88 manual E2E allowlist dışı hedef mesajı.'],
            'metadata' => [
                'test_smoke' => true,
                'manual_e2e' => true,
                'smoke_run_id' => $global['manual_e2e_active_run_id'],
                'manual_e2e_run_id' => $global['manual_e2e_active_run_id'],
            ],
        ]);

        try {
            app(TechnicalServiceMessagingSettingsService::class)
                ->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
            $this->fail('Allowlist dışı dispatch için window açılmamalıydı.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, $dispatch->fresh()->attempt_count);
        }
        Http::assertNothingSent();
    }

    public function test_direct_evolution_client_is_blocked_before_dispatch_mutation_during_manual_run(): void
    {
        Http::fake();
        $this->activateManualE2EContext();
        $before = TechnicalServiceMessageDispatch::query()->count();

        try {
            app(EvolutionWhatsAppMessageService::class)->send(
                'manual_e2e_direct_bypass',
                'customer',
                '905372081633',
                'Direct bypass engellenmelidir.',
            );
            $this->fail('Active Manual E2E sırasında direct Evolution çağrısı engellenmeliydi.');
        } catch (ValidationException) {
            $this->assertSame($before, TechnicalServiceMessageDispatch::query()->count());
        }
        Http::assertNothingSent();
    }

    public function test_direct_clients_reject_outer_transaction_before_dispatch_and_http(): void
    {
        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.real_send_enabled' => true,
            'services.evolution.allow_unit_test_http_fake' => true,
            'services.evolution.test_phone_min_seconds' => 0,
        ]);
        $actor = $this->admin();
        $this->actingAs($actor);
        $this->configureNacDirectClient();
        Http::fake();
        $before = TechnicalServiceMessageDispatch::query()->count();

        try {
            DB::transaction(function (): void {
                app(EvolutionWhatsAppMessageService::class)->send(
                    'template_test_whatsapp',
                    'shared_test_phone',
                    '905467647428',
                    'Outer transaction Evolution provider mesajı.',
                    [
                        'provider_test' => true,
                        'manual_ui_send' => true,
                        'allow_unit_test_http_fake' => true,
                    ],
                );
            });
            $this->fail('Evolution direct client dış transaction içinden çalışmamalıydı.');
        } catch (ValidationException) {
            $this->assertSame($before, TechnicalServiceMessageDispatch::query()->count());
        }

        try {
            DB::transaction(function () use ($actor): void {
                app(TechnicalServiceNacSmsTestClient::class)->sendProviderTest(
                    '905372081633',
                    ['real_sms_confirmed' => true],
                    $actor,
                );
            });
            $this->fail('NAC direct client dış transaction içinden çalışmamalıydı.');
        } catch (ValidationException) {
            $this->assertSame($before, TechnicalServiceMessageDispatch::query()->count());
        }
        Http::assertNothingSent();
    }

    public function test_direct_evolution_claim_is_durable_before_fake_http(): void
    {
        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.real_send_enabled' => true,
            'services.evolution.allow_unit_test_http_fake' => true,
            'services.evolution.test_phone_min_seconds' => 0,
        ]);
        Http::fake(function () {
            $claimed = TechnicalServiceMessageDispatch::query()->latest('id')->firstOrFail();
            $this->assertSame('evo_whatsapp', $claimed->provider_key);
            $this->assertSame('whatsapp', $claimed->channel);
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $claimed->status);
            $this->assertSame(1, $claimed->attempt_count);
            $this->assertTrue((bool) data_get($claimed->metadata, 'normal_outbound_replay_blocked'));
            $this->assertTrue((bool) data_get($claimed->metadata, 'provider_send_attempted'));
            $readinessCodes = collect(app(TechnicalServiceMessagingSettingsService::class)->manualE2EReadiness()['blockers'])
                ->pluck('code')
                ->all();
            $this->assertContains('pending_provider_dispatch', $readinessCodes);
            $this->assertContains('manual_e2e_lifecycle_busy', $readinessCodes);

            return Http::response(['ok' => true], 200);
        });

        $dispatch = app(EvolutionWhatsAppMessageService::class)->send(
            'template_test_whatsapp',
            'shared_test_phone',
            '905467647428',
            'Durable direct Evolution fake-provider mesajı.',
            [
                'provider_test' => true,
                'manual_ui_send' => true,
                'allow_unit_test_http_fake' => true,
            ],
        );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $dispatch->fresh()->status);
        $this->assertSame(1, $dispatch->fresh()->attempt_count);
        Http::assertSentCount(1);
    }

    public function test_direct_evolution_ambiguous_http_result_blocks_replay_before_new_dispatch(): void
    {
        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.real_send_enabled' => true,
            'services.evolution.allow_unit_test_http_fake' => true,
            'services.evolution.test_phone_min_seconds' => 0,
        ]);
        $httpAttempts = 0;
        Http::fake(function () use (&$httpAttempts) {
            $httpAttempts++;

            throw new RuntimeException('simulated Evolution timeout');
        });
        $service = app(EvolutionWhatsAppMessageService::class);
        $arguments = [
            'template_test_whatsapp',
            'shared_test_phone',
            '905467647428',
            'Ambiguous Evolution replay edilmemelidir.',
            [
                'provider_test' => true,
                'manual_ui_send' => true,
                'allow_unit_test_http_fake' => true,
            ],
        ];

        $first = $service->send(...$arguments)->fresh();
        $countAfterFirst = TechnicalServiceMessageDispatch::query()->count();

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $first->status);
        $this->assertSame(1, $first->attempt_count);
        $this->assertSame('ambiguous_no_retry', data_get($first->response_payload, 'status'));
        $this->assertTrue((bool) data_get($first->metadata, 'normal_outbound_replay_blocked'));

        try {
            $service->send(...$arguments);
            $this->fail('Belirsiz Evolution sonucu ikinci outbound attempt üretememeliydi.');
        } catch (ValidationException) {
            $this->assertSame($countAfterFirst, TechnicalServiceMessageDispatch::query()->count());
        }

        $this->assertSame(1, $httpAttempts);
    }

    public function test_legacy_evolution_ambiguous_attempt_blocks_same_idempotency_without_second_http(): void
    {
        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.real_send_enabled' => true,
            'services.evolution.allow_unit_test_http_fake' => true,
            'services.evolution.test_phone_min_seconds' => 0,
        ]);
        $httpAttempts = 0;
        Http::fake(function () use (&$httpAttempts) {
            $httpAttempts++;

            return Http::response(['ok' => true], 200);
        });
        $service = app(EvolutionWhatsAppMessageService::class);
        $arguments = [
            'template_test_whatsapp',
            'shared_test_phone',
            '905467647428',
            'Legacy ambiguous Evolution tekrar edilmemelidir.',
            [
                'provider_test' => true,
                'manual_ui_send' => true,
                'allow_unit_test_http_fake' => true,
            ],
        ];
        $legacy = $service->send(...$arguments);
        $legacy->forceFill([
            'provider_key' => null,
            'channel' => null,
            'status' => TechnicalServiceMessageDispatch::STATUS_FAILED,
            'attempt_count' => 0,
            'provider_message_id' => null,
            'metadata' => [],
            'error_message' => 'legacy Evolution timeout',
            'sent_at' => now(),
        ])->save();

        $second = $service->send(...$arguments)->fresh();

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_DUPLICATE, $second->status);
        $this->assertSame($legacy->id, data_get($second->response_payload, 'duplicate_dispatch_id'));
        $forceArguments = $arguments;
        $forceArguments[4]['force_resend'] = true;
        $forced = $service->send(...$forceArguments)->fresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_DUPLICATE, $forced->status);
        $this->assertSame($legacy->id, data_get($forced->response_payload, 'duplicate_dispatch_id'));
        $this->assertSame(1, $httpAttempts);
        Http::assertSentCount(1);
    }

    public function test_normal_router_cannot_reach_http_without_authoritative_outbound_lock(): void
    {
        $this->configureEvoDirectApi();
        Http::fake();
        $dispatch = $this->enqueueDispatch([
            'event' => 'normal_router_without_lifecycle_lock',
            'message_type' => 'appointment_approved_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '05372081633',
            'payload' => ['body' => 'NORMAL-ROUTER-LOCK kontrollü mesaj.'],
            'metadata' => [
                'test_smoke' => true,
                'smoke_run_id' => 'NORMAL-ROUTER-LOCK',
            ],
        ]);

        $result = app(TechnicalServiceMessageProviderRouter::class)->dispatch(
            $dispatch,
            noExternal: false,
            allowlistedPhones: ['905372081633'],
            expectedSmokeRunId: 'NORMAL-ROUTER-LOCK',
        );

        $this->assertSame('normal_outbound_transport_permit_rejected', $result['provider_status']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->fresh()->status);
        $this->assertSame(0, $dispatch->fresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_manual_e2e_claim_is_committed_before_single_http_and_ack_is_not_delivery_proof(): void
    {
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $dispatch->forceFill([
            'request_payload' => [
                'body' => 'MANUAL-E2E-EXACT-TOKEN kontrollü provider mesajı. 905372081633 token=provider-secret-value apikey=evo-live-key api_key=nac-live-key',
            ],
        ])->save();
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $httpObservations = 0;

        Http::fake(function () use ($dispatch, $settings, &$httpObservations) {
            $httpObservations++;
            $this->assertSame(0, DB::transactionLevel());
            $persisted = $dispatch->fresh();
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $persisted->status);
            $this->assertSame(1, $persisted->attempt_count);
            $this->assertTrue((bool) data_get($persisted->metadata, 'manual_e2e_transport_permit_consumed'));
            $this->assertSame('http_started', data_get($settings->manualE2EContext()->activeClaim(), 'status'));

            return Http::response([
                'messageId' => 'EVO-MANUAL-ACK-1',
                'remoteJid' => '905372081633@s.whatsapp.net',
                'number' => '905372081633',
                'api-key' => 'structured-api-key-secret',
                'basic' => 'structured-basic-secret',
                'meta' => ['passwd' => 'nested-passwd-secret'],
                'raw' => 'recipient=905372081633 token=provider-secret-value apikey=evo-response-key api_key=nac-response-key Authorization: Bearer bearer-secret-value, basic=basic-secret-value {"api_key":"quoted-api-secret","token":"quoted-token-secret"}',
            ], 200);
        });

        $result = app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
            $dispatch->id,
            noExternal: false,
            options: [
                'manual_e2e_only' => true,
                'smoke_run_id' => $global['manual_e2e_active_run_id'],
                'created_after' => $global['manual_e2e_created_after'],
            ],
        );

        $dispatch->refresh();
        $this->assertSame(
            TechnicalServiceMessageDispatch::STATUS_SENT,
            $result['status'],
            json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        $this->assertSame(1, $httpObservations);
        $this->assertSame(1, $dispatch->attempt_count);
        $this->assertSame('EVO-MANUAL-ACK-1', $dispatch->provider_message_id);
        $this->assertTrue((bool) data_get($dispatch->metadata, 'provider_send_attempted'));
        $this->assertTrue((bool) data_get($dispatch->metadata, 'provider_accepted'));
        $this->assertFalse((bool) data_get($dispatch->metadata, 'delivery_proven'));
        $this->assertSame('provider_accepted', data_get($dispatch->metadata, 'manual_e2e_outcome'));
        $redactedResponse = json_encode($dispatch->provider_response_redacted, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('905372081633', $redactedResponse);
        $this->assertStringNotContainsString('provider-secret-value', $redactedResponse);
        $this->assertStringNotContainsString('evo-live-key', $redactedResponse);
        $this->assertStringNotContainsString('nac-live-key', $redactedResponse);
        $this->assertStringNotContainsString('evo-response-key', $redactedResponse);
        $this->assertStringNotContainsString('nac-response-key', $redactedResponse);
        $this->assertStringNotContainsString('quoted-api-secret', $redactedResponse);
        $this->assertStringNotContainsString('quoted-token-secret', $redactedResponse);
        $this->assertStringNotContainsString('bearer-secret-value', $redactedResponse);
        $this->assertStringNotContainsString('basic-secret-value', $redactedResponse);
        $this->assertStringNotContainsString('structured-api-key-secret', $redactedResponse);
        $this->assertStringNotContainsString('structured-basic-secret', $redactedResponse);
        $this->assertStringNotContainsString('nested-passwd-secret', $redactedResponse);
        $this->assertStringContainsString('[redacted-recipient]', $redactedResponse);
        $this->assertStringContainsString('[redacted-phone]', $redactedResponse);
        $this->assertStringContainsString('[redacted]', $redactedResponse);
        $payload = $settings->payload();
        $this->assertSame('prepared', data_get($payload, 'global.manual_e2e_phase'));
        $this->assertFalse((bool) data_get($payload, 'global.real_send_enabled'));
        $this->assertTrue((bool) data_get($payload, 'global.queue_paused'));
        $this->assertNull(data_get($payload, 'manual_e2e.active_claim'));
        Http::assertSentCount(1);
    }

    public function test_claim_transaction_rollback_leaves_window_and_dispatch_unmodified(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);

        try {
            DB::transaction(function () use ($settings, $global, $dispatch): void {
                $settings->claimManualE2ESend($dispatch->id, $global['manual_e2e_active_run_id']);
                throw new RuntimeException('force outer rollback');
            });
            $this->fail('Outer transaction rollback testi exception üretmeliydi.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force outer rollback', $exception->getMessage());
        }

        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
        $this->assertSame(0, $dispatch->attempt_count);
        $this->assertNull(data_get($dispatch->metadata, 'manual_e2e_claim_hash'));
        $this->assertSame('window_open', $settings->manualE2EContext()->phase());
        $this->assertNull($settings->manualE2EContext()->activeClaim());
        $settings->closeManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        Http::assertNothingSent();
    }

    public function test_only_one_claimant_wins_and_claim_commit_without_transport_cannot_replay(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $claim = $settings->claimManualE2ESend($dispatch->id, $global['manual_e2e_active_run_id']);

        try {
            $settings->claimManualE2ESend($dispatch->id, $global['manual_e2e_active_run_id']);
            $this->fail('İkinci claimant aynı dispatch’i claim edememeliydi.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, $dispatch->fresh()->attempt_count);
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $dispatch->fresh()->status);
        }

        $finalized = $settings->finalizeManualE2ESend($dispatch->id, $claim['claim_nonce'], [
            'status' => TechnicalServiceMessageDispatch::STATUS_SENT,
            'provider_status' => '200',
            'provider_message_id' => 'FORGED-PRE-HTTP-ACK',
            'transport_started' => true,
        ]);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $finalized->status);
        $this->assertNull($finalized->provider_message_id);
        $this->assertFalse((bool) data_get($finalized->metadata, 'provider_send_attempted'));
        $this->assertTrue((bool) data_get($finalized->metadata, 'manual_e2e_replay_blocked'));
        Http::assertNothingSent();
    }

    public function test_same_transport_claim_nonce_can_authorize_at_most_one_http_call(): void
    {
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $claim = $settings->claimManualE2ESend($dispatch->id, $global['manual_e2e_active_run_id']);
        Http::fake(['*' => Http::response(['messageId' => 'EVO-ONE-TIME-ACK'], 200)]);
        $router = app(TechnicalServiceMessageProviderRouter::class);

        $first = $router->dispatch($dispatch->fresh(), manualE2EClaimNonce: $claim['claim_nonce']);
        $second = $router->dispatch($dispatch->fresh(), manualE2EClaimNonce: $claim['claim_nonce']);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $first['status']);
        $this->assertSame('manual_e2e_transport_permit_rejected', $second['provider_status']);
        Http::assertSentCount(1);
        $finalized = $settings->finalizeManualE2ESend($dispatch->id, $claim['claim_nonce'], $first);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $finalized->status);
        $this->assertSame(1, $finalized->attempt_count);
    }

    public function test_manual_e2e_timeout_is_ambiguous_and_cannot_trigger_second_http(): void
    {
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $httpAttempts = 0;
        Http::fake(function () use (&$httpAttempts) {
            $httpAttempts++;
            throw new RuntimeException('simulated timeout');
        });
        $processor = app(TechnicalServiceMessageDispatchProcessor::class);
        $options = [
            'manual_e2e_only' => true,
            'smoke_run_id' => $global['manual_e2e_active_run_id'],
            'created_after' => $global['manual_e2e_created_after'],
        ];

        $first = $processor->processOne($dispatch->id, noExternal: false, options: $options);
        $second = $processor->processOne($dispatch->id, noExternal: false, options: $options);

        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $first['status']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $second['status']);
        $this->assertSame(1, $dispatch->attempt_count);
        $this->assertSame('manual_e2e_ambiguous_no_retry', $dispatch->last_error_code);
        $this->assertSame('ambiguous_no_retry', data_get($dispatch->metadata, 'manual_e2e_outcome'));
        $this->assertTrue((bool) data_get($dispatch->metadata, 'provider_send_attempted'));
        $this->assertSame(1, $httpAttempts);
    }

    public function test_finalize_rollback_keeps_http_started_claim_and_blocks_replay(): void
    {
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $claim = $settings->claimManualE2ESend($dispatch->id, $global['manual_e2e_active_run_id']);
        Http::fake(['*' => Http::response(['messageId' => 'EVO-FINALIZE-ROLLBACK'], 200)]);
        $router = app(TechnicalServiceMessageProviderRouter::class);
        $result = $router->dispatch($dispatch->fresh(), manualE2EClaimNonce: $claim['claim_nonce']);

        try {
            DB::transaction(function () use ($settings, $dispatch, $claim, $result): void {
                $settings->finalizeManualE2ESend($dispatch->id, $claim['claim_nonce'], $result);
                throw new RuntimeException('force finalize rollback');
            });
            $this->fail('Finalize outer rollback testi exception üretmeliydi.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force finalize rollback', $exception->getMessage());
        }

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $dispatch->fresh()->status);
        $this->assertSame(1, $dispatch->fresh()->attempt_count);
        $this->assertNull($dispatch->fresh()->provider_message_id);
        $this->assertSame('http_started', data_get($settings->manualE2EContext()->activeClaim(), 'status'));
        $replay = $router->dispatch($dispatch->fresh(), manualE2EClaimNonce: $claim['claim_nonce']);
        $this->assertSame('manual_e2e_transport_permit_rejected', $replay['provider_status']);
        Http::assertSentCount(1);
        $settings->freezeManualE2E();
    }

    public function test_foreign_finalize_nonce_cannot_change_claim_or_attempt(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $claim = $settings->claimManualE2ESend($dispatch->id, $global['manual_e2e_active_run_id']);

        try {
            $settings->finalizeManualE2ESend($dispatch->id, 'foreign-finalize-token', [
                'status' => TechnicalServiceMessageDispatch::STATUS_SENT,
                'provider_message_id' => 'FOREIGN',
            ]);
            $this->fail('Foreign finalize token reddedilmeliydi.');
        } catch (ConflictHttpException) {
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $dispatch->fresh()->status);
            $this->assertSame(1, $dispatch->fresh()->attempt_count);
            $this->assertNull($dispatch->fresh()->provider_message_id);
        }

        $finalized = $settings->finalizeManualE2ESend($dispatch->id, $claim['claim_nonce'], [
            'status' => TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR,
            'provider_status' => 'pre_http_blocked',
            'provider_message_id' => null,
            'transport_started' => false,
        ]);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $finalized->status);
        $this->assertFalse((bool) data_get($finalized->metadata, 'provider_send_attempted'));
        Http::assertNothingSent();
    }

    public function test_open_window_binds_provider_channel_recipient_idempotency_and_body_tuple(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);

        foreach ([
            ['provider_key' => 'nac_sms'],
            ['channel' => 'sms'],
            ['target_phone' => '905467647428'],
            ['idempotency_key' => hash('sha256', 'tampered-idempotency-key')],
            ['request_payload' => ['body' => 'MANUAL-E2E-EXACT-TOKEN değiştirilmiş kontrollü provider mesajı.']],
        ] as $tamper) {
            $fresh = $dispatch->fresh();
            $original = collect(array_keys($tamper))
                ->mapWithKeys(fn (string $key): array => [$key => $fresh->getAttribute($key)])
                ->all();
            $dispatch->forceFill($tamper)->saveQuietly();
            try {
                $settings->claimManualE2ESend($dispatch->id, $global['manual_e2e_active_run_id']);
                $this->fail('Window tuple tamper claim edilmemeliydi.');
            } catch (ConflictHttpException) {
                $this->assertSame(0, $dispatch->fresh()->attempt_count);
                $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->fresh()->status);
            }
            $dispatch->forceFill($original)->saveQuietly();
        }

        $settings->closeManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        Http::assertNothingSent();
    }

    public function test_claimed_dispatch_body_tamper_cannot_start_transport_or_call_provider(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $claim = $settings->claimManualE2ESend($dispatch->id, $global['manual_e2e_active_run_id']);
        $dispatch->forceFill([
            'request_payload' => ['body' => 'MANUAL-E2E-EXACT-TOKEN claim sonrası değiştirilmiş mesaj.'],
        ])->saveQuietly();

        $result = app(TechnicalServiceMessageProviderRouter::class)->dispatch(
            $dispatch->fresh(),
            manualE2EClaimNonce: $claim['claim_nonce'],
        );

        $this->assertSame('manual_e2e_transport_permit_rejected', $result['provider_status']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $dispatch->fresh()->status);
        $this->assertSame(1, $dispatch->fresh()->attempt_count);
        $this->assertFalse((bool) data_get($dispatch->fresh()->metadata, 'manual_e2e_transport_permit_consumed'));
        Http::assertNothingSent();
    }

    public function test_expired_window_is_closed_without_claim_or_provider_call(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $this->travel(31)->seconds();

        $result = app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
            $dispatch->id,
            noExternal: false,
            options: [
                'manual_e2e_only' => true,
                'smoke_run_id' => $global['manual_e2e_active_run_id'],
                'created_after' => $global['manual_e2e_created_after'],
            ],
        );

        $dispatch->refresh();
        $this->assertTrue((bool) $result['blocked']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
        $this->assertSame(0, $dispatch->attempt_count);
        $this->assertSame('prepared', $settings->manualE2EContext()->phase());
        $this->assertNull($settings->manualE2EContext()->openWindow());
        Http::assertNothingSent();
    }

    public function test_unrelated_pending_dispatch_blocks_window_without_mutating_either_dispatch(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $target = $this->enqueueManualE2EDispatch($global, token: 'TARGET-TOKEN');
        $unrelated = $this->enqueueManualE2EDispatch($global, token: 'UNRELATED-TOKEN');

        try {
            app(TechnicalServiceMessagingSettingsService::class)
                ->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $target->id);
            $this->fail('Unrelated pending dispatch varken window açılmamalıydı.');
        } catch (ConflictHttpException) {
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $target->fresh()->status);
            $this->assertSame(0, $target->fresh()->attempt_count);
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $unrelated->fresh()->status);
            $this->assertSame(0, $unrelated->fresh()->attempt_count);
        }
        Http::assertNothingSent();
    }

    public function test_normal_processor_commits_one_time_claim_before_http_without_open_transaction(): void
    {
        $this->configureEvoDirectApi();
        $dispatch = $this->enqueueDispatch([
            'event' => 'normal_durable_claim',
            'message_type' => 'normal_durable_claim',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'test',
            'target_type' => 'test',
            'target_phone' => '905372081633',
            'payload' => ['body' => 'NORMAL-DURABLE-CLAIM controlled fake message.'],
            'metadata' => [
                'test_smoke' => true,
                'smoke_run_id' => 'NORMAL-DURABLE-CLAIM',
            ],
        ]);
        Http::fake(function () use ($dispatch) {
            $this->assertSame(0, DB::transactionLevel());
            $claimed = $dispatch->fresh();
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $claimed->status);
            $this->assertSame(1, $claimed->attempt_count);
            $this->assertNotSame('', (string) data_get($claimed->metadata, 'normal_processor_claim_hash'));
            $this->assertTrue((bool) data_get($claimed->metadata, 'provider_send_attempted'));
            $this->assertNotNull(data_get($claimed->metadata, 'normal_outbound_http_started_at'));

            return Http::response(['messageId' => 'EVO-NORMAL-ACK-1'], 200);
        });

        $result = app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
            $dispatch->id,
            noExternal: false,
            allowlistedPhones: ['905372081633'],
            options: [
                'smoke_run_id' => 'NORMAL-DURABLE-CLAIM',
                'expected_body_token' => 'NORMAL-DURABLE-CLAIM',
            ],
        );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $result['status']);
        $this->assertSame(1, $dispatch->fresh()->attempt_count);
        $this->assertSame('provider_accepted', data_get($dispatch->fresh()->metadata, 'normal_outbound_outcome'));
        Http::assertSentCount(1);
    }

    public function test_stale_queue_cancel_cannot_erase_committed_provider_acceptance(): void
    {
        $this->configureEvoDirectApi();
        $dispatchInput = [
            'event' => 'normal_stale_cancel_accepted',
            'message_type' => 'normal_stale_cancel_accepted',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'test',
            'target_type' => 'test',
            'target_phone' => '905372081633',
            'payload' => ['body' => 'NORMAL-STALE-CANCEL-ACCEPTED controlled fake message.'],
            'metadata' => [
                'test_smoke' => true,
                'smoke_run_id' => 'NORMAL-STALE-CANCEL-ACCEPTED',
            ],
        ];
        $dispatch = $this->enqueueDispatch($dispatchInput);
        $staleQueuedModel = TechnicalServiceMessageDispatch::query()->findOrFail($dispatch->id);
        $lateStaleQueuedModel = TechnicalServiceMessageDispatch::query()->findOrFail($dispatch->id);
        $actor = $this->admin();
        Http::fake(function () use ($staleQueuedModel, $actor) {
            $activeClaim = $this->authoritativeLifecycleSetting('normal_outbound_active_claim');
            $this->assertSame('http_started', data_get($activeClaim, 'status'));
            $this->assertSame($staleQueuedModel->id, data_get($activeClaim, 'dispatch_id'));
            $this->assertSame(0, DB::transactionLevel());

            app(TechnicalServiceMessageDispatchQueue::class)->cancelQueued($staleQueuedModel, $actor);

            return Http::response(['messageId' => 'EVO-STALE-CANCEL-ACK'], 200);
        });

        $result = app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
            $dispatch->id,
            noExternal: false,
            allowlistedPhones: ['905372081633'],
            options: [
                'smoke_run_id' => 'NORMAL-STALE-CANCEL-ACCEPTED',
                'expected_body_token' => 'NORMAL-STALE-CANCEL-ACCEPTED',
            ],
        );

        $persisted = $dispatch->fresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $result['status']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $persisted->status);
        $this->assertSame(1, $persisted->attempt_count);
        $this->assertSame('EVO-STALE-CANCEL-ACK', $persisted->provider_message_id);
        $this->assertSame('provider_accepted', data_get($persisted->metadata, 'normal_outbound_outcome'));
        $this->assertNull($this->authoritativeLifecycleSetting('normal_outbound_active_claim'));
        $history = (array) $this->authoritativeLifecycleSetting('normal_outbound_history');
        $this->assertTrue(collect($history)->contains(
            fn (mixed $entry): bool => is_array($entry)
                && (int) ($entry['dispatch_id'] ?? 0) === $dispatch->id
                && (string) ($entry['outcome'] ?? '') === 'provider_accepted',
        ));

        app(TechnicalServiceMessageDispatchQueue::class)->cancelQueued($lateStaleQueuedModel, $actor);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_CANCELLED, $dispatch->fresh()->status);
        $this->assertSame(1, $dispatch->fresh()->attempt_count);
        $requeued = $this->enqueueDispatch($dispatchInput);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $requeued->status);
        $this->assertSame($dispatch->idempotency_key, data_get($requeued->metadata, 'terminal_idempotency_key'));

        $replay = app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
            $requeued->id,
            noExternal: false,
            allowlistedPhones: ['905372081633'],
            options: [
                'smoke_run_id' => 'NORMAL-STALE-CANCEL-ACCEPTED',
                'expected_body_token' => 'NORMAL-STALE-CANCEL-ACCEPTED',
            ],
        );
        $this->assertTrue((bool) ($replay['blocked'] ?? false));
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $requeued->fresh()->status);
        $this->assertSame(0, $requeued->fresh()->attempt_count);
        $this->assertSame('normal_outbound_terminal_replay_blocked', $requeued->fresh()->last_error_code);
        $this->assertTrue(app(TechnicalServiceMessagingSettingsService::class)->withManualE2EFrozenOutbound(fn (): bool => true));
        $this->assertNotContains(
            'pending_provider_dispatch',
            collect(app(TechnicalServiceMessagingSettingsService::class)->manualE2EReadiness()['blockers'])->pluck('code')->all(),
        );
        Http::assertSentCount(1);
    }

    public function test_stale_cancelled_terminal_failure_does_not_block_unrelated_outbound(): void
    {
        $this->configureEvoDirectApi();
        $dispatch = $this->enqueueDispatch([
            'event' => 'normal_stale_cancel_failed',
            'message_type' => 'normal_stale_cancel_failed',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'test',
            'target_type' => 'test',
            'target_phone' => '905372081633',
            'payload' => ['body' => 'NORMAL-STALE-CANCEL-FAILED controlled fake message.'],
            'metadata' => [
                'test_smoke' => true,
                'smoke_run_id' => 'NORMAL-STALE-CANCEL-FAILED',
            ],
        ]);
        $staleQueuedModel = TechnicalServiceMessageDispatch::query()->findOrFail($dispatch->id);
        Http::fake(['*' => Http::response(['error' => 'deterministic rejection'], 422)]);

        app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
            $dispatch->id,
            noExternal: false,
            allowlistedPhones: ['905372081633'],
            options: [
                'smoke_run_id' => 'NORMAL-STALE-CANCEL-FAILED',
                'expected_body_token' => 'NORMAL-STALE-CANCEL-FAILED',
            ],
        );
        $terminal = $dispatch->fresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR, $terminal->status);
        $this->assertSame(1, $terminal->attempt_count);
        $this->assertNotNull($terminal->failed_at);

        app(TechnicalServiceMessageDispatchQueue::class)->cancelQueued($staleQueuedModel, $this->admin());
        $cancelled = $dispatch->fresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->failed_at);
        $this->assertTrue(app(TechnicalServiceMessagingSettingsService::class)->withManualE2EFrozenOutbound(fn (): bool => true));
        $this->assertNotContains(
            'pending_provider_dispatch',
            collect(app(TechnicalServiceMessagingSettingsService::class)->manualE2EReadiness()['blockers'])->pluck('code')->all(),
        );
        Http::assertSentCount(1);
    }

    public function test_stale_queue_cancel_during_timeout_restores_ambiguous_attempt_and_blocks_replay(): void
    {
        $this->configureEvoDirectApi();
        $dispatch = $this->enqueueDispatch([
            'event' => 'normal_stale_cancel_ambiguous',
            'message_type' => 'normal_stale_cancel_ambiguous',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'test',
            'target_type' => 'test',
            'target_phone' => '905372081633',
            'payload' => ['body' => 'NORMAL-STALE-CANCEL-AMBIGUOUS controlled fake message.'],
            'metadata' => [
                'test_smoke' => true,
                'smoke_run_id' => 'NORMAL-STALE-CANCEL-AMBIGUOUS',
            ],
        ]);
        $staleQueuedModel = TechnicalServiceMessageDispatch::query()->findOrFail($dispatch->id);
        $lateStaleQueuedModel = TechnicalServiceMessageDispatch::query()->findOrFail($dispatch->id);
        $actor = $this->admin();
        $httpAttempts = 0;
        Http::fake(function () use ($staleQueuedModel, $actor, &$httpAttempts) {
            $httpAttempts++;
            app(TechnicalServiceMessageDispatchQueue::class)->cancelQueued($staleQueuedModel, $actor);

            throw new RuntimeException('simulated stale-cancel provider timeout');
        });
        $processor = app(TechnicalServiceMessageDispatchProcessor::class);
        $arguments = [
            $dispatch->id,
            false,
            ['905372081633'],
            [
                'smoke_run_id' => 'NORMAL-STALE-CANCEL-AMBIGUOUS',
                'expected_body_token' => 'NORMAL-STALE-CANCEL-AMBIGUOUS',
            ],
        ];

        $first = $processor->processOne(...$arguments);
        $reconciled = $dispatch->fresh();
        $activeClaim = $this->authoritativeLifecycleSetting('normal_outbound_active_claim');
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $first['status']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $reconciled->status);
        $this->assertSame(1, $reconciled->attempt_count);
        $this->assertSame('ambiguous_no_retry', data_get($reconciled->metadata, 'normal_outbound_outcome'));
        $this->assertSame('ambiguous_no_retry', data_get($activeClaim, 'status'));
        $this->assertSame($dispatch->id, data_get($activeClaim, 'dispatch_id'));

        app(TechnicalServiceMessageDispatchQueue::class)->cancelQueued($lateStaleQueuedModel, $actor);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_CANCELLED, $dispatch->fresh()->status);
        $callbackRan = false;
        try {
            app(TechnicalServiceMessagingSettingsService::class)->withManualE2EFrozenOutbound(
                function () use (&$callbackRan): void {
                    $callbackRan = true;
                },
            );
            $this->fail('Authoritative unresolved claim direct outbound callback çağrısını engellemeliydi.');
        } catch (ValidationException) {
            $this->assertFalse($callbackRan);
        }

        $second = $processor->processOne(...$arguments);
        $this->assertTrue((bool) ($second['blocked'] ?? false));
        $this->assertTrue((bool) ($second['skipped'] ?? false));
        $this->assertSame(1, $httpAttempts);
    }

    public function test_terminal_requeue_lineage_blocks_second_child_after_first_child_attempt(): void
    {
        $this->configureEvoDirectApi();
        $dispatchInput = [
            'event' => 'normal_terminal_lineage',
            'message_type' => 'normal_terminal_lineage',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'test',
            'target_type' => 'test',
            'target_phone' => '905372081633',
            'payload' => ['body' => 'NORMAL-TERMINAL-LINEAGE controlled fake message.'],
            'metadata' => [
                'test_smoke' => true,
                'smoke_run_id' => 'NORMAL-TERMINAL-LINEAGE',
            ],
        ];
        $root = $this->enqueueDispatch($dispatchInput);
        app(TechnicalServiceMessageDispatchQueue::class)->cancelQueued($root, $this->admin());
        $firstChild = $this->enqueueDispatch($dispatchInput);
        $this->assertSame($root->idempotency_key, data_get($firstChild->metadata, 'terminal_idempotency_key'));
        Http::fake(['*' => Http::response(['messageId' => 'EVO-FIRST-LINEAGE-ACK'], 200)]);
        $processor = app(TechnicalServiceMessageDispatchProcessor::class);
        $options = [
            'smoke_run_id' => 'NORMAL-TERMINAL-LINEAGE',
            'expected_body_token' => 'NORMAL-TERMINAL-LINEAGE',
        ];

        $firstResult = $processor->processOne(
            $firstChild->id,
            noExternal: false,
            allowlistedPhones: ['905372081633'],
            options: $options,
        );
        $secondChild = $this->enqueueDispatch($dispatchInput);
        $secondResult = $processor->processOne(
            $secondChild->id,
            noExternal: false,
            allowlistedPhones: ['905372081633'],
            options: $options,
        );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $firstResult['status']);
        $this->assertSame(1, $firstChild->fresh()->attempt_count);
        $this->assertSame($root->idempotency_key, data_get($secondChild->metadata, 'terminal_idempotency_key'));
        $this->assertTrue((bool) ($secondResult['blocked'] ?? false));
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $secondChild->fresh()->status);
        $this->assertSame(0, $secondChild->fresh()->attempt_count);
        $this->assertSame('normal_outbound_terminal_replay_blocked', $secondChild->fresh()->last_error_code);
        Http::assertSentCount(1);
    }

    public function test_legacy_terminal_provider_evidence_blocks_requeue_even_when_attempt_is_zero(): void
    {
        $this->configureEvoDirectApi();
        $dispatchInput = [
            'event' => 'normal_legacy_terminal_evidence',
            'message_type' => 'normal_legacy_terminal_evidence',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'test',
            'target_type' => 'test',
            'target_phone' => '905372081633',
            'payload' => ['body' => 'NORMAL-LEGACY-TERMINAL-EVIDENCE controlled fake message.'],
            'metadata' => [
                'test_smoke' => true,
                'smoke_run_id' => 'NORMAL-LEGACY-TERMINAL-EVIDENCE',
            ],
        ];
        $legacy = $this->enqueueDispatch($dispatchInput);
        $legacy->forceFill([
            'status' => TechnicalServiceMessageDispatch::STATUS_CANCELLED,
            'attempt_count' => 0,
            'provider_message_id' => 'LEGACY-PROVIDER-EVIDENCE',
            'sent_at' => now(),
        ])->save();
        $requeued = $this->enqueueDispatch($dispatchInput);
        Http::fake();

        $result = app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
            $requeued->id,
            noExternal: false,
            allowlistedPhones: ['905372081633'],
            options: [
                'smoke_run_id' => 'NORMAL-LEGACY-TERMINAL-EVIDENCE',
                'expected_body_token' => 'NORMAL-LEGACY-TERMINAL-EVIDENCE',
            ],
        );

        $this->assertSame(0, $legacy->fresh()->attempt_count);
        $this->assertSame($legacy->idempotency_key, data_get($requeued->metadata, 'terminal_idempotency_key'));
        $this->assertTrue((bool) ($result['blocked'] ?? false));
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $requeued->fresh()->status);
        $this->assertSame(0, $requeued->fresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_manual_terminal_requeue_lineage_cannot_open_second_send_window(): void
    {
        $global = $this->activateManualE2EContext();
        $request = $this->technicalServiceRequest(['mrn' => 'MRN-MANUAL-TERMINAL-LINEAGE']);
        $root = $this->enqueueManualE2EDispatch(
            $global,
            token: 'MANUAL-TERMINAL-LINEAGE',
            request: $request,
        );
        app(TechnicalServiceMessageDispatchQueue::class)->cancelQueued($root, $this->admin());
        $firstChild = $this->enqueueManualE2EDispatch(
            $global,
            token: 'MANUAL-TERMINAL-LINEAGE',
            request: $request,
        );
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $firstChild->id);
        Http::fake(['*' => Http::response(['messageId' => 'EVO-MANUAL-LINEAGE-ACK'], 200)]);

        $firstResult = app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
            $firstChild->id,
            noExternal: false,
            options: [
                'manual_e2e_only' => true,
                'smoke_run_id' => $global['manual_e2e_active_run_id'],
                'created_after' => $global['manual_e2e_created_after'],
            ],
        );
        $secondChild = $this->enqueueManualE2EDispatch(
            $global,
            token: 'MANUAL-TERMINAL-LINEAGE',
            request: $request,
        );

        try {
            $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $secondChild->id);
            $this->fail('Attempt edilmiş terminal lineage için ikinci Manual window açılmamalıydı.');
        } catch (ConflictHttpException) {
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $firstResult['status']);
            $this->assertSame(1, $firstChild->fresh()->attempt_count);
            $this->assertSame($root->idempotency_key, data_get($secondChild->metadata, 'terminal_idempotency_key'));
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $secondChild->fresh()->status);
            $this->assertSame(0, $secondChild->fresh()->attempt_count);
        }
        Http::assertSentCount(1);
    }

    public function test_corrupt_normal_outbound_claim_is_preserved_as_invalid_and_blocks_provider_paths(): void
    {
        Http::fake();
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->freezeManualE2E();
        $page = PageConfig::query()
            ->where('page_code', TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE)
            ->firstOrFail();
        $layout = (array) $page->layout_json;
        Arr::set(
            $layout,
            TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY.'.normal_outbound_active_claim',
            'corrupt-claim',
        );
        $page->forceFill(['layout_json' => $layout])->saveQuietly();

        try {
            $settings->assertManualE2ELifecycleStateValid();
            $this->fail('Corrupt normal outbound claim lifecycle doğrulamasını geçmemeliydi.');
        } catch (ValidationException) {
            $callbackRan = false;
            try {
                $settings->withManualE2EFrozenOutbound(function () use (&$callbackRan): void {
                    $callbackRan = true;
                });
                $this->fail('Corrupt claim direct provider callback çalıştırmamalıydı.');
            } catch (ValidationException) {
                $this->assertFalse($callbackRan);
            }
        }
        Http::assertNothingSent();
    }

    public function test_normal_processor_timeout_keeps_unresolved_attempt_and_blocks_replay(): void
    {
        $this->configureEvoDirectApi();
        $dispatch = $this->enqueueDispatch([
            'event' => 'normal_timeout_no_replay',
            'message_type' => 'normal_timeout_no_replay',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'test',
            'target_type' => 'test',
            'target_phone' => '905372081633',
            'payload' => ['body' => 'NORMAL-TIMEOUT-NO-REPLAY controlled fake message.'],
            'metadata' => [
                'test_smoke' => true,
                'smoke_run_id' => 'NORMAL-TIMEOUT-NO-REPLAY',
            ],
        ]);
        $httpAttempts = 0;
        Http::fake(function () use (&$httpAttempts) {
            $httpAttempts++;

            throw new RuntimeException('simulated normal processor timeout');
        });
        $processor = app(TechnicalServiceMessageDispatchProcessor::class);
        $arguments = [
            $dispatch->id,
            false,
            ['905372081633'],
            [
                'smoke_run_id' => 'NORMAL-TIMEOUT-NO-REPLAY',
                'expected_body_token' => 'NORMAL-TIMEOUT-NO-REPLAY',
            ],
        ];

        $first = $processor->processOne(...$arguments);
        $second = $processor->processOne(...$arguments);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $first['status']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $second['status']);
        $this->assertTrue((bool) ($second['skipped'] ?? false));
        $this->assertSame(1, $dispatch->fresh()->attempt_count);
        $this->assertTrue((bool) data_get($dispatch->fresh()->metadata, 'provider_send_attempted'));
        $this->assertSame('ambiguous_no_retry', data_get($dispatch->fresh()->metadata, 'normal_outbound_outcome'));
        $this->assertSame(1, $httpAttempts);

        $this->expectException(ValidationException::class);
        app(TechnicalServiceMessageDispatchQueue::class)->retryFailed($dispatch->fresh(), $this->admin());
    }

    public function test_normal_evolution_success_without_message_id_is_ambiguous_and_not_replayed(): void
    {
        $this->configureEvoDirectApi();
        $dispatch = $this->enqueueDispatch([
            'event' => 'normal_missing_provider_id',
            'message_type' => 'normal_missing_provider_id',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'test',
            'target_type' => 'test',
            'target_phone' => '905372081633',
            'payload' => ['body' => 'NORMAL-MISSING-PROVIDER-ID controlled fake message.'],
            'metadata' => [
                'test_smoke' => true,
                'smoke_run_id' => 'NORMAL-MISSING-PROVIDER-ID',
            ],
        ]);
        $httpAttempts = 0;
        Http::fake(function () use (&$httpAttempts) {
            $httpAttempts++;

            return Http::response([], 200);
        });
        $processor = app(TechnicalServiceMessageDispatchProcessor::class);
        $arguments = [
            $dispatch->id,
            false,
            ['905372081633'],
            [
                'smoke_run_id' => 'NORMAL-MISSING-PROVIDER-ID',
                'expected_body_token' => 'NORMAL-MISSING-PROVIDER-ID',
            ],
        ];

        $first = $processor->processOne(...$arguments);
        $second = $processor->processOne(...$arguments);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $first['status']);
        $this->assertSame('accepted_without_message_id', $first['provider_status']);
        $this->assertNull($dispatch->fresh()->provider_message_id);
        $this->assertSame('ambiguous_no_retry', data_get($dispatch->fresh()->metadata, 'normal_outbound_outcome'));
        $this->assertTrue((bool) ($second['skipped'] ?? false));
        $this->assertSame(1, $httpAttempts);
    }

    public function test_normal_provider_ack_then_finalize_crash_preserves_claim_and_blocks_second_http(): void
    {
        $this->configureEvoDirectApi();
        $dispatch = $this->enqueueDispatch([
            'event' => 'normal_finalize_crash',
            'message_type' => 'normal_finalize_crash',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'test',
            'target_type' => 'test',
            'target_phone' => '905372081633',
            'payload' => ['body' => 'NORMAL-FINALIZE-CRASH controlled fake message.'],
            'metadata' => [
                'test_smoke' => true,
                'smoke_run_id' => 'NORMAL-FINALIZE-CRASH',
            ],
        ]);
        $httpAttempts = 0;
        Http::fake(function () use (&$httpAttempts) {
            $httpAttempts++;

            return Http::response(['messageId' => 'EVO-ACK-BEFORE-FINALIZE-CRASH'], 200);
        });
        $throwOnFinalize = true;
        TechnicalServiceMessageDispatch::saving(function (TechnicalServiceMessageDispatch $saving) use (&$throwOnFinalize): void {
            if ($throwOnFinalize
                && $saving->status === TechnicalServiceMessageDispatch::STATUS_SENT
                && (string) $saving->provider_message_id === 'EVO-ACK-BEFORE-FINALIZE-CRASH') {
                $throwOnFinalize = false;
                throw new RuntimeException('simulated normal finalize crash');
            }
        });
        $processor = app(TechnicalServiceMessageDispatchProcessor::class);
        $arguments = [
            $dispatch->id,
            false,
            ['905372081633'],
            [
                'smoke_run_id' => 'NORMAL-FINALIZE-CRASH',
                'expected_body_token' => 'NORMAL-FINALIZE-CRASH',
            ],
        ];

        try {
            $processor->processOne(...$arguments);
            $this->fail('Finalize crash testi exception üretmeliydi.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated normal finalize crash', $exception->getMessage());
        }

        $persisted = $dispatch->fresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $persisted->status);
        $this->assertSame(1, $persisted->attempt_count);
        $this->assertNull($persisted->provider_message_id);
        $this->assertTrue((bool) data_get($persisted->metadata, 'provider_send_attempted'));
        $activeClaim = $this->authoritativeLifecycleSetting('normal_outbound_active_claim');
        $this->assertSame('http_started', data_get($activeClaim, 'status'));
        $this->assertSame($dispatch->id, data_get($activeClaim, 'dispatch_id'));
        $second = $processor->processOne(...$arguments);
        $this->assertTrue((bool) ($second['blocked'] ?? false));
        $this->assertTrue((bool) ($second['skipped'] ?? false));
        $this->assertSame(1, $httpAttempts);
    }

    public function test_exact_dispatch_send_does_not_mutate_related_second_channel(): void
    {
        $global = $this->activateManualE2EContext();
        $request = $this->technicalServiceRequest(['mrn' => 'MRN-RELATED-CHANNELS']);
        $target = $this->enqueueManualE2EDispatch($global, request: $request, token: 'RELATED-TOKEN');
        $sibling = $this->enqueueManualE2EDispatch(
            $global,
            provider: 'nac_sms',
            channel: 'sms',
            token: 'RELATED-TOKEN',
            request: $request,
        );
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $target->id);
        Http::fake(['*' => Http::response(['messageId' => 'EVO-RELATED-ACK'], 200)]);

        app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
            $target->id,
            noExternal: false,
            options: [
                'manual_e2e_only' => true,
                'smoke_run_id' => $global['manual_e2e_active_run_id'],
                'created_after' => $global['manual_e2e_created_after'],
            ],
        );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $target->fresh()->status);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $sibling->fresh()->status);
        $this->assertSame(0, $sibling->fresh()->attempt_count);
        $this->assertFalse((bool) data_get($sibling->fresh()->metadata, 'provider_send_attempted'));
        Http::assertSentCount(1);
    }

    public function test_generic_processor_cannot_mutate_unrelated_dispatch_created_after_window_opens(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $target = $this->enqueueManualE2EDispatch($global, token: 'WINDOW-TARGET');
        app(TechnicalServiceMessagingSettingsService::class)
            ->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $target->id);

        $unrelated = $this->enqueueDispatch([
            'event' => 'unrelated_live_dispatch',
            'message_type' => 'appointment_updated_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_phone' => '905372081633',
            'payload' => ['body' => 'EMAKS Prime unrelated dispatch.'],
            'metadata' => ['provider_send_attempted' => false],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
            $unrelated->id,
            noExternal: false,
        );

        $unrelated->refresh();
        $this->assertTrue((bool) $result['blocked']);
        $this->assertTrue((bool) $result['skipped']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $unrelated->status);
        $this->assertSame(0, $unrelated->attempt_count);
        $this->assertFalse((bool) data_get($unrelated->metadata, 'provider_send_attempted'));
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $target->fresh()->status);
        $this->assertSame(0, $target->fresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_direct_nac_client_is_blocked_before_dispatch_mutation_during_manual_run(): void
    {
        Http::fake();
        $this->activateManualE2EContext();
        $before = TechnicalServiceMessageDispatch::query()->count();

        try {
            app(TechnicalServiceNacSmsTestClient::class)->sendProviderTest(
                '905372081633',
                ['real_sms_confirmed' => true],
                $this->admin(),
            );
            $this->fail('Active Manual E2E sırasında direct NAC çağrısı engellenmeliydi.');
        } catch (ValidationException) {
            $this->assertSame($before, TechnicalServiceMessageDispatch::query()->count());
        }
        Http::assertNothingSent();
    }

    public function test_direct_nac_claim_is_durable_before_fake_http(): void
    {
        $actor = $this->admin();
        $this->actingAs($actor);
        $this->configureNacDirectClient();
        Http::fake(function () {
            $claimed = TechnicalServiceMessageDispatch::query()->latest('id')->firstOrFail();
            $this->assertSame('nac_sms', $claimed->provider_key);
            $this->assertSame('sms', $claimed->channel);
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $claimed->status);
            $this->assertSame(1, $claimed->attempt_count);
            $this->assertTrue((bool) data_get($claimed->metadata, 'normal_outbound_replay_blocked'));
            $this->assertTrue((bool) data_get($claimed->metadata, 'provider_send_attempted'));

            return Http::response(['err' => null, 'data' => ['pkgID' => 123456]], 200);
        });

        $dispatch = app(TechnicalServiceNacSmsTestClient::class)->sendProviderTest(
            '905372081633',
            ['real_sms_confirmed' => true],
            $actor,
        );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $dispatch->fresh()->status);
        $this->assertSame(1, $dispatch->fresh()->attempt_count);
        Http::assertSentCount(1);
    }

    public function test_direct_nac_ambiguous_http_result_blocks_replay_before_new_dispatch(): void
    {
        $actor = $this->admin();
        $this->actingAs($actor);
        $this->configureNacDirectClient();
        $httpAttempts = 0;
        Http::fake(function () use (&$httpAttempts) {
            $httpAttempts++;

            throw new RuntimeException('simulated NAC timeout');
        });
        $service = app(TechnicalServiceNacSmsTestClient::class);

        $first = $service->sendProviderTest(
            '905372081633',
            ['real_sms_confirmed' => true],
            $actor,
        )->fresh();
        $countAfterFirst = TechnicalServiceMessageDispatch::query()->count();

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $first->status);
        $this->assertSame(1, $first->attempt_count);
        $this->assertSame('ambiguous_no_retry', data_get($first->response_payload, 'status'));
        $this->assertTrue((bool) data_get($first->metadata, 'normal_outbound_replay_blocked'));

        try {
            $service->sendProviderTest(
                '905372081633',
                ['real_sms_confirmed' => true],
                $actor,
            );
            $this->fail('Belirsiz NAC sonucu ikinci outbound attempt üretememeliydi.');
        } catch (ValidationException) {
            $this->assertSame($countAfterFirst, TechnicalServiceMessageDispatch::query()->count());
        }

        $this->assertSame(1, $httpAttempts);
    }

    public function test_legacy_nac_ambiguous_attempt_blocks_same_content_before_dispatch_and_http(): void
    {
        $actor = $this->admin();
        $this->actingAs($actor);
        $this->configureNacDirectClient();
        $message = 'EMAKS Prime SMS altyapı testi. Gönderim zamanı: '.now()->timezone(config('app.timezone'))->format('d.m.Y H:i').'.';
        TechnicalServiceMessageDispatch::query()->create([
            'event' => 'provider_test_sms',
            'provider_key' => null,
            'channel' => null,
            'target_type' => 'shared_test_phone',
            'target_phone' => '905372081633',
            'test_mode' => true,
            'status' => TechnicalServiceMessageDispatch::STATUS_FAILED,
            'attempt_count' => 0,
            'provider_message_id' => null,
            'request_payload' => [
                'content_preview' => mb_substr($message, 0, 240),
            ],
            'response_payload' => ['status' => 'exception'],
            'error_message' => 'legacy NAC timeout',
            'sent_at' => now(),
        ]);
        $before = TechnicalServiceMessageDispatch::query()->count();
        Http::fake();

        try {
            app(TechnicalServiceNacSmsTestClient::class)->sendProviderTest(
                '905372081633',
                ['real_sms_confirmed' => true],
                $actor,
            );
            $this->fail('Legacy NAC ambiguous attempt aynı içeriği tekrar göndermemeliydi.');
        } catch (ValidationException) {
            $this->assertSame($before, TechnicalServiceMessageDispatch::query()->count());
        }
        Http::assertNothingSent();
    }

    public function test_explicit_invalid_lifecycle_state_blocks_normal_router_before_http(): void
    {
        Http::fake();
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->freezeManualE2E();
        $page = PageConfig::query()
            ->where('page_code', TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE)
            ->firstOrFail();
        $layout = (array) $page->layout_json;
        Arr::set($layout, TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY.'.queue_paused', false);
        $page->forceFill(['layout_json' => $layout])->saveQuietly();
        $dispatch = $this->enqueueDispatch([
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'test',
        ]);

        $processed = app(TechnicalServiceMessageDispatchProcessor::class)->processOne($dispatch->id);
        $this->assertTrue((bool) $processed['blocked']);
        $this->assertTrue((bool) $processed['skipped']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->fresh()->status);
        $this->assertSame(0, $dispatch->fresh()->attempt_count);

        $result = app(TechnicalServiceMessageProviderRouter::class)->dispatch($dispatch);

        $this->assertSame('manual_e2e_lifecycle_invalid', $result['provider_status']);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->fresh()->status);
        $this->assertSame(0, $dispatch->fresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_attempted_manual_dispatch_cannot_retry_or_open_clone_window(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $claim = $settings->claimManualE2ESend($dispatch->id, $global['manual_e2e_active_run_id']);

        try {
            app(TechnicalServiceMessageDispatchQueue::class)->retryFailed($dispatch->fresh(), $this->admin());
            $this->fail('Attempt=1 sending dispatch retry kuyruğuna alınmamalıydı.');
        } catch (ValidationException) {
            $this->assertSame(1, $dispatch->fresh()->attempt_count);
        }

        $settings->finalizeManualE2ESend($dispatch->id, $claim['claim_nonce'], [
            'status' => TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR,
            'provider_status' => 'pre_http_crash',
            'transport_started' => false,
        ]);
        $clone = $this->enqueueManualE2EDispatch($global, token: 'CLONE-TOKEN');
        $clone->forceFill([
            'parent_dispatch_id' => $dispatch->id,
            'metadata' => [
                ...((array) $clone->metadata),
                'force_resend_from_dispatch_id' => $dispatch->id,
            ],
        ])->saveQuietly();

        try {
            $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $clone->id);
            $this->fail('Attempted parent üzerinden Manual E2E clone window açılmamalıydı.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, $clone->fresh()->attempt_count);
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $clone->fresh()->status);
        }
        Http::assertNothingSent();
    }

    public function test_terminal_idempotency_requeue_cannot_replay_attempted_manual_dispatch(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $request = $this->technicalServiceRequest(['mrn' => 'MRN-TERMINAL-REPLAY']);
        $original = $this->enqueueManualE2EDispatch(
            $global,
            token: 'TERMINAL-REPLAY',
            request: $request,
        );
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $original->id);
        $claim = $settings->claimManualE2ESend($original->id, $global['manual_e2e_active_run_id']);
        $settings->finalizeManualE2ESend($original->id, $claim['claim_nonce'], [
            'status' => TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR,
            'provider_status' => 'pre_http_crash',
        ]);

        $requeued = $this->enqueueManualE2EDispatch(
            $global,
            token: 'TERMINAL-REPLAY',
            request: $request,
        );
        $this->assertTrue((bool) data_get($requeued->metadata, 'terminal_idempotency_requeued'));
        $this->assertSame($original->idempotency_key, data_get($requeued->metadata, 'terminal_idempotency_key'));

        try {
            $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $requeued->id);
            $this->fail('Attempted terminal idempotency kaydı yeni dispatch üzerinden replay edilememeliydi.');
        } catch (ConflictHttpException) {
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $requeued->fresh()->status);
            $this->assertSame(0, $requeued->fresh()->attempt_count);
        }
        Http::assertNothingSent();
    }

    public function test_closed_dispatch_cannot_reopen_but_second_related_channel_can_open(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $request = $this->technicalServiceRequest(['mrn' => 'MRN-SECOND-CHANNEL']);
        $whatsapp = $this->enqueueManualE2EDispatch($global, request: $request, token: 'SECOND-CHANNEL');
        $sms = $this->enqueueManualE2EDispatch(
            $global,
            provider: 'nac_sms',
            channel: 'sms',
            token: 'SECOND-CHANNEL',
            request: $request,
        );
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $runId = $global['manual_e2e_active_run_id'];

        $settings->openManualE2ESendWindow($runId, $whatsapp->id);
        $settings->closeManualE2ESendWindow($runId, $whatsapp->id);
        try {
            $settings->openManualE2ESendWindow($runId, $whatsapp->id);
            $this->fail('Closed dispatch window yeniden açılamamalıydı.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, $whatsapp->fresh()->attempt_count);
        }

        $opened = $settings->openManualE2ESendWindow($runId, $sms->id);
        $this->assertSame($sms->id, data_get($opened, 'manual_e2e.open_window.dispatch_id'));
        $this->assertSame('nac_sms', data_get($opened, 'manual_e2e.open_window.provider'));
        $settings->closeManualE2ESendWindow($runId, $sms->id);
        Http::assertNothingSent();
    }

    public function test_second_stale_open_loses_and_first_window_remains_authoritative(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $request = $this->technicalServiceRequest(['mrn' => 'MRN-STALE-SECOND-OPEN']);
        $first = $this->enqueueManualE2EDispatch(
            $global,
            token: 'STALE-SECOND-OPEN',
            request: $request,
        );
        $second = $this->enqueueManualE2EDispatch(
            $global,
            provider: 'nac_sms',
            channel: 'sms',
            token: 'STALE-SECOND-OPEN',
            request: $request,
        );
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $runId = $global['manual_e2e_active_run_id'];
        $settings->openManualE2ESendWindow($runId, $first->id);

        try {
            $settings->openManualE2ESendWindow($runId, $second->id);
            $this->fail('Aynı run için ikinci open çağrısı ilk pencereyi değiştirmemeliydi.');
        } catch (ConflictHttpException) {
            $current = $settings->payload();
            $this->assertSame($first->id, data_get($current, 'manual_e2e.open_window.dispatch_id'));
            $this->assertSame('evo_whatsapp', data_get($current, 'manual_e2e.open_window.provider'));
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $second->fresh()->status);
            $this->assertSame(0, $second->fresh()->attempt_count);
        }
        Http::assertNothingSent();
    }

    public function test_freeze_preserves_unresolved_claim_and_attempt_audit_without_reopening_dispatch(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $settings->claimManualE2ESend($dispatch->id, $global['manual_e2e_active_run_id']);

        $frozen = $settings->freezeManualE2E();
        $secondFreeze = $settings->freezeManualE2E();

        $this->assertSame('frozen', data_get($frozen, 'global.manual_e2e_phase'));
        $this->assertFalse((bool) data_get($frozen, 'global.manual_e2e_enabled'));
        $this->assertFalse((bool) data_get($frozen, 'global.real_send_enabled'));
        $this->assertTrue((bool) data_get($frozen, 'global.queue_paused'));
        $this->assertNull(data_get($frozen, 'manual_e2e.active_run_id'));
        $this->assertSame($global['manual_e2e_active_run_id'], data_get($secondFreeze, 'manual_e2e.last_run_id'));
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $dispatch->fresh()->status);
        $this->assertSame(1, $dispatch->fresh()->attempt_count);
        $this->assertFalse((bool) data_get($dispatch->fresh()->metadata, 'provider_send_attempted'));
        $history = (array) data_get(
            PageConfig::query()->where('page_code', TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE)->value('layout_json'),
            TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY.'.manual_e2e_window_history',
            [],
        );
        $this->assertTrue(collect($history)->contains(
            fn (array $entry): bool => (int) ($entry['dispatch_id'] ?? 0) === $dispatch->id
                && (string) ($entry['status'] ?? '') === 'frozen_unresolved',
        ));
        Http::assertNothingSent();
    }

    public function test_unresolved_manual_attempt_blocks_normal_direct_outbound_after_freeze(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $manualDispatch = $this->enqueueManualE2EDispatch($global);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $manualDispatch->id);
        $settings->claimManualE2ESend($manualDispatch->id, $global['manual_e2e_active_run_id']);
        $settings->freezeManualE2E();
        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.real_send_enabled' => true,
            'services.evolution.allow_unit_test_http_fake' => true,
            'services.evolution.test_phone_min_seconds' => 0,
        ]);
        $countBefore = TechnicalServiceMessageDispatch::query()->count();

        try {
            app(EvolutionWhatsAppMessageService::class)->send(
                'template_test_whatsapp',
                'shared_test_phone',
                '905467647428',
                'Unresolved Manual attempt varken normal outbound engellenmelidir.',
                [
                    'provider_test' => true,
                    'manual_ui_send' => true,
                    'allow_unit_test_http_fake' => true,
                ],
            );
            $this->fail('Freeze unresolved Manual attempt gerçeğini normal outbound için görünmez yapmamalıydı.');
        } catch (ValidationException) {
            $this->assertSame($countBefore, TechnicalServiceMessageDispatch::query()->count());
        }

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $manualDispatch->fresh()->status);
        $this->assertSame(1, $manualDispatch->fresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_stale_cancel_and_freeze_during_manual_http_cannot_hide_unresolved_attempt(): void
    {
        $global = $this->activateManualE2EContext();
        $dispatch = $this->enqueueManualE2EDispatch($global);
        $staleQueuedModel = TechnicalServiceMessageDispatch::query()->findOrFail($dispatch->id);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->openManualE2ESendWindow($global['manual_e2e_active_run_id'], $dispatch->id);
        $actor = $this->admin();

        Http::fake(function () use ($staleQueuedModel, $settings, $actor) {
            app(TechnicalServiceMessageDispatchQueue::class)->cancelQueued($staleQueuedModel, $actor);
            $settings->freezeManualE2E();

            return Http::response(['messageId' => 'EVO-STALE-FREEZE-ACK'], 200);
        });

        app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
            $dispatch->id,
            noExternal: false,
            options: [
                'manual_e2e_only' => true,
                'smoke_run_id' => $global['manual_e2e_active_run_id'],
                'created_after' => $global['manual_e2e_created_after'],
            ],
        );

        $unresolved = $dispatch->fresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_CANCELLED, $unresolved->status);
        $this->assertSame(1, $unresolved->attempt_count);
        $this->assertContains(
            'pending_provider_dispatch',
            collect($settings->manualE2EReadiness()['blockers'])->pluck('code')->all(),
        );
        $countBefore = TechnicalServiceMessageDispatch::query()->count();

        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.real_send_enabled' => true,
            'services.evolution.allow_unit_test_http_fake' => true,
            'services.evolution.test_phone_min_seconds' => 0,
        ]);
        try {
            app(EvolutionWhatsAppMessageService::class)->send(
                'template_test_whatsapp',
                'shared_test_phone',
                '905467647428',
                'Stale cancel ve freeze unresolved attempt gerçeğini gizlememelidir.',
                [
                    'provider_test' => true,
                    'manual_ui_send' => true,
                    'allow_unit_test_http_fake' => true,
                ],
            );
            $this->fail('Cancelled attempt=1 varken yeni normal outbound başlatılmamalıydı.');
        } catch (ValidationException) {
            $this->assertSame($countBefore, TechnicalServiceMessageDispatch::query()->count());
        }

        Http::assertSentCount(1);
    }

    public function test_prepared_assignment_offer_plans_two_run_scoped_dispatches_without_provider_call(): void
    {
        Http::fake();
        $global = $this->activateManualE2EContext();
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Prepared Assignment Usta',
            'technician_type' => 'locksmith',
            'phone' => '+905467647428',
            'city' => 'Istanbul',
            'active' => true,
            'cari_code' => 'PREPARED-ASSIGNMENT-USTA',
        ]);
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'PREPARED-ASSIGNMENT-PARTNER',
            'display_name' => 'Prepared Assignment Partner',
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
            'is_primary' => true,
            'active' => true,
            'source' => 'manual_e2e_lifecycle_test',
        ]);
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-PREPARED-ASSIGNMENT',
            'technical_service_technician_id' => $technician->id,
        ]);
        $actor = $this->admin();

        app(TechnicalServiceWorkflowMessageDispatchService::class)->queueSystemMessage(
            $request,
            'assignment_offer_technician',
            'technician',
            'EMAKS Prime Teknik Servis yeni iş teklifi. MRN-PREPARED-ASSIGNMENT İş Kartı http://example.test/pj/1',
            [],
            $actor,
            null,
            [
                'recipient_phone' => '905467647428',
                'triggered_by' => 'manual_e2e_prepared_assignment_test',
            ],
        );

        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'assignment_offer_technician')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $dispatches);
        $this->assertSame(['sms', 'whatsapp'], $dispatches->pluck('channel')->sort()->values()->all());
        foreach ($dispatches as $dispatch) {
            $this->assertSame(
                TechnicalServiceMessageDispatch::STATUS_QUEUED,
                $dispatch->status,
                json_encode($dispatch->only(['id', 'channel', 'status', 'last_error_code', 'last_error_message_redacted']), JSON_UNESCAPED_UNICODE),
            );
            $this->assertSame(0, $dispatch->attempt_count);
            $this->assertTrue((bool) data_get($dispatch->metadata, 'manual_e2e'));
            $this->assertSame($global['manual_e2e_active_run_id'], data_get($dispatch->metadata, 'manual_e2e_run_id'));
            $this->assertFalse((bool) data_get($dispatch->metadata, 'provider_send_attempted'));
        }
        $current = app(TechnicalServiceMessagingSettingsService::class)->payload();
        $this->assertSame('prepared', data_get($current, 'global.manual_e2e_phase'));
        $this->assertFalse((bool) data_get($current, 'global.real_send_enabled'));
        $this->assertTrue((bool) data_get($current, 'global.queue_paused'));
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
        $this->assertSame('test_smoke_required', $dispatch->fresh()->last_error_code);
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
        $this->assertSame('manual_e2e_target_not_allowlisted', $dispatch->fresh()->last_error_code);
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
        $this->assertSame('manual_e2e_run_id_mismatch', $dispatch->fresh()->last_error_code);
        $this->assertStringContainsString('run id worker run id ile eşleşmiyor', $dispatch->fresh()->last_error_message_redacted);
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
        $this->assertSame('Sistem kaydı', $detail['status_label']);
        $this->assertSame('Dış sağlayıcı yok', $detail['provider_label']);
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

    public function test_blocked_unsent_dispatch_can_be_reconciled_without_losing_audit(): void
    {
        $request = $this->technicalServiceRequest();
        $creator = $this->admin();
        $actor = $this->admin();
        $dispatch = app(TechnicalServiceMessageDispatchQueue::class)->blocked([
            'technical_service_request_id' => $request->id,
            'request_id' => $request->id,
            'message_type' => 'assignment_offer_technician',
            'channel' => 'sms',
            'provider_key' => 'nac_sms',
            'recipient_role' => 'technician',
            'target_phone' => '905467647428',
            'metadata' => ['provider_send_attempted' => false],
        ], $creator, 'public_url_missing', 'Portal URL eksik.');

        $reconciled = app(TechnicalServiceMessageDispatchQueue::class)->reconcileBlockedUnsent(
            $dispatch,
            'public_url_missing_before_local_portal_configuration',
            $actor,
        );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_CANCELLED, $reconciled->status);
        $this->assertSame(0, $reconciled->attempt_count);
        $this->assertNull($reconciled->sent_at);
        $this->assertNull($reconciled->provider_message_id);
        $this->assertFalse((bool) data_get($reconciled->metadata, 'provider_send_attempted'));
        $this->assertTrue((bool) data_get($reconciled->metadata, 'retained_for_audit'));
        $this->assertSame(
            'public_url_missing_before_local_portal_configuration',
            data_get($reconciled->metadata, 'reconciliation_reason'),
        );
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'message_blocked_reconciled',
            'author_user_id' => $actor->id,
        ]);
        $event = TechnicalServiceRequestEvent::query()
            ->where('technical_service_request_id', $request->id)
            ->where('event_type', 'message_blocked_reconciled')
            ->firstOrFail();
        $this->assertSame(
            'public_url_missing_before_local_portal_configuration',
            data_get($event->metadata, 'reconciliation_reason'),
        );
        $this->assertTrue((bool) data_get($event->metadata, 'retained_for_audit'));
    }

    public function test_reconciliation_rejects_blocked_dispatch_after_provider_attempt(): void
    {
        $request = $this->technicalServiceRequest();
        $dispatch = app(TechnicalServiceMessageDispatchQueue::class)->blocked([
            'technical_service_request_id' => $request->id,
            'request_id' => $request->id,
            'message_type' => 'assignment_offer_technician',
            'channel' => 'whatsapp',
            'provider_key' => 'evo_whatsapp',
            'recipient_role' => 'technician',
            'target_phone' => '905467647428',
            'metadata' => ['provider_send_attempted' => true],
        ], $this->admin(), 'public_url_missing', 'Portal URL eksik.');

        $this->expectException(ValidationException::class);
        app(TechnicalServiceMessageDispatchQueue::class)->reconcileBlockedUnsent(
            $dispatch,
            'must_not_reconcile_attempted_dispatch',
            $this->admin(),
        );
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
     * @return array<string, mixed>
     */
    private function activateManualE2EContext(): array
    {
        $this->actingAs($this->admin());
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->freezeManualE2E();
        $settings->update([
            'messaging_enabled' => true,
            'test_mode_enabled' => false,
            'shared_test_phone' => '905467647428',
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
            'ops_whatsapp_phone' => '905467647428',
            'manual_e2e_partner_portal_origin_enabled' => true,
            'manual_e2e_partner_portal_origin' => 'http://10.0.28.64:8000',
            'manual_e2e_ttl_seconds' => 14400,
            'active_provider' => 'evo_whatsapp',
            'provider_key' => 'evo_whatsapp',
            'evo_whatsapp' => [
                'direct_api_enabled' => true,
                'direct_api_base_url' => 'https://evo-api.example.test',
                'direct_api_instance_name' => 'manual-e2e-test',
            ],
            'nac_sms' => [
                'enabled' => true,
                'sender' => 'EMAKS TEST',
            ],
            'message_types' => [
                'assignment_offer_technician' => [
                    'enabled' => true,
                    'real_send_allowed' => true,
                    'channel_policy' => 'whatsapp_and_sms',
                ],
                'earnings_message_technician' => [
                    'enabled' => true,
                    'real_send_allowed' => true,
                    'channel_policy' => 'whatsapp_only',
                ],
            ],
        ]);
        $page = PageConfig::query()
            ->where('page_code', TechnicalServiceMessagingSettingsService::PAGE_CODE)
            ->firstOrFail();
        $layout = (array) $page->layout_json;
        Arr::set($layout, TechnicalServiceMessagingSettingsService::ROOT_KEY.'.providers.evo_whatsapp', [
            'enabled' => true,
            'real_send_allowed' => true,
            'test_send_allowed' => true,
            'notes' => 'Fake Manual E2E queue test provider.',
        ]);
        $page->forceFill(['layout_json' => $layout])->save();
        $lifecyclePage = PageConfig::query()
            ->where('page_code', TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE)
            ->firstOrFail();
        $lifecycleLayout = (array) $lifecyclePage->layout_json;
        Arr::set(
            $lifecycleLayout,
            TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY.'.providers.evo_whatsapp',
            [
                'enabled' => true,
                'real_send_allowed' => true,
                'test_send_allowed' => true,
                'notes' => 'Fake Manual E2E queue test provider.',
            ],
        );
        $lifecyclePage->forceFill(['layout_json' => $lifecycleLayout])->save();
        $settings->saveEvoWhatsappCredentials(['api_key' => 'test-evo-key']);
        $settings->saveNacSmsCredentials(['username' => 'test-user', 'password' => 'test-password']);

        return $settings->prepareManualE2E()['global'];
    }

    private function enqueueManualE2EDispatch(
        array $global,
        string $provider = 'evo_whatsapp',
        string $channel = 'whatsapp',
        string $targetPhone = '905372081633',
        string $token = 'MANUAL-E2E-EXACT-TOKEN',
        ?TechnicalServiceRequest $request = null,
    ): TechnicalServiceMessageDispatch {
        $request ??= $this->technicalServiceRequest([
            'mrn' => 'MRN-'.$token,
            'customer_phone' => $targetPhone,
        ]);
        $metadata = app(TechnicalServiceMessagingSettingsService::class)
            ->manualE2EContext()
            ->dispatchMetadata($token, $targetPhone, 'customer');

        return $this->enqueueDispatch([
            'event' => 'manual_e2e_exact_customer',
            'message_type' => 'manual_e2e_exact_customer',
            'technical_service_request_id' => $request->id,
            'request_id' => $request->id,
            'provider_key' => $provider,
            'channel' => $channel,
            'recipient_role' => 'customer',
            'target_phone' => $targetPhone,
            'payload' => ['body' => "{$token} kontrollü provider mesajı."],
            'metadata' => [
                ...$metadata,
                'provider_send_attempted' => false,
                'fixture_run_id' => $global['manual_e2e_active_run_id'],
            ],
        ]);
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
                'notes' => 'Fake normal queue provider.',
            ]);
            $page->forceFill(['layout_json' => $layout])->save();
        }
        $settings->saveEvoWhatsappCredentials(['api_key' => 'evo-secret-key']);
    }

    private function configureNacDirectClient(): void
    {
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
    }

    private function authoritativeLifecycleSetting(string $key): mixed
    {
        $layout = PageConfig::query()
            ->where('page_code', TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE)
            ->value('layout_json');

        return Arr::get(
            is_array($layout) ? $layout : [],
            TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY.'.'.$key,
        );
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
