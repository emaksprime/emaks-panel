<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountSession;
use Throwable;

class MountSessionEnrichmentService
{
    /**
     * @param array<string, mixed> $context
     */
    public function applyContext(TechnicalServiceMountSession $session, array $context): TechnicalServiceMountSession
    {
        $session->forceFill([
            'sale_mount_status' => $context['sale_mount_status'] ?? TechnicalServiceMountSession::SALE_UNKNOWN,
            'context_payload' => array_replace($session->context_payload ?? [], $context),
            'decision_status' => TechnicalServiceMountSession::DECISION_READY,
            'check_attempt_count' => $session->check_attempt_count + 1,
            'last_checked_at' => now(),
            'check_error' => null,
        ])->save();

        return $session->fresh();
    }

    public function markCheckTimeout(TechnicalServiceMountSession $session, string|Throwable $error): TechnicalServiceMountSession
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        $session->forceFill([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_CHECK_FAILED,
            'decision_status' => TechnicalServiceMountSession::DECISION_CHECK_TIMEOUT,
            'check_attempt_count' => $session->check_attempt_count + 1,
            'last_checked_at' => now(),
            'check_error' => $message,
        ])->save();

        return $session->fresh();
    }
}
