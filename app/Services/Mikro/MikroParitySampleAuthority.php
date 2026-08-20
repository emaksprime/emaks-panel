<?php

namespace App\Services\Mikro;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

class MikroParitySampleAuthority
{
    public function __construct(private readonly MikroParityContract $contract) {}

    /** @return array{key_base64:string,salt_base64:string} */
    public function generateKeyMaterial(): array
    {
        return [
            'key_base64' => base64_encode(random_bytes(32)),
            'salt_base64' => base64_encode(random_bytes(16)),
        ];
    }

    /** @param array<string, mixed> $sourceContext
     * @return array<string, mixed>
     */
    public function validatedSourceContext(array $sourceContext): array
    {
        return $this->normalizeSourceContext($sourceContext);
    }

    /**
     * @param  array<string, array<string, mixed>>  $discoveryResults
     * @param  array<string, mixed>  $sourceContext
     * @param  array<string, mixed>  $retention
     * @return array{public_manifest:array<string,mixed>,protected_manifest:array<string,mixed>}
     */
    public function build(
        array $discoveryResults,
        array $sourceContext,
        string $keyBase64,
        string $saltBase64,
        array $retention,
    ): array {
        [$key, $salt] = $this->decodeKeyMaterial($keyBase64, $saltBase64);
        $context = $this->normalizeSourceContext($sourceContext);
        $retention = $this->normalizeRetention($retention);
        $policy = $this->contract->samplePolicy();
        $operations = [];

        foreach ($policy['targets'] as $operationKey => $target) {
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
                    'business_selected_count' => 0,
                    'synthetic_selected_count' => 0,
                    'selected_count' => 0,
                    'target_count' => $target,
                    'duplicate_identity_count' => 0,
                    'strata_coverage' => [],
                    'unavailable_strata' => [],
                    'samples' => [],
                ];

                continue;
            }

            $samples = data_get($result, 'envelope.samples', []);
            if (! is_array($samples)) {
                throw new DomainException('MIKRO_PARITY_SAMPLE_DISCOVERY_INVALID');
            }

            [$unique, $duplicates] = $this->uniqueSamples($operationKey, $samples, $context);
            $ordered = array_values($unique);
            usort($ordered, fn (array $left, array $right): int => $this->selectionKey($operationKey, $left)
                <=> $this->selectionKey($operationKey, $right));

            [$selected, $coverage, $unavailableStrata] = $this->selectRequiredStrata(
                $operationKey,
                $ordered,
                $policy['operations'][$operationKey],
                (int) $target,
            );
            $businessSelectedCount = count($selected);
            $syntheticCount = 0;
            if ($operationKey === 'serial.lookup') {
                $selected[] = $this->negativeSerialCanary($key, $salt);
                $syntheticCount = 1;
            }

