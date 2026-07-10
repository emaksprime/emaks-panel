<?php

namespace Tests\Feature;

use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceManualE2ERunContext;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TechnicalServiceManualE2EEntryPointTest extends TestCase
{
    use RefreshDatabase;

    private const PROTECTED_UPDATE_MESSAGE = 'Manual E2E ve gerçek gönderim durumu genel ayarlar üzerinden değiştirilemez. Manual E2E kontrol panelindeki güvenli açma/dondurma aksiyonunu kullanın.';

    public function test_manual_e2e_entry_point_rejects_lifecycle_fields_from_generic_settings(): void
    {
        $admin = $this->admin();
        $baselineQueuePaused = app(TechnicalServiceMessagingSettingsService::class)->payload()['global']['queue_paused'];

        foreach ([
            'manual_e2e_enabled' => true,
            'real_send_enabled' => true,
            'queue_paused' => false,
            'manual_e2e_active_run_id' => 'MANUAL-E2E-FULL-20260710-100000-TEST',
            'smoke_run_id' => 'MANUAL-E2E-FULL-20260710-100000-TEST',
        ] as $field => $value) {
            $this->actingAs($admin)
                ->patchJson('/api/technical-service/messaging-settings', [$field => $value])
                ->assertUnprocessable()
                ->assertJsonPath("errors.{$field}.0", self::PROTECTED_UPDATE_MESSAGE);
        }

        $this->actingAs($admin)
            ->patchJson('/api/technical-service/messaging-settings', [
                'manual_e2e' => ['active_run_id' => 'MANUAL-E2E-FULL-20260710-100000-TEST'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.manual_e2e.0', self::PROTECTED_UPDATE_MESSAGE);

        $payload = app(TechnicalServiceMessagingSettingsService::class)->payload();
        $this->assertFalse($payload['global']['manual_e2e_enabled']);
        $this->assertFalse($payload['global']['real_send_enabled']);
        $this->assertSame($baselineQueuePaused, $payload['global']['queue_paused']);
        $this->assertNull($payload['manual_e2e']['active_run_id']);
    }

    public function test_messaging_settings_safe_fields_still_update_and_frontend_generic_save_excludes_lifecycle_fields(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'messaging_enabled' => true,
                'test_mode_enabled' => true,
                'test_phone' => '905467647428',
                'send_delay_seconds' => 120,
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.global.messaging_enabled', true)
            ->assertJsonPath('messaging_settings.global.send_delay_seconds', 120);

        $source = File::get(resource_path('js/pages/panel/technical-service-admin.tsx'));
        $start = strpos($source, 'const saveMessagingSettings');
        $end = strpos($source, 'const checkManualE2EReadiness', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $genericSave = substr($source, $start, $end - $start);

        foreach (TechnicalServiceMessagingSettingsService::GENERIC_LIFECYCLE_FIELDS as $field) {
            $this->assertStringNotContainsString("{$field}:", $genericSave);
        }

        $this->assertStringContainsString('manual-e2e/readiness', $source);
        $this->assertStringContainsString('manual-e2e/enable', $source);
        $this->assertStringContainsString('manual-e2e/freeze', $source);
        $this->assertStringContainsString('manual-e2e-enable-confirmation', $source);
        $this->assertStringContainsString('Gerçek gönderim riskini kabul et ve aç', $source);
        $this->assertStringNotContainsString("['real_send_enabled', 'Gerçek gönderim aktif']", $source);
        $this->assertStringNotContainsString("['queue_paused', 'Kuyruk duraklatıldı']", $source);
    }

    public function test_manual_e2e_readiness_is_read_only_complete_and_does_not_expose_secrets(): void
    {
        $this->getJson('/api/technical-service/messaging-settings/manual-e2e/readiness')
            ->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role_code' => 'ops']))
            ->getJson('/api/technical-service/messaging-settings/manual-e2e/readiness')
            ->assertForbidden();

        $beforePages = PageConfig::query()->count();
        $response = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/messaging-settings/manual-e2e/readiness')
            ->assertOk()
            ->assertJsonStructure([
                'manual_e2e_readiness' => [
                    'eligible',
                    'blockers',
                    'warnings',
                    'evo_ready',
                    'nac_ready',
                    'allowlisted_phones',
                    'allowlisted_phone_masks',
                    'ops_sms_enabled',
                    'pending_external_count',
                    'unsafe_external_count',
                    'worker_lock_available',
                    'lifecycle_lock_available',
                    'active_run_id',
                    'ttl_seconds',
                    'channel_policies',
                ],
            ]);

        $codes = collect($response->json('manual_e2e_readiness.blockers'))->pluck('code');
        $this->assertContains('manual_e2e_allowlist_invalid', $codes);
        $this->assertContains('manual_e2e_ops_target_invalid', $codes);
        $this->assertContains('messaging_disabled', $codes);
        $this->assertContains('evo_not_ready', $codes);
        $this->assertContains('nac_not_ready', $codes);
        $this->assertFalse($response->json('manual_e2e_readiness.ops_sms_enabled'));
        $this->assertSame($beforePages, PageConfig::query()->count());
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('api_key', $encoded);
        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('Author'.'ization', $encoded);
    }

    public function test_enable_manual_e2e_requires_authorized_admin_and_returns_atomic_context_without_starting_worker(): void
    {
        Http::fake();

        $this->postJson('/api/technical-service/messaging-settings/manual-e2e/enable')
            ->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role_code' => 'ops']))
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable')
            ->assertForbidden();

        $admin = $this->admin();
        $settings = $this->readyManualE2ESettings($admin);
        $readiness = $this->actingAs($admin)
            ->getJson('/api/technical-service/messaging-settings/manual-e2e/readiness')
            ->assertOk()
            ->assertJsonPath('manual_e2e_readiness.eligible', true)
            ->json('manual_e2e_readiness');

        $payload = $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', [
                'manual_e2e_allowlisted_phones' => $readiness['allowlisted_phones'],
                'manual_e2e_ttl_seconds' => $readiness['ttl_seconds'],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.global.manual_e2e_enabled', true)
            ->assertJsonPath('messaging_settings.global.real_send_enabled', true)
            ->assertJsonPath('messaging_settings.global.queue_paused', false)
            ->assertJsonPath('messaging_settings.global.test_mode_enabled', false)
            ->assertJsonPath('messaging_settings.global.ops_whatsapp_enabled', true)
            ->json('messaging_settings');

        $runId = (string) $payload['manual_e2e']['active_run_id'];
        $this->assertMatchesRegularExpression('/^MANUAL-E2E-FULL-\d{8}-\d{6}-[A-Z0-9]{4}$/', $runId);
        $this->assertSame($payload['manual_e2e']['started_at'], $payload['manual_e2e']['created_after']);
        $this->assertStringContainsString('--smoke-run-id='.$runId, (string) $payload['manual_e2e']['worker_command']);
        $this->assertStringContainsString('--created-after="'.$payload['manual_e2e']['created_after'].'"', (string) $payload['manual_e2e']['worker_command']);
        $this->assertStringContainsString('--manual-e2e-only', (string) $payload['manual_e2e']['worker_command']);

        $workerLock = Cache::lock(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY, 5);
        $this->assertTrue($workerLock->get(), 'HTTP enable endpoint worker başlatmamalı veya worker lock tutmamalı.');
        $workerLock->release();

        $this->actingAs($admin)
            ->getJson('/api/technical-service/messaging-settings/manual-e2e/readiness')
            ->assertOk()
            ->assertJsonPath('manual_e2e_readiness.eligible', false)
            ->assertJsonPath('manual_e2e_readiness.active_run_id', $runId);
        $this->assertSame($runId, $settings->payload()['manual_e2e']['active_run_id']);
        Http::assertNothingSent();
    }

    public function test_enable_manual_e2e_repeats_readiness_inside_lock_and_rejects_pending_or_unsafe_dispatch(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->readyManualE2ESettings($admin);
        TechnicalServiceMessageDispatch::query()->create([
            'event' => 'manual_e2e_readiness_test',
            'message_type' => 'assignment_offer_technician',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'technician',
            'target_phone' => '905551112233',
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable')
            ->assertUnprocessable();
        $errors = collect($response->json('errors.manual_e2e'));
        $this->assertTrue($errors->contains(fn (string $message): bool => str_contains($message, 'external provider kuyruğu boş')));
        $this->assertTrue($errors->contains(fn (string $message): bool => str_contains($message, 'Allowlist dışı pending provider dispatch')));

        $payload = $settings->payload();
        $this->assertFalse($payload['global']['manual_e2e_enabled']);
        $this->assertFalse($payload['global']['real_send_enabled']);
        $this->assertTrue($payload['global']['queue_paused']);
        $this->assertNull($payload['manual_e2e']['active_run_id']);
        Http::assertNothingSent();
    }

    public function test_concurrent_enable_creates_only_one_run_and_worker_lock_blocks_a_new_run(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->readyManualE2ESettings($admin);
        $first = $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable')
            ->assertOk()
            ->json('messaging_settings.manual_e2e.active_run_id');

        $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable')
            ->assertConflict();
        $this->assertSame($first, $settings->payload()['manual_e2e']['active_run_id']);

        $settings->freezeManualE2E();
        $lifecycleLock = Cache::lock(TechnicalServiceManualE2ERunContext::LIFECYCLE_LOCK_KEY, 30);
        $this->assertTrue($lifecycleLock->get());

        try {
            $this->actingAs($admin)
                ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable')
                ->assertConflict()
                ->assertJsonPath('message', 'Manual E2E yaşam döngüsü başka bir işlem tarafından güncelleniyor.');
            $this->actingAs($admin)
                ->patchJson('/api/technical-service/messaging-settings', [
                    'send_delay_seconds' => 120,
                ])
                ->assertConflict()
                ->assertJsonPath('message', 'Manual E2E yaşam döngüsü başka bir işlem tarafından güncelleniyor.');
        } finally {
            $lifecycleLock->release();
        }

        $workerLock = Cache::lock(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY, 30);
        $this->assertTrue($workerLock->get());

        try {
            $this->actingAs($admin)
                ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable')
                ->assertUnprocessable()
                ->assertJsonPath('errors.manual_e2e.0', 'Başka bir Manual E2E worker çalışıyor.');
        } finally {
            $workerLock->release();
        }

        $this->assertNull($settings->payload()['manual_e2e']['active_run_id']);
        Http::assertNothingSent();
    }

    public function test_protected_settings_are_locked_while_active_and_allowed_after_freeze(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->readyManualE2ESettings($admin);
        $settings->enableManualE2E();

        $this->actingAs($admin)
            ->patchJson('/api/technical-service/messaging-settings', [
                'manual_e2e_allowlisted_phones' => ['905467647428'],
                'manual_e2e_ttl_seconds' => 600,
                'test_mode_enabled' => true,
                'active_provider' => 'nac_sms',
                'message_types' => [
                    'assignment_offer_technician' => ['channel_policy' => 'whatsapp_only'],
                ],
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Aktif Manual E2E oturumu varken gönderim güvenliği ayarları değiştirilemez. Önce gönderimleri dondurun.');

        $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/evo-whatsapp/credentials', [
                'api_key' => 'replacement-test-key',
            ])
            ->assertConflict();

        $settings->freezeManualE2E();
        $this->actingAs($admin)
            ->patchJson('/api/technical-service/messaging-settings', [
                'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
                'manual_e2e_ttl_seconds' => 600,
                'send_delay_seconds' => 120,
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.global.manual_e2e_ttl_seconds', 600)
            ->assertJsonPath('messaging_settings.global.send_delay_seconds', 120);
        Http::assertNothingSent();
    }

    public function test_freeze_manual_e2e_requires_admin_is_idempotent_and_preserves_last_run_audit(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->readyManualE2ESettings($admin);
        $runId = (string) $settings->enableManualE2E()['manual_e2e']['active_run_id'];

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/technical-service/messaging-settings/manual-e2e/freeze')
            ->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role_code' => 'ops']))
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/freeze')
            ->assertForbidden();

        $first = $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/freeze')
            ->assertOk()
            ->assertJsonPath('messaging_settings.global.manual_e2e_enabled', false)
            ->assertJsonPath('messaging_settings.global.real_send_enabled', false)
            ->assertJsonPath('messaging_settings.global.queue_paused', true)
            ->assertJsonPath('messaging_settings.global.ops_whatsapp_enabled', false)
            ->json('messaging_settings');

        $this->assertNull($first['manual_e2e']['active_run_id']);
        $this->assertSame($runId, $first['manual_e2e']['last_run_id']);
        $this->assertNotNull($first['manual_e2e']['last_stopped_at']);

        $second = $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/freeze')
            ->assertOk()
            ->json('messaging_settings');
        $this->assertNull($second['manual_e2e']['active_run_id']);
        $this->assertSame($runId, $second['manual_e2e']['last_run_id']);
        Http::assertNothingSent();
    }

    public function test_generic_settings_reset_cannot_replace_freeze_or_change_lifecycle_state(): void
    {
        $admin = $this->admin();
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->freezeManualE2E();

        $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/reset')
            ->assertOk()
            ->assertJsonPath('messaging_settings.global.manual_e2e_enabled', false)
            ->assertJsonPath('messaging_settings.global.real_send_enabled', false)
            ->assertJsonPath('messaging_settings.global.queue_paused', true)
            ->assertJsonPath('messaging_settings.manual_e2e.active_run_id', null);

        $this->readyManualE2ESettings($admin)->enableManualE2E();
        $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/reset')
            ->assertConflict();
    }

    public function test_enable_manual_e2e_failure_rolls_back_to_frozen_state(): void
    {
        Http::fake();
        $settings = $this->readyManualE2ESettings($this->admin());
        $throwOnActiveSave = true;
        PageConfig::saving(function (PageConfig $page) use (&$throwOnActiveSave): void {
            if ($throwOnActiveSave && data_get($page->layout_json, TechnicalServiceMessagingSettingsService::ROOT_KEY.'.manual_e2e_enabled') === true) {
                $throwOnActiveSave = false;
                throw new RuntimeException('Simulated lifecycle persistence failure.');
            }
        });

        try {
            $settings->enableManualE2E();
            $this->fail('Simulated lifecycle persistence failure bekleniyordu.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated lifecycle persistence failure.', $exception->getMessage());
        }

        $payload = $settings->payload();
        $this->assertFalse($payload['global']['manual_e2e_enabled']);
        $this->assertFalse($payload['global']['real_send_enabled']);
        $this->assertTrue($payload['global']['queue_paused']);
        $this->assertFalse($payload['global']['ops_whatsapp_enabled']);
        $this->assertNull($payload['manual_e2e']['active_run_id']);
        Http::assertNothingSent();
    }

    private function readyManualE2ESettings(User $admin): TechnicalServiceMessagingSettingsService
    {
        $this->actingAs($admin);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->freezeManualE2E();
        $settings->update([
            'messaging_enabled' => true,
            'test_mode_enabled' => false,
            'shared_test_phone' => '905467647428',
            'ops_whatsapp_phone' => '905467647428',
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
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
            'notes' => 'Fake Manual E2E endpoint test provider.',
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
