<?php

namespace Tests\Feature;

use App\Models\IntegrationProviderCredential;
use App\Models\PageConfig;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TechnicalServiceMessagingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_messaging_settings_default_to_safe_off(): void
    {
        config(['services.evolution.n8n_webhook_url' => null]);

        $this->actingAs($this->admin())
            ->getJson('/api/technical-service/messaging-settings')
            ->assertOk()
            ->assertJsonPath('messaging_settings.global.messaging_enabled', false)
            ->assertJsonPath('messaging_settings.global.real_send_enabled', false)
            ->assertJsonPath('messaging_settings.global.test_mode_enabled', true)
            ->assertJsonPath('messaging_settings.global.send_delay_seconds', 90)
            ->assertJsonPath('messaging_settings.global.duplicate_cooldown_minutes', 10)
            ->assertJsonPath('messaging_settings.global.active_provider', 'null_local')
            ->assertJsonPath('messaging_settings.global.default_provider', 'null_local')
            ->assertJsonPath('messaging_settings.global.fallback_provider', 'evo_whatsapp')
            ->assertJsonPath('messaging_settings.readiness.queue_ready', false)
            ->assertJsonPath('messaging_settings.readiness.can_send_real', false)
            ->assertJsonPath('messaging_settings.provider.webhook_url_configured', false);
    }

    public function test_messaging_provider_registry_contains_null_evo_nac_voibot_and_mikro(): void
    {
        $providers = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/messaging-settings')
            ->assertOk()
            ->json('messaging_settings.providers');

        $keys = collect($providers)->pluck('key');

        $this->assertContains('null_local', $keys);
        $this->assertContains('evo_whatsapp', $keys);
        $this->assertContains('voibot_voice', $keys);
        $this->assertContains('voibot_messaging_if_supported', $keys);
        $this->assertContains('nac_sms', $keys);
        $this->assertContains('future_sms_provider', $keys);
        $this->assertContains('mikro_api', $keys);
    }

    public function test_voibot_provider_is_disabled_until_contract_confirmed(): void
    {
        $providers = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/messaging-settings')
            ->assertOk()
            ->json('messaging_settings.providers');

        $voice = collect($providers)->firstWhere('key', 'voibot_voice');
        $messaging = collect($providers)->firstWhere('key', 'voibot_messaging_if_supported');

        $this->assertFalse($voice['enabled']);
        $this->assertFalse($voice['contract_confirmed']);
        $this->assertFalse($voice['real_ready']);
        $this->assertFalse($messaging['enabled']);
        $this->assertFalse($messaging['contract_confirmed']);
        $this->assertFalse($messaging['capabilities']['supports_text']);
    }

    public function test_provider_capability_map_is_exposed_without_secret_values(): void
    {
        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/messaging-settings')
            ->assertOk()
            ->json('messaging_settings');

        $this->assertTrue($payload['capability_map']['evo_whatsapp']['supports_text']);
        $this->assertTrue($payload['capability_map']['nac_sms']['supports_sms']);
        $this->assertTrue($payload['capability_map']['mikro_api']['supports_read']);
        $this->assertTrue($payload['capability_map']['mikro_api']['requires_approval']);
        $this->assertTrue($payload['capability_map']['voibot_voice']['supports_voice']);
        $this->assertFalse($payload['capability_map']['voibot_messaging_if_supported']['supports_text']);
        $this->assertArrayNotHasKey('token', $payload['provider']);
        $this->assertNull($payload['provider']['secret_value']);
    }

    public function test_admin_sections_include_nac_sms_mikro_and_health_without_send_action(): void
    {
        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/messaging-settings')
            ->assertOk()
            ->json('messaging_settings');

        $sections = collect($payload['admin_sections'])->pluck('key');

        $this->assertContains('nac_sms', $sections);
        $this->assertContains('mikro_api', $sections);
        $this->assertContains('health', $sections);
        $this->assertFalse($payload['nac_sms']['live_ready']);
        $this->assertFalse($payload['mikro_api']['write_ready']);
    }

    public function test_message_type_real_send_default_disabled(): void
    {
        $types = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/messaging-settings')
            ->assertOk()
            ->json('messaging_settings.message_types');

        $this->assertNotEmpty($types);
        $this->assertContains('appointment_approved_customer', collect($types)->pluck('key'));
        $this->assertTrue(collect($types)->every(fn (array $type): bool => $type['real_send_allowed'] === false));
    }

    public function test_admin_can_save_safe_test_mode_settings(): void
    {
        config(['services.evolution.n8n_webhook_url' => 'https://n8n.example.test/webhook/emaks/evo/send-message']);

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'messaging_enabled' => true,
                'test_mode_enabled' => true,
                'test_phone' => '0546 764 74 28',
                'real_send_enabled' => false,
                'active_provider' => 'evo_whatsapp',
                'default_provider' => 'null_local',
                'fallback_provider' => 'evo_whatsapp',
                'send_delay_seconds' => 90,
                'duplicate_cooldown_minutes' => 10,
                'hourly_limit' => 30,
                'daily_limit' => 200,
                'max_auto_retries' => 1,
                'message_types' => [
                    'appointment_approved_customer' => [
                        'enabled' => true,
                        'test_send_allowed' => true,
                        'real_send_allowed' => false,
                        'template_key' => 'future_template_key',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.global.messaging_enabled', true)
            ->assertJsonPath('messaging_settings.global.active_provider', 'evo_whatsapp')
            ->assertJsonPath('messaging_settings.global.test_phone', '905467647428')
            ->assertJsonPath('messaging_settings.readiness.can_send_test', true)
            ->assertJsonPath('messaging_settings.readiness.can_send_real', false)
            ->assertJsonPath('messaging_settings.provider.webhook_url_configured', true);

        $layout = PageConfig::query()
            ->where('page_code', TechnicalServiceMessagingSettingsService::PAGE_CODE)
            ->value('layout_json');

        $this->assertTrue((bool) data_get($layout, 'technical_service.messaging.messaging_enabled'));
        $this->assertSame('evo_whatsapp', data_get($layout, 'technical_service.messaging.active_provider'));
        $this->assertSame('905467647428', data_get($layout, 'technical_service.messaging.test_phone'));
        $this->assertSame('future_template_key', data_get($layout, 'technical_service.messaging.message_types.appointment_approved_customer.template_key'));
    }

    public function test_admin_can_save_nac_sms_non_secret_settings(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'messaging_enabled' => true,
                'test_mode_enabled' => true,
                'shared_test_phone' => '0546 764 74 28',
                'active_provider' => 'nac_sms',
                'nac_sms' => [
                    'enabled' => true,
                    'scheme' => 'https',
                    'host' => 'smslogin.nac.com.tr',
                    'port' => 9588,
                    'sender' => 'EMAKS',
                    'title' => 'EMAKS',
                    'encoding' => 0,
                    'recipient_type' => 0,
                    'validity' => 6,
                    'use_shared_test_phone' => true,
                    'real_send_allowed' => false,
                ],
                'message_types' => [
                    'appointment_approved_customer' => [
                        'enabled' => true,
                        'test_send_allowed' => true,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.global.active_provider', 'nac_sms')
            ->assertJsonPath('messaging_settings.nac_sms.enabled', true)
            ->assertJsonPath('messaging_settings.nac_sms.sender', 'EMAKS')
            ->assertJsonPath('messaging_settings.nac_sms.credentials_ready', false)
            ->assertJsonPath('messaging_settings.readiness.can_send_real', false);

        $layout = PageConfig::query()
            ->where('page_code', TechnicalServiceMessagingSettingsService::PAGE_CODE)
            ->value('layout_json');

        $this->assertSame('nac_sms', data_get($layout, 'technical_service.messaging.active_provider'));
        $this->assertSame('EMAKS', data_get($layout, 'technical_service.messaging.nac_sms.sender'));
    }

    public function test_nac_sms_credentials_are_encrypted_at_rest_and_masked_in_response(): void
    {
        $secret = 'PR88_NAC_CREDENTIAL_TEST_ONLY';

        $payload = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/nac-sms/credentials', [
                'username' => 'nac-user@example.test',
                'password' => $secret,
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.nac_sms.credentials_ready', true)
            ->assertJsonPath('messaging_settings.nac_sms.password_mask', '********')
            ->json();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $row = DB::table('integration_provider_credentials')->where('provider', 'nac_sms')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString($secret, (string) $row->password_encrypted);
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertSame('configured', IntegrationProviderCredential::query()->where('provider', 'nac_sms')->value('credentials_status'));
        $this->assertStringNotContainsString($secret, json_encode(PageConfig::query()->pluck('layout_json')->all(), JSON_THROW_ON_ERROR));
    }

    public function test_nac_sms_real_send_remains_blocked_without_queue_and_single_send_proof(): void
    {
        Http::fake();

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/nac-sms/credentials', [
                'username' => 'nac-user@example.test',
                'password' => 'PR88_NAC_CREDENTIAL_TEST_ONLY',
            ])
            ->assertOk();

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'messaging_enabled' => true,
                'test_mode_enabled' => false,
                'test_phone' => '905467647428',
                'real_send_enabled' => true,
                'active_provider' => 'nac_sms',
                'nac_sms' => [
                    'enabled' => true,
                    'sender' => 'EMAKS',
                    'title' => 'EMAKS',
                    'real_send_allowed' => true,
                ],
                'message_types' => [
                    'appointment_approved_customer' => [
                        'enabled' => true,
                        'real_send_allowed' => true,
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['real_send_enabled']);

        Http::assertNothingSent();
    }

    public function test_mikro_api_settings_and_credentials_are_readiness_only(): void
    {
        $secret = 'PR88_MIKRO_CREDENTIAL_TEST_ONLY';

        $payload = $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => [
                    'enabled' => true,
                    'base_url' => 'https://mikro-api.example.test',
                    'api_version' => 'V17',
                    'application_code' => 'EMAKS',
                    'application_name' => 'EMAKS Panel',
                    'timeout_seconds' => 15,
                    'read_sync_enabled' => true,
                    'write_enabled' => true,
                    'write_approval_required' => true,
                    'operation_catalog_status' => 'missing',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.enabled', true)
            ->assertJsonPath('messaging_settings.mikro_api.write_approval_required', true)
            ->json();

        $this->assertFalse($payload['messaging_settings']['mikro_api']['write_ready']);

        $payload = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/credentials', [
                'api_key' => $secret,
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.credentials_ready', true)
            ->json();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $row = DB::table('integration_provider_credentials')->where('provider', 'mikro_api')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString($secret, (string) $row->api_key_encrypted);
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString($secret, json_encode(PageConfig::query()->pluck('layout_json')->all(), JSON_THROW_ON_ERROR));
    }

    public function test_invalid_test_phone_is_rejected_when_test_mode_enabled(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'messaging_enabled' => true,
                'test_mode_enabled' => true,
                'test_phone' => 'abc',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['test_phone']);
    }

    public function test_test_phone_validation_endpoint_normalizes_phone_without_sending(): void
    {
        Http::fake();

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/validate-phone', [
                'test_phone' => '0546 764 74 28',
            ])
            ->assertOk()
            ->assertJsonPath('phone.normalized', '905467647428')
            ->assertJsonPath('phone.valid', true);

        Http::assertNothingSent();
    }

    public function test_send_delay_minimum_is_enforced(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'send_delay_seconds' => 10,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['send_delay_seconds']);
    }

    public function test_unknown_message_type_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'message_types' => [
                    'unknown_message_type' => [
                        'enabled' => true,
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message_types']);
    }

    public function test_real_send_requires_provider_readiness(): void
    {
        config(['services.evolution.n8n_webhook_url' => null]);

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'messaging_enabled' => true,
                'test_mode_enabled' => false,
                'test_phone' => '905467647428',
                'real_send_enabled' => true,
                'message_types' => [
                    'appointment_approved_customer' => [
                        'enabled' => true,
                        'real_send_allowed' => true,
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['real_send_enabled']);

        $this->assertFalse(app(TechnicalServiceMessagingSettingsService::class)->payload()['global']['real_send_enabled']);
    }

    public function test_no_real_send_from_voibot_placeholder(): void
    {
        config(['services.evolution.n8n_webhook_url' => 'https://n8n.example.test/webhook/emaks/evo/send-message']);
        Http::fake();

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'messaging_enabled' => true,
                'test_mode_enabled' => false,
                'test_phone' => '905467647428',
                'active_provider' => 'voibot_voice',
                'real_send_enabled' => true,
                'message_types' => [
                    'appointment_approved_customer' => [
                        'enabled' => true,
                        'real_send_allowed' => true,
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['real_send_enabled']);

        Http::assertNothingSent();
    }

    public function test_settings_response_does_not_expose_webhook_or_secret_values(): void
    {
        config(['services.evolution.n8n_webhook_url' => 'https://n8n.example.test/webhook/secret-token-value']);

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/messaging-settings')
            ->assertOk()
            ->assertJsonPath('messaging_settings.provider.webhook_url_configured', true)
            ->json();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('secret-token-value', $encoded);
        $this->assertStringNotContainsString('https://n8n.example.test/webhook/secret-token-value', $encoded);
    }

    public function test_non_admin_cannot_update_messaging_settings(): void
    {
        $this->actingAs(User::factory()->create(['role_code' => 'ops']))
            ->patchJson('/api/technical-service/messaging-settings', [
                'messaging_enabled' => true,
            ])
            ->assertForbidden();
    }

    public function test_admin_settings_page_contains_messaging_settings_without_send_button(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-admin.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('Mesajlaşma Sağlayıcı Ayarları', $source);
        $this->assertStringContainsString('Voibot sözleşme bekliyor', $source);
        $this->assertStringContainsString('Provider readiness', $source);
        $this->assertStringContainsString('SMS API / NAC', $source);
        $this->assertStringContainsString('Mikro API', $source);
        $this->assertStringContainsString('admin_sections', $source);
        $this->assertStringContainsString('Test telefon numarası', $source);
        $this->assertStringContainsString('Gerçek gönderim aktif', $source);
        $this->assertStringContainsString('Duplicate cooldown dakika', $source);
        $this->assertStringContainsString('Queue sender', $source);
        $this->assertStringContainsString('Mesaj tipi', $source);
        $this->assertStringContainsString('Test telefonu doğrula', $source);
        $this->assertStringNotContainsString('WhatsApp test mesajı gönder', $source);
        $this->assertStringNotContainsString('/sms/create', $source);
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }
}
