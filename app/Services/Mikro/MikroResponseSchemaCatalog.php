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
}
