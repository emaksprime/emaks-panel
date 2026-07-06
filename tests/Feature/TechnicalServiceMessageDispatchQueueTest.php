<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestEvent;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceMessageChannelPlanner;
use App\Services\Messaging\TechnicalServiceMessageDispatchProcessor;
use App\Services\Messaging\TechnicalServiceMessageDispatchQueue;
use App\Services\Messaging\TechnicalServiceMessageDispatchStatusRegistry;
use App\Services\Messaging\TechnicalServiceMessageIdempotencyService;
use App\Services\Messaging\TechnicalServiceMessageProviderRouter;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
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
            'payload' => ['body' => 'second'],
        ]);

        $result = app(TechnicalServiceMessageDispatchProcessor::class)->processOne($dispatch->id, noExternal: true);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_COOLDOWN_BLOCKED, $result['status']);
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
        $this->assertSame('fake_accepted', $dispatch->provider_status);
        $this->assertSame('evo_whatsapp-fake-'.$dispatch->id, $dispatch->provider_message_id);
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
