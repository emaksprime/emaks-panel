<?php

namespace App\Services\Mikro;

use Illuminate\Contracts\Foundation\Application;

final class MikroCanaryPolicy
{
    private const LOCAL_EXECUTION_ENVIRONMENTS = ['local', 'testing'];

    /**
     * @param  array{real_canary_enabled?: bool, allowed_canary_environments?: list<string>}  $config
     */
    public function __construct(
        private readonly Application $application,
        private readonly array $config = [],
    ) {}

    public function decide(
        MikroOperationDefinition $operation,
        MikroConnectionProfile $profile,
        MikroCredentialEnvelope $credentials,
        MikroStockListRequest $request,
        MikroRequestContext $context,
    ): MikroCanaryDecision {
        if (($this->config['real_canary_enabled'] ?? false) !== true) {
            return MikroCanaryDecision::deny(MikroErrorClass::FEATURE_DISABLED, 'REAL_CANARY_DISABLED');
        }

        if (! $this->application->environment(...self::LOCAL_EXECUTION_ENVIRONMENTS)
            || ! in_array($context->environment, self::LOCAL_EXECUTION_ENVIRONMENTS, true)) {
            return MikroCanaryDecision::deny(MikroErrorClass::NON_LOCAL_EXECUTION, 'CANARY_ENVIRONMENT_FORBIDDEN');
        }

        $configuredEnvironments = $this->config['allowed_canary_environments'] ?? self::LOCAL_EXECUTION_ENVIRONMENTS;
        $allowedEnvironments = array_values(array_intersect(
            self::LOCAL_EXECUTION_ENVIRONMENTS,
            $configuredEnvironments,
        ));
        if (! in_array($context->environment, $allowedEnvironments, true)) {
            return MikroCanaryDecision::deny(MikroErrorClass::NON_LOCAL_EXECUTION, 'CANARY_ENVIRONMENT_FORBIDDEN');
        }

        if (! $credentials->configured()) {
            return MikroCanaryDecision::deny(MikroErrorClass::MISSING_CREDENTIALS, 'CANARY_CREDENTIALS_MISSING');
        }

        if ($operation->isWrite() || $operation->requiresWriteGate) {
            return MikroCanaryDecision::deny(MikroErrorClass::WRITE_OPERATION_FORBIDDEN, 'CANARY_WRITE_OPERATION_FORBIDDEN');
        }

        if (! $operation->isContractVerified()) {
            return MikroCanaryDecision::deny(MikroErrorClass::CONTRACT_UNVERIFIED, 'CANARY_CONTRACT_UNVERIFIED');
        }

        if ($operation->code !== MikroOperationCatalog::STOCK_LIST
            || $operation->classification !== MikroOperationDefinition::CLASSIFICATION_READ
            || $operation->method !== MikroOperationCatalog::STOCK_LIST_METHOD
            || $operation->endpoint !== MikroOperationCatalog::STOCK_LIST_ENDPOINT
            || ! $operation->safeForCanary
            || $operation->maxRows === null
            || $operation->maxRows > MikroStockListRequest::MAX_CANARY_ROWS) {
            return MikroCanaryDecision::deny(MikroErrorClass::OPERATION_BLOCKED, 'CANARY_OPERATION_NOT_EXACTLY_ALLOWLISTED');
        }

        if ($context->operationCode !== $operation->code
            || $context->requestBudget !== 1
            || $context->httpRequestCount() !== 0
            || ! $context->hasRemainingRequestBudget()) {
            return MikroCanaryDecision::deny(MikroErrorClass::REQUEST_BUDGET_EXCEEDED, 'CANARY_REQUEST_BUDGET_INVALID');
        }

        if ($request->size > $operation->maxRows) {
            return MikroCanaryDecision::deny(MikroErrorClass::OPERATION_BLOCKED, 'CANARY_ROW_LIMIT_EXCEEDED');
        }

        return MikroCanaryDecision::allow();
    }
}
