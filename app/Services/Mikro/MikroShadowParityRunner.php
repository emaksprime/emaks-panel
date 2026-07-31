<?php

namespace App\Services\Mikro;

use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\N8nPanelDataGateway;
use DomainException;
use Throwable;

class MikroShadowParityRunner
{
    public function __construct(
        private readonly MikroParityContract $contract,
        private readonly MikroParitySampleAuthority $samples,
        private readonly N8nPanelDataGateway $n8n,
        private readonly MikroApiClient $mikro,
        private readonly TechnicalServiceMessagingSettingsService $settings,
    ) {}

    /** @return array<string, mixed> */
    public function preflight(): array
    {
        $context = $this->settings->mikroApiConnectionContext();
        $operations = [];
        foreach ($this->contract->operationKeys() as $operationKey) {
            $operations[$operationKey] = $this->contract->operationReadiness($operationKey);
        }

        return [
            'normalization_version' => MikroParityContract::NORMALIZATION_VERSION,
            'operation_contract_version' => MikroParityContract::OPERATION_CONTRACT_VERSION,
            'contract_fingerprint' => $this->contract->fingerprint(),
            'operations' => $operations,
            'mikro_switches' => [
                'active' => (bool) ($context['enabled'] ?? false),
                'read_sync' => (bool) ($context['read_sync_enabled'] ?? false),
                'write' => (bool) ($context['write_enabled'] ?? false),
            ],
            'formal_run_1' => 'NOT_RUN',
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $parametersByOperation
     * @return array<string, mixed>
     */
    public function discoverSamples(array $parametersByOperation): array
    {
        $results = [];
        foreach ($this->contract->operationKeys() as $operationKey) {
            $parameters = $parametersByOperation[$operationKey] ?? null;
            if (! is_array($parameters)) {
                throw new DomainException('MIKRO_PARITY_DISCOVERY_PARAMETERS_MISSING');
            }
            $source = MikroParitySource::discoveryFor($operationKey);
            try {
                $results[$operationKey] = $this->n8n->readForParity($source, $parameters);
            } catch (Throwable $exception) {
                $results[$operationKey] = [
                    'status' => 'SOURCE_UNAVAILABLE',
                    'operation_key' => $operationKey,
                    'error_code' => $this->safeErrorCode($exception),
                    'envelope' => ['samples' => []],
                ];
            }
        }

        return $this->samples->build($results);
    }

    /**
     * One operation-level schema probe performs exactly one n8n read and one Mikro
     * read. It does not compare records or produce a formal parity outcome.
     *
     * @param  array<string, mixed>  $lookup
     * @return array<string, mixed>
     */
    public function schemaProbe(string $operationKey, array $lookup): array
    {
        $source = MikroParitySource::detailFor($operationKey);
        try {
            $n8n = $this->n8n->readForParity($source, $lookup);
        } catch (Throwable $exception) {
            $n8n = ['status' => 'SOURCE_UNAVAILABLE', 'error_code' => $this->safeErrorCode($exception)];
        }
        try {
            $mikro = $this->mikro->authenticatedParityRead($source, $lookup);
        } catch (Throwable $exception) {
            $mikro = ['success' => false, 'error_code' => $this->safeErrorCode($exception)];
        }

        return [
            'operation_key' => $operationKey,
            'formal_parity_result' => 'NOT_RUN',
            'n8n' => $n8n,
            'mikro' => $mikro,
            'source_mode_mutated' => false,
            'mikro_switches_mutated' => false,
        ];
    }

    public function runFormalParity(string $operationKey): never
    {
        MikroParitySource::detailFor($operationKey);

        throw new DomainException('MIKRO_FORMAL_SHADOW_PARITY_NOT_AUTHORIZED');
    }

    private function safeErrorCode(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return preg_match('/^[A-Z0-9_]+$/', $message) === 1
            ? $message
            : 'PARITY_SOURCE_REQUEST_FAILED';
    }
}
