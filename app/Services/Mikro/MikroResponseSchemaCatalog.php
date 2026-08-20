<?php

namespace App\Services\Mikro;

use DomainException;

final class MikroResponseSchemaCatalog
{
    public const VERIFIED = 'VERIFIED';

    public const PARTIAL_VERIFIED = 'PARTIAL_VERIFIED';

    public const MISSING = 'MISSING';

    public const STOCK_LIST_CONTRACT_VERSION = 'stok-listesi-v2-installed-2026-08-14';

    public const STOCK_LIST_RESPONSE_SCHEMA_FINGERPRINT = 'a48fd0939a9929a8c4e0251e362374bb5f41c8460c708827b8ff85441a84d81c';

    public const STOCK_LIST_NOT_FOUND_FINGERPRINT = 'fac4b5dd5e7d78308a38f3d7b12a2f9a50e58324c6bf45b8e100180f90f84bad';

    public const STOCK_SEARCH_CONTRACT_VERSION = 'technical-service-part-search-v1';

    public const STOCK_SEARCH_RESPONSE_SCHEMA_FINGERPRINT = '163a6d6ad41ab836835badc50dfeb2c56cceb289a2ca949b773f47ca68a82064';

    public const PHYSICAL_STOCK_CONTRACT_VERSION = 'technical-service-part-physical-stock-v1';

    public const PHYSICAL_STOCK_RESPONSE_SCHEMA_FINGERPRINT = '1e5bf09b70fb8eef4447fdb81724c6658f2c86e110d3dfcbf8ec7d583fadb83c';

    public const STOCK_TAX_PROFILE_CONTRACT_VERSION = 'technical-service-part-tax-profile-v1';

    public const STOCK_TAX_PROFILE_RESPONSE_SCHEMA_FINGERPRINT = 'cf569d6f884734f47cb7cde8a78c1b5caae4b84171cdc62aec303fe870188fb0';

    /** @var array<string, array<int, string>> */
    private const VERIFIED_FIELDS = [
        'health.check' => ['service_status'],
        'customer.detail' => ['customer_code', 'title', 'title_2', 'group_code', 'representative_code'],
        'customer.balance' => ['customer_code', 'balance'],
        'customer.document.timeline' => ['document_guid', 'customer_code', 'document_date', 'document_type', 'document_series', 'document_number', 'description', 'amount'],
        'stock.availability' => ['stock_code', 'depot_1_quantity', 'depot_5_quantity', 'available_quantity'],
        'stock.movement.list' => ['movement_guid', 'movement_date', 'stock_code', 'customer_code', 'movement_type', 'is_return', 'quantity', 'document_series', 'document_number'],
        'serial.lookup' => ['serial_number', 'movement_guid', 'movement_date', 'stock_code', 'customer_code', 'order_guid', 'invoice_guid'],
        'serial.history' => ['serial_number', 'movement_guid', 'movement_date', 'movement_type', 'is_return', 'stock_code', 'customer_code', 'document_series', 'document_number'],
        'order.list' => ['order_guid', 'order_date', 'document_series', 'document_number', 'customer_code', 'representative_code', 'stock_code', 'ordered_quantity', 'delivered_quantity'],
        'order.detail' => ['order_guid', 'order_date', 'document_series', 'document_number', 'customer_code', 'representative_code', 'description'],
        'order.lines' => ['order_guid', 'stock_code', 'ordered_quantity', 'delivered_quantity', 'movement_guid'],
        'order.remaining.quantity' => ['order_guid', 'remaining_quantity'],
        'invoice.list' => ['invoice_guid', 'invoice_date', 'customer_code', 'document_series', 'document_number', 'amount'],
        'invoice.detail' => ['invoice_guid', 'invoice_date', 'customer_code', 'document_series', 'document_number', 'description', 'amount'],
        'invoice.lines' => ['movement_guid', 'invoice_guid', 'stock_code', 'quantity', 'amount', 'description'],
        'dispatch.list' => ['dispatch_guid', 'dispatch_date', 'document_series', 'document_number', 'customer_code'],
        'dispatch.detail' => ['dispatch_guid', 'dispatch_date', 'document_series', 'document_number', 'customer_code', 'description'],
        'dispatch.lines' => ['movement_guid', 'stock_code', 'quantity', 'amount'],
        'return.list' => ['return_guid', 'return_date', 'stock_code', 'customer_code', 'quantity', 'document_series', 'document_number'],
        'return.detail' => ['return_guid', 'return_date', 'stock_code', 'customer_code', 'quantity', 'description'],
        'exchange.status' => ['serial_number', 'movement_guid', 'movement_date', 'movement_type', 'is_return', 'stock_code', 'customer_code'],
        'replacement.serial.lookup' => ['serial_number', 'movement_guid', 'movement_date', 'stock_code', 'customer_code', 'replacement_context'],
    ];

