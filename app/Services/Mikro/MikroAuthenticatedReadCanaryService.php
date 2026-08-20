<?php

namespace App\Services\Mikro;

use App\Models\TechnicalServiceRequestSerial;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use Illuminate\Support\Collection;

final class MikroAuthenticatedReadCanaryService
{
    private const MAX_SERIAL_DISCOVERY_ATTEMPTS = 12;

    public function __construct(
        private readonly MikroApiClient $client,
        private readonly MikroOperationRegistry $registry,
        private readonly TechnicalServiceMessagingSettingsService $settings,
    ) {}

    /** @return array{allowed:bool,operations:array<string, array<string, mixed>>,blocker_codes:array<int, string>} */
    public function eligibility(): array
    {
        return $this->registry->canaryEligibility($this->settings->mikroApiConnectionContext());
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $context = $this->settings->mikroApiConnectionContext();
        $eligibility = $this->registry->canaryEligibility($context);
        if (! $eligibility['allowed']) {
            return $this->blockedResult($eligibility['blocker_codes'], 0);
        }

        $serialResult = null;
        $selectedRow = null;
        $serialContext = null;
        $networkReadCount = 0;
        $discoveryAttempts = 0;

        foreach ($this->serialCandidates() as $candidate) {
            $discoveryAttempts++;
            $current = $this->client->authenticatedReadCanary('serial.lookup', [
                'serial_number' => (string) $candidate->serial_number,
            ]);
            $networkReadCount += (int) ($current['attempt_count'] ?? 0);
            $record = $current['data'][0] ?? null;
            if (! ($current['success'] ?? false) || ! is_array($record)) {
                $serialResult = $current;

                continue;
            }

            $customerCode = trim((string) ($record['customer_code'] ?? ''));
            $stockCode = trim((string) ($record['stock_code'] ?? ''));
            $orderGuid = $this->canonicalGuid((string) ($record['order_guid'] ?? ''));
            if ($customerCode === '' || $stockCode === '' || $orderGuid === null) {
                $serialResult = $current;

                continue;
            }

            $serialResult = $current;
            $selectedRow = $candidate;
            $serialContext = [
                'customer_code' => $customerCode,
                'stock_code' => $stockCode,
                'order_guid' => $orderGuid,
            ];
            break;
        }

        if (! is_array($serialContext) || $selectedRow === null || ! is_array($serialResult)) {
            $operations = [];
            if (is_array($serialResult)) {
                $operations['serial.lookup'] = $this->sanitizeOperationResult(
                    'serial.lookup',
                    $serialResult,
                    ['type' => 'LOCAL_N8N_SERIAL_HISTORY', 'status' => 'NO_ORDER_GUID_CONTEXT'],
                );
            }

            return $this->blockedResult(
                ['MIKRO_CANARY_SAMPLE_WITH_ORDER_GUID_NOT_FOUND'],
                $networkReadCount,
                $operations,
                $discoveryAttempts,
            );
        }

        $serialSource = [
            'type' => 'LOCAL_N8N_SERIAL_HISTORY',
            'record_fingerprint' => hash('sha256', 'technical_service_request_serials:'.$selectedRow->getKey()),
            'value_fingerprint' => hash('sha256', (string) $selectedRow->serial_number),
        ];
        $serialSanitized = $this->sanitizeOperationResult('serial.lookup', $serialResult, $serialSource);

        $customerResult = $this->client->authenticatedReadCanary('customer.lookup', [
            'customer_code' => $serialContext['customer_code'],
        ]);
        $networkReadCount += (int) ($customerResult['attempt_count'] ?? 0);

        $stockResult = $this->client->authenticatedReadCanary('stock.availability', [
            'stock_code' => $serialContext['stock_code'],
        ]);
        $networkReadCount += (int) ($stockResult['attempt_count'] ?? 0);

        $orderResult = $this->client->authenticatedReadCanary('order.detail', [
            'order_guid' => $serialContext['order_guid'],
        ]);
        $networkReadCount += (int) ($orderResult['attempt_count'] ?? 0);

        $derivedSource = static fn (string $field): array => [
            'type' => 'MIKRO_SERIAL_LOOKUP_NORMALIZED',
            'field' => $field,
            'value_fingerprint' => hash('sha256', (string) $serialContext[$field]),
        ];
        $operations = [
            'customer.lookup' => $this->sanitizeOperationResult('customer.lookup', $customerResult, $derivedSource('customer_code')),
            'stock.availability' => $this->sanitizeOperationResult('stock.availability', $stockResult, [
                ...$derivedSource('stock_code'),
                'warehouse_context' => [1, 5],
            ]),
            'serial.lookup' => $serialSanitized,
            'order.detail' => $this->sanitizeOperationResult('order.detail', $orderResult, $derivedSource('order_guid')),
        ];
        $failed = array_values(array_filter(
            $operations,
            static fn (array $operation): bool => ! $operation['success'] || $operation['result_count'] < 1,
        ));

        return [
            'success' => $failed === [],
            'blocker_codes' => array_values(array_unique(array_map(
                static fn (array $operation): string => $operation['error_code'] ?? 'MIKRO_CANARY_SAMPLE_NOT_FOUND',
                $failed,
            ))),
            'operations' => $operations,
            'serial_discovery_attempt_count' => $discoveryAttempts,
            'mikro_read_count' => $networkReadCount,
            'mikro_write_count' => 0,
            'business_db_write_count' => 0,
            'n8n_mutation_count' => 0,
            'provider_effect_count' => 0,
            'source_mode_delta' => 0,
            'runtime_enabled_delta' => 0,
            'global_switches' => $this->safeSwitches($context),
        ];
    }

