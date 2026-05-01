<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckMikroSerialNumberRequest;
use App\Services\TechnicalService\MikroSerialNumberService;
use Illuminate\Http\JsonResponse;

class TechnicalServiceMikroController extends Controller
{
    public function __construct(
        private readonly MikroSerialNumberService $serialNumbers,
    ) {
    }

    public function check(CheckMikroSerialNumberRequest $request): JsonResponse
    {
        return response()->json(
            $this->serialNumbers->checkInstallation($request->validated('serial_no')),
        );
    }

    public function history(CheckMikroSerialNumberRequest $request): JsonResponse
    {
        return response()->json(
            $this->serialNumbers->history($request->validated('serial_no')),
        );
    }
}
