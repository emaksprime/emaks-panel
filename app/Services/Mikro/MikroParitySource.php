<?php

namespace App\Services\Mikro;

use DomainException;

enum MikroParitySource: string
{
    case CUSTOMER_DISCOVERY = 'parity_customer_discovery';
    case CUSTOMER_DETAIL = 'parity_customer_detail';
    case STOCK_DISCOVERY = 'parity_stock_discovery';
    case STOCK_DETAIL = 'parity_stock_availability';
    case SERIAL_DISCOVERY = 'parity_serial_discovery';
    case SERIAL_DETAIL = 'parity_serial_detail';
    case ORDER_DISCOVERY = 'parity_order_discovery';
    case ORDER_DETAIL = 'parity_order_detail';

    public function operationKey(): string
    {
        return match ($this) {
            self::CUSTOMER_DISCOVERY, self::CUSTOMER_DETAIL => 'customer.lookup',
            self::STOCK_DISCOVERY, self::STOCK_DETAIL => 'stock.availability',
            self::SERIAL_DISCOVERY, self::SERIAL_DETAIL => 'serial.lookup',
            self::ORDER_DISCOVERY, self::ORDER_DETAIL => 'order.detail',
        };
    }

    public function queryId(): string
    {
        return match ($this) {
            self::CUSTOMER_DISCOVERY => 'parity.customer.discovery.v1',
            self::CUSTOMER_DETAIL => 'parity.customer.detail.v1',
            self::STOCK_DISCOVERY => 'parity.stock.discovery.v1',
            self::STOCK_DETAIL => 'parity.stock.detail.v1',
            self::SERIAL_DISCOVERY => 'parity.serial.discovery.v1',
            self::SERIAL_DETAIL => 'parity.serial.detail.v1',
            self::ORDER_DISCOVERY => 'parity.order.discovery.v1',
            self::ORDER_DETAIL => 'parity.order.detail.v1',
        };
    }

    public function phase(): string
    {
        return str_ends_with($this->name, '_DISCOVERY') ? 'discovery' : 'detail';
    }

    public static function discoveryFor(string $operationKey): self
    {
        return match ($operationKey) {
            'customer.lookup' => self::CUSTOMER_DISCOVERY,
            'stock.availability' => self::STOCK_DISCOVERY,
            'serial.lookup' => self::SERIAL_DISCOVERY,
            'order.detail' => self::ORDER_DISCOVERY,
            default => throw new DomainException('MIKRO_PARITY_OPERATION_NOT_ALLOWED'),
        };
    }

    public static function detailFor(string $operationKey): self
    {
        return match ($operationKey) {
            'customer.lookup' => self::CUSTOMER_DETAIL,
            'stock.availability' => self::STOCK_DETAIL,
            'serial.lookup' => self::SERIAL_DETAIL,
            'order.detail' => self::ORDER_DETAIL,
            default => throw new DomainException('MIKRO_PARITY_OPERATION_NOT_ALLOWED'),
        };
    }
}
