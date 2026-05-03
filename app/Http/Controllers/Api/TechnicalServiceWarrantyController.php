<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TechnicalService\WarrantyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return response()->json($this->warranties->statusForSerial($payload['serial_no']));
    }
}
