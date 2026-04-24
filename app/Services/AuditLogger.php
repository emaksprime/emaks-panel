<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function log(?User $user, string $action, array $payload = [], ?Request $request = null): void
    {
        try {
            AuditLog::query()->create([
                'user_id' => $user?->id,
                'action' => $action,
                'payload' => [
                    ...$payload,
                    'ip_address' => $request?->ip(),
                    'user_agent' => $request?->userAgent(),
                ],
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Audit logging should never block panel rendering or authentication flows.
        }
    }
}
