<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
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
        $providerKeys = array_keys(TechnicalServiceMessagingSettingsService::PROVIDERS);

        $data = $request->validate([
            'messaging_enabled' => ['sometimes', 'required', 'boolean'],
            'real_send_enabled' => ['sometimes', 'required', 'boolean'],
            'test_mode_enabled' => ['sometimes', 'required', 'boolean'],
            'shared_test_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'test_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'queue_paused' => ['sometimes', 'required', 'boolean'],
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
            'message_types.*.template_key' => ['sometimes', 'nullable', 'string', 'max:120'],
            'message_types.*.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'nac_sms' => ['sometimes', 'array'],
            'nac_sms.enabled' => ['sometimes', 'boolean'],
            'nac_sms.scheme' => ['sometimes', 'required', 'string', Rule::in(['http', 'https'])],
            'nac_sms.host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nac_sms.port' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
            'nac_sms.sender' => ['sometimes', 'nullable', 'string', 'max:120'],
            'nac_sms.title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'nac_sms.gateway_uuid' => ['sometimes', 'nullable', 'string', 'max:120'],
            'nac_sms.encoding' => ['sometimes', 'required', 'integer', Rule::in([0, 1, 2])],
            'nac_sms.commercial' => ['sometimes', 'boolean'],
            'nac_sms.skip_ahs_query' => ['sometimes', 'boolean'],
            'nac_sms.recipient_type' => ['sometimes', 'required', 'integer', Rule::in([0, 1, 2])],
            'nac_sms.validity' => ['sometimes', 'required', 'integer', 'min:3', 'max:6'],
            'nac_sms.report_push_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'nac_sms.use_shared_test_phone' => ['sometimes', 'boolean'],
            'nac_sms.test_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'nac_sms.real_send_allowed' => ['sometimes', 'boolean'],
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
}
