<?php

namespace App\Services\Payments;

use Illuminate\Support\Arr;

class IyzicoLinkResponseNormalizer
{
    /**
     * @param array<string, mixed> $requestPayload
     * @param array<string, mixed> $providerPayload
     */
    public function normalize(
        array $requestPayload,
        array $providerPayload,
        string $operation,
        int $statusCode,
        bool $dryRun = false,
        bool $noSend = false,
    ): PaymentProviderGatewayResponse {
        $topLevelStatus = strtolower((string) ($providerPayload['status'] ?? ''));
        $ok = $statusCode >= 200 && $statusCode < 300 && $topLevelStatus !== 'failure';

        return PaymentProviderGatewayResponse::fromArray([
            'ok' => $ok,
            'provider' => 'iyzico',
            'mode' => (string) ($requestPayload['mode'] ?? 'sandbox'),
            'operation' => $operation,
            'payment_id' => (string) ($requestPayload['payment_id'] ?? ''),
            'request_id' => $requestPayload['request_id'] ?? null,
            'request_code' => $requestPayload['request_code'] ?? null,
            'root_mrn' => $requestPayload['root_mrn'] ?? null,
            'serial_no' => $requestPayload['serial_no'] ?? null,
            'amount' => (string) ($requestPayload['amount'] ?? ''),
            'currency' => (string) ($requestPayload['currency'] ?? 'TRY'),
            'conversation_id' => (string) ($providerPayload['conversationId'] ?? $requestPayload['conversation_id'] ?? ''),
            'provider_token' => $this->providerToken($providerPayload, $requestPayload),
            'payment_url' => $this->paymentUrl($providerPayload),
            'provider_status' => $ok ? $this->providerStatus($providerPayload, $operation) : 'provider_error',
            'raw_status' => $this->rawStatus($providerPayload),
            'error_code' => $ok ? null : $this->errorCode($providerPayload, $statusCode),
            'error_message' => $ok ? null : $this->errorMessage($providerPayload),
            'provider_response_redacted' => $providerPayload,
            'dry_run' => $dryRun,
            'no_send' => $noSend,
            'would_send' => ! $dryRun && ! $noSend,
            'should_call_iyzico' => ! $dryRun && ! $noSend,
            'provider_send_allowed' => ! $dryRun && ! $noSend,
            'status_code' => $statusCode,
            'meta' => [
                'transport' => TechnicalServicePaymentProviderTransportResolver::TRANSPORT_DIRECT_LARAVEL,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $requestPayload
     */
    public function noSend(array $requestPayload, string $operation): PaymentProviderGatewayResponse
    {
        return PaymentProviderGatewayResponse::fromArray([
            'ok' => true,
            'provider' => 'iyzico',
            'mode' => (string) ($requestPayload['mode'] ?? 'sandbox'),
            'operation' => $operation,
            'payment_id' => (string) ($requestPayload['payment_id'] ?? ''),
            'request_code' => $requestPayload['request_code'] ?? null,
            'provider_status' => 'disabled_no_send',
            'raw_status' => 'disabled_no_send',
            'provider_response_redacted' => [],
            'dry_run' => (bool) ($requestPayload['dry_run'] ?? false),
            'no_send' => (bool) ($requestPayload['no_send'] ?? false),
            'would_send' => false,
            'should_call_iyzico' => false,
            'provider_send_allowed' => false,
            'status_code' => 200,
            'meta' => [
                'transport' => TechnicalServicePaymentProviderTransportResolver::TRANSPORT_DIRECT_LARAVEL,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $requestPayload
     */
    private function providerToken(array $payload, array $requestPayload): ?string
    {
        $value = Arr::get($payload, 'data.token')
            ?? $payload['token']
            ?? $requestPayload['provider_reference']
            ?? Arr::get($requestPayload, 'metadata.provider_reference')
            ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function paymentUrl(array $payload): ?string
    {
        $value = Arr::get($payload, 'data.url') ?? $payload['url'] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function providerStatus(array $payload, string $operation): string
    {
        $status = strtolower((string) (Arr::get($payload, 'data.status') ?? $payload['provider_status'] ?? ''));

        if ($status !== '') {
            return $status;
        }

        return match ($operation) {
            PaymentProviderGatewayRequest::OPERATION_CANCEL_LINK => 'passive',
            PaymentProviderGatewayRequest::OPERATION_SYNC_STATUS,
            PaymentProviderGatewayRequest::OPERATION_GET_LINK => 'active',
            PaymentProviderGatewayRequest::OPERATION_UPDATE_LINK => 'active',
            default => 'active',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function rawStatus(array $payload): ?string
    {
        $value = Arr::get($payload, 'data.status') ?? $payload['status'] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function errorCode(array $payload, int $statusCode): string
    {
        $value = $payload['errorCode'] ?? $payload['error_code'] ?? $payload['errorGroup'] ?? null;

        return is_scalar($value) && trim((string) $value) !== ''
            ? trim((string) $value)
            : 'iyzico_http_'.$statusCode;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function errorMessage(array $payload): string
    {
        $value = $payload['errorMessage'] ?? $payload['error_message'] ?? null;

        return is_scalar($value) && trim((string) $value) !== ''
            ? trim((string) $value)
            : 'Iyzico ödeme sağlayıcısı işlem yanıtı başarısız.';
    }
}
