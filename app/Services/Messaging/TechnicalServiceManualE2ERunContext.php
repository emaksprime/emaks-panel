<?php

namespace App\Services\Messaging;

class TechnicalServiceManualE2ERunContext
{
    public const DEFAULT_RUN_ID = 'MANUAL-E2E-LIVE-TEST';

    public static function defaultRunId(): string
    {
        return self::DEFAULT_RUN_ID;
    }

    public static function normalizeRunId(mixed $runId): ?string
    {
        if (! is_scalar($runId)) {
            return null;
        }

        $runId = trim((string) $runId);

        return $runId !== '' ? $runId : null;
    }

    public static function effectiveRunId(mixed $runId, bool $manualE2eOnly = false): ?string
    {
        $normalized = self::normalizeRunId($runId);

        if ($normalized !== null) {
            return $normalized;
        }

        return $manualE2eOnly ? self::DEFAULT_RUN_ID : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function dispatchRunId(array $metadata): ?string
    {
        return self::normalizeRunId($metadata['manual_e2e_run_id'] ?? null)
            ?? self::normalizeRunId($metadata['smoke_run_id'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function dispatchMetadata(string $expectedBodyToken, string $targetPhone, string $recipientRole): array
    {
        return [
            'test_smoke' => true,
            'manual_e2e' => true,
            'allowlisted_target' => true,
            'smoke_run_id' => self::DEFAULT_RUN_ID,
            'manual_e2e_run_id' => self::DEFAULT_RUN_ID,
            'expected_body_token' => $expectedBodyToken,
            'role_target_phone' => $targetPhone,
            'recipient_role_expected' => $recipientRole,
        ];
    }
}
