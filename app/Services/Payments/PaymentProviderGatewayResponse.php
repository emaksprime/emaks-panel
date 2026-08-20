<?php

namespace App\Services\Payments;

final class PaymentProviderGatewayResponse
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(private readonly array $payload) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $payload['provider_response_redacted'] = self::redactProviderResponse(
            is_array($payload['provider_response_redacted'] ?? null)
                ? $payload['provider_response_redacted']
                : (is_array($payload['provider_response'] ?? null) ? $payload['provider_response'] : [])
        );
        unset($payload['provider_response']);

        return new self($payload);
    }

    public static function disabled(string $message): self
    {
        return new self([
            'ok' => false,
            'provider' => 'iyzico',
            'error_code' => 'provider_disabled',
            'error_message' => $message,
            'provider_response_redacted' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public static function redactProviderResponse(array $response): array
    {
        $redacted = [];

        foreach ($response as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (str_contains($normalizedKey, 'secret')
                || str_contains($normalizedKey, 'api_key')
                || str_contains($normalizedKey, 'apikey')
                || str_contains($normalizedKey, 'authorization')
                || str_contains($normalizedKey, 'signature')
                || str_contains($normalizedKey, 'randomkey')
                || str_contains($normalizedKey, 'x-iyzi-rnd')
                || str_contains($normalizedKey, 'password')
                || str_contains($normalizedKey, 'credential')
                || str_contains($normalizedKey, 'x-panel-token')
                || str_contains($normalizedKey, 'iyzwsv2')
                || str_contains($normalizedKey, 'panel_token')
                || str_contains($normalizedKey, 'gateway_token')) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_array($value) ? self::redactProviderResponse($value) : $value;
        }

        return $redacted;
    }

    public function ok(): bool
    {
        return (bool) ($this->payload['ok'] ?? false);
    }

    public function providerToken(): ?string
    {
        $value = $this->payload['provider_token'] ?? $this->payload['provider_reference'] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    public function paymentUrl(): ?string
    {
        $value = $this->payload['payment_url'] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    public function dryRun(): bool
    {
        return (bool) ($this->payload['dry_run'] ?? false);
    }

    public function noSend(): bool
    {
        return (bool) ($this->payload['no_send'] ?? false);
    }

    public function wouldSend(): bool
    {
        return (bool) ($this->payload['would_send'] ?? false);
    }

    public function errorMessage(): string
    {
        $message = $this->payload['error_message'] ?? null;

        return is_scalar($message) && trim((string) $message) !== ''
            ? (string) $message
            : TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
