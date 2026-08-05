<?php

namespace App\Services\Mikro;

use JsonSerializable;

final class MikroApiResult implements JsonSerializable
{
    /**
     * @param  array<string, bool|int|string|null>  $metadata
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?int $httpStatus,
        public readonly ?string $providerStatus,
        public readonly string $errorClass,
        public readonly ?string $errorCode,
        public readonly string $message,
        public readonly int $rowCount,
        public readonly int $durationMs,
        public readonly int $httpRequestCount,
        public readonly int $readHttpRequestCount,
        public readonly int $writeHttpRequestCount,
        public readonly string $correlationId,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, bool|int|string|array<string, bool|int|string|null>|null>
     */
    public function jsonSerialize(): array
    {
        return [
            'success' => $this->success,
            'http_status' => $this->httpStatus,
            'provider_status' => $this->providerStatus,
            'error_class' => $this->errorClass,
            'error_code' => $this->errorCode,
            'message' => $this->message,
            'row_count' => $this->rowCount,
            'duration_ms' => $this->durationMs,
            'http_request_count' => $this->httpRequestCount,
            'read_http_request_count' => $this->readHttpRequestCount,
            'write_http_request_count' => $this->writeHttpRequestCount,
            'correlation_id' => $this->correlationId,
            'metadata' => $this->metadata,
        ];
    }
}
