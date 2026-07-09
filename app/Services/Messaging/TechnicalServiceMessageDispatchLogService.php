<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TechnicalServiceMessageDispatchLogService
{
    private const TIMEZONE = 'Europe/Istanbul';

    private const STATUS_LABELS = [
        TechnicalServiceMessageDispatch::STATUS_SUPPRESSED => 'Baskılandı',
        TechnicalServiceMessageDispatch::STATUS_QUEUED => 'Kuyrukta',
        TechnicalServiceMessageDispatch::STATUS_SENDING => 'Gönderiliyor',
        TechnicalServiceMessageDispatch::STATUS_SENT => 'Gönderildi',
        TechnicalServiceMessageDispatch::STATUS_FAILED => 'Başarısız',
        TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED => 'Tekrar engellendi',
        TechnicalServiceMessageDispatch::STATUS_CANCELLED => 'İptal edildi',
        TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR => 'Sağlayıcı hatası',
        TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED => 'Limit nedeniyle bekliyor',
        TechnicalServiceMessageDispatch::STATUS_COOLDOWN_BLOCKED => 'Bekleme süresinde',
        TechnicalServiceMessageDispatch::STATUS_BLOCKED => 'Bloklandı',
        TechnicalServiceMessageDispatch::STATUS_TEST_SENT => 'Test gönderildi',
        TechnicalServiceMessageDispatch::STATUS_TEST_FAILED => 'Test başarısız',
        TechnicalServiceMessageDispatch::STATUS_NOT_CONFIGURED => 'Yapılandırma eksik',
        TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TEST_FIXTURE => 'Test fixture baskılandı',
        TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TESTING_ENVIRONMENT => 'Test ortamı baskıladı',
        TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED => 'Gerçek gönderim kapalı',
        TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_DUPLICATE => 'Tekrar baskılandı',
        TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_RATE_LIMITED => 'Limit nedeniyle baskılandı',
    ];

    private const CHANNEL_LABELS = [
        'whatsapp' => 'WhatsApp',
        'sms' => 'SMS',
        'voice' => 'Sesli Arama',
        'voice_script' => 'Sesli Arama',
        'system' => 'Sistem',
    ];

    private const PROVIDER_LABELS = [
        'evo_whatsapp' => 'Evo WhatsApp',
        'nac_sms' => 'NAC SMS',
        'null_local' => 'Null Local',
        'system' => 'Sistem',
        'voibot_voice' => 'Voibot',
        'evolution_n8n' => 'Evo WhatsApp',
    ];

    private const ROLE_LABELS = [
        'customer' => 'Müşteri',
        'technician' => 'Usta',
        'ops' => 'OPS',
        'test' => 'Test',
        'internal' => 'İç Sistem',
    ];

    private const MESSAGE_TYPE_LABELS = [
        'appointment_approved_customer' => 'Müşteri randevu onayı',
        'appointment_approved_technician' => 'Usta randevu bildirimi',
        'appointment_updated_customer' => 'Müşteri randevu güncelleme',
        'appointment_updated_technician' => 'Usta randevu güncelleme',
        'customer_approval_request' => 'Müşteri onay talebi',
        'payment_link_customer' => 'Ödeme bağlantısı',
        'payment_received_ops' => 'Ödeme alındı / OPS bildirimi',
        'customer_pays_technician_notice' => 'Ustaya ödeme bilgilendirmesi',
        'assignment_offer_technician' => 'Usta iş teklifi',
        'appointment_proposed_ops' => 'OPS randevu önerisi',
        'earnings_message_technician' => 'Usta hakediş bilgilendirmesi',
        'completion_submitted_ops' => 'Usta işi tamamladı / OPS kontrol',
        'support_request_ops' => 'Destek talebi',
        'job_rejected_ops' => 'Usta işi reddetti',
        'price_revision_requested_ops' => 'Fiyat revizyon talebi',
        'price_revision_response_technician' => 'Usta hakediş revizyon cevabı',
        'final_control_completed_customer' => 'Müşteri son kontrol tamamlandı',
        'activation_code_customer' => 'Müşteri aktivasyon kodu',
        'warranty_started_customer' => 'Müşteri garanti başlangıcı',
        'activation_warranty_customer' => 'Aktivasyon ve garanti bilgilendirmesi',
        'part_request_ops' => 'Parça talebi',
        'part_received_ops' => 'Parça teslim alındı / OPS bildirimi',
        'part_request_technician' => 'Usta parça talebi',
        'part_fee_payment_link_customer' => 'Parça ücreti ödeme bağlantısı',
        'appointment_cancelled_customer' => 'Müşteri randevu iptali',
        'appointment_cancelled_technician' => 'Usta randevu iptali',
        'provider_test_sms' => 'SMS altyapı testi',
        'template_test_sms' => 'SMS şablon testi',
        'provider_test_whatsapp' => 'WhatsApp altyapı testi',
        'template_test_whatsapp' => 'WhatsApp şablon testi',
    ];

    /**
     * @return array<string, mixed>
     */
    public function payload(?Request $request = null): array
    {
        return [
            'summary' => $this->summary(),
            'recent' => $this->list($request),
            'filters' => [
                'providers' => $this->options(['null_local', 'evo_whatsapp', 'nac_sms', 'voibot_voice'], self::PROVIDER_LABELS),
                'channels' => $this->options(['whatsapp', 'sms', 'voice', 'system'], self::CHANNEL_LABELS),
                'recipient_roles' => $this->options(['customer', 'technician', 'ops', 'test', 'internal'], self::ROLE_LABELS),
                'statuses' => $this->options(app(TechnicalServiceMessageDispatchStatusRegistry::class)->statuses(), self::STATUS_LABELS),
                'message_types' => $this->options(array_keys(self::MESSAGE_TYPE_LABELS), self::MESSAGE_TYPE_LABELS),
            ],
            'labels' => $this->labels(),
            'pagination' => $this->paginationMeta($request),
            'warnings' => [
                'Modal ve controller aksiyonları provider çağırmaz; mesajlar sadece kuyruk dispatch kaydı üretir.',
                'Tabloda telefonlar maskeli gösterilir; provider kimlik doğrulama bilgileri bu loglarda yer almaz.',
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        $counts = TechnicalServiceMessageDispatch::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
        $externalCounts = $this->externalProviderQuery()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();

        return [
            'queued' => $externalCounts[TechnicalServiceMessageDispatch::STATUS_QUEUED] ?? 0,
            'sending' => $externalCounts[TechnicalServiceMessageDispatch::STATUS_SENDING] ?? 0,
            'sent' => ($counts[TechnicalServiceMessageDispatch::STATUS_SENT] ?? 0) + ($counts[TechnicalServiceMessageDispatch::STATUS_TEST_SENT] ?? 0),
            'failed' => ($counts[TechnicalServiceMessageDispatch::STATUS_FAILED] ?? 0) + ($counts[TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR] ?? 0) + ($counts[TechnicalServiceMessageDispatch::STATUS_TEST_FAILED] ?? 0),
            'duplicate_blocked' => $counts[TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED] ?? 0,
            'rate_limited' => ($externalCounts[TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED] ?? 0) + ($externalCounts[TechnicalServiceMessageDispatch::STATUS_COOLDOWN_BLOCKED] ?? 0),
            'cancelled' => $counts[TechnicalServiceMessageDispatch::STATUS_CANCELLED] ?? 0,
        ];
    }

    private function externalProviderQuery(): Builder
    {
        return TechnicalServiceMessageDispatch::query()
            ->whereNotIn('provider_key', ['null_local', 'system'])
            ->where(function (Builder $query): void {
                $query->whereNull('channel')
                    ->orWhere('channel', '!=', 'system');
            });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list(?Request $request = null): array
    {
        $paginator = $this->filteredQuery($request)
            ->select([
                'id',
                'status',
                'provider_key',
                'channel',
                'message_type',
                'recipient_role',
                'target_type',
                'effective_target_phone_mask',
                'target_phone',
                'idempotency_key',
                'attempt_count',
                'max_attempts',
                'provider_message_id',
                'last_error_message_redacted',
                'error_message',
                'force_resend_reason',
                'template_key',
                'payload_hash',
                'rendered_body_hash',
                'request_payload',
                'created_by',
                'root_mrn',
                'mrn',
                'srv',
                'request_id',
                'technical_service_request_id',
                'queued_at',
                'sent_at',
                'failed_at',
                'created_at',
            ])
            ->latest('id')
            ->paginate($this->perPage($request));

        return $paginator
            ->getCollection()
            ->map(fn (TechnicalServiceMessageDispatch $dispatch): array => $this->listRow($dispatch))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(TechnicalServiceMessageDispatch $dispatch, ?User $viewer = null): array
    {
        $message = $this->messageContent($dispatch);
        $canViewFullPhone = $this->canViewFullPhone($viewer);
        $providerKey = $dispatch->provider_key
            ?? data_get($dispatch->request_payload, 'provider_key')
            ?? data_get($dispatch->request_payload, 'provider');
        $channel = $dispatch->channel ?? data_get($dispatch->request_payload, 'channel');
        $messageType = $dispatch->message_type
            ?? data_get($dispatch->request_payload, 'message_type')
            ?? data_get($dispatch->request_payload, 'event')
            ?? $dispatch->event;
        $recipientRole = $dispatch->recipient_role
            ?? data_get($dispatch->request_payload, 'recipient_role')
            ?? $dispatch->target_type;

        return [
            'id' => $dispatch->id,
            'status' => $dispatch->status,
            'status_label' => $this->dispatchStatusLabel($dispatch, $providerKey, $channel),
            'status_badge_tone' => $this->statusBadgeTone($dispatch->status),
            'provider_key' => $providerKey,
            'provider_label' => $this->dispatchProviderLabel($dispatch, $providerKey, $channel),
            'provider_missing_label' => $providerKey === null ? 'Eski kayıt / sağlayıcı bilgisi yok' : null,
            'channel' => $channel,
            'channel_label' => $this->channelLabel($channel),
            'channel_missing_label' => $channel === null ? 'Eski kayıt / kanal bilgisi yok' : null,
            'recipient_role' => $recipientRole,
            'recipient_role_label' => $this->roleLabel($recipientRole),
            'message_type' => $messageType,
            'message_type_label' => $this->messageTypeLabel($messageType),
            'target_phone_full' => $canViewFullPhone ? $this->normalizePhone($dispatch->target_phone) : null,
            'target_phone_masked' => $dispatch->effective_target_phone_mask ?? $this->maskPhone($dispatch->target_phone),
            'original_recipient_phone_full' => $canViewFullPhone ? $this->normalizePhone($dispatch->original_phone) : null,
            'original_recipient_phone_masked' => $dispatch->recipient_phone_mask ?? $this->maskPhone($dispatch->original_phone),
            'test_redirect_applied' => (bool) $dispatch->test_redirect_applied,
            'reference' => $this->reference($dispatch),
            'request_id' => $dispatch->request_id ?? $dispatch->technical_service_request_id,
            'template_key' => $dispatch->template_key,
            'template_label' => $dispatch->template_key ?? 'Eski kayıt / template bilgisi yok',
            'template_version' => $dispatch->template_version,
            'channel_policy' => $dispatch->channel_policy,
            'idempotency_key_short' => $dispatch->idempotency_key ? Str::substr($dispatch->idempotency_key, 0, 16) : null,
            'idempotency_label' => $dispatch->idempotency_key ? Str::substr($dispatch->idempotency_key, 0, 16) : 'Eski kayıt / idempotency bilgisi yok',
            'payload_hash' => $dispatch->payload_hash,
            'payload_hash_short' => $dispatch->payload_hash ? Str::substr($dispatch->payload_hash, 0, 16) : null,
            'rendered_body_hash' => $dispatch->rendered_body_hash,
            'attempt_count' => $this->displayAttemptCount($dispatch),
            'max_attempts' => (int) $dispatch->max_attempts,
            'queued_at' => $this->datePayload($dispatch->queued_at),
            'sending_started_at' => $this->datePayload($dispatch->sending_started_at),
            'sent_at' => $this->datePayload($dispatch->sent_at),
            'failed_at' => $this->datePayload($dispatch->failed_at),
            'created_at' => $this->datePayload($dispatch->created_at),
            'display_time' => $this->displayTime($dispatch),
            'provider_message_id' => $dispatch->provider_message_id ?? data_get($dispatch->response_payload, 'pkgID'),
            'provider_status' => $dispatch->provider_status,
            'provider_response_redacted' => $this->redacted($dispatch->provider_response_redacted ?? $dispatch->response_payload ?? []),
            'provider_payload_body_hash' => data_get($dispatch->provider_response_redacted, 'provider_payload_body_hash'),
            'provider_payload_body_matches_dispatch' => data_get($dispatch->provider_response_redacted, 'provider_payload_body_matches_dispatch'),
            'provider_request_target_phone' => data_get($dispatch->provider_response_redacted, 'provider_request_target_phone'),
            'provider_request_target_type' => data_get($dispatch->provider_response_redacted, 'provider_request_target_type'),
            'provider_request_recipient_role' => data_get($dispatch->provider_response_redacted, 'provider_request_recipient_role'),
            'provider_request_preview' => data_get($dispatch->provider_response_redacted, 'provider_request_preview'),
            'provider_payload_warning' => $this->providerPayloadWarning($dispatch),
            'last_error_code' => $dispatch->last_error_code,
            'last_error_redacted' => $dispatch->last_error_message_redacted ?? $dispatch->error_message,
            'parent_dispatch_id' => $dispatch->parent_dispatch_id,
            'force_resend' => (bool) $dispatch->force_resend,
            'force_resend_reason' => $dispatch->force_resend_reason,
            'created_by' => $dispatch->created_by,
            'triggered_by' => $dispatch->triggered_by,
            'rendered_message_content' => $message['content'],
            'message_content_missing_reason' => $message['missing_reason'],
            'message_content_source' => $message['source'],
            'message_preview' => $message['preview'],
            'sms_footer_note' => data_get($dispatch->request_payload, 'sms_footer_note'),
            'technical_keys' => [
                'event' => $dispatch->event,
                'message_type' => $dispatch->message_type,
                'status' => $dispatch->status,
                'provider_key' => $dispatch->provider_key,
                'idempotency_key' => $dispatch->idempotency_key,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(TechnicalServiceMessageDispatch $dispatch): array
    {
        $providerKey = $dispatch->provider_key
            ?? data_get($dispatch->request_payload, 'provider_key')
            ?? data_get($dispatch->request_payload, 'provider');
        $channel = $dispatch->channel ?? data_get($dispatch->request_payload, 'channel');
        $messageType = $dispatch->message_type
            ?? data_get($dispatch->request_payload, 'message_type')
            ?? data_get($dispatch->request_payload, 'event')
            ?? $dispatch->event;
        $recipientRole = $dispatch->recipient_role
            ?? data_get($dispatch->request_payload, 'recipient_role')
            ?? $dispatch->target_type;

        return [
            'id' => $dispatch->id,
            'status' => $dispatch->status,
            'status_label' => $this->dispatchStatusLabel($dispatch, $providerKey, $channel),
            'status_badge_tone' => $this->statusBadgeTone($dispatch->status),
            'provider_key' => $providerKey,
            'provider_label' => $this->dispatchProviderLabel($dispatch, $providerKey, $channel),
            'channel' => $channel,
            'channel_label' => $this->channelLabel($channel),
            'message_type' => $messageType,
            'message_type_label' => $this->messageTypeLabel($messageType),
            'recipient_role' => $recipientRole,
            'recipient_role_label' => $this->roleLabel($recipientRole),
            'target_masked' => $dispatch->effective_target_phone_mask ?? $this->maskPhone($dispatch->target_phone),
            'idempotency_key_short' => $dispatch->idempotency_key ? Str::substr($dispatch->idempotency_key, 0, 12) : null,
            'attempt_count' => $this->displayAttemptCount($dispatch),
            'max_attempts' => (int) $dispatch->max_attempts,
            'provider_message_id' => $dispatch->provider_message_id,
            'template_key' => $dispatch->template_key,
            'payload_hash_short' => $dispatch->payload_hash ? Str::substr($dispatch->payload_hash, 0, 12) : null,
            'message_preview' => $this->messageContent($dispatch)['preview'],
            'last_error_redacted' => $dispatch->last_error_message_redacted ?? $dispatch->error_message,
            'force_resend_reason' => $dispatch->force_resend_reason,
            'created_by' => $dispatch->created_by,
            'reference' => $this->reference($dispatch),
            'created_at' => $this->datePayload($dispatch->created_at),
            'queued_at' => $this->datePayload($dispatch->queued_at),
            'sent_at' => $this->datePayload($dispatch->sent_at),
            'failed_at' => $this->datePayload($dispatch->failed_at),
            'display_time' => $this->displayTime($dispatch),
        ];
    }

    private function filteredQuery(?Request $request): Builder
    {
        $query = TechnicalServiceMessageDispatch::query();

        if (! $request instanceof Request) {
            return $query;
        }

        $this->applyMultiValueFilter($query, $request, 'status', 'status');
        $this->applyMultiValueFilter($query, $request, 'provider', 'provider_key', ['provider_key', 'provider']);
        $this->applyMultiValueFilter($query, $request, 'channel', 'channel', ['channel']);
        $this->applyMultiValueFilter($query, $request, 'recipient_role', 'recipient_role', ['recipient_role', 'target_type']);
        $this->applyMultiValueFilter($query, $request, 'message_type', 'message_type', ['message_type', 'event']);

        if ($request->boolean('only_failed')) {
            $query->whereIn('status', [
                TechnicalServiceMessageDispatch::STATUS_FAILED,
                TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR,
                TechnicalServiceMessageDispatch::STATUS_TEST_FAILED,
            ]);
        }

        if ($request->boolean('only_queued')) {
            $query->whereIn('status', [
                TechnicalServiceMessageDispatch::STATUS_QUEUED,
                TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED,
            ]);
        }

        if ($request->boolean('only_duplicate_blocked')) {
            $query->where('status', TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED);
        }

        if ($request->boolean('only_test')) {
            $query->where(function (Builder $nested): void {
                $nested->where('recipient_role', 'test')
                    ->orWhere('test_mode', true)
                    ->orWhere('test_redirect_applied', true)
                    ->orWhere('message_type', 'like', '%test%');
            });
        }

        if ($request->boolean('only_business')) {
            $query->where('recipient_role', '!=', 'test')
                ->where('test_mode', false)
                ->where('test_redirect_applied', false)
                ->where('message_type', 'not like', '%test%');
        }

        $this->applyDateFilter($query, $request, 'date_from', '>=');
        $this->applyDateFilter($query, $request, 'date_to', '<=');

        $search = trim((string) ($request->query('q') ?? $request->query('search') ?? $request->query('mrn_srv_search') ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $nested) use ($search): void {
                $like = '%'.$search.'%';
                $normalizedPhone = $this->normalizePhone($search);
                $nested->where('mrn', 'like', $like)
                    ->orWhere('root_mrn', 'like', $like)
                    ->orWhere('srv', 'like', $like)
                    ->orWhere('provider_message_id', 'like', $like)
                    ->orWhere('idempotency_key', 'like', $like)
                    ->orWhere('request_payload->body', 'like', $like)
                    ->orWhere('request_payload->rendered_body', 'like', $like)
                    ->orWhere('request_payload->message_preview', 'like', $like)
                    ->orWhere('request_payload->content', 'like', $like)
                    ->orWhere('request_payload->text', 'like', $like)
                    ->orWhere('request_payload->payload->content', 'like', $like)
                    ->when($normalizedPhone !== null && strlen($normalizedPhone) >= 7, function (Builder $phoneQuery) use ($normalizedPhone): void {
                        $phoneQuery->orWhere('target_phone', 'like', '%'.$normalizedPhone.'%');
                    });
            });
        }

        $providerMessageId = trim((string) $request->query('provider_message_id', ''));
        if ($providerMessageId !== '') {
            $query->where('provider_message_id', 'like', '%'.$providerMessageId.'%');
        }

        $phone = trim((string) $request->query('phone', ''));
        if ($phone !== '') {
            $normalized = $this->normalizePhone($phone);
            $query->where(function (Builder $nested) use ($phone, $normalized): void {
                $nested->where('target_phone', 'like', '%'.$normalized.'%')
                    ->orWhere('effective_target_phone_mask', 'like', '%'.$phone.'%');
            });
        }

        return $query;
    }

    private function applyDateFilter(Builder $query, Request $request, string $key, string $operator): void
    {
        $value = trim((string) $request->query($key, ''));
        if ($value === '') {
            return;
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $value, self::TIMEZONE);
        if (! $date instanceof CarbonImmutable) {
            return;
        }

        $boundary = $operator === '>=' ? $date->startOfDay() : $date->endOfDay();
        $query->where('created_at', $operator, $boundary->timezone('UTC'));
    }

    /**
     * @return array<int, string>
     */
    private function arrayFilter(Request $request, string $key): array
    {
        $value = $request->query($key);
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    /**
     * @param  array<int, string>  $payloadKeys
     */
    private function applyMultiValueFilter(
        Builder $query,
        Request $request,
        string $input,
        string $column,
        array $payloadKeys = []
    ): void {
        $values = $this->arrayFilter($request, $input);
        if ($values === []) {
            return;
        }

        $query->where(function (Builder $nested) use ($column, $payloadKeys, $values): void {
            $nested->whereIn($column, $values);

            foreach ($payloadKeys as $payloadKey) {
                $nested->orWhereIn('request_payload->'.$payloadKey, $values);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function paginationMeta(?Request $request): array
    {
        $paginator = $this->filteredQuery($request)->latest('id')->paginate($this->perPage($request));

        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    private function perPage(?Request $request): int
    {
        $perPage = $request instanceof Request ? (int) $request->query('per_page', 50) : 50;

        return max(1, min(100, $perPage));
    }

    private function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        if ($digits === '') {
            return null;
        }

        return substr($digits, 0, 4).'***'.substr($digits, -3);
    }

    private function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        return $digits !== '' ? $digits : null;
    }

    private function statusLabel(?string $value): string
    {
        return self::STATUS_LABELS[$value ?? ''] ?? $this->fallbackLabel($value);
    }

    private function dispatchStatusLabel(TechnicalServiceMessageDispatch $dispatch, ?string $providerKey, ?string $channel): string
    {
        if ($this->isSystemOnlyDispatch($dispatch, $providerKey, $channel)) {
            return 'Sistem kaydı';
        }

        return $this->statusLabel($dispatch->status);
    }

    private function channelLabel(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'Eski kayıt / kanal bilgisi yok';
        }

        return self::CHANNEL_LABELS[$value] ?? $this->fallbackLabel($value);
    }

    private function providerLabel(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'Eski kayıt / sağlayıcı bilgisi yok';
        }

        return self::PROVIDER_LABELS[$value] ?? $this->fallbackLabel($value);
    }

    private function dispatchProviderLabel(TechnicalServiceMessageDispatch $dispatch, ?string $providerKey, ?string $channel): string
    {
        if ($this->isSystemOnlyDispatch($dispatch, $providerKey, $channel)) {
            return 'Dış sağlayıcı yok';
        }

        return $this->providerLabel($providerKey);
    }

    private function isSystemOnlyDispatch(TechnicalServiceMessageDispatch $dispatch, ?string $providerKey, ?string $channel): bool
    {
        $metadata = (array) $dispatch->metadata;
        $payload = (array) $dispatch->request_payload;
        $externalProviderCall = $metadata['external_provider_call'] ?? $payload['external_provider_call'] ?? null;

        if ($providerKey === 'system') {
            return true;
        }

        if ($providerKey === 'null_local' && $channel === 'system') {
            return true;
        }

        return $providerKey === 'null_local' && $externalProviderCall === false;
    }

    private function roleLabel(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'Eski kayıt / rol bilgisi yok';
        }

        return self::ROLE_LABELS[$value] ?? $this->fallbackLabel($value);
    }

    private function messageTypeLabel(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'Eski kayıt / mesaj tipi yok';
        }

        return self::MESSAGE_TYPE_LABELS[$value] ?? $this->fallbackLabel($value);
    }

    private function fallbackLabel(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '-';
        }

        return Str::of($value)->replace('_', ' ')->title()->toString();
    }

    private function statusBadgeTone(?string $status): string
    {
        return match ($status) {
            TechnicalServiceMessageDispatch::STATUS_SENT,
            TechnicalServiceMessageDispatch::STATUS_TEST_SENT => 'success',
            TechnicalServiceMessageDispatch::STATUS_FAILED,
            TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR,
            TechnicalServiceMessageDispatch::STATUS_TEST_FAILED => 'danger',
            TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED,
            TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED,
            TechnicalServiceMessageDispatch::STATUS_COOLDOWN_BLOCKED,
            TechnicalServiceMessageDispatch::STATUS_BLOCKED,
            TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED => 'warning',
            TechnicalServiceMessageDispatch::STATUS_SENDING => 'info',
            default => 'neutral',
        };
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function labels(): array
    {
        return [
            'statuses' => self::STATUS_LABELS,
            'channels' => self::CHANNEL_LABELS,
            'providers' => self::PROVIDER_LABELS,
            'recipient_roles' => self::ROLE_LABELS,
            'message_types' => self::MESSAGE_TYPE_LABELS,
        ];
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<string, string>  $labels
     * @return array<int, array{value: string, label: string}>
     */
    private function options(array $keys, array $labels): array
    {
        return array_values(array_map(fn (string $key): array => [
            'value' => $key,
            'label' => $labels[$key] ?? $this->fallbackLabel($key),
        ], $keys));
    }

    private function reference(TechnicalServiceMessageDispatch $dispatch): ?string
    {
        $value = collect([
            $dispatch->srv,
            $dispatch->mrn,
            $dispatch->root_mrn,
            $dispatch->request_id ?? $dispatch->technical_service_request_id,
        ])->filter(fn (mixed $value): bool => filled($value))->first();

        return filled($value) ? (string) $value : null;
    }

    /**
     * @return array<string, string|null>|null
     */
    private function datePayload(mixed $date): ?array
    {
        if ($date === null) {
            return null;
        }

        $utc = CarbonImmutable::parse($date)->timezone('UTC');
        $local = $utc->timezone(self::TIMEZONE);

        return [
            'utc' => $utc->toISOString(),
            'local' => $local->format('Y-m-d H:i:s'),
            'human' => $local->format('d.m.Y H:i'),
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function displayTime(TechnicalServiceMessageDispatch $dispatch): ?array
    {
        return $this->datePayload(
            $dispatch->sent_at
                ?? $dispatch->failed_at
                ?? $dispatch->queued_at
                ?? $dispatch->created_at
        );
    }

    private function redacted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $normalized = mb_strtolower((string) $key);
            if (str_contains($normalized, 'password')
                || str_contains($normalized, 'authoriz'.'ation')
                || str_contains($normalized, 'token')
                || str_contains($normalized, 'secret')
                || str_contains($normalized, 'basic')) {
                $value[$key] = '[redacted]';
            } elseif (is_array($item)) {
                $value[$key] = $this->redacted($item);
            }
        }

        return $value;
    }

    private function displayAttemptCount(TechnicalServiceMessageDispatch $dispatch): int
    {
        $attemptCount = (int) $dispatch->attempt_count;

        if ($attemptCount > 0) {
            return $attemptCount;
        }

        if (in_array($dispatch->status, TechnicalServiceMessageDispatch::SUCCESS_STATUSES, true)
            && ($dispatch->provider_message_id !== null || $dispatch->provider_status !== null)) {
            return 1;
        }

        return $attemptCount;
    }

    private function providerPayloadWarning(TechnicalServiceMessageDispatch $dispatch): ?string
    {
        if (! is_array($dispatch->provider_response_redacted)) {
            return null;
        }

        $matches = data_get($dispatch->provider_response_redacted, 'provider_payload_body_matches_dispatch');
        if ($matches === false) {
            return 'Provider payload uyuşmazlığı';
        }

        $dispatchHash = $dispatch->providerBodyHash();
        $payloadHash = data_get($dispatch->provider_response_redacted, 'provider_payload_body_hash');
        if ($dispatchHash !== null && $payloadHash !== null && ! hash_equals($dispatchHash, (string) $payloadHash)) {
            return 'Provider payload uyuşmazlığı';
        }

        return null;
    }

    /**
     * @return array{content: string, preview: string, source: string|null, missing_reason: string|null}
     */
    private function messageContent(TechnicalServiceMessageDispatch $dispatch): array
    {
        $providerBody = $dispatch->bodyForProvider();
        if ($providerBody !== '') {
            return $this->messageContentPayload($providerBody, 'dispatch.body_for_provider');
        }

        foreach ([
            'body',
            'rendered_body',
            'final_body_redacted',
            'rendered_body_redacted',
            'message_text',
            'content',
            'text',
            'preview_text_validated',
        ] as $path) {
            $value = data_get($dispatch->request_payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return $this->messageContentPayload($value, 'request_payload.'.$path);
            }
        }

        $preview = data_get($dispatch->request_payload, 'message_preview')
            ?? data_get($dispatch->request_payload, 'content_preview');
        if (is_string($preview) && trim($preview) !== '') {
            return $this->messageContentPayload($preview, 'request_payload.message_preview', 'Bu kayıtta yalnızca kısa mesaj önizlemesi saklanmış.');
        }

        foreach ([
            'request_payload.payload.content',
            'request_payload.payload.body',
            'provider_response_redacted.content',
            'response_payload.content',
        ] as $path) {
            $value = data_get($dispatch, $path);
            if (is_string($value) && trim($value) !== '') {
                return $this->messageContentPayload($value, $path);
            }
        }

        $reason = 'Bu kayıt eski/test kaydıdır; mesaj içeriği o dönemde saklanmamış.';

        return [
            'content' => $reason,
            'preview' => $reason,
            'source' => null,
            'missing_reason' => $reason,
        ];
    }

    /**
     * @return array{content: string, preview: string, source: string, missing_reason: string|null}
     */
    private function messageContentPayload(string $content, string $source, ?string $missingReason = null): array
    {
        $content = trim($content);

        return [
            'content' => $content,
            'preview' => Str::limit(preg_replace('/\s+/', ' ', $content) ?: $content, 120),
            'source' => $source,
            'missing_reason' => $missingReason,
        ];
    }

    private function canViewFullPhone(?User $viewer): bool
    {
        if (! $viewer instanceof User) {
            return false;
        }

        return in_array((string) $viewer->role_code, ['admin', 'localstage_admin', 'super_admin'], true);
    }
}
