<?php

namespace App\Services\Mikro;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;

final class MikroRequestContext
{
    private int $httpRequestCount = 0;

    private int $readHttpRequestCount = 0;

    private int $writeHttpRequestCount = 0;

    public readonly CarbonImmutable $startedAt;

    public function __construct(
        public readonly string $correlationId,
        public readonly string $operationCode,
        public readonly string $environment,
        public readonly int $requestBudget = 1,
        ?CarbonImmutable $startedAt = null,
    ) {
        if (trim($correlationId) === '' || strlen($correlationId) > 128) {
            throw new InvalidArgumentException('Correlation id is invalid.');
        }

        if (trim($operationCode) === '' || trim($environment) === '') {
            throw new InvalidArgumentException('Request context fields must not be blank.');
        }

        if ($requestBudget < 1) {
            throw new InvalidArgumentException('Request budget must be positive.');
        }

        $this->startedAt = $startedAt ?? CarbonImmutable::now();
    }

    public function recordHttpRequest(string $classification): void
    {
        if (! in_array($classification, [
            MikroOperationDefinition::CLASSIFICATION_READ,
            MikroOperationDefinition::CLASSIFICATION_WRITE,
        ], true)) {
            throw new InvalidArgumentException('Unsupported HTTP request classification.');
        }

        if ($this->httpRequestCount >= $this->requestBudget) {
            throw new LogicException(MikroErrorClass::REQUEST_BUDGET_EXCEEDED);
        }

        $this->httpRequestCount++;
        if ($classification === MikroOperationDefinition::CLASSIFICATION_READ) {
            $this->readHttpRequestCount++;
        } else {
            $this->writeHttpRequestCount++;
        }
    }

    public function hasRemainingRequestBudget(): bool
    {
        return $this->httpRequestCount < $this->requestBudget;
    }

    public function httpRequestCount(): int
    {
        return $this->httpRequestCount;
    }

    public function readHttpRequestCount(): int
    {
        return $this->readHttpRequestCount;
    }

    public function writeHttpRequestCount(): int
    {
        return $this->writeHttpRequestCount;
    }

    public function durationMs(): int
    {
        return max(0, CarbonImmutable::now()->getTimestampMs() - $this->startedAt->getTimestampMs());
    }
}
