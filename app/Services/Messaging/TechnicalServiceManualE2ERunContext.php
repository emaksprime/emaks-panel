<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class TechnicalServiceManualE2ERunContext
{
    public const DEFAULT_TTL_SECONDS = 14400;

    public const WORKER_LOCK_KEY = 'technical-service:manual-e2e-worker';

    public const WORKER_LEASE_KEY = 'technical-service:manual-e2e-worker-lease';

    public const WORKER_HEARTBEAT_STALE_AFTER_SECONDS = 180;

    public const LIFECYCLE_LOCK_KEY = 'technical-service:manual-e2e-lifecycle';

    private function __construct(
        private readonly array $settings,
        private readonly CarbonImmutable $now,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromSettings(array $settings, ?CarbonImmutable $now = null): self
    {
        return new self($settings, $now ?? CarbonImmutable::now());
    }

    public static function generateRunId(?CarbonImmutable $startedAt = null): string
    {
        $startedAt ??= CarbonImmutable::now();

        return sprintf(
            'MANUAL-E2E-FULL-%s-%s',
            $startedAt->format('Ymd-His'),
            Str::upper(Str::random(4)),
        );
    }

    public static function normalizeRunId(mixed $runId): ?string
    {
        if (! is_scalar($runId)) {
            return null;
        }

        $runId = trim((string) $runId);

        return $runId !== '' ? $runId : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function dispatchRunId(array $metadata): ?string
    {
        return self::normalizeRunId($metadata['manual_e2e_run_id'] ?? null)
            ?? self::normalizeRunId($metadata['smoke_run_id'] ?? null);
    }

    public function enabled(): bool
    {
        return (bool) ($this->settings['manual_e2e_enabled'] ?? false);
    }

    public function phase(): string
    {
        $phase = is_scalar($this->settings['manual_e2e_phase'] ?? null)
            ? trim((string) $this->settings['manual_e2e_phase'])
            : '';

        return in_array($phase, [
            TechnicalServiceMessagingSettingsService::MANUAL_E2E_PHASE_FROZEN,
            TechnicalServiceMessagingSettingsService::MANUAL_E2E_PHASE_PREPARED,
            TechnicalServiceMessagingSettingsService::MANUAL_E2E_PHASE_WINDOW_OPEN,
        ], true)
            ? $phase
            : (! $this->enabled() && $this->activeRunId() === null
                ? TechnicalServiceMessagingSettingsService::MANUAL_E2E_PHASE_FROZEN
                : 'invalid');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function openWindow(): ?array
    {
        return is_array($this->settings['manual_e2e_open_window'] ?? null)
            ? $this->settings['manual_e2e_open_window']
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeClaim(): ?array
    {
        return is_array($this->settings['manual_e2e_active_claim'] ?? null)
            ? $this->settings['manual_e2e_active_claim']
            : null;
    }

    public function activeRunId(): ?string
    {
        return self::normalizeRunId($this->settings['manual_e2e_active_run_id'] ?? null);
    }

    public function startedAt(): ?CarbonImmutable
    {
        return $this->parseDate($this->settings['manual_e2e_started_at'] ?? null);
    }

    public function createdAfter(): ?CarbonImmutable
    {
        return $this->parseDate($this->settings['manual_e2e_created_after'] ?? null);
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return $this->parseDate($this->settings['manual_e2e_expires_at'] ?? null);
    }

    public function isActive(): bool
    {
        return $this->contextBlockingReason() === null;
    }

    /**
     * @return array{code:string,message:string}|null
     */
    public function contextBlockingReason(): ?array
    {
        if (! $this->enabled() || $this->activeRunId() === null) {
            return [
                'code' => 'manual_e2e_active_run_missing',
                'message' => 'Aktif Manual E2E run bulunamadı.',
            ];
        }

        if (! in_array($this->phase(), [
            TechnicalServiceMessagingSettingsService::MANUAL_E2E_PHASE_PREPARED,
            TechnicalServiceMessagingSettingsService::MANUAL_E2E_PHASE_WINDOW_OPEN,
        ], true)) {
            return [
                'code' => 'manual_e2e_invalid_phase',
                'message' => 'Manual E2E run phase bilgisi eksik veya geçersiz.',
            ];
        }

        $startedAt = $this->startedAt();
        $createdAfter = $this->createdAfter();
        $expiresAt = $this->expiresAt();
        if ($startedAt === null || $createdAfter === null || $expiresAt === null) {
            return [
                'code' => 'manual_e2e_active_run_missing',
                'message' => 'Manual E2E run zaman bilgileri eksik veya geçersiz.',
            ];
        }

        if ($this->now->lt($startedAt) || $this->now->lt($createdAfter)) {
            return [
                'code' => 'manual_e2e_dispatch_before_run',
                'message' => 'Manual E2E run başlangıç zamanı henüz gelmedi.',
            ];
        }

        if (! $this->now->lt($expiresAt)) {
            return [
                'code' => 'manual_e2e_run_expired',
                'message' => 'Manual E2E run süresi doldu.',
            ];
        }

        return null;
    }

    /**
     * @return array{code:string,message:string}|null
     */
    public function dispatchBlockingReason(string $targetPhone): ?array
    {
        $contextBlock = $this->contextBlockingReason();
        if ($contextBlock !== null) {
            return $contextBlock;
        }

        if (! $this->targetIsAllowlisted($targetPhone)) {
            return [
                'code' => 'manual_e2e_target_not_allowlisted',
                'message' => 'Manual E2E hedef telefonu allowlist içinde değil.',
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function dispatchMetadata(string $expectedBodyToken, string $targetPhone, string $recipientRole): array
    {
        $block = $this->dispatchBlockingReason($targetPhone);
        if ($block !== null) {
            throw new LogicException($block['code'].': '.$block['message']);
        }

        $runId = (string) $this->activeRunId();
        $normalizedTarget = $this->normalizePhone($targetPhone);

        return [
            'test_smoke' => true,
            'manual_e2e' => true,
            'allowlisted_target' => true,
            'smoke_run_id' => $runId,
            'manual_e2e_run_id' => $runId,
            'manual_e2e_started_at' => $this->startedAt()?->toIso8601String(),
            'manual_e2e_created_after' => $this->createdAfter()?->toIso8601String(),
            'manual_e2e_expires_at' => $this->expiresAt()?->toIso8601String(),
            'expected_body_token' => $expectedBodyToken,
            'role_target_phone' => $normalizedTarget,
            'effective_target_phone' => $normalizedTarget,
            'recipient_role_expected' => $recipientRole,
        ];
    }

    public function matchesDispatch(TechnicalServiceMessageDispatch|array $dispatch): bool
    {
        $metadata = $dispatch instanceof TechnicalServiceMessageDispatch
            ? (array) $dispatch->metadata
            : (array) ($dispatch['metadata'] ?? $dispatch);

        return $this->isActive()
            && self::dispatchRunId($metadata) === $this->activeRunId();
    }

    /**
     * @return array{code:string,message:string}|null
     */
    public function workerBlockingReason(mixed $workerRunId, mixed $createdAfter): ?array
    {
        $contextBlock = $this->contextBlockingReason();
        if ($contextBlock !== null) {
            return $contextBlock;
        }

        if (self::normalizeRunId($workerRunId) !== $this->activeRunId()) {
            return [
                'code' => 'manual_e2e_run_id_mismatch',
                'message' => 'Worker run id aktif Manual E2E run id ile eşleşmiyor.',
            ];
        }

        if ($this->phase() !== TechnicalServiceMessagingSettingsService::MANUAL_E2E_PHASE_WINDOW_OPEN
            || $this->openWindow() === null) {
            return [
                'code' => 'manual_e2e_send_window_missing',
                'message' => 'Exact Manual E2E gönderim penceresi açık değil.',
            ];
        }

        $workerCreatedAfter = $this->parseDate($createdAfter);
        $activeCreatedAfter = $this->createdAfter();
        if ($workerCreatedAfter === null || $activeCreatedAfter === null || ! $workerCreatedAfter->equalTo($activeCreatedAfter)) {
            return [
                'code' => 'manual_e2e_created_after_mismatch',
                'message' => 'Worker created-after değeri aktif Manual E2E context ile eşleşmiyor.',
            ];
        }

        return null;
    }

    public function remainingTtlSeconds(): int
    {
        $expiresAt = $this->expiresAt();
        if ($expiresAt === null || ! $this->now->lt($expiresAt)) {
            return 0;
        }

        return max(0, (int) floor($this->now->diffInSeconds($expiresAt)));
    }

    /**
     * @return array<int, string>
     */
    public function allowlistedPhones(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $phone): string => $this->normalizePhone((string) $phone),
            (array) ($this->settings['manual_e2e_allowlisted_phones'] ?? []),
        ))));
    }

    /**
     * @param  array<int, string>  $phones
     */
    public function allowlistMatches(array $phones): bool
    {
        $expected = $this->allowlistedPhones();
        $actual = array_values(array_unique(array_filter(array_map(
            fn (string $phone): string => $this->normalizePhone($phone),
            $phones,
        ))));
        sort($expected);
        sort($actual);

        return $expected !== [] && $expected === $actual;
    }

    public function workerCommand(int $sleepSeconds = 10): ?string
    {
        $window = $this->openWindow();
        if (! $this->isActive()
            || $this->phase() !== TechnicalServiceMessagingSettingsService::MANUAL_E2E_PHASE_WINDOW_OPEN
            || $window === null
            || ! (bool) ($this->settings['real_send_enabled'] ?? false)
            || (bool) ($this->settings['queue_paused'] ?? true)
            || $this->remainingTtlSeconds() <= 0) {
            return null;
        }

        $dispatchId = (int) ($window['dispatch_id'] ?? 0);
        $provider = trim((string) ($window['provider'] ?? ''));
        $channel = trim((string) ($window['channel'] ?? ''));
        if ($dispatchId <= 0
            || ! in_array($provider, ['evo_whatsapp', 'nac_sms'], true)
            || ! in_array($channel, ['whatsapp', 'sms'], true)) {
            return null;
        }

        try {
            $windowExpiresAt = CarbonImmutable::parse((string) ($window['expires_at'] ?? ''));
        } catch (Throwable) {
            return null;
        }
        $windowRemaining = max(0, (int) floor($this->now->diffInSeconds($windowExpiresAt)));
        if ($windowRemaining <= 0 || ! $this->now->lt($windowExpiresAt)) {
            return null;
        }

        $parts = [
            'php artisan technical-service:process-message-dispatches',
            '--manual-e2e-only',
            '--dispatch-id='.$dispatchId,
            '--limit=1',
            '--created-after="'.$this->createdAfter()?->toIso8601String().'"',
            '--smoke-run-id='.$this->activeRunId(),
            '--provider='.$provider,
            '--channel='.$channel,
        ];

        return implode(' ', [
            ...$parts,
            '--require-real-send-enabled',
            '--require-queue-not-paused',
            '--max-seconds='.min(TechnicalServiceMessagingSettingsService::MANUAL_E2E_WINDOW_TTL_SECONDS, $windowRemaining),
            '--sleep-seconds=0',
            '--stop-after-idle-cycles=1',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $block = $this->contextBlockingReason();
        $status = $block === null ? 'active' : match ($block['code']) {
            'manual_e2e_run_expired' => 'expired',
            'manual_e2e_dispatch_before_run' => 'not_started',
            default => 'inactive',
        };

        return [
            'enabled' => $this->enabled(),
            'active' => $block === null,
            'phase' => $this->phase(),
            'status' => $status,
            'status_label' => match ($status) {
                'active' => 'Aktif',
                'expired' => 'Süresi doldu',
                'not_started' => 'Başlamadı',
                default => 'Aktif run yok',
            },
            'active_run_id' => $this->activeRunId(),
            'started_at' => $this->startedAt()?->toIso8601String(),
            'created_after' => $this->createdAfter()?->toIso8601String(),
            'expires_at' => $this->expiresAt()?->toIso8601String(),
            'remaining_ttl_seconds' => $this->remainingTtlSeconds(),
            'worker_command_ready' => $this->workerCommand() !== null,
            'worker_command' => $this->workerCommand(),
            'allowlisted_phone_count' => count($this->allowlistedPhones()),
            'open_window' => $this->publicWindow($this->openWindow()),
            'active_claim' => $this->publicWindow($this->activeClaim()),
            'blocker_code' => $block['code'] ?? null,
            'blocker_message' => $block['message'] ?? null,
            'last_run_id' => self::normalizeRunId($this->settings['manual_e2e_last_run_id'] ?? null),
            'last_stopped_at' => $this->parseDate($this->settings['manual_e2e_last_stopped_at'] ?? null)?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $window
     * @return array<string, mixed>|null
     */
    private function publicWindow(?array $window): ?array
    {
        if ($window === null) {
            return null;
        }

        return Arr::only($window, [
            'id',
            'status',
            'run_id',
            'dispatch_id',
            'provider',
            'channel',
            'role_target',
            'request_id',
            'offer_cycle_id',
            'opened_at',
            'expires_at',
            'claimed_at',
            'http_started_at',
            'maximum_attempts',
        ]);
    }

    private function targetIsAllowlisted(string $phone): bool
    {
        $target = $this->normalizePhone($phone);

        return $target !== '' && in_array($target, $this->allowlistedPhones(), true);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (str_starts_with($digits, '0')) {
            return '90'.substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '90'.$digits;
        }

        return $digits;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
