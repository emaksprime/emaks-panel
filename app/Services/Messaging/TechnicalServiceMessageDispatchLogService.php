<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;
use Illuminate\Support\Str;

class TechnicalServiceMessageDispatchLogService
{
    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'summary' => $this->summary(),
            'recent' => $this->recent(),
            'filters' => [
                'providers' => ['null_local', 'evo_whatsapp', 'nac_sms', 'voibot_voice'],
                'channels' => ['whatsapp', 'sms', 'voice', 'system'],
                'recipient_roles' => ['customer', 'technician', 'ops', 'test', 'internal'],
                'statuses' => app(TechnicalServiceMessageDispatchStatusRegistry::class)->statuses(),
            ],
            'warnings' => [
                'REL-4D provider kuyruğu business trigger bağlamaz; gerçek müşteri/usta gönderimi REL-4E/REL-4F bekler.',
                'Telefonlar maskeli gösterilir; Basic Auth, token ve provider credential bu loglarda yer almaz.',
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

        return [
            'queued' => $counts[TechnicalServiceMessageDispatch::STATUS_QUEUED] ?? 0,
            'sending' => $counts[TechnicalServiceMessageDispatch::STATUS_SENDING] ?? 0,
            'sent' => ($counts[TechnicalServiceMessageDispatch::STATUS_SENT] ?? 0) + ($counts[TechnicalServiceMessageDispatch::STATUS_TEST_SENT] ?? 0),
            'failed' => ($counts[TechnicalServiceMessageDispatch::STATUS_FAILED] ?? 0) + ($counts[TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR] ?? 0) + ($counts[TechnicalServiceMessageDispatch::STATUS_TEST_FAILED] ?? 0),
            'duplicate_blocked' => $counts[TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED] ?? 0,
            'rate_limited' => ($counts[TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED] ?? 0) + ($counts[TechnicalServiceMessageDispatch::STATUS_COOLDOWN_BLOCKED] ?? 0),
            'cancelled' => $counts[TechnicalServiceMessageDispatch::STATUS_CANCELLED] ?? 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recent(): array
    {
        return TechnicalServiceMessageDispatch::query()
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (TechnicalServiceMessageDispatch $dispatch): array => [
                'id' => $dispatch->id,
                'status' => $dispatch->status,
                'provider_key' => $dispatch->provider_key ?? data_get($dispatch->request_payload, 'provider'),
                'channel' => $dispatch->channel ?? data_get($dispatch->request_payload, 'channel'),
                'message_type' => $dispatch->message_type ?? data_get($dispatch->request_payload, 'message_type'),
                'recipient_role' => $dispatch->recipient_role ?? $dispatch->target_type,
                'target_masked' => $dispatch->effective_target_phone_mask ?? $this->maskPhone($dispatch->target_phone),
                'idempotency_key_short' => $dispatch->idempotency_key ? Str::substr($dispatch->idempotency_key, 0, 12) : null,
                'attempt_count' => (int) $dispatch->attempt_count,
                'provider_message_id' => $dispatch->provider_message_id ?? data_get($dispatch->response_payload, 'pkgID'),
                'last_error_redacted' => $dispatch->last_error_message_redacted ?? $dispatch->error_message,
                'force_resend_reason' => $dispatch->force_resend_reason,
                'created_by' => $dispatch->created_by,
                'created_at' => $dispatch->created_at?->toISOString(),
                'sent_at' => $dispatch->sent_at?->toISOString(),
            ])
            ->all();
    }

    private function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        if ($digits === '') {
            return null;
        }

        return substr($digits, 0, 4).'***'.substr($digits, -3);
    }
}
