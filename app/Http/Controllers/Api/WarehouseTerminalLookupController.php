<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WarehouseTerminalLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class WarehouseTerminalLookupController extends Controller
{
    public function __construct(
        private readonly WarehouseTerminalLookupService $lookups,
    ) {
    }

    public function warehouses(): JsonResponse
    {
        try {
            return response()->json($this->lookups->warehouses());
        } catch (RuntimeException $exception) {
            return $this->lookupError($exception);
        }
    }

    public function racks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_no' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(['source', 'target'])],
        ]);

        try {
            return response()->json($this->lookups->racks((int) $data['warehouse_no'], (string) $data['type']));
        } catch (RuntimeException $exception) {
            return $this->lookupError($exception);
        }
    }

    public function items(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_no' => ['required', 'integer', 'min:1'],
            'q' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        try {
            return response()->json($this->lookups->items((int) $data['warehouse_no'], (string) $data['q']));
        } catch (RuntimeException $exception) {
            return $this->lookupError($exception);
        }
    }

    private function lookupError(RuntimeException $exception): JsonResponse
    {
        return response()->json([
            'items' => [],
            'source' => 'error',
            'message' => $exception->getMessage(),
        ], 502);
    }
}
