<?php

namespace Tests\Feature;

use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Messaging\TechnicalServiceWorkflowMessageDispatchService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\InteractsWithExternalExecutionControlPlane;
use Tests\TestCase;

class TechnicalServiceManualE2ERunContextTest extends TestCase
{
    use DatabaseMigrations, InteractsWithExternalExecutionControlPlane;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('m', 32))]);
        $this->travelTo(Carbon::parse('2026-07-21 12:00:00', 'Europe/Istanbul'));
        Http::preventStrayRequests();
    }

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();

        $this->beforeApplicationDestroyed(function (): void {
            // The in-memory connection is discarded, so legacy SQLite down
            // migrations do not need to run during teardown.
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_manual_e2e_run_context_defaults_to_inactive_without_generic_run(): void
    {
        $payload = app(TechnicalServiceMessagingSettingsService::class)->payload();

        $this->assertFalse($payload['manual_e2e']['active']);
        $this->assertSame('frozen', $payload['manual_e2e']['phase']);
        $this->assertSame('Aktif run yok', $payload['manual_e2e']['status_label']);
        $this->assertNull($payload['manual_e2e']['active_run_id']);
        $this->assertNull($payload['manual_e2e']['worker_command']);
    }

    public function test_enabling_manual_e2e_generates_unique_run_id_and_repeated_read_keeps_it(): void
    {
        Http::fake();
        $settings = $this->readyControlledManualE2ESettings();
        $first = $settings->enableManualE2E();
        $runId = (string) $first['manual_e2e']['active_run_id'];

        $this->assertMatchesRegularExpression('/^MANUAL-E2E-FULL-\d{8}-\d{6}-[A-Z0-9]{4}$/', $runId);
        $this->assertSame($runId, $settings->payload()['manual_e2e']['active_run_id']);
        $this->assertSame($runId, $settings->payload()['manual_e2e']['active_run_id']);
        $this->assertNotNull($first['manual_e2e']['started_at']);
        $this->assertSame($first['manual_e2e']['started_at'], $first['manual_e2e']['created_after']);
        $this->assertNotNull($first['manual_e2e']['expires_at']);
        $this->assertSame('prepared', $first['manual_e2e']['phase']);
        $this->assertFalse($first['global']['real_send_enabled']);
        $this->assertTrue($first['global']['queue_paused']);
        $this->assertNull($first['manual_e2e']['worker_command']);
        $this->assertNull($first['manual_e2e']['open_window']);
        Http::assertNothingSent();
    }

    public function test_freeze_deactivates_context_preserves_last_run_and_new_enable_gets_new_id(): void
    {
        Http::fake();
        $settings = $this->readyControlledManualE2ESettings();
        $firstRunId = (string) $settings->enableManualE2E()['manual_e2e']['active_run_id'];

        $frozen = $settings->freezeManualE2E();
        $this->assertFalse($frozen['global']['manual_e2e_enabled']);
        $this->assertFalse($frozen['global']['real_send_enabled']);
        $this->assertTrue($frozen['global']['queue_paused']);
        $this->assertNull($frozen['manual_e2e']['active_run_id']);
        $this->assertSame($firstRunId, $frozen['manual_e2e']['last_run_id']);
        $this->assertNotNull($frozen['manual_e2e']['last_stopped_at']);

        $secondRunId = (string) $settings->enableManualE2E()['manual_e2e']['active_run_id'];
        $this->assertNotSame($firstRunId, $secondRunId);
        Http::assertNothingSent();
    }

    public function test_frozen_outbound_lock_excludes_prepare_transition_for_the_entire_callback(): void
    {
        Http::fake();
        $settings = $this->readyControlledManualE2ESettings();

        $settings->withManualE2EFrozenOutbound(function () use ($settings): void {
            try {
                $settings->prepareManualE2E();
                $this->fail('Outbound kilidi tutulurken prepare lifecycle geçişi yapamamalıydı.');
            } catch (ConflictHttpException) {
                $payload = $settings->payload();
                $this->assertFalse($payload['global']['manual_e2e_enabled']);
                $this->assertFalse($payload['global']['real_send_enabled']);
                $this->assertTrue($payload['global']['queue_paused']);
                $this->assertNull($payload['manual_e2e']['active_run_id']);
                $this->assertContains(
                    'manual_e2e_lifecycle_busy',
                    collect($settings->manualE2EReadiness()['blockers'])->pluck('code')->all(),
                );
            }
        });

        $prepared = $settings->prepareManualE2E();
        $this->assertSame('prepared', $prepared['manual_e2e']['phase']);
        $settings->freezeManualE2E();
        Http::assertNothingSent();
    }

    public function test_frozen_outbound_lock_releases_after_callback_exception(): void
    {
        Http::fake();
        $settings = $this->readyControlledManualE2ESettings();

        try {
            $settings->withManualE2EFrozenOutbound(static function (): void {
                throw new \RuntimeException('controlled outbound failure');
            });
            $this->fail('Controlled callback exception dışarı taşınmalıydı.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('controlled outbound failure', $exception->getMessage());
        }

        $prepared = $settings->prepareManualE2E();
        $this->assertSame('prepared', $prepared['manual_e2e']['phase']);
        $settings->freezeManualE2E();
        Http::assertNothingSent();
    }

    public function test_stale_main_page_writer_cannot_resurrect_frozen_lifecycle(): void
    {
        Http::fake();
        $settings = $this->readyControlledManualE2ESettings();
        $settings->prepareManualE2E();
        $mainPage = PageConfig::query()->where('page_code', TechnicalServiceMessagingSettingsService::PAGE_CODE)->firstOrFail();
        $stalePreparedLayout = (array) $mainPage->layout_json;

        $settings->freezeManualE2E();
        $mainPage->refresh()->forceFill(['layout_json' => $stalePreparedLayout])->saveQuietly();

        $payload = $settings->payload();
        $this->assertSame('frozen', $payload['global']['manual_e2e_phase']);
        $this->assertFalse($payload['global']['manual_e2e_enabled']);
        $this->assertFalse($payload['global']['real_send_enabled']);
        $this->assertTrue($payload['global']['queue_paused']);
        $this->assertNull($payload['manual_e2e']['active_run_id']);

        $callbackRan = false;
        $settings->withManualE2EFrozenOutbound(function () use (&$callbackRan): void {
            $callbackRan = true;
        });
        $this->assertTrue($callbackRan);
        Http::assertNothingSent();
    }

    public function test_worker_command_is_generated_only_for_one_exact_open_dispatch_window(): void
    {
        Http::fake();
        $settings = $this->readyControlledManualE2ESettings();
        $prepared = $settings->enableManualE2E();
        $this->assertNull($prepared['manual_e2e']['worker_command']);

        $dispatch = $this->manualDispatch($settings, 'evo_whatsapp', 'whatsapp');
        $settings->openManualE2ESendWindow(
            (string) $prepared['manual_e2e']['active_run_id'],
            $dispatch->id,
        );
        $payload = $settings->payload();

        Artisan::call('technical-service:process-message-dispatches', ['--print-start-command' => true]);
        $output = Artisan::output();
        $runId = (string) $payload['manual_e2e']['active_run_id'];
        $createdAfter = (string) $payload['manual_e2e']['created_after'];

        $this->assertStringContainsString('--smoke-run-id='.$runId, $output);
        $this->assertStringContainsString('--created-after=\\"'.$createdAfter.'\\"', $output);
        $this->assertStringContainsString('--manual-e2e-only', $output);
        $this->assertStringContainsString('--dispatch-id='.$dispatch->id, $output);
        $this->assertStringContainsString('--provider=evo_whatsapp', $output);
        $this->assertStringContainsString('--channel=whatsapp', $output);
        $this->assertStringContainsString('--limit=1', $output);
        $this->assertStringNotContainsString('--allowlisted-phone', $output);
        $this->assertStringNotContainsString('905372081633', $output);
        $this->assertStringNotContainsString('evo_whatsapp,nac_sms', $output);
        $this->assertSame($runId, $settings->payload()['manual_e2e']['active_run_id']);
        Http::assertNothingSent();
    }

    public function test_default_effect_window_and_worker_remain_thirty_seconds_and_claim_at_29_seconds(): void
    {
        Http::fake();
        $settings = $this->readyControlledManualE2ESettings();
        $prepared = $settings->enableManualE2E();
        $runId = (string) data_get($prepared, 'manual_e2e.active_run_id');
        $dispatch = $this->manualDispatch($settings, 'evo_whatsapp', 'whatsapp');
        $settings->openManualE2ESendWindow($runId, $dispatch->id);
        $payload = $settings->payload();

        $this->assertSame(30, data_get($payload, 'manual_e2e.effect_window_seconds'));
        $this->assertSame(30, data_get($payload, 'manual_e2e.open_window.effect_window_seconds'));
        $this->assertStringContainsString('--max-seconds=30', (string) data_get($payload, 'manual_e2e.worker_command'));

        $this->travel(29)->seconds();
        $claim = $settings->claimManualE2ESend($dispatch->id, $runId);

        $this->assertSame($dispatch->id, $claim['dispatch_id']);
        $this->assertSame(1, $dispatch->fresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_default_effect_window_rejects_claim_at_30_seconds_without_effect(): void
    {
        Http::fake();
        $settings = $this->readyControlledManualE2ESettings();
        $prepared = $settings->enableManualE2E();
        $runId = (string) data_get($prepared, 'manual_e2e.active_run_id');
        $dispatch = $this->manualDispatch($settings, 'evo_whatsapp', 'whatsapp');
        $settings->openManualE2ESendWindow($runId, $dispatch->id);

        $this->travel(30)->seconds();

        try {
            $settings->claimManualE2ESend($dispatch->id, $runId);
            $this->fail('Default effect-window tam 30 saniyede kapanmalıydı.');
        } catch (ConflictHttpException) {
            $dispatch->refresh();
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
            $this->assertSame(0, $dispatch->attempt_count);
        }
        Http::assertNothingSent();
    }

    public function test_worker_context_rejects_mismatched_created_after_and_freeze_invalidates_old_run(): void
    {
        Http::fake();
        $settings = $this->readyControlledManualE2ESettings();
        $payload = $settings->enableManualE2E();
        $runId = (string) $payload['manual_e2e']['active_run_id'];
        $createdAfter = (string) $payload['manual_e2e']['created_after'];
        $context = $settings->manualE2EContext();

        $mismatch = $context->workerBlockingReason($runId, now()->addSecond()->toIso8601String());
        $this->assertSame('manual_e2e_send_window_missing', $mismatch['code']);
        $this->assertSame('manual_e2e_send_window_missing', $context->workerBlockingReason($runId, $createdAfter)['code']);

        $dispatch = $this->manualDispatch($settings, 'evo_whatsapp', 'whatsapp');
        $settings->openManualE2ESendWindow($runId, $dispatch->id);
        $context = $settings->manualE2EContext();
        $mismatch = $context->workerBlockingReason($runId, now()->addSecond()->toIso8601String());
        $this->assertSame('manual_e2e_created_after_mismatch', $mismatch['code']);
        $this->assertNull($context->workerBlockingReason($runId, $createdAfter));

        $settings->closeManualE2ESendWindow($runId, $dispatch->id);
        $closed = $settings->payload();
        $this->assertSame($runId, $closed['manual_e2e']['active_run_id']);
        $this->assertSame('prepared', $closed['manual_e2e']['phase']);
        $this->assertFalse($closed['global']['real_send_enabled']);
        $this->assertTrue($closed['global']['queue_paused']);

        $settings->freezeManualE2E();
        $frozenBlock = $settings->manualE2EContext()->workerBlockingReason($runId, $createdAfter);
        $this->assertSame('manual_e2e_active_run_missing', $frozenBlock['code']);
    }

    public function test_worker_rejects_cli_run_id_not_matching_active_settings_before_processing(): void
    {
        Http::fake();
        $settings = $this->readyControlledManualE2ESettings();
        $prepared = $settings->enableManualE2E();
        $dispatch = $this->manualDispatch($settings, 'evo_whatsapp', 'whatsapp');
        $settings->openManualE2ESendWindow((string) $prepared['manual_e2e']['active_run_id'], $dispatch->id);
        $payload = $settings->payload();

        $this->withoutMockingConsoleOutput();
        Artisan::call('technical-service:process-message-dispatches', [
            '--worker-loop' => true,
            '--dry-run' => true,
            '--manual-e2e-only' => true,
            '--created-after' => $payload['manual_e2e']['created_after'],
            '--smoke-run-id' => 'MANUAL-E2E-FULL-20260710-000000-WRNG',
            '--allowlisted-phone' => ['905372081633', '905467647428'],
            '--provider' => 'evo_whatsapp,nac_sms',
            '--max-seconds' => 1,
            '--sleep-seconds' => 0,
        ]);
        $output = Artisan::output();

        $this->assertStringContainsString('"manual_e2e_worker_started": false', $output);
        $this->assertStringContainsString('"stop_reason": "manual_e2e_run_id_mismatch"', $output);
        $this->assertSame(1, TechnicalServiceMessageDispatch::query()->count());
        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
        $this->assertSame(0, $dispatch->attempt_count);
        Http::assertNothingSent();
    }

    public function test_dispatch_creation_blocks_when_manual_e2e_active_run_is_missing(): void
    {
        Http::fake();
        $settings = $this->readyControlledManualE2ESettings([
            'appointment_updated_customer' => [
                'enabled' => true,
                'real_send_allowed' => true,
                'channel_policy' => 'whatsapp_only',
            ],
        ]);
        $settings->enableManualE2E();
        $page = PageConfig::query()->where('page_code', TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE)->firstOrFail();
        $layout = (array) $page->layout_json;
        Arr::set($layout, TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY.'.manual_e2e_active_run_id', null);
        $page->forceFill(['layout_json' => $layout])->save();

        $request = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-REL4E15G-MISSING-RUN',
            'customer_name' => 'Run Context Test',
            'customer_phone' => '05372081633',
            'customer_city' => 'İstanbul',
            'customer_district' => 'Kadıköy',
            'service_address' => 'Test adresi',
            'product_name' => 'Test ürün',
            'service_type' => 'Montaj',
            'status' => 'Yeni',
        ]);
        $summary = app(TechnicalServiceWorkflowMessageDispatchService::class)->queueWorkflowDispatches(
            $request,
            'appointment_updated_customer',
            'customer',
            ['appointment_date' => '10.07.2026', 'appointment_time' => '10:00'],
            $this->admin(),
        );

        $this->assertSame(0, $summary['queued']);
        $this->assertSame(1, $summary['blocked']);
        $this->assertSame('manual_e2e_active_run_missing', $summary['blockers'][0]['code']);
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
        ]);
        Http::assertNothingSent();
    }

    public function test_no_workflow_service_writes_literal_generic_run_id(): void
    {
        foreach ([
            app_path('Services/Messaging/TechnicalServiceManualE2ERunContext.php'),
            app_path('Services/Messaging/TechnicalServiceWorkflowMessageDispatchService.php'),
            app_path('Services/Messaging/TechnicalServiceAppointmentMessageDispatchService.php'),
            app_path('Services/Messaging/TechnicalServiceMessageDispatchProcessor.php'),
            app_path('Services/Messaging/TechnicalServiceMessageProviderRouter.php'),
            app_path('Console/Commands/ProcessTechnicalServiceMessageDispatches.php'),
        ] as $path) {
            $this->assertStringNotContainsString('MANUAL-E2E-LIVE-TEST', File::get($path));
        }
    }

    private function manualDispatch(
        TechnicalServiceMessagingSettingsService $settings,
        string $provider,
        string $channel,
    ): TechnicalServiceMessageDispatch {
        $request = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-MANUAL-WINDOW-'.strtoupper(substr(hash('sha256', uniqid('', true)), 0, 8)),
            'customer_name' => 'Manual Window Test',
            'customer_phone' => '05372081633',
            'customer_city' => 'İstanbul',
            'customer_district' => 'Kadıköy',
            'service_address' => 'Test adresi',
            'product_name' => 'Test ürün',
            'service_type' => 'Montaj',
            'status' => 'Yeni',
        ]);
        $token = (string) $request->mrn;
        $body = "EMAKS Prime {$token} randevu bilgilendirmesi.";
        $metadata = [
            ...$settings->executionModeSnapshot(),
            ...$settings->manualE2EContext()->dispatchMetadata(
                $token,
                '905372081633',
                'customer',
            ),
        ];

        return TechnicalServiceMessageDispatch::query()->create([
            'event' => 'appointment_updated_customer',
            'technical_service_request_id' => $request->id,
            'request_id' => $request->id,
            'mrn' => $request->mrn,
            'message_type' => 'appointment_updated_customer',
            'provider_key' => $provider,
            'channel' => $channel,
            'recipient_role' => 'customer',
            'target_type' => 'customer',
            'target_phone' => '905372081633',
            'original_phone' => '905372081633',
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
            'attempt_count' => 0,
            'max_attempts' => 1,
            'idempotency_key' => hash('sha256', uniqid('manual-window-', true)),
            'queued_at' => now(),
            'request_payload' => ['body' => $body],
            'metadata' => $metadata,
        ]);
    }

    private function readyControlledManualE2ESettings(array $messageTypes = []): TechnicalServiceMessagingSettingsService
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->freezeManualE2E();
        $settings->update([
            'messaging_enabled' => true,
            'test_mode_enabled' => false,
            'shared_test_phone' => '905467647428',
            'ops_whatsapp_phone' => '905467647428',
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
            'manual_e2e_partner_portal_origin_enabled' => true,
            'manual_e2e_partner_portal_origin' => 'http://10.0.28.64:8000',
            'active_provider' => 'evo_whatsapp',
            'provider_key' => 'evo_whatsapp',
            'evo_whatsapp' => [
                'direct_api_enabled' => true,
                'direct_api_base_url' => 'https://evo-api.example.test',
                'direct_api_instance_name' => 'manual-e2e-test',
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
            'message_types' => array_replace_recursive([
                'assignment_offer_technician' => [
                    'enabled' => true,
                    'real_send_allowed' => true,
                    'channel_policy' => 'whatsapp_and_sms',
                ],
            ], $messageTypes),
        ]);
        $page = PageConfig::query()->where('page_code', TechnicalServiceMessagingSettingsService::PAGE_CODE)->firstOrFail();
        $layout = (array) $page->layout_json;
        Arr::set($layout, TechnicalServiceMessagingSettingsService::ROOT_KEY.'.providers.evo_whatsapp', [
            'enabled' => true,
            'real_send_allowed' => true,
            'test_send_allowed' => true,
            'notes' => 'Fake manual E2E test provider.',
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
                'notes' => 'Fake manual E2E test provider.',
            ],
        );
        $lifecyclePage->forceFill(['layout_json' => $lifecycleLayout])->save();
        $settings->saveEvoWhatsappCredentials(['api_key' => 'test-evo-key']);
        $settings->saveNacSmsCredentials(['username' => 'test-user', 'password' => 'test-password']);
        $this->activateGlobalLiveForMessagingAdapterFixture($settings, $admin);

        return $settings;
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }
}
