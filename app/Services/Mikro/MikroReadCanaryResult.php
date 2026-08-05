<?php

namespace App\Services\Mikro;

final class MikroReadCanaryResult
{
    public function __construct(
        public readonly bool $executed,
        public readonly MikroCanaryDecision $decision,
        public readonly MikroApiResult $apiResult,
        public readonly int $businessWriteCount,
    ) {}

    public function passed(): bool
    {
        return $this->executed
            && $this->decision->allowed
            && $this->apiResult->success
            && $this->apiResult->httpRequestCount === 1
            && $this->apiResult->readHttpRequestCount === 1
            && $this->apiResult->writeHttpRequestCount === 0
            && $this->businessWriteCount === 0;
    }
}