            $status = match (true) {
                $duplicates > 0 => 'CONTRACT_ERROR',
                count($ordered) < $target => 'INSUFFICIENT_SAMPLE',
                $unavailableStrata !== [] => 'STRATUM_UNAVAILABLE',
                default => 'READY',
            };
            $operations[$operationKey] = [
                'status' => $status,
                'available_count' => count($ordered),
                'business_selected_count' => $businessSelectedCount,
                'synthetic_selected_count' => $syntheticCount,
                'selected_count' => count($selected),
                'target_count' => $target,
                'duplicate_identity_count' => $duplicates,
                'strata_coverage' => $coverage,
                'unavailable_strata' => $unavailableStrata,
                'samples' => $selected,
            ];
        }

        $selectionSignature = $this->selectionSignature($operations, $key, $salt);
        $protected = [
            'schema_version' => MikroParityContract::SCHEMA_VERSION,
            'manifest_kind' => 'PROTECTED_EXECUTION',
            'manifest_id' => $retention['manifest_id'],
            'normalization_version' => MikroParityContract::NORMALIZATION_VERSION,
            'operation_contract_version' => MikroParityContract::OPERATION_CONTRACT_VERSION,
            'sample_policy_version' => MikroParityContract::SAMPLE_POLICY_VERSION,
            'contract_fingerprint' => $this->contract->fingerprint(),
            'source_context' => $context,
            'source_context_sha256' => hash('sha256', $this->contract->canonicalJson($context)),
            'selection_signature' => $selectionSignature,
            'selection_algorithm' => $policy['selection_algorithm'],
            'retention' => $retention,
            'hmac' => [
                'algorithm' => 'sha256',
                'key_base64' => $keyBase64,
                'salt_base64' => $saltBase64,
            ],
            'operations' => $operations,
        ];
        $protected['protected_manifest_sha256'] = hash('sha256', $this->contract->canonicalJson($protected));

        $public = $this->buildPublicManifest($protected, $key, $salt);

        return ['public_manifest' => $public, 'protected_manifest' => $protected];
    }

    /**
     * @param  array<string, mixed>  $publicManifest
     * @param  array<string, mixed>  $protectedManifest
     * @param  array<string, mixed>  $expectedSourceContext
     */
    public function assertReusable(array $publicManifest, array $protectedManifest, array $expectedSourceContext): void
    {
        $this->assertCurrentContract($publicManifest);
        $this->assertCurrentContract($protectedManifest);
        $expectedContext = $this->normalizeSourceContext($expectedSourceContext);
        if (($protectedManifest['source_context'] ?? null) !== $expectedContext) {
            throw new DomainException('MIKRO_PARITY_SAMPLE_CONTEXT_MISMATCH');
        }
        $contextHash = hash('sha256', $this->contract->canonicalJson($expectedContext));
        if (! hash_equals($contextHash, (string) ($protectedManifest['source_context_sha256'] ?? ''))
            || ! hash_equals($contextHash, (string) ($publicManifest['source_context_sha256'] ?? ''))) {
            throw new DomainException('MIKRO_PARITY_SAMPLE_CONTEXT_MISMATCH');
        }

        $providedProtectedHash = (string) ($protectedManifest['protected_manifest_sha256'] ?? '');
        $unsignedProtected = $protectedManifest;
        unset($unsignedProtected['protected_manifest_sha256']);
        $actualProtectedHash = hash('sha256', $this->contract->canonicalJson($unsignedProtected));
        if ($providedProtectedHash === ''
            || ! hash_equals($providedProtectedHash, $actualProtectedHash)
            || ! hash_equals($providedProtectedHash, (string) ($publicManifest['protected_manifest_sha256'] ?? ''))) {
            throw new DomainException('MIKRO_PARITY_PROTECTED_SAMPLE_MANIFEST_INVALID');
        }

        [$key, $salt] = $this->decodeKeyMaterial(
            (string) data_get($protectedManifest, 'hmac.key_base64', ''),
            (string) data_get($protectedManifest, 'hmac.salt_base64', ''),
        );
        $providedPublicHash = (string) ($publicManifest['public_manifest_sha256'] ?? '');
        $providedPublicHmac = (string) ($publicManifest['public_manifest_hmac'] ?? '');
        $unsignedPublic = $publicManifest;
        unset($unsignedPublic['public_manifest_sha256'], $unsignedPublic['public_manifest_hmac']);
        $actualPublicHash = hash('sha256', $this->contract->canonicalJson($unsignedPublic));
        if ($providedPublicHash === ''
            || ! hash_equals($providedPublicHash, $actualPublicHash)
            || ! hash_equals($this->hmac($actualPublicHash, $key, $salt), $providedPublicHmac)) {
            throw new DomainException('MIKRO_PARITY_PUBLIC_SAMPLE_MANIFEST_INVALID');
        }

        $operations = $protectedManifest['operations'] ?? null;
        if (! is_array($operations)) {
            throw new DomainException('MIKRO_PARITY_PROTECTED_SAMPLE_MANIFEST_INVALID');
        }
        $selectionSignature = $this->selectionSignature($operations, $key, $salt);
        if (! hash_equals($selectionSignature, (string) ($protectedManifest['selection_signature'] ?? ''))
            || ! hash_equals($selectionSignature, (string) ($publicManifest['selection_signature'] ?? ''))) {
            throw new DomainException('MIKRO_PARITY_SAMPLE_SELECTION_INVALID');
        }

        $this->assertRetention($protectedManifest['retention'] ?? null);
        $this->assertOperationBinding($publicManifest, $protectedManifest, $key, $salt, $expectedContext);
        $this->assertPublicManifestContainsNoRawIdentity($publicManifest);
    }

    /** @param array<int, array<string, mixed>> $samples */
    private function uniqueSamples(string $operationKey, array $samples, array $context): array
    {
        $unique = [];
        $duplicates = 0;
        foreach ($samples as $sample) {
            if (! is_array($sample)
                || trim((string) ($sample['identity'] ?? '')) === ''
                || ! is_array($sample['lookup'] ?? null)
                || ! is_array($sample['strata'] ?? null)
                || ! is_array($sample['strata_dimensions'] ?? null)) {
                throw new DomainException('MIKRO_PARITY_SAMPLE_DISCOVERY_INVALID');
            }
            $identity = (string) $sample['identity'];
            if (array_key_exists($identity, $unique)) {
                $duplicates++;

                continue;
            }
            $sample['synthetic'] = false;
            $sample['strata'] = array_values(array_unique(array_map('strval', $sample['strata'])));
            sort($sample['strata'], SORT_STRING);
            ksort($sample['strata_dimensions'], SORT_STRING);
            if ($operationKey === 'stock.availability') {
                $sample['lookup']['as_of_date'] = $context['as_of_date'];
            }
            $unique[$identity] = $sample;
        }

        return [$unique, $duplicates];
    }

    /**
     * @param  array<int, array<string, mixed>>  $ordered
     * @param  array<int, array<string, mixed>>  $policy
     * @return array{0:array<int,array<string,mixed>>,1:array<string,array<string,mixed>>,2:array<int,string>}
     */
    private function selectRequiredStrata(string $operationKey, array $ordered, array $policy, int $target): array
    {
        $selected = [];
        $coverage = [];
        $unavailable = [];
        foreach ($policy as $rule) {
            $key = (string) $rule['key'];
            if (($rule['classifier'] ?? null) === 'FIELD_UNAVAILABLE') {
                $coverage[$key] = ['status' => 'STRATUM_UNAVAILABLE', 'represented_count' => 0];
                if (($rule['required'] ?? false) === true) {
                    $unavailable[] = $key;
                }

                continue;
            }
            if (($rule['mode'] ?? null) === 'synthetic') {
                $coverage[$key] = ['status' => 'COVERED_SYNTHETIC', 'represented_count' => 1];

                continue;
            }

            $matches = [];
            if (($rule['mode'] ?? null) === 'label') {
                foreach ($ordered as $sample) {
                    if (in_array($rule['label'], $sample['strata'], true)) {
                        $matches[] = $sample;
                    }
                }
                if ($matches !== []) {
                    $selected[$matches[0]['identity']] = $matches[0];
                    $coverage[$key] = ['status' => 'COVERED', 'represented_count' => 1];
                } elseif (($rule['when_present'] ?? false) === true) {
                    $coverage[$key] = ['status' => 'NOT_PRESENT_OPTIONAL', 'represented_count' => 0];
                } else {
                    $coverage[$key] = ['status' => 'STRATUM_UNAVAILABLE', 'represented_count' => 0];
                    if (($rule['required'] ?? false) === true) {
                        $unavailable[] = $key;
                    }
                }

                continue;
            }

            $dimension = (string) ($rule['dimension'] ?? '');
            $byValue = [];
            foreach ($ordered as $sample) {
                $value = $sample['strata_dimensions'][$dimension] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '' && ! isset($byValue[(string) $value])) {
                    $byValue[(string) $value] = $sample;
                }
            }
            uksort($byValue, fn (string $left, string $right): int => hash('sha256', $key.'|'.$left) <=> hash('sha256', $key.'|'.$right));
            $minimum = (int) ($rule['minimum_distinct'] ?? 1);
            foreach (array_slice($byValue, 0, $minimum, true) as $sample) {
                $selected[$sample['identity']] = $sample;
            }
            if (count($byValue) >= $minimum) {
                $coverage[$key] = ['status' => 'COVERED', 'represented_count' => $minimum];
            } else {
                $coverage[$key] = ['status' => 'STRATUM_UNAVAILABLE', 'represented_count' => count($byValue)];
                if (($rule['required'] ?? false) === true) {
                    $unavailable[] = $key;
                }
            }
        }

        foreach ($ordered as $sample) {
            if (count($selected) >= $target) {
                break;
            }
            $selected[$sample['identity']] = $sample;
        }

        return [array_values($selected), $coverage, array_values(array_unique($unavailable))];
    }

    /** @return array<string, mixed> */
    private function negativeSerialCanary(string $key, string $salt): array
    {
        $suffix = strtoupper(substr($this->hmac('negative-serial-canary', $key, $salt), 0, 16));

        return [
            'identity' => 'synthetic-negative-serial|'.$suffix,
            'lookup' => ['serial_number' => 'PARITY-NOT-FOUND-'.$suffix, 'item_code' => 'PARITY-NEGATIVE'],
            'strata' => ['negative_not_found'],
            'strata_dimensions' => [],
            'synthetic' => true,
            'synthetic_classification' => 'NOT_BUSINESS_DATA',
        ];
    }

    /** @return array<string, mixed> */
    private function buildPublicManifest(array $protected, string $key, string $salt): array
    {
        $operations = [];
        foreach ($protected['operations'] as $operationKey => $operation) {
            $publicSamples = [];
            foreach ($operation['samples'] as $sample) {
                $publicStrata = $sample['strata'];
                foreach ($sample['strata_dimensions'] as $dimension => $value) {
                    $publicStrata[] = $dimension.'_bucket_'.substr($this->hmac((string) $value, $key, $salt), 0, 16);
                }
                sort($publicStrata, SORT_STRING);
                $publicSamples[] = [
                    'sample_id' => $this->sampleId($operationKey, $sample, $key, $salt),
                    'operation' => $operationKey,
                    'strata' => $publicStrata,
                    'synthetic' => (bool) $sample['synthetic'],
                ];
            }
            $operations[$operationKey] = [
                'status' => $operation['status'],
                'available_count' => $operation['available_count'],
                'business_selected_count' => $operation['business_selected_count'],
                'synthetic_selected_count' => $operation['synthetic_selected_count'],
                'selected_count' => $operation['selected_count'],
                'target_count' => $operation['target_count'],
                'duplicate_identity_count' => $operation['duplicate_identity_count'],
                'strata_coverage' => $operation['strata_coverage'],
                'unavailable_strata' => $operation['unavailable_strata'],
                'samples' => $publicSamples,
            ];
        }

        $public = [
            'schema_version' => MikroParityContract::SCHEMA_VERSION,
            'manifest_kind' => 'PUBLIC_EVIDENCE',
            'manifest_id' => $protected['manifest_id'],
            'normalization_version' => MikroParityContract::NORMALIZATION_VERSION,
            'operation_contract_version' => MikroParityContract::OPERATION_CONTRACT_VERSION,
            'sample_policy_version' => MikroParityContract::SAMPLE_POLICY_VERSION,
            'contract_fingerprint' => $this->contract->fingerprint(),
            'source_context_sha256' => $protected['source_context_sha256'],
            'protected_manifest_sha256' => $protected['protected_manifest_sha256'],
            'selection_signature' => $protected['selection_signature'],
            'operations' => $operations,
        ];
        $publicHash = hash('sha256', $this->contract->canonicalJson($public));
        $public['public_manifest_sha256'] = $publicHash;
        $public['public_manifest_hmac'] = $this->hmac($publicHash, $key, $salt);

        return $public;
    }

    /** @param array<string, mixed> $manifest */
    private function assertCurrentContract(array $manifest): void
    {
        if (($manifest['schema_version'] ?? null) !== MikroParityContract::SCHEMA_VERSION
            || ($manifest['normalization_version'] ?? null) !== MikroParityContract::NORMALIZATION_VERSION
            || ($manifest['operation_contract_version'] ?? null) !== MikroParityContract::OPERATION_CONTRACT_VERSION
            || ($manifest['sample_policy_version'] ?? null) !== MikroParityContract::SAMPLE_POLICY_VERSION
            || ($manifest['contract_fingerprint'] ?? null) !== $this->contract->fingerprint()) {
            throw new DomainException('MIKRO_PARITY_SAMPLE_CONTRACT_MISMATCH');
        }
    }

    private function assertOperationBinding(array $public, array $protected, string $key, string $salt, array $context): void
    {
        $targets = $this->contract->samplePolicy()['targets'];
        foreach ($targets as $operationKey => $target) {
            $privateOperation = $protected['operations'][$operationKey] ?? null;
            $publicOperation = $public['operations'][$operationKey] ?? null;
            if (! is_array($privateOperation) || ! is_array($publicOperation)
                || (int) ($privateOperation['target_count'] ?? 0) !== $target
                || (int) ($publicOperation['target_count'] ?? 0) !== $target
                || (int) ($privateOperation['selected_count'] ?? -1) !== (int) ($publicOperation['selected_count'] ?? -2)
                || count($privateOperation['samples'] ?? []) !== count($publicOperation['samples'] ?? [])) {
                throw new DomainException('MIKRO_PARITY_SAMPLE_OPERATION_BINDING_INVALID');
            }
            foreach ($privateOperation['samples'] as $index => $sample) {
                $publicSample = $publicOperation['samples'][$index] ?? null;
                if (! is_array($sample) || ! is_array($publicSample)
                    || ! hash_equals($this->sampleId($operationKey, $sample, $key, $salt), (string) ($publicSample['sample_id'] ?? ''))) {
                    throw new DomainException('MIKRO_PARITY_SAMPLE_OPERATION_BINDING_INVALID');
                }
                if ($operationKey === 'stock.availability' && ($sample['synthetic'] ?? false) !== true
                    && ($sample['lookup']['as_of_date'] ?? null) !== $context['as_of_date']) {
                    throw new DomainException('MIKRO_PARITY_STOCK_AS_OF_DATE_MISSING');
                }
            }
        }
    }

    private function assertRetention(mixed $retention): void
    {
        if (! is_array($retention)) {
            throw new DomainException('MIKRO_PARITY_SAMPLE_RETENTION_INVALID');
        }
        $expires = $this->contract->canonicalTimestamp($retention['expires_at_utc'] ?? null, 'UTC');
        if (new DateTimeImmutable($expires) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new DomainException('MIKRO_PARITY_SAMPLE_MANIFEST_EXPIRED');
        }
    }

    /** @return array<string, mixed> */
    private function normalizeSourceContext(array $context): array
    {
        $company = trim((string) ($context['company_code'] ?? ''));
        $year = filter_var($context['working_year'] ?? null, FILTER_VALIDATE_INT);
        $branch = filter_var($context['branch_code'] ?? null, FILTER_VALIDATE_INT);
        $warehouses = $context['warehouse_codes'] ?? null;
        $source = $context['source_context'] ?? null;
        if ($company === '' || $year === false || $branch === false || ! is_array($warehouses) || ! is_array($source) || $source === []) {
            throw new DomainException('MIKRO_PARITY_SAMPLE_CONTEXT_INVALID');
        }
        $warehouses = array_values(array_unique(array_map('intval', $warehouses)));
        sort($warehouses, SORT_NUMERIC);
        if ($warehouses !== [1, 5]) {
            throw new DomainException('MIKRO_PARITY_SAMPLE_CONTEXT_INVALID');
        }
        $asOfDate = $this->contract->canonicalDate($context['as_of_date'] ?? null, 'UTC');
        $dateFrom = $this->contract->canonicalDate(data_get($context, 'date_range.from'), 'UTC');
        $dateTo = $this->contract->canonicalDate(data_get($context, 'date_range.to'), 'UTC');
        if ($dateFrom > $dateTo) {
            throw new DomainException('MIKRO_PARITY_SAMPLE_CONTEXT_INVALID');
        }
        $this->assertNoSecretKeys($source);

        return [
            'company_code' => $company,
            'working_year' => (int) $year,
            'branch_code' => (int) $branch,
            'warehouse_codes' => $warehouses,
            'as_of_date' => $asOfDate,
            'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
            'source_context' => $this->canonicalArray($source),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeRetention(array $retention): array
    {
        $manifestId = trim((string) ($retention['manifest_id'] ?? ''));
        $purpose = trim((string) ($retention['purpose'] ?? ''));
        $days = filter_var($retention['retention_days'] ?? null, FILTER_VALIDATE_INT);
        $generated = $this->contract->canonicalTimestamp($retention['generated_at_utc'] ?? null, 'UTC');
        $expires = $this->contract->canonicalTimestamp($retention['expires_at_utc'] ?? null, 'UTC');
        if ($manifestId === '' || $purpose !== 'RUN1_RUN2_REUSE' || $days === false || $days < 1 || $generated >= $expires) {
            throw new DomainException('MIKRO_PARITY_SAMPLE_RETENTION_INVALID');
        }

        return [
            'manifest_id' => $manifestId,
            'purpose' => $purpose,
            'generated_at_utc' => $generated,
            'expires_at_utc' => $expires,
            'retention_days' => (int) $days,
        ];
    }

    private function assertNoSecretKeys(array $value): void
    {
        foreach ($value as $key => $item) {
            if (preg_match('/password|api.?key|secret|token|authorization/i', (string) $key)) {
                throw new DomainException('MIKRO_PARITY_SAMPLE_CONTEXT_INVALID');
            }
            if (is_array($item)) {
                $this->assertNoSecretKeys($item);
            }
        }
    }

    private function assertPublicManifestContainsNoRawIdentity(array $manifest): void
    {
        $forbidden = ['identity', 'lookup', 'customer_code', 'item_code', 'serial_number', 'order_anchor_line_guid', 'document_identity', 'record_id'];
        $walk = function (array $value) use (&$walk, $forbidden): void {
            foreach ($value as $key => $item) {
                if (in_array((string) $key, $forbidden, true)) {
                    throw new DomainException('MIKRO_PARITY_PUBLIC_SAMPLE_IDENTIFIER_EXPOSED');
                }
                if (is_array($item)) {
                    $walk($item);
                }
            }
        };
        $walk($manifest);
    }

    /** @return array{0:string,1:string} */
    private function decodeKeyMaterial(string $keyBase64, string $saltBase64): array
    {
        $key = base64_decode($keyBase64, true);
        $salt = base64_decode($saltBase64, true);
        if (! is_string($key) || strlen($key) < 32 || ! is_string($salt) || strlen($salt) < 16) {
            throw new DomainException('MIKRO_PARITY_SAMPLE_HMAC_INVALID');
        }

        return [$key, $salt];
    }

    private function selectionKey(string $operationKey, array $sample): array
    {
        return [
            hash('sha256', MikroParityContract::SAMPLE_POLICY_VERSION.'|'.$operationKey.'|'.$sample['identity']),
            $sample['identity'],
        ];
    }

    private function selectionSignature(array $operations, string $key, string $salt): string
    {
        $selection = [];
        foreach ($operations as $operationKey => $operation) {
            $selection[$operationKey] = array_map(fn (array $sample): array => [
                'identity' => $sample['identity'],
                'lookup' => $sample['lookup'],
                'strata' => $sample['strata'],
                'strata_dimensions' => $sample['strata_dimensions'],
                'synthetic' => $sample['synthetic'],
            ], $operation['samples'] ?? []);
        }

        return $this->hmac($this->contract->canonicalJson($selection), $key, $salt);
    }

    private function sampleId(string $operationKey, array $sample, string $key, string $salt): string
    {
        return 'sample_'.substr($this->hmac($this->contract->canonicalJson([
            'operation' => $operationKey,
            'identity' => $sample['identity'],
            'lookup' => $sample['lookup'],
            'synthetic' => $sample['synthetic'],
        ]), $key, $salt), 0, 32);
    }

    private function hmac(string $value, string $key, string $salt): string
    {
        return hash_hmac('sha256', $salt.'|'.$value, $key);
    }

    private function canonicalArray(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => is_array($item) ? $this->canonicalArray($item) : $item, $value);
        }
        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => is_array($item) ? $this->canonicalArray($item) : $item, $value);
    }
}
