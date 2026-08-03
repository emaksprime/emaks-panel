<?php

namespace Tests\Feature;

use App\Mail\TechnicalServicePaymentAuditMail;
use App\Models\MailTransportProfile;
use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
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
use App\Services\Payments\TechnicalServicePaymentProviderReconciliationService;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use App\Services\TechnicalService\TechnicalServicePartRequestService;
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

    public function test_local_allowlisted_uat_effect_window_is_code_owned_snapshot_bound_and_shared_with_worker(): void
    {
        Http::fake();
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $localContract = TechnicalServiceMessagingSettingsService::manualE2EEffectWindowContract(
            ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE,
        );
        $defaultContract = TechnicalServiceMessagingSettingsService::manualE2EEffectWindowContract(null);

        $this->assertSame(900, $localContract['effect_window_seconds']);
        $this->assertSame(30, $defaultContract['effect_window_seconds']);
        $this->assertNull(TechnicalServiceMessagingSettingsService::resolvedManualE2EEffectWindowContract([
            'scoped_local_uat_profile_id' => 'unknown-profile',
            'effect_window_seconds' => 900,
            'effect_window_fingerprint' => $localContract['effect_window_fingerprint'],
        ]));

        $prepared = $settings->prepareManualE2E();
        $stored = $this->persistedLifecycleSettings();
        $snapshot = (array) ($stored['manual_e2e_run_snapshot'] ?? []);
        $state = $settings->scopedLocalUatControlPlaneState(false);

        $this->assertSame(3600, $stored['manual_e2e_ttl_seconds']);
        $this->assertSame(900, $snapshot['effect_window_seconds']);
        $this->assertSame($localContract['effect_window_fingerprint'], $snapshot['effect_window_fingerprint']);
        $this->assertSame(900, $state['effect_window_seconds']);
        $this->assertSame($localContract['effect_window_fingerprint'], $state['effect_window_fingerprint']);
        $this->assertTrue(app(ExternalExecutionControlPlaneService::class)->messagingRunSnapshotIsCurrent($snapshot));

        $dispatch = $this->scopedDispatch($settings);
        $settings->openManualE2ESendWindow(
            (string) data_get($prepared, 'manual_e2e.active_run_id'),
            $dispatch->id,
        );
        $stored = $this->persistedLifecycleSettings();
        $window = (array) ($stored['manual_e2e_open_window'] ?? []);
        $workerCommand = (string) data_get($settings->payload(), 'manual_e2e.worker_command');

        $this->assertSame(900, $window['effect_window_seconds']);
        $this->assertSame($localContract['effect_window_fingerprint'], $window['effect_window_fingerprint']);
        $this->assertSame(900, (int) Carbon::parse($window['opened_at'])->diffInSeconds(Carbon::parse($window['expires_at'])));
        $this->assertStringContainsString('--max-seconds=900', $workerCommand);
        Http::assertNothingSent();
    }

    public function test_local_uat_effect_window_request_overrides_and_snapshot_drift_fail_closed(): void
    {
        Http::fake();
        ['settings' => $settings] = $this->readyScopedLocalUat();

        foreach (['effect_window_seconds', 'claim_window_seconds', 'ttl', 'expires_in', 'manual_window', 'authorization_window'] as $field) {
            foreach ([5, 30, 900, 3600] as $value) {
                try {
                    $settings->prepareManualE2E([$field => $value]);
                    $this->fail("{$field} request override reddedilmeliydi.");
                } catch (ValidationException) {
                    $this->assertNull($settings->manualE2EContext()->activeRunId());
                }
            }
        }

        $prepared = $settings->prepareManualE2E();
        $dispatch = $this->scopedDispatch($settings);
        $this->mutateLifecycleSettings(function (array $current): array {
            $snapshot = (array) ($current['manual_e2e_run_snapshot'] ?? []);
            $snapshot['effect_window_seconds'] = 30;
            $current['manual_e2e_run_snapshot'] = $snapshot;

            return $current;
        });

        try {
            $settings->openManualE2ESendWindow(
                (string) data_get($prepared, 'manual_e2e.active_run_id'),
                $dispatch->id,
            );
            $this->fail('Mutasyona uğramış effect-window snapshotı reddedilmeliydi.');
        } catch (ConflictHttpException|ValidationException) {
            $dispatch->refresh();
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
            $this->assertSame(0, $dispatch->attempt_count);
        }
        Http::assertNothingSent();
    }

    public function test_local_uat_claim_passes_at_899_seconds(): void
    {
        Http::fake();
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $prepared = $settings->prepareManualE2E();
        $dispatch = $this->scopedDispatch($settings);
        $runId = (string) data_get($prepared, 'manual_e2e.active_run_id');
        $settings->openManualE2ESendWindow($runId, $dispatch->id);

        $this->travel(899)->seconds();
        $claim = $settings->claimManualE2ESend($dispatch->id, $runId);

        $this->assertSame($runId, $claim['run_id']);
        $this->assertSame($dispatch->id, $claim['dispatch_id']);
        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $dispatch->status);
        $this->assertSame(1, $dispatch->attempt_count);
        Http::assertNothingSent();
    }

    public function test_local_uat_claim_rejects_at_900_seconds_without_effect(): void
    {
        Http::fake();
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $prepared = $settings->prepareManualE2E();
        $dispatch = $this->scopedDispatch($settings);
        $runId = (string) data_get($prepared, 'manual_e2e.active_run_id');
        $settings->openManualE2ESendWindow($runId, $dispatch->id);

        $this->travel(900)->seconds();

        try {
            $settings->claimManualE2ESend($dispatch->id, $runId);
            $this->fail('Local UAT effect-window tam 900 saniyede kapanmalıydı.');
        } catch (ConflictHttpException) {
            $dispatch->refresh();
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
            $this->assertSame(0, $dispatch->attempt_count);
        }
        Http::assertNothingSent();
    }

    public function test_same_scoped_dispatch_window_can_be_claimed_only_once(): void
    {
        Http::fake();
        ['settings' => $settings] = $this->readyScopedLocalUat();
        $prepared = $settings->prepareManualE2E();
        $dispatch = $this->scopedDispatch($settings);
        $runId = (string) data_get($prepared, 'manual_e2e.active_run_id');
        $settings->openManualE2ESendWindow($runId, $dispatch->id);

        $first = $settings->claimManualE2ESend($dispatch->id, $runId);
        $this->assertSame($dispatch->id, $first['dispatch_id']);

        try {
            $settings->claimManualE2ESend($dispatch->id, $runId);
            $this->fail('Aynı dispatch window ikinci kez claim edilememeliydi.');
        } catch (ConflictHttpException) {
            $dispatch->refresh();
            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENDING, $dispatch->status);
            $this->assertSame(1, $dispatch->attempt_count);
        }
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
        $this->assertStringContainsString('claimScopedLocalUatSandboxPaymentCallbackEffect', $settlement);
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
        $payload = is_array($payment->fresh()->raw_payload) ? $payment->fresh()->raw_payload : [];
        $history = (array) ($payload['scoped_local_uat_effect_history'] ?? []);
        foreach (range(1, 25) as $index) {
            $history[] = [
                'run_id' => $runId,
                'operation' => 'unrelated-'.$index,
                'status' => 'completed',
                'idempotency_hash' => hash('sha256', 'unrelated-pending-reuse-'.$index),
            ];
        }
        $payload['scoped_local_uat_effect_history'] = $history;
        $payment->forceFill(['raw_payload' => $payload])->save();
        $second = app(PaymentProviderManager::class)->createPayment($payment->fresh());

        $this->assertSame(PaymentProviderManager::CREATE_OUTCOME_NEW_PENDING, $first['outcome']);
        $this->assertSame(PaymentProviderManager::CREATE_OUTCOME_REUSED_PENDING, $second['outcome']);
        $this->assertSame(Arr::except($first, 'outcome'), Arr::except($second, 'outcome'));
        $this->assertNotNull($payment->fresh()->provider_reference);
        $creates = collect($this->effectHistory())->where('operation', TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CREATE);
        $this->assertCount(1, $creates);
        $this->assertSame('completed', $creates->first()['status']);
        $this->assertGreaterThan(20, count((array) data_get(
            $payment->fresh()->raw_payload,
            'scoped_local_uat_effect_history',
            [],
        )));
    }

    public function test_pending_reuse_requires_exact_successful_create_history_authority(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $mutateHistory = static function (array $payload, callable $mutation): array {
            $history = (array) ($payload['scoped_local_uat_effect_history'] ?? []);
            foreach ($history as $index => $entry) {
                if (is_array($entry)
                    && ($entry['operation'] ?? null) === TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CREATE) {
                    $history[$index] = $mutation($entry);
                    $payload['scoped_local_uat_effect_history'] = $history;

                    return $payload;
                }
            }

            throw new \RuntimeException('Synthetic successful create history fixture bulunamadı.');
        };
        $cases = [
            'completed_metadata_without_history' => static function (array $payload): array {
                unset($payload['scoped_local_uat_effect_history'], $payload['scoped_local_uat_effect_claim']);

                return $payload;
            },
            'foreign_payment_history' => static fn (array $payload, TechnicalServiceMountPayment $canonical, TechnicalServiceMountPayment $duplicate): array => $mutateHistory(
                $payload,
                static fn (array $entry): array => [...$entry, 'payment_id' => (int) $duplicate->getKey()],
            ),
            'operation_mismatch' => static fn (array $payload): array => $mutateHistory(
                $payload,
                static fn (array $entry): array => [...$entry, 'operation' => 'sandbox_payment_other'],
            ),
            'business_fingerprint_mismatch' => static fn (array $payload): array => $mutateHistory(
                $payload,
                static fn (array $entry): array => [...$entry, 'business_identity_hash' => hash('sha256', 'wrong-business')],
            ),
            'idempotency_mismatch' => static fn (array $payload): array => $mutateHistory(
                $payload,
                static fn (array $entry): array => [...$entry, 'idempotency_hash' => hash('sha256', 'wrong-idempotency')],
            ),
            'provider_mismatch' => static fn (array $payload): array => $mutateHistory(
                $payload,
                static fn (array $entry): array => [...$entry, 'provider' => 'iyzico_sandbox'],
            ),
            'provider_reference_mismatch' => static fn (array $payload): array => $mutateHistory(
                $payload,
                static fn (array $entry): array => [...$entry, 'provider_reference_hash' => hash('sha256', 'wrong-reference')],
            ),
            'amount_mismatch' => static fn (array $payload): array => $mutateHistory(
                $payload,
                static fn (array $entry): array => [...$entry, 'amount_minor' => '999'],
            ),
            'currency_mismatch' => static fn (array $payload): array => $mutateHistory(
                $payload,
                static fn (array $entry): array => [...$entry, 'currency' => 'USD'],
            ),
            'run_mismatch' => static fn (array $payload): array => $mutateHistory(
                $payload,
                static fn (array $entry): array => [...$entry, 'run_id' => 'LOCAL-UAT-WRONG-RUN'],
            ),
            'profile_mismatch' => static fn (array $payload): array => $mutateHistory(
                $payload,
                static fn (array $entry): array => [...$entry, 'profile_id' => 'WRONG_PROFILE'],
            ),
            'failed_history_without_retry_authority' => static fn (array $payload): array => $mutateHistory(
                $payload,
                static fn (array $entry): array => [...$entry, 'status' => 'failed', 'outcome' => 'failed_no_retry'],
            ),
        ];
        $provider = $this->partialMock(FakePaymentProvider::class, function ($mock) use ($cases): void {
            $mock->shouldReceive('createPayment')->times(count($cases))->passthru();
        });
        $this->app->instance(FakePaymentProvider::class, $provider);
        $manager = app(PaymentProviderManager::class);

        foreach ($cases as $case => $mutation) {
            $canonical = $this->scopedPayment($runId);
            $duplicate = $this->duplicateScopedPayment($canonical);
            $created = $manager->createPayment($canonical);
            $this->assertSame(PaymentProviderManager::CREATE_OUTCOME_NEW_PENDING, $created['outcome'], $case);
            $canonical = $canonical->fresh();
            $payload = $mutation(
                is_array($canonical->raw_payload) ? $canonical->raw_payload : [],
                $canonical,
                $duplicate,
            );
            $canonical->forceFill(['raw_payload' => $payload])->save();

            $result = null;
            try {
                $result = $manager->createPayment($duplicate);
                $this->fail($case.' exact successful history olmadan reused_pending üretmemeliydi.');
            } catch (ConflictHttpException $exception) {
                $this->assertMatchesRegularExpression(
                    '/UNSAFE_PENDING_NOT_REUSABLE|scoped_uat_effect_replay_blocked/',
                    $exception->getMessage(),
                    $case,
                );
            }

            $this->assertNull($result, $case);
            $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $duplicate->fresh()->status, $case);
            $this->assertNull($duplicate->fresh()->provider_reference, $case);
            $this->assertNull($duplicate->fresh()->payment_url, $case);
            $this->assertNull(data_get(
                $duplicate->fresh()->raw_payload,
                'scoped_local_uat_duplicate_payment',
            ), $case);
            $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $canonical->fresh()->status, $case);
        }
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

    public function test_freeze_wins_after_claim_and_before_actual_email_transport(): void
    {
        ['settings' => $settings, 'run_id' => $runId] = $this->startScopedLocalUat();
        Mail::fake();
        $payment = $this->scopedPayment($runId);
        $mail = new class($payment, ['mrn' => $payment->technicalServiceRequest?->mrn, 'amount' => number_format((float) $payment->amount, 2, '.', ''), 'currency' => $payment->currency], $settings) extends TechnicalServicePaymentAuditMail
        {
            public function __construct(
                TechnicalServiceMountPayment $payment,
                array $details,
                private readonly TechnicalServiceMessagingSettingsService $settings,
            ) {
                parent::__construct($payment, $details);
            }

            public function build(): self
            {
                $this->settings->freezeManualE2E();

                return parent::build();
            }
        };

        try {
            app(TechnicalServiceMailTransportSettingsService::class)->sendPaymentAuditMail(
                [self::PAYMENT_EMAIL],
                $mail,
            );
            $this->fail('Freeze final dispatch linearizationdan önce kazanırsa SMTP transport çağrılmamalıydı.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('frozen_before_dispatch', $exception->getMessage());
        }

        Mail::assertNothingSent();
        $this->assertSame('frozen_unresolved', data_get(
            $payment->fresh()->raw_payload,
            'scoped_local_uat_effect_history.0.status',
        ));
        $this->assertNull(data_get($payment->fresh()->raw_payload, 'scoped_local_uat_effect_claim'));
    }

    public function test_callback_without_successful_create_session_history_is_rejected(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $payment = $this->scopedPayment($runId);

        try {
            app(TechnicalServicePaymentSettlementService::class)->markPaid($payment, ['fake_approved' => true]);
            $this->fail('Stored create/session authority olmadan callback paid geçişi yapmamalıydı.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('session_authority_missing', $exception->getMessage());
        }

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paid_at);
        $this->assertSame(0, $payment->technicalServiceRequest?->events()->where('event_type', 'mount_payment_paid')->count());
        $callback = collect(data_get($payment->fresh()->raw_payload, 'scoped_local_uat_effect_history', []))
            ->firstWhere('operation', TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CALLBACK);
        $this->assertSame('failed', $callback['status'] ?? null);
    }

    public function test_duplicate_business_payment_rows_create_one_provider_session(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $provider = $this->partialMock(FakePaymentProvider::class, function ($mock): void {
            $mock->shouldReceive('createPayment')->once()->passthru();
        });
        $this->app->instance(FakePaymentProvider::class, $provider);
        $first = $this->scopedPayment($runId);
        $second = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $first->technical_service_mount_session_id,
            'technical_service_request_id' => $first->technical_service_request_id,
            'provider' => 'fake',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => $first->amount,
            'currency' => $first->currency,
            'raw_payload' => $first->raw_payload,
        ]);

        $firstResponse = app(PaymentProviderManager::class)->createPayment($first);
        $secondResponse = app(PaymentProviderManager::class)->createPayment($second);

        $this->assertSame(PaymentProviderManager::CREATE_OUTCOME_NEW_PENDING, $firstResponse['outcome']);
        $this->assertSame(PaymentProviderManager::CREATE_OUTCOME_REUSED_PENDING, $secondResponse['outcome']);
        $this->assertSame(Arr::except($firstResponse, 'outcome'), Arr::except($secondResponse, 'outcome'));
        $this->assertSame($first->id, $secondResponse['payment_id']);
        $this->assertNotNull($first->fresh()->provider_reference);
        $this->assertNull($second->fresh()->provider_reference);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_CANCELLED, $second->fresh()->status);
        $this->assertSame($first->id, data_get(
            $second->fresh()->raw_payload,
            'scoped_local_uat_duplicate_payment.canonical_payment_id',
        ));
        $creates = collect($this->effectHistory())
            ->where('operation', TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CREATE);
        $this->assertCount(1, $creates);
        $this->assertStringNotContainsString('payment:'.$first->id, (string) $creates->first()['idempotency_hash']);
        $this->assertStringNotContainsString('payment:'.$second->id, (string) $creates->first()['idempotency_hash']);
    }

    public function test_callback_provider_must_match_stored_session_and_run_snapshot(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        Mail::fake();
        $payment = $this->scopedPayment($runId);
        app(PaymentProviderManager::class)->createPayment($payment);

        try {
            app(TechnicalServicePaymentSettlementService::class)->markPaid($payment->fresh(), [
                'fake_approved' => true,
                'provider' => 'iyzico',
                'provider_mode' => 'sandbox',
            ]);
            $this->fail('Request provider stored fake_payment authority yerine geçmemeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('provider_mode_mismatch', $exception->getMessage());
        }

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paid_at);
        $this->assertSame(0, $payment->technicalServiceRequest?->events()->where('event_type', 'mount_payment_paid')->count());
        Mail::assertNothingSent();
    }

    public function test_payment_create_provider_must_match_immutable_run_snapshot(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $this->mock(FakePaymentProvider::class, fn ($mock) => $mock->shouldNotReceive('createPayment'));
        $payment = $this->scopedPayment($runId);
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $payload['provider_mode'] = 'sandbox';
        $payment->forceFill([
            'provider' => 'iyzico',
            'raw_payload' => $payload,
        ])->save();

        try {
            app(PaymentProviderManager::class)->createPayment($payment->fresh());
            $this->fail('Payment row providerı immutable fake_payment run snapshotını değiştirmemeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('provider_snapshot_mismatch', $exception->getMessage());
        }

        $this->assertNull($payment->fresh()->provider_reference);
    }

    public function test_callback_run_authority_cannot_be_replaced_by_request_payload(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        Mail::fake();
        $payment = $this->scopedPayment($runId);
        app(PaymentProviderManager::class)->createPayment($payment);

        try {
            app(TechnicalServicePaymentSettlementService::class)->markPaid($payment->fresh(), [
                'fake_approved' => true,
                'run_id' => 'LOCAL-UAT-WRONG-RUN',
            ]);
            $this->fail('Request run_id stored session authority yerine geçmemeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('session_authority_mismatch', $exception->getMessage());
        }

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paid_at);
        $this->assertSame(0, $payment->technicalServiceRequest?->events()->where('event_type', 'mount_payment_paid')->count());
        Mail::assertNothingSent();
    }

    public function test_callback_amount_currency_and_frozen_run_are_fail_closed(): void
    {
        ['settings' => $settings, 'run_id' => $runId] = $this->startScopedLocalUat();
        $payment = $this->scopedPayment($runId);
        app(PaymentProviderManager::class)->createPayment($payment);

        try {
            app(TechnicalServicePaymentSettlementService::class)->markPaid($payment->fresh(), [
                'fake_approved' => true,
                'amount' => 2,
                'currency' => 'USD',
            ]);
            $this->fail('Callback amount/currency stored session authority ile eşleşmeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('session_authority_mismatch', $exception->getMessage());
        }
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->fresh()->status);

        $settings->freezeManualE2E();
        try {
            app(TechnicalServicePaymentSettlementService::class)->markPaid($payment->fresh(), ['fake_approved' => true]);
            $this->fail('Frozen run callback paid geçişi yapmamalıydı.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('active_run_missing', $exception->getMessage());
        }
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->fresh()->status);
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

    public function test_all_production_payment_create_callers_use_canonical_manager_result(): void
    {
        foreach ([
            app_path('Http/Controllers/PublicMountRequestController.php'),
            app_path('Http/Controllers/Api/TechnicalServiceController.php'),
            app_path('Services/TechnicalService/TechnicalServicePartRequestService.php'),
        ] as $path) {
            $source = File::get($path);

            $this->assertMatchesRegularExpression('/\$createResult\s*=.*createPayment\(\$payment\);/s', $source, $path);
            $this->assertMatchesRegularExpression(
                '/\$payment\s*=.*canonicalPaymentFromCreateResult\(\$createResult\);/s',
                $source,
                $path,
            );
            $this->assertMatchesRegularExpression('/createOutcome\(\$createResult\)/', $source, $path);
        }
    }

    public function test_part_request_identity_prevents_distinct_obligations_from_colliding(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $provider = $this->partialMock(FakePaymentProvider::class, function ($mock): void {
            $mock->shouldReceive('createPayment')->twice()->passthru();
        });
        $this->app->instance(FakePaymentProvider::class, $provider);
        $first = $this->scopedPayment($runId, true, [
            'source' => 'operation_customer_charge',
            'purpose' => 'part_payment',
            'charge_type' => 'part_payment',
        ]);
        $firstPart = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $first->technical_service_request_id,
            'root_request_id' => $first->technical_service_request_id,
            'status' => TechnicalServicePartRequest::STATUS_OPS_REVIEW,
            'part_name' => 'Scoped part one',
        ]);
        $secondPart = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $first->technical_service_request_id,
            'root_request_id' => $first->technical_service_request_id,
            'status' => TechnicalServicePartRequest::STATUS_OPS_REVIEW,
            'part_name' => 'Scoped part two',
        ]);
        $firstPayload = is_array($first->raw_payload) ? $first->raw_payload : [];
        $firstPayload['part_request_id'] = $firstPart->id;
        $first->forceFill(['raw_payload' => $firstPayload])->save();
        $second = $this->duplicateScopedPayment($first, ['part_request_id' => $secondPart->id]);

        $firstResult = app(PaymentProviderManager::class)->createPayment($first);
        $secondResult = app(PaymentProviderManager::class)->createPayment($second);

        $this->assertNotSame($firstResult['payment_id'], $secondResult['payment_id']);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $second->fresh()->status);
    }

    public function test_serial_set_identity_is_sorted_stable_and_distinguishes_different_sets(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $provider = $this->partialMock(FakePaymentProvider::class, function ($mock): void {
            $mock->shouldReceive('createPayment')->twice()->passthru();
        });
        $this->app->instance(FakePaymentProvider::class, $provider);
        $first = $this->scopedPayment($runId, true, [
            'source' => 'operation_extra_mount_fee',
            'purpose' => 'multi_product_mount',
            'charge_type' => 'multi_product_mount',
        ]);
        $serials = collect(['SERIAL-ONE', 'SERIAL-TWO', 'SERIAL-THREE'])
            ->map(fn (string $serial): TechnicalServiceRequestSerial => TechnicalServiceRequestSerial::query()->create([
                'technical_service_request_id' => $first->technical_service_request_id,
                'mrn' => $first->technicalServiceRequest?->mrn,
                'serial_number' => $serial,
            ]));
        $firstSerialIds = [$serials[0]->id, $serials[1]->id];
        $firstPayload = is_array($first->raw_payload) ? $first->raw_payload : [];
        $firstPayload['selected_serial_ids'] = $firstSerialIds;
        $first->forceFill(['raw_payload' => $firstPayload])->save();
        $reordered = $this->duplicateScopedPayment($first, ['selected_serial_ids' => array_reverse($firstSerialIds)]);
        $different = $this->duplicateScopedPayment($first, ['selected_serial_ids' => [$serials[0]->id, $serials[2]->id]]);

        $firstResult = app(PaymentProviderManager::class)->createPayment($first);
        $reorderedResult = app(PaymentProviderManager::class)->createPayment($reordered);
        $differentResult = app(PaymentProviderManager::class)->createPayment($different);

        $this->assertSame($firstResult['payment_id'], $reorderedResult['payment_id']);
        $this->assertNotSame($firstResult['payment_id'], $differentResult['payment_id']);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_CANCELLED, $reordered->fresh()->status);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $different->fresh()->status);
    }

    public function test_scope_specific_payment_identity_fields_fail_closed_before_provider(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $this->mock(FakePaymentProvider::class, fn ($mock) => $mock->shouldNotReceive('createPayment'));

        foreach ([
            $this->scopedPayment($runId, true, [
                'source' => 'operation_customer_charge',
                'purpose' => 'part_payment',
                'charge_type' => 'part_payment',
            ]),
            $this->scopedPayment($runId, true, [
                'source' => 'operation_extra_mount_fee',
                'purpose' => 'multi_product_mount',
                'charge_type' => 'multi_product_mount',
            ]),
        ] as $payment) {
            try {
                app(PaymentProviderManager::class)->createPayment($payment);
                $this->fail('Eksik scope-specific payment identity providera ulaşmamalıydı.');
            } catch (ConflictHttpException $exception) {
                $this->assertStringContainsString('CONTRACT_FIELD_UNAVAILABLE', $exception->getMessage());
            }

            $this->assertNull($payment->fresh()->provider_reference);
            $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->fresh()->status);
        }
    }

    public function test_duplicate_callback_validates_stored_authority_and_returns_canonical_payment(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        Mail::fake();
        $canonical = $this->scopedPayment($runId);
        $duplicate = $this->duplicateScopedPayment($canonical);
        app(PaymentProviderManager::class)->createPayment($canonical);
        app(PaymentProviderManager::class)->createPayment($duplicate);
        app(TechnicalServicePaymentSettlementService::class)->markPaid($canonical->fresh(), ['fake_approved' => true]);

        try {
            app(TechnicalServicePaymentSettlementService::class)->markPaid($duplicate->fresh(), [
                'fake_approved' => true,
                'provider' => 'iyzico',
                'provider_mode' => 'sandbox',
            ]);
            $this->fail('Duplicate callback stored provider authority kontrolünü atlamamalıydı.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('provider_mode_mismatch', $exception->getMessage());
        }

        $result = app(TechnicalServicePaymentSettlementService::class)
            ->markPaid($duplicate->fresh(), ['fake_approved' => true]);
        $this->assertSame($canonical->id, $result->id);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $result->status);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_CANCELLED, $duplicate->fresh()->status);
        $this->assertNull($duplicate->fresh()->paid_at);
        $this->assertSame(1, $canonical->technicalServiceRequest?->events()->where('event_type', 'mount_payment_paid')->count());
    }

    public function test_scoped_callback_state_machine_rejects_failed_cancelled_and_expired_to_paid(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();

        foreach ([
            TechnicalServiceMountPayment::STATUS_FAILED,
            TechnicalServiceMountPayment::STATUS_CANCELLED,
            TechnicalServiceMountPayment::STATUS_EXPIRED,
        ] as $status) {
            $payment = $this->scopedPayment($runId);
            app(PaymentProviderManager::class)->createPayment($payment);
            $payment->forceFill(['status' => $status])->save();

            try {
                app(TechnicalServicePaymentSettlementService::class)
                    ->markPaid($payment->fresh(), ['fake_approved' => true]);
                $this->fail($status.' payment paid durumuna geçmemeliydi.');
            } catch (ConflictHttpException $exception) {
                $this->assertStringContainsString('callback_state_invalid', $exception->getMessage());
            }

            $this->assertSame($status, $payment->fresh()->status);
            $this->assertNull($payment->fresh()->paid_at);
        }
    }

    public function test_exact_payment_history_is_not_limited_to_last_twenty_entries(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $payment = $this->scopedPayment($runId);
        app(PaymentProviderManager::class)->createPayment($payment);
        $payload = $payment->fresh()->raw_payload;
        $history = (array) ($payload['scoped_local_uat_effect_history'] ?? []);
        foreach (range(1, 25) as $index) {
            $history[] = [
                'run_id' => $runId,
                'operation' => 'unrelated-'.$index,
                'status' => 'completed',
                'idempotency_hash' => hash('sha256', 'unrelated-'.$index),
            ];
        }
        $payload['scoped_local_uat_effect_history'] = $history;
        $payment->forceFill(['raw_payload' => $payload])->save();

        $result = app(TechnicalServicePaymentSettlementService::class)
            ->markPaid($payment->fresh(), ['fake_approved' => true]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $result->status);
        $this->assertGreaterThan(20, count((array) data_get($result->raw_payload, 'scoped_local_uat_effect_history', [])));
    }

    public function test_failed_payment_history_outside_last_twenty_blocks_blind_retry(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $provider = $this->mock(FakePaymentProvider::class, function ($mock): void {
            $mock->shouldReceive('createPayment')->once()->andThrow(new \RuntimeException('Synthetic provider failure'));
        });
        $this->app->instance(FakePaymentProvider::class, $provider);
        $payment = $this->scopedPayment($runId);

        try {
            app(PaymentProviderManager::class)->createPayment($payment);
            $this->fail('İlk provider çağrısının başarısız olması bekleniyordu.');
        } catch (\RuntimeException) {
            $this->assertSame(TechnicalServiceMountPayment::STATUS_FAILED, $payment->fresh()->status);
        }
        $payload = $payment->fresh()->raw_payload;
        $history = (array) ($payload['scoped_local_uat_effect_history'] ?? []);
        foreach (range(1, 25) as $index) {
            $history[] = [
                'run_id' => $runId,
                'operation' => 'unrelated-'.$index,
                'status' => 'completed',
                'idempotency_hash' => hash('sha256', 'unrelated-failure-'.$index),
            ];
        }
        $payload['scoped_local_uat_effect_history'] = $history;
        $payment->forceFill(['raw_payload' => $payload])->save();

        $retry = app(PaymentProviderManager::class)->createPayment($payment->fresh());

        $this->assertSame(PaymentProviderManager::CREATE_OUTCOME_TERMINAL_NOT_REUSABLE, $retry['outcome']);
        $this->assertSame($payment->id, $retry['payment_id']);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_FAILED, $payment->fresh()->status);
    }

    public function test_terminal_payment_reuse_returns_typed_outcomes_without_provider_retry(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $provider = $this->partialMock(FakePaymentProvider::class, function ($mock): void {
            $mock->shouldReceive('createPayment')->times(4)->passthru();
        });
        $this->app->instance(FakePaymentProvider::class, $provider);
        $manager = app(PaymentProviderManager::class);

        foreach ([
            TechnicalServiceMountPayment::STATUS_PAID => PaymentProviderManager::CREATE_OUTCOME_ALREADY_PAID,
            TechnicalServiceMountPayment::STATUS_FAILED => PaymentProviderManager::CREATE_OUTCOME_TERMINAL_NOT_REUSABLE,
            TechnicalServiceMountPayment::STATUS_CANCELLED => PaymentProviderManager::CREATE_OUTCOME_TERMINAL_NOT_REUSABLE,
            TechnicalServiceMountPayment::STATUS_EXPIRED => PaymentProviderManager::CREATE_OUTCOME_TERMINAL_NOT_REUSABLE,
        ] as $status => $expectedOutcome) {
            $canonical = $this->scopedPayment($runId);
            $duplicate = $this->duplicateScopedPayment($canonical);
            $manager->createPayment($canonical);
            $canonical->forceFill([
                'status' => $status,
                'paid_at' => $status === TechnicalServiceMountPayment::STATUS_PAID ? now() : null,
            ])->save();

            $result = $manager->createPayment($duplicate);

            $this->assertSame($expectedOutcome, $result['outcome']);
            $this->assertSame($canonical->id, $result['payment_id']);
            $this->assertSame($status, $canonical->fresh()->status);
            if ($status === TechnicalServiceMountPayment::STATUS_PAID) {
                $this->assertSame($canonical->id, $manager->canonicalPaymentFromCreateResult($result)->id);
                $this->assertSame(TechnicalServiceMountPayment::STATUS_CANCELLED, $duplicate->fresh()->status);
            } else {
                try {
                    $manager->canonicalPaymentFromCreateResult($result);
                    $this->fail('Terminal payment normal create sonucu gibi kullanılamamalıydı.');
                } catch (ConflictHttpException $exception) {
                    $this->assertStringContainsString('TERMINAL_PAYMENT_NOT_REUSABLE', $exception->getMessage());
                }
                $manager->discardFailedCreatePaymentUnlessAudited($duplicate);
                $this->assertNull($duplicate->fresh());
            }
        }
    }

    public function test_callback_malformed_alias_conflicts_and_tampered_pointer_fail_closed(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $canonical = $this->scopedPayment($runId);
        $duplicate = $this->duplicateScopedPayment($canonical);
        app(PaymentProviderManager::class)->createPayment($canonical);

        foreach ([
            ['provider' => []],
            ['provider' => 'fake', 'payment_provider' => 'iyzico', 'provider_mode' => 'sandbox'],
            ['amount' => '1e0'],
            ['provider_reference' => $canonical->fresh()->provider_reference, 'payment_id' => 'conflicting-reference'],
            ['provider_mode' => 'unknown-mode'],
        ] as $payload) {
            try {
                app(TechnicalServicePaymentSettlementService::class)->markPaid($canonical->fresh(), $payload);
                $this->fail('Malformed veya çelişkili callback authority paid geçişi yapmamalıydı.');
            } catch (ConflictHttpException $exception) {
                $this->assertMatchesRegularExpression('/malformed|mismatch|conflict|CONTRACT_FIELD_UNAVAILABLE/i', $exception->getMessage());
            }
            $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $canonical->fresh()->status);
        }

        app(PaymentProviderManager::class)->createPayment($duplicate);
        $payload = is_array($duplicate->fresh()->raw_payload) ? $duplicate->fresh()->raw_payload : [];
        data_set($payload, 'scoped_local_uat_duplicate_payment.amount_minor', '999999');
        $duplicate->forceFill(['raw_payload' => $payload])->save();

        try {
            app(TechnicalServicePaymentSettlementService::class)->markPaid($duplicate->fresh(), ['fake_approved' => true]);
            $this->fail('Tampered duplicate pointer canonical authority olarak kullanılamamalıydı.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('DUPLICATE_POINTER_AUTHORITY_MISMATCH', $exception->getMessage());
        }
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $canonical->fresh()->status);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_CANCELLED, $duplicate->fresh()->status);
    }

    public function test_conflicting_payment_purpose_fails_before_provider_and_minor_units_are_canonical(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $realProvider = new FakePaymentProvider;
        $provider = $this->mock(FakePaymentProvider::class, function ($mock) use ($realProvider): void {
            $mock->shouldReceive('createPayment')->once()->andReturnUsing(
                fn (TechnicalServiceMountPayment $payment): array => $realProvider->createPayment($payment),
            );
        });
        $this->app->instance(FakePaymentProvider::class, $provider);
        $conflicting = $this->scopedPayment($runId, true, [
            'source' => 'operation_extra_mount_fee',
            'purpose' => 'mount_extra',
            'charge_type' => 'route_fee',
        ]);

        try {
            app(PaymentProviderManager::class)->createPayment($conflicting);
            $this->fail('Çelişkili purpose/charge_type providera ulaşmamalıydı.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('purpose_conflict', $exception->getMessage());
        }
        $this->assertNull($conflicting->fresh()->provider_reference);

        $valid = $this->scopedPayment($runId);
        $valid->forceFill(['amount' => '3500.00'])->save();
        app(PaymentProviderManager::class)->createPayment($valid->fresh());

        $this->assertSame('350000', (string) data_get(
            $valid->fresh()->raw_payload,
            'scoped_local_uat_payment_session_authority.amount_minor',
        ));
    }

    public function test_payment_and_smtp_provider_effects_reject_nested_application_transactions(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        Mail::fake();
        $this->mock(FakePaymentProvider::class, fn ($mock) => $mock->shouldNotReceive('createPayment'));
        $payment = $this->scopedPayment($runId);

        foreach ([
            fn () => app(PaymentProviderManager::class)->createPayment($payment),
            fn () => $this->sendScopedPaymentMail($payment),
        ] as $effect) {
            try {
                DB::transaction($effect);
                $this->fail('Provider effect application transactionı içinden çalışmamalıydı.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('manual_e2e', $exception->errors());
            }
        }

        Mail::assertNothingSent();
        $this->assertNull(data_get($payment->fresh()->raw_payload, 'scoped_local_uat_effect_claim'));
    }

    public function test_dispatching_state_is_not_regressed_to_claimed_by_stale_model_save(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $realProvider = new FakePaymentProvider;
        $provider = $this->mock(FakePaymentProvider::class, function ($mock) use ($realProvider): void {
            $mock->shouldReceive('createPayment')->once()->andReturnUsing(function (TechnicalServiceMountPayment $payment) use ($realProvider): array {
                $this->assertSame('dispatching', data_get(
                    $payment->fresh()->raw_payload,
                    'scoped_local_uat_effect_claim.status',
                ));

                return $realProvider->createPayment($payment);
            });
        });
        $this->app->instance(FakePaymentProvider::class, $provider);
        $payment = $this->scopedPayment($runId);

        app(PaymentProviderManager::class)->createPayment($payment);

        $fresh = $payment->fresh();
        $this->assertNull(data_get($fresh->raw_payload, 'scoped_local_uat_effect_claim'));
        $this->assertSame('completed', data_get($fresh->raw_payload, 'scoped_local_uat_effect_history.0.status'));
        $this->assertFalse(collect((array) data_get($fresh->raw_payload, 'scoped_local_uat_effect_history', []))
            ->contains(fn (mixed $entry): bool => is_array($entry) && ($entry['status'] ?? null) === 'claimed'));
    }

    public function test_part_service_stale_reload_cannot_replace_manager_result(): void
    {
        $canonical = $this->scopedPayment('STALE-MANAGER-RESULT');
        $request = $canonical->technicalServiceRequest()->firstOrFail();
        $partRequest = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $request->id,
            'root_request_id' => $request->id,
            'status' => TechnicalServicePartRequest::STATUS_OPS_REVIEW,
            'part_name' => 'Canonical result fixture',
        ]);
        $canonical->forceFill([
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'provider_reference' => 'canonical-manager-reference',
            'payment_url' => 'http://10.0.28.64:8000/mount-payment/canonical-manager-reference',
            'paid_at' => now(),
        ]);
        $manager = $this->partialMock(PaymentProviderManager::class);
        $manager->shouldReceive('providerName')->andReturn('fake');
        $manager->shouldReceive('environment')->andReturn('local');
        $manager->shouldReceive('createPayment')->once()->andReturn([
            'payment_id' => $canonical->id,
            'provider_reference' => 'canonical-manager-reference',
            'payment_url' => $canonical->payment_url,
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'outcome' => PaymentProviderManager::CREATE_OUTCOME_ALREADY_PAID,
        ]);
        $manager->shouldReceive('createOutcome')->once()
            ->andReturn(PaymentProviderManager::CREATE_OUTCOME_ALREADY_PAID);
        $manager->shouldReceive('canonicalPaymentFromCreateResult')->once()->andReturn($canonical);

        $updated = app(TechnicalServicePartRequestService::class)->transition(
            $partRequest,
            TechnicalServicePartRequest::STATUS_APPROVED,
            $this->admin(),
            [
                'charge_decision' => 'chargeable',
                'service_amount' => 0,
                'part_amount' => 1,
                'customer_message' => 'Synthetic canonical payment message',
            ],
        );

        $this->assertSame($canonical->id, data_get($updated->metadata, 'customer_charge_payment_id'));
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, data_get($updated->metadata, 'charge_status'));
        $this->assertSame('canonical-manager-reference', data_get($updated->metadata, 'customer_charge.provider_reference'));
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $canonical->fresh()->status);
    }

    public function test_provider_and_payment_references_keep_distinct_callback_semantics(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $payment = $this->scopedPayment($runId);
        app(PaymentProviderManager::class)->createPayment($payment);
        $payment = $payment->fresh();
        $payment->forceFill(['provider_payment_reference' => 'provider-payment-reference'])->save();

        $result = app(TechnicalServicePaymentSettlementService::class)->markPaid($payment->fresh(), [
            'fake_approved' => true,
            'payment_id' => $payment->id,
            'provider_reference' => $payment->provider_reference,
            'payment_reference' => 'provider-payment-reference',
        ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $result->status);
        $this->assertSame($payment->provider_reference, $result->provider_reference);
        $this->assertSame('provider-payment-reference', $result->provider_payment_reference);
        $this->assertNotSame($result->provider_reference, $result->provider_payment_reference);
    }

    public function test_actual_scoped_reconciliation_uses_code_owned_callback_effect_and_omits_absent_optional_references(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $payment = $this->scopedPayment($runId);
        app(PaymentProviderManager::class)->createPayment($payment);
        $payment = $payment->fresh();

        $result = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($payment, [
                'ok' => true,
                'provider' => 'fake',
                'provider_status' => 'paid',
                'provider_reference' => $payment->provider_reference,
                'provider_response_redacted' => ['status' => 'paid'],
            ]);

        $profile = app(ExternalEffectCapabilityRegistry::class)->localAllowlistedUatProfile();
        $this->assertSame(
            ['channel' => 'sandbox_payment', 'providers' => ['fake_payment', 'iyzico_sandbox']],
            $profile['action_events']['sandbox_payment_callback'],
        );
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $result->status);
        $callbackPayload = (array) data_get($result->raw_payload, 'callback_payload', []);
        $this->assertArrayNotHasKey('provider_payment_reference', $callbackPayload);
        $this->assertArrayNotHasKey('provider_transaction_reference', $callbackPayload);
        $callbackHistory = collect((array) data_get($result->raw_payload, 'scoped_local_uat_effect_history', []))
            ->firstWhere('operation', TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CALLBACK);
        $this->assertSame('completed', $callbackHistory['status'] ?? null);
        $this->assertSame('local', data_get($callbackHistory, 'callback_submission.provider_mode'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get(
            $callbackHistory,
            'callback_submission.submission_fingerprint',
        ));
    }

    public function test_callback_optional_references_are_strict_and_first_valid_values_bind_once(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $payment = $this->scopedPayment($runId);
        app(PaymentProviderManager::class)->createPayment($payment);

        foreach ([null, ''] as $invalidReference) {
            try {
                app(TechnicalServicePaymentSettlementService::class)->markPaid($payment->fresh(), [
                    'fake_approved' => true,
                    'provider_payment_reference' => $invalidReference,
                ]);
                $this->fail('Present null/empty provider payment reference malformed olmalıydı.');
            } catch (ConflictHttpException $exception) {
                $this->assertStringContainsString('field_malformed', $exception->getMessage());
            }
        }

        $first = app(TechnicalServicePaymentSettlementService::class)->markPaid($payment->fresh(), [
            'fake_approved' => true,
            'provider_payment_reference' => 'provider-payment-first',
            'provider_transaction_reference' => 'provider-transaction-first',
        ]);
        $same = app(TechnicalServicePaymentSettlementService::class)->markPaid($payment->fresh(), [
            'fake_approved' => true,
            'provider_payment_reference' => 'provider-payment-first',
            'provider_transaction_reference' => 'provider-transaction-first',
        ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $first->status);
        $this->assertSame('provider-payment-first', $first->provider_payment_reference);
        $this->assertSame('provider-transaction-first', $first->provider_transaction_reference);
        $this->assertSame($first->id, $same->id);
        try {
            app(TechnicalServicePaymentSettlementService::class)->markPaid($payment->fresh(), [
                'fake_approved' => true,
                'provider_payment_reference' => 'provider-payment-first',
                'provider_transaction_reference' => 'provider-transaction-different',
            ]);
            $this->fail('Stored first transaction reference request ile değiştirilememeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('session_authority_mismatch', $exception->getMessage());
        }
        $this->assertSame('provider-transaction-first', $payment->fresh()->provider_transaction_reference);
        $callbackHistory = collect((array) data_get($payment->fresh()->raw_payload, 'scoped_local_uat_effect_history', []))
            ->firstWhere('operation', TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CALLBACK);
        $this->assertArrayNotHasKey('_provider_payment_reference_binding', (array) data_get($callbackHistory, 'callback_submission', []));
        $this->assertArrayNotHasKey('_provider_transaction_reference_binding', (array) data_get($callbackHistory, 'callback_submission', []));
    }

    public function test_provider_mode_is_bound_to_submission_fingerprint_and_cannot_be_overridden(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUat();
        $payment = $this->scopedPayment($runId);
        app(PaymentProviderManager::class)->createPayment($payment);

        try {
            app(TechnicalServicePaymentSettlementService::class)->markPaid($payment->fresh(), [
                'fake_approved' => true,
                'provider_mode' => 'sandbox',
            ]);
            $this->fail('Request provider_mode stored local authority yerine geçmemeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('provider_mode_mismatch', $exception->getMessage());
        }
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->fresh()->status);

        $paid = app(TechnicalServicePaymentSettlementService::class)->markPaid($payment->fresh(), ['fake_approved' => true]);
        $callback = collect((array) data_get($paid->raw_payload, 'scoped_local_uat_effect_history', []))
            ->firstWhere('operation', TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CALLBACK);
        $this->assertSame('local', data_get($callback, 'callback_submission.provider_mode'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get(
            $callback,
            'callback_submission.submission_fingerprint',
        ));
    }

    public function test_sandbox_snapshot_accepts_family_plus_sandbox_mode_identity(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUatWithIyzicoSandbox();
        $this->expectFakeIyzicoSandboxCreate();
        $payment = $this->scopedPayment($runId)->forceFill(['provider' => 'iyzico']);
        $payment->save();

        $result = app(PaymentProviderManager::class)->createPayment($payment);
        $fresh = $payment->fresh();

        $this->assertSame(PaymentProviderManager::CREATE_OUTCOME_NEW_PENDING, $result['outcome']);
        $this->assertSame('iyzico', $fresh->provider);
        $this->assertSame('sandbox', data_get($fresh->raw_payload, 'provider_mode'));
        $this->assertSame('iyzico_sandbox', data_get(
            $fresh->raw_payload,
            'scoped_local_uat_payment_session_authority.provider',
        ));
        Http::assertNothingSent();
    }

    public function test_family_only_payment_provider_is_canonicalized_with_stored_mode(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUatWithIyzicoSandbox();
        $this->expectFakeIyzicoSandboxCreate();
        $payment = $this->scopedPayment($runId)->forceFill(['provider' => 'iyzico']);
        $payment->save();

        app(PaymentProviderManager::class)->createPayment($payment);
        $fresh = $payment->fresh();

        $this->assertSame('iyzico', $fresh->provider);
        $this->assertSame('iyzico', data_get($fresh->raw_payload, 'provider_decision.provider'));
        $this->assertSame('sandbox', data_get($fresh->raw_payload, 'provider_decision.provider_mode'));
        $this->assertSame('iyzico_sandbox', data_get(
            $fresh->raw_payload,
            'scoped_local_uat_effect_history.0.provider',
        ));
        Http::assertNothingSent();
    }

    public function test_live_mode_cannot_match_sandbox_snapshot(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUatWithIyzicoSandbox();
        $this->forcePaymentProviderMode('live');
        $this->mock(IyzicoPaymentProvider::class, fn ($mock) => $mock->shouldNotReceive('createPayment'));
        $payment = $this->scopedPayment($runId)->forceFill(['provider' => 'iyzico']);
        $payment->save();

        try {
            app(PaymentProviderManager::class)->createPayment($payment);
            $this->fail('Live provider mode sandbox run snapshotını geçmemeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('provider_snapshot_mismatch', $exception->getMessage());
        }

        $fresh = $payment->fresh();
        $this->assertNull(data_get($fresh->raw_payload, 'provider_mode'));
        $this->assertNull(data_get($fresh->raw_payload, 'provider_decision'));
        $this->assertNull($fresh->provider_reference);
        Http::assertNothingSent();
    }

    public function test_provider_family_mismatch_is_rejected(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUatWithIyzicoSandbox();
        $this->mock(IyzicoPaymentProvider::class, fn ($mock) => $mock->shouldNotReceive('createPayment'));
        $payment = $this->scopedPayment($runId);

        try {
            app(PaymentProviderManager::class)->createPayment($payment);
            $this->fail('Payment provider family stored configuration authority ile eşleşmeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('provider_family_mismatch', $exception->getMessage());
        }

        $fresh = $payment->fresh();
        $this->assertSame('fake', $fresh->provider);
        $this->assertNull(data_get($fresh->raw_payload, 'provider_mode'));
        $this->assertNull(data_get($fresh->raw_payload, 'provider_decision'));
        Http::assertNothingSent();
    }

    public function test_request_cannot_override_provider_family_or_mode(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUatWithIyzicoSandbox();
        $this->mock(IyzicoPaymentProvider::class, fn ($mock) => $mock->shouldNotReceive('createPayment'));
        $payment = $this->scopedPayment($runId, true, [
            'provider' => 'other_provider',
            'provider_mode' => 'live',
            'mode' => 'live',
            'environment' => 'production',
            'sandbox' => false,
        ])->forceFill(['provider' => 'iyzico']);
        $payment->save();

        try {
            app(PaymentProviderManager::class)->createPayment($payment);
            $this->fail('Request provider mode stored sandbox authority yerine geçmemeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('provider_mode_mismatch', $exception->getMessage());
        }

        $fresh = $payment->fresh();
        $this->assertSame('iyzico', $fresh->provider);
        $this->assertSame('live', data_get($fresh->raw_payload, 'provider_mode'));
        $this->assertNull(data_get($fresh->raw_payload, 'provider_decision'));
        $this->assertNull($fresh->provider_reference);
        Http::assertNothingSent();
    }

    public function test_mismatch_rejects_before_stamp_and_provider_call(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUatWithIyzicoSandbox();
        $this->forcePaymentProviderMode('live');
        $this->mock(IyzicoPaymentProvider::class, fn ($mock) => $mock->shouldNotReceive('createPayment'));
        $payment = $this->scopedPayment($runId)->forceFill(['provider' => 'iyzico']);
        $payment->save();

        try {
            app(PaymentProviderManager::class)->createPayment($payment);
            $this->fail('Provider identity mismatch final decision stamp öncesi reddedilmeliydi.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('provider_snapshot_mismatch', $exception->getMessage());
        }

        $fresh = $payment->fresh();
        $this->assertNull(data_get($fresh->raw_payload, 'provider_mode'));
        $this->assertNull(data_get($fresh->raw_payload, 'provider_decision'));
        $this->assertNull($fresh->provider_reference);
        Http::assertNothingSent();
    }

    public function test_matching_identity_allows_existing_provider_flow(): void
    {
        ['run_id' => $runId] = $this->startScopedLocalUatWithIyzicoSandbox();
        $this->expectFakeIyzicoSandboxCreate();
        $payment = $this->scopedPayment($runId)->forceFill(['provider' => 'iyzico']);
        $payment->save();

        $result = app(PaymentProviderManager::class)->createPayment($payment);
        $fresh = $payment->fresh();

        $this->assertSame((int) $fresh->getKey(), $result['payment_id']);
        $this->assertSame('sandbox-session-reference', $fresh->provider_reference);
        $this->assertSame('http://10.0.28.64:8000/payments/sandbox-session-reference', $fresh->payment_url);
        $this->assertSame('completed', data_get($fresh->raw_payload, 'scoped_local_uat_effect_history.0.status'));
        Http::assertNothingSent();
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

    /**
     * @return array{admin:User,settings:TechnicalServiceMessagingSettingsService,run_id:string}
     */
    private function startScopedLocalUatWithIyzicoSandbox(): array
    {
        $ready = $this->readyScopedLocalUat();
        app(TechnicalServicePaymentProviderCredentialService::class)->saveIyzicoCredentials(
            'sandbox',
            'TEST_SCOPED_SANDBOX_API_KEY',
            'TEST_SCOPED_SANDBOX_SECRET_KEY',
            $ready['admin'],
        );
        app(TechnicalServicePaymentProviderSettingsService::class)->update([
            'real_provider_enabled' => true,
            'provider_mode' => 'sandbox',
            'payment_notification_enabled' => true,
            'payment_notification_recipients' => self::PAYMENT_EMAIL,
        ]);
        $prepared = $ready['settings']->prepareManualE2E();

        $this->assertSame('iyzico_sandbox', data_get(
            $this->persistedLifecycleSettings(),
            'manual_e2e_run_snapshot.scoped_local_uat_sandbox_payment_provider',
        ));

        return [
            ...$ready,
            'run_id' => (string) data_get($prepared, 'manual_e2e.active_run_id'),
        ];
    }

    private function expectFakeIyzicoSandboxCreate(): void
    {
        $this->mock(IyzicoPaymentProvider::class, function ($mock): void {
            $mock->shouldReceive('createPayment')->once()->andReturnUsing(
                function (TechnicalServiceMountPayment $payment): array {
                    $payment->forceFill([
                        'provider' => 'iyzico',
                        'provider_reference' => 'sandbox-session-reference',
                        'status' => TechnicalServiceMountPayment::STATUS_PENDING,
                        'payment_url' => 'http://10.0.28.64:8000/payments/sandbox-session-reference',
                    ])->save();

                    return [
                        'payment_id' => (int) $payment->getKey(),
                        'provider_reference' => 'sandbox-session-reference',
                        'payment_url' => 'http://10.0.28.64:8000/payments/sandbox-session-reference',
                        'status' => TechnicalServiceMountPayment::STATUS_PENDING,
                    ];
                },
            );
        });
    }

    private function forcePaymentProviderMode(string $mode): void
    {
        $page = PageConfig::query()
            ->where('page_code', TechnicalServicePaymentProviderSettingsService::PAGE_CODE)
            ->firstOrFail();
        $layout = is_array($page->layout_json) ? $page->layout_json : [];
        Arr::set($layout, TechnicalServicePaymentProviderSettingsService::PROVIDER_MODE_KEY, $mode);
        $page->forceFill(['layout_json' => $layout])->save();
    }

    private function scopedPayment(string $runId, bool $synthetic = true, array $payloadOverrides = []): TechnicalServiceMountPayment
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
        $payload = [
            ...$payload,
            ...$payloadOverrides,
        ];

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

    private function duplicateScopedPayment(
        TechnicalServiceMountPayment $payment,
        array $payloadOverrides = [],
    ): TechnicalServiceMountPayment {
        return TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $payment->technical_service_mount_session_id,
            'technical_service_request_id' => $payment->technical_service_request_id,
            'provider' => $payment->provider,
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'raw_payload' => [
                ...(is_array($payment->raw_payload) ? $payment->raw_payload : []),
                ...$payloadOverrides,
            ],
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
