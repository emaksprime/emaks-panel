<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\IntegrationProviderCredential;
use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Models\UserAccess;
use App\Services\ExternalEffects\ExternalEffectCapabilityRegistry;
use App\Services\ExternalEffects\ExternalExecutionControlPlaneService;
use App\Services\Messaging\EvolutionWhatsAppMessageService;
use App\Services\Messaging\TechnicalServiceMessageDispatchProcessor;
use App\Services\Messaging\TechnicalServiceMessageDispatchQueue;
use App\Services\Messaging\TechnicalServiceMessageProviderRouter;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Messaging\TechnicalServiceNacSmsTestClient;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithExternalExecutionControlPlane;
use Tests\TestCase;

class TechnicalServiceMessagingExecutionModeTest extends TestCase
{
    use DatabaseMigrations, InteractsWithExternalExecutionControlPlane;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-07-21 12:00:00', 'Europe/Istanbul'));
        Cache::flush();
        Http::preventStrayRequests();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();

        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_execution_mode_defaults_local_and_dedicated_api_is_authorized_strict_and_sanitized(): void
    {
        $admin = $this->admin();
        $operator = User::factory()->create(['role_code' => 'ops']);
        UserAccess::query()->create([
            'user_id' => $operator->id,
            'resource_code' => 'technical_service_manage',
            'can_view' => true,
        ]);
        $managementViewer = User::factory()->create(['role_code' => 'ops']);
        UserAccess::query()->create([
            'user_id' => $managementViewer->id,
            'resource_code' => 'admin_panel',
            'can_view' => true,
        ]);

        $this->getJson('/api/technical-service/execution-control')
            ->assertUnauthorized();
        $this->actingAs($operator)
            ->getJson('/api/technical-service/execution-control')
            ->assertOk()
            ->assertJsonPath('execution_control.mode', 'local')
            ->assertJsonPath('execution_control.can_transition', false);
        $this->actingAs($operator)
            ->postJson('/api/technical-service/execution-control', [
                'mode' => 'local',
                'reason' => 'Yetkisiz mode degisiklik denemesi.',
                'expected_revision' => 1,
            ])
            ->assertForbidden();
        $this->actingAs($managementViewer)
            ->getJson('/api/technical-service/execution-control')
            ->assertOk()
            ->assertJsonPath('execution_control.mode', 'local')
            ->assertJsonPath('execution_control.can_transition', false);
        $this->actingAs($managementViewer)
            ->postJson('/api/technical-service/execution-control', [
                'mode' => 'local',
                'reason' => 'Yonetim paneli gorunurlugu mutation yetkisi degildir.',
                'expected_revision' => 1,
            ])
            ->assertForbidden();

        $response = $this->actingAs($admin)
            ->getJson('/api/technical-service/execution-control')
            ->assertOk()
            ->assertJsonPath('execution_control.mode', 'local')
            ->assertJsonPath('execution_control.state', 'local')
            ->assertJsonPath('execution_control.epoch', 1)
            ->assertJsonPath('execution_control.revision', 1)
            ->assertJsonPath('execution_control.runtime_environment', 'local')
            ->assertJsonPath('execution_control.readiness.registered_count', 28)
            ->assertJsonPath('execution_control.can_transition', true);

        $encoded = $response->getContent();
        $this->assertStringNotContainsString('api_key', $encoded);
        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('manual_e2e_allowlisted_phones', $encoded);

        $this->actingAs($admin)
            ->postJson('/api/technical-service/execution-control', [
                'mode' => 'live',
                'reason' => 'Canli moda gecis testi.',
                'expected_revision' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');
        $this->actingAs($admin)
            ->postJson('/api/technical-service/execution-control', [
                'mode' => 'local',
                'reason' => 'kisa',
                'expected_revision' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->actingAs($admin)
            ->postJson('/api/technical-service/execution-control', [
                'mode' => 'local',
                'reason' => 'Strict payload dogrulama testi.',
                'expected_revision' => 1,
                'runtime_environment' => 'production',
                'provider' => 'evo_whatsapp',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');

        $this->actingAs($admin)
            ->getJson('/api/technical-service/messaging-settings/execution-mode/readiness')
            ->assertOk()
            ->assertJsonPath('deprecated', true)
            ->assertJsonPath('canonical_endpoint', '/api/technical-service/execution-control')
            ->assertJsonPath('execution_mode.mode', 'local')
            ->assertJsonPath('execution_mode.revision', 1);

        $current = app(TechnicalServiceMessagingSettingsService::class)->executionModePayload();
        $this->assertSame('local', $current['mode']);
        $this->assertSame(1, $current['revision']);
        $this->assertDatabaseCount('panel.logs', 0);
        Http::assertNothingSent();
    }

    public function test_global_registry_is_complete_and_unknown_capabilities_fail_closed(): void
    {
        $registry = app(ExternalEffectCapabilityRegistry::class);
        $controlPlane = app(ExternalExecutionControlPlaneService::class);
        $definitions = $registry->definitions();
        $payload = $controlPlane->payload();

        $this->assertCount(28, $definitions);
        $this->assertSame(28, data_get($payload, 'readiness.registered_count'));
        $this->assertFalse((bool) data_get($payload, 'readiness.eligible'));
        $this->assertGreaterThan(0, (int) data_get($payload, 'readiness.blocker_count'));
        $this->assertFalse((bool) $definitions['bulk.support.apply']['adapted']);
        $this->assertTrue((bool) $definitions['bulk.support.apply']['required']);

        $authorization = $controlPlane->authorizeCapabilitySnapshot('future.unregistered.effect', []);
        $this->assertFalse($authorization['allowed']);
        $this->assertSame('external_capability_unknown', $authorization['code']);
        Http::assertNothingSent();
    }

    public function test_legacy_messaging_live_value_cannot_promote_global_authority(): void
    {
        Http::fake();
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->freezeManualE2E();
        $this->setPersistedSetting('outbound_execution_mode', 'live');
        $this->setPersistedSetting('real_send_enabled', true);
        $this->setPersistedSetting('queue_paused', false);

        $controlPlane = app(ExternalExecutionControlPlaneService::class);
        $this->assertSame('local', $controlPlane->state()['operator_mode']);
        $this->assertSame('local', $settings->executionModePayload()['mode']);
        $this->assertSame(
            'outbound_execution_mode_local',
            $settings->directProviderExecutionBlock('evo_whatsapp')['code'],
        );

        $dispatch = app(TechnicalServiceMessageDispatchQueue::class)->enqueue([
            'event' => 'legacy_mode_must_not_promote_global_live',
            'message_type' => 'assignment_offer_technician',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'technician',
            'target_type' => 'technician',
            'target_phone' => '905000000001',
            'payload' => ['body' => 'Synthetic legacy mode isolation message.'],
        ]);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED, $dispatch->status);
        $this->assertSame(0, $dispatch->attempt_count);
        Http::assertNothingSent();
    }

    public function test_execution_mode_requires_valid_expected_revision_without_mutation(): void
    {
        $admin = $this->admin();
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $before = $this->executionModeMutationSnapshot($settings);

        $cases = [
            'missing' => [
                'payload' => [
                    'mode' => 'local',
                    'reason' => 'Missing revision validation test.',
                ],
                'field' => 'expected_revision',
            ],
            'string' => [
                'payload' => [
                    'mode' => 'local',
                    'reason' => 'String revision validation test.',
                    'expected_revision' => 'not-a-revision',
                ],
                'field' => 'expected_revision',
            ],
            'zero' => [
                'payload' => [
                    'mode' => 'local',
                    'reason' => 'Zero revision validation test.',
                    'expected_revision' => 0,
                ],
                'field' => 'expected_revision',
            ],
            'negative' => [
                'payload' => [
                    'mode' => 'local',
                    'reason' => 'Negative revision validation test.',
                    'expected_revision' => -1,
                ],
                'field' => 'expected_revision',
            ],
            'unknown field' => [
                'payload' => [
                    'mode' => 'local',
                    'reason' => 'Unknown field validation test.',
                    'expected_revision' => 1,
                    'provider' => 'evo_whatsapp',
                ],
                'field' => 'mode',
            ],
        ];

        foreach ($cases as $case => $expectation) {
            $this->actingAs($admin)
                ->postJson(
                    '/api/technical-service/execution-control',
                    $expectation['payload'],
                )
                ->assertUnprocessable()
                ->assertJsonValidationErrors($expectation['field']);

            $this->assertSame(
                $before,
                $this->executionModeMutationSnapshot($settings),
                'Invalid CAS payload mutated execution state for case: '.$case,
            );
            $this->assertSame(
                0,
                AuditLog::query()
                    ->where('action', 'external_execution_control.mode.changed')
                    ->count(),
                'Invalid CAS payload created an execution-mode audit for case: '.$case,
            );
        }

        Http::assertNothingSent();
    }

    public function test_exact_execution_mode_revision_succeeds_once_and_increments_once(): void
    {
        $admin = $this->admin();
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $capturedRevision = (int) $settings->executionModePayload()['revision'];
        $reason = 'Exact revision token=fake-secret Authorization: Bearer fake-bearer telefon 905000000001';

        $response = $this->actingAs($admin)
            ->postJson('/api/technical-service/execution-control', [
                'mode' => 'local',
                'reason' => $reason,
                'expected_revision' => $capturedRevision,
            ])
            ->assertOk()
            ->assertJsonPath('execution_control.mode', 'local')
            ->assertJsonPath('execution_control.revision', $capturedRevision + 1);

        $audit = AuditLog::query()
            ->where('action', 'external_execution_control.mode.changed')
            ->sole();
        $this->assertSame($capturedRevision, data_get($audit->payload, 'previous_revision'));
        $this->assertSame($capturedRevision + 1, data_get($audit->payload, 'new_revision'));
        $encoded = $response->getContent().json_encode($audit->payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('fake-secret', $encoded);
        $this->assertStringNotContainsString('fake-bearer', $encoded);
        $this->assertStringNotContainsString('905000000001', $encoded);
        $this->assertStringContainsString('[redacted]', $encoded);
        $this->assertStringContainsString('[redacted-phone]', $encoded);
        Http::assertNothingSent();
    }

    public function test_two_admins_with_same_revision_reject_stale_same_mode_without_mutation(): void
    {
        $adminA = $this->admin();
        $adminB = $this->admin();
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $capturedRevision = (int) $settings->executionModePayload()['revision'];

        $this->actingAs($adminA)
            ->postJson('/api/technical-service/execution-control', [
                'mode' => 'local',
                'reason' => 'First administrator exact transition.',
                'expected_revision' => $capturedRevision,
            ])
            ->assertOk()
            ->assertJsonPath('execution_control.revision', $capturedRevision + 1);

        $afterFirstTransition = $this->executionModeMutationSnapshot($settings);
        $this->actingAs($adminB)
            ->postJson('/api/technical-service/execution-control', [
                'mode' => 'local',
                'reason' => 'Second administrator stale same-mode transition.',
                'expected_revision' => $capturedRevision,
            ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Çalışma modu başka bir yönetici tarafından değiştirildi. Güncel durumu yeniden yükleyip kararınızı tekrar verin.',
            );

        $this->assertSame($afterFirstTransition, $this->executionModeMutationSnapshot($settings));
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'external_execution_control.mode.changed')
                ->count(),
        );
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('action', 'external_execution_control.mode.changed')
                ->where('user_id', $adminB->id)
                ->count(),
        );
        Http::assertNothingSent();
    }

    public function test_stale_transition_conflicts_before_manual_e2e_freeze_or_gate_mutation(): void
    {
        $admin = $this->admin();
        $settings = $this->configureLiveReadiness($admin);
        $capturedRevision = (int) $settings->executionModePayload()['revision'];
        $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
        $prepared = $settings->prepareManualE2E()['global'];
        $this->assertNotNull($prepared['manual_e2e_active_run_id']);
        $beforeConflict = $this->executionModeMutationSnapshot($settings);

        $this->actingAs($admin)
            ->postJson('/api/technical-service/execution-control', [
                'mode' => 'local',
                'reason' => 'Stale request must not freeze active lifecycle.',
                'expected_revision' => $capturedRevision,
            ])
            ->assertConflict();

        $this->assertSame($beforeConflict, $this->executionModeMutationSnapshot($settings));
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('action', 'external_execution_control.mode.changed')
                ->count(),
        );
        Http::assertNothingSent();
    }

    public function test_global_live_rejects_required_unadapted_capabilities_without_partial_mutation(): void
    {
        $admin = $this->admin();
        $settings = $this->configureLiveReadiness($admin);
        $reason = 'Kontrollu test token=fake-secret api_key_encrypted=encrypted-key token_encrypted=encrypted-token password_encrypted=encrypted-password Authorization: Basic ZmFrZTpmYWtl https://user:pass@evo.example.test/send?apikey=query-secret&client_secret=client-secret telefon 905000000001';
        $before = $this->executionModeMutationSnapshot($settings);

        $readiness = $this->actingAs($admin)
            ->getJson('/api/technical-service/execution-control')
            ->assertOk()
            ->assertJsonPath('execution_control.readiness.eligible', false)
            ->assertJsonPath('execution_control.readiness.registered_count', 28)
            ->assertJsonPath('execution_control.mode', 'local')
            ->json('execution_control.readiness');
        $this->assertGreaterThan(0, (int) $readiness['blocker_count']);
        $this->assertContains('bulk.support.apply', array_column($readiness['blockers'], 'capability'));

        $response = $this->actingAs($admin)
            ->withHeader('X-Request-ID', 'MODE-TEST-CORRELATION-1')
            ->postJson('/api/technical-service/execution-control', [
                'mode' => 'live',
                'reason' => $reason,
                'confirmation' => 'CANLI MODU AÇ',
                'expected_revision' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');

        $encoded = $response->getContent();
        $this->assertStringNotContainsString('fake-secret', $encoded);
        $this->assertStringNotContainsString('ZmFrZTpmYWtl', $encoded);
        $this->assertStringNotContainsString('user:pass', $encoded);
        $this->assertStringNotContainsString('query-secret', $encoded);
        $this->assertStringNotContainsString('encrypted-key', $encoded);
        $this->assertStringNotContainsString('encrypted-token', $encoded);
        $this->assertStringNotContainsString('encrypted-password', $encoded);
        $this->assertStringNotContainsString('client-secret', $encoded);
        $this->assertStringNotContainsString('905000000001', $encoded);
        $this->assertSame($before, $this->executionModeMutationSnapshot($settings));
        $this->assertSame('local', $settings->executionModePayload()['mode']);
        $this->assertDatabaseCount('panel.logs', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('missingProviderCases')]
    public function test_live_readiness_is_all_or_nothing_and_failure_has_no_partial_mutation(string $missingProvider): void
    {
        $admin = $this->admin();
        $settings = $this->configureLiveReadiness(
            $admin,
            withEvoCredential: $missingProvider !== 'evo_whatsapp',
            withNacCredential: $missingProvider !== 'nac_sms',
        );
        $before = $settings->executionModePayload();

        $response = $this->actingAs($admin)
            ->postJson('/api/technical-service/execution-control', [
                'mode' => 'live',
                'reason' => 'Eksik provider readiness atomic gecis testi.',
                'confirmation' => 'CANLI MODU AÇ',
                'expected_revision' => (int) $before['revision'],
            ])
            ->assertUnprocessable();

        $this->assertStringContainsString(
            $missingProvider === 'evo_whatsapp'
                ? ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND
                : ExternalEffectCapabilityRegistry::MESSAGING_NAC_SEND,
            $response->getContent(),
        );
        $after = $settings->executionModePayload();
        $this->assertSame($before['mode'], $after['mode']);
        $this->assertSame($before['revision'], $after['revision']);
        $this->assertFalse($after['real_send_enabled']);
        $this->assertTrue($after['queue_paused']);
        $this->assertDatabaseCount('panel.logs', 0);
        Http::assertNothingSent();
    }

    public static function missingProviderCases(): array
    {
        return [
            'Evo credential missing' => ['evo_whatsapp'],
            'NAC credential missing' => ['nac_sms'],
        ];
    }

    public function test_active_manual_run_blocks_live_reactivation_and_local_transition_emergency_freezes_it(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->configureLiveReadiness($admin);
        $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
        $prepared = $settings->prepareManualE2E()['global'];

        $this->assertTrue($prepared['manual_e2e_enabled']);
        $this->assertFalse($prepared['real_send_enabled']);
        $this->assertTrue($prepared['queue_paused']);
        $this->assertNotNull($prepared['manual_e2e_active_run_id']);

        $this->actingAs($admin)
            ->postJson('/api/technical-service/execution-control', [
                'mode' => 'live',
                'reason' => 'Active run varken yeniden acma denemesi.',
                'confirmation' => 'CANLI MODU AÇ',
                'expected_revision' => (int) $settings->executionModePayload()['revision'],
            ])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->postJson('/api/technical-service/execution-control', [
                'mode' => 'local',
                'reason' => 'Emergency freeze ile provider kapilarini kapat.',
                'expected_revision' => (int) $settings->executionModePayload()['revision'],
            ])
            ->assertOk()
            ->assertJsonPath('execution_control.mode', 'local')
            ->assertJsonPath('execution_control.state', 'local');

        $payload = $settings->payload();
        $this->assertNull($payload['manual_e2e']['active_run_id']);
        $this->assertFalse($payload['global']['manual_e2e_enabled']);
        $this->assertSame('frozen', $payload['global']['manual_e2e_phase']);
        $this->assertFalse($payload['global']['real_send_enabled']);
        $this->assertTrue($payload['global']['queue_paused']);
        Http::assertNothingSent();
    }

    public function test_local_transition_ignores_broken_live_profile_and_closes_all_outbound_gates(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->configureLiveReadiness($admin);
        $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
        $settings->prepareManualE2E();
        Cache::put(TechnicalServiceMessagingSettingsService::OUTBOUND_WORKER_LEASE_KEY, [
            'lock_owner' => 'stale-worker-owner',
            'release_sha' => '98fb1937fd2dc302870c992bf864108bc7acba7d',
            'heartbeat_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinute()->toIso8601String(),
        ], 60);
        $this->setPersistedSetting('send_delay_seconds', 1);

        $mode = $settings->transitionExecutionMode(
            'local',
            'Broken provider profile must not block emergency freeze.',
            $admin,
            (int) $settings->executionModePayload()['revision'],
        );

        $this->assertSame('local', $mode['mode']);
        $this->assertFalse($mode['real_send_enabled']);
        $this->assertTrue($mode['queue_paused']);
        $this->assertFalse($mode['manual_e2e_enabled']);
        $this->assertSame('frozen', $mode['manual_e2e_phase']);
        $this->assertNotNull(Cache::get(TechnicalServiceMessagingSettingsService::OUTBOUND_WORKER_LEASE_KEY));
        $this->assertFalse($settings->normalOutboundWorkerMayProcess('stale-worker-owner'));
        Http::assertNothingSent();
    }

    public function test_manual_e2e_run_state_is_rejected_after_environment_changes_to_production(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->configureLiveReadiness($admin);
        $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
        $settings->prepareManualE2E();

        $this->withProductionRuntime(function () use ($settings): void {
            try {
                $settings->assertManualE2ELifecycleStateValid();
                $this->fail('Non-production Manual E2E state production runtime içinde geçerli olmamalıydı.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        });

        Http::assertNothingSent();
    }

    public function test_dispatch_snapshot_is_server_owned_and_local_or_stale_work_never_attempts_provider(): void
    {
        Http::fake();
        $queue = app(TechnicalServiceMessageDispatchQueue::class);
        $processor = app(TechnicalServiceMessageDispatchProcessor::class);
        $parent = $queue->enqueue([
            'event' => 'execution_mode_parent',
            'message_type' => 'assignment_offer_technician',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'technician',
            'target_type' => 'technician',
            'target_phone' => '905000000001',
            'payload' => ['body' => 'Execution mode local audit message.'],
            'metadata' => [
                'outbound_execution_mode' => 'live',
                'outbound_mode_revision' => 999,
                'runtime_environment' => 'production',
            ],
        ]);

        $this->assertSame('local', data_get($parent->metadata, 'outbound_execution_mode'));
        $this->assertSame(1, data_get($parent->metadata, 'outbound_mode_revision'));
        $this->assertSame('local', data_get($parent->metadata, 'runtime_environment'));
        $this->assertFalse((bool) data_get($parent->metadata, 'messaging_enabled'));
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED, $parent->status);
        $this->assertSame(0, $parent->fresh()->attempt_count);
        $this->assertSame('local_no_send', $parent->fresh()->provider_status);
        $this->assertNull($parent->fresh()->queued_at);
        $this->assertTrue((bool) data_get($parent->fresh()->metadata, 'local_no_send_recorded'));

        $admin = $this->admin();
        $settings = $this->configureLiveReadiness($admin);
        $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
        $child = $queue->enqueue([
            'event' => 'execution_mode_parent',
            'message_type' => 'assignment_offer_technician',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'technician',
            'target_type' => 'technician',
            'target_phone' => '905000000001',
            'payload' => ['body' => 'Execution mode stale child audit message.'],
            'parent_dispatch_id' => $parent->id,
            'force_resend' => true,
            'force_resend_reason' => 'Revision mirasi guvenlik testi.',
        ], $admin);

        $this->assertSame('local', data_get($child->metadata, 'outbound_execution_mode'));
        $this->assertSame(1, data_get($child->metadata, 'outbound_mode_revision'));
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED, $child->status);
        $this->assertSame('outbound_execution_mode_local', $child->fresh()->last_error_code);
        $this->assertSame(0, $child->fresh()->attempt_count);

        $stale = $queue->enqueue([
            'event' => 'execution_mode_stale_revision',
            'message_type' => 'assignment_offer_technician',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'technician',
            'target_type' => 'technician',
            'target_phone' => '905000000001',
            'payload' => ['body' => 'Execution mode stale revision message.'],
        ]);
        $retryCandidate = $queue->enqueue([
            'event' => 'execution_mode_stale_retry',
            'message_type' => 'assignment_offer_technician',
            'provider_key' => 'nac_sms',
            'channel' => 'sms',
            'recipient_role' => 'technician',
            'target_type' => 'technician',
            'target_phone' => '905000000001',
            'payload' => ['body' => 'Execution mode stale retry message.'],
        ]);
        $retryCandidate->forceFill([
            'status' => TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR,
        ])->save();
        $retryBefore = $retryCandidate->fresh()->getRawOriginal();
        $settings->transitionExecutionMode(
            'local',
            'Stale dispatch fencing revision advance.',
            $admin,
            (int) $settings->executionModePayload()['revision'],
        );
        $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
        $staleResult = $processor->processOne($stale->id);
        $this->assertTrue((bool) ($staleResult['blocked'] ?? false));
        $this->assertSame('outbound_mode_revision_stale', $stale->fresh()->last_error_code);
        $this->assertSame(0, $stale->fresh()->attempt_count);

        $staleResend = $queue->enqueue([
            'event' => 'execution_mode_stale_force_resend',
            'message_type' => 'assignment_offer_technician',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'technician',
            'target_type' => 'technician',
            'target_phone' => '905000000001',
            'payload' => ['body' => 'Stale force resend must be blocked before queue release.'],
            'parent_dispatch_id' => $stale->id,
            'force_resend' => true,
            'force_resend_reason' => 'Stale global revision regression.',
        ], $admin);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_BLOCKED, $staleResend->status);
        $this->assertSame('outbound_mode_revision_stale', $staleResend->last_error_code);
        $this->assertSame(0, $staleResend->attempt_count);
        $this->assertNull($staleResend->queued_at);

        try {
            $queue->retryFailed($retryCandidate->fresh(), $admin);
            $this->fail('Stale global revision dispatch retry kuyruğuna alınmamalıydı.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dispatch', $exception->errors());
        }
        $this->assertSame($retryBefore, $retryCandidate->fresh()->getRawOriginal());

        $normal = $queue->enqueue([
            'event' => 'execution_mode_non_production_normal',
            'message_type' => 'assignment_offer_technician',
            'provider_key' => 'nac_sms',
            'channel' => 'sms',
            'recipient_role' => 'technician',
            'target_type' => 'technician',
            'target_phone' => '905000000001',
            'payload' => ['body' => 'Non production normal dispatch is blocked.'],
        ]);
        $normalResult = $processor->processOne($normal->id);
        $this->assertTrue((bool) ($normalResult['blocked'] ?? false));
        $this->assertSame('non_production_normal_outbound_blocked', $normal->fresh()->last_error_code);
        $this->assertSame(0, $normal->fresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_switching_to_local_terminalizes_force_resend_and_rejects_retry_without_backlog(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->configureLiveReadiness($admin);
        $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
        $queue = app(TechnicalServiceMessageDispatchQueue::class);
        $parent = $queue->enqueue([
            'event' => 'execution_mode_live_parent',
            'message_type' => 'assignment_offer_technician',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'technician',
            'target_type' => 'technician',
            'target_phone' => '905000000001',
            'payload' => ['body' => 'Live parent later retried in local mode.'],
        ]);
        $failed = $queue->enqueue([
            'event' => 'execution_mode_live_failed',
            'message_type' => 'assignment_offer_technician',
            'provider_key' => 'nac_sms',
            'channel' => 'sms',
            'recipient_role' => 'technician',
            'target_type' => 'technician',
            'target_phone' => '905000000001',
            'payload' => ['body' => 'Failed dispatch must not requeue in local mode.'],
        ]);
        $failed->forceFill(['status' => TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR])->save();
        $failedBefore = $failed->fresh()->getRawOriginal();

        $mode = $settings->transitionExecutionMode(
            'local',
            'Retry ve resend dis provider kapisi kapatildi.',
            $admin,
            (int) $settings->executionModePayload()['revision'],
        );
        $child = $queue->enqueue([
            'event' => 'execution_mode_local_force_resend',
            'message_type' => 'assignment_offer_technician',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'technician',
            'target_type' => 'technician',
            'target_phone' => '905000000001',
            'payload' => ['body' => 'Local force resend remains a no-send record.'],
            'parent_dispatch_id' => $parent->id,
            'force_resend' => true,
            'force_resend_reason' => 'Lokal mod replay engeli.',
        ], $admin);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED, $child->status);
        $this->assertSame('local_no_send', $child->provider_status);
        $this->assertSame('local', data_get($child->metadata, 'outbound_execution_mode'));
        $this->assertSame($mode['revision'], data_get($child->metadata, 'outbound_mode_revision'));
        $this->assertSame('live', data_get($child->metadata, 'parent_outbound_execution_mode'));
        $this->assertSame(0, $child->attempt_count);
        $this->assertNull($child->queued_at);

        try {
            $queue->retryFailed($failed->fresh(), $admin);
            $this->fail('Lokal modda external dispatch retry reddedilmeliydi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dispatch', $exception->errors());
        }

        $this->assertSame($failedBefore, $failed->fresh()->getRawOriginal());
        $this->assertSame(1, TechnicalServiceMessageDispatch::query()->where('status', TechnicalServiceMessageDispatch::STATUS_QUEUED)->count());
        Http::assertNothingSent();
    }

    public function test_direct_evolution_and_nac_clients_cannot_bypass_execution_claim_boundary(): void
    {
        $admin = $this->admin();
        $settings = $this->configureLiveReadiness($admin);
        $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
        config([
            'services.evolution.n8n_webhook_url' => 'https://legacy-evo.example.test/send',
            'services.evolution.test_mode' => false,
            'services.evolution.real_send_enabled' => true,
            'services.evolution.allow_unit_test_http_fake' => true,
        ]);
        Http::fake();

        $request = $this->technicalServiceRequest();
        $dispatch = app(EvolutionWhatsAppMessageService::class)->send(
            'customer_approval_request',
            'customer',
            '905000000001',
            'Direct Evo bypass guvenlik testi.',
            ['manual_ui_send' => true, 'allow_unit_test_http_fake' => true],
            $request,
        );
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $dispatch->status);
        $this->assertSame('direct_provider_claim_required', data_get($dispatch->response_payload, 'execution_mode_block_code'));
        $this->assertFalse((bool) data_get($dispatch->response_payload, 'provider_send_attempted'));

        $nacDispatch = app(TechnicalServiceNacSmsTestClient::class)->sendProviderTest(
            '905000000001',
            ['real_sms_confirmed' => true],
            $admin,
        );
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $nacDispatch->status);
        $this->assertSame('direct_provider_claim_required', data_get($nacDispatch->response_payload, 'execution_mode_block_code'));
        $this->assertFalse((bool) data_get($nacDispatch->response_payload, 'provider_send_attempted'));

        $this->assertSame(2, TechnicalServiceMessageDispatch::query()->count());
        $this->assertSame(0, TechnicalServiceMessageDispatch::query()->sum('attempt_count'));
        Http::assertNothingSent();
    }

    #[DataProvider('productionProviderCases')]
    public function test_production_live_mode_opens_only_the_guarded_fake_queue_provider_path(
        string $provider,
        string $channel,
    ): void {
        $this->withProductionRuntime(function () use ($provider, $channel): void {
            $admin = $this->admin();
            $settings = $this->configureLiveReadiness($admin);
            $owner = 'execution-mode-worker-owner';
            $settings->registerOutboundWorkerLease($owner, now()->toImmutable(), now()->addMinute()->toImmutable());
            $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);

            $mode = $settings->executionModePayload();
            $this->assertSame('production', $mode['runtime_environment']);
            $this->assertSame('live', $mode['mode']);
            $this->assertTrue($mode['real_send_enabled']);
            $this->assertFalse($mode['queue_paused']);
            $this->assertFalse($mode['readiness']['eligible']);
            $this->assertGreaterThan(0, (int) $mode['readiness']['blocker_count']);

            $dispatch = app(TechnicalServiceMessageDispatchQueue::class)->enqueue([
                'event' => 'execution_mode_production_'.$provider,
                'message_type' => 'assignment_offer_technician',
                'provider_key' => $provider,
                'channel' => $channel,
                'recipient_role' => 'technician',
                'target_type' => 'technician',
                'target_phone' => '905000000001',
                'payload' => ['body' => 'Production fake provider execution mode message.'],
                'metadata' => [
                    'normal_outbound_worker_lease_hash' => hash('sha256', $owner),
                ],
            ]);
            $direct = app(TechnicalServiceMessageProviderRouter::class)->dispatch($dispatch);
            $this->assertSame('normal_outbound_transport_permit_rejected', $direct['provider_status']);
            $this->assertSame(0, $dispatch->fresh()->attempt_count);
            Http::assertNothingSent();
            Http::fake(function ($request) use ($provider, $dispatch) {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertFalse(DB::connection()->getPdo()->inTransaction());
                $claimed = $dispatch->fresh();
                $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $claimed->status);
                $this->assertSame(1, $claimed->attempt_count);
                $this->assertNotSame('', (string) data_get($claimed->metadata, 'normal_processor_claim_hash'));
                $this->assertTrue((bool) data_get($claimed->metadata, 'provider_send_attempted'));
                $this->assertNotNull(data_get($claimed->metadata, 'normal_outbound_http_started_at'));

                return $provider === 'nac_sms'
                    ? Http::response(['err' => null, 'data' => ['pkgID' => 987654]], 200)
                    : Http::response(['messageId' => 'EVO-EXECUTION-MODE-ACK'], 200);
            });

            $result = app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
                $dispatch->id,
                noExternal: false,
                options: ['outbound_worker_owner' => $owner],
            );
            $persisted = $dispatch->fresh();

            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $result['status']);
            $this->assertSame(1, $persisted->attempt_count);
            $this->assertSame('provider_accepted', data_get($persisted->metadata, 'normal_outbound_outcome'));
            $this->assertNotSame('delivered', data_get($persisted->metadata, 'normal_outbound_outcome'));
            $this->assertNotNull($persisted->provider_message_id);
            Http::assertSentCount(1);
        });
    }

    public static function productionProviderCases(): array
    {
        return [
            'Evolution WhatsApp' => ['evo_whatsapp', 'whatsapp'],
            'NAC SMS' => ['nac_sms', 'sms'],
        ];
    }

    #[DataProvider('productionProviderCases')]
    public function test_production_live_final_boundary_requires_both_providers_to_remain_ready(
        string $provider,
        string $channel,
    ): void {
        $this->withProductionRuntime(function () use ($provider, $channel): void {
            $admin = $this->admin();
            $settings = $this->configureLiveReadiness($admin);
            $owner = 'all-or-nothing-worker-owner';
            $settings->registerOutboundWorkerLease($owner, now()->toImmutable(), now()->addMinute()->toImmutable());
            $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
            $missingProvider = $provider === 'evo_whatsapp' ? 'nac_sms' : 'evo_whatsapp';
            IntegrationProviderCredential::query()
                ->where('scope', IntegrationProviderCredential::SCOPE_TECHNICAL_SERVICE)
                ->where('provider', $missingProvider)
                ->delete();
            $dispatch = app(TechnicalServiceMessageDispatchQueue::class)->enqueue([
                'event' => 'execution_mode_provider_set_'.$provider,
                'message_type' => 'assignment_offer_technician',
                'provider_key' => $provider,
                'channel' => $channel,
                'recipient_role' => 'technician',
                'target_type' => 'technician',
                'target_phone' => '905000000001',
                'payload' => ['body' => 'Both providers must remain ready before outbound.'],
            ]);
            Http::fake();

            $result = app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
                $dispatch->id,
                options: ['outbound_worker_owner' => $owner],
            );

            $this->assertTrue((bool) ($result['blocked'] ?? false));
            $this->assertSame('outbound_provider_set_not_ready', $dispatch->fresh()->last_error_code);
            $this->assertSame(0, $dispatch->fresh()->attempt_count);
            Http::assertNothingSent();
        });
    }

    #[DataProvider('productionProviderCases')]
    public function test_production_live_provider_requests_never_follow_redirects(
        string $provider,
        string $channel,
    ): void {
        $this->withProductionRuntime(function () use ($provider, $channel): void {
            $admin = $this->admin();
            $settings = $this->configureLiveReadiness($admin);
            $owner = 'redirect-worker-owner';
            $settings->registerOutboundWorkerLease($owner, now()->toImmutable(), now()->addMinute()->toImmutable());
            $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
            $dispatch = app(TechnicalServiceMessageDispatchQueue::class)->enqueue([
                'event' => 'execution_mode_redirect_'.$provider,
                'message_type' => 'assignment_offer_technician',
                'provider_key' => $provider,
                'channel' => $channel,
                'recipient_role' => 'technician',
                'target_type' => 'technician',
                'target_phone' => '905000000001',
                'payload' => ['body' => 'Provider redirect must not create another request.'],
            ]);
            Http::fake(fn () => Http::response([], 307, [
                'Location' => 'https://redirect-target.example.test/second-request',
            ]));

            app(TechnicalServiceMessageDispatchProcessor::class)->processOne(
                $dispatch->id,
                options: ['outbound_worker_owner' => $owner],
            );

            $persisted = $dispatch->fresh();
            $this->assertSame(1, $persisted->attempt_count);
            $this->assertNull($persisted->provider_message_id);
            $this->assertNotContains($persisted->status, TechnicalServiceMessageDispatch::SUCCESS_STATUSES);
            Http::assertSentCount(1);
        });
    }

    public function test_production_local_mode_and_outer_transaction_fail_before_provider_mutation(): void
    {
        $this->withProductionRuntime(function (): void {
            Http::fake();
            $queue = app(TechnicalServiceMessageDispatchQueue::class);
            $processor = app(TechnicalServiceMessageDispatchProcessor::class);
            $local = $queue->enqueue([
                'event' => 'execution_mode_production_local',
                'message_type' => 'assignment_offer_technician',
                'provider_key' => 'evo_whatsapp',
                'channel' => 'whatsapp',
                'recipient_role' => 'technician',
                'target_type' => 'technician',
                'target_phone' => '905000000001',
                'payload' => ['body' => 'Production local mode no send.'],
            ]);
            $localResult = $processor->processOne($local->id);
            $this->assertTrue((bool) ($localResult['skipped'] ?? false));
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED, $localResult['status']);
            $this->assertSame('local_no_send', $local->fresh()->provider_status);
            $this->assertSame(0, $local->fresh()->attempt_count);

            $admin = $this->admin();
            $settings = $this->configureLiveReadiness($admin);
            $owner = 'outer-transaction-worker-owner';
            $settings->registerOutboundWorkerLease($owner, now()->toImmutable(), now()->addMinute()->toImmutable());
            $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
            $dispatch = $queue->enqueue([
                'event' => 'execution_mode_outer_transaction',
                'message_type' => 'assignment_offer_technician',
                'provider_key' => 'evo_whatsapp',
                'channel' => 'whatsapp',
                'recipient_role' => 'technician',
                'target_type' => 'technician',
                'target_phone' => '905000000001',
                'payload' => ['body' => 'Outer transaction must fail before claim.'],
            ]);

            $result = DB::transaction(fn (): array => $processor->processOne(
                $dispatch->id,
                options: ['outbound_worker_owner' => $owner],
            ));
            $this->assertTrue((bool) ($result['blocked'] ?? false));
            $this->assertSame('dispatch_outer_transaction_open', $result['provider_status']);
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->fresh()->status);
            $this->assertSame(0, $dispatch->fresh()->attempt_count);
            $this->assertNull(data_get($dispatch->fresh()->metadata, 'normal_processor_claim_hash'));
            Http::assertNothingSent();
        });
    }

    public function test_metadata_seeder_cache_clear_and_credential_save_do_not_change_persisted_mode(): void
    {
        $admin = $this->admin();
        $settings = $this->configureLiveReadiness($admin);
        $this->assertSame('local', $settings->executionModePayload()['mode']);
        $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);
        $before = $settings->executionModePayload();
        $credentialsBefore = IntegrationProviderCredential::query()
            ->orderBy('provider')
            ->get(['provider', 'api_key_encrypted', 'username_encrypted', 'password_encrypted'])
            ->toArray();

        $this->seed(PanelMetadataSeeder::class);
        Cache::flush();
        $after = app(TechnicalServiceMessagingSettingsService::class)->executionModePayload();
        $credentialsAfter = IntegrationProviderCredential::query()
            ->orderBy('provider')
            ->get(['provider', 'api_key_encrypted', 'username_encrypted', 'password_encrypted'])
            ->toArray();

        $this->assertSame($before['mode'], $after['mode']);
        $this->assertSame($before['revision'], $after['revision']);
        $this->assertSame($credentialsBefore, $credentialsAfter);
        Http::assertNothingSent();
    }

    public function test_execution_mode_ui_exposes_read_only_environment_readiness_and_non_optimistic_controls(): void
    {
        $managementSource = File::get(resource_path('js/pages/panel/admin/AdminIndex.jsx'));
        $operationsSource = File::get(resource_path('js/pages/panel/technical-service.tsx'));
        $messagingAdminSource = File::get(resource_path('js/pages/panel/technical-service-admin.tsx'));
        $postBody = $this->executionControlPostBody($managementSource);

        $this->assertStringContainsString('Sistem Çalışma Modu', $managementSource);
        $this->assertStringContainsString('Canlıya Geç', $managementSource);
        $this->assertStringContainsString('Lokale Al', $managementSource);
        $this->assertStringContainsString('CANLI MODU AÇ', $managementSource);
        $this->assertStringContainsString('/api/technical-service/execution-control', $managementSource);
        $this->assertStringContainsString('executionControlExpectedRevision', $managementSource);
        $this->assertStringContainsString('setExecutionControlExpectedRevision(current.revision)', $managementSource);
        $this->assertStringContainsString('expected_revision: executionControlExpectedRevision', $postBody);
        $this->assertStringContainsString('response.status === 409', $postBody);
        $this->assertStringContainsString('await loadExecutionControl(false)', $postBody);
        $this->assertStringContainsString('resetExecutionControlDialog()', $postBody);
        $this->assertStringNotContainsString('runtime_environment:', $postBody);
        $this->assertStringNotContainsString('retry', $postBody);

        $this->assertStringContainsString('data-testid="global-execution-control-readonly"', $operationsSource);
        $this->assertStringContainsString('Yönetim Paneli’nde yönetilir', $operationsSource);
        $this->assertStringNotContainsString('transitionExecutionControl', $operationsSource);
        $this->assertStringNotContainsString('data-testid="global-execution-control-action"', $operationsSource);

        $this->assertStringContainsString('Global Sistem Çalışma Modu', $messagingAdminSource);
        $this->assertStringContainsString('Yönetim Paneli’nde yönetilir.', $messagingAdminSource);
        $this->assertStringContainsString('Salt okunur', $messagingAdminSource);
        $this->assertStringNotContainsString('Lokalde Çalıştır', $messagingAdminSource);
        $this->assertStringNotContainsString('Canlıda Çalıştır', $messagingAdminSource);
        $this->assertStringNotContainsString('transitionExecutionControl', $messagingAdminSource);
        $this->assertSame(
            1,
            substr_count(
                $managementSource.$operationsSource.$messagingAdminSource,
                'data-testid="global-execution-control-confirm"',
            ),
        );
        $this->assertSame(
            1,
            substr_count(
                $managementSource.$operationsSource.$messagingAdminSource,
                'data-testid="global-execution-control-action"',
            ),
        );
    }

    public function test_legacy_missing_mode_with_open_flags_can_be_repaired_through_local_transition(): void
    {
        Http::fake();
        $admin = $this->admin();
        app(TechnicalServiceMessagingSettingsService::class)->freezeManualE2E();
        $this->setPersistedSetting('outbound_execution_mode', null);
        $this->setPersistedSetting('real_send_enabled', true);
        $this->setPersistedSetting('queue_paused', false);

        $before = app(TechnicalServiceMessagingSettingsService::class)->executionModePayload();
        $this->assertSame('local', $before['mode']);
        $this->assertTrue($before['real_send_enabled']);
        $this->assertFalse($before['queue_paused']);

        $after = app(TechnicalServiceMessagingSettingsService::class)->transitionExecutionMode(
            'local',
            'Legacy inconsistent local gates are explicitly frozen.',
            $admin,
            (int) $before['revision'],
        );

        $this->assertSame('local', $after['mode']);
        $this->assertFalse($after['real_send_enabled']);
        $this->assertTrue($after['queue_paused']);
        $this->assertSame('frozen', $after['manual_e2e_phase']);
        Http::assertNothingSent();
    }

    private function configureLiveReadiness(
        User $admin,
        bool $withEvoCredential = true,
        bool $withNacCredential = true,
    ): TechnicalServiceMessagingSettingsService {
        $this->actingAs($admin);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->freezeManualE2E();
        $settings->update([
            'messaging_enabled' => true,
            'test_mode_enabled' => false,
            'shared_test_phone' => '905000000001',
            'ops_whatsapp_phone' => '905000000001',
            'manual_e2e_allowlisted_phones' => ['905000000001'],
            'manual_e2e_partner_portal_origin_enabled' => true,
            'manual_e2e_partner_portal_origin' => 'http://10.10.10.10:8000',
            'active_provider' => 'evo_whatsapp',
            'provider_key' => 'evo_whatsapp',
            'evo_whatsapp' => [
                'direct_api_enabled' => true,
                'direct_api_base_url' => 'https://evo-api.example.test',
                'direct_api_instance_name' => 'execution-mode-test',
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
                'assignment_offer_technician' => [
                    'enabled' => true,
                    'real_send_allowed' => true,
                    'channel_policy' => 'whatsapp_and_sms',
                    'whatsapp_provider' => 'evo_whatsapp',
                    'sms_provider' => 'nac_sms',
                ],
            ],
        ]);
        $this->enableProviders(['evo_whatsapp', 'nac_sms']);
        if ($withEvoCredential) {
            $settings->saveEvoWhatsappCredentials(['api_key' => 'fake-evo-api-key']);
        }
        if ($withNacCredential) {
            $settings->saveNacSmsCredentials(['username' => 'fake-nac-user', 'password' => 'fake-nac-password']);
        }

        return $settings;
    }

    /**
     * @param  array<int, string>  $providers
     */
    private function enableProviders(array $providers): void
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
            foreach ($providers as $provider) {
                Arr::set($layout, $target['root'].'.providers.'.$provider, [
                    'enabled' => true,
                    'real_send_allowed' => true,
                    'test_send_allowed' => true,
                    'notes' => 'Execution mode isolated fake provider.',
                ]);
            }
            $page->forceFill(['layout_json' => $layout])->save();
        }
    }

    private function setPersistedSetting(string $key, mixed $value): void
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
            Arr::set($layout, $target['root'].'.'.$key, $value);
            $page->forceFill(['layout_json' => $layout])->save();
        }
    }

    private function withProductionRuntime(callable $callback): mixed
    {
        $previousEnvironment = $this->app->environment();
        $previousTrustedProxies = getenv('TRUSTED_PROXIES');
        $overrides = [
            'app.debug' => false,
            'app.release_sha' => '98fb1937fd2dc302870c992bf864108bc7acba7d',
            'app.url' => 'https://uat.example.test',
            'services.partner_portal.public_url' => 'https://uat.example.test',
            'services.evolution.allow_unit_test_http_fake' => true,
            'session.secure' => true,
            'session.domain' => 'uat.example.test',
        ];
        $previousConfig = [];
        foreach (array_keys($overrides) as $key) {
            $previousConfig[$key] = config($key);
        }

        $this->app->detectEnvironment(static fn (): string => 'production');
        config($overrides);
        putenv('TRUSTED_PROXIES=127.0.0.1');

        try {
            return $callback();
        } finally {
            config($previousConfig);
            $this->app->detectEnvironment(static fn (): string => $previousEnvironment);
            if ($previousTrustedProxies === false) {
                putenv('TRUSTED_PROXIES');
            } else {
                putenv('TRUSTED_PROXIES='.$previousTrustedProxies);
            }
        }
    }

    private function technicalServiceRequest(): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create([
            'mrn' => 'MODE-SAFETY-REQUEST',
            'customer_name' => 'Execution Mode Test',
            'customer_phone' => '05000000001',
            'customer_city' => 'Test City',
            'customer_district' => 'Test District',
            'service_address' => 'Synthetic test address',
            'product_name' => 'Synthetic product',
            'service_type' => 'Test',
            'status' => 'Yeni',
        ]);
    }

    private function executionControlPostBody(string $source): string
    {
        $start = strpos($source, 'const transitionExecutionControl');
        $end = strpos($source, 'const counts =', $start === false ? 0 : $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }

    /**
     * @return array<string, mixed>
     */
    private function executionModeMutationSnapshot(
        TechnicalServiceMessagingSettingsService $settings,
    ): array {
        $executionMode = $settings->executionModePayload();
        $lifecycle = $settings->manualE2ELifecyclePayload();

        return [
            'execution_mode' => Arr::only($executionMode, [
                'mode',
                'state',
                'epoch',
                'revision',
                'profile_fingerprint',
                'real_send_enabled',
                'queue_paused',
                'manual_e2e_enabled',
                'manual_e2e_phase',
                'changed_at',
                'changed_by',
                'reason',
            ]),
            'lifecycle_global' => Arr::only((array) $lifecycle['global'], [
                'manual_e2e_enabled',
                'real_send_enabled',
                'queue_paused',
                'test_mode_enabled',
                'ops_whatsapp_enabled',
                'manual_e2e_phase',
                'manual_e2e_active_run_id',
                'manual_e2e_started_at',
                'manual_e2e_created_after',
                'manual_e2e_expires_at',
                'manual_e2e_last_run_id',
                'manual_e2e_last_stopped_at',
            ]),
            'manual_e2e' => Arr::only((array) $lifecycle['manual_e2e'], [
                'enabled',
                'phase',
                'active_run_id',
                'started_at',
                'created_after',
                'expires_at',
                'open_window',
                'active_claim',
                'last_run_id',
                'last_stopped_at',
            ]),
        ];
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }
}
