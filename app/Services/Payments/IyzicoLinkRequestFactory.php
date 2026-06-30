<?php

namespace App\Services\Payments;

use Illuminate\Support\Arr;

class IyzicoLinkRequestFactory
{
    private const DEFAULT_IMAGE_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function linkBody(array $payload): array
    {
        return [
            'conversationId' => (string) ($payload['conversation_id'] ?? $payload['payment_id'] ?? uniqid('payment:', true)),
            'locale' => 'tr',
            'name' => 'EMAKS Teknik Servis',
            'description' => $this->description($payload),
            'price' => number_format((float) ($payload['amount'] ?? 0), 2, '.', ''),
            'currencyCode' => strtoupper((string) ($payload['currency'] ?? 'TRY')),
            'encodedImageFile' => $this->encodedImageFile(),
            // Iyzico Link checkout should not ask the buyer for address in this flow.
            // Any fallback provider address would be request-compatibility data only, not CRM truth.
            'addressIgnorable' => true,
            'installmentRequested' => false,
            'stockEnabled' => false,
            'categoryType' => 'UNKNOWN',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function description(array $payload): string
    {
        $description = trim((string) ($payload['description'] ?? ''));

        if ($description !== '') {
            return mb_substr($description, 0, 250);
        }

        $parts = array_filter([
            'EMAKS Teknik Servis',
            $this->stringValue($payload['request_code'] ?? null) ? 'MRN '.$this->stringValue($payload['request_code']) : null,
            $this->stringValue($payload['serial_no'] ?? null) ? 'Seri '.$this->stringValue($payload['serial_no']) : null,
        ]);

        return mb_substr(implode(' - ', $parts), 0, 250);
    }

    private function encodedImageFile(): string
    {
        $configured = config('payments.iyzico.link_image_base64');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : self::DEFAULT_IMAGE_BASE64;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function providerToken(array $payload): ?string
    {
        $token = $payload['provider_token']
            ?? $payload['provider_reference']
            ?? Arr::get($payload, 'metadata.provider_reference')
            ?? Arr::get($payload, 'metadata.provider_token')
            ?? null;

        return is_scalar($token) && trim((string) $token) !== '' ? trim((string) $token) : null;
    }
}
