<?php

namespace App\Services\Mikro;

use DomainException;
use JsonException;

class MikroParityContract
{
    public const NORMALIZATION_VERSION = 'mikro-shadow-parity-normalization.v1';

    public const OPERATION_CONTRACT_VERSION = 'mikro-shadow-parity-operations.v1';

    public function __construct(private readonly MikroFixedQueryCatalog $queries) {}

    /** @return array<int, string> */
    public function operationKeys(): array
    {
        return array_keys($this->operationDefinitions());
    }

    /** @return array<string, mixed> */
    public function operation(string $operationKey): array
    {
        $definition = $this->operationDefinitions()[$operationKey] ?? null;
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
            'schema_version' => 1,
        ];
    }

    /**
     * Validation deliberately renders and discards the SQL. This reuses the fixed-query
     * value validator while the n8n path receives only the immutable template and values.
     *
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
        $sources = [];
        foreach (MikroParitySource::cases() as $source) {
            $sources[$source->value] = $this->source($source);
        }

        return [
            'normalization_version' => self::NORMALIZATION_VERSION,
            'operation_contract_version' => self::OPERATION_CONTRACT_VERSION,
            'schema_version' => 1,
            'operations' => $this->operationDefinitions(),
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

        return [
            'operation_key' => $operationKey,
            'source_contract' => 'READY',
            'parity_readiness' => $unavailable === [] ? 'READY' : 'CONTRACT_FIELD_UNAVAILABLE',
            'unavailable_promotion_fields' => $unavailable,
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

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function normalize(MikroParitySource $source, string $provider, array $rows): array
    {
        $normalizedRows = array_values(array_map(fn (array $row): array => $this->lowerKeys($row), $rows));

        if ($source->phase() === 'discovery') {
            return $this->normalizeDiscovery($source, $provider, $normalizedRows);
        }

        $definition = $this->operation($source->operationKey());
        $requiredSourceFields = $definition['required_source_fields'][$provider] ?? null;
        if (! is_array($requiredSourceFields)) {
            throw new DomainException('MIKRO_PARITY_SOURCE_MAPPING_MISSING');
        }

        $missing = $this->missingFields($normalizedRows, $requiredSourceFields);
        if ($missing !== []) {
            return $this->normalizationResult($source, $provider, 'CONTRACT_ERROR', [], $missing);
        }

        try {
            $envelope = match ($source) {
                MikroParitySource::CUSTOMER_DETAIL => $this->customerEnvelope($normalizedRows[0]),
                MikroParitySource::STOCK_DETAIL => $this->stockEnvelope($normalizedRows[0]),
                MikroParitySource::SERIAL_DETAIL => $this->serialEnvelope($normalizedRows[0]),
                MikroParitySource::ORDER_DETAIL => $this->orderEnvelope($normalizedRows),
                default => throw new DomainException('MIKRO_PARITY_SOURCE_PHASE_INVALID'),
            };
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
        $required = match ($source) {
            MikroParitySource::CUSTOMER_DISCOVERY => ['record_id', 'customer_code'],
            MikroParitySource::STOCK_DISCOVERY => ['item_code', 'warehouse_code'],
            MikroParitySource::SERIAL_DISCOVERY => ['serial_number', 'item_code'],
            MikroParitySource::ORDER_DISCOVERY => ['anchor_line_guid', 'document_identity'],
            default => throw new DomainException('MIKRO_PARITY_SOURCE_PHASE_INVALID'),
        };
        $missing = $this->missingFields($rows, $required);
        if ($missing !== []) {
            return $this->normalizationResult($source, $provider, 'CONTRACT_ERROR', [], $missing);
        }

        $samples = array_map(function (array $row) use ($source): array {
            return match ($source) {
                MikroParitySource::CUSTOMER_DISCOVERY => [
                    'identity' => $this->text($row['customer_code']),
                    'lookup' => ['customer_code' => $this->text($row['customer_code'])],
                    'record_fingerprint' => $this->recordFingerprint([$row['record_id'], $row['customer_code']]),
                ],
                MikroParitySource::STOCK_DISCOVERY => [
                    'identity' => $this->text($row['item_code']).'|'.$this->integer($row['warehouse_code']),
                    'lookup' => [
                        'item_code' => $this->text($row['item_code']),
                        'warehouse_code' => $this->integer($row['warehouse_code']),
                    ],
                    'record_fingerprint' => $this->recordFingerprint([$row['item_code'], $row['warehouse_code']]),
                ],
                MikroParitySource::SERIAL_DISCOVERY => [
                    'identity' => $this->text($row['serial_number']).'|'.$this->text($row['item_code']),
                    'lookup' => [
                        'serial_number' => $this->text($row['serial_number']),
                        'item_code' => $this->text($row['item_code']),
                    ],
                    'record_fingerprint' => $this->recordFingerprint([$row['serial_number'], $row['item_code']]),
                ],
                MikroParitySource::ORDER_DISCOVERY => [
                    'identity' => $this->text($row['document_identity']),
                    'lookup' => ['order_anchor_line_guid' => strtolower($this->text($row['anchor_line_guid']))],
                    'record_fingerprint' => $this->recordFingerprint([$row['document_identity'], $row['anchor_line_guid']]),
                ],
                default => throw new DomainException('MIKRO_PARITY_SOURCE_PHASE_INVALID'),
            };
        }, $rows);

        usort($samples, fn (array $left, array $right): int => strcmp($left['identity'], $right['identity']));

        return $this->normalizationResult($source, $provider, 'READY', ['samples' => $samples]);
    }

    /** @param array<string, mixed> $row */
    private function customerEnvelope(array $row): array
    {
        $active = $this->integer($row['active_abandon_code']) === 0
            && $this->integer($row['company_open_closed_flag']) === 0
            && $this->integer($row['locked_flag']) === 0;

        return [
            'record_id' => strtolower($this->text($row['record_id'])),
            'customer_code' => $this->text($row['customer_code']),
            'title_normalized' => $this->normalizedText($this->text($row['title_1']).' '.$this->text($row['title_2'])),
            'active_state' => $active ? 'ACTIVE' : 'INACTIVE_OR_RESTRICTED',
            'customer_group_code' => $this->nullableText($row['customer_group_code']),
            'source_status' => [
                'active_abandon_code' => $this->integer($row['active_abandon_code']),
                'company_open_closed_flag' => $this->integer($row['company_open_closed_flag']),
                'locked_flag' => $this->integer($row['locked_flag']),
            ],
            'source_updated_at' => $this->nullableText($row['source_updated_at']),
        ];
    }

    /** @param array<string, mixed> $row */
    private function stockEnvelope(array $row): array
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
            'source_updated_at' => $this->nullableText($row['source_updated_at']),
        ];
    }

    /** @param array<string, mixed> $row */
    private function serialEnvelope(array $row): array
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
            'movement_timestamp_normalized' => $this->nullableText($row['movement_timestamp']),
            'source_updated_at' => $this->nullableText($row['source_updated_at']),
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function orderEnvelope(array $rows): array
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
            'order_date_normalized' => $this->text($first['order_date']),
            'requested_delivery_date_normalized' => $this->nullableText($first['requested_delivery_date']),
            'warehouse_code' => $this->integer($first['warehouse_code']),
            'source_state' => [
                'cancelled_flag' => $this->integer($first['cancelled_flag']),
                'closed_flag' => $this->integer($first['closed_flag']),
                'raw_order_state' => $this->integer($first['raw_order_state']),
            ],
            'line_count' => count($lines),
            'lines' => $lines,
            'source_updated_at' => $this->nullableText($first['source_updated_at']),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $required
     * @return array<int, string>
     */
    private function missingFields(array $rows, array $required): array
    {
        if ($rows === []) {
            return ['__row__'];
        }

        $missing = [];
        foreach ($rows as $row) {
            foreach ($required as $field) {
                if (! array_key_exists($field, $row)) {
                    $missing[$field] = true;
                }
            }
        }

        return array_keys($missing);
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
            'contract_fingerprint' => $this->fingerprint(),
            'missing_source_fields' => $missing,
            'unavailable_promotion_fields' => $unavailable,
            'envelope' => $envelope,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function operationDefinitions(): array
    {
        return [
            'customer.lookup' => [
                'identity_key' => ['customer_code'],
                'sample_identity_contract' => ['customer_code'],
                'discovery_source' => MikroParitySource::CUSTOMER_DISCOVERY->value,
                'detail_source' => MikroParitySource::CUSTOMER_DETAIL->value,
                'promotion_critical_fields' => ['record_id', 'customer_code', 'title_normalized', 'active_state', 'customer_group_code', 'currency_code'],
                'enrichment_diagnostic_fields' => ['balance_by_currency', 'credit_limit', 'risk_total', 'tax_identity_hash', 'tax_office_normalized', 'phone_hashes_sorted', 'address_keys_sorted'],
                'required_source_fields' => [
                    'mikro' => ['record_id', 'customer_code', 'title_1', 'title_2', 'customer_group_code', 'active_abandon_code', 'company_open_closed_flag', 'locked_flag', 'source_updated_at'],
                    'n8n' => ['record_id', 'customer_code', 'title_1', 'title_2', 'customer_group_code', 'active_abandon_code', 'company_open_closed_flag', 'locked_flag', 'source_updated_at'],
                ],
                'source_field_mapping' => [
                    'mikro' => ['cari_Guid' => 'record_id', 'cari_kod' => 'customer_code', 'cari_unvan1+cari_unvan2' => 'title_normalized', 'status_flags' => 'active_state', 'cari_grup_kodu' => 'customer_group_code'],
                    'n8n' => ['record_id' => 'record_id', 'customer_code' => 'customer_code', 'title_1+title_2' => 'title_normalized', 'status_flags' => 'active_state', 'customer_group_code' => 'customer_group_code'],
                ],
                'unavailable_fields' => [
                    'currency_code' => ['classification' => 'PROMOTION_CRITICAL', 'reason' => 'V17 source proves only a currency index; no verified index-to-code authority exists in the parity source.'],
                    'balance_by_currency' => ['classification' => 'ENRICHMENT_DIAGNOSTIC', 'reason' => 'The existing panel balance function has context-dependent currency semantics and cannot be relabelled as a currency breakdown.'],
                    'credit_limit' => ['classification' => 'ENRICHMENT_DIAGNOSTIC', 'reason' => 'No verified V17 field or current primary result supplies this value.'],
                    'risk_total' => ['classification' => 'ENRICHMENT_DIAGNOSTIC', 'reason' => 'No verified V17 risk aggregation contract is available.'],
                ],
            ],
            'stock.availability' => [
                'identity_key' => ['item_code', 'warehouse_code'],
                'sample_identity_contract' => ['item_code', 'warehouse_code'],
                'discovery_source' => MikroParitySource::STOCK_DISCOVERY->value,
                'detail_source' => MikroParitySource::STOCK_DETAIL->value,
                'promotion_critical_fields' => ['item_code', 'warehouse_code', 'unit_code', 'on_hand_quantity', 'reserved_quantity', 'available_quantity', 'serial_tracking_state', 'item_active_state'],
                'enrichment_diagnostic_fields' => ['record_id', 'source_updated_at'],
                'required_source_fields' => [
                    'mikro' => ['record_id', 'item_code', 'warehouse_code', 'unit_name', 'on_hand_quantity', 'serial_tracking_code', 'item_active_flag', 'source_updated_at'],
                    'n8n' => ['record_id', 'item_code', 'warehouse_code', 'unit_name', 'on_hand_quantity', 'serial_tracking_code', 'item_active_flag', 'source_updated_at'],
                ],
                'source_field_mapping' => [
                    'mikro' => ['sto_kod' => 'item_code', 'warehouse parameter' => 'warehouse_code', 'fn_DepodakiMiktar' => 'on_hand_quantity', 'sto_detay_takip' => 'serial_tracking_state', 'sto_pasif_fl' => 'item_active_state'],
                    'n8n' => ['item_code' => 'item_code', 'warehouse_code' => 'warehouse_code', 'on_hand_quantity' => 'on_hand_quantity', 'serial_tracking_code' => 'serial_tracking_state', 'item_active_flag' => 'item_active_state'],
                ],
                'unavailable_fields' => [
                    'unit_code' => ['classification' => 'PROMOTION_CRITICAL', 'reason' => 'The verified stock field is a unit name, not an authoritative unit code.'],
                    'reserved_quantity' => ['classification' => 'PROMOTION_CRITICAL', 'reason' => 'No verified reservation aggregation rule exists for item plus warehouse.'],
                    'available_quantity' => ['classification' => 'PROMOTION_CRITICAL', 'reason' => 'Availability cannot be inferred from on-hand while reserved quantity is unavailable.'],
                ],
            ],
            'serial.lookup' => [
                'identity_key' => ['serial_number', 'item_code'],
                'sample_identity_contract' => ['serial_number', 'item_code'],
                'discovery_source' => MikroParitySource::SERIAL_DISCOVERY->value,
                'detail_source' => MikroParitySource::SERIAL_DETAIL->value,
                'promotion_critical_fields' => ['serial_number', 'item_code', 'warehouse_code', 'serial_state', 'available_state', 'movement_timestamp_normalized'],
                'enrichment_diagnostic_fields' => ['customer_code', 'order_number', 'dispatch_number', 'invoice_number'],
                'required_source_fields' => [
                    'mikro' => ['record_id', 'serial_number', 'item_code', 'reserved_flag', 'movement_type', 'ingress_warehouse_code', 'egress_warehouse_code', 'customer_code', 'order_line_guid', 'invoice_line_guid', 'movement_document_series', 'movement_document_number', 'movement_timestamp', 'source_updated_at'],
                    'n8n' => ['record_id', 'serial_number', 'item_code', 'reserved_flag', 'movement_type', 'ingress_warehouse_code', 'egress_warehouse_code', 'customer_code', 'order_line_guid', 'invoice_line_guid', 'movement_document_series', 'movement_document_number', 'movement_timestamp', 'source_updated_at'],
                ],
                'source_field_mapping' => [
                    'mikro' => ['chz_serino' => 'serial_number', 'chz_stok_kodu' => 'item_code', 'latest master_tablo=0 movement' => 'movement context'],
                    'n8n' => ['serial_number' => 'serial_number', 'item_code' => 'item_code', 'latest stock movement' => 'movement context'],
                ],
                'unavailable_fields' => [
                    'warehouse_code' => ['classification' => 'PROMOTION_CRITICAL', 'reason' => 'A latest movement exposes ingress and egress warehouses but does not prove current serial location.'],
                    'serial_state' => ['classification' => 'PROMOTION_CRITICAL', 'reason' => 'No verified lifecycle state rule maps movement history to one canonical state.'],
                    'available_state' => ['classification' => 'PROMOTION_CRITICAL', 'reason' => 'Reserved flag alone is not a verified availability contract.'],
                    'dispatch_number' => ['classification' => 'ENRICHMENT_DIAGNOSTIC', 'reason' => 'The latest stock movement document is not proven to be a dispatch document.'],
                ],
            ],
            'order.detail' => [
                'identity_key' => ['document_identity'],
                'sample_identity_contract' => ['anchor_line_guid', 'document_identity'],
                'discovery_source' => MikroParitySource::ORDER_DISCOVERY->value,
                'detail_source' => MikroParitySource::ORDER_DETAIL->value,
                'promotion_critical_fields' => ['authoritative_order_guid', 'order_number', 'customer_code', 'order_state', 'order_date_normalized', 'currency_code', 'warehouse_code', 'line_count', 'lines'],
                'enrichment_diagnostic_fields' => ['net_total', 'tax_total', 'gross_total', 'dispatch_references_sorted', 'invoice_references_sorted'],
                'required_source_fields' => [
                    'mikro' => ['line_guid', 'document_identity', 'document_series', 'document_number', 'line_number', 'customer_code', 'order_date', 'requested_delivery_date', 'warehouse_code', 'item_code', 'ordered_quantity', 'delivered_quantity', 'open_quantity', 'unit_price', 'line_net_amount', 'line_tax_amount', 'cancelled_flag', 'closed_flag', 'raw_order_state', 'source_updated_at'],
                    'n8n' => ['line_guid', 'document_identity', 'document_series', 'document_number', 'line_number', 'customer_code', 'order_date', 'requested_delivery_date', 'warehouse_code', 'item_code', 'ordered_quantity', 'delivered_quantity', 'open_quantity', 'unit_price', 'line_net_amount', 'line_tax_amount', 'cancelled_flag', 'closed_flag', 'raw_order_state', 'source_updated_at'],
                ],
                'source_field_mapping' => [
                    'mikro' => ['sip_Guid' => 'lines[].line_key', 'document tuple' => 'document_identity', 'sip_miktar' => 'ordered_quantity', 'sip_teslim_miktar' => 'delivered_quantity'],
                    'n8n' => ['line_guid' => 'lines[].line_key', 'document_identity' => 'document_identity', 'ordered_quantity' => 'ordered_quantity', 'delivered_quantity' => 'delivered_quantity'],
                ],
                'unavailable_fields' => [
                    'authoritative_order_guid' => ['classification' => 'PROMOTION_CRITICAL', 'reason' => 'V17 SIPARISLER exposes line GUIDs; the document identity is a composite tuple and no document GUID is proven.'],
                    'order_state' => ['classification' => 'PROMOTION_CRITICAL', 'reason' => 'Raw cancel, close and status flags are exposed without inventing a lifecycle mapping.'],
                    'currency_code' => ['classification' => 'PROMOTION_CRITICAL', 'reason' => 'The verified source exposes only a currency index.'],
                    'unit_code' => ['classification' => 'PROMOTION_CRITICAL', 'reason' => 'The verified source exposes only a unit pointer.'],
                    'net_total' => ['classification' => 'ENRICHMENT_DIAGNOSTIC', 'reason' => 'Discount and charge aggregation is not yet a verified parity formula.'],
                    'tax_total' => ['classification' => 'ENRICHMENT_DIAGNOSTIC', 'reason' => 'Document-level tax aggregation is not yet a verified parity formula.'],
                    'gross_total' => ['classification' => 'ENRICHMENT_DIAGNOSTIC', 'reason' => 'Document-level gross aggregation is not yet a verified parity formula.'],
                ],
            ],
        ];
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
        return trim((string) $value);
    }

    private function nullableText(mixed $value): ?string
    {
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

    /** @param array<int, mixed> $parts */
    private function recordFingerprint(array $parts): string
    {
        return hash('sha256', implode('|', array_map(fn (mixed $part): string => $this->text($part), $parts)));
    }
}
