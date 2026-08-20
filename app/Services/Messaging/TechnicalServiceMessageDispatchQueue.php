<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TechnicalServiceMessageDispatchQueue
{
    public function __construct(
        private readonly TechnicalServiceMessageIdempotencyService $idempotency,
        private readonly TechnicalServiceMessagingSettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function enqueue(array $input, ?User $actor = null): TechnicalServiceMessageDispatch
    {
        $input = $this->settings->withNormalDispatchRecipientAuthority($input);
        $input = $this->withServerExecutionModeSnapshot($input);
        $targetPhone = $this->idempotency->normalizePhone((string) ($input['target_phone'] ?? $input['effective_target_phone'] ?? ''));
        $recipientHash = $this->idempotency->phoneHash((string) ($input['recipient_phone'] ?? $targetPhone));
        $effectiveHash = $this->idempotency->phoneHash($targetPhone);
        $payload = $this->messagePayload($input);
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

            if ($this->idempotencyKeyExists($idempotencyKey)) {
                $input['metadata'] = [
                    ...((array) ($input['metadata'] ?? [])),
                    'terminal_idempotency_key' => $idempotencyKey,
                    'terminal_idempotency_requeued' => true,
                ];
                $idempotencyKey = hash('sha256', $idempotencyKey.'|terminal-requeue|'.microtime(true));
            }
        }

        if ($this->isSystemOnlyRecord($input)) {
            $dispatch = $this->createDispatch($input, $targetPhone, $recipientHash, $effectiveHash, $payloadHash, $idempotencyKey, $actor, [
                'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED,
                'queued_at' => null,
                'next_attempt_at' => null,
                'provider_status' => 'no_external_provider',
                'last_error_code' => 'null_local_system_no_external_provider',
                'last_error_message_redacted' => 'Dış sağlayıcı yok; sistem kaydı olarak tutuldu.',
                'metadata' => [
                    ...((array) ($input['metadata'] ?? [])),
                    'null_local_system_recorded' => true,
                    'provider_send_attempted' => false,
                    'external_provider_call' => false,
                ],
            ]);
            $this->recordEvent($dispatch, 'message_system_recorded', 'Mesaj sistem kaydı olarak tutuldu.');

            return $dispatch;
        }

        if ($this->usesExternalProvider($input)) {
            $authorization = $this->settings->outboundQueueAuthorization(
                (string) ($input['provider_key'] ?? ''),
                (string) ($input['channel'] ?? ''),
                (string) ($input['message_type'] ?? ''),
                $targetPhone,
                (array) ($input['metadata'] ?? []),
                (string) ($input['rendered_body'] ?? data_get($payload, 'body', '')),
                (string) ($input['recipient_role'] ?? $input['target_type'] ?? ''),
            );
            if (! $authorization['allowed']) {
                $code = (string) ($authorization['code'] ?? 'external_execution_control_blocked');
                $message = (string) ($authorization['message'] ?? 'Global execution snapshot current state ile eşleşmiyor.');
                if ($code === 'outbound_execution_mode_local') {
                    $dispatch = $this->createDispatch($input, $targetPhone, $recipientHash, $effectiveHash, $payloadHash, $idempotencyKey, $actor, [
                        'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED,
                        'queued_at' => null,
                        'next_attempt_at' => null,
                        'provider_status' => 'local_no_send',
                        'last_error_code' => $code,
                        'last_error_message_redacted' => 'Mesaj Lokal çalışma modunda dış sağlayıcıya gönderilmeden kaydedildi.',
                        'metadata' => [
                            ...((array) ($input['metadata'] ?? [])),
                            'local_no_send_recorded' => true,
                            'provider_send_attempted' => false,
                            'external_provider_call' => false,
                        ],
                    ]);
                    $this->recordEvent($dispatch, 'message_local_recorded', 'Mesaj Lokal çalışma modunda dış sağlayıcıya gönderilmeden kaydedildi.');

                    return $dispatch;
                }

                $dispatch = $this->createDispatch($input, $targetPhone, $recipientHash, $effectiveHash, $payloadHash, $idempotencyKey, $actor, [
                    'status' => TechnicalServiceMessageDispatch::STATUS_BLOCKED,
                    'queued_at' => null,
                    'next_attempt_at' => null,
                    'provider_status' => 'execution_control_blocked',
                    'last_error_code' => $code,
                    'last_error_message_redacted' => $message,
                    'metadata' => [
                        ...((array) ($input['metadata'] ?? [])),
                        'execution_control_blocked' => true,
                        'provider_send_attempted' => false,
                        'external_provider_call' => false,
                    ],
                ]);
                $this->recordEvent($dispatch, 'message_execution_control_blocked', 'Mesaj global execution fence ile engellendi.');

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

    public function reconcileBlockedUnsent(
        TechnicalServiceMessageDispatch $dispatch,
        string $reason,
        ?User $actor = null,
    ): TechnicalServiceMessageDispatch {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Blocked dispatch reconciliation nedeni zorunlu.',
            ]);
        }

        return DB::transaction(function () use ($dispatch, $reason, $actor): TechnicalServiceMessageDispatch {
            $locked = TechnicalServiceMessageDispatch::query()->lockForUpdate()->findOrFail($dispatch->id);
            $providerSendAttempted = filter_var(
                data_get($locked->metadata, 'provider_send_attempted', false),
                FILTER_VALIDATE_BOOL,
            );

            if ($locked->status !== TechnicalServiceMessageDispatch::STATUS_BLOCKED
                || (int) $locked->attempt_count !== 0
                || $locked->sent_at !== null
                || filled($locked->provider_message_id)
                || $providerSendAttempted
            ) {
                throw ValidationException::withMessages([
                    'dispatch' => 'Yalnız provider denemesi yapılmamış blocked dispatch audit olarak reconcile edilebilir.',
                ]);
            }

            $locked->forceFill([
                'status' => TechnicalServiceMessageDispatch::STATUS_CANCELLED,
                'queued_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => 'manual_e2e_public_url_readiness_reconciled',
                'last_error_message_redacted' => 'Public URL readiness öncesi blocked dispatch, gönderilmeden audit olarak kapatıldı.',
                'metadata' => [
                    ...((array) $locked->metadata),
                    'reconciliation_reason' => $reason,
                    'provider_send_attempted' => false,
                    'retained_for_audit' => true,
                    'reconciled_by' => $actor?->id,
                    'reconciled_at' => now()->toIso8601String(),
                ],
            ])->save();

            $this->recordEvent(
                $locked,
                'message_blocked_reconciled',
                'Bloklu mesaj gönderilmeden audit olarak kapatıldı.',
                $actor,
                [
                    'reconciliation_reason' => $reason,
                    'provider_send_attempted' => false,
                    'retained_for_audit' => true,
                ],
            );

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function blocked(array $input, ?User $actor, string $code, string $message): TechnicalServiceMessageDispatch
    {
        $input = $this->withServerExecutionModeSnapshot($input);
        $targetPhone = $this->idempotency->normalizePhone((string) ($input['target_phone'] ?? $input['effective_target_phone'] ?? ''));
        $recipientHash = $this->idempotency->phoneHash((string) ($input['recipient_phone'] ?? $targetPhone));
        $effectiveHash = $this->idempotency->phoneHash($targetPhone);
        $payload = $this->messagePayload($input);
        $payloadHash = (string) ($input['payload_hash'] ?? $this->idempotency->payloadHash($payload));
        $idempotencyKey = (string) ($input['idempotency_key'] ?? hash('sha256', implode('|', [
            $input['request_id'] ?? $input['technical_service_request_id'] ?? 'no-request',
            $input['message_type'] ?? $input['event'] ?? 'message_dispatch',
            $input['channel'] ?? 'system',
            $input['provider_key'] ?? 'null_local',
            $targetPhone,
            $code,
            microtime(true),
        ])));

        $dispatch = $this->createDispatch($input, $targetPhone, $recipientHash, $effectiveHash, $payloadHash, $idempotencyKey, $actor, [
            'status' => TechnicalServiceMessageDispatch::STATUS_BLOCKED,
            'last_error_code' => $code,
            'last_error_message_redacted' => $message,
            'metadata' => [
                ...((array) ($input['metadata'] ?? [])),
                'blocked_code' => $code,
                'blocked_reason' => $message,
            ],
        ]);
        $this->recordEvent($dispatch, 'message_blocked', 'Mesaj oluşturulamadı: eksik bilgi');

        return $dispatch;
    }

    public function retryFailed(TechnicalServiceMessageDispatch $dispatch, ?User $actor = null): TechnicalServiceMessageDispatch
    {
        if (! in_array($dispatch->status, [TechnicalServiceMessageDispatch::STATUS_FAILED, TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR], true)) {
            throw ValidationException::withMessages([
                'dispatch' => 'Sadece failed/provider_error mesajlar retry edilebilir.',
            ]);
        }

        $usesExternalProvider = $this->usesExternalProvider(['provider_key' => $dispatch->provider_key]);
        if ($usesExternalProvider) {
            $currentSnapshot = $this->settings->executionModeSnapshot((string) $dispatch->provider_key);
            if (($currentSnapshot['outbound_execution_mode'] ?? null) === TechnicalServiceMessagingSettingsService::OUTBOUND_EXECUTION_MODE_LOCAL) {
                throw ValidationException::withMessages([
                    'dispatch' => 'Lokal çalışma modunda dış provider dispatch retry edilemez.',
                ]);
            }

            $authorization = $this->settings->outboundSnapshotAuthorization(
                (string) $dispatch->provider_key,
                (array) $dispatch->metadata,
            );
            if (! $authorization['allowed']) {
                throw ValidationException::withMessages([
                    'dispatch' => (string) ($authorization['message'] ?? 'Dispatch global execution snapshotı current state ile eşleşmiyor.'),
                ]);
            }
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

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordEvent(
        TechnicalServiceMessageDispatch $dispatch,
        string $eventType,
        string $title,
        ?User $actor = null,
        array $metadata = [],
    ): void {
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
            'author_user_id' => $actor?->id ?? $dispatch->created_by,
            'metadata' => [
                'dispatch_id' => $dispatch->id,
                'status' => $dispatch->status,
                'provider' => $dispatch->provider_key,
                'channel' => $dispatch->channel,
                'target_masked' => $dispatch->effective_target_phone_mask,
                'error_redacted' => $dispatch->last_error_message_redacted,
                ...$metadata,
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
            'original_phone' => $input['recipient_phone'] ?? null,
            'target_phone' => $targetPhone !== '' ? $targetPhone : null,
            'test_mode' => (bool) ($input['test_mode'] ?? false),
            'attempt_count' => (int) ($input['attempt_count'] ?? 0),
            'max_attempts' => (int) ($input['max_attempts'] ?? 1),
            'parent_dispatch_id' => $input['parent_dispatch_id'] ?? null,
            'force_resend' => (bool) ($input['force_resend'] ?? false),
            'force_resend_reason' => $input['force_resend_reason'] ?? null,
            'created_by' => $actor?->id,
            'triggered_by' => $input['triggered_by'] ?? 'rel4d_queue',
            'metadata' => $input['metadata'] ?? [],
            'request_payload' => $this->redactedPayload($this->messagePayload($input)),
            'sent_by' => $actor?->id,
            ...$overrides,
        ]);
    }

    private function idempotencyKeyExists(string $idempotencyKey): bool
    {
        if ($idempotencyKey === '') {
            return false;
        }

        return TechnicalServiceMessageDispatch::query()
            ->where('idempotency_key', $idempotencyKey)
            ->exists();
    }

    /**
     * Client metadata cannot select or refresh an outbound execution revision.
     * Parent/force-resend/fallback rows inherit their source snapshot so a mode
     * switch never turns historical work into a newly authorized dispatch.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function withServerExecutionModeSnapshot(array $input): array
    {
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $currentSnapshot = $this->settings->executionModeSnapshot(
            is_scalar($input['provider_key'] ?? null) ? (string) $input['provider_key'] : null,
        );
        $parentId = is_numeric($input['parent_dispatch_id'] ?? null)
            ? (int) $input['parent_dispatch_id']
            : 0;
        $snapshot = null;
        if ($parentId > 0) {
            $parent = TechnicalServiceMessageDispatch::query()->find($parentId);
            if ($parent instanceof TechnicalServiceMessageDispatch) {
                $parentMetadata = (array) $parent->metadata;
                $capabilityCode = is_scalar($currentSnapshot['external_capability_code'] ?? null)
                    ? (string) $currentSnapshot['external_capability_code']
                    : null;
                $capabilitySnapshots = is_array($parentMetadata['external_capability_snapshots'] ?? null)
                    ? $parentMetadata['external_capability_snapshots']
                    : [];
                $capabilitySnapshot = $capabilityCode !== null && is_array($capabilitySnapshots[$capabilityCode] ?? null)
                    ? $capabilitySnapshots[$capabilityCode]
                    : [];
                $snapshot = ($currentSnapshot['outbound_execution_mode'] ?? null) === TechnicalServiceMessagingSettingsService::OUTBOUND_EXECUTION_MODE_LOCAL
                    ? [
                        ...$currentSnapshot,
                        'outbound_snapshot_source_dispatch_id' => $parent->id,
                        'parent_outbound_execution_mode' => $parentMetadata['outbound_execution_mode'] ?? 'local',
                        'parent_outbound_mode_revision' => (int) ($parentMetadata['outbound_mode_revision'] ?? 0),
                    ]
                    : [
                        'global_execution_mode' => $parentMetadata['global_execution_mode'] ?? 'local',
                        'global_execution_state' => $parentMetadata['global_execution_state'] ?? 'local',
                        'global_execution_epoch' => (int) ($parentMetadata['global_execution_epoch'] ?? 0),
                        'global_execution_revision' => (int) ($parentMetadata['global_execution_revision'] ?? 0),
                        'global_runtime_environment' => $parentMetadata['global_runtime_environment'] ?? 'unknown',
                        'global_profile_fingerprint' => $parentMetadata['global_profile_fingerprint'] ?? '',
                        'global_public_origin_profile_fingerprint' => $parentMetadata['global_public_origin_profile_fingerprint'] ?? '',
                        'global_execution_snapshot_at' => $parentMetadata['global_execution_snapshot_at'] ?? $parent->created_at?->toIso8601String(),
                        'external_capability_code' => $capabilityCode,
                        'external_capability_revision' => (int) ($capabilitySnapshot['revision'] ?? 0),
                        'external_capability_profile_fingerprint' => $capabilitySnapshot['profile_fingerprint'] ?? '',
                        'external_capability_snapshots' => $capabilitySnapshots,
                        'outbound_execution_mode' => $parentMetadata['outbound_execution_mode'] ?? 'local',
                        'outbound_mode_revision' => (int) ($parentMetadata['outbound_mode_revision'] ?? 0),
                        'runtime_environment' => $parentMetadata['runtime_environment'] ?? 'unknown',
                        'outbound_mode_snapshot_at' => $parentMetadata['outbound_mode_snapshot_at'] ?? $parent->created_at?->toIso8601String(),
                        'messaging_enabled' => filter_var($parentMetadata['messaging_enabled'] ?? false, FILTER_VALIDATE_BOOL),
                        'outbound_snapshot_source_dispatch_id' => $parent->id,
                    ];
            }
        }

        $snapshot ??= $currentSnapshot;
        $input['metadata'] = [
            ...$metadata,
            ...$snapshot,
        ];

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function isSystemOnlyRecord(array $input): bool
    {
        $providerKey = (string) ($input['provider_key'] ?? 'null_local');
        $channel = (string) ($input['channel'] ?? 'system');
        $metadata = (array) ($input['metadata'] ?? []);
        $payload = $this->messagePayload($input);
        $externalProviderCall = $metadata['external_provider_call'] ?? $payload['external_provider_call'] ?? null;

        if ($providerKey === 'system') {
            return true;
        }

        if ($providerKey === 'null_local' && $channel === 'system') {
            return true;
        }

        return $providerKey === 'null_local' && $externalProviderCall === false;
    }

    /** @param  array<string, mixed>  $input */
    private function usesExternalProvider(array $input): bool
    {
        return ! in_array((string) ($input['provider_key'] ?? 'null_local'), ['null_local', 'system'], true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function messagePayload(array $input): array
    {
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        $renderedBody = $input['rendered_body'] ?? $payload['body'] ?? $payload['message_text'] ?? null;

        if (is_string($renderedBody) && trim($renderedBody) !== '') {
            $payload['body'] ??= $renderedBody;
            $payload['rendered_body'] ??= $renderedBody;
            $payload['message_preview'] ??= mb_substr($renderedBody, 0, 240);
        }

        $payload['event'] ??= $input['event'] ?? null;
        $payload['message_type'] ??= $input['message_type'] ?? null;
        $payload['channel'] ??= $input['channel'] ?? null;
        $payload['provider_key'] ??= $input['provider_key'] ?? null;

        return $payload;
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
