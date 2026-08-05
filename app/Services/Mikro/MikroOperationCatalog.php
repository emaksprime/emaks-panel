<?php

namespace App\Services\Mikro;

final class MikroOperationCatalog implements MikroOperationCatalogInterface
{
    public const HEALTH_CHECK = 'health.check';

    public const STOCK_LIST = 'stock.list';

    public const HEALTH_METHOD = 'GET';

    public const STOCK_LIST_METHOD = 'POST';

    public const HEALTH_ENDPOINT = '/Api/APIMethods/HealthCheck';

    public const STOCK_LIST_ENDPOINT = '/Api/APIMethods/StokListesiV2';

    /**
     * @return list<MikroOperationDefinition>
     */
    public function all(): array
    {
        return [
            self::read(self::HEALTH_CHECK, 'Health check', MikroOperationDefinition::VERIFICATION_BLOCKED, self::HEALTH_METHOD, self::HEALTH_ENDPOINT),
            self::read('user.parameters', 'User parameters'),
            self::read('user.list', 'User list'),
            self::read('customer.list', 'Customer list'),
            self::read(self::STOCK_LIST, 'Stock list', MikroOperationDefinition::VERIFICATION_BLOCKED, self::STOCK_LIST_METHOD, self::STOCK_LIST_ENDPOINT, MikroStockListRequest::MAX_CANARY_ROWS),
            self::read('invoice.pdf', 'Invoice PDF'),
            self::read('dispatch.pdf', 'Dispatch PDF'),
            self::read('edocument.status', 'Electronic document status'),
            self::read('etaxpayer.check', 'Electronic taxpayer check'),

            self::disabledRead('customer.detail', 'Customer detail'),
            self::disabledRead('customer.balance', 'Customer balance'),
            self::disabledRead('customer.document.timeline', 'Customer document timeline'),
            self::disabledRead('stock.availability', 'Stock availability'),
            self::disabledRead('stock.movement.list', 'Stock movement list'),
            self::disabledRead('serial.lookup', 'Serial lookup'),
            self::disabledRead('serial.history', 'Serial history'),
            self::disabledRead('order.list', 'Order list'),
            self::disabledRead('order.detail', 'Order detail'),
            self::disabledRead('order.lines', 'Order lines'),
            self::disabledRead('order.remaining.quantity', 'Order remaining quantity'),
            self::disabledRead('invoice.list', 'Invoice list'),
            self::disabledRead('invoice.detail', 'Invoice detail'),
            self::disabledRead('invoice.lines', 'Invoice lines'),
            self::disabledRead('dispatch.list', 'Dispatch list'),
            self::disabledRead('dispatch.detail', 'Dispatch detail'),
            self::disabledRead('dispatch.lines', 'Dispatch lines'),
            self::disabledRead('return.list', 'Return list'),
            self::disabledRead('return.detail', 'Return detail'),
            self::disabledRead('exchange.status', 'Exchange status'),
            self::disabledRead('replacement.serial.lookup', 'Replacement serial lookup'),

            self::read('proforma.list', 'Proforma list', MikroOperationDefinition::VERIFICATION_BLOCKED),
            self::read('proforma.detail', 'Proforma detail', MikroOperationDefinition::VERIFICATION_BLOCKED),

            self::write('customer.save', 'Customer save'),
            self::write('order.save', 'Order save'),
            self::write('invoice.create', 'Invoice create'),
            self::write('dispatch.create', 'Dispatch create'),
            self::write('record.link.save', 'Record link save'),
            self::write('record.bulk.save', 'Record bulk save'),
            self::write('stock.transfer.create', 'Stock transfer create'),
            self::write('order.dispatch.legacy.create', 'Order dispatch compatibility create'),
            self::write('proforma.create', 'Proforma create'),
            self::write('return.create', 'Return create'),
            self::write('exchange.create', 'Exchange create'),
        ];
    }

    public function find(string $code): ?MikroOperationDefinition
    {
        foreach ($this->all() as $operation) {
            if ($operation->code === $code) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * @return list<MikroOperationDefinition>
     */
    public function readOperations(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (MikroOperationDefinition $operation): bool => $operation->isRead(),
        ));
    }

    /**
     * @return list<MikroOperationDefinition>
     */
    public function writeOperations(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (MikroOperationDefinition $operation): bool => $operation->isWrite(),
        ));
    }

    /**
     * @return array{declared_read_count: int, declared_write_count: int, contract_verified_count: int, runtime_verified_count: int, enabled_write_count: int, write_gate: string}
     */
    public function summary(): array
    {
        $all = $this->all();

        return [
            'declared_read_count' => count($this->readOperations()),
            'declared_write_count' => count($this->writeOperations()),
            'contract_verified_count' => count(array_filter($all, fn (MikroOperationDefinition $operation): bool => $operation->isContractVerified())),
            'runtime_verified_count' => count(array_filter($all, fn (MikroOperationDefinition $operation): bool => $operation->isRuntimeVerified())),
            'enabled_write_count' => 0,
            'write_gate' => 'CLOSED',
        ];
    }

    private static function read(
        string $code,
        string $title,
        string $verification = MikroOperationDefinition::VERIFICATION_DECLARED,
        ?string $method = null,
        ?string $endpoint = null,
        ?int $maxRows = null,
    ): MikroOperationDefinition {
        return new MikroOperationDefinition(
            code: $code,
            title: $title,
            classification: MikroOperationDefinition::CLASSIFICATION_READ,
            verification: $verification,
            method: $method,
            endpoint: $endpoint,
            maxRows: $maxRows,
        );
    }

    private static function disabledRead(string $code, string $title): MikroOperationDefinition
    {
        return self::read($code, $title, MikroOperationDefinition::VERIFICATION_LEGACY_DISABLED);
    }

    private static function write(string $code, string $title): MikroOperationDefinition
    {
        return new MikroOperationDefinition(
            code: $code,
            title: $title,
            classification: MikroOperationDefinition::CLASSIFICATION_WRITE,
            verification: MikroOperationDefinition::VERIFICATION_DECLARED,
            requiresWriteGate: true,
        );
    }
}
