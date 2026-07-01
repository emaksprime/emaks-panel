<?php

namespace App\Services\Payments;

use InvalidArgumentException;

final class PaymentProviderGatewayRequest
{
    public const OPERATION_CREATE_LINK = 'create_link';
    public const OPERATION_UPDATE_LINK = 'update_link';
    public const OPERATION_CANCEL_LINK = 'cancel_link';
    public const OPERATION_GET_LINK = 'get_link';
    public const OPERATION_LIST_LINKS = 'list_links';
    public const OPERATION_SYNC_STATUS = 'sync_status';
    public const OPERATION_RECONCILE_PAYMENT = 'reconcile_payment';
    public const OPERATION_PROVIDER_HEALTH_CHECK = 'provider_health_check';

    public const ALLOWED_OPERATIONS = [
        self::OPERATION_CREATE_LINK,
        self::OPERATION_UPDATE_LINK,
        self::OPERATION_CANCEL_LINK,
        self::OPERATION_GET_LINK,
        self::OPERATION_LIST_LINKS,
        self::OPERATION_SYNC_STATUS,
        self::OPERATION_RECONCILE_PAYMENT,
        self::OPERATION_PROVIDER_HEALTH_CHECK,
    ];

    /**
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $provider,
        private readonly string $mode,
        private readonly string $operation,
        private readonly string $paymentId,
        private readonly ?string $requestId,
        private readonly ?string $requestCode,
        private readonly ?string $rootMrn,
        private readonly ?string $serialNo,
        private readonly array $customer,
        private readonly string $amount,
        private readonly string $currency,
        private readonly string $description,
        private readonly string $conversationId,
        private readonly string $idempotencyKey,
        private readonly ?string $callbackUrl = null,
        private readonly ?string $returnUrl = null,
        private readonly array $metadata = [],
        private readonly bool $dryRun = false,
        private readonly bool $noSend = false,
        private readonly bool $allowProviderSend = false,
    ) {
        if (! in_array($operation, self::ALLOWED_OPERATIONS, true)) {
            throw new InvalidArgumentException('Desteklenmeyen ödeme sağlayıcısı operasyonu.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'mode' => $this->mode,
            'operation' => $this->operation,
            'payment_id' => $this->paymentId,
            'request_id' => $this->requestId,
            'request_code' => $this->requestCode,
            'root_mrn' => $this->rootMrn,
            'serial_no' => $this->serialNo,
            'customer' => $this->customer,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'conversation_id' => $this->conversationId,
            'idempotency_key' => $this->idempotencyKey,
            'callback_url' => $this->callbackUrl,
            'return_url' => $this->returnUrl,
            'metadata' => $this->metadata,
            'dry_run' => $this->dryRun,
            'no_send' => $this->noSend,
            'allow_provider_send' => $this->allowProviderSend,
        ];
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function mode(): string
    {
        return $this->mode;
    }
}
