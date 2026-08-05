<?php

namespace App\Services\Mikro;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use LogicException;
use Throwable;

final class DirectMikroApiClient implements MikroApiClientInterface
{
    private const LOCAL_EXECUTION_ENVIRONMENTS = ['local', 'testing'];

    public function __construct(
        private readonly Factory $http,
        private readonly Application $application,
        private readonly bool $healthProbeEnabled = false,
    ) {}

    public function probeHealth(
        MikroConnectionProfile $profile,
        MikroRequestContext $context,
    ): MikroApiResult {
        if (! $this->healthProbeEnabled) {
            return $this->failure(
                $context,
                MikroErrorClass::FEATURE_DISABLED,
                'REAL_HEALTH_PROBE_DISABLED',
                'Real health probing is disabled by default.',
            );
        }

        if (! $this->application->environment(...self::LOCAL_EXECUTION_ENVIRONMENTS)
            || ! in_array($context->environment, self::LOCAL_EXECUTION_ENVIRONMENTS, true)) {
            return $this->failure(
                $context,
                MikroErrorClass::NON_LOCAL_EXECUTION,
                'HEALTH_PROBE_ENVIRONMENT_FORBIDDEN',
                'Health probing is not allowed in this environment.',
            );
        }

        if ($context->operationCode !== MikroOperationCatalog::HEALTH_CHECK) {
            return $this->failure(
                $context,
                MikroErrorClass::OPERATION_BLOCKED,
                'HEALTH_OPERATION_NOT_ALLOWLISTED',
                'Health probing accepts only the exact health operation.',
            );
        }

        if ($context->requestBudget !== 1
            || $context->httpRequestCount() !== 0
            || ! $context->hasRemainingRequestBudget()) {
            return $this->failure(
                $context,
                MikroErrorClass::REQUEST_BUDGET_EXCEEDED,
                'HEALTH_REQUEST_BUDGET_INVALID',
                'Health probing requires one unused request slot.',
            );
        }

        try {
            $context->recordHttpRequest(MikroOperationDefinition::CLASSIFICATION_READ);

            $response = $this->http
                ->withHeaders(['X-Correlation-ID' => $context->correlationId])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(min(5, $profile->timeoutSeconds))
                ->timeout($profile->timeoutSeconds)
                ->get($profile->baseUrl->endpoint(MikroOperationCatalog::HEALTH_ENDPOINT));

            $status = $response->status();
            if ($status >= 200 && $status < 300) {
                return $this->failure(
                    $context,
                    MikroErrorClass::BODY_CONTRACT_UNVERIFIED,
                    'HEALTH_RESPONSE_NOT_INTERPRETED',
                    'Health response body contract has not been verified.',
                    $status,
                    ['body_consumed' => false],
                );
            }

            return $this->failure(
                $context,
                match (true) {
                    $status >= 400 && $status < 500 => MikroErrorClass::HTTP_CLIENT,
                    $status >= 500 => MikroErrorClass::HTTP_SERVER,
                    default => MikroErrorClass::PROVIDER_REJECTED,
                },
                'HEALTH_REQUEST_REJECTED',
                'Health request did not return an acceptable status.',
                $status,
                ['body_consumed' => false],
            );
        } catch (LogicException) {
            return $this->failure(
                $context,
                MikroErrorClass::REQUEST_BUDGET_EXCEEDED,
                'HTTP_REQUEST_BUDGET_EXCEEDED',
                'HTTP request budget was exceeded.',
            );
        } catch (ConnectionException) {
            return $this->failure(
                $context,
                MikroErrorClass::HTTP_CONNECTION,
                'HEALTH_CONNECTION_FAILED',
                'Health request could not connect.',
            );
        } catch (Throwable) {
            return $this->failure(
                $context,
                MikroErrorClass::UNKNOWN,
                'HEALTH_REQUEST_FAILED',
                'Health request failed without a trusted response.',
            );
        }
    }

    public function readStockList(
        MikroConnectionProfile $profile,
        MikroCredentialEnvelope $credentials,
        MikroRequestContext $context,
        MikroStockListRequest $request,
    ): MikroApiResult {
        return $this->failure(
            $context,
            MikroErrorClass::CONTRACT_UNVERIFIED,
            'DIRECT_STOCK_CONTRACT_BLOCKED',
            'Direct stock request is blocked pending a canonical request and response contract.',
            metadata: [
                'contract_verified' => false,
                'request_size' => $request->size,
            ],
        );
    }

    /**
     * @param  array<string, bool|int|string|null>  $metadata
     */
    private function failure(
        MikroRequestContext $context,
        string $errorClass,
        string $errorCode,
        string $message,
        ?int $httpStatus = null,
        array $metadata = [],
    ): MikroApiResult {
        return new MikroApiResult(
            success: false,
            httpStatus: $httpStatus,
            providerStatus: null,
            errorClass: $errorClass,
            errorCode: $errorCode,
            message: $message,
            rowCount: 0,
            durationMs: $context->durationMs(),
            httpRequestCount: $context->httpRequestCount(),
            readHttpRequestCount: $context->readHttpRequestCount(),
            writeHttpRequestCount: $context->writeHttpRequestCount(),
            correlationId: $context->correlationId,
            metadata: $metadata,
        );
    }
}
