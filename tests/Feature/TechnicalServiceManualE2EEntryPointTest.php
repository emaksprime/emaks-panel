<?php

namespace Tests\Feature;

use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceManualE2ERunContext;
use App\Services\Messaging\TechnicalServiceMessageDispatchQueue;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TechnicalServiceManualE2EEntryPointTest extends TestCase
{
    use RefreshDatabase;

    private const PROTECTED_UPDATE_MESSAGE = 'Manual E2E ve gerçek gönderim durumu genel ayarlar üzerinden değiştirilemez. Manual E2E kontrol panelindeki güvenli açma/dondurma aksiyonunu kullanın.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-07-21 12:00:00', 'Europe/Istanbul'));
        Http::preventStrayRequests();
    }

    public function test_manual_e2e_entry_point_rejects_lifecycle_fields_from_generic_settings(): void
    {
        $admin = $this->admin();
        $baselineQueuePaused = app(TechnicalServiceMessagingSettingsService::class)->payload()['global']['queue_paused'];

        foreach ([
            'manual_e2e_enabled' => true,
            'real_send_enabled' => true,
            'queue_paused' => false,
            'manual_e2e_active_run_id' => 'MANUAL-E2E-FULL-20260710-100000-TEST',
            'manual_e2e_phase' => 'window_open',
            'manual_e2e_open_window' => ['dispatch_id' => 1],
            'manual_e2e_active_claim' => ['dispatch_id' => 1],
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

    public function test_manual_e2e_lifecycle_requires_strict_operation_and_rejects_client_security_tuple(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->readyManualE2ESettings($admin);

        foreach ([
            [],
            ['operation' => 'enable'],
            ['operation' => 'unknown'],
            ['operation' => 'prepare', 'provider' => 'evo_whatsapp'],
            ['operation' => 'prepare', 'channel' => 'whatsapp'],
            ['operation' => 'prepare', 'recipient' => '905000000000'],
            ['operation' => 'prepare', 'force' => true],
            [
                'operation' => 'open_send_window',
                'active_run_id' => 'RUN',
                'dispatch_id' => 1,
                'provider' => 'evo_whatsapp',
            ],
            [
                'operation' => 'open_send_window',
                'active_run_id' => 'RUN',
                'dispatch_id' => 1,
                'recipient' => '905000000000',
            ],
            [
                'operation' => 'close_send_window',
                'active_run_id' => 'RUN',
                'dispatch_id' => 1,
                'force' => true,
            ],
        ] as $payload) {
            $this->actingAs($admin)
                ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', $payload)
                ->assertUnprocessable();
        }

        $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/freeze', ['operation' => 'prepare'])
            ->assertUnprocessable();
        $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/freeze', [
                'operation' => 'freeze',
                'dispatch_id' => 1,
            ])
            ->assertUnprocessable();

        $current = $settings->payload();
        $this->assertSame('frozen', $current['global']['manual_e2e_phase']);
        $this->assertFalse($current['global']['manual_e2e_enabled']);
        $this->assertFalse($current['global']['real_send_enabled']);
        $this->assertTrue($current['global']['queue_paused']);
        $this->assertNull($current['manual_e2e']['active_run_id']);
        Http::assertNothingSent();
    }

    public function test_unauthorized_actor_cannot_run_any_manual_e2e_lifecycle_operation(): void
    {
        foreach ([
            ['/api/technical-service/messaging-settings/manual-e2e/enable', ['operation' => 'prepare']],
            ['/api/technical-service/messaging-settings/manual-e2e/enable', ['operation' => 'open_send_window', 'active_run_id' => 'RUN', 'dispatch_id' => 1]],
            ['/api/technical-service/messaging-settings/manual-e2e/enable', ['operation' => 'close_send_window', 'active_run_id' => 'RUN', 'dispatch_id' => 1]],
            ['/api/technical-service/messaging-settings/manual-e2e/freeze', ['operation' => 'freeze']],
        ] as [$url, $payload]) {
            $this->postJson($url, $payload)->assertUnauthorized();
            $this->actingAs(User::factory()->create(['role_code' => 'ops']))
                ->postJson($url, $payload)
                ->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
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
        $this->assertStringContainsString("operation: 'prepare'", $source);
        $this->assertStringContainsString("'open_send_window'", $source);
        $this->assertStringContainsString("'close_send_window'", $source);
        $this->assertStringContainsString("operation: 'freeze'", $source);
        $this->assertStringContainsString('Güvenli run hazırlığını başlat', $source);
        $this->assertStringContainsString('Worker otomatik başlamaz', $source);
        $this->assertStringContainsString('otomatik tekrar yapılmadı', $source);
        $this->assertStringContainsString('manualE2ELifecycleBusyRef.current', $source);
        $this->assertStringNotContainsString('Gerçek gönderim riskini kabul et ve aç', $source);

        $lifecycleApplyStart = strpos($source, 'const applyManualE2ELifecycle');
        $lifecycleApplyEnd = strpos($source, 'const applyMessageTemplatePayload', $lifecycleApplyStart);
        $this->assertNotFalse($lifecycleApplyStart);
        $this->assertNotFalse($lifecycleApplyEnd);
        $lifecycleApply = substr($source, $lifecycleApplyStart, $lifecycleApplyEnd - $lifecycleApplyStart);
        $this->assertStringContainsString('setMessagingInputs((current)', $lifecycleApply);
        $this->assertStringContainsString('nextSettings.global.test_mode_enabled', $lifecycleApply);
        $this->assertStringContainsString('messagingRuntimeHeadline(messaging)', $source);
        $this->assertStringContainsString('messagingQueueStatus(messaging)', $source);
        $this->assertStringNotContainsString('REL-4D bekliyor', $source);
        $this->assertStringNotContainsString("['real_send_enabled', 'Gerçek gönderim aktif']", $source);
        $this->assertStringNotContainsString("['queue_paused', 'Kuyruk duraklatıldı']", $source);

        $operationSource = File::get(resource_path('js/pages/panel/technical-service.tsx'));
        $this->assertStringContainsString('Atama sonrası mesaj durumu server ayarları ve kanal politikasıyla belirlenir', $operationSource);
        $this->assertStringNotContainsString('canlı WhatsApp gönderimi yapılmaz', $operationSource);
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
                    'allowlisted_phone_masks',
                    'ops_sms_enabled',
                    'pending_external_count',
                    'unsafe_external_count',
                    'worker_lock_available',
                    'worker_lock_raw_available',
                    'worker_state',
                    'worker_run_id',
                    'worker_heartbeat_at',
                    'worker_stale_recoverable',
                    'lifecycle_lock_available',
                    'active_run_id',
                    'ttl_seconds',
                    'channel_policies',
                ],
            ]);

        $codes = collect($response->json('manual_e2e_readiness.blockers'))->pluck('code');
        $this->assertContains('manual_e2e_allowlist_invalid', $codes);
        $this->assertNotContains('manual_e2e_ops_target_invalid', $codes);
        $this->assertContains('messaging_disabled', $codes);
        $this->assertContains('evo_not_ready', $codes);
        $this->assertContains('nac_not_ready', $codes);
        $this->assertFalse($response->json('manual_e2e_readiness.ops_sms_enabled'));
        $this->assertArrayNotHasKey('allowlisted_phones', $response->json('manual_e2e_readiness'));
        $this->assertSame($beforePages, PageConfig::query()->count());
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('api_key', $encoded);
        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('Author'.'ization', $encoded);
    }

    public function test_enable_manual_e2e_requires_authorized_admin_and_returns_atomic_context_without_starting_worker(): void
    {
        Http::fake();

        $this->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', ['operation' => 'prepare'])
            ->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role_code' => 'ops']))
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', ['operation' => 'prepare'])
            ->assertForbidden();

        $admin = $this->admin();
        $settings = $this->readyManualE2ESettings($admin);
        $page = PageConfig::query()->where('page_code', TechnicalServiceMessagingSettingsService::PAGE_CODE)->firstOrFail();
        $layout = (array) $page->layout_json;
        Arr::set($layout, 'unrelated.lifecycle_probe', ['preserved' => true]);
        $page->forceFill(['layout_json' => $layout])->save();
        $lifecyclePage = PageConfig::query()
            ->where('page_code', TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE)
            ->firstOrFail();
        $lifecycleLayout = (array) $lifecyclePage->layout_json;
        Arr::set($lifecycleLayout, 'unrelated.authoritative_probe', ['preserved' => true]);
        $lifecyclePage->forceFill(['layout_json' => $lifecycleLayout])->save();
        $this->actingAs($admin)
            ->getJson('/api/technical-service/messaging-settings/manual-e2e/readiness')
            ->assertOk()
            ->assertJsonPath('manual_e2e_readiness.eligible', true);

        $payload = $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', [
                'operation' => 'prepare',
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.global.manual_e2e_enabled', true)
            ->assertJsonPath('messaging_settings.global.manual_e2e_phase', 'prepared')
            ->assertJsonPath('messaging_settings.global.real_send_enabled', false)
            ->assertJsonPath('messaging_settings.global.queue_paused', true)
            ->assertJsonPath('messaging_settings.global.test_mode_enabled', false)
            ->assertJsonPath('messaging_settings.global.ops_whatsapp_enabled', false)
            ->assertJsonPath('messaging_settings.readiness.queue_ready', false)
            ->assertJsonPath('messaging_settings.readiness.can_send_real', false)
            ->json('messaging_settings');

        $runId = (string) $payload['manual_e2e']['active_run_id'];
        $this->assertMatchesRegularExpression('/^MANUAL-E2E-FULL-\d{8}-\d{6}-[A-Z0-9]{4}$/', $runId);
        $this->assertSame($payload['manual_e2e']['started_at'], $payload['manual_e2e']['created_after']);
        $this->assertSame('prepared', $payload['manual_e2e']['phase']);
        $this->assertNull($payload['manual_e2e']['open_window']);
        $this->assertNull($payload['manual_e2e']['active_claim']);
        $this->assertNull($payload['manual_e2e']['worker_command']);
        $this->assertSame(2, $payload['manual_e2e']['allowlisted_phone_count']);
        $this->assertArrayNotHasKey('allowlisted_phones', $payload['manual_e2e']);

        $workerLock = Cache::lock(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY, 5);
        $this->assertTrue($workerLock->get(), 'HTTP enable endpoint worker başlatmamalı veya worker lock tutmamalı.');
        $workerLock->release();

        $this->actingAs($admin)
            ->getJson('/api/technical-service/messaging-settings/manual-e2e/readiness')
            ->assertOk()
            ->assertJsonPath('manual_e2e_readiness.eligible', false)
            ->assertJsonPath('manual_e2e_readiness.active_run_id', $runId);
        $this->assertSame($runId, $settings->payload()['manual_e2e']['active_run_id']);
        $this->assertTrue((bool) data_get(
            PageConfig::query()->whereKey($page->id)->value('layout_json'),
            'unrelated.lifecycle_probe.preserved',
        ));
        $this->assertTrue((bool) data_get(
            PageConfig::query()->whereKey($lifecyclePage->id)->value('layout_json'),
            'unrelated.authoritative_probe.preserved',
        ));
        Http::assertNothingSent();
    }

    public function test_technician_only_manual_e2e_starts_with_ops_whatsapp_false(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->readyManualE2ESettings($admin, [
            'ops_whatsapp_enabled' => false,
            'ops_whatsapp_phone' => null,
        ]);

        $readiness = $settings->manualE2EReadiness();
        $this->assertTrue($readiness['eligible']);
        $this->assertNotContains('manual_e2e_ops_target_invalid', collect($readiness['blockers'])->pluck('code')->all());

        $payload = $settings->enableManualE2E();

        $this->assertTrue($payload['global']['manual_e2e_enabled']);
        $this->assertFalse($payload['global']['real_send_enabled']);
        $this->assertTrue($payload['global']['queue_paused']);
        $this->assertFalse($payload['global']['ops_whatsapp_enabled']);
        $this->assertSame('prepared', $payload['manual_e2e']['phase']);
        $this->assertNull($payload['manual_e2e']['worker_command']);
        Http::assertNothingSent();
    }

    public function test_controller_opens_and_closes_one_exact_dispatch_window_without_provider_call(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->readyManualE2ESettings($admin);
        $prepared = $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', ['operation' => 'prepare'])
            ->assertOk()
            ->json('messaging_settings');
        $runId = (string) data_get($prepared, 'manual_e2e.active_run_id');
        $request = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-CONTROLLER-WINDOW',
            'customer_name' => 'Controller Window Test',
            'customer_phone' => '05372081633',
            'customer_city' => 'İstanbul',
            'customer_district' => 'Kadıköy',
            'service_address' => 'Test adresi',
            'product_name' => 'Test ürün',
            'service_type' => 'Montaj',
            'status' => 'Yeni',
        ]);
        $token = (string) $request->mrn;
        $dispatch = app(TechnicalServiceMessageDispatchQueue::class)->enqueue([
            'event' => 'appointment_updated_customer',
            'technical_service_request_id' => $request->id,
            'request_id' => $request->id,
            'mrn' => $request->mrn,
            'message_type' => 'appointment_updated_customer',
            'provider_key' => 'evo_whatsapp',
            'channel' => 'whatsapp',
            'recipient_role' => 'customer',
            'target_type' => 'customer',
            'target_phone' => '905372081633',
            'original_phone' => '905372081633',
            'max_attempts' => 1,
            'idempotency_key' => hash('sha256', 'controller-window-'.$runId),
            'queued_at' => now(),
            'payload' => ['body' => "EMAKS Prime {$token} controller window mesajı."],
            'metadata' => $settings->manualE2EContext()->dispatchMetadata(
                $token,
                '905372081633',
                'customer',
            ),
        ]);

        $openResponse = $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', [
                'operation' => 'open_send_window',
                'active_run_id' => $runId,
                'dispatch_id' => $dispatch->id,
            ]);
        $this->assertSame(200, $openResponse->status(), $openResponse->getContent());
        $openResponse
            ->assertJsonPath('messaging_settings.global.manual_e2e_phase', 'window_open')
            ->assertJsonPath('messaging_settings.global.real_send_enabled', true)
            ->assertJsonPath('messaging_settings.global.queue_paused', false)
            ->assertJsonPath('messaging_settings.manual_e2e.open_window.dispatch_id', $dispatch->id);

        $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', [
                'operation' => 'close_send_window',
                'active_run_id' => $runId,
                'dispatch_id' => $dispatch->id,
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.global.manual_e2e_phase', 'prepared')
            ->assertJsonPath('messaging_settings.global.real_send_enabled', false)
            ->assertJsonPath('messaging_settings.global.queue_paused', true)
            ->assertJsonPath('messaging_settings.manual_e2e.active_run_id', $runId);

        $this->assertTrue((bool) data_get($dispatch->fresh()->metadata, 'manual_e2e_window_consumed'));
        $this->assertSame(0, $dispatch->fresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_ops_enabled_manual_e2e_preserves_ops_setting_and_requires_allowlisted_target(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->readyManualE2ESettings($admin, ['ops_whatsapp_enabled' => true]);

        $this->assertTrue($settings->manualE2EReadiness()['eligible']);
        $payload = $settings->enableManualE2E();
        $this->assertTrue($payload['global']['ops_whatsapp_enabled']);

        $settings->freezeManualE2E();
        $settings->transitionExecutionMode(
            TechnicalServiceMessagingSettingsService::OUTBOUND_EXECUTION_MODE_LOCAL,
            'OPS hedefi gecersiz senaryo ayari icin guvenli lokal gecis.',
            $admin,
        );
        $settings->update([
            'ops_whatsapp_enabled' => true,
            'ops_whatsapp_phone' => '905551112233',
        ]);

        $readiness = $settings->manualE2EReadiness();
        $this->assertFalse($readiness['eligible']);
        $this->assertContains('manual_e2e_ops_target_invalid', collect($readiness['blockers'])->pluck('code')->all());
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
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', ['operation' => 'prepare'])
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
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', ['operation' => 'prepare'])
            ->assertOk()
            ->json('messaging_settings.manual_e2e.active_run_id');

        $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', ['operation' => 'prepare'])
            ->assertConflict();
        $this->assertSame($first, $settings->payload()['manual_e2e']['active_run_id']);

        $settings->freezeManualE2E();
        $settings->transitionExecutionMode(
            TechnicalServiceMessagingSettingsService::OUTBOUND_EXECUTION_MODE_LOCAL,
            'Lifecycle lock regresyonu icin guvenli lokal gecis.',
            $admin,
        );
        $lifecycleLock = Cache::lock(TechnicalServiceManualE2ERunContext::LIFECYCLE_LOCK_KEY, 30);
        $this->assertTrue($lifecycleLock->get());

        try {
            $this->actingAs($admin)
                ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', ['operation' => 'prepare'])
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

        $settings->transitionExecutionMode(
            TechnicalServiceMessagingSettingsService::OUTBOUND_EXECUTION_MODE_LIVE,
            'Worker lock regresyonu icin Manual E2E live test profili.',
            $admin,
            'CANLI MODU AÇ',
        );

        $workerLock = Cache::lock(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY, 30);
        $this->assertTrue($workerLock->get());

        try {
            $this->actingAs($admin)
                ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', ['operation' => 'prepare'])
                ->assertConflict()
                ->assertJsonPath('message', 'Başka bir Manual E2E worker çalışıyor veya worker lock sahipliği güvenli biçimde doğrulanamadı.');
        } finally {
            $workerLock->release();
        }

        $this->assertNull($settings->payload()['manual_e2e']['active_run_id']);
        Http::assertNothingSent();
    }

    public function test_protected_settings_are_locked_while_live_or_active_and_allowed_after_local_freeze(): void
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
            ->assertJsonPath('message', 'Canlı çalışma modunda provider ve mesaj ayarları değiştirilemez. Önce çalışma modunu Lokal olarak dondurun.');

        $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/evo-whatsapp/credentials', [
                'api_key' => 'replacement-test-key',
            ])
            ->assertConflict();

        $settings->freezeManualE2E();
        $settings->transitionExecutionMode(
            TechnicalServiceMessagingSettingsService::OUTBOUND_EXECUTION_MODE_LOCAL,
            'Ayar duzenleme regresyonu icin guvenli lokal gecis.',
            $admin,
        );
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
        $this->postJson('/api/technical-service/messaging-settings/manual-e2e/freeze', ['operation' => 'freeze'])
            ->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role_code' => 'ops']))
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/freeze', ['operation' => 'freeze'])
            ->assertForbidden();

        $first = $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/freeze', ['operation' => 'freeze'])
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
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/freeze', ['operation' => 'freeze'])
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
            if ($throwOnActiveSave
                && $page->page_code === TechnicalServiceMessagingSettingsService::LIFECYCLE_PAGE_CODE
                && data_get($page->layout_json, TechnicalServiceMessagingSettingsService::LIFECYCLE_ROOT_KEY.'.manual_e2e_enabled') === true) {
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

    public function test_stale_owned_worker_lock_is_reconciled_before_enable(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->readyManualE2ESettings($admin);
        $workerLock = Cache::lock(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY, 14400);
        $this->assertTrue($workerLock->get());

        Cache::put(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY, [
            'run_id' => 'MANUAL-E2E-FULL-20260713-080000-OLD1',
            'lock_owner' => $workerLock->owner(),
            'process_id' => 12345,
            'started_at' => now()->subMinutes(10)->toIso8601String(),
            'heartbeat_at' => now()->subMinutes(5)->toIso8601String(),
            'expires_at' => now()->addHour()->toIso8601String(),
            'invalidated_at' => null,
        ], 14400);

        $this->actingAs($admin)
            ->getJson('/api/technical-service/messaging-settings/manual-e2e/readiness')
            ->assertOk()
            ->assertJsonPath('manual_e2e_readiness.worker_lock_raw_available', false)
            ->assertJsonPath('manual_e2e_readiness.worker_state', 'stale')
            ->assertJsonPath('manual_e2e_readiness.worker_stale_recoverable', true);

        $this->actingAs($admin)
            ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', ['operation' => 'prepare'])
            ->assertOk()
            ->assertJsonPath('messaging_settings.global.manual_e2e_enabled', true);

        $this->assertNull(Cache::get(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY));
        $replacementLock = Cache::lock(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY, 30);
        $this->assertTrue($replacementLock->get());
        $replacementLock->release();
        $settings->freezeManualE2E();
        Http::assertNothingSent();
    }

    public function test_stale_lease_with_wrong_owner_cannot_bypass_live_worker_lock(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->readyManualE2ESettings($admin);
        $workerLock = Cache::lock(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY, 14400);
        $this->assertTrue($workerLock->get());

        Cache::put(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY, [
            'run_id' => 'MANUAL-E2E-FULL-20260713-080000-OLD2',
            'lock_owner' => 'wrong-owner-token',
            'process_id' => 54321,
            'started_at' => now()->subMinutes(10)->toIso8601String(),
            'heartbeat_at' => now()->subMinutes(5)->toIso8601String(),
            'expires_at' => now()->addHour()->toIso8601String(),
            'invalidated_at' => null,
        ], 14400);

        try {
            $this->actingAs($admin)
                ->postJson('/api/technical-service/messaging-settings/manual-e2e/enable', ['operation' => 'prepare'])
                ->assertConflict()
                ->assertJsonPath('message', 'Başka bir Manual E2E worker çalışıyor veya worker lock sahipliği güvenli biçimde doğrulanamadı.');

            $this->assertNull($settings->payload()['manual_e2e']['active_run_id']);
            $this->assertNotNull(Cache::get(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY));
        } finally {
            $workerLock->release();
            Cache::forget(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY);
        }

        Http::assertNothingSent();
    }

    public function test_freeze_invalidates_matching_worker_lease_and_releases_owned_lock(): void
    {
        Http::fake();
        $admin = $this->admin();
        $settings = $this->readyManualE2ESettings($admin);
        $active = $settings->enableManualE2E()['manual_e2e'];
        $runId = (string) $active['active_run_id'];
        $workerLock = Cache::lock(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY, 14400);
        $this->assertTrue($workerLock->get());

        $settings->registerManualE2EWorkerLease(
            $runId,
            $workerLock->owner(),
            now()->toImmutable(),
            now()->addHour()->toImmutable(),
        );
        $lease = Cache::get(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY);
        $this->assertIsArray($lease);
        $this->assertSame($settings->executionModePayload()['revision'], $lease['outbound_mode_revision']);
        $lifecycleLock = Cache::lock(TechnicalServiceManualE2ERunContext::LIFECYCLE_LOCK_KEY, 30);
        $this->assertTrue($lifecycleLock->get());
        try {
            $this->assertFalse($settings->heartbeatManualE2EWorkerLease($runId, $workerLock->owner()));
            $this->assertFalse($settings->clearManualE2EWorkerLease($runId, $workerLock->owner()));
            $this->assertSame($lease, Cache::get(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY));
        } finally {
            $lifecycleLock->release();
        }
        $settings->freezeManualE2E();

        $this->assertNull(Cache::get(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY));
        $this->assertFalse($settings->heartbeatManualE2EWorkerLease($runId, $workerLock->owner()));
        $replacementLock = Cache::lock(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY, 30);
        $this->assertTrue($replacementLock->get());
        $replacementLock->release();
        Http::assertNothingSent();
    }

    private function readyManualE2ESettings(
        User $admin,
        array $overrides = [],
    ): TechnicalServiceMessagingSettingsService {
        $this->actingAs($admin);
        $settings = app(TechnicalServiceMessagingSettingsService::class);
        $settings->freezeManualE2E();
        $settings->update(array_replace_recursive([
            'messaging_enabled' => true,
            'test_mode_enabled' => false,
            'shared_test_phone' => '905467647428',
            'ops_whatsapp_phone' => '905467647428',
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
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
                'real_send_allowed' => true,
            ],
            'message_types' => [
                'assignment_offer_technician' => [
                    'enabled' => true,
                    'real_send_allowed' => true,
                    'channel_policy' => 'whatsapp_and_sms',
                ],
            ],
        ], $overrides));

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
                'notes' => 'Fake Manual E2E endpoint test provider.',
            ],
        );
        $lifecyclePage->forceFill(['layout_json' => $lifecycleLayout])->save();
        $settings->saveEvoWhatsappCredentials(['api_key' => 'test-evo-key']);
        $settings->saveNacSmsCredentials(['username' => 'test-user', 'password' => 'test-password']);
        $settings->transitionExecutionMode(
            TechnicalServiceMessagingSettingsService::OUTBOUND_EXECUTION_MODE_LIVE,
            'Manual E2E entrypoint izolasyon testi hazirligi.',
            $admin,
            'CANLI MODU AÇ',
            'TEST-MANUAL-E2E-MODE-ENTRYPOINT',
        );

        return $settings;
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }
}
