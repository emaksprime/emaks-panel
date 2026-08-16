<?php

namespace App\Services\Mikro;

use DomainException;

class MikroOperationRegistry
{
    public const BLOCKED_UNKNOWN = 'MIKRO_UNKNOWN_OPERATION';

    public const BLOCKED_DENIED = 'MIKRO_OPERATION_NOT_ALLOWED';

    public const BLOCKED_DISABLED = 'MIKRO_OPERATION_DISABLED';

    public const BLOCKED_SERVER_CANARY = 'MIKRO_OPERATION_SERVER_CANARY_REQUIRED';

    public const BLOCKED_RESPONSE_SCHEMA = 'MIKRO_RESPONSE_SCHEMA_UNVERIFIED';

    public const BLOCKED_CANARY_OPERATION = 'MIKRO_CANARY_OPERATION_NOT_ALLOWED';

    public const SOURCE_MODES = ['mikro', 'n8n', 'shadow_compare', 'disabled'];

    /** @var array<string, string> */
    private const AUTHENTICATED_READ_CANARY_ALIASES = [
        'customer.lookup' => 'customer.detail',
        'stock.search' => 'stock.search',
        'stock.physical_quantity' => 'stock.physical_quantity',
        'stock.availability' => 'stock.availability',
        'serial.lookup' => 'serial.lookup',
        'order.detail' => 'order.detail',
    ];

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
        'stock.search' => ['name' => 'Technical service part search', 'category' => 'stock', 'adapter' => 'FIXED_QUERY', 'target' => 'stock.search', 'request' => 'MikroStockSearchQuery', 'response' => 'MikroStockSearchResult', 'source' => 'mikro', 'fallback' => false, 'parity' => ['item_code', 'item_name', 'item_short_name', 'stock_type', 'detail_tracking_type']],
        'stock.physical_quantity' => ['name' => 'Technical service part physical stock', 'category' => 'stock', 'adapter' => 'FIXED_QUERY', 'target' => 'stock.physical_quantity', 'request' => 'MikroPhysicalStockBatchQuery', 'response' => 'MikroPhysicalStockBatchResult', 'source' => 'mikro', 'fallback' => false, 'parity' => ['item_code', 'unit_code', 'warehouse_code', 'physical_quantity']],
        'stock.tax_profile' => ['name' => 'Technical service part tax profile', 'category' => 'stock', 'adapter' => 'FIXED_QUERY', 'target' => 'stock.tax_profile', 'request' => 'MikroStockTaxProfileBatchQuery', 'response' => 'MikroStockTaxProfileBatchResult', 'source' => 'mikro', 'fallback' => false, 'parity' => ['item_code', 'retail_tax_pointer', 'retail_tax_rate', 'wholesale_tax_pointer', 'wholesale_tax_rate', 'selected_tax_basis', 'selected_tax_rate']],
        'stock.availability' => ['name' => 'Stock availability', 'category' => 'stock', 'adapter' => 'FIXED_QUERY', 'target' => 'stock.availability', 'request' => 'MikroStockAvailabilityQuery', 'response' => 'MikroStockAvailabilityResult', 'source' => 'shadow_compare', 'fallback' => true, 'parity' => ['stock_code', 'depot_1_quantity', 'depot_5_quantity', 'available_quantity']],
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
        'proforma.list' => ['name' => 'Proforma list', 'category' => 'proforma', 'adapter' => 'CONTRACT_BLOCKED', 'target' => null, 'request' => 'MikroProformaListQuery', 'response' => 'MikroProformaListResult', 'source' => 'disabled', 'fallback' => true, 'parity' => [], 'contract' => 'CONTRACT_BLOCKED', 'implementation' => 'BLOCKED', 'runtime' => false],
        'proforma.detail' => ['name' => 'Proforma detail', 'category' => 'proforma', 'adapter' => 'CONTRACT_BLOCKED', 'target' => null, 'request' => 'MikroProformaDetailQuery', 'response' => 'MikroProformaDetailResult', 'source' => 'disabled', 'fallback' => true, 'parity' => [], 'contract' => 'CONTRACT_BLOCKED', 'implementation' => 'BLOCKED', 'runtime' => false],
    ];

    /** @var array<string, array<string, mixed>> */
    private const WRITE_OPERATIONS = [
        'customer.save' => ['name' => 'Save customer', 'category' => 'customer', 'method' => 'CariKaydetV2', 'contract' => 'DOCUMENTED'],
        'order.save' => ['name' => 'Save order', 'category' => 'order', 'method' => 'SiparisKaydetV2', 'contract' => 'DOCUMENTED'],
        'invoice.create' => ['name' => 'Create invoice', 'category' => 'invoice', 'method' => 'FaturaKaydetV3', 'contract' => 'DOCUMENTED'],
        'dispatch.create' => ['name' => 'Create dispatch', 'category' => 'dispatch', 'method' => 'IrsaliyeKaydetV2', 'contract' => 'DOCUMENTED'],
        'record.link.save' => ['name' => 'Save linked record', 'category' => 'record', 'method' => 'KayitKaydetV2', 'contract' => 'DOCUMENTED'],
        'record.bulk.save' => ['name' => 'Save record batch', 'category' => 'record', 'method' => 'KayitKaydetTopluV2', 'contract' => 'DOCUMENTED'],
        'stock.transfer.create' => ['name' => 'Create internal stock movement', 'category' => 'stock', 'method' => 'DahiliStokHareketKaydetV2', 'contract' => 'DOCUMENTED'],
        'order.dispatch.legacy.create' => ['name' => 'Legacy order dispatch', 'category' => 'dispatch', 'method' => 'SiparistenIrsaliyeOlusturmaV2', 'contract' => 'DOCUMENTED'],
        'proforma.create' => ['name' => 'Create proforma', 'category' => 'proforma', 'method' => 'ProformaSiparisKaydetV2', 'contract' => 'DOCUMENTED'],
        'return.create' => ['name' => 'Create return', 'category' => 'return', 'method' => null, 'contract' => 'CONTRACT_BLOCKED'],
        'exchange.create' => ['name' => 'Create exchange', 'category' => 'exchange', 'method' => null, 'contract' => 'CONTRACT_BLOCKED'],
    ];

    private const DENIED_OPERATIONS = [
        'generic.call', 'sql.read', 'sql.write', 'raw.sql', 'table.read', 'table.write',
        'record.save', 'record.update', 'record.delete', 'stock.save', 'stock.movement.create',
        'order.create', 'order.update', 'order.delete',
    ];

    public function __construct(
        private readonly MikroResponseSchemaCatalog $responseSchemas,
        private readonly MikroFixedQueryCatalog $fixedQueries,
    ) {}

    /** @return array<string, mixed> */
    public function read(string $operationKey): array
    {
        $operation = $this->operation($operationKey);
        if (($operation['mode'] ?? null) !== 'READ') {
            throw new DomainException(self::BLOCKED_DENIED);
        }
        if (($operation['contract_status'] ?? null) === 'CONTRACT_BLOCKED' || ($operation['implementation_status'] ?? null) !== 'IMPLEMENTED') {
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
        $control = (array) ($settings['operation_controls'][$operationKey] ?? []);
        $operation = $this->applyControl($this->read($operationKey), $control);
        $isHealthCheck = $operationKey === 'health.check';
        $explicitOnDemandRead = array_key_exists('runtime_enabled', $control)
            && (bool) $control['runtime_enabled'];
        if (! $isHealthCheck && ! (bool) ($settings['enabled'] ?? false)) {
            throw new DomainException('MIKRO_DISABLED');
        }
        if (! $isHealthCheck
            && ! (bool) ($settings['read_sync_enabled'] ?? false)
            && ! $explicitOnDemandRead) {
            throw new DomainException('MIKRO_READ_SYNC_DISABLED');
        }
        if (($operation['response_schema_status'] ?? null) !== MikroResponseSchemaCatalog::VERIFIED) {
            throw new DomainException(self::BLOCKED_RESPONSE_SCHEMA);
        }
        if (! ($operation['runtime_eligible'] ?? false)) {
            throw new DomainException(self::BLOCKED_SERVER_CANARY);
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
    public function assertCanaryAllowed(string $requestedOperationKey, array $context): array
    {
        $canonicalOperationKey = self::AUTHENTICATED_READ_CANARY_ALIASES[$requestedOperationKey] ?? null;
        if (! is_string($canonicalOperationKey)) {
            throw new DomainException(self::BLOCKED_CANARY_OPERATION);
        }

        $operation = $this->read($canonicalOperationKey);
        if (($operation['response_schema_status'] ?? null) !== MikroResponseSchemaCatalog::VERIFIED) {
            throw new DomainException(self::BLOCKED_RESPONSE_SCHEMA);
        }
        if (! (bool) ($context['live_configuration_ready'] ?? false)) {
            throw new DomainException('MIKRO_LIVE_CONFIGURATION_MISSING');
        }
        if (! (bool) ($context['health_ready'] ?? false)) {
            throw new DomainException('MIKRO_PRIVATE_HEALTH_NOT_READY');
        }
        if ((bool) ($context['write_enabled'] ?? false)) {
            throw new DomainException('MIKRO_CANARY_REQUIRES_WRITE_DISABLED');
        }
        if (blank($context['base_url'] ?? null) || $this->baseUrlBlocker($context['base_url'] ?? null) !== null) {
            throw new DomainException('MIKRO_INVALID_BASE_URL');
        }
        if (! in_array($operation['adapter_type'] ?? null, ['DIRECT_ENDPOINT', 'FIXED_QUERY'], true)) {
            throw new DomainException(self::BLOCKED_CANARY_OPERATION);
        }
        if (($operation['adapter_type'] ?? null) === 'FIXED_QUERY') {
            $queryId = $operation['fixed_query_id'] ?? null;
            if (! is_string($queryId) || $queryId !== $canonicalOperationKey) {
                throw new DomainException('MIKRO_FIXED_QUERY_UNKNOWN');
            }
            $this->fixedQueries->definition($queryId);
        }

        return [
            ...$operation,
            'requested_operation_key' => $requestedOperationKey,
            'canonical_operation_key' => $canonicalOperationKey,
            'canary_eligible' => true,
        ];
    }

    /** @return array{allowed:bool,operations:array<string, array<string, mixed>>,blocker_codes:array<int, string>} */
    public function canaryEligibility(array $context): array
    {
        $operations = [];
        $blockers = [];
        foreach (array_keys(self::AUTHENTICATED_READ_CANARY_ALIASES) as $requestedOperationKey) {
            try {
                $operation = $this->assertCanaryAllowed($requestedOperationKey, $context);
                $operations[$requestedOperationKey] = [
                    'allowed' => true,
                    'canonical_operation_key' => $operation['canonical_operation_key'],
                    'adapter_type' => $operation['adapter_type'],
                    'fixed_query_id' => $operation['fixed_query_id'],
                    'blocker' => null,
                ];
            } catch (DomainException $exception) {
                $blockers[] = $exception->getMessage();
                $operations[$requestedOperationKey] = [
                    'allowed' => false,
                    'canonical_operation_key' => self::AUTHENTICATED_READ_CANARY_ALIASES[$requestedOperationKey],
                    'adapter_type' => null,
                    'fixed_query_id' => null,
                    'blocker' => $exception->getMessage(),
                ];
            }
        }

        $blockers = array_values(array_unique($blockers));

        return ['allowed' => $blockers === [], 'operations' => $operations, 'blocker_codes' => $blockers];
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
        if (! ($operation['runtime_eligible'] ?? false) || ! ($operation['runtime_enabled'] ?? false)) {
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
            'contract_blocked_count' => count(array_filter($operations, fn (array $row): bool => $row['contract_status'] === 'CONTRACT_BLOCKED')),
            'server_verified_read_count' => count(array_filter($reads, fn (array $row): bool => in_array($row['evidence_status'], ['OFFICIAL_AND_SERVER_VERIFIED', 'DEPOT_AND_SERVER_VERIFIED'], true))),
            'server_unverified_count' => count(array_filter($operations, fn (array $row): bool => $row['evidence_status'] === 'DOCUMENTED_SERVER_UNVERIFIED')),
            'runtime_eligible_read_count' => count(array_filter($reads, fn (array $row): bool => $row['runtime_eligible'])),
            'response_schema_verified_count' => count(array_filter($reads, fn (array $row): bool => $row['response_schema_status'] === MikroResponseSchemaCatalog::VERIFIED)),
            'response_schema_missing_count' => count(array_filter($reads, fn (array $row): bool => $row['response_schema_status'] === MikroResponseSchemaCatalog::MISSING)),
            'parity_status_counts' => array_count_values(array_map(fn (array $row): string => (string) $row['business_parity_source']['status'], $operations)),
            'matrix_complete' => count($operations) === 46 && count(array_filter($operations, fn (array $row): bool => in_array($row['evidence_status'], MikroContractEvidenceCatalog::ALLOWED_STATUSES, true)
                && preg_match('/^[a-f0-9]{64}$/', (string) $row['evidence_hash']) === 1
                && is_array($row['business_parity_source'])
                && in_array($row['business_parity_source']['status'] ?? null, MikroContractEvidenceCatalog::PARITY_STATUSES, true))) === 46,
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
        $evidence = MikroContractEvidenceCatalog::for($key, 'READ', $adapter);
        $schema = $this->responseSchemas->descriptor($key);
        $fixedQuery = $adapter === 'FIXED_QUERY'
            ? $this->fixedQueries->definition((string) $definition['target'])
            : null;
        $runtimeEligible = (bool) $evidence['runtime_eligible']
            && $schema['schema_status'] === MikroResponseSchemaCatalog::VERIFIED;

        return [
            'operation_key' => $key,
            'display_name' => $definition['name'],
            'category' => $definition['category'],
            'mode' => 'READ',
            'capability_status' => 'LICENSED',
            'contract_status' => $evidence['contract_status'],
            'evidence_status' => $evidence['evidence_status'],
            'implementation_status' => $evidence['contract_status'] === 'CONTRACT_BLOCKED' ? 'BLOCKED' : ($definition['implementation'] ?? 'IMPLEMENTED'),
            'runtime_eligible' => $runtimeEligible,
            'runtime_enabled' => $runtimeEligible && (bool) $evidence['runtime_enabled'],
            'adapter_type' => $evidence['contract_status'] === 'CONTRACT_BLOCKED' ? 'CONTRACT_BLOCKED' : $adapter,
            'endpoint' => $evidence['exact_path'],
            'method' => $evidence['exact_http_method'],
            'payload_style' => $definition['payload_style'] ?? ($adapter === 'FIXED_QUERY' ? 'fixed_query' : null),
            'mikro_method' => $evidence['exact_path'] === null ? null : basename((string) $evidence['exact_path']),
            'fixed_query_id' => $adapter === 'FIXED_QUERY' ? $definition['target'] : null,
            'supporting_endpoint' => is_array($fixedQuery) ? ($fixedQuery['rate_endpoint'] ?? null) : null,
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
            'response_schema_status' => $schema['schema_status'],
            'normalizer_id' => $schema['normalizer_id'],
            'allowed_response_fields' => $schema['allowed_record_fields'],
            'snapshot_allowed' => $schema['snapshot_allowed'],
            'approval_required' => false,
            'blocker' => $schema['blocker'] ?? $evidence['blocker'] ?? $definition['blocker'] ?? null,
            'evidence_blocker' => $evidence['blocker'] ?? null,
            'response_schema_blocker' => $schema['blocker'],
            ...$this->evidenceFields($evidence),
        ];
    }

    /** @return array<string, mixed> */
    private function writeDescriptor(string $key, array $definition): array
    {
        $evidence = MikroContractEvidenceCatalog::for($key, 'WRITE', $definition['method'] ? 'DIRECT_ENDPOINT' : 'CONTRACT_BLOCKED');

        return [
            'operation_key' => $key,
            'display_name' => $definition['name'],
            'category' => $definition['category'],
            'mode' => 'WRITE',
            'capability_status' => 'LICENSED',
            'contract_status' => $evidence['contract_status'],
            'evidence_status' => $evidence['evidence_status'],
            'implementation_status' => $evidence['contract_status'] === 'CONTRACT_BLOCKED' ? 'BLOCKED' : 'CONTROL_PLANE_READY',
            'runtime_eligible' => false,
            'runtime_enabled' => false,
            'adapter_type' => $evidence['contract_status'] === 'CONTRACT_BLOCKED' ? 'CONTRACT_BLOCKED' : 'DIRECT_ENDPOINT',
            'endpoint' => $evidence['exact_path'],
            'method' => $evidence['exact_http_method'],
            'payload_style' => 'mikro',
            'mikro_method' => $evidence['exact_path'] === null ? null : basename((string) $evidence['exact_path']),
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
            'response_schema_status' => MikroResponseSchemaCatalog::MISSING,
            'normalizer_id' => null,
            'allowed_response_fields' => [],
            'snapshot_allowed' => false,
            'approval_required' => true,
            'blocker' => $evidence['blocker'],
            ...$this->evidenceFields($evidence),
        ];
    }

    /** @return array<string, mixed> */
    private function applyControl(array $operation, array $control): array
    {
        if (array_key_exists('runtime_enabled', $control)) {
            $operation['runtime_enabled'] = (bool) ($operation['runtime_eligible'] ?? false)
                && (bool) $control['runtime_enabled'];
        }
        if (($operation['mode'] ?? null) === 'READ' && isset($control['source_mode']) && $this->sourceModeAllowed((string) $control['source_mode'])) {
            $operation['source_mode'] = (string) $control['source_mode'];
        }
        if (($operation['mode'] ?? null) === 'WRITE') {
            $operation['source_mode'] = 'disabled';
            $operation['approval_required'] = true;
            $operation['runtime_enabled'] = false;
        }

        return $operation;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function evidenceFields(array $evidence): array
    {
        return [
            'official_doc_reference' => $evidence['official_api_page'],
            'official_api_page' => $evidence['official_api_page'],
            'official_changelog_reference' => $evidence['official_changelog_reference'],
            'official_postman_item' => $evidence['official_postman_item'],
            'local_postman_item' => $evidence['local_postman_item'],
            'official_method' => $evidence['exact_http_method'],
            'exact_path' => $evidence['exact_path'],
            'exact_path_casing' => $evidence['exact_path_casing'],
            'request_schema' => $evidence['request_root_keys'],
            'response_schema' => $evidence['response_root_keys'],
            'request_root_keys' => $evidence['request_root_keys'],
            'response_root_keys' => $evidence['response_root_keys'],
            'source_document' => $evidence['source_document'],
            'source_documents' => $evidence['source_documents'],
            'source_item_category' => $evidence['source_item_category'],
            'depot_evidence' => $evidence['depot_source_file'],
            'depot_source_file' => $evidence['depot_source_file'],
            'depot_method' => $evidence['depot_method'],
            'installed_server_canary' => $evidence['installed_server_canary'],
            'v17_table_evidence' => array_values(array_filter($evidence['source_documents'], fn (array $source): bool => ($source['type'] ?? null) === 'fly_v17_table')),
            'business_parity_source' => $evidence['business_parity_source'],
            'evidence_hash' => $evidence['evidence_hash'],
            'api_key_field' => $evidence['api_key_field'],
            'contract_version' => $evidence['contract_version'] ?? null,
            'response_schema_fingerprint' => $evidence['response_schema_fingerprint'] ?? null,
            'not_found_schema_fingerprint' => $evidence['not_found_schema_fingerprint'] ?? null,
        ];
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
