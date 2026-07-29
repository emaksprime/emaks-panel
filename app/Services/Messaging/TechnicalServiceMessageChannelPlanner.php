<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;

class TechnicalServiceMessageChannelPlanner
{
    /**
     * @param  array<string, mixed>  $base
     * @return array<int, array<string, mixed>>
     */
    public function plan(string $policy, array $base): array
    {
        return match ($policy) {
            'whatsapp_only' => [$this->dispatch($base, 'whatsapp', $base['whatsapp_provider'] ?? 'evo_whatsapp')],
            'sms_only' => [$this->dispatch($base, 'sms', $base['sms_provider'] ?? 'nac_sms')],
            'whatsapp_and_sms' => [
                $this->dispatch($base, 'whatsapp', $base['whatsapp_provider'] ?? 'evo_whatsapp'),
                $this->dispatch($base, 'sms', $base['sms_provider'] ?? 'nac_sms'),
            ],
            'whatsapp_primary_sms_fallback' => [
                $this->dispatch($base, 'whatsapp', $base['whatsapp_provider'] ?? 'evo_whatsapp', ['fallback_pending' => true]),
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fallbackAfter(TechnicalServiceMessageDispatch $dispatch): ?array
    {
        if ($dispatch->channel_policy !== 'whatsapp_primary_sms_fallback'
            || $dispatch->channel !== 'whatsapp'
            || ! in_array($dispatch->status, [TechnicalServiceMessageDispatch::STATUS_FAILED, TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR], true)) {
            return null;
        }

        return [
            'event' => $dispatch->event.'_sms_fallback',
            'request_id' => $dispatch->request_id,
            'message_type' => $dispatch->message_type,
            'channel' => 'sms',
            'provider_key' => 'nac_sms',
            'recipient_role' => $dispatch->recipient_role,
            'target_phone' => null,
            'channel_policy' => $dispatch->channel_policy,
            'parent_dispatch_id' => $dispatch->id,
            'metadata' => ['fallback_from_dispatch_id' => $dispatch->id],
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function dispatch(array $base, string $channel, string $provider, array $metadata = []): array
    {
        return [
            ...$base,
            'channel' => $channel,
            'provider_key' => $provider,
            'metadata' => [
                ...((array) ($base['metadata'] ?? [])),
                ...$metadata,
            ],
        ];
    }
}