    /** @return Collection<int, TechnicalServiceRequestSerial> */
    private function serialCandidates(): Collection
    {
        $base = TechnicalServiceRequestSerial::query()
            ->whereNotNull('serial_number')
            ->where('serial_number', '<>', '')
            ->whereNotNull('stock_code')
            ->where('stock_code', '<>', '')
            ->where(static fn ($query) => $query->whereNull('is_returned')->orWhere('is_returned', false));

        $preferred = (clone $base)
            ->where('is_current_latest_sale', true)
            ->orderByDesc('is_primary')
            ->orderByDesc('updated_at')
            ->limit(self::MAX_SERIAL_DISCOVERY_ATTEMPTS)
            ->get(['id', 'serial_number', 'stock_code', 'updated_at']);

        if ($preferred->count() >= self::MAX_SERIAL_DISCOVERY_ATTEMPTS) {
            return $preferred;
        }

        return $preferred
            ->concat((clone $base)->orderByDesc('updated_at')->limit(self::MAX_SERIAL_DISCOVERY_ATTEMPTS)->get(['id', 'serial_number', 'stock_code', 'updated_at']))
            ->unique('id')
            ->take(self::MAX_SERIAL_DISCOVERY_ATTEMPTS)
            ->values();
    }

    /** @return array<string, mixed> */
    private function sanitizeOperationResult(string $requestedOperationKey, array $result, array $sampleSource): array
    {
        $canonicalOperationKey = (string) ($result['canonical_operation_key'] ?? $result['operation_key'] ?? '');
        $operation = $this->registry->operation($canonicalOperationKey);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $fields = [];
        foreach ($data as $record) {
            if (is_array($record)) {
                $fields = [...$fields, ...array_keys($record)];
            }
        }
        $fields = array_values(array_unique($fields));
        sort($fields);

        return [
            'requested_operation_key' => $requestedOperationKey,
            'canonical_operation_key' => $canonicalOperationKey,
            'adapter_type' => $operation['adapter_type'],
            'fixed_query_id' => $operation['fixed_query_id'],
            'http_status' => $result['status'] ?? null,
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            'result_count' => (int) ($result['result_count'] ?? 0),
            'success' => (bool) ($result['success'] ?? false),
            'error_code' => $result['error_code'] ?? null,
            'correlation_id' => $result['correlation_id'] ?? null,
            'transport_retry_count' => max(0, (int) ($result['attempt_count'] ?? 0) - 1),
            'schema_validation' => ($result['success'] ?? false) ? 'PASS' : 'FAIL',
            'normalized_result' => [
                'fields' => $fields,
                'fingerprint' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
            ],
            'sample_source' => $sampleSource,
            'runtime_state_mutated' => (bool) ($result['runtime_state_mutated'] ?? true),
            'source_mode_mutated' => (bool) ($result['source_mode_mutated'] ?? true),
        ];
    }

    /** @return array<string, mixed> */
    private function blockedResult(array $blockerCodes, int $networkReadCount, array $operations = [], int $discoveryAttempts = 0): array
    {
        $context = $this->settings->mikroApiConnectionContext();

        return [
            'success' => false,
            'blocker_codes' => array_values(array_unique($blockerCodes)),
            'operations' => $operations,
            'serial_discovery_attempt_count' => $discoveryAttempts,
            'mikro_read_count' => $networkReadCount,
            'mikro_write_count' => 0,
            'business_db_write_count' => 0,
            'n8n_mutation_count' => 0,
            'provider_effect_count' => 0,
            'source_mode_delta' => 0,
            'runtime_enabled_delta' => 0,
            'global_switches' => $this->safeSwitches($context),
        ];
    }

    /** @return array{enabled:bool,read_sync_enabled:bool,write_enabled:bool} */
    private function safeSwitches(array $context): array
    {
        return [
            'enabled' => (bool) ($context['enabled'] ?? false),
            'read_sync_enabled' => (bool) ($context['read_sync_enabled'] ?? false),
            'write_enabled' => (bool) ($context['write_enabled'] ?? false),
        ];
    }

    private function isGuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function canonicalGuid(string $value): ?string
    {
        $value = trim(trim($value), '{}');

        return $this->isGuid($value) ? strtolower($value) : null;
    }
}
