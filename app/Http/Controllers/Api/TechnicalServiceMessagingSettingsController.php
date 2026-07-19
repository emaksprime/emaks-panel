<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Messaging\TechnicalServiceNacSmsTestClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TechnicalServiceMessagingSettingsController extends Controller
{
    public function show(TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        return response()->json([
            'messaging_settings' => $settings->payload(),
        ]);
    }

    public function update(Request $request, TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        $settings->assertGenericUpdateAllowed($request->all());

        $providerKeys = array_keys(TechnicalServiceMessagingSettingsService::PROVIDERS);
        $channelPolicies = TechnicalServiceMessagingSettingsService::SMS_CHANNEL_POLICIES;
        $channelModes = TechnicalServiceMessagingSettingsService::CHANNEL_MODES;

        $data = $request->validate([
            'messaging_enabled' => ['sometimes', 'required', 'boolean'],
            'test_mode_enabled' => ['sometimes', 'required', 'boolean'],
            'manual_e2e_ttl_seconds' => ['sometimes', 'required', 'integer', 'min:60', 'max:14400'],
            'manual_e2e_allowlisted_phones' => ['sometimes', 'array'],
            'manual_e2e_allowlisted_phones.*' => ['required', 'string', 'max:32'],
            'manual_e2e_partner_portal_origin_enabled' => ['sometimes', 'required', 'boolean'],
            'manual_e2e_partner_portal_origin' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ops_whatsapp_enabled' => ['sometimes', 'required', 'boolean'],
            'ops_whatsapp_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'shared_test_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'test_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'provider_key' => ['sometimes', 'required', 'string', Rule::in($providerKeys)],
            'active_provider' => ['sometimes', 'required', 'string', Rule::in($providerKeys)],
            'default_provider' => ['sometimes', 'required', 'string', Rule::in($providerKeys)],
            'fallback_provider' => ['sometimes', 'required', 'string', Rule::in($providerKeys)],
            'provider_priority' => ['sometimes', 'array'],
            'provider_priority.*' => ['required', 'string', Rule::in($providerKeys)],
            'send_delay_seconds' => ['sometimes', 'required', 'integer', 'min:30', 'max:3600'],
            'duplicate_cooldown_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],
            'hourly_limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'daily_limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:100000'],
            'max_auto_retries' => ['sometimes', 'required', 'integer', 'min:0', 'max:3'],
            'allow_browser_smoke_send' => ['sometimes', 'required', 'boolean'],
            'allow_test_fixture_send' => ['sometimes', 'required', 'boolean'],
            'message_types' => ['sometimes', 'array'],
            'message_types.*.enabled' => ['sometimes', 'boolean'],
            'message_types.*.real_send_allowed' => ['sometimes', 'boolean'],
            'message_types.*.test_send_allowed' => ['sometimes', 'boolean'],
            'message_types.*.channel_policy' => ['sometimes', 'string', Rule::in($channelPolicies)],
            'message_types.*.whatsapp_mode' => ['sometimes', 'string', Rule::in($channelModes)],
            'message_types.*.sms_mode' => ['sometimes', 'string', Rule::in($channelModes)],
            'message_types.*.whatsapp_provider' => ['sometimes', 'string', Rule::in($providerKeys)],
            'message_types.*.sms_provider' => ['sometimes', 'string', Rule::in($providerKeys)],
            'message_types.*.template_key' => ['sometimes', 'nullable', 'string', 'max:120'],
            'message_types.*.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'nac_sms' => ['sometimes', 'array'],
            'nac_sms.enabled' => ['sometimes', 'boolean'],
            'nac_sms.profile' => ['sometimes', 'required', 'string', Rule::in(['docs_https_9588', 'legacy_working_http_9587', 'custom'])],
            'nac_sms.scheme' => ['sometimes', 'required', 'string', Rule::in(['http', 'https'])],
            'nac_sms.host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nac_sms.port' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
            'nac_sms.path' => ['sometimes', 'required', 'string', 'max:120'],
            'nac_sms.request_shape' => ['sometimes', 'required', 'string', Rule::in(['legacy_working_minimal', 'docs_full'])],
            'nac_sms.sender' => ['sometimes', 'nullable', 'string', 'max:120'],
            'nac_sms.title' => ['sometimes', 'nullable', 'string', 'min:5', 'max:50'],
            'nac_sms.gateway_uuid' => ['sometimes', 'nullable', 'string', 'max:120'],
            'nac_sms.encoding' => ['sometimes', 'required', 'integer', Rule::in([0, 1, 2])],
            'nac_sms.commercial' => ['sometimes', 'boolean'],
            'nac_sms.skip_ahs_query' => ['sometimes', 'boolean'],
            'nac_sms.recipient_type' => ['sometimes', 'required', 'integer', Rule::in([0, 1, 2])],
            'nac_sms.validity' => ['sometimes', 'required', 'integer', 'min:60', 'max:1440'],
            'nac_sms.report_push_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'nac_sms.use_shared_test_phone' => ['sometimes', 'boolean'],
            'nac_sms.test_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'nac_sms.real_send_allowed' => ['sometimes', 'boolean'],
            'evo_whatsapp' => ['sometimes', 'array'],
            'evo_whatsapp.direct_api_enabled' => ['sometimes', 'boolean'],
            'evo_whatsapp.direct_api_base_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'evo_whatsapp.direct_api_instance_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'evo_whatsapp.delay' => ['sometimes', 'required', 'integer', 'min:0', 'max:120'],
            'evo_whatsapp.link_preview' => ['sometimes', 'boolean'],
            'mikro_api' => ['sometimes', 'array'],
            'mikro_api.enabled' => ['sometimes', 'boolean'],
            'mikro_api.base_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'mikro_api.api_version' => ['sometimes', 'nullable', 'string', 'max:50'],
            'mikro_api.application_code' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.application_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.company_code' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.branch_code' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.workstation_code' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.fiscal_year' => ['sometimes', 'nullable', 'string', 'max:20'],
            'mikro_api.timeout_seconds' => ['sometimes', 'required', 'integer', 'min:3', 'max:120'],
            'mikro_api.license_status' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.app_customer_license_status' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.read_sync_enabled' => ['sometimes', 'boolean'],
            'mikro_api.write_enabled' => ['sometimes', 'boolean'],
            'mikro_api.write_approval_required' => ['sometimes', 'boolean'],
            'mikro_api.operation_catalog_status' => ['sometimes', 'nullable', 'string', 'max:120'],
        ], [
            'nac_sms.validity.min' => 'Single SMS geçerlilik süresi 60-1440 aralığında olmalıdır.',
            'nac_sms.validity.max' => 'Single SMS geçerlilik süresi 60-1440 aralığında olmalıdır.',
        ]);

        return response()->json([
            'messaging_settings' => $settings->update($data),
        ]);
    }

    public function reset(TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        return response()->json([
            'messaging_settings' => $settings->reset(),
            'message' => 'Mesajlaşma sağlayıcı ayarları güvenli varsayılanlara döndürüldü.',
        ]);
    }

    public function enableManualE2E(Request $request, TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'manual_e2e_allowlisted_phones' => ['sometimes', 'array', 'min:1'],
            'manual_e2e_allowlisted_phones.*' => ['required', 'string', 'max:32'],
            'manual_e2e_ttl_seconds' => ['sometimes', 'integer', 'min:60', 'max:14400'],
        ]);

        return response()->json([
            'messaging_settings' => $settings->enableManualE2E($data),
            'message' => 'Manual E2E run context hazırlandı. Worker otomatik başlatılmadı.',
        ]);
    }

    public function manualE2EReadiness(TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        return response()->json([
            'manual_e2e_readiness' => $settings->manualE2EReadiness(),
        ]);
    }

    public function freezeManualE2E(TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        return response()->json([
            'messaging_settings' => $settings->freezeManualE2E(),
            'message' => 'Manual E2E durduruldu ve aktif run context kapatıldı.',
        ]);
    }

    public function validatePhone(Request $request, TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'test_phone' => ['required', 'string', 'max:32'],
        ]);

        return response()->json([
            'phone' => $settings->validatePhone((string) $data['test_phone']),
            'message' => 'Test telefon numarası geçerli.',
        ]);
    }

    public function saveNacSmsCredentials(Request $request, TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json([
            'messaging_settings' => $settings->saveNacSmsCredentials($data),
            'message' => 'NAC SMS bilgileri encrypted olarak kaydedildi. Tam değerler tekrar gösterilmez.',
        ]);
    }

    public function clearNacSmsCredentials(TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        return response()->json([
            'messaging_settings' => $settings->clearProviderCredentials('nac_sms'),
            'message' => 'NAC SMS credential bilgileri temizlendi.',
        ]);
    }

    public function saveEvoWhatsappCredentials(Request $request, TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:2000', 'required_without:token'],
            'token' => ['nullable', 'string', 'max:2000', 'required_without:api_key'],
        ]);

        return response()->json([
            'messaging_settings' => $settings->saveEvoWhatsappCredentials($data),
            'message' => 'Evo Direct API bilgileri encrypted olarak kaydedildi. Tam değerler tekrar gösterilmez.',
        ]);
    }

    public function clearEvoWhatsappCredentials(TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        return response()->json([
            'messaging_settings' => $settings->clearProviderCredentials('evo_whatsapp'),
            'message' => 'Evo Direct API credential bilgileri temizlendi.',
        ]);
    }

    public function testNacSms(Request $request, TechnicalServiceMessagingSettingsService $settings, TechnicalServiceNacSmsTestClient $client): JsonResponse
    {
        $data = $request->validate([
            'real_sms_confirmed' => ['accepted'],
        ]);

        $dispatch = $client->sendProviderTest($settings->testPhone(), $data, $request->user());

        return response()->json([
            'provider_test' => [
                'message' => $dispatch->status === 'sent'
                    ? 'NAC altyapı test SMS’i shared test telefonuna gönderildi.'
                    : 'NAC altyapı test SMS’i başarısız: '.($dispatch->error_message ?: 'Güvenli hata detayı yok.'),
                'dispatch' => [
                    'id' => $dispatch->id,
                    'event' => $dispatch->event,
                    'status' => $dispatch->status,
                    'target_type' => $dispatch->target_type,
                    'target_phone_masked' => $this->maskPhone($dispatch->target_phone),
                    'response_status' => $dispatch->response_payload['status'] ?? null,
                    'provider_reference' => $dispatch->response_payload['pkgID'] ?? null,
                    'error_message' => $dispatch->error_message,
                    'test_type' => $dispatch->response_payload['test_type']
                        ?? $dispatch->request_payload['test_type']
                        ?? $dispatch->event,
                    'content_preview' => $dispatch->request_payload['content_preview'] ?? null,
                    'encoding' => $dispatch->response_payload['encoding']
                        ?? $dispatch->request_payload['encoding']
                        ?? null,
                    'test_code' => $dispatch->response_payload['test_code']
                        ?? $dispatch->request_payload['test_code']
                        ?? null,
                    'custom_id' => $dispatch->response_payload['customID']
                        ?? $dispatch->request_payload['custom_id']
                        ?? null,
                    'payload_hash' => $dispatch->response_payload['payload_hash']
                        ?? $dispatch->request_payload['payload_hash']
                        ?? null,
                    'previous_payload_hash' => $dispatch->response_payload['previous_payload_hash']
                        ?? $dispatch->request_payload['previous_payload_hash']
                        ?? null,
                    'duplicate' => (bool) ($dispatch->response_payload['duplicate'] ?? false),
                ],
            ],
        ]);
    }

    public function saveMikroApiCredentials(Request $request, TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:2000', 'required_without:token'],
            'token' => ['nullable', 'string', 'max:2000', 'required_without:api_key'],
        ]);

        return response()->json([
            'messaging_settings' => $settings->saveMikroApiCredentials($data),
            'message' => 'Mikro API bilgileri encrypted olarak kaydedildi. Tam değerler tekrar gösterilmez.',
        ]);
    }

    public function clearMikroApiCredentials(TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        return response()->json([
            'messaging_settings' => $settings->clearProviderCredentials('mikro_api'),
            'message' => 'Mikro API credential bilgileri temizlendi.',
        ]);
    }

    private function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        if ($digits === '') {
            return null;
        }

        return substr($digits, 0, 4).'***'.substr($digits, -3);
    }
}
