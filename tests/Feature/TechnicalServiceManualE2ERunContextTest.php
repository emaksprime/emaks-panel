<?php

namespace Tests\Feature;

use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Messaging\TechnicalServiceWorkflowMessageDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TechnicalServiceManualE2ERunContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_e2e_run_context_defaults_to_inactive_without_generic_run(): void
    {
        $payload = app(TechnicalServiceMessagingSettingsService::class)->payload();

        $this->assertFalse($payload['manual_e2e']['active']);
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

    public function test_generated_worker_command_uses_active_unique_run_and_persisted_created_after(): void
    {
        Http::fake();
        $settings = $this->readyControlledManualE2ESettings();
        $payload = $settings->enableManualE2E([
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
        ]);

        Artisan::call('technical-service:process-message-dispatches', ['--print-start-command' => true]);
        $output = Artisan::output();
        $runId = (string) $payload['manual_e2e']['active_run_id'];
        $createdAfter = (string) $payload['manual_e2e']['created_after'];

        $this->assertStringContainsString('--smoke-run-id='.$runId, $output);
        $this->assertStringContainsString('--created-after=\\"'.$createdAfter.'\\"', $output);
        $this->assertStringContainsString('--manual-e2e-only', $output);
        $this->assertStringContainsString('--provider=evo_whatsapp,nac_sms', $output);
        $this->assertSame($runId, $settings->payload()['manual_e2e']['active_run_id']);
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
        $this->assertSame('manual_e2e_created_after_mismatch', $mismatch['code']);
        $this->assertNull($context->workerBlockingReason($runId, $createdAfter));

        $settings->freezeManualE2E();
        $frozenBlock = $settings->manualE2EContext()->workerBlockingReason($runId, $createdAfter);
        $this->assertSame('manual_e2e_active_run_missing', $frozenBlock['code']);
    }

    public function test_worker_rejects_cli_run_id_not_matching_active_settings_before_processing(): void
    {
        Http::fake();
        $payload = $this->readyControlledManualE2ESettings()->enableManualE2E();

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
        $this->assertSame(0, TechnicalServiceMessageDispatch::query()->count());
        Http::assertNothingSent();
    }

    public function test_dispatch_creation_blocks_when_manual_e2e_active_run_is_missing(): void
    {
        Http::fake();
        $settings = $this->readyControlledManualE2ESettings();
        $settings->update([
            'message_types' => [
                'appointment_updated_customer' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
            ],
        ]);
        $settings->enableManualE2E();
        $page = PageConfig::query()->where('page_code', TechnicalServiceMessagingSettingsService::PAGE_CODE)->firstOrFail();
        $layout = (array) $page->layout_json;
        Arr::set($layout, TechnicalServiceMessagingSettingsService::ROOT_KEY.'.manual_e2e_active_run_id', null);
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

    private function readyControlledManualE2ESettings(): TechnicalServiceMessagingSettingsService
    {
        $this->actingAs($this->admin());
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
                'sender' => 'EMAKS TEST',
            ],
            'message_types' => [
                'assignment_offer_technician' => [
                    'enabled' => true,
                    'real_send_allowed' => true,
                    'channel_policy' => 'whatsapp_and_sms',
                ],
            ],
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
        $settings->saveEvoWhatsappCredentials(['api_key' => 'test-evo-key']);
        $settings->saveNacSmsCredentials(['username' => 'test-user', 'password' => 'test-password']);

        return $settings;
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }
}
