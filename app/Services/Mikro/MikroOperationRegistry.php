<?php

namespace App\Services\Mikro;

use DomainException;

class MikroOperationRegistry
{
    public const BLOCKED_UNKNOWN = 'MIKRO_UNKNOWN_OPERATION';

    public const BLOCKED_DENIED = 'MIKRO_OPERATION_NOT_ALLOWED';

    public const BLOCKED_DISABLED = 'MIKRO_OPERATION_DISABLED';

    public const SOURCE_MODES = ['mikro', 'n8n', 'shadow_compare', 'disabled'];

    /** @var array<string, array<string, mixed>> */
    private const READ_OPERATIONS = [
        'health.check' => ['name' => 'Health check', 'category' => 'system', 'adapter' => 'DIRECT_ENDPOINT', 'target' => '/Api/APIMethods/HealthCheck', 'method' => 'GET', 'payload_style' => 'none', 'request' => 'MikroHealthCheckRequest', 'response' => 'MikroHealthCheckResult', 'source' => 'mikro', 'fallback' => false, 'parity' => ['service_status']],
        'user.parameters' => ['name' => 'User parameters', 'category' => 'system', 'adapter' => 'DIRECT_ENDPOINT', 'target' => '/Api/APIMethods/KullaniciParametreleriV2', 'method' => 'POST', 'payload_style' => 'standard', 'request' => 'MikroUserParametersQuery', 'response' => 'MikroUserParametersResult', 'source' => 'mikro', 'fallback' => false, 'parity' => ['user_code', 'firm_code', 'working_year']],
        'user.list' => ['name' => 'User list', 'category' => 'system', 'adapter' => 'DIRECT_ENDPOINT', 'target' => '/Api/APIMethods/KullaniciListesiV2', 'method' => 'POST', 'payload_style' => 'standard', 'request' => 'MikroUserListQuery', 'response' => 'MikroUserListResult', 'source' => 'mikro', 'fallback' => false, 'parity' => ['user_code', 'name']],
        'customer.list' => ['name' => 'Customer list', 'category' => 'customer', 'adapter' => 'DIRECT_ENDPOINT', 'target' => '/Api/APIMethods/CariListesiV3', 'method' => 'POST', 'payload_style' => 'standard', 'request' => 'MikroCustomerListQuery', 'response' => 'MikroCustomerListResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['customer_code', 'title', 'tax_number']],
        'customer.detail' => ['name' => 'Customer detail', 'category' => 'customer', 'adapter' => 'FIXED_QUERY', 'target' => 'customer.detail', 'request' => 'MikroCustomerDetailQuery', 'response' => 'MikroCustomerDetailResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['customer_code', 'title', 'representative_code']],
        'customer.balance' => ['name' => 'Customer balance', 'category' => 'customer', 'adapter' => 'FIXED_QUERY', 'target' => 'customer.balance', 'request' => 'MikroCustomerBalanceQuery', 'response' => 'MikroCustomerBalanceResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['customer_code', 'balance']],
        'customer.document.timeline' => ['name' => 'Customer document timeline', 'category' => 'customer', 'adapter' => 'FIXED_QUERY', 'target' => 'customer.document.timeline', 'request' => 'MikroCustomerTimelineQuery', 'response' => 'MikroCustomerTimelineResult', 'source' => 'n8n', 'fallback' => true, 'parity' => ['document_guid', 'document_date', 'amount']],
        'stock.list' => ['name' => 'Stock list', 'category' => 'stock', 'adapter' => 'DIRECT_ENDPOINT', 'target' => '/Api/APIMethods/StokListesiV2', 'method' => 'POST', 'payload_style' => 'standard', 'request' => 'MikroStockListQuery', 'response' => 'MikroStockListResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['stock_code', 'stock_name']],
        'stock.availability' => ['name' => 'Stock availability', 'category' => 'stock', 'adapter' => 'FIXED_QUERY', 'target' => 'stock.availability', 'request' => 'MikroStockAvailabilityQuery', 'response' => 'MikroStockAvailabilityResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['stock_code', 'available_quantity']],
        'stock.movement.list' => ['name' => 'Stock movements', 'category' => 'stock', 'adapter' => 'FIXED_QUERY', 'target' => 'stock.movement.list', 'request' => 'MikroStockMovementQuery', 'response' => 'MikroStockMovementResult', 'source' => 'n8n', 'fallback' => true, 'parity' => ['movement_guid', 'movement_date', 'quantity']],
        'serial.lookup' => ['name' => 'Serial lookup', 'category' => 'serial', 'adapter' => 'FIXED_QUERY', 'target' => 'serial.lookup', 'request' => 'MikroSerialLookupQuery', 'response' => 'MikroSerialLookupResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['serial_number', 'stock_code', 'customer_code']],
        'serial.history' => ['name' => 'Serial history', 'category' => 'serial', 'adapter' => 'FIXED_QUERY', 'target' => 'serial.history', 'request' => 'MikroSerialHistoryQuery', 'response' => 'MikroSerialHistoryResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['serial_number', 'movement_date', 'movement_type']],
        'order.list' => ['name' => 'Order list', 'category' => 'order', 'adapter' => 'FIXED_QUERY', 'target' => 'order.list', 'request' => 'MikroOrderListQuery', 'response' => 'MikroOrderListResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['order_guid', 'order_date', 'customer_code']],
        'order.detail' => ['name' => 'Order detail', 'category' => 'order', 'adapter' => 'FIXED_QUERY', 'target' => 'order.detail', 'request' => 'MikroOrderDetailQuery', 'response' => 'MikroOrderDetailResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['order_guid', 'customer_code', 'document_number']],
        'order.lines' => ['name' => 'Order lines', 'category' => 'order', 'adapter' => 'FIXED_QUERY', 'target' => 'order.lines', 'request' => 'MikroOrderLinesQuery', 'response' => 'MikroOrderLinesResult', 'source' => 'n8n', 'fallback' => true, 'parity' => ['order_guid', 'stock_code', 'ordered_quantity']],
        'order.remaining.quantity' => ['name' => 'Order remaining quantity', 'category' => 'order', 'adapter' => 'FIXED_QUERY', 'target' => 'order.remaining.quantity', 'request' => 'MikroOrderRemainingQuery', 'response' => 'MikroOrderRemainingResult', 'source' => 'n8n', 'fallback' => true, 'parity' => ['order_guid', 'remaining_quantity']],
        'invoice.list' => ['name' => 'Invoice list', 'category' => 'invoice', 'adapter' => 'FIXED_QUERY', 'target' => 'invoice.list', 'request' => 'MikroInvoiceListQuery', 'response' => 'MikroInvoiceListResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['invoice_guid', 'invoice_date', 'customer_code']],
        'invoice.detail' => ['name' => 'Invoice detail', 'category' => 'invoice', 'adapter' => 'FIXED_QUERY', 'target' => 'invoice.detail', 'request' => 'MikroInvoiceDetailQuery', 'response' => 'MikroInvoiceDetailResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['invoice_guid', 'customer_code', 'amount']],
        'invoice.lines' => ['name' => 'Invoice lines', 'category' => 'invoice', 'adapter' => 'FIXED_QUERY', 'target' => 'invoice.lines', 'request' => 'MikroInvoiceLinesQuery', 'response' => 'MikroInvoiceLinesResult', 'source' => 'n8n', 'fallback' => true, 'parity' => ['invoice_guid', 'stock_code', 'quantity']],
        'invoice.pdf' => ['name' => 'Invoice PDF', 'category' => 'invoice', 'adapter' => 'DIRECT_ENDPOINT', 'target' => '/Api/APIMethods/FaturaPdfV2', 'method' => 'POST', 'payload_style' => 'mikro', 'request' => 'MikroInvoicePdfQuery', 'response' => 'MikroDocumentPdfResult', 'source' => 'mikro', 'fallback' => false, 'parity' => ['document_id', 'content_type']],
        'dispatch.list' => ['name' => 'Dispatch list', 'category' => 'dispatch', 'adapter' => 'FIXED_QUERY', 'target' => 'dispatch.list', 'request' => 'MikroDispatchListQuery', 'response' => 'MikroDispatchListResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['dispatch_guid', 'dispatch_date', 'customer_code']],
        'dispatch.detail' => ['name' => 'Dispatch detail', 'category' => 'dispatch', 'adapter' => 'FIXED_QUERY', 'target' => 'dispatch.detail', 'request' => 'MikroDispatchDetailQuery', 'response' => 'MikroDispatchDetailResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['dispatch_guid', 'customer_code', 'document_number']],
        'dispatch.lines' => ['name' => 'Dispatch lines', 'category' => 'dispatch', 'adapter' => 'FIXED_QUERY', 'target' => 'dispatch.lines', 'request' => 'MikroDispatchLinesQuery', 'response' => 'MikroDispatchLinesResult', 'source' => 'n8n', 'fallback' => true, 'parity' => ['movement_guid', 'stock_code', 'quantity']],
        'dispatch.pdf' => ['name' => 'E-dispatch PDF', 'category' => 'dispatch', 'adapter' => 'DIRECT_ENDPOINT', 'target' => '/Api/APIMethods/EIrsaliyePdfV2', 'method' => 'POST', 'payload_style' => 'mikro', 'request' => 'MikroDispatchPdfQuery', 'response' => 'MikroDocumentPdfResult', 'source' => 'mikro', 'fallback' => false, 'parity' => ['document_id', 'content_type']],
        'edocument.status' => ['name' => 'E-document status', 'category' => 'edocument', 'adapter' => 'DIRECT_ENDPOINT', 'target' => '/Api/APIMethods/EBelgeDurumSorgulamaV2', 'method' => 'POST', 'payload_style' => 'edocument', 'request' => 'MikroEDocumentStatusQuery', 'response' => 'MikroEDocumentStatusResult', 'source' => 'mikro', 'fallback' => false, 'parity' => ['uuid', 'status']],
        'etaxpayer.check' => ['name' => 'E-taxpayer check', 'category' => 'edocument', 'adapter' => 'DIRECT_ENDPOINT', 'target' => '/Api/APIMethods/EMukellefSorgulamaV2', 'method' => 'POST', 'payload_style' => 'etaxpayer', 'request' => 'MikroETaxpayerQuery', 'response' => 'MikroETaxpayerResult', 'source' => 'mikro', 'fallback' => false, 'parity' => ['tax_number', 'registered']],
        'return.list' => ['name' => 'Return list', 'category' => 'return', 'adapter' => 'FIXED_QUERY', 'target' => 'return.list', 'request' => 'MikroReturnListQuery', 'response' => 'MikroReturnListResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['return_guid', 'return_date', 'customer_code']],
        'return.detail' => ['name' => 'Return detail', 'category' => 'return', 'adapter' => 'FIXED_QUERY', 'target' => 'return.detail', 'request' => 'MikroReturnDetailQuery', 'response' => 'MikroReturnDetailResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['return_guid', 'stock_code', 'quantity']],
        'exchange.status' => ['name' => 'Exchange status', 'category' => 'exchange', 'adapter' => 'FIXED_QUERY', 'target' => 'exchange.status', 'request' => 'MikroExchangeStatusQuery', 'response' => 'MikroExchangeStatusResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['serial_number', 'movement_type', 'is_return']],
        'replacement.serial.lookup' => ['name' => 'Replacement serial lookup', 'category' => 'exchange', 'adapter' => 'FIXED_QUERY', 'target' => 'replacement.serial.lookup', 'request' => 'MikroReplacementSerialQuery', 'response' => 'MikroReplacementSerialResult', 'source' => 'n8n', 'fallback' => true, 'parity' => ['serial_number', 'stock_code', 'replacement_context']],
        'proforma.list' => ['name' => 'Proforma list', 'category' => 'proforma', 'adapter' => 'CONTRACT_BLOCKED', 'target' => null, 'request' => 'MikroProformaListQuery', 'response' => 'MikroProformaListResult', 'source' => 'disabled', 'fallback' => true, 'parity' => [], 'contract' => 'BLOCKED_CONTRACT_MISSING', 'implementation' => 'BLOCKED', 'runtime' => false, 'blocker' => 'Existing PrimeCRM proforma list is file-backed; no verified V17 endpoint or SQL contract exists.'],
        'proforma.detail' => ['name' => 'Proforma detail', 'category' => 'proforma', 'adapter' => 'CONTRACT_BLOCKED', 'target' => null, 'request' => 'MikroProformaDetailQuery', 'response' => 'MikroProformaDetailResult', 'source' => 'disabled', 'fallback' => true, 'parity' => [], 'contract' => 'BLOCKED_CONTRACT_MISSING', 'implementation' => 'BLOCKED', 'runtime' => false, 'blocker' => 'Existing PrimeCRM proforma detail is file-backed; no verified V17 endpoint or SQL contract exists.'],
    ];

    /** @var array<string, array<string, mixed>> */
    private const WRITE_OPERATIONS = [
        'customer.save' => ['name' => 'Save customer', 'category' => 'customer', 'method' => 'CariKaydetV2', 'contract' => 'VERIFIED'],
        'order.save' => ['name' => 'Save order', 'category' => 'order', 'method' => 'SiparisKaydetV2', 'contract' => 'VERIFIED'],
        'invoice.create' => ['name' => 'Create invoice', 'category' => 'invoice', 'method' => 'FaturaKaydetV3', 'contract' => 'VERIFIED'],
        'dispatch.create' => ['name' => 'Create dispatch', 'category' => 'dispatch', 'method' => 'IrsaliyeKaydetV2', 'contract' => 'VERIFIED'],
        'record.link.save' => ['name' => 'Save linked record', 'category' => 'record', 'method' => 'KayitKaydetV2', 'contract' => 'VERIFIED'],
        'record.bulk.save' => ['name' => 'Save record batch', 'category' => 'record', 'method' => 'KayitKaydetTopluV2', 'contract' => 'VERIFIED'],
        'stock.transfer.create' => ['name' => 'Create internal stock movement', 'category' => 'stock', 'method' => 'DahiliStokHareketKaydetV2', 'contract' => 'VERIFIED'],
        'order.dispatch.legacy.create' => ['name' => 'Legacy order dispatch', 'category' => 'dispatch', 'method' => 'SiparistenIrsaliyeOlusturmaV2', 'contract' => 'VERIFIED_LEGACY', 'blocker' => 'Legacy method is isolated from the production dispatch flow.'],
        'proforma.create' => ['name' => 'Create proforma', 'category' => 'proforma', 'method' => null, 'contract' => 'BLOCKED_CONTRACT_MISSING', 'blocker' => 'Exact V17 request contract is not verified.'],
        'return.create' => ['name' => 'Create return', 'category' => 'return', 'method' => null, 'contract' => 'BLOCKED_CONTRACT_MISSING', 'blocker' => 'Exact V17 request contract is not verified.'],
        'exchange.create' => ['name' => 'Create exchange', 'category' => 'exchange', 'method' => null, 'contract' => 'BLOCKED_CONTRACT_MISSING', 'blocker' => 'Exact V17 request contract is not verified.'],
    ];

    private const DENIED_OPERATIONS = [
        'generic.call', 'sql.read', 'sql.write', 'raw.sql', 'table.read', 'table.write',
        'record.save', 'record.update', 'record.delete', 'stock.save', 'stock.movement.create',
        'order.create', 'order.update', 'order.delete',
    ];

    /** @return array<string, mixed> */
    public function read(string $operationKey): array
    {
        $operation = $this->operation($operationKey);
        if (($operation['mode'] ?? null) !== 'READ') {
            throw new DomainException(self::BLOCKED_DENIED);
        }
        if (($operation['contract_status'] ?? null) !== 'VERIFIED' || ($operation['implementation_status'] ?? null) !== 'IMPLEMENTED') {
            throw new DomainException(self::BLOCKED_DISABLED);
        }

        return $operation;
    }

    /** @return array<string, mixed> */
    public function writeCapability(string $operationKey): array
    {
        $operation = $this->operation($operationKey);
        if (($operation['mode'] ?? null) !== 'WRITE') {
            throw new DomainException(self::BLOCKED_DENIED);
        }

        return $operation;
    }

    /** @return array<string, mixed> */
    public function operation(string $operationKey): array
    {
        if (in_array($operationKey, self::DENIED_OPERATIONS, true)) {
            throw new DomainException(self::BLOCKED_DENIED);
        }
        if (isset(self::READ_OPERATIONS[$operationKey])) {
            return $this->readDescriptor($operationKey, self::READ_OPERATIONS[$operationKey]);
        }
        if (isset(self::WRITE_OPERATIONS[$operationKey])) {
            return $this->writeDescriptor($operationKey, self::WRITE_OPERATIONS[$operationKey]);
        }

        throw new DomainException(self::BLOCKED_UNKNOWN);
    }

    /** @return array<string, mixed> */
    public function assertReadAllowed(string $operationKey, array $settings): array
    {
        $operation = $this->applyControl($this->read($operationKey), (array) ($settings['operation_controls'][$operationKey] ?? []));
        if (! (bool) ($settings['enabled'] ?? false)) {
            throw new DomainException('MIKRO_DISABLED');
        }
        if (! (bool) ($settings['read_sync_enabled'] ?? false)) {
            throw new DomainException('MIKRO_READ_SYNC_DISABLED');
        }
        if (! ($operation['runtime_enabled'] ?? false) || ($operation['source_mode'] ?? null) === 'disabled') {
            throw new DomainException(self::BLOCKED_DISABLED);
        }
        if (($operation['source_mode'] ?? null) === 'n8n') {
            throw new DomainException(self::BLOCKED_DENIED);
        }

        return $operation;
    }

    /** @return array<string, mixed> */
    public function assertWriteAllowed(
        string $operationKey,
        array $settings,
        bool $approved,
        ?string $idempotencyKey,
    ): array {
        $operation = $this->applyControl($this->writeCapability($operationKey), (array) ($settings['operation_controls'][$operationKey] ?? []));
        if (! (bool) ($settings['enabled'] ?? false) || ! (bool) ($settings['write_enabled'] ?? false)) {
            throw new DomainException('MIKRO_WRITE_DISABLED');
        }
        if (($operation['contract_status'] ?? null) !== 'VERIFIED' || ! ($operation['runtime_enabled'] ?? false)) {
            throw new DomainException('MIKRO_WRITE_DISABLED');
        }
        if (($operation['approval_required'] ?? true) && ! $approved) {
            throw new DomainException('MIKRO_WRITE_APPROVAL_REQUIRED');
        }
        if (blank($idempotencyKey)) {
            throw new DomainException('MIKRO_RECONCILIATION_REQUIRED');
        }

        return $operation;
    }

    /** @return array<int, array<string, mixed>> */
    public function catalog(array $controls = [], array $runtimeStates = []): array
    {
        $rows = [];
        foreach (self::READ_OPERATIONS as $key => $definition) {
            $rows[] = $this->withRuntimeState($this->applyControl($this->readDescriptor($key, $definition), (array) ($controls[$key] ?? [])), (array) ($runtimeStates[$key] ?? []));
        }
        foreach (self::WRITE_OPERATIONS as $key => $definition) {
            $rows[] = $this->withRuntimeState($this->applyControl($this->writeDescriptor($key, $definition), (array) ($controls[$key] ?? [])), (array) ($runtimeStates[$key] ?? []));
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function summary(array $controls = [], array $runtimeStates = []): array
    {
        $operations = $this->catalog($controls, $runtimeStates);
        $reads = array_values(array_filter($operations, fn (array $row): bool => $row['mode'] === 'READ'));
        $writes = array_values(array_filter($operations, fn (array $row): bool => $row['mode'] === 'WRITE'));

        return [
            'status' => 'active',
            'read_count' => count($reads),
            'write_count' => count($writes),
            'implemented_read_count' => count(array_filter($reads, fn (array $row): bool => $row['implementation_status'] === 'IMPLEMENTED')),
            'enabled_read_count' => count(array_filter($reads, fn (array $row): bool => $row['runtime_enabled'])),
            'enabled_write_count' => count(array_filter($writes, fn (array $row): bool => $row['runtime_enabled'])),
            'direct_endpoint_count' => count(array_filter($reads, fn (array $row): bool => $row['adapter_type'] === 'DIRECT_ENDPOINT')),
            'fixed_query_count' => count(array_filter($reads, fn (array $row): bool => $row['adapter_type'] === 'FIXED_QUERY')),
            'contract_blocked_count' => count(array_filter($operations, fn (array $row): bool => $row['contract_status'] === 'BLOCKED_CONTRACT_MISSING')),
            'enabled_keys' => array_values(array_map(fn (array $row): string => $row['operation_key'], array_filter($reads, fn (array $row): bool => $row['runtime_enabled']))),
            'operations' => $operations,
        ];
    }

    /** @return array<int, string> */
    public function deniedOperations(): array
    {
        return self::DENIED_OPERATIONS;
    }

    public function sourceModeAllowed(string $sourceMode): bool
    {
        return in_array($sourceMode, self::SOURCE_MODES, true);
    }

    public function baseUrlBlocker(?string $baseUrl): ?string
    {
        $baseUrl = trim((string) $baseUrl);
        if ($baseUrl === '') {
            return null;
        }
        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return 'MIKRO_BASE_URL_SCHEME_INVALID';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return 'MIKRO_BASE_URL_AUTHORITY_INVALID';
        }
        if (! in_array((string) ($parts['path'] ?? ''), ['', '/'], true)) {
            return 'MIKRO_BASE_URL_MUST_BE_ORIGIN';
        }
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '') {
            return 'MIKRO_BASE_URL_HOST_MISSING';
        }
        $allowedHosts = array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), (array) config('services.mikro_api.allowed_hosts', []));
        if (in_array($host, array_filter($allowedHosts), true)) {
            return null;
        }
        if (app()->environment(['local', 'testing']) && ($this->isPrivateIp($host) || $this->isLoopback($host) || str_ends_with($host, '.internal') || str_ends_with($host, '.local') || str_ends_with($host, '.example.test'))) {
            return null;
        }

        return 'MIKRO_BASE_URL_PUBLIC_HOST_DENIED';
    }

    /** @return array<string, mixed> */
    private function readDescriptor(string $key, array $definition): array
    {
        $adapter = $definition['adapter'];
        return [
            'operation_key' => $key,
            'display_name' => $definition['name'],
            'category' => $definition['category'],
            'mode' => 'READ',
            'capability_status' => 'LICENSED',
            'contract_status' => $definition['contract'] ?? 'VERIFIED',
            'implementation_status' => $definition['implementation'] ?? 'IMPLEMENTED',
            'runtime_enabled' => $definition['runtime'] ?? true,
            'adapter_type' => $adapter,
            'endpoint' => $adapter === 'DIRECT_ENDPOINT' ? $definition['target'] : ($adapter === 'FIXED_QUERY' ? '/Api/APIMethods/SqlVeriOkuV2' : null),
            'method' => $definition['method'] ?? ($adapter === 'FIXED_QUERY' ? 'POST' : null),
            'payload_style' => $definition['payload_style'] ?? ($adapter === 'FIXED_QUERY' ? 'fixed_query' : null),
            'mikro_method' => $adapter === 'DIRECT_ENDPOINT' ? basename((string) $definition['target']) : null,
            'fixed_query_id' => $adapter === 'FIXED_QUERY' ? $definition['target'] : null,
            'api_version' => 'V17',
            'request_type' => $definition['request'],
            'response_type' => $definition['response'],
            'required_context' => $key === 'health.check' ? [] : ['api_key', 'working_year', 'firm_code', 'user_code', 'password', 'server_timezone'],
            'timeout' => 15,
            'retry_policy' => 'READ_TRANSIENT_MAX_2',
            'circuit_policy' => '3_FAILURES_OPEN_30S_HALF_OPEN_1',
            'cache_policy' => 'LAST_GOOD_24H_EXPLICIT_STALE',
            'source_mode' => $definition['source'],
            'n8n_fallback' => $definition['fallback'],
            'parity_fields' => $definition['parity'],
            'approval_required' => false,
            'blocker' => $definition['blocker'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function writeDescriptor(string $key, array $definition): array
    {
        return [
            'operation_key' => $key,
            'display_name' => $definition['name'],
            'category' => $definition['category'],
            'mode' => 'WRITE',
            'capability_status' => 'LICENSED',
            'contract_status' => $definition['contract'],
            'implementation_status' => $definition['contract'] === 'VERIFIED' ? 'CONTROL_PLANE_READY' : 'BLOCKED',
            'runtime_enabled' => false,
            'adapter_type' => $definition['method'] ? 'DIRECT_ENDPOINT' : 'CONTRACT_BLOCKED',
            'endpoint' => $definition['method'] ? '/Api/APIMethods/'.$definition['method'] : null,
            'method' => $definition['method'] ? 'POST' : null,
            'payload_style' => 'mikro',
            'mikro_method' => $definition['method'],
            'fixed_query_id' => null,
            'api_version' => 'V17',
            'request_type' => 'Mikro'.str_replace(' ', '', ucwords(str_replace(['.', '-'], ' ', $key))).'Command',
            'response_type' => 'MikroWriteCommandResult',
            'required_context' => ['api_key', 'working_year', 'firm_code', 'user_code', 'password', 'server_timezone', 'idempotency_key', 'approval'],
            'timeout' => 15,
            'retry_policy' => 'NO_AUTOMATIC_RETRY_PENDING_RECONCILIATION',
            'circuit_policy' => 'FAIL_CLOSED',
            'cache_policy' => 'NONE',
            'source_mode' => 'disabled',
            'n8n_fallback' => false,
            'parity_fields' => [],
            'approval_required' => true,
            'blocker' => $definition['blocker'] ?? 'Live write execution is disabled for the pilot.',
        ];
    }

    /** @return array<string, mixed> */
    private function applyControl(array $operation, array $control): array
    {
        if (array_key_exists('runtime_enabled', $control)) {
            $operation['runtime_enabled'] = (bool) $control['runtime_enabled'];
        }
        if (($operation['mode'] ?? null) === 'READ' && isset($control['source_mode']) && $this->sourceModeAllowed((string) $control['source_mode'])) {
            $operation['source_mode'] = (string) $control['source_mode'];
        }
        if (($operation['mode'] ?? null) === 'WRITE') {
            $operation['source_mode'] = 'disabled';
            $operation['approval_required'] = true;
            if (($operation['contract_status'] ?? null) !== 'VERIFIED') {
                $operation['runtime_enabled'] = false;
            }
        }

        return $operation;
    }

    /** @return array<string, mixed> */
    private function withRuntimeState(array $operation, array $state): array
    {
        return [
            ...$operation,
            'parity_status' => $state['parity_status'] ?? 'PENDING',
            'last_success_at' => $state['last_success_at'] ?? null,
            'last_error_code' => $state['last_error_code'] ?? null,
            'circuit_state' => $state['circuit_state'] ?? 'CLOSED',
        ];
    }

    private function isLoopback(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_starts_with($host, '127.');
    }

    private function isPrivateIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $value = ip2long($host);
            return $value !== false && (($value >= ip2long('10.0.0.0') && $value <= ip2long('10.255.255.255')) || ($value >= ip2long('172.16.0.0') && $value <= ip2long('172.31.255.255')) || ($value >= ip2long('192.168.0.0') && $value <= ip2long('192.168.255.255')));
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return str_starts_with(strtolower($host), 'fc') || str_starts_with(strtolower($host), 'fd');
        }

        return false;
    }
}
