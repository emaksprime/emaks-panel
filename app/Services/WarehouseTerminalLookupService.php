<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\WarehouseRack;
use App\Models\WarehouseSerialLocation;
use App\Models\WarehouseStockLocation;
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
        $rows = $this->gatewayRows(
            self::SOURCE_WAREHOUSES,
            [],
            fn (): array => $this->fallbackWarehouses(),
            'Depo listesi boş. Önce lokasyon kaydı veya Mikro lookup kaynağı tanımı gerekir.',
        );

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
        $rows = $this->gatewayRows(
            self::SOURCE_RACKS,
            [
                'warehouse_no' => $warehouseNo,
                'type' => $type,
                'hgrp_no' => $type === 'source' ? 2 : 3,
            ],
            fn (): array => $this->fallbackRacks($warehouseNo),
            'Raf listesi boş. Önce lokasyon kaydı veya Mikro lookup kaynağı tanımı gerekir.',
        );

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
        $rows = $this->gatewayRows(
            self::SOURCE_ITEMS,
            [
                'warehouse_no' => $warehouseNo,
                'q' => trim($query),
            ],
            fn (): array => $this->fallbackItems($warehouseNo, trim($query)),
            'Ürün arama sonucu bulunamadı.',
        );

        return [
            'items' => array_values(array_filter(array_map(fn (array $row): ?array => $this->itemRow($row), $rows['rows']))),
            'source' => $rows['source'],
            'message' => $rows['message'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param callable(): array<int, array<string, mixed>> $fallback
     * @return array{rows: array<int, array<string, mixed>>, source: string, message: ?string}
     */
    private function gatewayRows(string $sourceCode, array $payload, callable $fallback, string $emptyMessage): array
    {
        $source = DataSource::query()->where('code', $sourceCode)->first();

        if (! $source || ! $source->active) {
            return $this->fallbackRows($fallback, $emptyMessage);
        }

        try {
            $result = $this->dataSources->execute($source, $payload);
            $rows = $result['rows'] ?? [];
        } catch (RuntimeException) {
            return $this->fallbackRows($fallback, $emptyMessage);
        }

        if (! is_array($rows)) {
            return $this->fallbackRows($fallback, $emptyMessage);
        }

        return [
            'rows' => array_values(array_filter($rows, 'is_array')),
            'source' => 'mikro',
            'message' => null,
        ];
    }

    /**
     * @param callable(): array<int, array<string, mixed>> $fallback
     * @return array{rows: array<int, array<string, mixed>>, source: string, message: ?string}
     */
    private function fallbackRows(callable $fallback, string $emptyMessage): array
    {
        $rows = $fallback();

        return [
            'rows' => $rows,
            'source' => 'panel_fallback',
            'message' => $rows === [] ? $emptyMessage : 'Mikro lookup kaynağı bulunamadı; panel lokasyon kayıtları gösteriliyor.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fallbackWarehouses(): array
    {
        return collect()
            ->merge(WarehouseRack::query()->whereNotNull('warehouse_no')->pluck('warehouse_no'))
            ->merge(WarehouseSerialLocation::query()->whereNotNull('warehouse_no')->pluck('warehouse_no'))
            ->merge(WarehouseStockLocation::query()->whereNotNull('warehouse_no')->pluck('warehouse_no'))
            ->map(fn (mixed $warehouseNo): int => (int) $warehouseNo)
            ->filter(fn (int $warehouseNo): bool => $warehouseNo > 0)
            ->unique()
            ->sort()
            ->values()
            ->map(fn (int $warehouseNo): array => [
                'warehouse_no' => $warehouseNo,
                'warehouse_name' => "Depo {$warehouseNo}",
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fallbackRacks(int $warehouseNo): array
    {
        $rackNames = WarehouseRack::query()
            ->where('warehouse_no', $warehouseNo)
            ->get(['rack_code', 'rack_name'])
            ->mapWithKeys(fn (WarehouseRack $rack): array => [
                $rack->rack_code => $rack->rack_name ?: $rack->rack_code,
            ]);

        return collect()
            ->merge($rackNames->keys())
            ->merge(WarehouseSerialLocation::query()
                ->where('warehouse_no', $warehouseNo)
                ->whereNotNull('rack_code')
                ->pluck('rack_code'))
            ->merge(WarehouseStockLocation::query()
                ->where('warehouse_no', $warehouseNo)
                ->whereNotNull('rack_code')
                ->pluck('rack_code'))
            ->map(fn (mixed $rackCode): string => trim((string) $rackCode))
            ->filter(fn (string $rackCode): bool => $rackCode !== '')
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $rackCode): array => [
                'rack_code' => $rackCode,
                'rack_name' => (string) ($rackNames[$rackCode] ?? $rackCode),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fallbackItems(int $warehouseNo, string $query): array
    {
        $needle = '%'.strtolower($query).'%';

        $serialItems = WarehouseSerialLocation::query()
            ->where('warehouse_no', $warehouseNo)
            ->where(function ($builder) use ($needle): void {
                $builder
                    ->whereRaw('LOWER(serial_no) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(stock_code, \'\')) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(stock_name, \'\')) LIKE ?', [$needle]);
            })
            ->limit(20)
            ->get()
            ->map(fn (WarehouseSerialLocation $location): array => [
                'match_type' => $this->serialMatchType($location, $query),
                'stock_code' => $location->stock_code,
                'stock_name' => $location->stock_name,
                'barcode' => null,
                'serial_no' => $location->serial_no,
                'is_serial_tracked' => true,
                'display_label' => implode(' - ', array_filter([$location->stock_code, $location->stock_name, $location->serial_no])),
            ]);

        $stockItems = WarehouseStockLocation::query()
            ->where('warehouse_no', $warehouseNo)
            ->where(function ($builder) use ($needle): void {
                $builder
                    ->whereRaw('LOWER(stock_code) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(stock_name, \'\')) LIKE ?', [$needle]);
            })
            ->limit(20)
            ->get()
            ->map(fn (WarehouseStockLocation $location): array => [
                'match_type' => str_contains(strtolower($location->stock_code), strtolower($query)) ? 'stock_code' : 'stock_name',
                'stock_code' => $location->stock_code,
                'stock_name' => $location->stock_name,
                'barcode' => null,
                'serial_no' => null,
                'is_serial_tracked' => false,
                'display_label' => implode(' - ', array_filter([$location->stock_code, $location->stock_name])),
            ]);

        return collect($serialItems->all())
            ->merge($stockItems->all())
            ->unique(fn (array $item): string => implode('|', [
                $item['match_type'],
                $item['stock_code'] ?? '',
                $item['serial_no'] ?? '',
            ]))
            ->values()
            ->all();
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

    private function serialMatchType(WarehouseSerialLocation $location, string $query): string
    {
        $query = strtolower($query);

        if (str_contains(strtolower($location->serial_no), $query)) {
            return 'serial';
        }

        if ($location->stock_code && str_contains(strtolower($location->stock_code), $query)) {
            return 'stock_code';
        }

        return 'stock_name';
    }
}
