<?php

namespace App\Services;

use App\Models\WarehouseRack;
use App\Models\WarehouseSerialLocation;
use App\Models\WarehouseStockLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WarehouseRackReportService
{
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $itemType = (string) ($filters['item_type'] ?? 'all');
        $rackNames = $this->rackNames($filters);
        $rows = collect();

        if (in_array($itemType, ['all', 'serial'], true)) {
            $rows = $rows->merge($this->serialRows($filters, $rackNames));
        }

        if (in_array($itemType, ['all', 'stock'], true)) {
            $rows = $rows->merge($this->stockRows($filters, $rackNames));
        }

        $rows = $rows
            ->sortBy(fn (array $row): string => implode('|', [
                str_pad((string) $row['warehouse_no'], 8, '0', STR_PAD_LEFT),
                (string) $row['rack_code'],
                (string) $row['stock_code'],
                (string) ($row['serial_no'] ?? ''),
                (string) $row['item_type'],
            ]))
            ->values();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(250, (int) ($filters['per_page'] ?? 100)));
        $total = $rows->count();

        return [
            'items' => $rows->forPage($page, $perPage)->values()->all(),
            'summary' => $this->summary($rows),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @param Collection<string, string> $rackNames
     * @return Collection<int, array<string, mixed>>
     */
    private function serialRows(array $filters, Collection $rackNames): Collection
    {
        $query = WarehouseSerialLocation::query();

        $this->applyCommonFilters($query, $filters);

        if (! empty($filters['serial_no'])) {
            $query->where('serial_no', (string) $filters['serial_no']);
        }

        if (($filters['only_in_stock'] ?? true) === true) {
            $query->where('status', 'in_stock');
        }

        if (! empty($filters['search'])) {
            $this->applySearch($query, (string) $filters['search'], ['stock_code', 'stock_name', 'serial_no', 'rack_code']);
        }

        return $query->get()->map(function (WarehouseSerialLocation $location) use ($rackNames): array {
            return [
                'item_type' => 'serial',
                'warehouse_no' => (int) $location->warehouse_no,
                'warehouse_name' => 'Depo '.$location->warehouse_no,
                'rack_code' => $location->rack_code,
                'rack_name' => $this->rackName($rackNames, (int) $location->warehouse_no, (string) $location->rack_code),
                'stock_code' => $location->stock_code,
                'stock_name' => $location->stock_name,
                'serial_no' => $location->serial_no,
                'quantity' => 1,
                'status' => $location->status,
                'source' => $location->source,
                'last_operation_no' => $location->last_operation_no,
                'last_seen_at' => $location->last_seen_at?->toDateTimeString(),
                'updated_at' => $location->updated_at?->toDateTimeString(),
            ];
        });
    }

    /**
     * @param array<string, mixed> $filters
     * @param Collection<string, string> $rackNames
     * @return Collection<int, array<string, mixed>>
     */
    private function stockRows(array $filters, Collection $rackNames): Collection
    {
        if (! empty($filters['serial_no'])) {
            return collect();
        }

        $query = WarehouseStockLocation::query();

        $this->applyCommonFilters($query, $filters);

        if (($filters['only_in_stock'] ?? true) === true) {
            $query->where('quantity', '>', 0);
        }

        if (! empty($filters['search'])) {
            $this->applySearch($query, (string) $filters['search'], ['stock_code', 'stock_name', 'rack_code']);
        }

        return $query->get()->map(function (WarehouseStockLocation $location) use ($rackNames): array {
            return [
                'item_type' => 'stock',
                'warehouse_no' => (int) $location->warehouse_no,
                'warehouse_name' => 'Depo '.$location->warehouse_no,
                'rack_code' => $location->rack_code,
                'rack_name' => $this->rackName($rackNames, (int) $location->warehouse_no, (string) $location->rack_code),
                'stock_code' => $location->stock_code,
                'stock_name' => $location->stock_name,
                'serial_no' => null,
                'quantity' => (float) $location->quantity,
                'status' => (float) $location->quantity > 0 ? 'in_stock' : 'empty',
                'source' => $location->source,
                'last_operation_no' => $location->last_operation_no,
                'last_seen_at' => $location->last_seen_at?->toDateTimeString(),
                'updated_at' => $location->updated_at?->toDateTimeString(),
            ];
        });
    }

    /**
     * @param Builder<WarehouseSerialLocation|WarehouseStockLocation> $query
     * @param array<string, mixed> $filters
     */
    private function applyCommonFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['warehouse_no'])) {
            $query->where('warehouse_no', (int) $filters['warehouse_no']);
        }

        if (! empty($filters['rack_code'])) {
            $query->where('rack_code', (string) $filters['rack_code']);
        }

        if (! empty($filters['stock_code'])) {
            $query->where('stock_code', (string) $filters['stock_code']);
        }
    }

    /**
     * @param Builder<WarehouseSerialLocation|WarehouseStockLocation> $query
     * @param array<int, string> $columns
     */
    private function applySearch(Builder $query, string $search, array $columns): void
    {
        $needle = '%'.mb_strtolower($search).'%';

        $query->where(function (Builder $query) use ($columns, $needle): void {
            foreach ($columns as $column) {
                $query->orWhereRaw("LOWER({$column}) LIKE ?", [$needle]);
            }
        });
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<string, string>
     */
    private function rackNames(array $filters): Collection
    {
        $query = WarehouseRack::query();

        if (! empty($filters['warehouse_no'])) {
            $query->where('warehouse_no', (int) $filters['warehouse_no']);
        }

        if (! empty($filters['rack_code'])) {
            $query->where('rack_code', (string) $filters['rack_code']);
        }

        return $query
            ->get(['warehouse_no', 'rack_code', 'rack_name'])
            ->mapWithKeys(fn (WarehouseRack $rack): array => [
                $this->rackKey((int) $rack->warehouse_no, (string) $rack->rack_code) => (string) ($rack->rack_name ?: $rack->rack_code),
            ]);
    }

    /**
     * @param Collection<string, string> $rackNames
     */
    private function rackName(Collection $rackNames, int $warehouseNo, string $rackCode): string
    {
        return $rackNames->get($this->rackKey($warehouseNo, $rackCode), $rackCode);
    }

    private function rackKey(int $warehouseNo, string $rackCode): string
    {
        return $warehouseNo.'|'.$rackCode;
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $serialRows = $rows->where('item_type', 'serial');
        $stockRows = $rows->where('item_type', 'stock');

        return [
            'total_serial_count' => $serialRows->count(),
            'total_stock_rows' => $stockRows->count(),
            'total_stock_quantity' => round($stockRows->sum(fn (array $row): float => (float) $row['quantity']), 4),
            'rack_count' => $rows
                ->map(fn (array $row): string => $this->rackKey((int) $row['warehouse_no'], (string) $row['rack_code']))
                ->unique()
                ->count(),
        ];
    }
}
