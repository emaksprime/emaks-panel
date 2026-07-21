<?php

namespace App\Services\Messaging;

use App\Models\IntegrationProviderCredential;
use App\Models\TechnicalServiceMessageDispatch;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TechnicalServiceMessageProviderRouter
{
    public function __construct(
        private readonly TechnicalServiceMessagingSettingsService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dispatch(
        TechnicalServiceMessageDispatch $dispatch,
        bool $noExternal = false,
        array $allowlistedPhones = [],
        ?string $expectedSmokeRunId = null,
        ?string $manualE2EClaimNonce = null,
        ?string $normalOutboundClaimNonce = null,
    ): array {
        try {
            $this->settings->assertManualE2ELifecycleStateValid();
        } catch (Throwable) {
            return $this->blocked('manual_e2e_lifecycle_invalid', 'Manual E2E lifecycle durumu provider gönderimine uygun değil.');
        }

        $provider = (string) ($dispatch->provider_key ?: 'null_local');
        $manualE2e = $this->isManualE2eDispatch($dispatch);
        $manualContext = $this->settings->manualE2EContext();
        $activeManualE2e = $manualContext->enabled()
            || $manualContext->activeRunId() !== null
            || $manualContext->phase() !== TechnicalServiceMessagingSettingsService::MANUAL_E2E_PHASE_FROZEN;

        if (! $noExternal && $activeManualE2e && ! $manualE2e) {
            return $this->blocked('manual_e2e_exact_claim_required', 'Aktif Manual E2E sırasında yalnız exact persisted claim dispatch’i provider’a ulaşabilir.');
        }
        if (! $noExternal && $manualE2e && trim((string) $manualE2EClaimNonce) === '') {
            return $this->blocked('manual_e2e_transport_claim_required', 'Manual E2E provider gönderimi için tek kullanımlık persisted claim zorunlu.');
        }

        if ($provider === 'null_local') {
            return [
                'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED,
                'provider_status' => 'dry_run',
                'provider_message_id' => null,
                'response' => ['provider' => 'null_local', 'external_call' => false],
                'error' => null,
            ];
        }

        if (str_starts_with($provider, 'voibot')) {
            return $this->blocked('contract_pending', 'Voibot sağlayıcı sözleşmesi/API netleşmeden gönderim kapalı.');
        }

        if ($provider === 'future_sms_provider') {
            return $this->blocked('provider_pending', 'Gelecek SMS sağlayıcısı henüz aktif değil.');
        }

        if ($provider === 'nac_sms') {
            if (! $noExternal && $manualE2e) {
                return $this->sendNacSms($dispatch, (string) $manualE2EClaimNonce, null);
            }

            if (! $noExternal && $this->canRunControlledSmoke($dispatch, $allowlistedPhones, $expectedSmokeRunId)) {
                return $this->sendNacSms($dispatch, null, $normalOutboundClaimNonce);
            }

            return $this->fakeableAccepted($dispatch, $noExternal, 'nac_sms', 'direct_laravel');
        }

        if ($provider === 'evo_whatsapp') {
            if (! $noExternal && $manualE2e) {
                return $this->sendEvoWhatsApp($dispatch, (string) $manualE2EClaimNonce, null);
            }

            if (! $noExternal && $this->canRunControlledSmoke($dispatch, $allowlistedPhones, $expectedSmokeRunId)) {
                return $this->sendEvoWhatsApp($dispatch, null, $normalOutboundClaimNonce);
            }

            return $this->fakeableAccepted($dispatch, $noExternal, 'evo_whatsapp', 'evo_adapter');
        }

        return $this->blocked('provider_unknown', 'Bilinmeyen mesaj sağlayıcısı.');
    }

    public function providerReady(string $provider): bool
    {
        $payloadRoot = $this->settings->payload();
        if ($provider === 'nac_sms') {
            return (bool) data_get($payloadRoot, 'nac_sms.enabled', false);
        }

        $providers = collect($this->settings->payload()['providers'] ?? []);
        $payload = $providers->firstWhere('key', $provider);

        if ($provider === 'null_local') {
            return true;
        }

        return is_array($payload)
            && (bool) ($payload['enabled'] ?? false)
            && (bool) ($payload['contract_confirmed'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeableAccepted(TechnicalServiceMessageDispatch $dispatch, bool $noExternal, string $provider, string $transport): array
    {
        if (! $this->providerReady($provider)) {
            return $this->blocked('provider_not_ready', 'Provider kapalı veya readiness eksik.');
        }

        if ($noExternal) {
            return [
                'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED,
                'provider_status' => 'no_external',
                'provider_message_id' => null,
                'response' => [
                    'provider' => $provider,
                    'transport' => $transport,
                    'external_call' => false,
                ],
                'error' => null,
            ];
        }

        if ($dispatch->recipient_role !== 'test') {
            return $this->blocked('business_send_disabled_rel4d', 'REL-4D provider router business gönderimi yapmaz; REL-4E/REL-4F bekleniyor.');
        }

        return [
            'status' => TechnicalServiceMessageDispatch::STATUS_TEST_SENT,
            'provider_status' => 'fake_accepted',
            'provider_message_id' => $provider.'-fake-'.$dispatch->id,
            'response' => [
                'provider' => $provider,
                'transport' => $transport,
                'external_call' => false,
                'test_only' => true,
            ],
            'error' => null,
        ];
    }

    /**
     * @param  array<int, string>  $allowlistedPhones
     */
    private function canRunControlledSmoke(
        TechnicalServiceMessageDispatch $dispatch,
        array $allowlistedPhones,
        ?string $expectedSmokeRunId = null,
    ): bool {
        $dispatchRunId = TechnicalServiceManualE2ERunContext::dispatchRunId((array) $dispatch->metadata);
        $expectedSmokeRunId = TechnicalServiceManualE2ERunContext::normalizeRunId($expectedSmokeRunId);

        return $allowlistedPhones !== []
            && $this->targetIsAllowlisted($dispatch, $allowlistedPhones)
            && filter_var(data_get($dispatch->metadata, 'test_smoke', false), FILTER_VALIDATE_BOOL)
            && ($expectedSmokeRunId !== null ? $dispatchRunId === $expectedSmokeRunId : $dispatchRunId !== null);
    }

    private function isManualE2eDispatch(TechnicalServiceMessageDispatch $dispatch): bool
    {
        return filter_var(data_get($dispatch->metadata, 'manual_e2e', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<int, string>  $allowlistedPhones
     */
    private function targetIsAllowlisted(TechnicalServiceMessageDispatch $dispatch, array $allowlistedPhones): bool
    {
        $target = $this->normalizePhone($dispatch->target_phone);
        if ($target === '') {
            return false;
        }

        $allowed = array_filter(array_map(
            fn (string $phone): string => $this->normalizePhone($phone),
            $allowlistedPhones,
        ));

        return in_array($target, $allowed, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendEvoWhatsApp(
        TechnicalServiceMessageDispatch $dispatch,
        ?string $manualE2EClaimNonce,
        ?string $normalOutboundClaimNonce,
    ): array {
        if (! $this->providerReady('evo_whatsapp')) {
            return $this->blocked('provider_not_ready', 'Evo WhatsApp provider kapalı veya readiness eksik.');
        }

        $body = $dispatch->bodyForProvider();
        $validationErrors = $dispatch->providerBodyValidationErrors();
        if ($validationErrors !== []) {
            return $this->blocked('invalid_dispatch_body', implode(' ', $validationErrors));
        }
        $roleBodyValidation = $dispatch->roleBodyValidationErrors();
        if ($roleBodyValidation !== []) {
            return $this->blocked('role_body_mismatch', implode(' ', $roleBodyValidation));
        }
        $bodyHash = hash('sha256', $body);
        $targetPhone = $this->normalizePhone($dispatch->target_phone);

        $directConfig = $this->evoDirectApiConfig($this->evoCredential());
        if (! (bool) $directConfig['ready']) {
            return $this->blocked(
                'evo_direct_api_missing',
                'Evo WhatsApp queue gönderimi n8n webhook ile hedefi garanti etmiyor. Direct Evolution API base URL/instance/API key profili olmadan role-specific dispatch gönderimi engellendi.',
            );
        }

        return $this->sendEvoDirectApi(
            $dispatch,
            $directConfig,
            $targetPhone,
            $body,
            $bodyHash,
            $manualE2EClaimNonce,
            $normalOutboundClaimNonce,
        );
    }

    /**
     * @param  array{ready:bool,base_url?:string,instance_name?:string,api_key?:string,delay?:int,link_preview?:bool}  $directConfig
     * @return array<string, mixed>
     */
    private function sendEvoDirectApi(
        TechnicalServiceMessageDispatch $dispatch,
        array $directConfig,
        string $targetPhone,
        string $body,
        string $bodyHash,
        ?string $manualE2EClaimNonce,
        ?string $normalOutboundClaimNonce,
    ): array {
        $url = rtrim((string) $directConfig['base_url'], '/').'/message/sendText/'.rawurlencode((string) $directConfig['instance_name']);
        $targetType = $dispatch->target_type ?: $dispatch->recipient_role ?: 'explicit_phone';
        $dispatchEvent = (string) ($dispatch->event ?: $dispatch->message_type ?: 'message_dispatch');
        $auditTarget = $manualE2EClaimNonce !== null ? $dispatch->effective_target_phone_mask : $targetPhone;
        $recipientFingerprint = $manualE2EClaimNonce !== null ? hash('sha256', $targetPhone) : null;

        $payload = [
            'number' => $targetPhone,
            'text' => $body,
            'linkPreview' => (bool) ($directConfig['link_preview'] ?? false),
        ];

        if ((int) ($directConfig['delay'] ?? 0) > 0) {
            $payload['delay'] = (int) $directConfig['delay'];
        }

        $permit = $this->consumeManualE2ETransportPermit($dispatch, $manualE2EClaimNonce);
        if ($permit !== null) {
            return $permit;
        }
        if ($manualE2EClaimNonce === null) {
            $permit = $this->consumeNormalOutboundTransportPermit($dispatch, $normalOutboundClaimNonce);
            if ($permit !== null) {
                return $permit;
            }
        }

        try {
            $request = Http::timeout(15);
            if ($manualE2EClaimNonce !== null) {
                $request = $request->withOptions(['allow_redirects' => false]);
            }
            $response = $request
                ->acceptJson()
                ->asJson()
                ->withHeaders(['apikey' => (string) $directConfig['api_key']])
                ->post($url, $payload);
            $responseBody = $response->json();
            $responseBody = is_array($responseBody)
                ? $responseBody
                : ['raw' => mb_substr($response->body(), 0, 1000)];
            $providerMessageId = $this->providerMessageId($responseBody);

            if ($response->successful() && $providerMessageId !== null) {
                return [
                    'status' => TechnicalServiceMessageDispatch::STATUS_SENT,
                    'provider_status' => (string) $response->status(),
                    'provider_message_id' => $providerMessageId,
                    'response' => [
                        'http_status' => $response->status(),
                        'provider' => 'evo_whatsapp',
                        'transport' => 'evolution_direct_api',
                        'dispatch_body_hash' => $bodyHash,
                        'provider_payload_body_hash' => $bodyHash,
                        'provider_payload_body_matches_dispatch' => true,
                        'provider_request_event' => $dispatchEvent,
                        'provider_request_dispatch_event' => $dispatchEvent,
                        'provider_request_transport_event' => 'evolution_direct_api',
                        'provider_request_target_phone' => $auditTarget,
                        'provider_request_recipient_fingerprint' => $recipientFingerprint,
                        'provider_request_target_type' => $targetType,
                        'provider_request_recipient_role' => $dispatch->recipient_role,
                        'provider_request_preview' => $this->providerRequestPreview($body, $manualE2EClaimNonce !== null),
                        'body' => $this->redactPayload($responseBody, $manualE2EClaimNonce !== null),
                    ],
                    'error' => null,
                    'transport_started' => true,
                    'ambiguous' => false,
                ];
            }

            return [
                'status' => $response->successful()
                    ? TechnicalServiceMessageDispatch::STATUS_SENDING
                    : TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR,
                'provider_status' => $response->successful()
                    ? 'accepted_without_message_id'
                    : (string) $response->status(),
                'provider_message_id' => null,
                'response' => [
                    'http_status' => $response->status(),
                    'provider' => 'evo_whatsapp',
                    'transport' => 'evolution_direct_api',
                    'dispatch_body_hash' => $bodyHash,
                    'provider_payload_body_hash' => $bodyHash,
                    'provider_payload_body_matches_dispatch' => true,
                    'provider_request_event' => $dispatchEvent,
                    'provider_request_dispatch_event' => $dispatchEvent,
                    'provider_request_transport_event' => 'evolution_direct_api',
                    'provider_request_target_phone' => $auditTarget,
                    'provider_request_recipient_fingerprint' => $recipientFingerprint,
                    'provider_request_target_type' => $targetType,
                    'provider_request_recipient_role' => $dispatch->recipient_role,
                    'provider_request_preview' => $this->providerRequestPreview($body, $manualE2EClaimNonce !== null),
                    'body' => $this->redactPayload($responseBody, $manualE2EClaimNonce !== null),
                ],
                'error' => $response->successful()
                    ? 'Evo provider HTTP kabul yanıtında message ID yok; sonuç belirsiz ve tekrar gönderim kapalı.'
                    : mb_substr('Evo provider yanıtı başarısız: '.$this->redactText($response->body(), $manualE2EClaimNonce !== null), 0, 1000),
                'transport_started' => true,
                'ambiguous' => $response->successful(),
            ];
        } catch (Throwable $exception) {
            return [
                'status' => TechnicalServiceMessageDispatch::STATUS_SENDING,
                'provider_status' => 'exception',
                'provider_message_id' => null,
                'response' => [
                    'provider' => 'evo_whatsapp',
                    'transport' => 'evolution_direct_api',
                    'dispatch_body_hash' => $bodyHash,
                    'provider_payload_body_hash' => $bodyHash,
                    'provider_payload_body_matches_dispatch' => true,
                    'provider_request_event' => $dispatchEvent,
                    'provider_request_dispatch_event' => $dispatchEvent,
                    'provider_request_transport_event' => 'evolution_direct_api',
                    'provider_request_target_phone' => $auditTarget,
                    'provider_request_recipient_fingerprint' => $recipientFingerprint,
                    'provider_request_target_type' => $targetType,
                    'provider_request_recipient_role' => $dispatch->recipient_role,
                    'provider_request_preview' => $this->providerRequestPreview($body, $manualE2EClaimNonce !== null),
                    'message' => $this->redactText($exception->getMessage(), $manualE2EClaimNonce !== null),
                ],
                'error' => 'Evo endpoint erişilemedi: '.$this->redactText($exception->getMessage(), $manualE2EClaimNonce !== null),
                'transport_started' => true,
                'ambiguous' => true,
            ];
        }
    }

    private function evoCredential(): ?IntegrationProviderCredential
    {
        return IntegrationProviderCredential::query()
            ->where('scope', IntegrationProviderCredential::SCOPE_TECHNICAL_SERVICE)
            ->where('provider', 'evo_whatsapp')
            ->where('profile_key', IntegrationProviderCredential::PROFILE_DEFAULT)
            ->where('mode', IntegrationProviderCredential::MODE_LIVE)
            ->first();
    }

    /**
     * @return array{ready:bool,base_url?:string,instance_name?:string,api_key?:string,delay?:int,link_preview?:bool}
     */
    private function evoDirectApiConfig(?IntegrationProviderCredential $credential): array
    {
        $metadata = (array) ($credential?->metadata ?? []);
        $settings = (array) ($this->settings->payload()['evo_whatsapp'] ?? []);
        $baseUrl = trim((string) (
            Arr::get($settings, 'direct_api_base_url')
            ?? Arr::get($metadata, 'direct_api_base_url')
            ?? Arr::get($metadata, 'api_base_url')
            ?? ''
        ));
        $instanceName = trim((string) (
            Arr::get($settings, 'direct_api_instance_name')
            ?? Arr::get($metadata, 'instance_name')
            ?? Arr::get($metadata, 'evolution_instance')
            ?? ''
        ));
        $apiKey = trim((string) ($credential?->api_key_encrypted ?: $credential?->token_encrypted ?: ''));
        $enabled = filter_var(Arr::get($settings, 'direct_api_enabled', Arr::get($metadata, 'direct_api_enabled', true)), FILTER_VALIDATE_BOOL);

        return [
            'ready' => $enabled
                && ($credential?->apiKeyReady() ?? false)
                && $baseUrl !== ''
                && $instanceName !== ''
                && $apiKey !== '',
            'base_url' => $baseUrl,
            'instance_name' => $instanceName,
            'api_key' => $apiKey,
            'delay' => (int) Arr::get($settings, 'delay', Arr::get($metadata, 'delay', 0)),
            'link_preview' => filter_var(Arr::get($settings, 'link_preview', Arr::get($metadata, 'link_preview', false)), FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sendNacSms(
        TechnicalServiceMessageDispatch $dispatch,
        ?string $manualE2EClaimNonce,
        ?string $normalOutboundClaimNonce,
    ): array {
        if (! $this->providerReady('nac_sms')) {
            return $this->blocked('provider_not_ready', 'NAC SMS provider kapalı veya readiness eksik.');
        }

        $payloadRoot = $this->settings->payload();
        $nac = (array) ($payloadRoot['nac_sms'] ?? []);
        $credential = $this->nacCredential();
        $phone = $this->normalizePhone($dispatch->target_phone);
        $body = $dispatch->bodyForProvider();
        $validationErrors = $dispatch->providerBodyValidationErrors();
        if ($validationErrors !== []) {
            return $this->blocked('invalid_dispatch_body', implode(' ', $validationErrors));
        }
        $roleBodyValidation = $dispatch->roleBodyValidationErrors();
        if ($roleBodyValidation !== []) {
            return $this->blocked('role_body_mismatch', implode(' ', $roleBodyValidation));
        }
        $bodyHash = hash('sha256', $body);
        $auditTarget = $manualE2EClaimNonce !== null ? $dispatch->effective_target_phone_mask : $phone;
        $recipientFingerprint = $manualE2EClaimNonce !== null ? hash('sha256', $phone) : null;
        $blocking = $this->nacBlockingReasons($nac, $credential, $phone, $body);

        if ($blocking !== []) {
            return $this->blocked('nac_not_ready', implode(' ', $blocking));
        }

        $title = mb_substr('EMAKS FLOW D'.$dispatch->id, 0, 50);
        $customId = mb_substr('nac-flow-'.$dispatch->id.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)), 0, 100);
        $encoding = $this->smsEncoding($nac, $body);
        $requestPayload = $this->nacRequestPayload($nac, $phone, $body, $title, $customId, $encoding);
        $payloadHash = hash('sha256', json_encode($requestPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $permit = $this->consumeManualE2ETransportPermit($dispatch, $manualE2EClaimNonce);
        if ($permit !== null) {
            return $permit;
        }
        if ($manualE2EClaimNonce === null) {
            $permit = $this->consumeNormalOutboundTransportPermit($dispatch, $normalOutboundClaimNonce);
            if ($permit !== null) {
                return $permit;
            }
        }

        try {
            $request = Http::timeout(15);
            if ($manualE2EClaimNonce !== null) {
                $request = $request->withOptions(['allow_redirects' => false]);
            }
            $response = $request
                ->withBasicAuth((string) $credential?->username_encrypted, (string) $credential?->password_encrypted)
                ->acceptJson()
                ->asJson()
                ->post($this->nacEndpointUrl($nac), $requestPayload);

            $responseBody = $response->json();
            $responseBody = is_array($responseBody)
                ? $responseBody
                : ['raw' => mb_substr($response->body(), 0, 1000)];
            $pkgId = $this->pkgId($responseBody);

            if ($response->successful() && $pkgId !== null) {
                return [
                    'status' => TechnicalServiceMessageDispatch::STATUS_SENT,
                    'provider_status' => (string) $response->status(),
                    'provider_message_id' => $pkgId,
                    'response' => [
                        'http_status' => $response->status(),
                        'provider' => 'nac_sms',
                        'pkgID' => $pkgId,
                        'encoding' => $encoding,
                        'payload_hash' => $payloadHash,
                        'dispatch_body_hash' => $bodyHash,
                        'provider_payload_body_hash' => $bodyHash,
                        'provider_payload_body_matches_dispatch' => true,
                        'provider_request_target_phone' => $auditTarget,
                        'provider_request_recipient_fingerprint' => $recipientFingerprint,
                        'provider_request_target_type' => $dispatch->target_type ?: $dispatch->recipient_role,
                        'provider_request_recipient_role' => $dispatch->recipient_role,
                        'provider_request_preview' => $this->providerRequestPreview($body, $manualE2EClaimNonce !== null),
                        'customID' => $customId,
                        'body' => $this->redactPayload($responseBody, $manualE2EClaimNonce !== null),
                    ],
                    'error' => null,
                    'transport_started' => true,
                    'ambiguous' => false,
                ];
            }

            return [
                'status' => $response->successful()
                    ? TechnicalServiceMessageDispatch::STATUS_SENDING
                    : TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR,
                'provider_status' => $response->successful()
                    ? 'accepted_without_pkgid'
                    : (string) $response->status(),
                'provider_message_id' => null,
                'response' => [
                    'http_status' => $response->status(),
                    'provider' => 'nac_sms',
                    'encoding' => $encoding,
                    'payload_hash' => $payloadHash,
                    'dispatch_body_hash' => $bodyHash,
                    'provider_payload_body_hash' => $bodyHash,
                    'provider_payload_body_matches_dispatch' => true,
                    'provider_request_target_phone' => $auditTarget,
                    'provider_request_recipient_fingerprint' => $recipientFingerprint,
                    'provider_request_target_type' => $dispatch->target_type ?: $dispatch->recipient_role,
                    'provider_request_recipient_role' => $dispatch->recipient_role,
                    'provider_request_preview' => $this->providerRequestPreview($body, $manualE2EClaimNonce !== null),
                    'customID' => $customId,
                    'body' => $this->redactPayload($responseBody, $manualE2EClaimNonce !== null),
                ],
                'error' => $response->successful()
                    ? 'NAC provider HTTP kabul yanıtında pkgID yok; sonuç belirsiz ve tekrar gönderim kapalı.'
                    : $this->nacErrorMessage($responseBody, $response->body(), $response->status(), $manualE2EClaimNonce !== null),
                'transport_started' => true,
                'ambiguous' => $response->successful(),
            ];
        } catch (Throwable $exception) {
            return [
                'status' => TechnicalServiceMessageDispatch::STATUS_SENDING,
                'provider_status' => 'exception',
                'provider_message_id' => null,
                'response' => [
                    'provider' => 'nac_sms',
                    'dispatch_body_hash' => $bodyHash,
                    'provider_payload_body_hash' => $bodyHash,
                    'provider_payload_body_matches_dispatch' => true,
                    'provider_request_target_phone' => $auditTarget,
                    'provider_request_recipient_fingerprint' => $recipientFingerprint,
                    'provider_request_target_type' => $dispatch->target_type ?: $dispatch->recipient_role,
                    'provider_request_recipient_role' => $dispatch->recipient_role,
                    'provider_request_preview' => $this->providerRequestPreview($body, $manualE2EClaimNonce !== null),
                    'message' => $this->redactText($exception->getMessage(), $manualE2EClaimNonce !== null),
                ],
                'error' => 'NAC endpoint erişilemedi: scheme/host/port kontrol edin. '.$this->redactText($exception->getMessage(), $manualE2EClaimNonce !== null),
                'transport_started' => true,
                'ambiguous' => true,
            ];
        }
    }

    /**
     * Returns a blocked result when a Manual E2E permit cannot be consumed.
     *
     * @return array<string, mixed>|null
     */
    private function consumeManualE2ETransportPermit(
        TechnicalServiceMessageDispatch $dispatch,
        ?string $manualE2EClaimNonce,
    ): ?array {
        if ($manualE2EClaimNonce === null) {
            return null;
        }

        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Manual E2E transport izni açık DB transaction içinde tüketilemez.');
        }

        try {
            $this->settings->startManualE2ETransport($dispatch->id, $manualE2EClaimNonce);
        } catch (Throwable) {
            return $this->blocked('manual_e2e_transport_permit_rejected', 'Manual E2E transport izni geçersiz veya daha önce kullanılmış.');
        }

        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Provider HTTP açık DB transaction içinde başlatılamaz.');
        }

        return null;
    }

    /**
     * Consume the durable normal-processor claim before the first HTTP byte can
     * leave this process. A missing, stale, or reused nonce is fail-closed.
     *
     * @return array<string, mixed>|null
     */
    private function consumeNormalOutboundTransportPermit(
        TechnicalServiceMessageDispatch $dispatch,
        ?string $normalOutboundClaimNonce,
    ): ?array {
        if (DB::transactionLevel() !== 0) {
            return $this->blocked('normal_outbound_transaction_open', 'Provider HTTP açık DB transaction içinde başlatılamaz.');
        }

        try {
            $this->settings->assertManualE2EFrozenOutboundLockHeld($dispatch->id);
            $this->settings->startNormalOutboundTransport(
                $dispatch->id,
                (string) $normalOutboundClaimNonce,
            );
        } catch (Throwable) {
            return $this->blocked('normal_outbound_transport_permit_rejected', 'Normal outbound transport claim geçersiz veya daha önce kullanılmış.');
        }

        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Provider HTTP açık DB transaction içinde başlatılamaz.');
        }

        return null;
    }

    private function nacCredential(): ?IntegrationProviderCredential
    {
        return IntegrationProviderCredential::query()
            ->where('scope', IntegrationProviderCredential::SCOPE_TECHNICAL_SERVICE)
            ->where('provider', 'nac_sms')
            ->where('profile_key', IntegrationProviderCredential::PROFILE_DEFAULT)
            ->where('mode', IntegrationProviderCredential::MODE_LIVE)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $nac
     * @return array<int, string>
     */
    private function nacBlockingReasons(array $nac, ?IntegrationProviderCredential $credential, string $phone, string $body): array
    {
        $blocking = [];

        if (! (bool) ($nac['enabled'] ?? false)) {
            $blocking[] = 'NAC SMS sağlayıcısı kapalı.';
        }

        if (! ($credential?->basicAuthReady() ?? false)) {
            $blocking[] = 'NAC SMS Basic Auth bilgileri eksik.';
        }

        if (trim((string) ($nac['sender'] ?? '')) === '') {
            $blocking[] = 'NAC SMS gönderen başlığı eksik.';
        }

        if (trim((string) ($nac['host'] ?? '')) === '' || trim((string) ($nac['path'] ?? '')) === '') {
            $blocking[] = 'NAC SMS host/path eksik.';
        }

        if (preg_match('/^90\d{10}$/', $phone) !== 1) {
            $blocking[] = 'Hedef telefon NAC formatına uygun değil.';
        }

        if ($body === '') {
            $blocking[] = 'Gönderilecek SMS içeriği boş.';
        }

        return $blocking;
    }

    /**
     * @param  array<string, mixed>  $nac
     * @return array<string, mixed>
     */
    private function nacRequestPayload(array $nac, string $phone, string $body, string $title, string $customId, int $encoding): array
    {
        if (($nac['request_shape'] ?? 'legacy_working_minimal') === 'legacy_working_minimal') {
            return [
                'type' => 1,
                'sendingType' => 0,
                'title' => $title,
                'content' => $body,
                'number' => (int) $phone,
                'encoding' => $encoding,
                'sender' => $nac['sender'] ?? null,
                'periodicSettings' => null,
                'sendingDate' => null,
                'validity' => (int) ($nac['validity'] ?? 60),
                'pushSettings' => null,
                'customID' => $customId,
            ];
        }

        return array_filter([
            'type' => 1,
            'sendingType' => 0,
            'number' => (int) $phone,
            'sender' => $nac['sender'] ?? null,
            'title' => $title,
            'content' => $body,
            'encoding' => $encoding,
            'validity' => (int) ($nac['validity'] ?? 60),
            'gateway' => $nac['gateway_uuid'] ?? null,
            'commercial' => (bool) ($nac['commercial'] ?? false),
            'skipAhsQuery' => (bool) ($nac['skip_ahs_query'] ?? false),
            'recipientType' => (int) ($nac['recipient_type'] ?? 0),
            'customID' => $customId,
            'pushSettings' => [
                'url' => $nac['report_push_url'] ?? null,
            ],
        ], fn (mixed $value): bool => ! ($value === null || $value === ''));
    }

    /**
     * @param  array<string, mixed>  $nac
     */
    private function nacEndpointUrl(array $nac): string
    {
        $scheme = in_array($nac['scheme'] ?? null, ['http', 'https'], true) ? $nac['scheme'] : 'http';
        $host = trim((string) ($nac['host'] ?? ''));
        $port = (int) ($nac['port'] ?? 9587);
        $path = '/'.ltrim(trim((string) ($nac['path'] ?? '/sms/create')), '/');

        return "{$scheme}://{$host}:{$port}{$path}";
    }

    /**
     * @param  array<string, mixed>  $nac
     */
    private function smsEncoding(array $nac, string $body): int
    {
        if (preg_match('/[ÇĞİÖŞÜçğıöşü]/u', $body) === 1) {
            return 1;
        }

        if (preg_match('/[^\x00-\x7F]/u', $body) === 1) {
            return 2;
        }

        $configured = (int) ($nac['encoding'] ?? 0);

        return in_array($configured, [0, 1, 2], true) ? $configured : 0;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function pkgId(array $body): ?string
    {
        $value = Arr::get($body, 'data.pkgID')
            ?? Arr::get($body, 'data.pkgId')
            ?? Arr::get($body, 'pkgID')
            ?? Arr::get($body, 'pkgId');

        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function providerMessageId(array $body): ?string
    {
        $value = Arr::get($body, 'data.id')
            ?? Arr::get($body, 'data.messageId')
            ?? Arr::get($body, 'key.id')
            ?? Arr::get($body, 'messageId')
            ?? Arr::get($body, 'id');

        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function nacErrorMessage(array $body, string $fallback, int $httpStatus, bool $redactRecipient = false): string
    {
        $status = Arr::get($body, 'err.status') ?? Arr::get($body, 'status');
        $code = Arr::get($body, 'err.code') ?? Arr::get($body, 'error.code') ?? Arr::get($body, 'code');
        $message = Arr::get($body, 'err.message') ?? Arr::get($body, 'error.message') ?? Arr::get($body, 'message') ?? $fallback;

        $parts = ["HTTP {$httpStatus}"];
        if (is_scalar($status) && trim((string) $status) !== '') {
            $parts[] = 'NAC status '.trim((string) $status);
        }
        if (is_scalar($code) && trim((string) $code) !== '') {
            $parts[] = 'NAC code '.trim((string) $code);
        }
        $parts[] = $this->redactText((string) ($message ?: 'NAC SMS provider yanıtı başarısız.'), $redactRecipient);

        return mb_substr(implode(' - ', array_unique($parts)), 0, 1000);
    }

    private function preview(string $body): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', trim($body)) ?: trim($body), 0, 240);
    }

    private function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';
        if (str_starts_with($digits, '0')) {
            return '90'.substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '90'.$digits;
        }

        return $digits;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactPayload(array $payload, bool $redactRecipient = false): array
    {
        foreach ($payload as $key => $value) {
            $normalized = mb_strtolower((string) $key);
            if (str_contains($normalized, 'password')
                || str_contains($normalized, 'passwd')
                || str_contains($normalized, 'authoriz'.'ation')
                || str_contains($normalized, 'apikey')
                || str_contains($normalized, 'api_key')
                || str_contains($normalized, 'api-key')
                || $normalized === 'basic'
                || str_contains($normalized, 'token')
                || str_contains($normalized, 'secret')) {
                $payload[$key] = '[redacted]';
            } elseif ($redactRecipient && (
                str_contains($normalized, 'phone')
                || str_contains($normalized, 'number')
                || str_contains($normalized, 'recipient')
                || str_contains($normalized, 'remotejid')
                || $normalized === 'jid'
                || $normalized === 'to'
            )) {
                $payload[$key] = '[redacted-recipient]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->redactPayload($value, $redactRecipient);
            } elseif ($redactRecipient && is_string($value)) {
                $payload[$key] = $this->redactText($value, true);
            }
        }

        return $payload;
    }

    private function redactText(string $text, bool $redactRecipient = false): string
    {
        $redacted = preg_replace(
            "/([\"']?(?:authorization|basic)[\"']?\\s*[:=]\\s*)(?:\"[^\"]*\"|'[^']*'|[^,;}\\]\\r\\n]+)/i",
            '$1[redacted]',
            $text,
        );
        $redacted = preg_replace(
            "/([\"']?(?:password|passwd|secret|token|api[_-]?key)[\"']?\\s*[:=]\\s*)(?:\"[^\"]*\"|'[^']*'|[^\\s,;}\\]]+)/i",
            '$1[redacted]',
            $redacted ?? $text,
        );

        if ($redactRecipient) {
            $redacted = preg_replace(
                '/(?<!\d)\+?\d(?:[\s().-]*\d){9,}(?!\d)/u',
                '[redacted-phone]',
                (string) $redacted,
            );
        }

        return trim((string) ($redacted ?: 'Provider işlemi başarısız.'));
    }

    private function providerRequestPreview(string $body, bool $manualE2E): string
    {
        return $this->preview($manualE2E ? $this->redactText($body, true) : $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function blocked(string $code, string $message): array
    {
        return [
            'status' => TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR,
            'provider_status' => $code,
            'provider_message_id' => null,
            'response' => ['status' => $code, 'message' => $message],
            'error' => $message,
            'transport_started' => false,
            'ambiguous' => false,
        ];
    }
}
