<?php

namespace App\Services\Mikro;

use UnexpectedValueException;

final class MikroReadCanaryService
{
    public function __construct(
        private readonly MikroApiClientInterface $client,
        private readonly MikroCanaryPolicy $policy,
        private readonly MikroOperationCatalogInterface $catalog,
        private readonly MikroBusinessWriteMonitor $writeMonitor,
    ) {}

    public function run(
        MikroConnectionProfile $profile,
        MikroCredentialEnvelope $credentials,
        MikroRequestContext $context,
        MikroStockListRequest $request,
    ): MikroReadCanaryResult {
        $operation = $this->catalog->find(MikroOperationCatalog::STOCK_LIST);
        if (! $operation instanceof MikroOperationDefinition) {
            throw new UnexpectedValueException('Stock operation is missing from the catalog.');
        }

        $decision = $this->policy->decide($operation, $profile, $credentials, $request, $context);
        if (! $decision->allowed) {
            return new MikroReadCanaryResult(
                executed: false,
                decision: $decision,
                apiResult: $this->blockedResult($context, $decision),
                businessWriteCount: 0,
            );
        }

        $measurement = $this->writeMonitor->measure(
            fn (): MikroApiResult => $this->client->readStockList($profile, $credentials, $context, $request),
        );
        if (! $measurement->value instanceof MikroApiResult) {
            throw new UnexpectedValueException('Canary client returned an invalid result type.');
        }

        $apiResult = $measurement->value;
        if ($measurement->writeCount > 0
            || $context->writeHttpRequestCount() > 0
            || $apiResult->writeHttpRequestCount > 0) {
            $apiResult = new MikroApiResult(
                success: false,
                httpStatus: $apiResult->httpStatus,
                providerStatus: $apiResult->providerStatus,
                errorClass: MikroErrorClass::BUSINESS_WRITE_DETECTED,
                errorCode: 'CANARY_WRITE_DETECTED',
                message: 'Canary execution recorded a forbidden write side effect.',
                rowCount: 0,
                durationMs: $context->durationMs(),
                httpRequestCount: $context->httpRequestCount(),
                readHttpRequestCount: $context->readHttpRequestCount(),
                writeHttpRequestCount: $context->writeHttpRequestCount(),
                correlationId: $context->correlationId,
                metadata: ['original_error_class' => $apiResult->errorClass],
            );
        } elseif ($apiResult->success && ! $this->hasExactReadCounters($apiResult, $context)) {
            $apiResult = new MikroApiResult(
                success: false,
                httpStatus: $apiResult->httpStatus,
                providerStatus: $apiResult->providerStatus,
                errorClass: MikroErrorClass::INVALID_RESPONSE,
                errorCode: 'CANARY_COUNTER_INTEGRITY_FAILED',
                message: 'Canary execution counters did not match the measured request context.',
                rowCount: 0,
                durationMs: $context->durationMs(),
                httpRequestCount: $context->httpRequestCount(),
                readHttpRequestCount: $context->readHttpRequestCount(),
                writeHttpRequestCount: $context->writeHttpRequestCount(),
                correlationId: $context->correlationId,
                metadata: ['original_error_class' => $apiResult->errorClass],
            );
        }

        return new MikroReadCanaryResult(
            executed: true,
            decision: $decision,
            apiResult: $apiResult,
            businessWriteCount: $measurement->writeCount,
        );
    }

    private function hasExactReadCounters(
        MikroApiResult $apiResult,
        MikroRequestContext $context,
    ): bool {
        return $context->httpRequestCount() === 1
            && $context->readHttpRequestCount() === 1
            && $context->writeHttpRequestCount() === 0
            && $apiResult->httpRequestCount === $context->httpRequestCount()
            && $apiResult->readHttpRequestCount === $context->readHttpRequestCount()
            && $apiResult->writeHttpRequestCount === $context->writeHttpRequestCount();
    }

    private function blockedResult(
        MikroRequestContext $context,
        MikroCanaryDecision $decision,
    ): MikroApiResult {
        return new MikroApiResult(
            success: false,
            httpStatus: null,
            providerStatus: null,
            errorClass: $decision->errorClass,
            errorCode: $decision->reason,
            message: 'Canary execution was blocked before any provider request.',
            rowCount: 0,
            durationMs: $context->durationMs(),
            httpRequestCount: $context->httpRequestCount(),
            readHttpRequestCount: $context->readHttpRequestCount(),
            writeHttpRequestCount: $context->writeHttpRequestCount(),
            correlationId: $context->correlationId,
        );
    }
}
