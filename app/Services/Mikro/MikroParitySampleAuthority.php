<?php

namespace App\Services\Mikro;

use DomainException;

class MikroParitySampleAuthority
{
    private const SAMPLE_VERSION = 'mikro-shadow-parity-samples.v1';

    private const TARGETS = [
        'customer.lookup' => 50,
        'stock.availability' => 100,
        'serial.lookup' => 50,
        'order.detail' => 50,
    ];

    public function __construct(private readonly MikroParityContract $contract) {}

    /**
     * @param  array<string, array<string, mixed>>  $discoveryResults
     * @return array<string, mixed>
     */
    public function build(array $discoveryResults): array
    {
        $operations = [];

        foreach (self::TARGETS as $operationKey => $target) {
            $result = $discoveryResults[$operationKey] ?? null;
            if (! is_array($result) || ($result['status'] ?? null) !== 'READY') {
                $sourceStatus = is_array($result) && in_array(($result['status'] ?? null), ['CONTRACT_ERROR', 'SOURCE_UNAVAILABLE'], true)
                    ? (string) $result['status']
                    : 'SOURCE_UNAVAILABLE';
                $operations[$operationKey] = [
                    'status' => $sourceStatus,
                    'error_code' => is_array($result)
                        ? (string) ($result['error_code'] ?? 'PARITY_DISCOVERY_SOURCE_UNAVAILABLE')
                        : 'PARITY_DISCOVERY_RESULT_MISSING',
                    'available_count' => 0,
                    'selected_count' => 0,
                    'target_count' => $target,
                    'duplicate_identity_count' => 0,
                    'samples' => [],
                ];

                continue;
            }

            $samples = data_get($result, 'envelope.samples', []);
            if (! is_array($samples)) {
                throw new DomainException('MIKRO_PARITY_SAMPLE_DISCOVERY_INVALID');
            }

            $unique = [];
            $duplicates = 0;
            foreach ($samples as $sample) {
                if (! is_array($sample) || trim((string) ($sample['identity'] ?? '')) === '' || ! is_array($sample['lookup'] ?? null)) {
                    throw new DomainException('MIKRO_PARITY_SAMPLE_DISCOVERY_INVALID');
                }
                $identity = (string) $sample['identity'];
                if (array_key_exists($identity, $unique)) {
                    $duplicates++;

                    continue;
                }
                $unique[$identity] = $sample;
            }

            $ordered = array_values($unique);
            usort($ordered, function (array $left, array $right) use ($operationKey): int {
                $leftKey = hash('sha256', self::SAMPLE_VERSION.'|'.$operationKey.'|'.$left['identity']);
                $rightKey = hash('sha256', self::SAMPLE_VERSION.'|'.$operationKey.'|'.$right['identity']);

                return [$leftKey, $left['identity']] <=> [$rightKey, $right['identity']];
            });

            $selected = array_slice($ordered, 0, $target);
            $operations[$operationKey] = [
                'status' => $duplicates > 0
                    ? 'CONTRACT_ERROR'
                    : (count($ordered) < $target ? 'INSUFFICIENT_SAMPLE' : 'READY'),
                'available_count' => count($ordered),
                'selected_count' => count($selected),
                'target_count' => $target,
                'duplicate_identity_count' => $duplicates,
                'samples' => $selected,
            ];
        }

        $manifest = [
            'sample_version' => self::SAMPLE_VERSION,
            'normalization_version' => MikroParityContract::NORMALIZATION_VERSION,
            'operation_contract_version' => MikroParityContract::OPERATION_CONTRACT_VERSION,
            'contract_fingerprint' => $this->contract->fingerprint(),
            'selection_algorithm' => 'sha256(sample_version|operation_key|identity), then identity',
            'operations' => $operations,
        ];

        return [
            ...$manifest,
            'sample_manifest_sha256' => hash('sha256', $this->contract->canonicalJson($manifest)),
        ];
    }

    /** @param array<string, mixed> $manifest */
    public function assertReusable(array $manifest): void
    {
        $providedHash = (string) ($manifest['sample_manifest_sha256'] ?? '');
        $unsigned = $manifest;
        unset($unsigned['sample_manifest_sha256']);

        if (($manifest['sample_version'] ?? null) !== self::SAMPLE_VERSION
            || ($manifest['contract_fingerprint'] ?? null) !== $this->contract->fingerprint()
            || $providedHash === ''
            || ! hash_equals($providedHash, hash('sha256', $this->contract->canonicalJson($unsigned)))) {
            throw new DomainException('MIKRO_PARITY_SAMPLE_MANIFEST_INVALID');
        }

        foreach (self::TARGETS as $operationKey => $target) {
            $operation = is_array($manifest['operations'] ?? null)
                ? ($manifest['operations'][$operationKey] ?? null)
                : null;
            if (! is_array($operation) || (int) ($operation['target_count'] ?? 0) !== $target) {
                throw new DomainException('MIKRO_PARITY_SAMPLE_MANIFEST_INVALID');
            }
        }
    }
}
