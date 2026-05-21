<?php

namespace App\Services;

use App\Models\User;
use App\Models\WarehouseRackOperation;
use App\Models\WarehouseSerialLocation;
use App\Models\WarehouseStockLocation;
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
     * @return array<string, mixed>
     */
    private function summary(WarehouseRackOperation $operation, string $itemType, ?string $stockName): array
    {
        return [
            'operation_no' => $operation->operation_no,
            'warehouse_no' => $operation->source_warehouse_no,
            'source_rack_code' => $operation->source_rack_code,
            'target_rack_code' => $operation->target_rack_code,
            'item_code' => $operation->serial_no ?: $operation->stock_code,
            'item_type' => $itemType,
            'serial_no' => $operation->serial_no,
            'stock_code' => $operation->stock_code,
            'stock_name' => $stockName ?: null,
            'quantity' => (float) $operation->quantity,
            'operation_status' => $operation->status,
        ];
    }

    private function validationMessage(ValidationException $exception): string
    {
        return $exception->validator->errors()->first() ?: 'Raf transferi doğrulanamadı.';
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'rack_transfer' => $message,
        ]);
    }
}
