<?php

namespace App\Services\Payments;

use Illuminate\Support\Arr;

class IyzicoLinkResponseNormalizer
{
    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $providerPayload
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
        $providerPayload = $operation === PaymentProviderGatewayRequest::OPERATION_RECONCILE_PAYMENT
            ? $this->safeReportingPayload($providerPayload)
            : $providerPayload;

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
            'provider_payment_reference' => $this->providerPaymentReference($providerPayload, $requestPayload),
            'provider_transaction_reference' => $this->providerTransactionReference($providerPayload),
            'provider_receipt_reference' => null,
            'payment_url' => $this->paymentUrl($providerPayload),
            'provider_status' => $ok ? $this->providerStatus($providerPayload, $operation) : 'provider_error',
            'raw_status' => $this->rawStatus($providerPayload, $operation),
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
                'iyzico_link_product_status' => $this->productStatus($providerPayload),
                'iyzico_link_sold_count' => $this->soldCount($providerPayload),
                'iyzico_reporting_payment_status' => Arr::get($providerPayload, 'payments.0.paymentStatus'),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $requestPayload
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
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $requestPayload
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
     * @param  array<string, mixed>  $payload
     */
    private function paymentUrl(array $payload): ?string
    {
        $value = Arr::get($payload, 'data.url') ?? $payload['url'] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $requestPayload
     */
    private function providerPaymentReference(array $payload, array $requestPayload): ?string
    {
        foreach ([
            'paymentId',
            'payment_id',
            'data.paymentId',
            'data.payment_id',
            'payments.0.paymentId',
            'payments.0.payment_id',
        ] as $key) {
            $value = str_contains($key, '.') ? Arr::get($payload, $key) : ($payload[$key] ?? null);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerTransactionReference(array $payload): ?string
    {
        foreach ([
            'paymentTransactionId',
            'payment_transaction_id',
            'data.paymentTransactionId',
            'data.payment_transaction_id',
            'itemTransactions.0.paymentTransactionId',
            'data.itemTransactions.0.paymentTransactionId',
            'payments.0.itemTransactions.0.paymentTransactionId',
            'hostReference',
            'data.hostReference',
            'payments.0.hostReference',
        ] as $key) {
            $value = str_contains($key, '.') ? Arr::get($payload, $key) : ($payload[$key] ?? null);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerStatus(array $payload, string $operation): string
    {
        if ($operation === PaymentProviderGatewayRequest::OPERATION_RECONCILE_PAYMENT) {
            $status = strtolower(trim((string) Arr::get($payload, 'payments.0.paymentStatus', '')));

            return in_array($status, ['1', 'paid', 'success', 'successful'], true)
                ? 'paid'
                : ($status !== '' ? $status : 'pending');
        }

        $soldCount = $this->soldCount($payload);
        if ($soldCount !== null && $soldCount > 0) {
            return 'sold';
        }

        $status = strtolower((string) (
            $this->productStatus($payload)
            ?? Arr::get($payload, 'data.status')
            ?? $payload['provider_status']
            ?? ''
        ));

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
     * @param  array<string, mixed>  $payload
     */
    private function rawStatus(array $payload, string $operation): ?string
    {
        $value = $operation === PaymentProviderGatewayRequest::OPERATION_RECONCILE_PAYMENT
            ? Arr::get($payload, 'payments.0.paymentStatus')
            : ($this->productStatus($payload) ?? Arr::get($payload, 'data.status') ?? $payload['status'] ?? null);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function productStatus(array $payload): ?string
    {
        $value = Arr::get($payload, 'data.productStatus')
            ?? Arr::get($payload, 'data.status')
            ?? Arr::get($payload, 'productStatus')
            ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function soldCount(array $payload): ?int
    {
        $value = Arr::get($payload, 'data.soldCount') ?? Arr::get($payload, 'soldCount') ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function errorCode(array $payload, int $statusCode): string
    {
        $value = $payload['errorCode'] ?? $payload['error_code'] ?? $payload['errorGroup'] ?? null;

        return is_scalar($value) && trim((string) $value) !== ''
            ? trim((string) $value)
            : 'iyzico_http_'.$statusCode;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function errorMessage(array $payload): string
    {
        $value = $payload['errorMessage'] ?? $payload['error_message'] ?? null;

        return is_scalar($value) && trim((string) $value) !== ''
            ? trim((string) $value)
            : 'Iyzico ödeme sağlayıcısı işlem yanıtı başarısız.';
    }

    /**
     * Reporting responses can contain card and buyer data. Persist only fields
     * required to prove and project the payment result.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safeReportingPayload(array $payload): array
    {
        $safe = Arr::only($payload, [
            'status',
            'locale',
            'systemTime',
            'conversationId',
            'errorCode',
            'errorMessage',
        ]);
        $payments = is_array($payload['payments'] ?? null) ? $payload['payments'] : [];
        $safe['payments'] = array_values(array_filter(array_map(function (mixed $payment): ?array {
            if (! is_array($payment)) {
                return null;
            }

            $safePayment = Arr::only($payment, [
                'paymentId',
                'paymentStatus',
                'refundStatus',
                'price',
                'paidPrice',
                'paymentConversationId',
                'fraudStatus',
                'currency',
                'hostReference',
                'createdDate',
                'updatedDate',
            ]);
            $transactions = is_array($payment['itemTransactions'] ?? null)
                ? $payment['itemTransactions']
                : [];
            $safePayment['itemTransactions'] = array_values(array_filter(array_map(
                fn (mixed $transaction): ?array => is_array($transaction)
                    ? Arr::only($transaction, [
                        'paymentTransactionId',
                        'transactionStatus',
                        'price',
                        'paidPrice',
                        'merchantPayoutAmount',
                    ])
                    : null,
                $transactions,
            )));

            return $safePayment;
        }, $payments)));

        return $safe;
    }
}
