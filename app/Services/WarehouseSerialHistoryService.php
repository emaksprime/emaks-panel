<?php

namespace App\Services;

use App\Models\WarehouseRack;
use App\Models\WarehouseRackOperationItem;
use App\Models\WarehouseSerialLocation;
use App\Services\TechnicalService\MikroSerialNumberService;
use Illuminate\Support\Collection;
use Throwable;

class WarehouseSerialHistoryService
{
    public function __construct(
        private readonly MikroSerialNumberService $mikroSerialNumbers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function history(string $serialNo): array
    {
        $serialNo = trim($serialNo);
        $currentLocation = WarehouseSerialLocation::query()
            ->where('serial_no', $serialNo)
            ->first();
        $panelItems = $this->panelItems($serialNo);
        $mikroResult = $this->mikroItems($serialNo);
        $items = collect($panelItems)
            ->merge($mikroResult['items'])
            ->sortByDesc(fn (array $item): string => (string) ($item['date'] ?? ''))
            ->values()
            ->all();

        return [
            'serial_no' => $serialNo,
            'stock_code' => $currentLocation?->stock_code ?? $this->firstFilled($items, 'stock_code'),
            'stock_name' => $currentLocation?->stock_name ?? $this->firstFilled($items, 'stock_name'),
            'category_name' => $currentLocation?->category_name,
            'current_location' => $currentLocation ? $this->currentLocation($currentLocation) : null,
            'items' => $items,
            'message' => $mikroResult['message'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function panelItems(string $serialNo): array
    {
        $operationItems = WarehouseRackOperationItem::query()
            ->with(['operation.completedBy', 'operation.createdBy'])
            ->where('serial_no', $serialNo)
            ->get();
        $rackNames = $this->rackNames($operationItems);

        return $operationItems->map(function (WarehouseRackOperationItem $item) use ($rackNames): array {
            $operation = $item->operation;
            $date = $operation?->completed_at ?? $operation?->created_at ?? $item->created_at;
            $sourceWarehouseNo = $operation?->source_warehouse_no ?? $item->warehouse_no;
            $targetWarehouseNo = $operation?->target_warehouse_no ?? $item->warehouse_no;
            $sourceRackCode = $operation?->source_rack_code ?? $item->source_rack_code;
            $targetRackCode = $operation?->target_rack_code ?? $item->target_rack_code;
            $user = $operation?->completedBy ?? $operation?->createdBy;

            return [
                'source' => 'panel',
                'date' => $date?->toDateTimeString(),
                'movement_type' => $sourceWarehouseNo !== $targetWarehouseNo ? 'Depo Transferi' : 'Raf Transferi',
                'operation_no' => $operation?->operation_no,
                'stock_code' => $item->stock_code,
                'stock_name' => $item->stock_name,
                'source_warehouse_no' => $sourceWarehouseNo,
                'source_warehouse_name' => $this->warehouseName($sourceWarehouseNo),
                'source_rack_code' => $sourceRackCode,
                'source_rack_name' => $this->rackName($rackNames, $sourceWarehouseNo, $sourceRackCode),
                'target_warehouse_no' => $targetWarehouseNo,
                'target_warehouse_name' => $this->warehouseName($targetWarehouseNo),
                'target_rack_code' => $targetRackCode,
                'target_rack_name' => $this->rackName($rackNames, $targetWarehouseNo, $targetRackCode),
                'document_no' => null,
                'user' => $user?->full_name ?: $user?->username,
                'description' => $this->panelDescription($sourceRackCode, $targetRackCode),
            ];
        })->all();
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, message: ?string}
     */
    private function mikroItems(string $serialNo): array
    {
        try {
            $history = $this->mikroSerialNumbers->history($serialNo);
        } catch (Throwable) {
            return [
                'items' => [],
                'message' => 'Mikro seri geçmişi alınamadı; panel raf hareketleri gösteriliyor.',
            ];
        }

        return [
            'items' => collect($history['items'] ?? [])
                ->filter('is_array')
                ->map(fn (array $item): array => $this->mikroItem($item))
                ->values()
                ->all(),
            'message' => null,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function mikroItem(array $item): array
    {
        return [
            'source' => 'mikro',
            'date' => $item['event_date'] ?? null,
            'movement_type' => $this->filled($item['title'] ?? null) ?? $this->filled($item['event_type'] ?? null) ?? 'Mikro Seri Hareketi',
            'operation_no' => null,
            'stock_code' => $item['stok_kodu'] ?? null,
            'stock_name' => $item['stok_adi'] ?? null,
            'source_warehouse_no' => null,
            'source_warehouse_name' => $item['hareket_grup_kodu_1'] ?? null,
            'source_rack_code' => null,
            'source_rack_name' => null,
            'target_warehouse_no' => null,
            'target_warehouse_name' => null,
            'target_rack_code' => null,
            'target_rack_name' => null,
            'document_no' => $this->documentNo($item),
            'user' => null,
            'description' => $this->filled($item['description'] ?? null) ?? 'Mikro seri hareketi',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentLocation(WarehouseSerialLocation $location): array
    {
        $rackName = WarehouseRack::query()
            ->where('warehouse_no', $location->warehouse_no)
            ->where('rack_code', (string) $location->rack_code)
            ->value('rack_name');

        return [
            'warehouse_no' => (int) $location->warehouse_no,
            'warehouse_name' => $this->warehouseName($location->warehouse_no),
            'rack_code' => $location->rack_code,
            'rack_name' => $rackName ?: $location->rack_code,
            'status' => $location->status,
            'last_seen_at' => $location->last_seen_at?->toDateTimeString(),
        ];
    }

    /**
     * @param Collection<int, WarehouseRackOperationItem> $items
     * @return Collection<string, string>
     */
    private function rackNames(Collection $items): Collection
    {
        $keys = $items->flatMap(function (WarehouseRackOperationItem $item): array {
            $operation = $item->operation;

            return [
                [(int) ($operation?->source_warehouse_no ?? $item->warehouse_no), (string) ($operation?->source_rack_code ?? $item->source_rack_code)],
                [(int) ($operation?->target_warehouse_no ?? $item->warehouse_no), (string) ($operation?->target_rack_code ?? $item->target_rack_code)],
            ];
        })->filter(fn (array $key): bool => $key[0] > 0 && $key[1] !== '');

        if ($keys->isEmpty()) {
            return collect();
        }

        return WarehouseRack::query()
            ->where(function ($query) use ($keys): void {
                foreach ($keys as [$warehouseNo, $rackCode]) {
                    if ($warehouseNo <= 0 || $rackCode === '') {
                        continue;
                    }

                    $query->orWhere(fn ($query) => $query
                        ->where('warehouse_no', $warehouseNo)
                        ->where('rack_code', $rackCode));
                }
            })
            ->get(['warehouse_no', 'rack_code', 'rack_name'])
            ->mapWithKeys(fn (WarehouseRack $rack): array => [
                $this->rackKey($rack->warehouse_no, $rack->rack_code) => (string) ($rack->rack_name ?: $rack->rack_code),
            ]);
    }

    private function rackName(Collection $rackNames, mixed $warehouseNo, mixed $rackCode): ?string
    {
        $rackCode = $this->filled($rackCode);

        if ($rackCode === null) {
            return null;
        }

        return $rackNames->get($this->rackKey($warehouseNo, $rackCode), $rackCode);
    }

    private function rackKey(mixed $warehouseNo, mixed $rackCode): string
    {
        return (int) $warehouseNo.'|'.trim((string) $rackCode);
    }

    private function warehouseName(mixed $warehouseNo): ?string
    {
        if ($warehouseNo === null || $warehouseNo === '') {
            return null;
        }

        return 'Depo '.(int) $warehouseNo;
    }

    private function panelDescription(mixed $sourceRackCode, mixed $targetRackCode): string
    {
        $source = $this->filled($sourceRackCode) ?? '-';
        $target = $this->filled($targetRackCode) ?? '-';

        return "{$source} rafından {$target} rafına transfer";
    }

    /**
     * @param array<string, mixed> $item
     */
    private function documentNo(array $item): ?string
    {
        foreach ([['evrak_seri', 'evrak_sira'], ['fatura_seri', 'fatura_sira'], ['siparis_seri', 'siparis_sira']] as [$seriesKey, $numberKey]) {
            $parts = array_filter([
                $this->filled($item[$seriesKey] ?? null),
                $this->filled($item[$numberKey] ?? null),
            ]);

            if ($parts !== []) {
                return implode('/', $parts);
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function firstFilled(array $items, string $key): ?string
    {
        foreach ($items as $item) {
            $value = $this->filled($item[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function filled(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
