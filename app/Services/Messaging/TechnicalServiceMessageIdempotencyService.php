<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;

class TechnicalServiceMessageIdempotencyService
{
    public function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return '90'.substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '90'.$digits;
        }

        return $digits;
    }

    public function phoneHash(?string $phone): ?string
    {
        $normalized = $this->normalizePhone($phone);

        return $normalized === '' ? null : hash('sha256', $normalized);
    }

    public function maskPhone(?string $phone): ?string
    {
        $normalized = $this->normalizePhone($phone);

        if ($normalized === '') {
            return null;
        }

        return substr($normalized, 0, 4).'***'.substr($normalized, -3);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function payloadHash(array $payload): string
    {
        return hash('sha256', $this->stableJson($payload));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function idempotencyKey(array $values): string
    {
        $parts = [
            $values['request_id'] ?? $values['technical_service_request_id'] ?? 'no-request',
            $values['related_type'] ?? 'no-related-type',
            $values['related_id'] ?? 'no-related-id',
            $values['message_type'] ?? $values['event'] ?? 'message',
            $values['channel'] ?? 'channel',
            $values['provider_key'] ?? 'provider',
            $values['recipient_role'] ?? $values['target_type'] ?? 'recipient',
            $values['recipient_phone_hash'] ?? $values['effective_target_phone_hash'] ?? 'no-phone',
            $values['business_event_id'] ?? $values['appointment_id'] ?? 'no-event',
            $values['template_key'] ?? 'no-template',
            $values['template_version'] ?? 'no-version',
            $values['payload_hash'] ?? 'no-payload',
            $values['channel_policy'] ?? 'no-policy',
        ];

        if (filter_var($values['force_resend'] ?? false, FILTER_VALIDATE_BOOL)) {
            $parts[] = 'force';
            $parts[] = $values['parent_dispatch_id'] ?? 'no-parent';
            $parts[] = $values['force_resend_nonce'] ?? microtime(true);
        }

        return hash('sha256', implode('|', array_map(fn (mixed $value): string => (string) $value, $parts)));
    }

    public function blockingDuplicate(string $idempotencyKey): ?TechnicalServiceMessageDispatch
    {
        if ($idempotencyKey === '') {
            return null;
        }

        return TechnicalServiceMessageDispatch::query()
            ->where('idempotency_key', $idempotencyKey)
            ->whereIn('status', TechnicalServiceMessageDispatch::DUPLICATE_BLOCKING_STATUSES)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function stableJson(array $value): string
    {
        $normalized = $this->sortKeys($value);

        return (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function sortKeys(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeys($item);
            }
        }

        return $value;
    }
}
