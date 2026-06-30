<?php

namespace Tests\Feature;

use App\Models\PageConfig;
use App\Models\PaymentProviderCredential;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\Payments\PaymentProviderGatewayClient;
use App\Services\Payments\PaymentProviderGatewayRequest;
use App\Services\Payments\PaymentProviderGatewayResponse;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\TechnicalServicePaymentProviderCredentialService;
use App\Services\Payments\TechnicalServicePaymentProviderDisabledException;
use App\Services\Payments\TechnicalServicePaymentProviderModeResolver;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TechnicalServicePaymentProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_settings_default_fake_local(): void
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/payment-provider-settings')
            ->assertOk();

        $response->assertJsonPath('settings.real_provider_enabled', false)
            ->assertJsonPath('settings.provider', 'fake')
            ->assertJsonPath('settings.effective_mode', 'fake')
            ->assertJsonPath('settings.effective_mode_label', 'Fake / Yerel')
            ->assertJsonPath('settings.fake_active', true)
            ->assertJsonPath('settings.credentials.entry_supported', true);
    }

    public function test_readiness_defaults_to_fake_disabled(): void
    {
        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/payment-provider-settings')
            ->assertOk()
            ->assertJsonPath('settings.readiness.effective_mode', 'fake')
            ->assertJsonPath('settings.readiness.real_provider_enabled', false)
            ->assertJsonPath('settings.readiness.credential_source', 'disabled')
            ->assertJsonPath('settings.readiness.provider_send_ready', false)
            ->json('settings.readiness');

        $this->assertFalse($payload['can_enable_real_provider']);
    }

    public function test_real_payment_toggle_cannot_enable_without_gateway_ready(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'real_provider_enabled' => true,
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'real_provider_enabled' => [TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE],
            ]);

        $this->assertFalse(app(TechnicalServicePaymentProviderSettingsService::class)->realProviderEnabled());
    }

    public function test_real_payment_toggle_cannot_enable_without_credentials_ready(): void
    {
        $this->configureGatewayReady(['payments.gateway.credentials_ready' => false]);

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'real_provider_enabled' => true,
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'real_provider_enabled' => [TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE],
            ]);

        $this->assertFalse(app(TechnicalServicePaymentProviderSettingsService::class)->realProviderEnabled());
    }

    public function test_real_payment_toggle_can_enable_when_sandbox_credentials_are_saved(): void
    {
        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('sandbox', 'TEST_SANDBOX_API_KEY', 'TEST_SANDBOX_SECRET_KEY', $this->admin());

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'real_provider_enabled' => true,
                'provider_mode' => 'sandbox',
            ])
            ->assertOk()
            ->assertJsonPath('settings.real_provider_enabled', true)
            ->assertJsonPath('settings.provider', 'iyzico')
            ->assertJsonPath('settings.effective_mode', 'iyzico_sandbox')
            ->assertJsonPath('settings.fake_active', false)
            ->assertJsonPath('settings.provider_transport', 'direct_laravel')
            ->assertJsonPath('settings.can_enable_real_provider', true);
    }

    public function test_real_toggle_on_blocked_when_live_approval_missing_even_if_credentials_exist(): void
    {
        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('live', 'TEST_LIVE_API_KEY', 'TEST_LIVE_SECRET_KEY', $this->admin());

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'real_provider_enabled' => true,
                'provider_mode' => 'live',
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'real_provider_enabled' => [TechnicalServicePaymentProviderSettingsService::LIVE_SEND_APPROVAL_MESSAGE],
            ]);

        $payload = app(TechnicalServicePaymentProviderSettingsService::class)->payload();
        $liveCredentialPayload = app(TechnicalServicePaymentProviderCredentialService::class)->credentialPayload('live');

        $this->assertFalse($payload['real_provider_enabled']);
        $this->assertTrue($liveCredentialPayload['ready']);
    }

    public function test_credential_source_laravel_encrypted_credentials_are_direct_ready(): void
    {
        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('sandbox', 'TEST_SANDBOX_CREDENTIAL_ID', 'TEST_SANDBOX_PRIVATE_VALUE', $this->admin());

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/payment-provider-settings')
            ->assertOk()
            ->assertJsonPath('settings.credentials.ready', true)
            ->assertJsonPath('settings.credential_bridge.source', 'laravel_encrypted')
            ->assertJsonPath('settings.credential_bridge.credentials_ready_for_selected_source', true)
            ->assertJsonPath('settings.credential_bridge.safe_for_provider_send', true)
            ->assertJsonPath('settings.can_enable_real_provider', true)
            ->json();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('Laravel Direct', $payload['settings']['credential_bridge']['message']);
        $this->assertStringNotContainsString('TEST_SANDBOX_CREDENTIAL_ID', $encoded);
        $this->assertStringNotContainsString('TEST_SANDBOX_PRIVATE_VALUE', $encoded);
    }

    public function test_real_toggle_uses_laravel_encrypted_credentials_without_n8n_bridge(): void
    {
        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('sandbox', 'TEST_SANDBOX_CREDENTIAL_ID', 'TEST_SANDBOX_PRIVATE_VALUE', $this->admin());

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'real_provider_enabled' => true,
                'provider_mode' => 'sandbox',
            ])
            ->assertOk()
            ->assertJsonPath('settings.real_provider_enabled', true)
            ->assertJsonPath('settings.credential_bridge.source', 'laravel_encrypted')
            ->assertJsonPath('settings.provider_transport', 'direct_laravel');

        $payload = app(TechnicalServicePaymentProviderSettingsService::class)->payload();

        $this->assertTrue($payload['real_provider_enabled']);
        $this->assertSame('laravel_encrypted', $payload['credential_bridge']['source']);
        $this->assertNull($payload['disabled_reason']);
    }

    public function test_n8n_env_config_is_not_active_payment_credential_source(): void
    {
        config([
            'payments.gateway.credential_source' => 'n8n_env',
            'payments.gateway.n8n_env_credentials_ready' => true,
            'payments.gateway.credentials_ready' => true,
        ]);

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/payment-provider-settings')
            ->assertOk()
            ->assertJsonPath('settings.credential_bridge.source', 'disabled')
            ->assertJsonPath('settings.credential_bridge.n8n_env_credentials_ready', false)
            ->assertJsonPath('settings.credential_bridge.credentials_ready_for_selected_source', false)
            ->assertJsonPath('settings.legacy_n8n_adapter.active', false)
            ->json();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('IYZICO_API_KEY', $encoded);
        $this->assertStringNotContainsString('IYZICO_SECRET_KEY', $encoded);
    }

    public function test_provider_mode_selector_saves_without_enabling_real_provider(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'provider_mode' => 'live',
            ])
            ->assertOk()
            ->assertJsonPath('settings.real_provider_enabled', false)
            ->assertJsonPath('settings.provider_mode', 'live')
            ->assertJsonPath('settings.effective_mode', 'fake')
            ->assertJsonPath('settings.fake_active', true);
    }

    public function test_provider_mode_can_switch_from_live_to_sandbox_when_real_payment_off(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'provider_mode' => 'live',
            ])
            ->assertOk()
            ->assertJsonPath('settings.provider_mode', 'live')
            ->assertJsonPath('settings.effective_mode', 'fake');

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'provider_mode' => 'sandbox',
            ])
            ->assertOk()
            ->assertJsonPath('settings.real_provider_enabled', false)
            ->assertJsonPath('settings.provider_mode', 'sandbox')
            ->assertJsonPath('settings.selected_provider_mode_label', 'Iyzico Sandbox')
            ->assertJsonPath('settings.effective_mode', 'fake')
            ->assertJsonPath('settings.effective_mode_label', 'Fake / Yerel');
    }

    public function test_provider_mode_save_does_not_require_credentials_when_real_payment_off(): void
    {
        config([
            'payments.gateway.url' => null,
            'payments.gateway.token' => null,
            'payments.gateway.credentials_ready' => false,
        ]);

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'provider_mode' => 'sandbox',
            ])
            ->assertOk()
            ->assertJsonPath('settings.provider_mode', 'sandbox')
            ->assertJsonPath('settings.real_provider_enabled', false)
            ->assertJsonPath('settings.effective_mode', 'fake');
    }

    public function test_fake_safe_reset_sets_real_off_and_safe_mode(): void
    {
        $this->configureGatewayReady([
            'payments.gateway.allow_provider_send' => true,
            'payments.gateway.credential_source' => 'n8n_env',
            'payments.gateway.n8n_env_credentials_ready' => true,
            'payments.gateway.credentials_ready' => true,
            'payments.iyzico.live_send_approved' => true,
            'payments.iyzico.ip_whitelist_confirmed' => true,
            'services.public_urls.payment_base_url' => 'https://dashboard.emaksprime.com.tr',
        ]);
        Route::post('/test/iyzico-callback', fn () => response()->json(['ok' => true]))
            ->name('mount-payment.callback');
        Route::getRoutes()->refreshNameLookups();
        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('live', 'TEST_LIVE_API_KEY', 'TEST_LIVE_SECRET_KEY', $this->admin());
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'real_provider_enabled' => true,
                'provider_mode' => 'live',
            ])
            ->assertOk()
            ->assertJsonPath('settings.real_provider_enabled', true)
            ->assertJsonPath('settings.provider_mode', 'live');

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'real_provider_enabled' => false,
                'provider_mode' => 'sandbox',
            ])
            ->assertOk()
            ->assertJsonPath('settings.real_provider_enabled', false)
            ->assertJsonPath('settings.provider', 'fake')
            ->assertJsonPath('settings.provider_mode', 'sandbox')
            ->assertJsonPath('settings.effective_mode', 'fake')
            ->assertJsonPath('settings.fake_active', true);
    }

    public function test_admin_settings_show_iyzico_url_ip_and_back_url_readiness_contract(): void
    {
        config([
            'services.public_urls.payment_base_url' => 'https://dashboard.emaksprime.com.tr',
        ]);

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/payment-provider-settings')
            ->assertOk()
            ->assertJsonPath('settings.iyzico_urls.sandbox_base_url', 'https://sandbox-api.iyzipay.com')
            ->assertJsonPath('settings.iyzico_urls.live_base_url', 'https://api.iyzipay.com')
            ->assertJsonPath('settings.iyzico_urls.authorization_scheme', 'IYZWSv2')
            ->assertJsonPath('settings.ip_whitelist.source', 'direct_laravel_app_server')
            ->assertJsonPath('settings.ip_whitelist.status', 'manual_required')
            ->assertJsonPath('settings.ip_whitelist.manual_check_command', 'curl -4s https://api.ipify.org')
            ->assertJsonPath('settings.back_url.payment_return_route_exists', true)
            ->assertJsonPath('settings.back_url.payment_return_url', 'https://dashboard.emaksprime.com.tr/mount-payment/{provider_reference}')
            ->assertJsonPath('settings.back_url.callback_route_exists', false)
            ->assertJsonPath('settings.back_url.ready', false)
            ->json('settings');

        $this->assertStringContainsString('REL-3C.8', $payload['back_url']['message']);
    }

    public function test_live_readiness_blocked_when_ip_whitelist_is_unconfirmed(): void
    {
        config([
            'payments.iyzico.live_send_approved' => true,
            'services.public_urls.payment_base_url' => 'https://dashboard.emaksprime.com.tr',
        ]);
        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('live', 'TEST_LIVE_API_KEY', 'TEST_LIVE_SECRET_KEY', $this->admin());
        Route::post('/test/iyzico-callback', fn () => response()->json(['ok' => true]))
            ->name('mount-payment.callback');
        Route::getRoutes()->refreshNameLookups();

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'real_provider_enabled' => true,
                'provider_mode' => 'live',
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'real_provider_enabled' => ['Canlı Iyzico için uygulama sunucusu public IP adresi Iyzico panelinde onaylanmalı.'],
            ]);
    }

    public function test_live_readiness_blocked_when_back_url_callback_route_is_missing(): void
    {
        config([
            'payments.iyzico.live_send_approved' => true,
            'payments.iyzico.ip_whitelist_confirmed' => true,
            'services.public_urls.payment_base_url' => 'https://dashboard.emaksprime.com.tr',
        ]);
        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('live', 'TEST_LIVE_API_KEY', 'TEST_LIVE_SECRET_KEY', $this->admin());

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'real_provider_enabled' => true,
                'provider_mode' => 'live',
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'real_provider_enabled' => ['Back URL / callback route henüz tamamlanmadı; REL-3C.8 reconciliation aşamasında tamamlanacak.'],
            ]);
    }

    public function test_payment_back_url_rejects_localhost_for_live_readiness(): void
    {
        config([
            'payments.iyzico.live_send_approved' => true,
            'payments.iyzico.ip_whitelist_confirmed' => true,
            'services.public_urls.payment_base_url' => 'http://127.0.0.1:8000',
        ]);
        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('live', 'TEST_LIVE_API_KEY', 'TEST_LIVE_SECRET_KEY', $this->admin());

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'provider_mode' => 'live',
            ])
            ->assertOk()
            ->assertJsonPath('settings.back_url.public_https_ready', false)
            ->assertJsonPath('settings.readiness.back_url_ready', false)
            ->assertJsonPath('settings.readiness.live_readiness_ready', false)
            ->assertJsonPath('settings.can_enable_real_provider', false);
    }

    public function test_sandbox_readiness_allows_create_without_claiming_back_url_ready(): void
    {
        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('sandbox', 'TEST_SANDBOX_API_KEY', 'TEST_SANDBOX_SECRET_KEY', $this->admin());

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'real_provider_enabled' => true,
                'provider_mode' => 'sandbox',
            ])
            ->assertOk()
            ->assertJsonPath('settings.real_provider_enabled', true)
            ->assertJsonPath('settings.readiness.provider_send_ready', true)
            ->assertJsonPath('settings.back_url.ready', false)
            ->assertJsonPath('settings.readiness.live_readiness_ready', false);
    }

    public function test_settings_status_shows_gateway_and_credentials_missing_without_secrets(): void
    {
        config([
            'payments.gateway.token' => 'gateway-token-should-not-return',
        ]);

        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/payment-provider-settings')
            ->assertOk()
            ->assertJsonPath('settings.gateway.url_configured', false)
            ->assertJsonPath('settings.gateway.token_configured', false)
            ->assertJsonPath('settings.credentials.ready', false)
            ->assertJsonPath('settings.credentials.source_label', 'Encrypted admin storage')
            ->assertJsonPath('settings.credentials.api_key_status', 'API bilgileri tanımlı değil')
            ->assertJsonPath('settings.credentials.entry_supported', true)
            ->json();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('gateway-token-should-not-return', $encoded);
        $this->assertStringNotContainsString('IYZICO_API_KEY', $encoded);
        $this->assertStringNotContainsString('IYZICO_SECRET_KEY', $encoded);
    }

    public function test_credential_help_mentions_encrypted_storage_and_admin_input(): void
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/payment-provider-settings')
            ->assertOk()
            ->assertJsonPath('settings.credentials.entry_supported', true)
            ->assertJsonPath('settings.credentials.source_label', 'Encrypted admin storage');

        $this->assertStringContainsString(
            'encrypted saklanır',
            $response->json('settings.credentials.entry_message'),
        );
    }

    public function test_payment_provider_credentials_table_exists_if_migration_created(): void
    {
        $this->assertTrue(Schema::hasTable('payment_provider_credentials'));
    }

    public function test_can_save_sandbox_credentials_encrypted_and_response_is_masked(): void
    {
        $apiKey = 'PR88_SANDBOX_API_KEY_TEST_ONLY';
        $secretKey = 'PR88_SANDBOX_PRIVATE_VALUE_TEST_ONLY';

        $payload = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/payment-provider-settings/credentials', [
                'mode' => 'sandbox',
                'api_key' => $apiKey,
                'secret_key' => $secretKey,
            ])
            ->assertOk()
            ->assertJsonPath('settings.credentials.ready', true)
            ->assertJsonPath('settings.credentials.masked_api_key', 'PR88****ONLY')
            ->assertJsonPath('settings.credentials.masked_secret_key', '************')
            ->json();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($apiKey, $encoded);
        $this->assertStringNotContainsString($secretKey, $encoded);

        $row = DB::table('payment_provider_credentials')
            ->where('scope', PaymentProviderCredential::SCOPE_TECHNICAL_SERVICE)
            ->where('provider', PaymentProviderCredential::PROVIDER_IYZICO)
            ->where('mode', PaymentProviderCredential::MODE_SANDBOX)
            ->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString($apiKey, (string) $row->api_key_encrypted);
        $this->assertStringNotContainsString($secretKey, (string) $row->secret_key_encrypted);
        $this->assertSame('PR88****ONLY', $row->api_key_mask);
        $this->assertSame('************', $row->secret_key_mask);
        $this->assertSame(PaymentProviderCredential::STATUS_CONFIGURED, $row->credentials_status);
        $this->assertTrue(app(TechnicalServicePaymentProviderCredentialService::class)->credentialsReady('sandbox'));
    }

    public function test_credentials_unique_by_scope_provider_mode_and_update_without_plaintext_response(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/payment-provider-settings/credentials', [
                'mode' => 'sandbox',
                'api_key' => 'PR88_SANDBOX_API_KEY_TEST_ONLY',
                'secret_key' => 'PR88_SANDBOX_PRIVATE_VALUE_TEST_ONLY',
            ])
            ->assertOk();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/payment-provider-settings/credentials', [
                'mode' => 'sandbox',
                'api_key' => 'PR88_SANDBOX_API_KEY_REPLACED',
                'secret_key' => 'PR88_SANDBOX_SECRET_REPLACED',
            ])
            ->assertOk()
            ->assertJsonPath('settings.credentials.masked_api_key', 'PR88****ACED');

        $this->assertSame(1, PaymentProviderCredential::query()
            ->where('scope', PaymentProviderCredential::SCOPE_TECHNICAL_SERVICE)
            ->where('provider', PaymentProviderCredential::PROVIDER_IYZICO)
            ->where('mode', PaymentProviderCredential::MODE_SANDBOX)
            ->count());

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PR88_SANDBOX_API_KEY_REPLACED', $encoded);
        $this->assertStringNotContainsString('PR88_SANDBOX_SECRET_REPLACED', $encoded);
    }

    public function test_credentials_are_not_stored_in_page_configs_or_logs_plaintext(): void
    {
        $apiKey = 'PR88_SANDBOX_API_KEY_TEST_ONLY';
        $secretKey = 'PR88_SANDBOX_PRIVATE_VALUE_TEST_ONLY';

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/payment-provider-settings/credentials', [
                'mode' => 'sandbox',
                'api_key' => $apiKey,
                'secret_key' => $secretKey,
            ])
            ->assertOk();

        $pageConfig = PageConfig::query()
            ->where('page_code', TechnicalServicePaymentProviderSettingsService::PAGE_CODE)
            ->value('layout_json');
        $pageConfigJson = json_encode($pageConfig ?? [], JSON_THROW_ON_ERROR);
        $logsJson = json_encode(DB::table('panel.logs')->get()->all(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($apiKey, $pageConfigJson);
        $this->assertStringNotContainsString($secretKey, $pageConfigJson);
        $this->assertStringNotContainsString($apiKey, $logsJson);
        $this->assertStringNotContainsString($secretKey, $logsJson);
    }

    public function test_secret_key_not_stored_plaintext(): void
    {
        $secretKey = 'PR88_SANDBOX_PRIVATE_VALUE_TEST_ONLY';

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/payment-provider-settings/credentials', [
                'mode' => 'sandbox',
                'api_key' => 'PR88_SANDBOX_API_KEY_TEST_ONLY',
                'secret_key' => $secretKey,
            ])
            ->assertOk();

        $row = DB::table('payment_provider_credentials')
            ->where('mode', PaymentProviderCredential::MODE_SANDBOX)
            ->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString($secretKey, (string) $row->secret_key_encrypted);
        $this->assertSame('************', $row->secret_key_mask);
    }

    public function test_can_save_live_credentials_encrypted_and_clear_credentials(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/payment-provider-settings/credentials', [
                'mode' => 'live',
                'api_key' => 'PR88_LIVE_API_KEY_TEST_ONLY',
                'secret_key' => 'PR88_LIVE_PRIVATE_VALUE_TEST_ONLY',
            ])
            ->assertOk()
            ->assertJsonPath('credentials.ready', true)
            ->assertJsonPath('credentials.masked_api_key', 'PR88****ONLY');

        $this->assertTrue(app(TechnicalServicePaymentProviderCredentialService::class)->credentialsReady('live'));

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/payment-provider-settings/credentials/clear', [
                'mode' => 'live',
            ])
            ->assertOk()
            ->assertJsonPath('credentials.ready', false)
            ->assertJsonPath('credentials.masked_api_key', null)
            ->assertJsonPath('credentials.masked_secret_key', null);

        $row = DB::table('payment_provider_credentials')
            ->where('provider', PaymentProviderCredential::PROVIDER_IYZICO)
            ->where('mode', PaymentProviderCredential::MODE_LIVE)
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->api_key_encrypted);
        $this->assertNull($row->secret_key_encrypted);
        $this->assertSame(PaymentProviderCredential::STATUS_MISSING, $row->credentials_status);
    }

    public function test_non_admin_cannot_save_credentials(): void
    {
        $this->actingAs(User::factory()->create(['role_code' => 'ops']))
            ->postJson('/api/technical-service/payment-provider-settings/credentials', [
                'mode' => 'sandbox',
                'api_key' => 'PR88_SANDBOX_API_KEY_TEST_ONLY',
                'secret_key' => 'PR88_SANDBOX_PRIVATE_VALUE_TEST_ONLY',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('payment_provider_credentials', 0);
    }

    public function test_api_secret_not_stored_in_page_configs_plaintext(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/payment-provider-settings', [
                'provider_mode' => 'sandbox',
                'api_key' => 'api-key-should-not-store',
                'secret_key' => 'secret-key-should-not-store',
            ])
            ->assertOk();

        $layout = PageConfig::query()
            ->where('page_code', TechnicalServicePaymentProviderSettingsService::PAGE_CODE)
            ->value('layout_json');
        $encoded = json_encode($layout, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('api-key-should-not-store', $encoded);
        $this->assertStringNotContainsString('secret-key-should-not-store', $encoded);
    }

    public function test_health_check_status_does_not_call_provider_or_iyzico(): void
    {
        $this->app->instance(PaymentProviderGatewayClient::class, new class implements PaymentProviderGatewayClient
        {
            public function send(PaymentProviderGatewayRequest $request): PaymentProviderGatewayResponse
            {
                throw new \RuntimeException('Provider gateway should not be called.');
            }
        });

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/payment-provider-settings/health-check')
            ->assertOk()
            ->assertJsonPath('health_check.status', 'missing_credentials');
    }

    public function test_provider_mode_resolver_reports_effective_fake_when_toggle_off(): void
    {
        config([
            'payments.real_provider_enabled' => true,
            'payments.gateway.url' => 'https://n8n.example.test/webhook/panel-payment-provider-iyzico-runner-v1',
            'payments.gateway.token' => 'test-gateway-token',
            'payments.gateway.health_verified' => true,
            'payments.gateway.http_enabled' => true,
            'payments.gateway.allow_provider_send' => true,
        ]);
        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('live', 'TEST_LIVE_API_KEY', 'TEST_LIVE_SECRET_KEY', $this->admin());
        PageConfig::query()->create([
            'page_code' => TechnicalServicePaymentProviderSettingsService::PAGE_CODE,
            'layout_json' => [
                'technical_service' => [
                    'payment' => [
                        'real_provider_enabled' => false,
                        'provider' => 'fake',
                        'provider_mode' => 'live',
                    ],
                ],
            ],
        ]);

        $resolver = app(TechnicalServicePaymentProviderModeResolver::class);

        $this->assertFalse($resolver->realProviderEnabled());
        $this->assertSame('fake', $resolver->activeProviderName());
        $this->assertTrue($resolver->shouldUseFakeProvider());
    }

    public function test_payment_create_uses_fake_when_toggle_off(): void
    {
        $payment = $this->mountPayment(['provider' => app(PaymentProviderManager::class)->providerName()]);

        app(PaymentProviderManager::class)->createPayment($payment);

        $payment->refresh();
        $this->assertSame('fake', $payment->provider);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);
        $this->assertStringContainsString('/mount-payment/', (string) $payment->payment_url);
    }

    public function test_payment_create_uses_fake_when_real_toggle_off_and_provider_mode_live(): void
    {
        PageConfig::query()->create([
            'page_code' => TechnicalServicePaymentProviderSettingsService::PAGE_CODE,
            'layout_json' => [
                'technical_service' => [
                    'payment' => [
                        'real_provider_enabled' => false,
                        'provider' => 'fake',
                        'provider_mode' => 'live',
                    ],
                ],
            ],
        ]);

        $payment = $this->mountPayment(['provider' => app(PaymentProviderManager::class)->providerName()]);

        app(PaymentProviderManager::class)->createPayment($payment);

        $payment->refresh();
        $this->assertSame('fake', $payment->provider);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);
        $this->assertStringContainsString('/mount-payment/', (string) $payment->payment_url);
    }

    public function test_payment_create_blocks_when_toggle_on_but_not_ready_without_fake_fallback(): void
    {
        PageConfig::query()->create([
            'page_code' => TechnicalServicePaymentProviderSettingsService::PAGE_CODE,
            'layout_json' => [
                'technical_service' => [
                    'payment' => [
                        'real_provider_enabled' => true,
                        'provider' => 'iyzico',
                        'provider_mode' => 'sandbox',
                    ],
                ],
            ],
        ]);

        $payment = $this->mountPayment(['provider' => 'iyzico']);

        $this->expectException(TechnicalServicePaymentProviderDisabledException::class);
        $this->expectExceptionMessage(TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE);

        try {
            app(PaymentProviderManager::class)->createPayment($payment);
        } finally {
            $payment->refresh();
            $this->assertSame('iyzico', $payment->provider);
            $this->assertNull($payment->payment_url);
            $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);
        }
    }

    public function test_admin_settings_page_contains_payment_provider_section(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-admin.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('Ödeme sağlayıcı', $source);
        $this->assertStringContainsString('Gerçek ödeme alınsın', $source);
        $this->assertStringContainsString('Iyzico Sandbox', $source);
        $this->assertStringContainsString('Iyzico Live', $source);
        $this->assertStringContainsString('Planlanan Iyzico modu', $source);
        $this->assertStringContainsString('Fake/Yerel moda dön', $source);
        $this->assertStringContainsString('paymentSettings.credentials.entry_status', $source);
        $this->assertStringContainsString('paymentSettings.credentials.entry_message', $source);
        $this->assertStringContainsString('paymentSettings.credential_bridge.message', $source);
        $this->assertStringContainsString('paymentSettings.provider_transport_label', $source);
        $this->assertStringContainsString('paymentSettings.legacy_n8n_adapter.message', $source);
        $this->assertStringContainsString('paymentSettings.readiness.next_required_action', $source);
        $this->assertStringContainsString('paymentSettings.sandbox_activation_checklist', $source);
        $this->assertStringContainsString('paymentSettings.iyzico_urls.sandbox_base_url', $source);
        $this->assertStringContainsString('paymentSettings.iyzico_urls.live_base_url', $source);
        $this->assertStringContainsString('IP whitelist', $source);
        $this->assertStringContainsString('Back URL / callback', $source);
        $this->assertStringContainsString('paymentSettings.back_url.callback_route_exists', $source);
        $this->assertStringContainsString('API bilgilerini kaydet', $source);
        $this->assertStringContainsString('API bilgilerini temizle', $source);
        $this->assertStringContainsString('Bağlantıyı doğrula', $source);
        $this->assertStringNotContainsString("configured_provider !== 'iyzico'", $source);
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function configureGatewayReady(array $overrides = []): void
    {
        config(array_merge([
            'payments.gateway.url' => 'https://n8n.example.test/webhook/panel-payment-provider-iyzico-runner-v1',
            'payments.gateway.token' => 'test-gateway-token',
            'payments.gateway.health_verified' => true,
            'payments.gateway.http_enabled' => true,
            'payments.gateway.allow_provider_send' => false,
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function mountPayment(array $overrides = []): TechnicalServiceMountPayment
    {
        $request = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-PROVIDER-SETTINGS-'.uniqid(),
            'root_mrn' => null,
            'customer_name' => 'Provider Settings Müşteri',
            'customer_phone' => '5550000000',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'Provider settings test adres',
            'product_name' => 'Provider Settings Ürün',
            'serial_number' => 'SN-PROVIDER-SETTINGS-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ]);
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => $request->serial_number,
            'product_name' => $request->product_name,
            'product_model' => $request->product_model,
            'brand' => $request->brand,
        ]);
        $session = TechnicalServiceMountSession::query()->create([
            'technical_service_qr_link_id' => $link->id,
            'technical_service_request_id' => $request->id,
            'serial_number' => $request->serial_number,
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken(uniqid('provider-settings-session-', true)),
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
        ]);

        return TechnicalServiceMountPayment::query()->create(array_merge([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 750,
            'currency' => 'TRY',
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'technical_service_request_id' => $request->id,
                'request_code' => $request->mrn,
                'root_mrn' => $request->root_mrn ?: $request->mrn,
                'serial_number' => $request->serial_number,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
            ],
        ], $overrides));
    }
}