    private const MISSING_OPERATIONS = [
        'user.parameters',
        'user.list',
        'customer.list',
        'invoice.pdf',
        'dispatch.pdf',
        'edocument.status',
        'etaxpayer.check',
        'proforma.list',
        'proforma.detail',
    ];

    /** @return array<string, mixed> */
    public function descriptor(string $operationKey): array
    {
        if ($operationKey === 'stock.list') {
            return [
                'operation_key' => $operationKey,
                'schema_status' => self::VERIFIED,
                'normalizer_id' => self::STOCK_LIST_CONTRACT_VERSION,
                'contract_version' => self::STOCK_LIST_CONTRACT_VERSION,
                'response_schema_fingerprint' => self::STOCK_LIST_RESPONSE_SCHEMA_FINGERPRINT,
                'not_found_schema_fingerprint' => self::STOCK_LIST_NOT_FOUND_FINGERPRINT,
                'allowed_top_level_fields' => ['result'],
                'allowed_wrapper_fields' => ['Data', 'ErrorMessage', 'IsError', 'StatusCode'],
                'collection_path' => '$.result[].Data.StokListesi[]',
                'success_path' => '$.result[].IsError=false && $.result[].StatusCode=200',
                'error_path' => '$.result[].IsError=true || $.result[].StatusCode!=200',
                'allowed_record_fields' => ['sto_kod', 'sto_isim', 'sto_birim1_ad'],
                'required_record_fields' => ['sto_kod', 'sto_isim'],
                'nullable_record_fields' => ['sto_birim1_ad'],
                'field_types' => [
                    'sto_kod' => ['string'],
                    'sto_isim' => ['string'],
                    'sto_birim1_ad' => ['string', 'null'],
                ],
                'field_mapping' => [
                    'sto_kod' => 'item_code',
                    'sto_isim' => 'item_name',
                    'sto_birim1_ad' => 'unit_code',
                ],
                'normalized_fields' => ['item_code', 'item_name', 'unit_code'],
                'nested_mapping' => [],
                'sensitive_fields' => ['api_key', 'apikey', 'password', 'sifre', 'token', 'authorization'],
                'snapshot_allowed' => true,
                'blocker' => null,
            ];
        }

        if ($operationKey === 'stock.search') {
            return [
                'operation_key' => $operationKey,
                'schema_status' => self::VERIFIED,
                'normalizer_id' => self::STOCK_SEARCH_CONTRACT_VERSION,
                'contract_version' => self::STOCK_SEARCH_CONTRACT_VERSION,
                'response_schema_fingerprint' => self::STOCK_SEARCH_RESPONSE_SCHEMA_FINGERPRINT,
                'allowed_top_level_fields' => ['result'],
                'allowed_record_fields' => [
                    'item_code',
                    'item_name',
                    'item_short_name',
                    'unit_code',
                    'stock_type',
                    'detail_tracking_type',
                    'cancelled',
                    'hidden',
                ],
                'required_record_fields' => ['item_code', 'item_name', 'stock_type', 'detail_tracking_type', 'cancelled', 'hidden'],
                'nullable_record_fields' => ['item_short_name', 'unit_code'],
                'normalized_fields' => ['item_code', 'item_name', 'item_short_name', 'unit_code', 'stock_type', 'detail_tracking_type', 'cancelled', 'hidden'],
                'field_mapping' => [],
                'nested_mapping' => [],
                'sensitive_fields' => ['api_key', 'apikey', 'password', 'sifre', 'token', 'authorization'],
                'snapshot_allowed' => true,
                'blocker' => null,
            ];
        }

        if ($operationKey === 'stock.physical_quantity') {
            return [
                'operation_key' => $operationKey,
                'schema_status' => self::VERIFIED,
                'normalizer_id' => self::PHYSICAL_STOCK_CONTRACT_VERSION,
                'contract_version' => self::PHYSICAL_STOCK_CONTRACT_VERSION,
                'response_schema_fingerprint' => self::PHYSICAL_STOCK_RESPONSE_SCHEMA_FINGERPRINT,
                'allowed_top_level_fields' => ['result'],
                'allowed_record_fields' => ['item_code', 'unit_code', 'warehouse_code', 'physical_quantity'],
                'required_record_fields' => ['item_code', 'warehouse_code', 'physical_quantity'],
                'nullable_record_fields' => ['unit_code', 'physical_quantity'],
                'normalized_fields' => ['item_code', 'unit_code', 'warehouse_code', 'physical_quantity'],
                'field_mapping' => [],
                'nested_mapping' => [],
                'sensitive_fields' => ['api_key', 'apikey', 'password', 'sifre', 'token', 'authorization'],
                'snapshot_allowed' => true,
                'blocker' => null,
            ];
        }

        if ($operationKey === 'stock.tax_profile') {
            return [
                'operation_key' => $operationKey,
                'schema_status' => self::VERIFIED,
                'normalizer_id' => self::STOCK_TAX_PROFILE_CONTRACT_VERSION,
                'contract_version' => self::STOCK_TAX_PROFILE_CONTRACT_VERSION,
                'response_schema_fingerprint' => self::STOCK_TAX_PROFILE_RESPONSE_SCHEMA_FINGERPRINT,
                'allowed_top_level_fields' => ['result'],
                'allowed_record_fields' => [
                    'item_code',
                    'retail_tax_pointer',
                    'retail_tax_rate',
                    'wholesale_tax_pointer',
                    'wholesale_tax_rate',
                    'selected_tax_basis',
                    'selected_tax_pointer',
                    'selected_tax_rate',
                    'tax_status',
                    'tax_resolution_source',
                    'source',
                    'freshness_at',
                    'contract_version',
                    'correlation_id',
                ],
                'required_record_fields' => [
                    'item_code',
                    'retail_tax_pointer',
                    'retail_tax_rate',
                    'wholesale_tax_pointer',
                    'wholesale_tax_rate',
                    'selected_tax_basis',
                    'selected_tax_pointer',
                    'selected_tax_rate',
                    'tax_status',
                    'tax_resolution_source',
                    'source',
                    'freshness_at',
                    'contract_version',
                    'correlation_id',
                ],
                'nullable_record_fields' => [
                    'retail_tax_rate',
                    'wholesale_tax_rate',
                    'selected_tax_basis',
                    'selected_tax_pointer',
                    'selected_tax_rate',
                ],
                'normalized_fields' => [
                    'item_code',
                    'retail_tax_pointer',
                    'retail_tax_rate',
                    'wholesale_tax_pointer',
                    'wholesale_tax_rate',
                    'selected_tax_basis',
                    'selected_tax_pointer',
                    'selected_tax_rate',
                    'tax_status',
                    'tax_resolution_source',
                    'source',
                    'freshness_at',
                    'contract_version',
                    'correlation_id',
                ],
                'field_mapping' => [],
                'nested_mapping' => [],
                'sensitive_fields' => ['api_key', 'apikey', 'password', 'sifre', 'token', 'authorization'],
                'snapshot_allowed' => true,
                'blocker' => null,
            ];
        }

        $fields = self::VERIFIED_FIELDS[$operationKey] ?? null;
        if (is_array($fields)) {
            return [
                'operation_key' => $operationKey,
                'schema_status' => self::VERIFIED,
                'normalizer_id' => $operationKey,
                'allowed_top_level_fields' => ['data', 'Data', 'result', 'Result', 'rows', 'Rows'],
                'allowed_record_fields' => $fields,
                'field_mapping' => array_combine($fields, $fields),
                'nested_mapping' => [],
                'sensitive_fields' => ['api_key', 'apikey', 'password', 'sifre', 'token', 'authorization'],
                'snapshot_allowed' => true,
                'blocker' => null,
            ];
        }

        if (in_array($operationKey, self::MISSING_OPERATIONS, true)) {
            return [
                'operation_key' => $operationKey,
                'schema_status' => self::MISSING,
                'normalizer_id' => null,
                'allowed_top_level_fields' => [],
                'allowed_record_fields' => [],
                'field_mapping' => [],
                'nested_mapping' => [],
                'sensitive_fields' => ['api_key', 'apikey', 'password', 'sifre', 'token', 'authorization'],
                'snapshot_allowed' => false,
                'blocker' => 'MIKRO_RESPONSE_SCHEMA_UNVERIFIED',
            ];
        }

        throw new DomainException('MIKRO_RESPONSE_SCHEMA_MISSING');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function normalize(string $operationKey, array $rows): array
    {
        $schema = $this->descriptor($operationKey);
        if ($schema['schema_status'] !== self::VERIFIED) {
            throw new DomainException('MIKRO_RESPONSE_SCHEMA_UNVERIFIED');
        }

        if ($operationKey === 'stock.list') {
            return $this->normalizeStockList($rows, $schema);
        }
        if ($operationKey === 'stock.search') {
            return $this->normalizeStockSearch($rows);
        }
        if ($operationKey === 'stock.physical_quantity') {
            return $this->normalizePhysicalStock($rows);
        }
        if ($operationKey === 'stock.tax_profile') {
            return $this->normalizeStockTaxProfiles($rows);
        }

        $allowed = array_flip($schema['allowed_record_fields']);
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }

            $record = [];
            foreach ($row as $field => $value) {
                if (! isset($allowed[$field])) {
                    continue;
                }
                if (! is_scalar($value) && $value !== null) {
                    throw new DomainException('MIKRO_INVALID_RESPONSE');
                }
                $record[$field] = $value;
            }
            if ($record === []) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }
            $normalized[] = $record;
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     * @return array<int, array<string, mixed>>
     */
    public function sanitizeSnapshot(string $operationKey, array $data): array
    {
        $schema = $this->descriptor($operationKey);
        if (! $schema['snapshot_allowed']) {
            throw new DomainException('MIKRO_RESPONSE_SCHEMA_UNVERIFIED');
        }

        if ($operationKey === 'stock.list') {
            return $this->sanitizeStockListSnapshot($data);
        }
        if ($operationKey === 'stock.search') {
            return $this->normalizeStockSearch($data);
        }
        if ($operationKey === 'stock.physical_quantity') {
            return $this->normalizePhysicalStock($data);
        }
        if ($operationKey === 'stock.tax_profile') {
            return $this->normalizeStockTaxProfiles($data);
        }

        return $this->normalize($operationKey, $data);
    }

