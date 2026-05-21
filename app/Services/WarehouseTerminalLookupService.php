<?php

namespace App\Services;

use App\Models\DataSource;
use RuntimeException;

class WarehouseTerminalLookupService
{
    private const SOURCE_WAREHOUSES = 'warehouse_terminal_warehouses';
    private const SOURCE_RACKS = 'warehouse_terminal_racks';
    private const SOURCE_ITEMS = 'warehouse_terminal_items';

    public function __construct(
        private readonly PanelDataSourceManager $dataSources,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function warehouses(): array
    {
        $rows = $this->gatewayRows(self::SOURCE_WAREHOUSES, []);

        return [
            'items' => array_values(array_filter(array_map(fn (array $row): ?array => $this->warehouseRow($row), $rows['rows']))),
            'source' => $rows['source'],
            'message' => $rows['message'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function racks(int $warehouseNo, string $type): array
    {
        $rows = $this->gatewayRows(self::SOURCE_RACKS, [
            'warehouse_no' => $warehouseNo,
            'type' => $type,
            'hgrp_no' => $type === 'source' ? 2 : 3,
        ]);

        return [
            'items' => array_values(array_filter(array_map(fn (array $row): ?array => $this->rackRow($row), $rows['rows']))),
            'source' => $rows['source'],
            'message' => $rows['message'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function items(int $warehouseNo, string $query): array
    {
        $rows = $this->gatewayRows(self::SOURCE_ITEMS, [
            'warehouse_no' => $warehouseNo,
            'q' => trim($query),
        ]);

        return [
            'items' => array_values(array_filter(array_map(fn (array $row): ?array => $this->itemRow($row), $rows['rows']))),
            'source' => $rows['source'],
            'message' => $rows['message'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{rows: array<int, array<string, mixed>>, source: string, message: ?string}
     */
    private function gatewayRows(string $sourceCode, array $payload): array
    {
        $source = DataSource::query()->where('code', $sourceCode)->where('active', true)->first();

        if (! $source) {
            return [
                'rows' => [],
                'source' => 'not_configured',
                'message' => "Mikro lookup veri kaynağı tanımlı değil: {$sourceCode}",
            ];
        }

        $result = $this->dataSources->execute($source, $payload);
        $rows = $result['rows'] ?? [];

        if (! is_array($rows)) {
            throw new RuntimeException("Mikro lookup geçersiz satır döndürdü: {$sourceCode}");
        }

        return [
            'rows' => array_values(array_filter($rows, 'is_array')),
            'source' => $sourceCode,
            'message' => null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function warehouseRow(array $row): ?array
    {
        $warehouseNo = $this->integerValue($row, ['warehouse_no', 'depo_no', 'DepoNo', 'dep_no']);

        if ($warehouseNo === null) {
            return null;
        }

        return [
            'warehouse_no' => $warehouseNo,
            'warehouse_name' => $this->stringValue($row, ['warehouse_name', 'depo_adi', 'DepoAdi', 'name']) ?? (string) $warehouseNo,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function rackRow(array $row): ?array
    {
        $rackCode = $this->stringValue($row, ['rack_code', 'raf_kodu', 'hgrp_kodu', 'hareket_grup_kodu', 'code']);

        if ($rackCode === null) {
            return null;
        }

        return [
            'rack_code' => $rackCode,
            'rack_name' => $this->stringValue($row, ['rack_name', 'raf_adi', 'hgrp_adi', 'hareket_grup_adi', 'name']) ?? $rackCode,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function itemRow(array $row): ?array
    {
        $stockCode = $this->stringValue($row, ['stock_code', 'stok_kodu', 'StokKodu']);
        $serialNo = $this->stringValue($row, ['serial_no', 'seri_no', 'cihaz_seri_no']);
        $barcode = $this->stringValue($row, ['barcode', 'barkod', 'barkod_no']);

        if ($stockCode === null && $serialNo === null && $barcode === null) {
            return null;
        }

        $stockName = $this->stringValue($row, ['stock_name', 'stok_adi', 'StokAdi']);
        $matchType = $this->stringValue($row, ['match_type', 'eslesme_tipi']) ?? ($serialNo ? 'serial' : ($barcode ? 'barcode' : 'stock_code'));
        $serialTracked = $this->booleanValue($row['is_serial_tracked'] ?? $row['seri_takipli'] ?? $serialNo !== null);
        $labelParts = array_filter([$stockCode, $stockName, $serialNo, $barcode]);

        return [
            'match_type' => $matchType,
            'stock_code' => $stockCode,
            'stock_name' => $stockName,
            'barcode' => $barcode,
            'serial_no' => $serialNo,
            'is_serial_tracked' => $serialTracked,
            'display_label' => $this->stringValue($row, ['display_label', 'label']) ?? implode(' - ', $labelParts),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function stringValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $value = trim((string) $row[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function integerValue(array $row, array $keys): ?int
    {
        $value = $this->stringValue($row, $keys);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'evet'], true);
        }

        return false;
    }
}
