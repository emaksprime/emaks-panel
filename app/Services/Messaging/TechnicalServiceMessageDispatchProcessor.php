<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class TechnicalServiceMessageDispatchProcessor
{
    public function __construct(
        private readonly TechnicalServiceMessageRateLimitService $rateLimiter,
        private readonly TechnicalServiceMessageProviderRouter $router,
        private readonly TechnicalServiceMessageDispatchQueue $queue,
        private readonly TechnicalServiceMessageChannelPlanner $channelPlanner,
        private readonly TechnicalServiceMessageTemplateService $templates,
        private readonly TechnicalServiceMessagingSettingsService $settings,
    ) {}

    /**
     * @param  array{limit?:int,provider?:string|null,provider_keys?:array<int, string>,channel?:string|null,dispatch_id?:int|null,only_test?:bool,no_external?:bool,allowlisted_phones?:array<int, string>,smoke_run_id?:string|null,smoke_started_at?:string|null,expected_body_token?:string|null,manual_e2e_only?:bool,created_after?:string|null,guarded_batch?:bool}  $options
     * @return array<string, mixed>
     */
    public function dryRun(array $options = []): array
    {
        $candidates = $this->candidateQuery($options)->limit(max(1, (int) ($options['limit'] ?? 10)))->get();

        return [
            'dry_run' => true,
            'count' => $candidates->count(),
            'dispatches' => $candidates->map(fn (TechnicalServiceMessageDispatch $dispatch): array => [
                'id' => $dispatch->id,
                'status' => $dispatch->status,
                'provider_key' => $dispatch->provider_key,
                'channel' => $dispatch->channel,
                'message_type' => $dispatch->message_type,
                'target' => $dispatch->effective_target_phone_mask,
            ])->all(),
        ];
    }

    /**
     * @param  array{limit?:int,provider?:string|null,provider_keys?:array<int, string>,channel?:string|null,dispatch_id?:int|null,only_test?:bool,no_external?:bool,allowlisted_phones?:array<int, string>,smoke_run_id?:string|null,smoke_started_at?:string|null,expected_body_token?:string|null,manual_e2e_only?:bool,created_after?:string|null,guarded_batch?:bool}  $options
     * @return array<string, mixed>
     */
    public function process(array $options = []): array
    {
        $processed = [];
        $limit = max(1, (int) ($options['limit'] ?? 10));
        $allowlistedPhones = (array) ($options['allowlisted_phones'] ?? []);

        if (! (bool) ($options['no_external'] ?? false)
            && $allowlistedPhones !== []
            && empty($options['dispatch_id'])
            && ! $this->guardedManualE2eBatchAllowed($options, $allowlistedPhones)) {
            return [
                'dry_run' => false,
                'count' => 0,
                'blocked' => true,
                'reason' => 'Kontrollü gerçek smoke için tekil dispatch-id zorunlu.',
                'dispatches' => [],
            ];
        }

        $ids = $this->candidateQuery($options)->limit($limit)->pluck('id')->all();

        foreach ($ids as $id) {
            $processed[] = $this->processOne(
                (int) $id,
                (bool) ($options['no_external'] ?? false),
                $allowlistedPhones,
                $options,
            );
        }

        return [
            'dry_run' => false,
            'count' => count($processed),
            'dispatches' => $processed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $options
     */
    public function processOne(int $dispatchId, bool $noExternal = false, array $allowlistedPhones = [], array $options = []): array
    {
        return DB::transaction(function () use ($dispatchId, $noExternal, $allowlistedPhones, $options): array {
            /** @var TechnicalServiceMessageDispatch|null $dispatch */
            $dispatch = TechnicalServiceMessageDispatch::query()
                ->whereKey($dispatchId)
                ->lockForUpdate()
                ->first();

            if (! $dispatch instanceof TechnicalServiceMessageDispatch) {
                return ['id' => $dispatchId, 'status' => 'missing'];
            }

            if (! in_array($dispatch->status, [TechnicalServiceMessageDispatch::STATUS_QUEUED, TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED], true)) {
                return ['id' => $dispatch->id, 'status' => $dispatch->status, 'skipped' => true];
            }

            if (! $noExternal && $allowlistedPhones !== [] && ! $this->targetIsAllowlisted($dispatch, $allowlistedPhones)) {
                $dispatch->forceFill([
                    'status' => TechnicalServiceMessageDispatch::STATUS_BLOCKED,
                    'failed_at' => now(),
                    'last_error_code' => 'allowlist_blocked',
                    'last_error_message_redacted' => 'Kontrollü smoke allowlist dışında hedef telefon engellendi.',
                ])->save();
                $this->queue->recordEvent($dispatch, 'message_allowlist_blocked', 'Mesaj allowlist dışı hedef nedeniyle engellendi.');

                return ['id' => $dispatch->id, 'status' => $dispatch->status, 'reason' => $dispatch->last_error_message_redacted];
            }

            if (! $noExternal && $allowlistedPhones !== []) {
                $smokeValidation = $this->controlledSmokeValidationErrors($dispatch, $options);
                if ($smokeValidation !== []) {
                    $reason = implode(' ', array_column($smokeValidation, 'message'));
                    $dispatch->forceFill([
                        'status' => TechnicalServiceMessageDispatch::STATUS_BLOCKED,
                        'failed_at' => now(),
                        'last_error_code' => $smokeValidation[0]['code'],
                        'last_error_message_redacted' => $reason,
                    ])->save();
                    $this->queue->recordEvent($dispatch, 'message_smoke_blocked', 'Mesaj kontrollü smoke koşulu eksik olduğu için engellendi.');

                    return ['id' => $dispatch->id, 'status' => $dispatch->status, 'reason' => $reason];
                }
            }

            if (! $noExternal && $allowlistedPhones !== [] && ! $this->isControlledSmokeDispatch($dispatch, $options)) {
                $dispatch->forceFill([
                    'status' => TechnicalServiceMessageDispatch::STATUS_BLOCKED,
                    'failed_at' => now(),
                    'last_error_code' => 'test_smoke_required',
                    'last_error_message_redacted' => 'Kontrollü gerçek smoke için dispatch metadata.test_smoke=true olmalı.',
                ])->save();
                $this->queue->recordEvent($dispatch, 'message_smoke_blocked', 'Mesaj kontrollü smoke koşulu eksik olduğu için engellendi.');

                return ['id' => $dispatch->id, 'status' => $dispatch->status, 'reason' => $dispatch->last_error_message_redacted];
            }

            $bodyValidation = $dispatch->providerBodyValidationErrors();
            if ($bodyValidation !== []) {
                $reason = implode(' ', $bodyValidation);
                $dispatch->forceFill([
                    'status' => TechnicalServiceMessageDispatch::STATUS_BLOCKED,
                    'failed_at' => now(),
                    'last_error_code' => 'invalid_dispatch_body',
                    'last_error_message_redacted' => $reason,
                ])->save();
                $this->queue->recordEvent($dispatch, 'message_body_blocked', 'Mesaj içeriği provider gönderimi öncesi engellendi.');

                return ['id' => $dispatch->id, 'status' => $dispatch->status, 'reason' => $reason];
            }

            $roleBodyValidation = $dispatch->roleBodyValidationErrors();
            if ($roleBodyValidation !== []) {
                $reason = implode(' ', $roleBodyValidation);
                $dispatch->forceFill([
                    'status' => TechnicalServiceMessageDispatch::STATUS_BLOCKED,
                    'failed_at' => now(),
                    'last_error_code' => 'role_body_mismatch',
                    'last_error_message_redacted' => $reason,
                ])->save();
                $this->queue->recordEvent($dispatch, 'message_role_body_blocked', 'Mesaj rol/gövde uyumsuzluğu nedeniyle engellendi.');

                return ['id' => $dispatch->id, 'status' => $dispatch->status, 'reason' => $reason];
            }

            $rateLimit = $this->rateLimiter->evaluateBeforeProcessing($dispatch);
            if (! $rateLimit['allowed']) {
                $dispatch->forceFill([
                    'status' => $rateLimit['status'],
                    'next_attempt_at' => $rateLimit['next_attempt_at'],
                    'last_error_code' => $rateLimit['status'],
                    'last_error_message_redacted' => $rateLimit['reason'],
                ])->save();

                return ['id' => $dispatch->id, 'status' => $dispatch->status, 'reason' => $rateLimit['reason']];
            }

            $dispatch->forceFill([
                'status' => TechnicalServiceMessageDispatch::STATUS_SENDING,
                'sending_started_at' => now(),
                'attempt_count' => (int) $dispatch->attempt_count + 1,
            ])->save();

            $result = $this->router->dispatch(
                $dispatch,
                $noExternal,
                $allowlistedPhones,
                TechnicalServiceManualE2ERunContext::normalizeRunId($options['smoke_run_id'] ?? null),
            );
            $status = (string) $result['status'];

            $dispatch->forceFill([
                'status' => $status,
                'provider_status' => $result['provider_status'] ?? null,
                'provider_message_id' => $result['provider_message_id'] ?? null,
                'provider_response_redacted' => $result['response'] ?? null,
                'response_payload' => $result['response'] ?? null,
                'last_error_code' => $result['provider_status'] ?? null,
                'last_error_message_redacted' => $result['error'] ?? null,
                'error_message' => $result['error'] ?? null,
                'sent_at' => in_array($status, TechnicalServiceMessageDispatch::SUCCESS_STATUSES, true) ? now() : null,
                'failed_at' => in_array($status, [TechnicalServiceMessageDispatch::STATUS_FAILED, TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR], true) ? now() : null,
            ])->save();

            $this->queue->recordEvent(
                $dispatch,
                in_array($status, TechnicalServiceMessageDispatch::SUCCESS_STATUSES, true) ? 'message_sent' : 'message_failed',
                in_array($status, TechnicalServiceMessageDispatch::SUCCESS_STATUSES, true) ? 'Mesaj provider tarafından kabul edildi.' : 'Mesaj provider tarafından gönderilemedi.',
            );
            $fallback = $this->createFallbackIfNeeded($dispatch);

            return [
                'id' => $dispatch->id,
                'status' => $dispatch->status,
                'provider_status' => $dispatch->provider_status,
                'provider_message_id' => $dispatch->provider_message_id,
                'fallback_dispatch_id' => $fallback?->id,
            ];
        });
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

        $allowed = array_map(fn (string $phone): string => $this->normalizePhone($phone), $allowlistedPhones);

        return in_array($target, array_filter($allowed), true);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function isControlledSmokeDispatch(TechnicalServiceMessageDispatch $dispatch, array $options = []): bool
    {
        if (! filter_var(data_get($dispatch->metadata, 'test_smoke', false), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        if ((bool) ($options['manual_e2e_only'] ?? false)
            && ! filter_var(data_get($dispatch->metadata, 'manual_e2e', false), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $smokeRunId = TechnicalServiceManualE2ERunContext::normalizeRunId($options['smoke_run_id'] ?? null);
        $dispatchRunId = TechnicalServiceManualE2ERunContext::dispatchRunId((array) $dispatch->metadata);
        if ((bool) ($options['manual_e2e_only'] ?? false)) {
            $context = $this->settings->manualE2EContext();

            return $context->workerBlockingReason($smokeRunId, $options['created_after'] ?? null) === null
                && $context->matchesDispatch($dispatch);
        }

        if ($smokeRunId !== null) {
            return $dispatchRunId === $smokeRunId;
        }

        return $dispatchRunId !== null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, array{code:string,message:string}>
     */
    private function controlledSmokeValidationErrors(TechnicalServiceMessageDispatch $dispatch, array $options): array
    {
        $errors = [];
        $body = $dispatch->bodyForProvider();
        $metadata = (array) $dispatch->metadata;
        $manualOnly = (bool) ($options['manual_e2e_only'] ?? false);
        $smokeRunId = TechnicalServiceManualE2ERunContext::normalizeRunId($options['smoke_run_id'] ?? null);
        $dispatchRunId = TechnicalServiceManualE2ERunContext::dispatchRunId($metadata);

        if (! filter_var($metadata['test_smoke'] ?? false, FILTER_VALIDATE_BOOL)) {
            $errors[] = ['code' => 'test_smoke_required', 'message' => 'Kontrollü gerçek smoke için dispatch metadata.test_smoke=true olmalı.'];
        }

        if ($manualOnly && ! filter_var($metadata['manual_e2e'] ?? false, FILTER_VALIDATE_BOOL)) {
            $errors[] = ['code' => 'manual_e2e_active_run_missing', 'message' => 'Manual E2E worker sadece dispatch metadata.manual_e2e=true kayıtlarını işler.'];
        }

        $context = $manualOnly ? $this->settings->manualE2EContext() : null;
        if ($manualOnly && $context !== null) {
            $contextBlock = $context->contextBlockingReason();
            if ($contextBlock !== null) {
                $errors[] = $contextBlock;
            } else {
                $workerBlock = $context->workerBlockingReason($smokeRunId, $options['created_after'] ?? null);
                if ($workerBlock !== null) {
                    $errors[] = $workerBlock;
                }

                $dispatchBlock = $context->dispatchBlockingReason((string) $dispatch->target_phone);
                if ($dispatchBlock !== null) {
                    $errors[] = $dispatchBlock;
                }

                if ($dispatchRunId !== $context->activeRunId()) {
                    $errors[] = ['code' => 'manual_e2e_run_id_mismatch', 'message' => 'Dispatch run id aktif Manual E2E run id ile eşleşmiyor.'];
                }

                if ($dispatch->created_at !== null && $context->createdAfter() !== null && $dispatch->created_at->lt($context->createdAfter()->subSecond())) {
                    $errors[] = ['code' => 'manual_e2e_dispatch_before_run', 'message' => 'Dispatch aktif Manual E2E başlangıcından önce oluşturulmuş.'];
                }

                if ($dispatch->created_at !== null && $context->expiresAt() !== null && ! $dispatch->created_at->lt($context->expiresAt())) {
                    $errors[] = ['code' => 'manual_e2e_run_expired', 'message' => 'Dispatch aktif Manual E2E run süresi dışında oluşturulmuş.'];
                }
            }
        } elseif ($smokeRunId === null) {
            $errors[] = ['code' => 'manual_e2e_active_run_missing', 'message' => 'Kontrollü gerçek smoke için --smoke-run-id zorunlu.'];
        } elseif ($dispatchRunId === null || $dispatchRunId !== $smokeRunId) {
            $errors[] = ['code' => 'manual_e2e_run_id_mismatch', 'message' => 'Dispatch smoke run id worker run id ile eşleşmiyor.'];
        }

        $startedAt = trim((string) ($options['smoke_started_at'] ?? ''));
        $createdAfter = trim((string) ($options['created_after'] ?? ''));
        if ($startedAt === '' && $createdAfter !== '') {
            $startedAt = $createdAfter;
        }
        if ($startedAt !== '') {
            try {
                $boundary = CarbonImmutable::parse($startedAt);
                if ($dispatch->created_at !== null && $dispatch->created_at->lt($boundary->subSecond())) {
                    $errors[] = ['code' => 'manual_e2e_dispatch_before_run', 'message' => 'Dispatch smoke başlangıcından önce oluşturulmuş; stale dispatch işlenmedi.'];
                }
            } catch (Throwable) {
                $errors[] = ['code' => 'manual_e2e_created_after_mismatch', 'message' => '--smoke-started-at geçerli ISO tarih olmalı.'];
            }
        }

        $expectedBodyToken = trim((string) ($options['expected_body_token'] ?? ($metadata['expected_body_token'] ?? '')));
        if ($expectedBodyToken !== '' && ! str_contains($body, $expectedBodyToken)) {
            $errors[] = ['code' => 'invalid_dispatch_body', 'message' => 'Dispatch body beklenen smoke referansını içermiyor; stale/yanlış içerik engellendi.'];
        }

        if (str_contains($body, 'MRN-REL4C') || str_contains($body, 'SRV-REL4C')) {
            $errors[] = ['code' => 'invalid_dispatch_body', 'message' => 'REL-4E smoke içinde eski REL-4C referanslı body engellendi.'];
        }

        $roleTargetPhone = $this->roleTargetPhone($metadata, $options, (string) $dispatch->recipient_role);
        if ($roleTargetPhone !== null && $this->normalizePhone($dispatch->target_phone) !== $roleTargetPhone) {
            $errors[] = ['code' => 'manual_e2e_target_not_allowlisted', 'message' => 'Dispatch hedef telefonu rol için beklenen allowlist hedefiyle eşleşmiyor.'];
        }

        return collect($errors)
            ->unique(fn (array $error): string => $error['code'].'|'.$error['message'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $options
     */
    private function roleTargetPhone(array $metadata, array $options, string $recipientRole): ?string
    {
        $metadataTarget = $metadata['role_target_phone'] ?? null;
        if (is_scalar($metadataTarget) && trim((string) $metadataTarget) !== '') {
            return $this->normalizePhone((string) $metadataTarget);
        }

        $targets = (array) ($options['role_target_phones'] ?? []);
        $target = $targets[$recipientRole] ?? null;
        if (! is_scalar($target) || trim((string) $target) === '') {
            return null;
        }

        return $this->normalizePhone((string) $target);
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

    private function createFallbackIfNeeded(TechnicalServiceMessageDispatch $dispatch): ?TechnicalServiceMessageDispatch
    {
        $plan = $this->channelPlanner->fallbackAfter($dispatch);
        if ($plan === null || $dispatch->request_id === null) {
            return null;
        }

        $context = data_get($dispatch->request_payload, 'context');
        $preview = $this->templates->preview([
            'message_type' => $dispatch->message_type,
            'channel' => 'sms',
            'provider_key' => 'nac_sms',
            'request_id' => $dispatch->request_id,
            'sample_context' => false,
            'context' => is_array($context) ? $context : [],
        ]);

        $blockers = array_values((array) ($preview['blockers'] ?? []));
        if ($blockers !== [] || ! (bool) ($preview['preview_ready'] ?? false)) {
            $dispatch->forceFill([
                'metadata' => [
                    ...((array) $dispatch->metadata),
                    'fallback_blocked_reason' => implode(' ', $blockers) ?: 'SMS fallback şablonu bloklu.',
                ],
            ])->save();

            return null;
        }

        $template = (array) ($preview['template'] ?? []);

        return $this->queue->enqueue([
            'event' => (string) ($plan['event'] ?? $dispatch->event.'_sms_fallback'),
            'technical_service_request_id' => $dispatch->technical_service_request_id ?? $dispatch->request_id,
            'request_id' => $dispatch->request_id,
            'related_type' => $dispatch->related_type,
            'related_id' => $dispatch->related_id,
            'root_mrn' => $dispatch->root_mrn,
            'mrn' => $dispatch->mrn,
            'srv' => $dispatch->srv,
            'message_type' => $dispatch->message_type,
            'channel' => 'sms',
            'provider_key' => 'nac_sms',
            'recipient_role' => $dispatch->recipient_role,
            'target_type' => $dispatch->target_type,
            'recipient_phone' => $dispatch->original_phone ?: $dispatch->target_phone,
            'target_phone' => $dispatch->target_phone,
            'test_redirect_applied' => (bool) $dispatch->test_redirect_applied,
            'test_mode' => (bool) $dispatch->test_mode,
            'template_key' => $template['template_key'] ?? null,
            'template_version' => $template['version'] ?? null,
            'rendered_body' => (string) ($preview['rendered_body'] ?? ''),
            'payload' => [
                'body' => $preview['rendered_body'] ?? '',
                'rendered_body' => $preview['rendered_body'] ?? '',
                'message_type' => $dispatch->message_type,
                'channel' => 'sms',
                'provider_key' => 'nac_sms',
                'fallback_from_dispatch_id' => $dispatch->id,
                'context' => is_array($context) ? $context : [],
                'sms' => $preview['sms'] ?? null,
            ],
            'business_event_id' => 'fallback-'.$dispatch->id,
            'channel_policy' => $dispatch->channel_policy,
            'parent_dispatch_id' => $dispatch->id,
            'triggered_by' => 'whatsapp_failure_fallback',
            'metadata' => [
                ...array_intersect_key((array) $dispatch->metadata, array_flip([
                    'test_smoke',
                    'manual_e2e',
                    'allowlisted_target',
                    'pr88_rel',
                    'smoke_run_id',
                    'manual_e2e_run_id',
                    'manual_e2e_started_at',
                    'manual_e2e_created_after',
                    'manual_e2e_expires_at',
                    'effective_target_phone',
                    'role_target_phone',
                    'recipient_role_expected',
                    'created_by_smoke_at',
                    'expected_body_token',
                    'prefix',
                    'created_via',
                ])),
                ...((array) ($plan['metadata'] ?? [])),
                'fallback_from_dispatch_id' => $dispatch->id,
                'fallback_reason' => $dispatch->last_error_message_redacted,
            ],
        ], $dispatch->creator);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function candidateQuery(array $options)
    {
        $providerKeys = array_values(array_filter(array_map('strval', (array) ($options['provider_keys'] ?? []))));
        if ($providerKeys === [] && filled($options['provider'] ?? null)) {
            $providerKeys = [(string) $options['provider']];
        }

        $createdAfter = null;
        if (filled($options['created_after'] ?? null)) {
            try {
                $createdAfter = CarbonImmutable::parse((string) $options['created_after']);
            } catch (Throwable) {
                $createdAfter = CarbonImmutable::now()->addCentury();
            }
        }

        $allowlistedTargets = array_values(array_filter(array_map(
            fn (string $phone): string => $this->normalizePhone($phone),
            (array) ($options['allowlisted_phones'] ?? []),
        )));
        $manualRunId = (bool) ($options['manual_e2e_only'] ?? false)
            ? TechnicalServiceManualE2ERunContext::normalizeRunId($options['smoke_run_id'] ?? null)
            : null;

        return TechnicalServiceMessageDispatch::query()
            ->whereIn('status', [TechnicalServiceMessageDispatch::STATUS_QUEUED, TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED])
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->when($options['dispatch_id'] ?? null, fn ($query, $id) => $query->whereKey((int) $id))
            ->when($providerKeys !== [], fn ($query) => $query->whereIn('provider_key', $providerKeys))
            ->when($options['channel'] ?? null, fn ($query, $channel) => $query->where('channel', (string) $channel))
            ->when((bool) ($options['only_test'] ?? false), fn ($query) => $query->where('recipient_role', 'test'))
            ->when($createdAfter !== null, fn ($query) => $query->where('created_at', '>=', $createdAfter))
            ->when((bool) ($options['manual_e2e_only'] ?? false), function ($query): void {
                $query->where(function ($nested): void {
                    $nested
                        ->where('metadata->manual_e2e', true)
                        ->orWhere('metadata->manual_e2e', 1)
                        ->orWhere('metadata->manual_e2e', 'true');
                });
            })
            ->when($manualRunId !== null, function ($query) use ($manualRunId): void {
                $query->where(function ($nested) use ($manualRunId): void {
                    $nested
                        ->where('metadata->manual_e2e_run_id', $manualRunId)
                        ->orWhere(function ($legacy) use ($manualRunId): void {
                            $legacy
                                ->whereNull('metadata->manual_e2e_run_id')
                                ->where('metadata->smoke_run_id', $manualRunId);
                        });
                });
            })
            ->when($allowlistedTargets !== [], fn ($query) => $query->whereIn('target_phone', $allowlistedTargets))
            ->orderBy('next_attempt_at')
            ->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<int, string>  $allowlistedPhones
     */
    private function guardedManualE2eBatchAllowed(array $options, array $allowlistedPhones): bool
    {
        if (! (bool) ($options['guarded_batch'] ?? false)) {
            return false;
        }

        if (! (bool) ($options['manual_e2e_only'] ?? false)) {
            return false;
        }

        if (trim((string) ($options['created_after'] ?? '')) === '') {
            return false;
        }

        if ($allowlistedPhones === []) {
            return false;
        }

        $context = $this->settings->manualE2EContext();
        if ($context->workerBlockingReason(
            $options['smoke_run_id'] ?? null,
            $options['created_after'] ?? null,
        ) !== null || ! $context->allowlistMatches($allowlistedPhones)) {
            return false;
        }

        $providers = array_values(array_filter(array_map('strval', (array) ($options['provider_keys'] ?? []))));
        if ($providers === [] && filled($options['provider'] ?? null)) {
            $providers = [(string) $options['provider']];
        }

        return $providers !== [] && array_diff($providers, ['evo_whatsapp', 'nac_sms']) === [];
    }
}
