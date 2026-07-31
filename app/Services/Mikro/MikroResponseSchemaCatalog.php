<?php

namespace App\Services\Mikro;

use DomainException;

final class MikroResponseSchemaCatalog
{
    public const VERIFIED = 'VERIFIED';

    public const PARTIAL_VERIFIED = 'PARTIAL_VERIFIED';

    public const MISSING = 'MISSING';

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
        'stock.list',
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

        return $this->normalize($operationKey, $data);
    }
}
