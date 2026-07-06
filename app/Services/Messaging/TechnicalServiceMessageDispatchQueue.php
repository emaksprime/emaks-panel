<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TechnicalServiceMessageDispatchQueue
{
    public function __construct(
        private readonly TechnicalServiceMessageIdempotencyService $idempotency,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function enqueue(array $input, ?User $actor = null): TechnicalServiceMessageDispatch
    {
        $targetPhone = $this->idempotency->normalizePhone((string) ($input['target_phone'] ?? $input['effective_target_phone'] ?? ''));
        $recipientHash = $this->idempotency->phoneHash((string) ($input['recipient_phone'] ?? $targetPhone));
        $effectiveHash = $this->idempotency->phoneHash($targetPhone);
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [
            'body' => $input['rendered_body'] ?? null,
            'event' => $input['event'] ?? null,
        ];
        $payloadHash = (string) ($input['payload_hash'] ?? $this->idempotency->payloadHash($payload));
        $forceResend = filter_var($input['force_resend'] ?? false, FILTER_VALIDATE_BOOL);

        if ($forceResend && trim((string) ($input['force_resend_reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'force_resend_reason' => 'Manuel resend için neden zorunlu.',
            ]);
        }

        if ($forceResend && ! $actor instanceof User) {
            throw ValidationException::withMessages([
                'created_by' => 'Manuel resend için aktör zorunlu.',
            ]);
        }

        $base = [
            ...$input,
            'payload_hash' => $payloadHash,
            'recipient_phone_hash' => $recipientHash,
            'effective_target_phone_hash' => $effectiveHash,
        ];
        $idempotencyKey = (string) ($input['idempotency_key'] ?? $this->idempotency->idempotencyKey($base));

        if (! $forceResend) {
            $duplicate = $this->idempotency->blockingDuplicate($idempotencyKey);
            if ($duplicate instanceof TechnicalServiceMessageDispatch) {
                $blockedKey = hash('sha256', $idempotencyKey.'|duplicate|'.microtime(true));
                $dispatch = $this->createDispatch($input, $targetPhone, $recipientHash, $effectiveHash, $payloadHash, $idempotencyKey, $actor, [
                    'status' => TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED,
                    'idempotency_key' => $blockedKey,
                    'last_error_code' => 'duplicate_idempotency',
                    'last_error_message_redacted' => 'Aynı idempotency anahtarına sahip mesaj zaten queued/sending/sent durumda.',
                    'metadata' => [
                        ...((array) ($input['metadata'] ?? [])),
                        'duplicate_dispatch_id' => $duplicate->id,
                        'blocked_idempotency_key' => $idempotencyKey,
                    ],
                ]);
                $this->recordEvent($dispatch, 'message_duplicate_blocked', 'Mesaj duplicate guard ile engellendi.');

                return $dispatch;
            }
        }

        $dispatch = $this->createDispatch($input, $targetPhone, $recipientHash, $effectiveHash, $payloadHash, $idempotencyKey, $actor, [
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
            'queued_at' => now(),
            'next_attempt_at' => $input['next_attempt_at'] ?? now(),
        ]);
        $this->recordEvent($dispatch, 'message_queued', 'Mesaj kuyruğa alındı.');

        return $dispatch;
    }

    public function cancelQueued(TechnicalServiceMessageDispatch $dispatch, ?User $actor = null): TechnicalServiceMessageDispatch
    {
        if (! in_array($dispatch->status, [TechnicalServiceMessageDispatch::STATUS_QUEUED, TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED], true)) {
            throw ValidationException::withMessages([
                'dispatch' => 'Sadece queued/rate_limited mesajlar iptal edilebilir.',
            ]);
        }

        $dispatch->forceFill([
            'status' => TechnicalServiceMessageDispatch::STATUS_CANCELLED,
            'metadata' => [
                ...((array) $dispatch->metadata),
                'cancelled_by' => $actor?->id,
                'cancelled_at' => now()->toISOString(),
            ],
        ])->save();
        $this->recordEvent($dispatch, 'message_cancelled', 'Mesaj kuyruğu iptal edildi.');

        return $dispatch;
    }

    public function retryFailed(TechnicalServiceMessageDispatch $dispatch, ?User $actor = null): TechnicalServiceMessageDispatch
    {
        if (! in_array($dispatch->status, [TechnicalServiceMessageDispatch::STATUS_FAILED, TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR], true)) {
            throw ValidationException::withMessages([
                'dispatch' => 'Sadece failed/provider_error mesajlar retry edilebilir.',
            ]);
        }

        $dispatch->forceFill([
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
            'next_attempt_at' => now(),
            'metadata' => [
                ...((array) $dispatch->metadata),
                'retry_by' => $actor?->id,
                'retry_at' => now()->toISOString(),
            ],
        ])->save();
        $this->recordEvent($dispatch, 'message_retry_requested', 'Mesaj retry için kuyruğa alındı.');

        return $dispatch;
    }

    public function recordEvent(TechnicalServiceMessageDispatch $dispatch, string $eventType, string $title): void
    {
        $requestId = $dispatch->request_id ?? $dispatch->technical_service_request_id;

        if ($requestId === null) {
            return;
        }

        if (! TechnicalServiceRequest::query()->whereKey($requestId)->exists()) {
            return;
        }

        TechnicalServiceRequestEvent::query()->create([
            'technical_service_request_id' => $requestId,
            'event_type' => $eventType,
            'title' => $title,
            'note' => implode(' | ', array_filter([
                $dispatch->message_type,
                $dispatch->provider_key,
                $dispatch->effective_target_phone_mask,
                $dispatch->last_error_message_redacted,
            ])),
            'author_user_id' => $dispatch->created_by,
            'metadata' => [
                'dispatch_id' => $dispatch->id,
                'status' => $dispatch->status,
                'provider' => $dispatch->provider_key,
                'channel' => $dispatch->channel,
                'target_masked' => $dispatch->effective_target_phone_mask,
                'error_redacted' => $dispatch->last_error_message_redacted,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $overrides
     */
    private function createDispatch(
        array $input,
        string $targetPhone,
        ?string $recipientHash,
        ?string $effectiveHash,
        string $payloadHash,
        string $idempotencyKey,
        ?User $actor,
        array $overrides,
    ): TechnicalServiceMessageDispatch {
        return TechnicalServiceMessageDispatch::query()->create([
            'event' => $input['event'] ?? $input['message_type'] ?? 'message_dispatch',
            'technical_service_request_id' => $input['technical_service_request_id'] ?? $input['request_id'] ?? null,
            'request_id' => $input['request_id'] ?? $input['technical_service_request_id'] ?? null,
            'related_type' => $input['related_type'] ?? null,
            'related_id' => $input['related_id'] ?? null,
            'root_mrn' => $input['root_mrn'] ?? null,
            'mrn' => $input['mrn'] ?? null,
            'srv' => $input['srv'] ?? null,
            'message_type' => $input['message_type'] ?? $input['event'] ?? 'message_dispatch',
            'channel' => $input['channel'] ?? 'system',
            'provider_key' => $input['provider_key'] ?? 'null_local',
            'recipient_role' => $input['recipient_role'] ?? $input['target_type'] ?? 'internal',
            'recipient_phone_hash' => $recipientHash,
            'recipient_phone_mask' => $this->idempotency->maskPhone((string) ($input['recipient_phone'] ?? $targetPhone)),
            'effective_target_phone_hash' => $effectiveHash,
            'effective_target_phone_mask' => $this->idempotency->maskPhone($targetPhone),
            'test_redirect_applied' => (bool) ($input['test_redirect_applied'] ?? false),
            'template_key' => $input['template_key'] ?? null,
            'template_version' => $input['template_version'] ?? null,
            'rendered_body_hash' => isset($input['rendered_body'])
                ? hash('sha256', (string) $input['rendered_body'])
                : ($input['rendered_body_hash'] ?? null),
            'payload_hash' => $payloadHash,
            'idempotency_key' => $idempotencyKey,
            'channel_policy' => $input['channel_policy'] ?? null,
            'target_type' => $input['target_type'] ?? $input['recipient_role'] ?? 'internal',
            'original_phone' => null,
            'target_phone' => null,
            'test_mode' => (bool) ($input['test_mode'] ?? false),
            'attempt_count' => (int) ($input['attempt_count'] ?? 0),
            'max_attempts' => (int) ($input['max_attempts'] ?? 1),
            'parent_dispatch_id' => $input['parent_dispatch_id'] ?? null,
            'force_resend' => (bool) ($input['force_resend'] ?? false),
            'force_resend_reason' => $input['force_resend_reason'] ?? null,
            'created_by' => $actor?->id,
            'triggered_by' => $input['triggered_by'] ?? 'rel4d_queue',
            'metadata' => $input['metadata'] ?? [],
            'request_payload' => $this->redactedPayload(is_array($input['payload'] ?? null) ? $input['payload'] : []),
            'sent_by' => $actor?->id,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactedPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            $normalized = mb_strtolower((string) $key);
            if (str_contains($normalized, 'password')
                || str_contains($normalized, 'authoriz'.'ation')
                || str_contains($normalized, 'token')
                || str_contains($normalized, 'secret')) {
                $payload[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->redactedPayload($value);
            }
        }

        return $payload;
    }
}
