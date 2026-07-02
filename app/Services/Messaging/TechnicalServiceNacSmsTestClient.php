<?php

namespace App\Services\Messaging;

use App\Models\IntegrationProviderCredential;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TechnicalServiceNacSmsTestClient
{
    public function __construct(
        private readonly TechnicalServiceMessagingSettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $input
     */
    public function send(array $preview, string $phone, array $input, ?User $actor = null): TechnicalServiceMessageDispatch
    {
        $message = trim((string) ($preview['rendered_body'] ?? ''));
        if ($message === '') {
            throw ValidationException::withMessages([
                'template' => 'SMS şablon önizleme metni boş; test mesajı gönderilemez.',
            ]);
        }

        return $this->sendDirect(
            event: 'template_test_sms',
            phone: $phone,
            input: $input,
            actor: $actor,
            message: $message,
            messageSource: 'rendered_template_preview',
            preview: $preview,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function sendProviderTest(string $phone, array $input, ?User $actor = null): TechnicalServiceMessageDispatch
    {
        return $this->sendDirect(
            event: 'provider_test_sms',
            phone: $phone,
            input: $input,
            actor: $actor,
            message: $this->providerTestMessage(),
            messageSource: 'provider_infrastructure_test',
            preview: [],
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $preview
     */
    private function sendDirect(
        string $event,
        string $phone,
        array $input,
        ?User $actor,
        string $message,
        string $messageSource,
        array $preview,
    ): TechnicalServiceMessageDispatch {
        if (! (bool) ($input['real_sms_confirmed'] ?? false)) {
            throw ValidationException::withMessages([
                'real_sms_confirmed' => 'NAC gerçek test SMS gönderimi için açık SMS onayı zorunlu.',
            ]);
        }

        $payload = $this->settings->payload();
        $nac = $payload['nac_sms'] ?? [];
        $credential = $this->credential();

        $blocking = $this->blockingReasons($nac, $credential, $phone);
        if ($blocking !== []) {
            throw ValidationException::withMessages([
                'nac_sms' => implode(' ', $blocking),
            ]);
        }

        $endpointUrl = $this->endpointUrl($nac);
        $internalTestCode = $this->internalTestCode();
        $previousPayloadHash = $this->previousPayloadHash($phone, $event);

        $dispatch = TechnicalServiceMessageDispatch::query()->create([
            'event' => $event,
            'target_type' => 'shared_test_phone',
            'original_phone' => $phone,
            'target_phone' => $phone,
            'test_mode' => true,
            'status' => TechnicalServiceMessageDispatch::STATUS_NOT_CONFIGURED,
            'request_payload' => [
                'provider' => 'nac_sms',
                'channel' => 'sms',
                'test_type' => $event,
                'endpoint' => 'POST '.$this->endpointPath($nac),
                'endpoint_profile' => $nac['profile'] ?? 'legacy_working_http_9587',
                'request_shape' => $nac['request_shape'] ?? 'legacy_working_minimal',
                'message_type' => $input['message_type'] ?? null,
                'template_key' => $input['template_key'] ?? null,
                'target_role' => 'shared_test_phone',
                'internal_test_code' => $internalTestCode,
                'content_source' => $messageSource,
                'preview_text_validated' => (string) ($preview['rendered_body'] ?? ''),
                'sms' => $preview['sms'] ?? null,
                'previous_payload_hash' => $previousPayloadHash,
            ],
            'sent_by' => $actor?->id,
        ]);

        $packageCode = $this->packageCode($dispatch->id);
        $title = $this->packageTitle($event, $packageCode);
        $customId = $this->customId($dispatch->id, $internalTestCode);
        $encoding = $this->encodingForContent($nac, $message);
        $requestPayload = $this->requestPayload($nac, $phone, $message, $customId, $title, $encoding);
        $payloadHash = $this->payloadHash($requestPayload);

        $dispatch->forceFill([
            'request_payload' => [
                ...($dispatch->request_payload ?? []),
                'test_code' => $packageCode,
                'package_code' => $packageCode,
                'title' => $title,
                'text' => $message,
                'content_preview' => mb_substr($message, 0, 240),
                'template_body_hash' => $messageSource === 'rendered_template_preview'
                    ? hash('sha256', $message)
                    : null,
                'custom_id' => $customId,
                'encoding' => $encoding,
                'payload_hash' => $payloadHash,
                'nac_payload_shape' => $this->redactPayload($requestPayload),
            ],
        ])->save();

        try {
            $response = Http::timeout(15)
                ->withBasicAuth((string) $credential?->username_encrypted, (string) $credential?->password_encrypted)
                ->acceptJson()
                ->asJson()
                ->post($endpointUrl, $requestPayload);

            $body = $response->json();
            $body = is_array($body) ? $body : ['raw' => mb_substr($response->body(), 0, 1000)];
            $pkgId = $this->pkgId($body);
            $sent = $response->successful() && $pkgId !== null;

            $dispatch->forceFill([
                'status' => $sent
                    ? TechnicalServiceMessageDispatch::STATUS_SENT
                    : TechnicalServiceMessageDispatch::STATUS_FAILED,
                'response_payload' => [
                    'status' => $response->status(),
                    'pkgID' => $pkgId,
                    'test_code' => $packageCode,
                    'package_code' => $packageCode,
                    'customID' => $customId,
                    'test_type' => $event,
                    'encoding' => $encoding,
                    'payload_hash' => $payloadHash,
                    'previous_payload_hash' => $previousPayloadHash,
                    'duplicate' => $this->isDuplicateResponse($body),
                    'body' => $this->redactPayload($body),
                ],
                'error_message' => $sent ? null : $this->errorMessage(
                    $body,
                    $response->body(),
                    $response->status(),
                    $payloadHash,
                    $previousPayloadHash,
                    $event,
                ),
                'sent_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $dispatch->forceFill([
                'status' => TechnicalServiceMessageDispatch::STATUS_FAILED,
                'response_payload' => [
                    'status' => 'exception',
                    'test_code' => $packageCode,
                    'package_code' => $packageCode,
                    'customID' => $customId,
                    'test_type' => $event,
                    'encoding' => $encoding,
                    'payload_hash' => $payloadHash,
                    'previous_payload_hash' => $previousPayloadHash,
                    'message' => $this->redactText($exception->getMessage()),
                ],
                'error_message' => 'NAC endpoint erişilemedi: scheme/host/port/path kontrol edin. '.$this->redactText($exception->getMessage()),
                'sent_at' => now(),
            ])->save();
        }

        return $dispatch;
    }

    private function credential(): ?IntegrationProviderCredential
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
    private function blockingReasons(array $nac, ?IntegrationProviderCredential $credential, string $phone): array
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
            $blocking[] = 'Ortak test telefonu eksik veya geçersiz.';
        }

        return $blocking;
    }

    /**
     * @param  array<string, mixed>  $nac
     * @return array<string, mixed>
     */
    private function requestPayload(
        array $nac,
        string $phone,
        string $message,
        string $customId,
        string $title,
        int $encoding,
    ): array {
        if (($nac['request_shape'] ?? 'legacy_working_minimal') === 'legacy_working_minimal') {
            return [
                'type' => 1,
                'sendingType' => 0,
                'title' => $title,
                'content' => $message,
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
            'content' => $message,
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
    private function endpointUrl(array $nac): string
    {
        $scheme = in_array($nac['scheme'] ?? null, ['http', 'https'], true) ? $nac['scheme'] : 'http';
        $host = trim((string) ($nac['host'] ?? ''));
        $port = (int) ($nac['port'] ?? 9587);

        return "{$scheme}://{$host}:{$port}".$this->endpointPath($nac);
    }

    /**
     * @param  array<string, mixed>  $nac
     */
    private function endpointPath(array $nac): string
    {
        return '/'.ltrim(trim((string) ($nac['path'] ?? '/sms/create')), '/');
    }

    /**
     * @param  array<string, mixed>  $nac
     */
    private function internalTestCode(): string
    {
        return 'T'.now()->format('Ymd-His').'-'.Str::upper(Str::random(4));
    }

    private function packageCode(int $dispatchId): string
    {
        return 'B'.str_pad((string) $dispatchId, 3, '0', STR_PAD_LEFT);
    }

    private function packageTitle(string $event, string $packageCode): string
    {
        $prefix = $event === 'template_test_sms' ? 'EMAKS TPL' : 'EMAKS TEST';

        return mb_substr("{$prefix} {$packageCode}", 0, 50);
    }

    private function providerTestMessage(): string
    {
        return 'EMAKS Prime SMS altyapı testi. Gönderim zamanı: '.now()->timezone(config('app.timezone'))->format('d.m.Y H:i').'.';
    }

    private function customId(int $dispatchId, string $testCode): string
    {
        return mb_substr("nac-test-{$dispatchId}-{$testCode}", 0, 100);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function previousPayloadHash(string $phone, string $event): ?string
    {
        $payload = TechnicalServiceMessageDispatch::query()
            ->where('event', $event)
            ->where('target_type', 'shared_test_phone')
            ->where('target_phone', $phone)
            ->latest('id')
            ->value('request_payload');

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $hash = is_array($payload) ? Arr::get($payload, 'payload_hash') : null;

        return is_scalar($hash) && trim((string) $hash) !== '' ? (string) $hash : null;
    }

    /**
     * @param  array<string, mixed>  $nac
     */
    private function encodingForContent(array $nac, string $message): int
    {
        if (preg_match('/[ÇĞİÖŞÜçğıöşü]/u', $message) === 1) {
            return 1;
        }

        if (preg_match('/[^\x00-\x7F]/u', $message) === 1) {
            return 2;
        }

        $configured = (int) ($nac['encoding'] ?? 0);

        return in_array($configured, [0, 1, 2], true) ? $configured : 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactPayload(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $normalized = mb_strtolower((string) $key);
            if (str_contains($normalized, 'password')
                || str_contains($normalized, 'authorization')
                || str_contains($normalized, 'token')
                || str_contains($normalized, 'secret')) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redactPayload($value) : $value;
        }

        return $redacted;
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
    private function errorMessage(
        array $body,
        string $fallback,
        int $httpStatus,
        string $payloadHash,
        ?string $previousPayloadHash,
        string $event,
    ): string {
        $status = Arr::get($body, 'err.status')
            ?? Arr::get($body, 'status');
        $code = Arr::get($body, 'err.code')
            ?? Arr::get($body, 'error.code')
            ?? Arr::get($body, 'code');
        $message = Arr::get($body, 'err.message')
            ?? Arr::get($body, 'error.message')
            ?? Arr::get($body, 'message')
            ?? $fallback
            ?: 'NAC SMS provider yanıtı başarısız.';

        if ($this->isDuplicateResponse($body)) {
            $duplicateText = $event === 'template_test_sms'
                ? 'NAC duplicate engeli: Aynı içerik aynı test telefonuna kısa süre içinde gönderilmiş olabilir. Şablon metni değiştirilmeden tekrar gönderim provider tarafından engellendi.'
                : 'NAC duplicate engeli: Bu içerik/numara kombinasyonu son 30 dakika içinde gönderilmiş. Yeni provider altyapı testi daha sonra tekrar denenmeli. NAC aynı numaraya kısa aralıkta benzer paket engeli uyguluyor olabilir.';
            $hashMessage = $previousPayloadHash
                ? " Benzersiz title/customID ile denendi. Önceki payload hash: {$previousPayloadHash}; güncel payload hash: {$payloadHash}."
                : " Benzersiz title/customID ile denendi. Güncel payload hash: {$payloadHash}.";

            return mb_substr(
                "HTTP {$httpStatus} - NAC status {$status} - NAC code ERR_SMS_PKG_DUPLICATION - {$duplicateText}".$hashMessage,
                0,
                1000,
            );
        }

        $safe = $this->fieldHint((string) $message);
        $parts = ["HTTP {$httpStatus}"];
        if (is_scalar($status) && trim((string) $status) !== '') {
            $parts[] = 'NAC status '.trim((string) $status);
        }
        if (is_scalar($code) && trim((string) $code) !== '') {
            $parts[] = 'NAC code '.trim((string) $code);
        }
        $parts[] = $safe ?? $this->redactText((string) $message);

        return mb_substr(implode(' - ', array_unique($parts)), 0, 1000);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function isDuplicateResponse(array $body): bool
    {
        $code = Arr::get($body, 'err.code')
            ?? Arr::get($body, 'error.code')
            ?? Arr::get($body, 'code');

        return is_scalar($code) && trim((string) $code) === 'ERR_SMS_PKG_DUPLICATION';
    }

    private function fieldHint(string $message): ?string
    {
        $normalized = mb_strtolower($message);

        if (str_contains($normalized, 'validity')) {
            return 'Single SMS validity 60-1440 aralığında olmalıdır.';
        }

        if (str_contains($normalized, 'auth') || str_contains($normalized, 'password') || str_contains($normalized, 'credential')) {
            return 'NAC kimlik doğrulama başarısız. Kullanıcı adı/şifreyi kontrol edin.';
        }

        if (str_contains($normalized, 'sender')) {
            return 'Gönderen başlığı NAC hesabında tanımlı değil veya yetkili değil.';
        }

        if (str_contains($normalized, 'number') || str_contains($normalized, 'phone')) {
            return 'Test telefonu NAC formatına uygun değil.';
        }

        return null;
    }

    private function redactText(string $text): string
    {
        $redacted = preg_replace(
            '/(password|passwd|secret|token|authorization|basic)\s*[:=]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $text,
        );

        return trim((string) ($redacted ?: 'NAC SMS işlemi başarısız.'));
    }
}