    /**
     * The generic client has already selected the top-level `result` array.
     * This method validates the installed-server wrapper before exposing only
     * the three proven stock identity fields.
     *
     * @param  array<int, array<string, mixed>>  $envelopes
     * @param  array<string, mixed>  $schema
     * @return array<int, array<string, mixed>>
     */
    private function normalizeStockList(array $envelopes, array $schema): array
    {
        if (count($envelopes) !== 1 || ! is_array($envelopes[0])) {
            throw new DomainException('MIKRO_INVALID_RESPONSE');
        }

        $envelope = $envelopes[0];
        foreach ($schema['allowed_wrapper_fields'] as $field) {
            if (! array_key_exists($field, $envelope)) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }
        }
        if (! is_bool($envelope['IsError'])
            || ! is_int($envelope['StatusCode'])
            || (! is_string($envelope['ErrorMessage']) && $envelope['ErrorMessage'] !== null)) {
            throw new DomainException('MIKRO_INVALID_RESPONSE');
        }
        if ($envelope['IsError'] || $envelope['StatusCode'] !== 200) {
            throw new DomainException('MIKRO_INVALID_RESPONSE');
        }

        $data = $envelope['Data'];
        if ($data === null) {
            return [];
        }
        if (! is_array($data)
            || ! array_key_exists('StokListesi', $data)
            || ! is_array($data['StokListesi'])
            || ! array_is_list($data['StokListesi'])) {
            throw new DomainException('MIKRO_INVALID_RESPONSE');
        }

