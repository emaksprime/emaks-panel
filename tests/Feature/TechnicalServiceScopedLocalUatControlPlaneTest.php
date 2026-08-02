<?php

namespace Tests\Feature;

use App\Mail\TechnicalServicePaymentAuditMail;
use App\Models\MailTransportProfile;
use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\ExternalEffects\ExternalEffectCapabilityRegistry;
use App\Services\ExternalEffects\ExternalExecutionControlPlaneService;
use App\Services\Messaging\TechnicalServiceManualE2ERunContext;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Payments\FakePaymentProvider;
use App\Services\Payments\IyzicoPaymentProvider;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\TechnicalServiceMailTransportSettingsService;
use App\Services\Payments\TechnicalServicePaymentProviderCredentialService;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use App\Services\TechnicalService\TechnicalServicePaymentSettlementService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class TechnicalServiceScopedLocalUatControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER_PHONE = '905372081633';

    private const TECHNICIAN_PHONE = '905467647428';

    private const PAYMENT_EMAIL = 'payment-uat@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32))]);
        config([
            'services.partner_portal.public_url' => 'http://10.0.28.64:8000',
            'services.public_urls.payment_base_url' => 'http://10.0.28.64:8000',
        ]);
        $this->travelTo(Carbon::parse('2026-08-01 12:00:00', 'Europe/Istanbul'));
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_global_live_readiness_still_requires_all_required_capabilities(): void
    {
        $payload = app(ExternalExecutionControlPlaneService::class)->payload();

        $this->assertCount(28, app(ExternalEffectCapabilityRegistry::class)->definitions());
        $this->assertFalse((bool) data_get($payload, 'readiness.eligible'));
        $this->assertGreaterThan(0, (int) data_get($payload, 'readiness.blocker_count'));
        $this->assertSame(
            data_get($payload, 'readiness.blockers'),
            data_get($payload, 'readiness_profiles.global_live.blockers'),
        );
        Http::assertNothingSent();
    }

    public function test_scoped_uat_does_not_change_global_live_blockers(): void
    {
        $this->readyScopedLocalUat();
        $payload = app(ExternalExecutionControlPlaneService::class)->payload();

        $this->assertFalse((bool) data_get($payload, 'readiness_profiles.global_live.ready'));
        $this->assertTrue((bool) data_get($payload, 'readiness_profiles.local_allowlisted_uat.ready'));
        $this->assertSame(12, (int) data_get($payload, 'readiness_profiles.global_live.blocker_count'));
        $this->assertSame(
            data_get($payload, 'readiness.blockers'),
            data_get($payload, 'readiness_profiles.global_live.blockers'),
        );
        $this->assertNotEmpty(data_get($payload, 'readiness_profiles.local_allowlisted_uat.unrelated_global_blockers'));
        Http::assertNothingSent();
    }

    public function test_scoped_uat_never_marks_production_ready(): void
    {
        $this->readyScopedLocalUat();
        $readiness = app(ExternalExecutionControlPlaneService::class)->scopedLocalUatReadiness();

        $this->assertTrue($readiness['ready']);
        $this->assertFalse($readiness['production_ready']);
        $this->assertSame('Allowlistli Yerel UAT için hazır', $readiness['classification']);
        Http::assertNothingSent();
    }

    public function test_scoped_uat_requires_only_code_owned_profile_capabilities(): void
    {
        $this->readyScopedLocalUat();
        $profile = app(ExternalEffectCapabilityRegistry::class)->localAllowlistedUatProfile();
        $readiness = app(ExternalExecutionControlPlaneService::class)->scopedLocalUatReadiness();

        $this->assertSame([
            ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND,
            ExternalEffectCapabilityRegistry::MESSAGING_NAC_SEND,
            ExternalEffectCapabilityRegistry::MAIL_SMTP_SEND,
            ExternalEffectCapabilityRegistry::PAYMENT_LOCAL_SANDBOX_EXECUTE,
        ], $profile['required_capabilities']);
        $this->assertSame($profile['required_capabilities'], $readiness['required_capabilities']);
        $this->assertSame($profile['required_capabilities'], $readiness['ready_capabilities']);
        $this->assertSame([], $readiness['missing_capabilities']);
        Http::assertNothingSent();
    }

    public function test_unrelated_bulk_otp_sla_crm_survey_and_mikro_blockers_do_not_block_scoped_uat(): void
    {
        $this->readyScopedLocalUat();
        $readiness = app(ExternalExecutionControlPlaneService::class)->scopedLocalUatReadiness();
        $unrelated = collect($readiness['unrelated_global_blockers'])->pluck('capability')->filter()->all();

        $this->assertTrue($readiness['ready']);
        foreach (['bulk.support.apply', 'otp.send', 'state.sla.tick', 'crm.projection.refresh', 'survey.followup.plan', 'erp.mikro.read', 'erp.mikro.write'] as $capability) {
            $this->assertContains($capability, $unrelated);
        }
        Http::assertNothingSent();
    }

    public function test_missing_evolution_blocks_scoped_uat(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->clearProviderCredentials('evo_whatsapp');

        $this->assertScopedCapabilityMissing(ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND);
    }

    public function test_missing_nac_blocks_scoped_uat(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->clearProviderCredentials('nac_sms');

        $this->assertScopedCapabilityMissing(ExternalEffectCapabilityRegistry::MESSAGING_NAC_SEND);
    }

    public function test_missing_smtp_blocks_scoped_uat(): void
    {
        $this->readyScopedLocalUat();
        MailTransportProfile::query()->firstOrFail()->forceFill(['outgoing_enabled' => false])->save();

        $this->assertScopedCapabilityMissing(ExternalEffectCapabilityRegistry::MAIL_SMTP_SEND);
    }

    public function test_missing_sandbox_payment_blocks_scoped_uat(): void
    {
        $this->readyScopedLocalUat();
        app(TechnicalServicePaymentProviderSettingsService::class)->update([
            'real_provider_enabled' => false,
            'provider_mode' => 'live',
        ]);

        $this->assertScopedCapabilityMissing(ExternalEffectCapabilityRegistry::PAYMENT_LOCAL_SANDBOX_EXECUTE);
    }

    public function test_caller_cannot_remove_required_capability(): void
    {
        ['admin' => $admin] = $this->readyScopedLocalUat();

        $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', [
                'operation' => 'prepare',
                'required_capabilities' => [ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND],
            ])
            ->assertUnprocessable();
        $this->assertNull(app(TechnicalServiceMessagingSettingsService::class)->manualE2EContext()->activeRunId());
        Http::assertNothingSent();
    }

    public function test_caller_cannot_add_arbitrary_capability(): void
    {
        ['admin' => $admin] = $this->readyScopedLocalUat();

        $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', [
                'operation' => 'prepare',
                'capability' => 'erp.mikro.write',
            ])
            ->assertUnprocessable();
        $this->assertNull(app(TechnicalServiceMessagingSettingsService::class)->manualE2EContext()->activeRunId());
        Http::assertNothingSent();
    }

    public function test_unauthorized_event_fails_before_dispatch(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();

        $authorization = $settings->scopedLocalUatActionAuthorization(
            ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND,
            'otp.send',
            'whatsapp',
            'evo_whatsapp',
            self::CUSTOMER_PHONE,
            'customer',
        );

        $this->assertFalse($authorization['allowed']);
        $this->assertSame('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY', $authorization['code']);
        $this->assertDatabaseCount('technical_service_message_dispatches', 0);
        Http::assertNothingSent();
    }

    public function test_unauthorized_channel_fails_before_dispatch(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();

        $authorization = $settings->scopedLocalUatActionAuthorization(
            ExternalEffectCapabilityRegistry::MAIL_SMTP_SEND,
            'customer_approval_request',
            'email',
            'smtp',
            self::PAYMENT_EMAIL,
            'customer',
        );

        $this->assertFalse($authorization['allowed']);
        $this->assertSame('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY', $authorization['code']);
        Http::assertNothingSent();
    }

    public function test_sandbox_payment_does_not_require_live_payment_capability(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $readiness = app(ExternalExecutionControlPlaneService::class)->scopedLocalUatReadiness();
        $settings->prepareManualE2E();
        $authorization = $settings->scopedLocalUatActionAuthorization(
            ExternalEffectCapabilityRegistry::PAYMENT_LOCAL_SANDBOX_EXECUTE,
            'sandbox_payment',
            'sandbox_payment',
            'fake_payment',
        );

        $this->assertTrue($readiness['ready']);
        $this->assertTrue($authorization['allowed']);
        $this->assertFalse(app(TechnicalServicePaymentProviderSettingsService::class)->realProviderEnabled());
        Http::assertNothingSent();
    }

    public function test_scoped_uat_cannot_enable_real_payment(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();

        $this->expectException(ConflictHttpException::class);
        app(TechnicalServicePaymentProviderSettingsService::class)->update([
            'real_provider_enabled' => true,
            'provider_mode' => 'live',
        ]);
    }

    public function test_scoped_uat_cannot_call_real_payment_provider(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();

        $authorization = $settings->scopedLocalUatActionAuthorization(
            ExternalEffectCapabilityRegistry::PAYMENT_LOCAL_SANDBOX_EXECUTE,
            'sandbox_payment',
            'sandbox_payment',
            'iyzico_live',
        );

        $this->assertFalse($authorization['allowed']);
        $this->assertSame('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY', $authorization['code']);
        $this->assertDatabaseCount('technical_service_mount_payments', 0);
        Http::assertNothingSent();
    }

    public function test_scoped_active_run_locks_profile_provider_channel_event_and_allowlists(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $payload = $settings->prepareManualE2E();
        $stored = $this->persistedLifecycleSettings();
        $snapshot = (array) ($stored['manual_e2e_run_snapshot'] ?? []);

        $this->assertSame('prepared', data_get($payload, 'manual_e2e.phase'));
        $this->assertSame(ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE, $snapshot['scoped_local_uat_profile_id']);
        $this->assertSame(data_get($payload, 'manual_e2e.active_run_id'), $snapshot['scoped_local_uat_run_id']);
        $this->assertSame(3600, $stored['manual_e2e_ttl_seconds']);
        $this->assertFalse(data_get($payload, 'global.real_send_enabled'));
        $this->assertTrue(data_get($payload, 'global.queue_paused'));
        $this->assertFalse($snapshot['scoped_local_uat_production_ready']);
        $this->assertTrue($snapshot['scoped_local_uat_sandbox_payment']);
        $this->assertFalse($snapshot['scoped_local_uat_real_payment']);
        $this->assertFalse($snapshot['scoped_local_uat_ops_sms']);
        $this->assertSame(['whatsapp' => 4, 'sms' => 3, 'email' => 1, 'total' => 8, 'max_seconds' => 3600], $snapshot['scoped_local_uat_limits']);
        Http::assertNothingSent();
    }

    public function test_generic_settings_cannot_mutate_active_run(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();

        $this->expectException(ConflictHttpException::class);
        $settings->update(['manual_e2e_allowlisted_phones' => [self::CUSTOMER_PHONE]]);
    }

    public function test_expired_run_cannot_dispatch(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();
        $this->travel(3601)->seconds();

        $authorization = $settings->scopedLocalUatActionAuthorization(
            ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND,
            'appointment_approved_customer',
            'whatsapp',
            'evo_whatsapp',
            self::CUSTOMER_PHONE,
            'customer',
        );

        $this->assertFalse($authorization['allowed']);
        $this->assertSame('scoped_uat_active_run_missing', $authorization['code']);
        Http::assertNothingSent();
    }

    public function test_wrong_run_id_cannot_dispatch(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();

        $this->expectException(ConflictHttpException::class);
        $settings->openManualE2ESendWindow('MANUAL-E2E-FULL-20260801-120000-WRNG', 1);
    }

    public function test_pre_enable_dispatch_cannot_dispatch(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();

        $this->expectException(ConflictHttpException::class);
        $settings->openManualE2ESendWindow('MANUAL-E2E-FULL-20260801-120000-NONE', 1);
    }

    public function test_non_allowlisted_phone_fails_before_provider(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();

        $authorization = $settings->scopedLocalUatActionAuthorization(
            ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND,
            'appointment_approved_customer',
            'whatsapp',
            'evo_whatsapp',
            '905551112233',
            'customer',
        );

        $this->assertFalse($authorization['allowed']);
        $this->assertSame('scoped_uat_recipient_not_allowlisted', $authorization['code']);
        Http::assertNothingSent();
    }

    public function test_non_allowlisted_email_fails_before_transport(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();

        $authorization = $settings->scopedLocalUatActionAuthorization(
            ExternalEffectCapabilityRegistry::MAIL_SMTP_SEND,
            'sandbox_payment_notification',
            'email',
            'smtp',
            'outside@example.test',
            'customer',
        );

        $this->assertFalse($authorization['allowed']);
        $this->assertSame('scoped_uat_email_not_allowlisted', $authorization['code']);
        Http::assertNothingSent();
    }

    public function test_ops_sms_remains_disabled(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();

        $authorization = $settings->scopedLocalUatActionAuthorization(
            ExternalEffectCapabilityRegistry::MESSAGING_NAC_SEND,
            'appointment_approved_technician',
            'sms',
            'nac_sms',
            self::TECHNICIAN_PHONE,
            'ops',
        );

        $this->assertFalse($authorization['allowed']);
        $this->assertSame('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY', $authorization['code']);
        Http::assertNothingSent();
    }

    public function test_duplicate_idempotency_key_creates_no_second_send(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();
        $duplicateKey = hash('sha256', 'scoped-uat-duplicate');
        $first = $this->scopedDispatch($settings, idempotencyKey: $duplicateKey);
        $first->forceFill([
            'status' => TechnicalServiceMessageDispatch::STATUS_FAILED,
            'attempt_count' => 1,
        ])->save();

        $outerTransactionLevel = DB::transactionLevel();
        DB::beginTransaction();

        try {
            try {
                $this->scopedDispatch($settings, idempotencyKey: $duplicateKey);
                $this->fail('Duplicate idempotency key ikinci dispatch kaydı oluşturmamalıydı.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('unique', strtolower($exception->getMessage()));
            }
        } finally {
            while (DB::transactionLevel() > $outerTransactionLevel) {
                DB::rollBack();
            }
        }

        $this->assertSame(1, $first->fresh()->attempt_count);
        $this->assertSame(1, TechnicalServiceMessageDispatch::query()->where('idempotency_key', $duplicateKey)->count());
        Http::assertNothingSent();
    }

    public function test_freeze_closes_run_and_pauses_queue(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $prepared = $settings->prepareManualE2E();
        $runId = data_get($prepared, 'manual_e2e.active_run_id');
        $frozen = $settings->freezeManualE2E();

        $this->assertNull(data_get($frozen, 'manual_e2e.active_run_id'));
        $this->assertSame($runId, data_get($frozen, 'manual_e2e.last_run_id'));
        $this->assertSame('frozen', data_get($frozen, 'manual_e2e.phase'));
        $this->assertFalse(data_get($frozen, 'global.real_send_enabled'));
        $this->assertTrue(data_get($frozen, 'global.queue_paused'));
        $this->assertNull(Cache::get(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY));
        Http::assertNothingSent();
    }

    public function test_broad_queue_worker_is_not_required_or_started(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $prepared = $settings->prepareManualE2E();

        $this->assertNull(data_get($prepared, 'manual_e2e.worker_command'));
        $this->assertSame('none', data_get($settings->scopedLocalUatControlPlaneState(false), 'broad_worker_state'));
        $this->assertNull(Cache::get(TechnicalServiceMessagingSettingsService::OUTBOUND_WORKER_LEASE_KEY));
        Http::assertNothingSent();
    }

    public function test_mikro_switches_remain_false(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();
        $payload = $settings->payload();

        $this->assertFalse(data_get($payload, 'mikro_api.enabled'));
        $this->assertFalse(data_get($payload, 'mikro_api.read_sync_enabled'));
        $this->assertFalse(data_get($payload, 'mikro_api.write_enabled'));
        Http::assertNothingSent();
    }

    public function test_mikro_write_is_never_required_or_enabled(): void
    {
        $this->readyScopedLocalUat();
        $readiness = app(ExternalExecutionControlPlaneService::class)->scopedLocalUatReadiness();

        $this->assertNotContains('erp.mikro.write', $readiness['required_capabilities']);
        $this->assertContains('erp.mikro.write', collect($readiness['unrelated_global_blockers'])->pluck('capability')->all());
        Http::assertNothingSent();
    }

    public function test_local_origin_is_required(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->update([
            'manual_e2e_partner_portal_origin_enabled' => false,
            'manual_e2e_partner_portal_origin' => null,
        ]);
        $readiness = app(ExternalExecutionControlPlaneService::class)->scopedLocalUatReadiness();

        $this->assertFalse($readiness['ready']);
        $this->assertContains('scoped_uat_local_origin_not_ready', collect($readiness['blockers'])->pluck('code')->all());
        Http::assertNothingSent();
    }

    public function test_localhost_payload_is_rejected(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();
        $authorization = $settings->scopedLocalUatActionAuthorization(
            ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND,
            'appointment_approved_customer',
            'whatsapp',
            'evo_whatsapp',
            self::CUSTOMER_PHONE,
            'customer',
            'http://127.0.0.1:8000/partner/job-card/test',
        );

        $this->assertFalse($authorization['allowed']);
        $this->assertSame('scoped_uat_payload_origin_invalid', $authorization['code']);
        Http::assertNothingSent();
    }

    public function test_production_domain_payload_is_rejected_for_local_uat(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $settings->prepareManualE2E();
        $authorization = $settings->scopedLocalUatActionAuthorization(
            ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND,
            'appointment_approved_customer',
            'whatsapp',
            'evo_whatsapp',
            self::CUSTOMER_PHONE,
            'customer',
            'https://dashboard.example.test/partner/job-card/test',
        );

        $this->assertFalse($authorization['allowed']);
        $this->assertSame('scoped_uat_payload_origin_invalid', $authorization['code']);
        Http::assertNothingSent();
    }

    public function test_scoped_readiness_produces_no_business_write(): void
    {
        $this->readyScopedLocalUat();
        $before = $this->businessCounts();

        app(ExternalExecutionControlPlaneService::class)->scopedLocalUatReadiness();
        app(TechnicalServiceMessagingSettingsService::class)->manualE2EReadiness();

        $this->assertSame($before, $this->businessCounts());
        Http::assertNothingSent();
    }

    public function test_scoped_readiness_produces_no_provider_attempt(): void
    {
        $this->readyScopedLocalUat();

        app(ExternalExecutionControlPlaneService::class)->scopedLocalUatReadiness();
        app(TechnicalServiceMessagingSettingsService::class)->manualE2EReadiness();

        $this->assertDatabaseCount('technical_service_message_dispatches', 0);
        Http::assertNothingSent();
    }

    public function test_existing_global_local_to_live_transition_is_unchanged(): void
    {
        $admin = $this->admin();
        $controlPlane = app(ExternalExecutionControlPlaneService::class);
        $state = $controlPlane->state();

        try {
            $controlPlane->transition(
                ExternalExecutionControlPlaneService::MODE_LIVE,
                'Scoped UAT global geçiş sözleşmesini değiştirmemeli.',
                $admin,
                (int) $state['revision'],
                'CANLI MODU AÇ',
            );
            $this->fail('Eksik global capability varken LOCAL-to-LIVE geçişi açılamamalıydı.');
        } catch (ValidationException) {
            $this->assertSame(ExternalExecutionControlPlaneService::MODE_LOCAL, $controlPlane->state()['operator_mode']);
        }
        Http::assertNothingSent();
    }

    public function test_existing_manual_e2e_unique_run_context_is_preserved(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $first = $settings->prepareManualE2E();
        $firstRun = (string) data_get($first, 'manual_e2e.active_run_id');
        $settings->freezeManualE2E();
        $this->travel(1)->second();
        $second = $settings->prepareManualE2E();
        $secondRun = (string) data_get($second, 'manual_e2e.active_run_id');

        $this->assertMatchesRegularExpression('/^MANUAL-E2E-FULL-\d{8}-\d{6}-[A-Z0-9]{4}$/', $firstRun);
        $this->assertNotSame($firstRun, $secondRun);
        Http::assertNothingSent();
    }

    public function test_existing_selected_dispatch_processor_contract_is_preserved(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $prepared = $settings->prepareManualE2E();
        $dispatch = $this->scopedDispatch($settings);
        $runId = (string) data_get($prepared, 'manual_e2e.active_run_id');

        $opened = $settings->openManualE2ESendWindow($runId, (int) $dispatch->id);
        $this->assertSame('window_open', data_get($opened, 'manual_e2e.phase'));
        $this->assertStringContainsString('--manual-e2e-only', (string) data_get($opened, 'manual_e2e.worker_command'));
        $this->assertStringContainsString('--dispatch-id='.$dispatch->id, (string) data_get($opened, 'manual_e2e.worker_command'));
        $closed = $settings->closeManualE2ESendWindow($runId, (int) $dispatch->id);
        $this->assertSame('prepared', data_get($closed, 'manual_e2e.phase'));
        $this->assertSame(0, $dispatch->fresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_existing_provider_readiness_payload_is_preserved(): void
    {
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $payload = $settings->payload();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertTrue((bool) data_get($payload, 'evo_whatsapp.direct_api_ready'));
        $this->assertTrue((bool) data_get($payload, 'nac_sms.test_ready'));
        $this->assertStringNotContainsString('test-evo-key', $encoded);
        $this->assertStringNotContainsString('test-password', $encoded);
        Http::assertNothingSent();
    }

    public function test_admin_ui_exposes_scoped_readiness_without_claiming_live_ready(): void
    {
        $source = File::get(resource_path('js/pages/panel/technical-service-admin.tsx'));

        $this->assertStringContainsString('allowlistli Yerel', $source);
        $this->assertStringContainsString('production_ready', $source);
        $this->assertStringContainsString('sandbox_payment_ready', $source);
        $this->assertStringNotContainsString("messaging.execution_mode.mode !== 'live' ||", $source);
    }

    public function test_scoped_authorization_has_real_production_callers(): void
    {
        $mail = File::get(app_path('Services/Payments/TechnicalServiceMailTransportSettingsService.php'));
        $manager = File::get(app_path('Services/Payments/PaymentProviderManager.php'));
        $settlement = File::get(app_path('Services/TechnicalService/TechnicalServicePaymentSettlementService.php'));

        $this->assertStringContainsString('claimScopedLocalUatEmailEffect', $mail);
        $this->assertStringContainsString('claimScopedLocalUatSandboxPaymentEffect', $manager);
        $this->assertStringContainsString('claimScopedLocalUatSandboxPaymentEffect', $settlement);
        $this->assertStringContainsString('Mail::mailer', $mail);
        $this->assertStringContainsString('->createPayment(', $manager);
    }

    public function test_actual_email_send_entrypoint_requires_scoped_effect_authority(): void
    {
        $this->readyScopedLocalUat();
        Mail::fake();
        $payment = $this->scopedPayment('MISSING-RUN');

        try {
            $this->sendScopedPaymentMail($payment);
            $this->fail('Active run olmadan SMTP transporta ulaşılamamalıydı.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('active_run_missing', $exception->getMessage());
        }

        Mail::assertNothingSent();
    }

    public function test_actual_sandbox_session_entrypoint_requires_scoped_effect_authority(): void
    {
        $this->readyScopedLocalUat();
        $this->mock(FakePaymentProvider::class, fn ($mock) => $mock->shouldNotReceive('createPayment'));
        $payment = $this->scopedPayment('MISSING-RUN');

        try {
            app(PaymentProviderManager::class)->createPayment($payment);
            $this->fail('Active run olmadan sandbox provider çağrılamamalıydı.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('active_run_missing', $exception->getMessage());
        }

        $this->assertNull($payment->fresh()->provider_reference);
    }

    public function test_actual_sandbox_callback_requires_scoped_effect_authority(): void
    {
        $this->readyScopedLocalUat();
        $payment = $this->scopedPayment('MISSING-RUN');

        try {
            app(TechnicalServicePaymentSettlementService::class)->markPaid($payment, ['fake_approved' => true]);
            $this->fail('Active run olmadan fake callback işlenmemeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('active_run_missing', $exception->getMessage());
        }

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_allowlisted_email_can_be_atomically_claimed_once_and_quota_is_enforced_before_transport(): void
    {
        ['settings' => $settings, 'run_id' => $runId] = $this->startScopedLocalUat();
        Mail::fake();
        $first = $this->scopedPayment($runId);
        $second = $this->scopedPayment($runId);

        $this->sendScopedPaymentMail($first);
        Mail::assertSent(TechnicalServicePaymentAuditMail::class, 1);

        try {
            $this->sendScopedPaymentMail($second);
            $this->fail('E-posta kotası dolduktan sonra ikinci transport çağrısı olmamalıydı.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('quota_exceeded', $exception->getMessage());
        }

        Mail::assertSent(TechnicalServicePaymentAuditMail::class, 1);
        $history = $this->effectHistory();
        $this->assertCount(1, $history);
        $this->assertSame('completed', $history[0]['status']);
        $this->assertSame('email', $history[0]['channel']);
        $this->assertNull(data_get($this->persistedLifecycleSettings(), 'scoped_local_uat_active_effect_claim'));
    }

    public function test_total_messaging_limit_is_enforced_across_channels(): void
    {
        ['settings' => $settings, 'run_id' => $runId] = $this->startScopedLocalUat();
        $this->seedMessagingAttemptHistory($runId, ['whatsapp', 'whatsapp', 'whatsapp', 'whatsapp', 'sms', 'sms', 'sms']);
        Mail::fake();

        $this->sendScopedPaymentMail($this->scopedPayment($runId));
        $dispatch = $this->scopedDispatch($settings);

        try {
            $settings->openManualE2ESendWindow($runId, (int) $dispatch->id);
            $this->fail('Toplam sekiz messaging attempt sonrasında yeni dispatch açılamamalıydı.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('üst sınırı', $exception->getMessage());
        }

        Mail::assertSent(TechnicalServicePaymentAuditMail::class, 1);
    }

    public function test_concurrent_email_claims_allow_only_one(): void
    {
        ['settings' => $settings, 'run_id' => $runId] = $this->startScopedLocalUat();
        $first = $this->scopedPayment($runId);
        $second = $this->scopedPayment($runId);
        $claim = $settings->claimScopedLocalUatEmailEffect($first, [self::PAYMENT_EMAIL]);

        try {
            $settings->claimScopedLocalUatEmailEffect($second, [self::PAYMENT_EMAIL]);
            $this->fail('Aktif atomik claim varken ikinci e-posta claim alınmamalıydı.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('claim_busy', $exception->getMessage());
        } finally {
            $settings->failScopedLocalUatEffect((string) $claim['claim_nonce']);
        }

        $this->assertCount(1, $this->effectHistory());
    }

    public function test_non_allowlisted_email_and_wrong_template_fail_before_transport(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        Mail::fake();
        $payment = $this->scopedPayment($runId);

        try {
            app(TechnicalServiceMailTransportSettingsService::class)->sendPaymentAuditMail(
                ['outside@example.test'],
                new TechnicalServicePaymentAuditMail($payment, ['mrn' => $payment->technicalServiceRequest?->mrn]),
            );
            $this->fail('Allowlist dışı e-posta transporta ulaşmamalıydı.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('not_allowlisted', $exception->getMessage());
        }

        try {
            app(TechnicalServiceMailTransportSettingsService::class)->sendTestMail(self::PAYMENT_EMAIL);
            $this->fail('Scoped run sırasında test template gönderilememeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('UNAUTHORIZED_CAPABILITY', $exception->getMessage());
        }

        Mail::assertNothingSent();
    }

    public function test_smtp_payment_and_mikro_settings_are_immutable_during_active_run(): void
    {
        ['settings' => $settings] = $this->startScopedLocalUat();

        foreach ([
            fn () => app(TechnicalServiceMailTransportSettingsService::class)->saveOutgoing([
                'enabled' => true,
                'host' => 'changed.example.test',
                'port' => 465,
                'encryption' => 'ssl',
            ]),
            fn () => app(TechnicalServicePaymentProviderSettingsService::class)->update(['provider_mode' => 'live']),
            fn () => app(TechnicalServicePaymentProviderCredentialService::class)->saveIyzicoCredentials('sandbox', 'changed-key', 'changed-secret'),
            fn () => $settings->update(['mikro_api' => ['enabled' => true]]),
            fn () => $settings->saveMikroApiCredentials(['api_key' => 'changed-mikro-key']),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Active-run immutable setting değiştirilememeliydi.');
            } catch (ConflictHttpException $exception) {
                $this->assertStringContainsString('aktif', mb_strtolower($exception->getMessage()));
            }
        }
    }

    public function test_effect_boundary_detects_smtp_fingerprint_drift(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        Mail::fake();
        $payment = $this->scopedPayment($runId);
        $profile = MailTransportProfile::query()->firstOrFail();
        $profile->forceFill(['smtp_host' => 'drifted.example.test'])->save();

        try {
            $this->sendScopedPaymentMail($payment);
            $this->fail('SMTP fingerprint drift transporttan önce reddedilmeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('snapshot', mb_strtolower($exception->getMessage()));
        }
        Mail::assertNothingSent();

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_changed_payment_provider_or_origin_invalidates_active_run_before_provider(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $this->mock(IyzicoPaymentProvider::class, fn ($mock) => $mock->shouldNotReceive('createPayment'));
        $payment = $this->scopedPayment($runId);
        $page = PageConfig::query()
            ->where('page_code', TechnicalServicePaymentProviderSettingsService::PAGE_CODE)
            ->firstOrFail();
        $layout = (array) $page->layout_json;
        Arr::set($layout, TechnicalServicePaymentProviderSettingsService::REAL_PROVIDER_ENABLED_KEY, true);
        Arr::set($layout, TechnicalServicePaymentProviderSettingsService::PROVIDER_KEY, 'iyzico');
        Arr::set($layout, TechnicalServicePaymentProviderSettingsService::PROVIDER_MODE_KEY, 'live');
        $page->forceFill(['layout_json' => $layout])->save();
        config(['services.public_urls.payment_base_url' => 'http://10.0.28.65:8000']);

        try {
            app(PaymentProviderManager::class)->createPayment($payment);
            $this->fail('Payment config/origin drift real providera ulaşmamalıydı.');
        } catch (ConflictHttpException $exception) {
            $this->assertMatchesRegularExpression('/snapshot|capability|configuration/i', $exception->getMessage());
        }

        $this->assertNull($payment->fresh()->provider_reference);
    }

    public function test_effect_boundary_rejects_mikro_setting_drift(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $this->mutateLifecycleSettings(function (array $settings): array {
            $settings['mikro_api']['enabled'] = true;

            return $settings;
        });
        Mail::fake();

        try {
            $this->sendScopedPaymentMail($this->scopedPayment($runId));
            $this->fail('Mikro invariant drift SMTP effectinden önce reddedilmeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertMatchesRegularExpression('/snapshot|mikro/i', $exception->getMessage());
        }

        Mail::assertNothingSent();
    }

    public function test_sandbox_session_uses_deterministic_idempotency_and_duplicate_creates_one_session(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $provider = $this->partialMock(FakePaymentProvider::class, function ($mock): void {
            $mock->shouldReceive('createPayment')->once()->passthru();
        });
        $this->app->instance(FakePaymentProvider::class, $provider);
        $payment = $this->scopedPayment($runId);

        $first = app(PaymentProviderManager::class)->createPayment($payment);
        $second = app(PaymentProviderManager::class)->createPayment($payment->fresh());

        $this->assertSame($first, $second);
        $this->assertNotNull($payment->fresh()->provider_reference);
        $creates = collect($this->effectHistory())->where('operation', TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CREATE);
        $this->assertCount(1, $creates);
        $this->assertSame('completed', $creates->first()['status']);
    }

    public function test_duplicate_callback_produces_one_paid_transition(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $payment = $this->scopedPayment($runId);
        app(PaymentProviderManager::class)->createPayment($payment);

        $first = app(TechnicalServicePaymentSettlementService::class)->markPaid($payment->fresh(), ['fake_approved' => true]);
        $second = app(TechnicalServicePaymentSettlementService::class)->markPaid($payment->fresh(), ['fake_approved' => true]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $first->status);
        $this->assertSame($first->paid_at?->toIso8601String(), $second->paid_at?->toIso8601String());
        $this->assertSame(1, $payment->technicalServiceRequest?->events()->where('event_type', 'mount_payment_paid')->count());
        $callbacks = collect($this->effectHistory())->where('operation', TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CALLBACK);
        $this->assertCount(1, $callbacks);
    }

    public function test_wrong_expired_or_non_synthetic_payment_fails_before_provider(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $this->mock(FakePaymentProvider::class, fn ($mock) => $mock->shouldNotReceive('createPayment'));

        foreach ([
            $this->scopedPayment('WRONG-RUN'),
            $this->scopedPayment($runId, false),
        ] as $payment) {
            try {
                app(PaymentProviderManager::class)->createPayment($payment);
                $this->fail('Yanlış run veya non-synthetic payment providera ulaşmamalıydı.');
            } catch (ConflictHttpException) {
                $this->assertNull($payment->fresh()->provider_reference);
            }
        }

        $this->travel(3601)->seconds();
        $expired = $this->scopedPayment($runId);
        try {
            app(PaymentProviderManager::class)->createPayment($expired);
            $this->fail('Expired run providera ulaşmamalıydı.');
        } catch (ConflictHttpException) {
            $this->assertNull($expired->fresh()->provider_reference);
        }
    }

    public function test_failed_transport_preserves_attempt_history_without_duplicate_send(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $payment = $this->scopedPayment($runId);
        $mailer = \Mockery::mock();
        $mailer->shouldReceive('to')->once()->andReturnSelf();
        $mailer->shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic SMTP failure'));
        Mail::shouldReceive('forgetMailers')->once();
        Mail::shouldReceive('mailer')->once()->with('technical_service_smtp')->andReturn($mailer);

        try {
            $this->sendScopedPaymentMail($payment);
            $this->fail('Synthetic SMTP exception bekleniyordu.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic SMTP failure', $exception->getMessage());
        }

        $history = $this->effectHistory();
        $this->assertCount(1, $history);
        $this->assertSame('failed', $history[0]['status']);
        $this->assertTrue($history[0]['replay_blocked']);

        try {
            $this->sendScopedPaymentMail($payment->fresh());
            $this->fail('Failed attempt kör retry ile tekrar gönderilmemeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('replay_blocked', $exception->getMessage());
        }
    }

    public function test_freeze_stops_email_and_sandbox_payment_effects(): void
    {
        ['settings' => $settings, 'run_id' => $runId] = $this->startScopedLocalUat();
        $claimedPayment = $this->scopedPayment($runId);
        $mailPayment = $this->scopedPayment($runId);
        $providerPayment = $this->scopedPayment($runId);
        $settings->claimScopedLocalUatEmailEffect($claimedPayment, [self::PAYMENT_EMAIL]);
        $settings->freezeManualE2E();
        Mail::fake();
        $this->mock(FakePaymentProvider::class, fn ($mock) => $mock->shouldNotReceive('createPayment'));

        foreach ([
            fn () => $this->sendScopedPaymentMail($mailPayment),
            fn () => app(PaymentProviderManager::class)->createPayment($providerPayment),
        ] as $effect) {
            try {
                $effect();
                $this->fail('Frozen run effect üretmemeliydi.');
            } catch (ConflictHttpException $exception) {
                $this->assertStringContainsString('active_run_missing', $exception->getMessage());
            }
        }

        Mail::assertNothingSent();
        $this->assertTrue((bool) data_get($settings->payload(), 'global.queue_paused'));
        $this->assertNull(data_get($claimedPayment->fresh()->raw_payload, 'scoped_local_uat_effect_claim'));
        $this->assertSame(
            'frozen_unresolved',
            data_get($claimedPayment->fresh()->raw_payload, 'scoped_local_uat_effect_history.0.status'),
        );
        $this->assertTrue((bool) data_get(
            $claimedPayment->fresh()->raw_payload,
            'scoped_local_uat_effect_history.0.replay_blocked',
        ));
    }

    /**
     * @return array{admin:User,settings:TechnicalServiceMessagingSettingsService,run_id:string}
     */
    private function startScopedLocalUat(): array
    {
        $ready = $this->readyScopedLocalUat();
        $prepared = $ready['settings']->prepareManualE2E();

        return [
            ...$ready,
            'run_id' => (string) data_get($prepared, 'manual_e2e.active_run_id'),
        ];
    }

    private function scopedPayment(string $runId, bool $synthetic = true): TechnicalServiceMountPayment
    {
        $serial = 'SCOPED-PAY-'.strtoupper(substr(hash('sha256', uniqid('', true)), 0, 12));
        $request = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-SCOPED-PAY-'.strtoupper(substr(hash('sha256', uniqid('', true)), 0, 8)),
            'customer_name' => 'Scoped Payment Fixture',
            'customer_phone' => '05372081633',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Synthetic payment address',
            'product_name' => 'Synthetic payment product',
            'serial_number' => $serial,
            'service_type' => 'Montaj',
            'status' => 'Yeni',
        ]);
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => $serial,
            'product_name' => 'Synthetic payment product',
        ]);
        ['session' => $session] = TechnicalServiceMountSession::startForLink($link);
        $session->forceFill([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
        ])->save();
        $request->forceFill([
            'qr_link_id' => $link->id,
            'mount_session_id' => $session->id,
        ])->save();
        $payload = [
            'source' => 'scoped_local_uat_sandbox',
            'technical_service_request_id' => $request->id,
            'request_code' => $request->mrn,
        ];
        if ($synthetic) {
            $payload['scoped_local_uat'] = [
                'synthetic_uat' => true,
                'run_id' => $runId,
                'origin' => 'http://10.0.28.64:8000',
            ];
        }

        return TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 1.0,
            'currency' => 'TRY',
            'raw_payload' => $payload,
        ]);
    }

    private function sendScopedPaymentMail(TechnicalServiceMountPayment $payment): void
    {
        app(TechnicalServiceMailTransportSettingsService::class)->sendPaymentAuditMail(
            [self::PAYMENT_EMAIL],
            new TechnicalServicePaymentAuditMail($payment, [
                'mrn' => $payment->technicalServiceRequest?->mrn,
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'currency' => $payment->currency,
            ]),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function effectHistory(): array
    {
        return array_values((array) data_get(
            $this->persistedLifecycleSettings(),
            'scoped_local_uat_effect_history',
            [],
        ));
    }

    /**
     * @param  array<int, string>  $channels
     */
    private function seedMessagingAttemptHistory(string $runId, array $channels): void
    {
        $history = collect($channels)->map(fn (string $channel, int $index): array => [
            'id' => 'seed-'.$index,
            'run_id' => $runId,
            'channel' => $channel,
            'status' => 'closed',
            'attempted' => true,
        ])->all();
        $this->mutateLifecycleSettings(function (array $settings) use ($history): array {
            $settings['manual_e2e_window_history'] = $history;

            return $settings;
        });
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    private function mutateLifecycleSettings(callable $mutator): void
    {
        $page = PageConfig::query()
            ->where('page_code', TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE)
            ->firstOrFail();
        $layout = (array) $page->layout_json;
        $settings = (array) data_get($layout, TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY, []);
        Arr::set($layout, TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY, $mutator($settings));
        $page->forceFill(['layout_json' => $layout])->save();
    }

    /**
     * @return array{admin:User,settings:TechnicalServiceMessagingSettingsService}
     */
    private function readyScopedLocalUat(): array
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->freezeManualE2E();
        $settings->update([
            'messaging_enabled' => true,
            'test_mode_enabled' => false,
            'shared_test_phone' => self::TECHNICIAN_PHONE,
            'ops_whatsapp_phone' => self::TECHNICIAN_PHONE,
            'manual_e2e_allowlisted_phones' => [self::CUSTOMER_PHONE, self::TECHNICIAN_PHONE],
            'manual_e2e_partner_portal_origin_enabled' => true,
            'manual_e2e_partner_portal_origin' => 'http://10.0.28.64:8000',
            'manual_e2e_ttl_seconds' => 14400,
            'hourly_limit' => 30,
            'daily_limit' => 200,
            'active_provider' => 'evo_whatsapp',
            'provider_key' => 'evo_whatsapp',
            'evo_whatsapp' => [
                'direct_api_enabled' => true,
                'direct_api_base_url' => 'https://evo-api.example.test',
                'direct_api_instance_name' => 'scoped-local-uat',
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
                'assignment_offer_technician' => $this->scopedMessagePolicy('whatsapp_and_sms'),
                'appointment_approved_customer' => $this->scopedMessagePolicy('whatsapp_and_sms'),
                'appointment_approved_technician' => $this->scopedMessagePolicy('whatsapp_and_sms'),
                'customer_approval_request' => $this->scopedMessagePolicy('whatsapp_only'),
            ],
            'mikro_api' => [
                'enabled' => false,
                'read_sync_enabled' => false,
                'write_enabled' => false,
            ],
        ]);

        foreach ([
            TechnicalServiceMessagingSettingsService::PAGE_CODE => TechnicalServiceMessagingSettingsService::ROOT_KEY,
            TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE => TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY,
        ] as $pageCode => $rootKey) {
            $page = PageConfig::query()->where('page_code', $pageCode)->firstOrFail();
            $layout = (array) $page->layout_json;
            Arr::set($layout, $rootKey.'.providers.evo_whatsapp', [
                'enabled' => true,
                'real_send_allowed' => true,
                'test_send_allowed' => true,
                'notes' => 'Fake scoped UAT provider.',
            ]);
            $page->forceFill(['layout_json' => $layout])->save();
        }

        $settings->saveEvoWhatsappCredentials(['api_key' => 'test-evo-key']);
        $settings->saveNacSmsCredentials(['username' => 'test-user', 'password' => 'test-password']);

        app(TechnicalServiceMailTransportSettingsService::class)->saveOutgoing([
            'enabled' => true,
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => self::PAYMENT_EMAIL,
            'password' => 'TEST_SMTP_PASSWORD_ONLY',
            'from_address' => 'no-reply@example.test',
            'from_name' => 'EMAKS Test',
        ], $admin);

        app(TechnicalServicePaymentProviderSettingsService::class)->update([
            'real_provider_enabled' => false,
            'provider_mode' => 'sandbox',
            'payment_notification_enabled' => true,
            'payment_notification_recipients' => self::PAYMENT_EMAIL,
        ]);

        $this->assertSame('local', app(ExternalExecutionControlPlaneService::class)->state()['operator_mode']);

        return ['admin' => $admin, 'settings' => $settings];
    }

    /**
     * @return array<string, mixed>
     */
    private function scopedMessagePolicy(string $channelPolicy): array
    {
        return [
            'enabled' => true,
            'real_send_allowed' => true,
            'test_send_allowed' => true,
            'channel_policy' => $channelPolicy,
            'whatsapp_mode' => 'test',
            'sms_mode' => $channelPolicy === 'whatsapp_only' ? 'disabled' : 'test',
            'whatsapp_provider' => 'evo_whatsapp',
            'sms_provider' => 'nac_sms',
        ];
    }

    private function assertScopedCapabilityMissing(string $capability): void
    {
        $readiness = app(ExternalExecutionControlPlaneService::class)->scopedLocalUatReadiness();

        $this->assertFalse($readiness['ready']);
        $this->assertContains($capability, $readiness['missing_capabilities']);
        $this->assertContains('scoped_capability_not_ready:'.$capability, collect($readiness['blockers'])->pluck('code')->all());
        Http::assertNothingSent();
    }

    /**
     * @return array<string, int>
     */
    private function businessCounts(): array
    {
        return [
            'requests' => TechnicalServiceRequest::query()->count(),
            'dispatches' => TechnicalServiceMessageDispatch::query()->count(),
            'payments' => TechnicalServiceMountPayment::query()->count(),
            'page_configs' => PageConfig::query()->count(),
            'mail_profiles' => MailTransportProfile::query()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedLifecycleSettings(): array
    {
        $layout = PageConfig::query()
            ->where('page_code', TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE)
            ->value('layout_json');

        return (array) data_get($layout, TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY, []);
    }

    private function scopedDispatch(
        TechnicalServiceMessagingSettingsService $settings,
        string $event = 'appointment_approved_customer',
        string $provider = 'evo_whatsapp',
        string $channel = 'whatsapp',
        string $phone = self::CUSTOMER_PHONE,
        string $role = 'customer',
        ?string $idempotencyKey = null,
    ): TechnicalServiceMessageDispatch {
        $request = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-SCOPED-UAT-'.strtoupper(substr(hash('sha256', uniqid('', true)), 0, 8)),
            'customer_name' => 'Scoped UAT Fixture',
            'customer_phone' => '05372081633',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Synthetic test address',
            'product_name' => 'Synthetic product',
            'service_type' => 'Montaj',
            'status' => 'Yeni',
        ]);
        $token = (string) $request->mrn;
        $body = 'EMAKS Prime '.$token.' appointment message http://10.0.28.64:8000/partner/job-card/test';
        $metadata = [
            ...$settings->executionModeSnapshot(),
            ...$settings->manualE2EContext()->dispatchMetadata($token, $phone, $role),
        ];

        return TechnicalServiceMessageDispatch::query()->create([
            'event' => $event,
            'technical_service_request_id' => $request->id,
            'request_id' => $request->id,
            'mrn' => $request->mrn,
            'message_type' => $event,
            'provider_key' => $provider,
            'channel' => $channel,
            'recipient_role' => $role,
            'target_type' => $role,
            'target_phone' => $phone,
            'original_phone' => $phone,
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
            'attempt_count' => 0,
            'max_attempts' => 1,
            'idempotency_key' => $idempotencyKey ?? hash('sha256', uniqid('scoped-window-', true)),
            'queued_at' => now(),
            'request_payload' => ['body' => $body],
            'metadata' => $metadata,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }
}
