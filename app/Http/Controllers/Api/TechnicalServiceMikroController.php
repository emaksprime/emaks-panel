<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckMikroSerialNumberRequest;
use App\Services\TechnicalService\MikroSerialNumberService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class TechnicalServiceMikroController extends Controller
{
    public function __construct(
        private readonly MikroSerialNumberService $serialNumbers,
    ) {
    }

    public function check(CheckMikroSerialNumberRequest $request): JsonResponse
    {
        try {
            return response()->json(
                $this->serialNumbers->checkInstallation($request->validated('serial_no')),
            );
        } catch (RuntimeException $exception) {
            return $this->gatewayError($exception);
        }
    }

    public function history(CheckMikroSerialNumberRequest $request): JsonResponse
    {
        try {
            return response()->json(
                $this->serialNumbers->history($request->validated('serial_no')),
            );
        } catch (RuntimeException $exception) {
            return $this->gatewayError($exception);
        }
    }

    private function gatewayError(RuntimeException $exception): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => $exception->getMessage(),
            'rows' => [],
            'meta' => [],
            'request' => [],
        ], 502);
    }
}
