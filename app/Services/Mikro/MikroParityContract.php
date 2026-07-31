<?php

namespace App\Services\Mikro;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use JsonException;
use Throwable;

class MikroParityContract
{
    public const NORMALIZATION_VERSION = 'mikro-shadow-parity-normalization.v2';

    public const OPERATION_CONTRACT_VERSION = 'mikro-shadow-parity-operations.v2';

    public const SAMPLE_POLICY_VERSION = 'mikro-shadow-parity-samples.v2';

    public const SCHEMA_VERSION = 2;

    private const SOURCE_TIMEZONE = 'Europe/Istanbul';

    public function __construct(private readonly MikroFixedQueryCatalog $queries) {}

    /** @return array<int, string> */
    public function operationKeys(): array
    {
        return array_keys($this->operationDefinitions());
    }

    /** @return array<string, mixed> */
    public function operation(string $operationKey): array
    {
        $definitions = $this->operationDefinitions();
        $this->assertSchemasCompile($definitions);
        $definition = $definitions[$operationKey] ?? null;
        if (! is_array($definition)) {
            throw new DomainException('MIKRO_PARITY_OPERATION_NOT_ALLOWED');
        }

        return $definition;
    }

    /** @return array<string, mixed> */
    public function source(MikroParitySource $source): array
    {
        $query = $this->queries->definition($source->queryId());
        $n8nTemplate = $this->queries->n8nTemplate($source->queryId());
        if (($query['parity_only'] ?? false) !== true) {
            throw new DomainException('MIKRO_PARITY_SOURCE_NOT_ISOLATED');
        }

        return [
            'code' => $source->value,
            'operation_key' => $source->operationKey(),
            'phase' => $source->phase(),
            'query_id' => $source->queryId(),
            'query_template' => $n8nTemplate,
            'query_template_sha256' => hash('sha256', $n8nTemplate),
            'mikro_query_template_sha256' => hash('sha256', (string) $query['sql']),
            'allowed_params' => array_keys($query['parameters']),
            'parameter_types' => $query['parameters'],
            'tables' => $query['tables'],
            'table_evidence' => $query['table_evidence'],
            'schema_version' => self::SCHEMA_VERSION,
            'response_schema' => $this->sourceResponseSchema($source),
            'source_timezones' => [
                'mikro' => self::SOURCE_TIMEZONE,
                'n8n' => self::SOURCE_TIMEZONE,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function validatedParameters(MikroParitySource $source, array $parameters): array
    {
        $this->queries->render($source->queryId(), $parameters);

        return $parameters;
    }

    /** @return array<string, mixed> */
    public function contract(): array
    {
        $operations = $this->operationDefinitions();
        $this->assertSchemasCompile($operations);
        $sources = [];
        foreach (MikroParitySource::cases() as $source) {
            $sources[$source->value] = $this->source($source);
        }

        return [
            'normalization_version' => self::NORMALIZATION_VERSION,
            'operation_contract_version' => self::OPERATION_CONTRACT_VERSION,
            'sample_policy_version' => self::SAMPLE_POLICY_VERSION,
            'schema_version' => self::SCHEMA_VERSION,
            'normalizer_rules' => $this->normalizerRules(),
            'source_timezone_rules' => $this->sourceTimezoneRules(),
            'sample_policy' => $this->samplePolicy(),
            'operations' => $operations,
            'sources' => $sources,
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->canonicalJson($this->contract()));
    }

    /** @param array<string, mixed> $value */
    public function canonicalJson(array $value): string
    {
        try {
            return json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new DomainException('MIKRO_PARITY_CONTRACT_JSON_INVALID', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    public function operationReadiness(string $operationKey): array
    {
        $definition = $this->operation($operationKey);
        $unavailable = collect($definition['unavailable_fields'])
            ->filter(fn (array $field): bool => ($field['classification'] ?? null) === 'PROMOTION_CRITICAL')
            ->all();
        $semanticBlockers = collect($definition['semantic_blockers'] ?? [])
            ->filter(fn (array $field): bool => ($field['classification'] ?? null) === 'PROMOTION_CRITICAL')
            ->all();

        return [
            'operation_key' => $operationKey,
            'source_contract' => 'TYPED_SCHEMA_READY',
            'schema_compiled' => true,
            'parity_readiness' => $unavailable === [] && $semanticBlockers === []
                ? 'READY'
                : 'CONTRACT_FIELD_UNAVAILABLE',
            'unavailable_promotion_fields' => $unavailable,
            'promotion_semantic_blockers' => $semanticBlockers,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function normalizeMikro(MikroParitySource $source, array $rows): array
    {
        return $this->normalize($source, 'mikro', $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function normalizeN8n(MikroParitySource $source, array $rows): array
    {
        return $this->normalize($source, 'n8n', $rows);
    }

    public function canonicalDate(mixed $value, ?string $sourceTimezone, bool $nullable = false): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($nullable) {
                return null;
            }

            throw new DomainException('MIKRO_PARITY_DATE_INVALID');
        }
        if (! is_string($value) && ! is_int($value)) {
            throw new DomainException('MIKRO_PARITY_DATE_INVALID');
        }

        $text = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date && $date->format('Y-m-d') === $text) {
            return $text;
        }

        $timezone = $this->timezone($sourceTimezone);
        try {
            if ($this->hasExplicitOffset($text)) {
                $instant = new DateTimeImmutable($text);

                return $instant->setTimezone($timezone)->format('Y-m-d');
            }

            return $this->parseNaiveTimestamp($text, $timezone)->setTimezone($timezone)->format('Y-m-d');
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException('MIKRO_PARITY_DATE_INVALID', previous: $exception);
        }
    }

    public function canonicalTimestamp(mixed $value, ?string $sourceTimezone, bool $nullable = false): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($nullable) {
                return null;
            }

            throw new DomainException('MIKRO_PARITY_TIMESTAMP_INVALID');
        }
        if (! is_string($value)) {
            throw new DomainException('MIKRO_PARITY_TIMESTAMP_INVALID');
        }

        $text = trim($value);
        try {
            $instant = $this->hasExplicitOffset($text)
                ? $this->parseOffsetTimestamp($text)
                : $this->parseNaiveTimestamp($text, $this->timezone($sourceTimezone));

            return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException('MIKRO_PARITY_TIMESTAMP_INVALID', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    public function samplePolicy(): array
    {
        return [
            'selection_algorithm' => 'required-strata-first, then sha256(sample-policy|operation|identity)',
            'targets' => [
                'customer.lookup' => 50,
                'stock.availability' => 100,
                'serial.lookup' => 50,
                'order.detail' => 50,
            ],
            'operations' => [
                'customer.lookup' => [
                    ['key' => 'active', 'mode' => 'label', 'label' => 'active', 'required' => true, 'classifier' => 'available'],
                    ['key' => 'inactive', 'mode' => 'label', 'label' => 'inactive', 'required' => true, 'classifier' => 'available'],
                    ['key' => 'dealer', 'mode' => 'label', 'label' => 'dealer', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'project', 'mode' => 'label', 'label' => 'project', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'online_retail', 'mode' => 'label', 'label' => 'online_retail', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'multiple_currencies', 'mode' => 'dimension', 'dimension' => 'currency', 'minimum_distinct' => 2, 'required' => true, 'classifier' => 'available'],
                    ['key' => 'with_balance', 'mode' => 'label', 'label' => 'with_balance', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'without_balance', 'mode' => 'label', 'label' => 'without_balance', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                ],
                'stock.availability' => [
                    ['key' => 'in_stock', 'mode' => 'label', 'label' => 'in_stock', 'required' => true, 'classifier' => 'available'],
                    ['key' => 'out_of_stock', 'mode' => 'label', 'label' => 'out_of_stock', 'required' => true, 'classifier' => 'available'],
                    ['key' => 'reserved', 'mode' => 'label', 'label' => 'reserved', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'serial_tracked', 'mode' => 'label', 'label' => 'serial_tracked', 'required' => true, 'classifier' => 'available'],
                    ['key' => 'non_serial', 'mode' => 'label', 'label' => 'non_serial', 'required' => true, 'classifier' => 'available'],
                    ['key' => 'warehouse_1', 'mode' => 'label', 'label' => 'warehouse_1', 'required' => true, 'classifier' => 'available'],
                    ['key' => 'warehouse_5', 'mode' => 'label', 'label' => 'warehouse_5', 'required' => true, 'classifier' => 'available'],
                    ['key' => 'negative_exception', 'mode' => 'label', 'label' => 'negative_exception', 'required' => false, 'when_present' => true, 'classifier' => 'available'],
                ],
                'serial.lookup' => [
                    ['key' => 'in_warehouse', 'mode' => 'label', 'label' => 'in_warehouse', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'sold', 'mode' => 'label', 'label' => 'sold', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'returned', 'mode' => 'label', 'label' => 'returned', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'exchanged', 'mode' => 'label', 'label' => 'exchanged', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'mounted', 'mode' => 'label', 'label' => 'mounted', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'negative_not_found', 'mode' => 'synthetic', 'required' => true, 'classifier' => 'synthetic'],
                ],
                'order.detail' => [
                    ['key' => 'open', 'mode' => 'label', 'label' => 'open', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'partially_dispatched', 'mode' => 'label', 'label' => 'partially_dispatched', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'complete', 'mode' => 'label', 'label' => 'complete', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'cancelled', 'mode' => 'label', 'label' => 'cancelled', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'return_linked', 'mode' => 'label', 'label' => 'return_linked', 'required' => true, 'classifier' => 'FIELD_UNAVAILABLE'],
                    ['key' => 'multiple_customer_contexts', 'mode' => 'dimension', 'dimension' => 'customer_context', 'minimum_distinct' => 2, 'required' => true, 'classifier' => 'available'],
                    ['key' => 'multiple_warehouse_contexts', 'mode' => 'dimension', 'dimension' => 'warehouse_context', 'minimum_distinct' => 2, 'required' => true, 'classifier' => 'available'],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function normalize(MikroParitySource $source, string $provider, array $rows): array
    {
        $normalizedRows = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return $this->normalizationResult($source, $provider, 'CONTRACT_ERROR', [], ['__row__:TYPE_OBJECT']);
            }
            $normalizedRows[] = $this->lowerKeys($row);
        }

        $schemaErrors = $this->validateSourceRows($source, $provider, $normalizedRows);
        if ($schemaErrors !== []) {
            return $this->normalizationResult($source, $provider, 'CONTRACT_ERROR', [], $schemaErrors);
        }

        if ($source->phase() === 'discovery') {
            return $this->normalizeDiscovery($source, $provider, $normalizedRows);
        }

        if ($normalizedRows === []) {
            return $this->normalizationResult($source, $provider, 'CONTRACT_ERROR', [], ['__row__']);
        }

        $timezone = $this->sourceTimezone($source, $provider);
        try {
            $envelope = match ($source) {
                MikroParitySource::CUSTOMER_DETAIL => $this->customerEnvelope($normalizedRows[0], $timezone),
                MikroParitySource::STOCK_DETAIL => $this->stockEnvelope($normalizedRows[0], $timezone),
                MikroParitySource::SERIAL_DETAIL => $this->serialEnvelope($normalizedRows[0], $timezone),
                MikroParitySource::ORDER_DETAIL => $this->orderEnvelope($normalizedRows, $timezone),
                default => throw new DomainException('MIKRO_PARITY_SOURCE_PHASE_INVALID'),
            };
            $this->validateEnvelope($source->operationKey(), $envelope);
        } catch (DomainException $exception) {
            return $this->normalizationResult($source, $provider, 'CONTRACT_ERROR', [], [$exception->getMessage()]);
        }

        $readiness = $this->operationReadiness($source->operationKey());

        return $this->normalizationResult(
            $source,
            $provider,
            $readiness['parity_readiness'],
            $envelope,
            [],
            $readiness['unavailable_promotion_fields'],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function normalizeDiscovery(MikroParitySource $source, string $provider, array $rows): array
    {
        $samples = array_map(function (array $row) use ($source): array {
            return match ($source) {
                MikroParitySource::CUSTOMER_DISCOVERY => $this->customerDiscoverySample($row),
                MikroParitySource::STOCK_DISCOVERY => $this->stockDiscoverySample($row),
                MikroParitySource::SERIAL_DISCOVERY => [
                    'identity' => $this->text($row['serial_number']).'|'.$this->text($row['item_code']),
                    'lookup' => [
                        'serial_number' => $this->text($row['serial_number']),
                        'item_code' => $this->text($row['item_code']),
                    ],
                    'strata' => [],
                    'strata_dimensions' => [],
                ],
                MikroParitySource::ORDER_DISCOVERY => [
                    'identity' => $this->text($row['document_identity']),
                    'lookup' => ['order_anchor_line_guid' => strtolower($this->text($row['anchor_line_guid']))],
                    'strata' => [],
                    'strata_dimensions' => [
                        'customer_context' => $this->text($row['customer_code']),
                        'warehouse_context' => (string) $this->integer($row['warehouse_code']),
                    ],
                ],
                default => throw new DomainException('MIKRO_PARITY_SOURCE_PHASE_INVALID'),
            };
        }, $rows);

        usort($samples, fn (array $left, array $right): int => strcmp($left['identity'], $right['identity']));

        return $this->normalizationResult($source, $provider, 'READY', ['samples' => $samples]);
    }

    /** @param array<string, mixed> $row */
    private function customerDiscoverySample(array $row): array
    {
        $active = $this->integer($row['active_abandon_code']) === 0
            && $this->integer($row['company_open_closed_flag']) === 0
            && $this->integer($row['locked_flag']) === 0;

        return [
            'identity' => $this->text($row['customer_code']),
            'lookup' => ['customer_code' => $this->text($row['customer_code'])],
            'strata' => [$active ? 'active' : 'inactive'],
            'strata_dimensions' => ['currency' => (string) $this->integer($row['currency_index'])],
        ];
    }

    /** @param array<string, mixed> $row */
    private function stockDiscoverySample(array $row): array
    {
        $quantity = (float) $this->decimal($row['on_hand_quantity']);
        $warehouse = $this->integer($row['warehouse_code']);
        $strata = [
            $quantity > 0 ? 'in_stock' : 'out_of_stock',
            $this->integer($row['serial_tracking_code']) === 3 ? 'serial_tracked' : 'non_serial',
            'warehouse_'.$warehouse,
        ];
        if ($quantity < 0) {
            $strata[] = 'negative_exception';
        }

        return [
            'identity' => $this->text($row['item_code']).'|'.$warehouse,
            'lookup' => [
                'item_code' => $this->text($row['item_code']),
                'warehouse_code' => $warehouse,
            ],
            'strata' => $strata,
            'strata_dimensions' => [],
        ];
    }

    /** @param array<string, mixed> $row */
    private function customerEnvelope(array $row, string $timezone): array
    {
        $active = $this->integer($row['active_abandon_code']) === 0
            && $this->integer($row['company_open_closed_flag']) === 0
            && $this->integer($row['locked_flag']) === 0;

        return [
            'record_id' => strtolower($this->text($row['record_id'])),
            'customer_code' => $this->text($row['customer_code']),
            'title_normalized' => $this->normalizedText($this->text($row['title_1']).' '.($this->nullableText($row['title_2']) ?? '')),
            'active_state' => $active ? 'ACTIVE' : 'INACTIVE_OR_RESTRICTED',
            'customer_group_code' => $this->nullableText($row['customer_group_code']),
            'source_status' => [
                'active_abandon_code' => $this->integer($row['active_abandon_code']),
                'company_open_closed_flag' => $this->integer($row['company_open_closed_flag']),
                'locked_flag' => $this->integer($row['locked_flag']),
            ],
            'source_updated_at' => $this->canonicalTimestamp($row['source_updated_at'], $timezone, true),
        ];
    }

    /** @param array<string, mixed> $row */
    private function stockEnvelope(array $row, string $timezone): array
    {
        $trackingCode = $this->integer($row['serial_tracking_code']);

        return [
            'record_id' => strtolower($this->text($row['record_id'])),
            'item_code' => $this->text($row['item_code']),
            'warehouse_code' => $this->integer($row['warehouse_code']),
            'unit_name' => $this->nullableText($row['unit_name']),
            'on_hand_quantity' => $this->decimal($row['on_hand_quantity']),
            'serial_tracking_state' => $trackingCode === 3 ? 'SERIAL_TRACKED' : 'NOT_SERIAL_TRACKED',
            'item_active_state' => $this->integer($row['item_active_flag']) === 1 ? 'ACTIVE' : 'PASSIVE',
            'source_tracking_code' => $trackingCode,
            'source_updated_at' => $this->canonicalTimestamp($row['source_updated_at'], $timezone, true),
        ];
    }

    /** @param array<string, mixed> $row */
    private function serialEnvelope(array $row, string $timezone): array
    {
        return [
            'record_id' => strtolower($this->text($row['record_id'])),
            'serial_number' => $this->text($row['serial_number']),
            'item_code' => $this->text($row['item_code']),
            'reserved_flag' => $this->nullableInteger($row['reserved_flag']),
            'movement_type' => $this->nullableInteger($row['movement_type']),
            'ingress_warehouse_code' => $this->nullableInteger($row['ingress_warehouse_code']),
            'egress_warehouse_code' => $this->nullableInteger($row['egress_warehouse_code']),
            'customer_code' => $this->nullableText($row['customer_code']),
            'order_line_guid' => $this->nullableText($row['order_line_guid']),
            'invoice_line_guid' => $this->nullableText($row['invoice_line_guid']),
            'movement_document_series' => $this->nullableText($row['movement_document_series']),
            'movement_document_number' => $this->nullableInteger($row['movement_document_number']),
            'movement_timestamp_normalized' => $this->canonicalTimestamp($row['movement_timestamp'], $timezone, true),
            'source_updated_at' => $this->canonicalTimestamp($row['source_updated_at'], $timezone, true),
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function orderEnvelope(array $rows, string $timezone): array
    {
        $first = $rows[0];
        $lines = array_map(fn (array $row): array => [
            'line_key' => strtolower($this->text($row['line_guid'])),
            'line_number' => $this->integer($row['line_number']),
            'item_code' => $this->text($row['item_code']),
            'ordered_quantity' => $this->decimal($row['ordered_quantity']),
            'delivered_quantity' => $this->decimal($row['delivered_quantity']),
            'open_quantity' => $this->decimal($row['open_quantity']),
            'unit_price' => $this->decimal($row['unit_price']),
            'line_net_amount' => $this->decimal($row['line_net_amount']),
            'line_tax_amount' => $this->decimal($row['line_tax_amount']),
        ], $rows);

        return [
            'document_identity' => $this->text($first['document_identity']),
            'order_number' => $this->text($first['document_series']).'-'.$this->integer($first['document_number']),
            'customer_code' => $this->text($first['customer_code']),
            'order_date_normalized' => $this->canonicalDate($first['order_date'], $timezone),
            'requested_delivery_date_normalized' => $this->canonicalDate($first['requested_delivery_date'], $timezone, true),
            'warehouse_code' => $this->integer($first['warehouse_code']),
            'source_state' => [
                'cancelled_flag' => $this->integer($first['cancelled_flag']),
                'closed_flag' => $this->integer($first['closed_flag']),
                'raw_order_state' => $this->integer($first['raw_order_state']),
            ],
            'line_count' => count($lines),
            'lines' => $lines,
            'source_updated_at' => $this->canonicalTimestamp($first['source_updated_at'], $timezone, true),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function validateSourceRows(MikroParitySource $source, string $provider, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $errors = [];
        $timezone = $this->sourceTimezone($source, $provider);
        foreach ($rows as $row) {
            foreach ($this->sourceResponseSchema($source) as $field) {
                $path = $field['path'];
                if (! array_key_exists($path, $row)) {
                    $errors[$path] = true;

                    continue;
                }
                $value = $row[$path];
                if ($value === null || $value === '') {
                    if (! $field['nullable']) {
                        $errors[$path.':NULL_NOT_ALLOWED'] = true;
                    }

                    continue;
                }
                if (! $this->valueMatchesType($value, $field['type'])) {
                    $errors[$path.':TYPE_'.strtoupper($field['type'])] = true;

                    continue;
                }
                try {
                    if ($field['normalizer'] === 'canonical_timestamp') {
                        $this->canonicalTimestamp($value, $timezone, $field['nullable']);
                    } elseif ($field['normalizer'] === 'canonical_date') {
                        $this->canonicalDate($value, $timezone, $field['nullable']);
                    }
                } catch (DomainException $exception) {
                    $errors[$path.':'.$exception->getMessage()] = true;
                }
            }
        }

        return array_keys($errors);
    }

    /** @param array<string, mixed> $envelope */
    private function validateEnvelope(string $operationKey, array $envelope): void
    {
        foreach ($this->operation($operationKey)['response_schema'] as $field) {
            if (($field['availability'] ?? 'AVAILABLE') === 'FIELD_UNAVAILABLE') {
                continue;
            }
            $path = $field['path'];
            if ($path === 'lines') {
                if (! isset($envelope['lines']) || ! is_array($envelope['lines'])) {
                    throw new DomainException('MIKRO_PARITY_ENVELOPE_SCHEMA_INVALID');
                }
                foreach ($envelope['lines'] as $line) {
                    if (! is_array($line)) {
                        throw new DomainException('MIKRO_PARITY_ENVELOPE_SCHEMA_INVALID');
                    }
                    foreach ($field['children'] as $child) {
                        if (($child['availability'] ?? 'AVAILABLE') === 'FIELD_UNAVAILABLE') {
                            continue;
                        }
                        if (! array_key_exists($child['path'], $line)
                            || ($line[$child['path']] === null && ! $child['nullable'])
                            || ($line[$child['path']] !== null && ! $this->valueMatchesType($line[$child['path']], $child['type']))) {
                            throw new DomainException('MIKRO_PARITY_ENVELOPE_SCHEMA_INVALID');
                        }
                    }
                }

                continue;
            }
            if (! array_key_exists($path, $envelope)
                || ($envelope[$path] === null && ! $field['nullable'])
                || ($envelope[$path] !== null && ! $this->valueMatchesType($envelope[$path], $field['type']))) {
                throw new DomainException('MIKRO_PARITY_ENVELOPE_SCHEMA_INVALID');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<int, string>  $missing
     * @param  array<string, mixed>  $unavailable
     * @return array<string, mixed>
     */
    private function normalizationResult(MikroParitySource $source, string $provider, string $status, array $envelope, array $missing = [], array $unavailable = []): array
    {
        return [
            'status' => $status,
            'operation_key' => $source->operationKey(),
            'source_code' => $source->value,
            'provider' => $provider,
            'normalization_version' => self::NORMALIZATION_VERSION,
            'operation_contract_version' => self::OPERATION_CONTRACT_VERSION,
            'sample_policy_version' => self::SAMPLE_POLICY_VERSION,
            'contract_fingerprint' => $this->fingerprint(),
            'missing_source_fields' => $missing,
            'unavailable_promotion_fields' => $unavailable,
            'envelope' => $envelope,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function operationDefinitions(): array
    {
        $schemas = $this->responseSchemas();

        return [
            'customer.lookup' => [
                'identity_key' => ['customer_code'],
                'sample_identity_contract' => ['customer_code'],
                'discovery_source' => MikroParitySource::CUSTOMER_DISCOVERY->value,
                'detail_source' => MikroParitySource::CUSTOMER_DETAIL->value,
                'promotion_critical_fields' => ['record_id', 'customer_code', 'title_normalized', 'active_state', 'customer_group_code', 'currency_code'],
                'enrichment_diagnostic_fields' => ['balance_by_currency', 'credit_limit', 'risk_total', 'tax_identity_hash', 'tax_office_normalized', 'phone_hashes_sorted', 'address_keys_sorted'],
                'response_schema' => $schemas['customer.lookup'],
                'source_field_mapping' => [
                    'mikro' => ['cari_Guid' => 'record_id', 'cari_kod' => 'customer_code', 'cari_unvan1+cari_unvan2' => 'title_normalized', 'status_flags' => 'active_state', 'cari_grup_kodu' => 'customer_group_code'],
                    'n8n' => ['record_id' => 'record_id', 'customer_code' => 'customer_code', 'title_1+title_2' => 'title_normalized', 'status_flags' => 'active_state', 'customer_group_code' => 'customer_group_code'],
                ],
                'unavailable_fields' => [
                    'currency_code' => $this->unavailable('PROMOTION_CRITICAL', 'V17 source proves only a currency index; no verified index-to-code authority exists in the parity source.'),
                    'balance_by_currency' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'No verified balance-by-currency aggregation authority is sealed.'),
                    'credit_limit' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'No verified V17 field or current primary result supplies this value.'),
                    'risk_total' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'No verified V17 risk aggregation contract is available.'),
                    'tax_identity_hash' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'Verified source mapping is not implemented.'),
                    'tax_office_normalized' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'Verified source mapping is not implemented.'),
                    'phone_hashes_sorted' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'Verified source mapping is not implemented.'),
                    'address_keys_sorted' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'Verified source mapping is not implemented.'),
                ],
                'semantic_blockers' => [],
            ],
            'stock.availability' => [
                'identity_key' => ['item_code', 'warehouse_code'],
                'sample_identity_contract' => ['item_code', 'warehouse_code', 'as_of_date'],
                'discovery_source' => MikroParitySource::STOCK_DISCOVERY->value,
                'detail_source' => MikroParitySource::STOCK_DETAIL->value,
                'promotion_critical_fields' => ['item_code', 'warehouse_code', 'unit_code', 'on_hand_quantity', 'reserved_quantity', 'available_quantity', 'serial_tracking_state', 'item_active_state'],
                'enrichment_diagnostic_fields' => ['record_id', 'source_updated_at'],
                'response_schema' => $schemas['stock.availability'],
                'source_field_mapping' => [
                    'mikro' => ['sto_kod' => 'item_code', 'warehouse parameter' => 'warehouse_code', 'fn_DepodakiMiktar' => 'on_hand_quantity', 'sto_detay_takip' => 'serial_tracking_state', 'sto_pasif_fl' => 'item_active_state'],
                    'n8n' => ['item_code' => 'item_code', 'warehouse_code' => 'warehouse_code', 'on_hand_quantity' => 'on_hand_quantity', 'serial_tracking_code' => 'serial_tracking_state', 'item_active_flag' => 'item_active_state'],
                ],
                'unavailable_fields' => [
                    'unit_code' => $this->unavailable('PROMOTION_CRITICAL', 'The verified stock field is a unit name, not an authoritative unit code.'),
                    'reserved_quantity' => $this->unavailable('PROMOTION_CRITICAL', 'No verified reservation aggregation rule exists for item plus warehouse.'),
                    'available_quantity' => $this->unavailable('PROMOTION_CRITICAL', 'Availability cannot be inferred from on-hand while reserved quantity is unavailable.'),
                ],
                'semantic_blockers' => [
                    'on_hand_quantity' => $this->unavailable('PROMOTION_CRITICAL', 'The function result exists, but physical-stock semantic authority is not sealed.'),
                ],
            ],
            'serial.lookup' => [
                'identity_key' => ['serial_number', 'item_code'],
                'sample_identity_contract' => ['serial_number', 'item_code'],
                'discovery_source' => MikroParitySource::SERIAL_DISCOVERY->value,
                'detail_source' => MikroParitySource::SERIAL_DETAIL->value,
                'promotion_critical_fields' => ['serial_number', 'item_code', 'warehouse_code', 'serial_state', 'available_state', 'movement_timestamp_normalized'],
                'enrichment_diagnostic_fields' => ['customer_code', 'order_number', 'dispatch_number', 'invoice_number'],
                'response_schema' => $schemas['serial.lookup'],
                'source_field_mapping' => [
                    'mikro' => ['chz_serino' => 'serial_number', 'chz_stok_kodu' => 'item_code', 'latest master_tablo=0 movement' => 'movement context'],
                    'n8n' => ['serial_number' => 'serial_number', 'item_code' => 'item_code', 'latest stock movement' => 'movement context'],
                ],
                'unavailable_fields' => [
                    'warehouse_code' => $this->unavailable('PROMOTION_CRITICAL', 'Latest movement warehouses do not prove current location.'),
                    'serial_state' => $this->unavailable('PROMOTION_CRITICAL', 'No verified lifecycle mapping exists.'),
                    'available_state' => $this->unavailable('PROMOTION_CRITICAL', 'Reserved flag alone is not a verified availability contract.'),
                    'customer_code' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'Current-customer semantics are not proven.'),
                    'order_number' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'Only a line GUID is available.'),
                    'dispatch_number' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'Movement document type is not qualified as dispatch.'),
                    'invoice_number' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'Only a line GUID is available.'),
                ],
                'semantic_blockers' => [
                    'freshness' => $this->unavailable('PROMOTION_CRITICAL', 'Serial-card update time is not proven to equal latest movement freshness.'),
                ],
            ],
            'order.detail' => [
                'identity_key' => ['authoritative_order_guid'],
                'sample_identity_contract' => ['anchor_line_guid', 'document_identity'],
                'discovery_source' => MikroParitySource::ORDER_DISCOVERY->value,
                'detail_source' => MikroParitySource::ORDER_DETAIL->value,
                'promotion_critical_fields' => ['authoritative_order_guid', 'order_number', 'customer_code', 'order_state', 'order_date_normalized', 'currency_code', 'warehouse_code', 'line_count', 'lines'],
                'enrichment_diagnostic_fields' => ['net_total', 'tax_total', 'gross_total', 'dispatch_references_sorted', 'invoice_references_sorted'],
                'response_schema' => $schemas['order.detail'],
                'source_field_mapping' => [
                    'mikro' => ['sip_Guid' => 'lines[].line_key', 'document tuple' => 'document_identity', 'sip_miktar' => 'ordered_quantity', 'sip_teslim_miktar' => 'delivered_quantity'],
                    'n8n' => ['line_guid' => 'lines[].line_key', 'document_identity' => 'document_identity', 'ordered_quantity' => 'ordered_quantity', 'delivered_quantity' => 'delivered_quantity'],
                ],
                'unavailable_fields' => [
                    'authoritative_order_guid' => $this->unavailable('PROMOTION_CRITICAL', 'V17 SIPARISLER exposes line GUIDs; no document GUID is proven.'),
                    'order_state' => $this->unavailable('PROMOTION_CRITICAL', 'Raw cancel, close and status flags lack a verified lifecycle mapping.'),
                    'currency_code' => $this->unavailable('PROMOTION_CRITICAL', 'The verified source exposes only a currency index.'),
                    'unit_code' => $this->unavailable('PROMOTION_CRITICAL', 'The verified source exposes only a unit pointer.'),
                    'open_quantity' => $this->unavailable('PROMOTION_CRITICAL', 'Cancellation-aware open quantity semantics are not verified.'),
                    'net_total' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'Document-level aggregation is not verified.'),
                    'tax_total' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'Document-level tax aggregation is not verified.'),
                    'gross_total' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'Document-level gross aggregation is not verified.'),
                    'dispatch_references_sorted' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'Dispatch reference mapping is unavailable.'),
                    'invoice_references_sorted' => $this->unavailable('ENRICHMENT_DIAGNOSTIC', 'Invoice reference mapping is unavailable.'),
                ],
                'semantic_blockers' => [
                    'successful_discovery' => $this->unavailable('PROMOTION_CRITICAL', 'Final-template order discovery has not produced a successful sample.'),
                ],
            ],
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function responseSchemas(): array
    {
        return [
            'customer.lookup' => [
                $this->field('record_id', 'string', false, true, 'record', 'lowercase_text'),
                $this->field('customer_code', 'string', false, true, 'business_identity', 'trimmed_text'),
                $this->field('title_normalized', 'string', false, true, 'none', 'normalized_text'),
                $this->field('active_state', 'string', false, true, 'none', 'enum'),
                $this->field('customer_group_code', 'string', true, true, 'none', 'trimmed_text'),
                $this->field('currency_code', 'string', false, true, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('balance_by_currency', 'array', true, false, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('credit_limit', 'decimal', true, false, 'none', 'unavailable', decimalScale: 6, availability: 'FIELD_UNAVAILABLE'),
                $this->field('risk_total', 'decimal', true, false, 'none', 'unavailable', decimalScale: 6, availability: 'FIELD_UNAVAILABLE'),
                $this->field('tax_identity_hash', 'string', true, false, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('tax_office_normalized', 'string', true, false, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('phone_hashes_sorted', 'array', true, false, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('address_keys_sorted', 'array', true, false, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('source_status', 'object', false, false, 'none', 'source_flags'),
                $this->field('source_updated_at', 'timestamp', true, false, 'none', 'canonical_timestamp', self::SOURCE_TIMEZONE),
            ],
            'stock.availability' => [
                $this->field('record_id', 'string', false, false, 'record', 'lowercase_text'),
                $this->field('item_code', 'string', false, true, 'business_identity', 'trimmed_text'),
                $this->field('warehouse_code', 'integer', false, true, 'business_identity', 'integer'),
                $this->field('unit_code', 'string', false, true, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('unit_name', 'string', true, false, 'none', 'trimmed_text'),
                $this->field('on_hand_quantity', 'decimal', false, true, 'none', 'decimal', decimalScale: 6),
                $this->field('reserved_quantity', 'decimal', false, true, 'none', 'unavailable', decimalScale: 6, availability: 'FIELD_UNAVAILABLE'),
                $this->field('available_quantity', 'decimal', false, true, 'none', 'unavailable', decimalScale: 6, availability: 'FIELD_UNAVAILABLE'),
                $this->field('serial_tracking_state', 'string', false, true, 'none', 'enum'),
                $this->field('item_active_state', 'string', false, true, 'none', 'enum'),
                $this->field('source_tracking_code', 'integer', false, false, 'none', 'integer'),
                $this->field('source_updated_at', 'timestamp', true, false, 'none', 'canonical_timestamp', self::SOURCE_TIMEZONE),
            ],
            'serial.lookup' => [
                $this->field('record_id', 'string', false, false, 'record', 'lowercase_text'),
                $this->field('serial_number', 'string', false, true, 'business_identity', 'trimmed_text'),
                $this->field('item_code', 'string', false, true, 'business_identity', 'trimmed_text'),
                $this->field('warehouse_code', 'integer', false, true, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('serial_state', 'string', false, true, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('available_state', 'string', false, true, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('reserved_flag', 'integer', true, false, 'none', 'integer'),
                $this->field('movement_type', 'integer', true, false, 'none', 'integer'),
                $this->field('ingress_warehouse_code', 'integer', true, false, 'none', 'integer'),
                $this->field('egress_warehouse_code', 'integer', true, false, 'none', 'integer'),
                $this->field('customer_code', 'string', true, false, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('order_number', 'string', true, false, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('dispatch_number', 'string', true, false, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('invoice_number', 'string', true, false, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('order_line_guid', 'string', true, false, 'none', 'lowercase_text'),
                $this->field('invoice_line_guid', 'string', true, false, 'none', 'lowercase_text'),
                $this->field('movement_document_series', 'string', true, false, 'none', 'trimmed_text'),
                $this->field('movement_document_number', 'integer', true, false, 'none', 'integer'),
                $this->field('movement_timestamp_normalized', 'timestamp', true, true, 'none', 'canonical_timestamp', self::SOURCE_TIMEZONE),
                $this->field('source_updated_at', 'timestamp', true, false, 'none', 'canonical_timestamp', self::SOURCE_TIMEZONE),
            ],
            'order.detail' => [
                $this->field('authoritative_order_guid', 'string', false, true, 'canonical_identity', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('document_identity', 'string', false, false, 'source_composite', 'trimmed_text'),
                $this->field('order_number', 'string', false, true, 'business_identity', 'trimmed_text'),
                $this->field('customer_code', 'string', false, true, 'none', 'trimmed_text'),
                $this->field('order_state', 'string', false, true, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('order_date_normalized', 'date', false, true, 'none', 'canonical_date', self::SOURCE_TIMEZONE),
                $this->field('requested_delivery_date_normalized', 'date', true, false, 'none', 'canonical_date', self::SOURCE_TIMEZONE),
                $this->field('currency_code', 'string', false, true, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('warehouse_code', 'integer', false, true, 'none', 'integer'),
                $this->field('source_state', 'object', false, false, 'none', 'source_flags'),
                $this->field('line_count', 'integer', false, true, 'none', 'integer'),
                [
                    ...$this->field('lines', 'array', false, true, 'none', 'ordered_lines'),
                    'children' => [
                        $this->field('line_key', 'string', false, true, 'line_identity', 'lowercase_text'),
                        $this->field('line_number', 'integer', false, true, 'none', 'integer'),
                        $this->field('item_code', 'string', false, true, 'none', 'trimmed_text'),
                        $this->field('unit_code', 'string', false, true, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                        $this->field('ordered_quantity', 'decimal', false, true, 'none', 'decimal', decimalScale: 6),
                        $this->field('delivered_quantity', 'decimal', false, true, 'none', 'decimal', decimalScale: 6),
                        $this->field('open_quantity', 'decimal', false, true, 'none', 'unavailable', decimalScale: 6, availability: 'FIELD_UNAVAILABLE'),
                        $this->field('unit_price', 'decimal', false, true, 'none', 'decimal', decimalScale: 6),
                        $this->field('line_net_amount', 'decimal', false, false, 'none', 'decimal', decimalScale: 6),
                        $this->field('line_tax_amount', 'decimal', false, false, 'none', 'decimal', decimalScale: 6),
                    ],
                ],
                $this->field('net_total', 'decimal', true, false, 'none', 'unavailable', decimalScale: 6, availability: 'FIELD_UNAVAILABLE'),
                $this->field('tax_total', 'decimal', true, false, 'none', 'unavailable', decimalScale: 6, availability: 'FIELD_UNAVAILABLE'),
                $this->field('gross_total', 'decimal', true, false, 'none', 'unavailable', decimalScale: 6, availability: 'FIELD_UNAVAILABLE'),
                $this->field('dispatch_references_sorted', 'array', true, false, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('invoice_references_sorted', 'array', true, false, 'none', 'unavailable', availability: 'FIELD_UNAVAILABLE'),
                $this->field('source_updated_at', 'timestamp', true, false, 'none', 'canonical_timestamp', self::SOURCE_TIMEZONE),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function sourceResponseSchema(MikroParitySource $source): array
    {
        $string = fn (string $path, bool $nullable = false, string $normalizer = 'trimmed_text'): array => $this->field($path, 'string', $nullable, true, 'source', $normalizer);
        $integer = fn (string $path, bool $nullable = false): array => $this->field($path, 'integer', $nullable, true, 'source', 'integer');
        $decimal = fn (string $path): array => $this->field($path, 'decimal', false, true, 'source', 'decimal', decimalScale: 6);
        $timestamp = fn (string $path, bool $nullable = true): array => $this->field($path, 'timestamp', $nullable, true, 'source', 'canonical_timestamp', self::SOURCE_TIMEZONE);

        return match ($source) {
            MikroParitySource::CUSTOMER_DISCOVERY, MikroParitySource::CUSTOMER_DETAIL => [
                $string('record_id'), $string('customer_code'), $string('title_1'), $string('title_2', true),
                $string('customer_group_code', true), $integer('active_abandon_code'), $integer('company_open_closed_flag'),
                $integer('locked_flag'), $integer('currency_index'), $timestamp('source_updated_at'),
            ],
            MikroParitySource::STOCK_DISCOVERY, MikroParitySource::STOCK_DETAIL => [
                $string('record_id'), $string('item_code'), $integer('warehouse_code'), $string('unit_name', true),
                $decimal('on_hand_quantity'), $integer('serial_tracking_code'), $integer('item_active_flag'), $timestamp('source_updated_at'),
            ],
            MikroParitySource::SERIAL_DISCOVERY => [
                $string('record_id'), $string('serial_number'), $string('item_code'), $timestamp('source_updated_at'),
            ],
            MikroParitySource::SERIAL_DETAIL => [
                $string('record_id'), $string('serial_number'), $string('item_code'), $integer('reserved_flag', true),
                $integer('movement_type', true), $integer('ingress_warehouse_code', true), $integer('egress_warehouse_code', true),
                $string('customer_code', true), $string('order_line_guid', true), $string('invoice_line_guid', true),
                $string('movement_document_series', true), $integer('movement_document_number', true),
                $timestamp('movement_timestamp'), $timestamp('source_updated_at'),
            ],
            MikroParitySource::ORDER_DISCOVERY => [
                $string('anchor_line_guid'), $string('document_identity'), $string('document_series', true),
                $integer('document_number'), $string('customer_code'), $timestamp('order_date', false),
                $timestamp('requested_delivery_date'), $integer('currency_index'), $integer('warehouse_code'),
                $integer('line_count'), $timestamp('source_updated_at'),
            ],
            MikroParitySource::ORDER_DETAIL => [
                $string('line_guid'), $string('document_identity'), $string('document_series', true), $integer('document_number'),
                $integer('line_number'), $string('customer_code'), $timestamp('order_date', false), $timestamp('requested_delivery_date'),
                $integer('warehouse_code'), $string('item_code'), $decimal('ordered_quantity'), $decimal('delivered_quantity'),
                $decimal('open_quantity'), $decimal('unit_price'), $decimal('line_net_amount'), $decimal('line_tax_amount'),
                $integer('cancelled_flag'), $integer('closed_flag'), $integer('raw_order_state'), $timestamp('source_updated_at'),
            ],
        };
    }

    /** @param array<string, array<string, mixed>> $definitions */
    private function assertSchemasCompile(array $definitions): void
    {
        foreach ($definitions as $definition) {
            $schema = $definition['response_schema'] ?? null;
            if (! is_array($schema)) {
                throw new DomainException('MIKRO_PARITY_SCHEMA_INVALID');
            }
            $fields = [];
            foreach ($schema as $field) {
                $this->assertFieldSchema($field);
                $fields[$field['path']] = $field;
                foreach ($field['children'] ?? [] as $child) {
                    $this->assertFieldSchema($child);
                }
            }
            foreach ($definition['promotion_critical_fields'] as $critical) {
                if (! isset($fields[$critical]) || $fields[$critical]['critical'] !== true) {
                    throw new DomainException('MIKRO_PARITY_CRITICAL_FIELD_SCHEMA_MISSING');
                }
            }
        }
    }

    /** @param array<string, mixed> $field */
    private function assertFieldSchema(array $field): void
    {
        foreach (['path', 'type', 'nullable', 'critical', 'identity_role', 'normalizer', 'source_timezone', 'decimal_scale', 'null_empty_equivalence', 'availability'] as $key) {
            if (! array_key_exists($key, $field)) {
                throw new DomainException('MIKRO_PARITY_SCHEMA_INVALID');
            }
        }
        if (! in_array($field['type'], ['string', 'integer', 'decimal', 'boolean', 'date', 'timestamp', 'array', 'object'], true)
            || ! is_bool($field['nullable'])
            || ! is_bool($field['critical'])) {
            throw new DomainException('MIKRO_PARITY_SCHEMA_INVALID');
        }
    }

    /** @return array<string, mixed> */
    private function field(
        string $path,
        string $type,
        bool $nullable,
        bool $critical,
        string $identityRole,
        string $normalizer,
        ?string $sourceTimezone = null,
        ?int $decimalScale = null,
        string $availability = 'AVAILABLE',
    ): array {
        return [
            'path' => $path,
            'type' => $type,
            'nullable' => $nullable,
            'critical' => $critical,
            'identity_role' => $identityRole,
            'normalizer' => $normalizer,
            'source_timezone' => $sourceTimezone,
            'decimal_scale' => $decimalScale,
            'null_empty_equivalence' => $nullable ? 'NULL_EQUALS_EMPTY_INPUT' : 'STRICT',
            'availability' => $availability,
        ];
    }

    /** @return array<string, string> */
    private function unavailable(string $classification, string $reason): array
    {
        return ['classification' => $classification, 'reason' => $reason];
    }

    /** @return array<string, mixed> */
    private function normalizerRules(): array
    {
        return [
            'date' => ['output' => 'YYYY-MM-DD', 'timestamp_conflation' => false],
            'timestamp' => ['output' => 'UTC YYYY-MM-DDTHH:MM:SSZ', 'precision' => 'seconds', 'ambiguous_local_time' => 'CONTRACT_ERROR'],
            'decimal' => ['output' => 'canonical numeric string', 'maximum_scale' => 6],
            'text' => ['trim' => true, 'unicode_case' => 'operation-specific'],
            'missing_critical_field' => 'CONTRACT_ERROR_NO_DEFAULT',
            'unknown_provider_field' => 'DROP',
        ];
    }

    /** @return array<string, mixed> */
    private function sourceTimezoneRules(): array
    {
        $rules = [];
        foreach (MikroParitySource::cases() as $source) {
            $rules[$source->value] = ['mikro' => self::SOURCE_TIMEZONE, 'n8n' => self::SOURCE_TIMEZONE];
        }

        return $rules;
    }

    private function sourceTimezone(MikroParitySource $source, string $provider): string
    {
        $timezone = $this->sourceTimezoneRules()[$source->value][$provider] ?? null;
        if (! is_string($timezone) || $timezone === '') {
            throw new DomainException('SOURCE_TIMEZONE_UNAVAILABLE');
        }

        return $timezone;
    }

    private function timezone(?string $timezone): DateTimeZone
    {
        if (! is_string($timezone) || trim($timezone) === '') {
            throw new DomainException('SOURCE_TIMEZONE_UNAVAILABLE');
        }
        try {
            return new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new DomainException('SOURCE_TIMEZONE_UNAVAILABLE', previous: $exception);
        }
    }

    private function parseOffsetTimestamp(string $value): DateTimeImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/i', $value)) {
            throw new DomainException('MIKRO_PARITY_TIMESTAMP_INVALID');
        }

        return new DateTimeImmutable($value);
    }

    private function parseNaiveTimestamp(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.\d{1,6})?$/', $value, $matches)) {
            throw new DomainException('MIKRO_PARITY_TIMESTAMP_INVALID');
        }
        $wall = $matches[1].' '.$matches[2];
        $wallUtc = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wall, new DateTimeZone('UTC'));
        if (! $wallUtc || $wallUtc->format('Y-m-d H:i:s') !== $wall) {
            throw new DomainException('MIKRO_PARITY_TIMESTAMP_INVALID');
        }

        $naiveEpoch = $wallUtc->getTimestamp();
        $offsets = [];
        $transitions = $timezone->getTransitions($naiveEpoch - 172800, $naiveEpoch + 172800);
        if (is_array($transitions)) {
            foreach ($transitions as $transition) {
                $offsets[(int) $transition['offset']] = true;
            }
        }
        if ($offsets === []) {
            $offsets[$timezone->getOffset(new DateTimeImmutable('@'.$naiveEpoch))] = true;
        }

        $matchesByInstant = [];
        foreach (array_keys($offsets) as $offset) {
            $candidate = $naiveEpoch - $offset;
            $instant = (new DateTimeImmutable('@'.$candidate))->setTimezone($timezone);
            if ($instant->format('Y-m-d H:i:s') === $wall) {
                $matchesByInstant[$candidate] = $instant;
            }
        }
        if (count($matchesByInstant) !== 1) {
            throw new DomainException(count($matchesByInstant) > 1
                ? 'MIKRO_PARITY_TIMESTAMP_AMBIGUOUS'
                : 'MIKRO_PARITY_TIMESTAMP_INVALID');
        }

        return array_values($matchesByInstant)[0];
    }

    private function hasExplicitOffset(string $value): bool
    {
        return preg_match('/(?:Z|[+-]\d{2}:\d{2})$/i', $value) === 1;
    }

    private function valueMatchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string', 'date', 'timestamp' => is_string($value),
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'decimal' => is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ! array_is_list($value),
            default => false,
        };
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /** @param array<string, mixed> $row */
    private function lowerKeys(array $row): array
    {
        return array_combine(
            array_map(fn (string|int $key): string => mb_strtolower((string) $key), array_keys($row)),
            array_values($row),
        );
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new DomainException('MIKRO_PARITY_TEXT_INVALID');
        }

        return trim((string) $value);
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = $this->text($value);

        return $value === '' ? null : $value;
    }

    private function normalizedText(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim($value)), 'UTF-8');
    }

    private function integer(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new DomainException('MIKRO_PARITY_INTEGER_INVALID');
        }

        return (int) $value;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : $this->integer($value);
    }

    private function decimal(mixed $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            throw new DomainException('MIKRO_PARITY_DECIMAL_INVALID');
        }

        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = rtrim(str_pad(substr($fraction, 0, 6), 6, '0'), '0');
        $normalized = ltrim($integer, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        if ($fraction !== '') {
            $normalized .= '.'.$fraction;
        }

        return $negative && $normalized !== '0' ? '-'.$normalized : $normalized;
    }
}
