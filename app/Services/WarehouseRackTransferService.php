<?php

namespace App\Services;

use App\Models\User;
use App\Models\WarehouseRackOperation;
use App\Models\WarehouseRackOperationItem;
use App\Models\WarehouseSerialLocation;
use App\Models\WarehouseStockLocation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseRackTransferService
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload, ?User $user): array
    {
        $item = $this->resolveItem($payload);
        $quantity = $item['item_type'] === 'serial' ? 1.0 : $this->normalizedQuantity($payload);
        $operationNo = $this->newOperationNo();

        $operation = WarehouseRackOperation::query()->create([
            'operation_no' => $operationNo,
            'operation_type' => 'rack_transfer',
            'source_warehouse_no' => (int) $payload['warehouse_no'],
            'source_rack_code' => $this->normalizedRack($payload['source_rack_code']),
            'target_warehouse_no' => (int) $payload['warehouse_no'],
            'target_rack_code' => $this->normalizedRack($payload['target_rack_code']),
            'serial_no' => $item['serial_no'],
            'stock_code' => $item['stock_code'],
            'quantity' => $quantity,
            'status' => 'validated',
            'validation_status' => 'success',
            'validation_message' => 'Ön kontrol başarılı. Mikro’ya yazma yapılmadı.',
            'created_by' => $user?->id,
            'meta' => [
                'item_type' => $item['item_type'],
                'stock_name' => $item['stock_name'],
                'source' => 'panel',
            ],
        ]);

        return [
            'ok' => true,
            'operation_no' => $operation->operation_no,
            'item_type' => $item['item_type'],
            'message' => 'Ön kontrol başarılı. Mikro’ya yazma yapılmadı.',
            'summary' => $this->summary($operation, $item['item_type'], $item['stock_name']),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function complete(array $payload, ?User $user): array
    {
        return DB::transaction(function () use ($payload, $user): array {
            $operation = WarehouseRackOperation::query()
                ->where('operation_no', $payload['operation_no'])
                ->lockForUpdate()
                ->first();

            if (! $operation) {
                $this->fail('İşlem kaydı bulunamadı.');
            }

            if ($operation->status !== 'validated') {
                $this->fail('Sadece ön kontrolü başarılı işlemler tamamlanabilir.');
            }

            try {
                $itemType = (string) ($operation->meta['item_type'] ?? ($operation->serial_no ? 'serial' : 'stock'));
                $stockName = (string) ($operation->meta['stock_name'] ?? '');

                if ($itemType === 'serial') {
                    $stockName = $this->completeSerialTransfer($operation);
                } else {
                    $stockName = $this->completeStockTransfer($operation);
                }
            } catch (ValidationException $exception) {
                $operation->forceFill([
                    'status' => 'failed',
                    'validation_status' => 'failed',
                    'validation_message' => $this->validationMessage($exception),
                ])->save();

                throw $exception;
            }

            $operation->forceFill([
                'status' => 'completed',
                'completed_by' => $user?->id,
                'completed_at' => now(),
                'validation_status' => 'success',
                'validation_message' => 'Raf transferi tamamlandı. Mikro’ya yazma yapılmadı.',
            ])->save();

            return [
                'ok' => true,
                'message' => 'Raf transferi tamamlandı. Mikro’ya yazma yapılmadı.',
                'operation_no' => $operation->operation_no,
                'summary' => $this->summary($operation->fresh(), $itemType, $stockName),
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function transfer(array $payload, ?User $user): array
    {
        return DB::transaction(function () use ($payload, $user): array {
            $warehouseNo = (int) $payload['warehouse_no'];
            $sourceRackCode = $this->normalizedRack($payload['source_rack_code']);
            $targetRackCode = $this->normalizedRack($payload['target_rack_code']);

            if (Str::upper($sourceRackCode) === Str::upper($targetRackCode)) {
                $this->fail('Kaynak raf ile hedef raf aynı olamaz.');
            }

            $serialNumbers = $this->normalizedSerialNumbers($payload['serial_numbers'] ?? []);
            $stockCode = $this->normalizedItemCode($payload['stock_code'] ?? '');
            $itemCode = $this->normalizedItemCode($payload['item_code'] ?? '');

            if ($stockCode === '' && $itemCode === '' && $serialNumbers === []) {
                $this->fail('Ürün seçimi zorunludur.');
            }

            if ($serialNumbers === [] && $itemCode !== '') {
                $scannedSerial = WarehouseSerialLocation::query()
                    ->where('serial_no', $itemCode)
                    ->where('warehouse_no', $warehouseNo)
                    ->first();

                if ($scannedSerial) {
                    $serialNumbers = [$itemCode];
                    $stockCode = $stockCode !== '' ? $stockCode : (string) $scannedSerial->stock_code;
                }
            }

            $serialTracked = $this->serialTrackingRequested($payload)
                || $serialNumbers !== []
                || $this->hasSerialTrackingSignal($warehouseNo, $stockCode !== '' ? $stockCode : $itemCode);

            if ($serialTracked) {
                if ($serialNumbers === []) {
                    $this->fail('Seri takipli ürünlerde seri no zorunludur.');
                }

                return $this->transferSerials($payload, $user, $warehouseNo, $sourceRackCode, $targetRackCode, $stockCode, $itemCode, $serialNumbers);
            }

            return $this->transferStock($payload, $user, $warehouseNo, $sourceRackCode, $targetRackCode, $stockCode !== '' ? $stockCode : $itemCode);
        });
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function history(array $filters): array
    {
        $query = WarehouseRackOperation::query()
            ->with(['items', 'completedBy'])
            ->where('operation_type', 'rack_transfer')
            ->where('status', 'completed');

        if (! empty($filters['date_from'])) {
            $query->where('completed_at', '>=', Carbon::parse((string) $filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('completed_at', '<=', Carbon::parse((string) $filters['date_to'])->endOfDay());
        }

        if (! empty($filters['warehouse_no'])) {
            $query->where('source_warehouse_no', (int) $filters['warehouse_no']);
        }

        $operations = $query
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return [
            'items' => $operations->map(fn (WarehouseRackOperation $operation): array => $this->historyRow($operation))->all(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $serialNumbers
     * @return array<string, mixed>
     */
    private function transferSerials(
        array $payload,
        ?User $user,
        int $warehouseNo,
        string $sourceRackCode,
        string $targetRackCode,
        string $stockCode,
        string $itemCode,
        array $serialNumbers,
    ): array {
        $serials = WarehouseSerialLocation::query()
            ->whereIn('serial_no', $serialNumbers)
            ->lockForUpdate()
            ->get()
            ->keyBy('serial_no');

        if ($serials->count() !== count($serialNumbers)) {
            $this->fail('Bu ürün/seri belirtilen depoda ve kaynak rafta bulunamadı.');
        }

        $resolvedStockCode = $stockCode !== '' ? $stockCode : null;
        $stockName = null;

        foreach ($serialNumbers as $serialNo) {
            /** @var WarehouseSerialLocation|null $serial */
            $serial = $serials->get($serialNo);

            if (! $serial
                || (int) $serial->warehouse_no !== $warehouseNo
                || $serial->status !== 'in_stock'
                || $serial->rack_code !== $sourceRackCode
            ) {
                $this->fail('Bu ürün/seri belirtilen depoda ve kaynak rafta bulunamadı.');
            }

            if ($resolvedStockCode !== null && $serial->stock_code !== $resolvedStockCode) {
                $this->fail('Okutulan seri seçili stok ile uyumlu değil.');
            }

            $resolvedStockCode = (string) $serial->stock_code;
            $stockName = $stockName ?: $serial->stock_name;
        }

        if ($resolvedStockCode === null || $resolvedStockCode === '') {
            $this->fail('Seri için stok kodu bulunamadı.');
        }

        if ($itemCode !== '' && $stockCode === '' && $itemCode !== $resolvedStockCode && ! in_array($itemCode, $serialNumbers, true)) {
            $this->fail('Okutulan seri seçili stok ile uyumlu değil.');
        }

        $operation = $this->createCompletedOperation($payload, $user, [
            'warehouse_no' => $warehouseNo,
            'source_rack_code' => $sourceRackCode,
            'target_rack_code' => $targetRackCode,
            'stock_code' => $resolvedStockCode,
            'serial_no' => count($serialNumbers) === 1 ? $serialNumbers[0] : null,
            'stock_name' => $stockName,
            'quantity' => count($serialNumbers),
            'item_type' => 'serial',
            'serial_count' => count($serialNumbers),
        ]);

        foreach ($serialNumbers as $index => $serialNo) {
            /** @var WarehouseSerialLocation $serial */
            $serial = $serials->get($serialNo);

            $serial->forceFill([
                'rack_code' => $targetRackCode,
                'last_operation_no' => $operation->operation_no,
                'last_seen_at' => now(),
            ])->save();

            WarehouseRackOperationItem::query()->create([
                'operation_id' => $operation->id,
                'line_no' => $index + 1,
                'item_type' => 'serial',
                'warehouse_no' => $warehouseNo,
                'source_rack_code' => $sourceRackCode,
                'target_rack_code' => $targetRackCode,
                'serial_no' => $serialNo,
                'stock_code' => $resolvedStockCode,
                'stock_name' => $serial->stock_name,
                'barcode' => $this->blankToNull($payload['barcode'] ?? null),
                'quantity' => 1,
                'status' => 'completed',
                'meta' => [
                    'source' => 'panel',
                ],
            ]);
        }

        return [
            'ok' => true,
            'message' => 'Raf transferi tamamlandı. Mikro’ya yazma yapılmadı.',
            'operation_no' => $operation->operation_no,
            'summary' => $this->summary($operation->fresh('items'), 'serial', $stockName),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function transferStock(
        array $payload,
        ?User $user,
        int $warehouseNo,
        string $sourceRackCode,
        string $targetRackCode,
        string $stockCode,
    ): array {
        if ($stockCode === '') {
            $this->fail('Ürün seçimi zorunludur.');
        }

        $quantity = $this->normalizedQuantity($payload);
        $source = WarehouseStockLocation::query()
            ->where('stock_code', $stockCode)
            ->where('warehouse_no', $warehouseNo)
            ->where('rack_code', $sourceRackCode)
            ->lockForUpdate()
            ->first();

        if (! $source || (float) $source->quantity < $quantity) {
            $this->fail('Bu ürün/seri belirtilen depoda ve kaynak rafta bulunamadı.');
        }

        $target = WarehouseStockLocation::query()
            ->where('stock_code', $stockCode)
            ->where('warehouse_no', $warehouseNo)
            ->where('rack_code', $targetRackCode)
            ->lockForUpdate()
            ->first();

        $operation = $this->createCompletedOperation($payload, $user, [
            'warehouse_no' => $warehouseNo,
            'source_rack_code' => $sourceRackCode,
            'target_rack_code' => $targetRackCode,
            'stock_code' => $stockCode,
            'serial_no' => null,
            'stock_name' => $source->stock_name,
            'quantity' => $quantity,
            'item_type' => 'stock',
            'serial_count' => 0,
        ]);

        $source->forceFill([
            'quantity' => (float) $source->quantity - $quantity,
            'last_operation_no' => $operation->operation_no,
            'last_seen_at' => now(),
        ])->save();

        if ($target) {
            $target->forceFill([
                'quantity' => (float) $target->quantity + $quantity,
                'last_operation_no' => $operation->operation_no,
                'last_seen_at' => now(),
            ])->save();
        } else {
            WarehouseStockLocation::query()->create([
                'warehouse_no' => $warehouseNo,
                'rack_code' => $targetRackCode,
                'stock_code' => $stockCode,
                'stock_name' => $source->stock_name,
                'quantity' => $quantity,
                'source' => 'rack_transfer',
                'last_operation_no' => $operation->operation_no,
                'last_seen_at' => now(),
            ]);
        }

        WarehouseRackOperationItem::query()->create([
            'operation_id' => $operation->id,
            'line_no' => 1,
            'item_type' => 'stock',
            'warehouse_no' => $warehouseNo,
            'source_rack_code' => $sourceRackCode,
            'target_rack_code' => $targetRackCode,
            'serial_no' => null,
            'stock_code' => $stockCode,
            'stock_name' => $source->stock_name,
            'barcode' => $this->blankToNull($payload['barcode'] ?? null),
            'quantity' => $quantity,
            'status' => 'completed',
            'meta' => [
                'source' => 'panel',
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Raf transferi tamamlandı. Mikro’ya yazma yapılmadı.',
            'operation_no' => $operation->operation_no,
            'summary' => $this->summary($operation->fresh('items'), 'stock', $source->stock_name),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $data
     */
    private function createCompletedOperation(array $payload, ?User $user, array $data): WarehouseRackOperation
    {
        return WarehouseRackOperation::query()->create([
            'operation_no' => $this->newOperationNo(),
            'operation_type' => 'rack_transfer',
            'source_warehouse_no' => $data['warehouse_no'],
            'source_rack_code' => $data['source_rack_code'],
            'target_warehouse_no' => $data['warehouse_no'],
            'target_rack_code' => $data['target_rack_code'],
            'serial_no' => $data['serial_no'],
            'stock_code' => $data['stock_code'],
            'quantity' => $data['quantity'],
            'status' => 'completed',
            'validation_status' => 'success',
            'validation_message' => 'Raf transferi tamamlandı. Mikro’ya yazma yapılmadı.',
            'created_by' => $user?->id,
            'completed_by' => $user?->id,
            'completed_at' => now(),
            'meta' => [
                'item_type' => $data['item_type'],
                'stock_name' => $data['stock_name'],
                'warehouse_name' => $this->blankToNull($payload['warehouse_name'] ?? null),
                'selection_type' => $this->blankToNull($payload['selection_type'] ?? null),
                'serial_count' => $data['serial_count'],
                'source' => 'panel',
                'mikro_write' => false,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{item_type: string, serial_no: ?string, stock_code: ?string, stock_name: ?string}
     */
    private function resolveItem(array $payload): array
    {
        $warehouseNo = (int) $payload['warehouse_no'];
        $sourceRackCode = $this->normalizedRack($payload['source_rack_code']);
        $targetRackCode = $this->normalizedRack($payload['target_rack_code']);
        $itemCode = $this->normalizedItemCode($payload['item_code']);

        if (Str::upper($sourceRackCode) === Str::upper($targetRackCode)) {
            $this->fail('Kaynak raf ile hedef raf aynı olamaz.');
        }

        $serial = WarehouseSerialLocation::query()
            ->where('serial_no', $itemCode)
            ->where('warehouse_no', $warehouseNo)
            ->first();

        if ($serial) {
            if ($serial->status !== 'in_stock' || $serial->rack_code !== $sourceRackCode) {
                $this->fail('Bu ürün/seri belirtilen depoda ve kaynak rafta bulunamadı.');
            }

            return [
                'item_type' => 'serial',
                'serial_no' => $serial->serial_no,
                'stock_code' => $serial->stock_code,
                'stock_name' => $serial->stock_name,
            ];
        }

        $quantity = $this->normalizedQuantity($payload);
        $stock = WarehouseStockLocation::query()
            ->where('stock_code', $itemCode)
            ->where('warehouse_no', $warehouseNo)
            ->where('rack_code', $sourceRackCode)
            ->where('quantity', '>=', $quantity)
            ->first();

        if (! $stock) {
            $this->fail('Bu ürün/seri belirtilen depoda ve kaynak rafta bulunamadı.');
        }

        return [
            'item_type' => 'stock',
            'serial_no' => null,
            'stock_code' => $stock->stock_code,
            'stock_name' => $stock->stock_name,
        ];
    }

    private function completeSerialTransfer(WarehouseRackOperation $operation): ?string
    {
        $serial = WarehouseSerialLocation::query()
            ->where('serial_no', $operation->serial_no)
            ->where('warehouse_no', $operation->source_warehouse_no)
            ->lockForUpdate()
            ->first();

        if (! $serial || $serial->status !== 'in_stock' || $serial->rack_code !== $operation->source_rack_code) {
            $this->fail('Bu ürün/seri belirtilen depoda ve kaynak rafta bulunamadı.');
        }

        $serial->forceFill([
            'rack_code' => $operation->target_rack_code,
            'last_operation_no' => $operation->operation_no,
            'last_seen_at' => now(),
        ])->save();

        return $serial->stock_name;
    }

    private function completeStockTransfer(WarehouseRackOperation $operation): ?string
    {
        $quantity = (float) $operation->quantity;
        $source = WarehouseStockLocation::query()
            ->where('stock_code', $operation->stock_code)
            ->where('warehouse_no', $operation->source_warehouse_no)
            ->where('rack_code', $operation->source_rack_code)
            ->lockForUpdate()
            ->first();

        if (! $source || (float) $source->quantity < $quantity) {
            $this->fail('Bu ürün/seri belirtilen depoda ve kaynak rafta bulunamadı.');
        }

        $target = WarehouseStockLocation::query()
            ->where('stock_code', $operation->stock_code)
            ->where('warehouse_no', $operation->target_warehouse_no)
            ->where('rack_code', $operation->target_rack_code)
            ->lockForUpdate()
            ->first();

        $source->forceFill([
            'quantity' => (float) $source->quantity - $quantity,
            'last_operation_no' => $operation->operation_no,
            'last_seen_at' => now(),
        ])->save();

        if ($target) {
            $target->forceFill([
                'quantity' => (float) $target->quantity + $quantity,
                'last_operation_no' => $operation->operation_no,
                'last_seen_at' => now(),
            ])->save();
        } else {
            WarehouseStockLocation::query()->create([
                'warehouse_no' => $operation->target_warehouse_no,
                'rack_code' => $operation->target_rack_code,
                'stock_code' => $operation->stock_code,
                'stock_name' => $source->stock_name,
                'quantity' => $quantity,
                'source' => 'rack_transfer',
                'last_operation_no' => $operation->operation_no,
                'last_seen_at' => now(),
            ]);
        }

        return $source->stock_name;
    }

    private function newOperationNo(): string
    {
        do {
            $operationNo = 'RF-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (WarehouseRackOperation::query()->where('operation_no', $operationNo)->exists());

        return $operationNo;
    }

    private function normalizedRack(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizedItemCode(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function normalizedQuantity(array $payload): float
    {
        return (float) ($payload['quantity'] ?? 1);
    }

    /**
     * @return array<int, string>
     */
    private function normalizedSerialNumbers(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,;]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $serials = array_values(array_filter(
            array_map(fn (mixed $serial): string => trim((string) $serial), $value),
            fn (string $serial): bool => $serial !== '',
        ));
        $upperSerials = array_map(fn (string $serial): string => Str::upper($serial), $serials);

        if (count($upperSerials) !== count(array_unique($upperSerials))) {
            $this->fail('Aynı seri ikinci kez eklenemez.');
        }

        return $serials;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function serialTrackingRequested(array $payload): bool
    {
        if (! array_key_exists('is_serial_tracked', $payload)) {
            return false;
        }

        $value = $payload['is_serial_tracked'];

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'evet'], true);
    }

    private function hasSerialTrackingSignal(int $warehouseNo, string $stockCode): bool
    {
        if ($stockCode === '') {
            return false;
        }

        return WarehouseSerialLocation::query()
            ->where('warehouse_no', $warehouseNo)
            ->where('stock_code', $stockCode)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(WarehouseRackOperation $operation, string $itemType, ?string $stockName): array
    {
        $serialNumbers = $operation->relationLoaded('items')
            ? $operation->items->pluck('serial_no')->filter()->values()->all()
            : [];

        return [
            'operation_no' => $operation->operation_no,
            'warehouse_no' => $operation->source_warehouse_no,
            'warehouse_name' => $operation->meta['warehouse_name'] ?? null,
            'source_rack_code' => $operation->source_rack_code,
            'target_rack_code' => $operation->target_rack_code,
            'item_code' => $operation->serial_no ?: $operation->stock_code,
            'item_type' => $itemType,
            'serial_no' => $operation->serial_no,
            'serial_numbers' => $serialNumbers,
            'serial_count' => count($serialNumbers),
            'stock_code' => $operation->stock_code,
            'stock_name' => $stockName ?: null,
            'quantity' => (float) $operation->quantity,
            'operation_status' => $operation->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyRow(WarehouseRackOperation $operation): array
    {
        /** @var Collection<int, WarehouseRackOperationItem> $items */
        $items = $operation->items;
        $serialCount = $items->where('item_type', 'serial')->count();
        $firstItem = $items->first();
        $user = $operation->completedBy;

        return [
            'date' => $operation->completed_at?->toDateTimeString(),
            'operation_no' => $operation->operation_no,
            'warehouse_no' => $operation->source_warehouse_no,
            'warehouse_name' => $operation->meta['warehouse_name'] ?? null,
            'source_rack_code' => $operation->source_rack_code,
            'target_rack_code' => $operation->target_rack_code,
            'stock_code' => $firstItem?->stock_code ?? $operation->stock_code,
            'stock_name' => $firstItem?->stock_name ?? ($operation->meta['stock_name'] ?? null),
            'serial_count' => $serialCount,
            'total_quantity' => (float) $operation->quantity,
            'user' => $user?->full_name ?: $user?->username,
            'status' => $operation->status,
            'items' => $items->map(fn (WarehouseRackOperationItem $item): array => [
                'line_no' => $item->line_no,
                'item_type' => $item->item_type,
                'serial_no' => $item->serial_no,
                'stock_code' => $item->stock_code,
                'stock_name' => $item->stock_name,
                'barcode' => $item->barcode,
                'quantity' => (float) $item->quantity,
                'status' => $item->status,
            ])->all(),
        ];
    }

    private function validationMessage(ValidationException $exception): string
    {
        return $exception->validator->errors()->first() ?: 'Raf transferi doğrulanamadı.';
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'rack_transfer' => $message,
        ]);
    }
}
