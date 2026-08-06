<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExternalEffects\ExternalExecutionControlPlaneService;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Messaging\TechnicalServiceNacSmsTestClient;
use App\Services\Mikro\MikroApiClient;
use App\Services\Mikro\MikroAuthenticatedReadCanaryService;
use App\Services\Mikro\MikroOperationRegistry;
use App\Services\Mikro\MikroRuntimeState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TechnicalServiceMessagingSettingsController extends Controller
{
    public function show(TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        return response()->json([
            'messaging_settings' => $this->redactRecipientValues($settings->payload()),
        ]);
    }

    public function update(
        Request $request,
        TechnicalServiceMessagingSettingsService $settings,
        MikroOperationRegistry $mikroOperationRegistry,
    ): JsonResponse {
        $settings->assertGenericUpdateAllowed($request->all());

        $providerKeys = array_keys(TechnicalServiceMessagingSettingsService::PROVIDERS);
        $channelPolicies = TechnicalServiceMessagingSettingsService::SMS_CHANNEL_POLICIES;
        $channelModes = TechnicalServiceMessagingSettingsService::CHANNEL_MODES;

        $data = $request->validate([
            'messaging_enabled' => ['sometimes', 'required', 'boolean'],
            'real_send_enabled' => ['sometimes', 'required', 'boolean'],
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
            'customer_test_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'technician_ops_test_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
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
            'mikro_api.base_url' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
                static function (string $attribute, mixed $value, \Closure $fail) use ($mikroOperationRegistry): void {
                    if ($blocker = $mikroOperationRegistry->baseUrlBlocker((string) $value)) {
                        $fail($blocker);
                    }
                },
            ],
            'mikro_api.api_version' => ['sometimes', 'nullable', 'string', Rule::in(['V17'])],
            'mikro_api.application_code' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.application_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.company_code' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.branch_code' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.workstation_code' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.fiscal_year' => ['sometimes', 'nullable', 'string', 'max:20'],
            'mikro_api.user_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mikro_api.timeout_seconds' => ['sometimes', 'required', 'integer', 'min:3', 'max:120'],
            'mikro_api.server_timezone' => ['sometimes', 'required', 'timezone'],
            'mikro_api.license_status' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.app_customer_license_status' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.read_sync_enabled' => ['sometimes', 'boolean'],
            'mikro_api.write_enabled' => ['sometimes', 'boolean'],
            'mikro_api.write_approval_required' => ['sometimes', 'boolean'],
            'mikro_api.operation_catalog_status' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mikro_api.operation_controls' => ['sometimes', 'array'],
        ], [
            'nac_sms.validity.min' => 'Single SMS geçerlilik süresi 60-1440 aralığında olmalıdır.',
            'nac_sms.validity.max' => 'Single SMS geçerlilik süresi 60-1440 aralığında olmalıdır.',
        ]);

        return response()->json([
            'messaging_settings' => $this->redactRecipientValues($settings->update($data)),
        ]);
    }

    /**
     * Keep recipient values in the authoritative store while API responses
     * expose only the existing masks and fingerprints.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactRecipientValues(array $payload): array
    {
        Arr::set($payload, 'global.test_phone', null);
        Arr::set($payload, 'global.shared_test_phone', null);
        Arr::set($payload, 'global.customer_test_phone', null);
        Arr::set($payload, 'global.technician_ops_test_phone', null);
        Arr::set($payload, 'global.ops_whatsapp_phone', null);
        Arr::set($payload, 'global.manual_e2e_allowlisted_phones', []);
        Arr::set($payload, 'nac_sms.test_phone', null);

        return $payload;
    }

    public function reset(TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        return response()->json([
            'messaging_settings' => $this->redactRecipientValues($settings->reset()),
            'message' => 'Mesajlaşma sağlayıcı ayarları güvenli varsayılanlara döndürüldü.',
        ]);
    }

    public function executionModeReadiness(ExternalExecutionControlPlaneService $controlPlane): JsonResponse
    {
        return response()->json([
            'execution_mode' => $controlPlane->payload(),
            'deprecated' => true,
            'canonical_endpoint' => '/api/technical-service/execution-control',
        ]);
    }

    public function updateExecutionMode(
        Request $request,
        ExternalExecutionControlPlaneService $controlPlane,
    ): JsonResponse {
        $this->assertExecutionModePayloadKeys($request);
        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in([
                TechnicalServiceMessagingSettingsService::OUTBOUND_EXECUTION_MODE_LOCAL,
                TechnicalServiceMessagingSettingsService::OUTBOUND_EXECUTION_MODE_LIVE,
            ])],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirmation' => ['nullable', 'string', 'max:40'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);
        if ($data['mode'] === TechnicalServiceMessagingSettingsService::OUTBOUND_EXECUTION_MODE_LIVE
            && ($data['confirmation'] ?? null) !== 'CANLI MODU AÇ') {
            throw ValidationException::withMessages([
                'confirmation' => 'Canlı mod için CANLI MODU AÇ onayı zorunlu.',
            ]);
        }

        $actor = $request->user();
        if ($actor === null) {
            abort(403);
        }
        $correlationId = trim((string) $request->header('X-Request-ID'));
        if (preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $correlationId) !== 1) {
            $correlationId = (string) Str::uuid();
        }

        return response()->json([
            'execution_mode' => $controlPlane->transition(
                (string) $data['mode'],
                (string) $data['reason'],
                $actor,
                (int) $data['expected_revision'],
                isset($data['confirmation']) ? (string) $data['confirmation'] : null,
                $correlationId,
            ),
            'message' => $data['mode'] === TechnicalServiceMessagingSettingsService::OUTBOUND_EXECUTION_MODE_LIVE
                ? 'Global sistem çalışma modu readiness doğrulamasıyla Canlı olarak güncellendi.'
                : 'Global sistem çalışma modu Lokal olarak donduruldu; dış etki kapıları kapalı.',
            'deprecated' => true,
            'canonical_endpoint' => '/api/technical-service/execution-control',
        ]);
    }

    public function enableManualE2E(Request $request, TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        $operation = $request->validate([
            'operation' => ['required', 'string', Rule::in(['prepare', 'open_send_window', 'close_send_window'])],
        ])['operation'];

        if ($operation === 'prepare') {
            $this->assertLifecyclePayloadKeys($request, ['operation']);
            $settings->prepareManualE2E();
            $message = 'Manual E2E run güvenli hazırlık durumuna alındı; gerçek gönderim kapalı ve kuyruk duraklatılmış kaldı.';
        } else {
            $this->assertLifecyclePayloadKeys($request, ['operation', 'active_run_id', 'dispatch_id']);
            $data = $request->validate([
                'active_run_id' => ['required', 'string', 'max:160'],
                'dispatch_id' => ['required', 'integer', 'min:1'],
            ]);
            if ($operation === 'open_send_window') {
                $settings->openManualE2ESendWindow((string) $data['active_run_id'], (int) $data['dispatch_id']);
                $message = 'Exact dispatch için en fazla 30 saniyelik tek kullanımlık gönderim penceresi açıldı; worker otomatik başlatılmadı.';
            } else {
                $settings->closeManualE2ESendWindow((string) $data['active_run_id'], (int) $data['dispatch_id']);
                $message = 'Gönderim penceresi kapatıldı; active run hazırlık durumunda korundu.';
            }
        }

        return response()->json([
            'messaging_settings' => $settings->manualE2ELifecyclePayload(),
            'message' => $message,
        ]);
    }

    public function manualE2EReadiness(TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        return response()->json([
            'manual_e2e_readiness' => Arr::except($settings->manualE2EReadiness(), ['allowlisted_phones']),
        ]);
    }

    public function freezeManualE2E(Request $request, TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        $this->assertLifecyclePayloadKeys($request, ['operation']);
        $request->validate([
            'operation' => ['required', 'string', Rule::in(['freeze'])],
        ]);
        $settings->freezeManualE2E();

        return response()->json([
            'messaging_settings' => $settings->manualE2ELifecyclePayload(),
            'message' => 'Manual E2E durduruldu ve aktif run context kapatıldı.',
        ]);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function assertLifecyclePayloadKeys(Request $request, array $allowed): void
    {
        $unexpected = array_values(array_diff(array_keys($request->all()), $allowed));
        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'operation' => 'Manual E2E lifecycle payload yalnız operation, active run ve exact dispatch alanlarını kabul eder.',
            ]);
        }
    }

    private function assertExecutionModePayloadKeys(Request $request): void
    {
        $allowed = ['mode', 'reason', 'confirmation', 'expected_revision'];
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages([
                'mode' => 'Çalışma modu payload yalnız mode, reason, confirmation ve expected_revision alanlarını kabul eder.',
            ]);
        }
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
                'message' => match ($dispatch->status) {
                    'sent' => 'NAC altyapı test SMS’i shared test telefonuna gönderildi.',
                    'suppressed_real_send_disabled' => 'NAC altyapı testi no-send audit kaydı olarak oluşturuldu; provider çağrısı yapılmadı.',
                    default => 'NAC altyapı test SMS’i başarısız: '.($dispatch->error_message ?: 'Güvenli hata detayı yok.'),
                },
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
            'api_key' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'password' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'token' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'messaging_settings' => $settings->saveMikroApiCredentials($data),
            'message' => 'Mikro API bilgileri encrypted olarak kaydedildi. Tam değerler tekrar gösterilmez.',
        ]);
    }

    public function clearMikroApiCredentials(Request $request, TechnicalServiceMessagingSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'clear_api_key' => ['sometimes', 'boolean'],
            'clear_password' => ['sometimes', 'boolean'],
            'clear_token' => ['sometimes', 'boolean'],
        ]);
        $targets = array_keys(array_filter([
            'api_key' => (bool) ($data['clear_api_key'] ?? false),
            'password' => (bool) ($data['clear_password'] ?? false),
            'token' => (bool) ($data['clear_token'] ?? false),
        ]));

        if ($targets === []) {
            throw ValidationException::withMessages([
                'credentials' => 'Temizlenecek Mikro secret alanı açıkça seçilmelidir.',
            ]);
        }

        return response()->json([
            'messaging_settings' => $settings->clearMikroApiCredentials($targets),
            'message' => 'Seçilen Mikro API secret bilgileri temizlendi.',
        ]);
    }

    public function testMikroApiConnection(
        TechnicalServiceMessagingSettingsService $settings,
        MikroApiClient $client,
    ): JsonResponse {
        $readiness = $settings->payload()['mikro_api'];

        if (! ($readiness['health_configuration_ready'] ?? false)) {
            return response()->json([
                'mikro_connection' => [
                    'status' => null,
                    'success' => false,
                    'error_code' => 'MIKRO_HEALTH_CONFIGURATION_MISSING',
                    'duration_ms' => 0,
                    'result_count' => 0,
                    'normalized_data' => [],
                    'source' => 'mikro_api',
                    'freshness_at' => now()->toIso8601String(),
                    'correlation_id' => null,
                ],
                'blocker_codes' => $readiness['health_blocker_codes'] ?? [],
                'message' => 'Mikro HealthCheck için güvenli private base URL ayarı bekleniyor.',
            ], 409);
        }

        $result = $client->healthCheck();
        $messagingSettings = $settings->recordMikroHealthCheckResult($result);

        return response()->json([
            'mikro_connection' => $result,
            'messaging_settings' => $messagingSettings,
            'message' => $result['success']
                ? 'Mikro HealthCheck başarılı.'
                : 'Mikro HealthCheck güvenli biçimde tamamlanamadı.',
        ], $result['success'] ? 200 : 503);
    }

    public function runMikroAuthenticatedReadCanary(
        MikroAuthenticatedReadCanaryService $canaries,
    ): JsonResponse {
        $eligibility = $canaries->eligibility();
        if (! $eligibility['allowed']) {
            return response()->json([
                'mikro_canaries' => null,
                'blocker_codes' => $eligibility['blocker_codes'],
                'operations' => $eligibility['operations'],
                'message' => 'Authenticated Mikro READ canary güvenlik kapısı hazır değil.',
            ], 409);
        }

        $result = $canaries->run();

        return response()->json([
            'mikro_canaries' => $result,
            'message' => $result['success']
                ? 'Dört authenticated Mikro READ canary başarıyla tamamlandı.'
                : 'Authenticated Mikro READ canary güvenli biçimde tamamlanamadı.',
        ], $result['success'] ? 200 : 503);
    }

    public function resetMikroApiCircuit(
        string $operationKey,
        TechnicalServiceMessagingSettingsService $settings,
        MikroOperationRegistry $registry,
        MikroRuntimeState $runtimeState,
    ): JsonResponse {
        try {
            $registry->read($operationKey);
        } catch (\DomainException $exception) {
            return response()->json([
                'message' => 'Mikro operasyonu circuit reset için uygun değil.',
                'error_code' => $exception->getMessage(),
            ], 422);
        }

        $origin = trim((string) ($settings->mikroApiConnectionContext()['base_url'] ?? ''));
        if ($origin === '') {
            return response()->json([
                'message' => 'Mikro base URL eksik.',
                'error_code' => 'MIKRO_CONFIGURATION_MISSING',
            ], 409);
        }

        $runtimeState->resetCircuit($origin, $operationKey);

        return response()->json([
            'message' => 'Mikro circuit kontrollü biçimde sıfırlandı.',
            'operation_key' => $operationKey,
            'circuit_state' => 'CLOSED',
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