        $normalized = [];
        foreach ($data['StokListesi'] as $row) {
            if (! is_array($row)) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }
            foreach ($schema['required_record_fields'] as $field) {
                if (! array_key_exists($field, $row)
                    || ! is_string($row[$field])
                    || trim($row[$field]) === '') {
                    throw new DomainException('MIKRO_INVALID_RESPONSE');
                }
            }
            if (array_key_exists('sto_birim1_ad', $row)
                && ! is_string($row['sto_birim1_ad'])
                && $row['sto_birim1_ad'] !== null) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }

            $normalized[] = [
                'item_code' => trim($row['sto_kod']),
                'item_name' => trim($row['sto_isim']),
                'unit_code' => filled($row['sto_birim1_ad'] ?? null)
                    ? trim((string) $row['sto_birim1_ad'])
                    : null,
            ];
        }

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $data @return array<int, array<string, mixed>> */
    private function sanitizeStockListSnapshot(array $data): array
    {
        $sanitized = [];
        foreach ($data as $row) {
            if (! is_array($row)
                || ! is_string($row['item_code'] ?? null)
                || trim($row['item_code']) === ''
                || ! is_string($row['item_name'] ?? null)
                || trim($row['item_name']) === '') {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }
            if (array_key_exists('unit_code', $row)
                && ! is_string($row['unit_code'])
                && $row['unit_code'] !== null) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }

            $sanitized[] = [
                'item_code' => trim($row['item_code']),
                'item_name' => trim($row['item_name']),
                'unit_code' => filled($row['unit_code'] ?? null)
                    ? trim((string) $row['unit_code'])
                    : null,
            ];
        }

        return $sanitized;
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function normalizeStockSearch(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)
                || ! is_string($row['item_code'] ?? null)
                || trim($row['item_code']) === ''
                || ! is_string($row['item_name'] ?? null)
                || trim($row['item_name']) === '') {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }

            foreach (['item_short_name', 'unit_code'] as $field) {
                if (array_key_exists($field, $row) && ! is_string($row[$field]) && $row[$field] !== null) {
                    throw new DomainException('MIKRO_INVALID_RESPONSE');
                }
            }
            foreach (['stock_type', 'detail_tracking_type'] as $field) {
                if (! array_key_exists($field, $row)
                    || filter_var($row[$field], FILTER_VALIDATE_INT) === false) {
                    throw new DomainException('MIKRO_INVALID_RESPONSE');
                }
            }

            foreach (['cancelled', 'hidden'] as $field) {
                if (! array_key_exists($field, $row)
                    || (! is_bool($row[$field]) && filter_var($row[$field], FILTER_VALIDATE_INT) === false)) {
                    throw new DomainException('MIKRO_INVALID_RESPONSE');
                }
            }

            $cancelled = (int) $row['cancelled'];
            $hidden = (int) $row['hidden'];
            if (! in_array($cancelled, [0, 1], true) || ! in_array($hidden, [0, 1], true)) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }

            $normalized[] = [
                'item_code' => trim($row['item_code']),
                'item_name' => trim($row['item_name']),
                'item_short_name' => filled($row['item_short_name'] ?? null) ? trim((string) $row['item_short_name']) : null,
                'unit_code' => filled($row['unit_code'] ?? null) ? trim((string) $row['unit_code']) : null,
                'stock_type' => (int) $row['stock_type'],
                'detail_tracking_type' => (int) $row['detail_tracking_type'],
                'cancelled' => (bool) $cancelled,
                'hidden' => (bool) $hidden,
            ];
        }

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function normalizePhysicalStock(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)
                || ! is_string($row['item_code'] ?? null)
                || trim($row['item_code']) === ''
                || ! array_key_exists('warehouse_code', $row)
                || filter_var($row['warehouse_code'], FILTER_VALIDATE_INT) === false
                || ! array_key_exists('physical_quantity', $row)) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }
            $warehouseCode = (int) $row['warehouse_code'];
            if (! in_array($warehouseCode, [1, 5], true)) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }
            if (array_key_exists('unit_code', $row)
                && ! is_string($row['unit_code'])
                && $row['unit_code'] !== null) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }

            $quantity = $row['physical_quantity'];
            if ($quantity !== null) {
                if (! is_scalar($quantity)
                    || ! preg_match('/^-?\d+(?:\.\d{1,6})?$/', trim((string) $quantity))) {
                    throw new DomainException('MIKRO_INVALID_RESPONSE');
                }
                $quantity = trim((string) $quantity);
            }

            $normalized[] = [
                'item_code' => trim($row['item_code']),
                'unit_code' => filled($row['unit_code'] ?? null) ? trim((string) $row['unit_code']) : null,
                'warehouse_code' => $warehouseCode,
                'physical_quantity' => $quantity,
            ];
        }

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, int|string>> */
    public function normalizeStockTaxPointerRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)
                || ! is_string($row['item_code'] ?? null)
                || trim($row['item_code']) === ''
                || ! array_key_exists('retail_tax_pointer', $row)
                || filter_var($row['retail_tax_pointer'], FILTER_VALIDATE_INT) === false
                || ! array_key_exists('wholesale_tax_pointer', $row)
                || filter_var($row['wholesale_tax_pointer'], FILTER_VALIDATE_INT) === false) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }

            $retailPointer = (int) $row['retail_tax_pointer'];
            $wholesalePointer = (int) $row['wholesale_tax_pointer'];
            if ($retailPointer < 0 || $retailPointer > 255 || $wholesalePointer < 0 || $wholesalePointer > 255) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }

            $normalized[] = [
                'item_code' => trim($row['item_code']),
                'retail_tax_pointer' => $retailPointer,
                'wholesale_tax_pointer' => $wholesalePointer,
            ];
        }

        return $normalized;
    }

    /** @return array<int, array{tax_pointer:int,tax_rate:string}> */
    public function normalizeInstalledTaxRates(array $response): array
    {
        $result = $response['result'] ?? null;
        $envelope = is_array($result) && array_is_list($result) ? ($result[0] ?? null) : null;
        if (! is_array($envelope)
            || ($envelope['IsError'] ?? true) !== false
            || (int) ($envelope['StatusCode'] ?? 0) !== 200
            || ! is_array($envelope['Data'] ?? null)
            || ! is_array($envelope['Data']['list'] ?? null)
            || ! array_is_list($envelope['Data']['list'])) {
            throw new DomainException('MIKRO_INVALID_RESPONSE');
        }

        $byPointer = [];
        foreach ($envelope['Data']['list'] as $row) {
            if (! is_array($row)
                || filter_var($row['vergiSiraNo'] ?? null, FILTER_VALIDATE_INT) === false
                || ! array_key_exists('vergiOrani', $row)) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }
            $pointer = (int) $row['vergiSiraNo'];
            if ($pointer < 0 || $pointer > 255) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }
            $rate = $this->normalizedPercentage($row['vergiOrani']);
            if ($rate === null || (isset($byPointer[$pointer]) && $byPointer[$pointer] !== $rate)) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }
            $byPointer[$pointer] = $rate;
        }
        ksort($byPointer, SORT_NUMERIC);

        return collect($byPointer)
            ->map(fn (string $rate, int $pointer): array => ['tax_pointer' => $pointer, 'tax_rate' => $rate])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, int|string>>  $pointerRows
     * @param  array<int, array{tax_pointer:int,tax_rate:string}>  $rateRows
     * @return array<int, array<string, mixed>>
     */
    public function resolveStockTaxProfiles(
        array $pointerRows,
        array $rateRows,
        string $freshnessAt,
        string $correlationId,
    ): array {
        $rateByPointer = collect($rateRows)->mapWithKeys(
            fn (array $row): array => [(int) $row['tax_pointer'] => (string) $row['tax_rate']],
        )->all();

        $profiles = array_map(function (array $row) use ($rateByPointer, $freshnessAt, $correlationId): array {
            $retailPointer = (int) $row['retail_tax_pointer'];
            $wholesalePointer = (int) $row['wholesale_tax_pointer'];
            $retailRate = $rateByPointer[$retailPointer] ?? null;
            $wholesaleRate = $rateByPointer[$wholesalePointer] ?? null;
            $ratesVerified = is_string($retailRate) && is_string($wholesaleRate);
            $equalRates = $ratesVerified && $retailRate === $wholesaleRate;

            return [
                'item_code' => (string) $row['item_code'],
                'retail_tax_pointer' => $retailPointer,
                'retail_tax_rate' => $retailRate,
                'wholesale_tax_pointer' => $wholesalePointer,
                'wholesale_tax_rate' => $wholesaleRate,
                'selected_tax_basis' => $equalRates ? 'equal_rates' : null,
                'selected_tax_pointer' => $equalRates && $retailPointer === $wholesalePointer ? $retailPointer : null,
                'selected_tax_rate' => $equalRates ? $retailRate : null,
                'tax_status' => ! $ratesVerified ? 'unavailable' : ($equalRates ? 'verified' : 'unresolved_basis'),
                'tax_resolution_source' => 'STOKLAR.sto_*_vergi + VergiListesiV2.vergiSiraNo/vergiOrani',
                'source' => 'mikro_api',
                'freshness_at' => $freshnessAt,
                'contract_version' => self::STOCK_TAX_PROFILE_CONTRACT_VERSION,
                'correlation_id' => $correlationId,
            ];
        }, $pointerRows);

        return $this->normalizeStockTaxProfiles($profiles);
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function normalizeStockTaxProfiles(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $required = $this->descriptor('stock.tax_profile')['required_record_fields'];
            if (! is_array($row)
                || array_diff($required, array_keys($row)) !== []
                || ! is_string($row['item_code'])
                || trim($row['item_code']) === ''
                || filter_var($row['retail_tax_pointer'], FILTER_VALIDATE_INT) === false
                || filter_var($row['wholesale_tax_pointer'], FILTER_VALIDATE_INT) === false
                || ! in_array($row['tax_status'], ['verified', 'unresolved_basis', 'unavailable', 'stale'], true)
                || ($row['tax_resolution_source'] ?? null) !== 'STOKLAR.sto_*_vergi + VergiListesiV2.vergiSiraNo/vergiOrani'
                || $row['source'] !== 'mikro_api'
                || $row['contract_version'] !== self::STOCK_TAX_PROFILE_CONTRACT_VERSION
                || ! is_string($row['freshness_at'])
                || trim($row['freshness_at']) === ''
                || ! is_string($row['correlation_id'])
                || trim($row['correlation_id']) === '') {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }

            $retailPointer = (int) $row['retail_tax_pointer'];
            $wholesalePointer = (int) $row['wholesale_tax_pointer'];
            $selectedPointer = $row['selected_tax_pointer'];
            if ($retailPointer < 0 || $retailPointer > 255
                || $wholesalePointer < 0 || $wholesalePointer > 255
                || ($selectedPointer !== null && filter_var($selectedPointer, FILTER_VALIDATE_INT) === false)
                || ($selectedPointer !== null && ((int) $selectedPointer < 0 || (int) $selectedPointer > 255))) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }

            $retailRate = $this->normalizedPercentage($row['retail_tax_rate']);
            $wholesaleRate = $this->normalizedPercentage($row['wholesale_tax_rate']);
            $selectedRate = $this->normalizedPercentage($row['selected_tax_rate']);
            $selectedBasis = $row['selected_tax_basis'];
            if ($selectedBasis !== null && $selectedBasis !== 'equal_rates') {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }
            if ($row['tax_status'] === 'verified'
                && ($selectedBasis !== 'equal_rates' || $selectedRate === null || $retailRate !== $wholesaleRate || $selectedRate !== $retailRate)) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }
            if ($row['tax_status'] === 'unresolved_basis'
                && ($retailRate === null || $wholesaleRate === null || $retailRate === $wholesaleRate
                    || $selectedBasis !== null || $selectedPointer !== null || $selectedRate !== null)) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }
            if (in_array($row['tax_status'], ['unavailable', 'stale'], true)
                && ($selectedBasis !== null || $selectedPointer !== null || $selectedRate !== null)) {
                throw new DomainException('MIKRO_INVALID_RESPONSE');
            }

            $normalized[] = [
                'item_code' => trim($row['item_code']),
                'retail_tax_pointer' => $retailPointer,
                'retail_tax_rate' => $retailRate,
                'wholesale_tax_pointer' => $wholesalePointer,
                'wholesale_tax_rate' => $wholesaleRate,
                'selected_tax_basis' => $selectedBasis,
                'selected_tax_pointer' => $selectedPointer === null ? null : (int) $selectedPointer,
                'selected_tax_rate' => $selectedRate,
                'tax_status' => (string) $row['tax_status'],
                'tax_resolution_source' => 'STOKLAR.sto_*_vergi + VergiListesiV2.vergiSiraNo/vergiOrani',
                'source' => 'mikro_api',
                'freshness_at' => trim($row['freshness_at']),
                'contract_version' => self::STOCK_TAX_PROFILE_CONTRACT_VERSION,
                'correlation_id' => trim($row['correlation_id']),
            ];
        }

        return $normalized;
    }

    private function normalizedPercentage(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_scalar($value)) {
            throw new DomainException('MIKRO_INVALID_RESPONSE');
        }

        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw new DomainException('MIKRO_INVALID_RESPONSE');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if ((int) $whole > 100 || ((int) $whole === 100 && trim($fraction, '0') !== '')) {
            throw new DomainException('MIKRO_INVALID_RESPONSE');
        }

        $canonical = ltrim($whole, '0');
        $canonical = $canonical === '' ? '0' : $canonical;
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $canonical : $canonical.'.'.$fraction;
    }
}
