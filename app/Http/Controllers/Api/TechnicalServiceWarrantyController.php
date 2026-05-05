<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TechnicalService\WarrantyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TechnicalServiceWarrantyController extends Controller
{
    public function __construct(
        private readonly WarrantyService $warranties,
    ) {
    }

    public function serial(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'serial_no' => ['required', 'string', 'min:2', 'max:128'],
        ]);

        try {
            return response()->json($this->warranties->statusForSerial($payload['serial_no']));
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'error' => $exception->getMessage(),
                'rows' => [],
                'meta' => [],
                'request' => [],
            ], 502);
        }
    }
}
