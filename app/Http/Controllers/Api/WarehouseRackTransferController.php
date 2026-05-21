<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteWarehouseRackTransferRequest;
use App\Http\Requests\ValidateWarehouseRackTransferRequest;
use App\Services\WarehouseRackTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class WarehouseRackTransferController extends Controller
{
    public function __construct(
        private readonly WarehouseRackTransferService $transfers,
    ) {
    }

    public function validate(ValidateWarehouseRackTransferRequest $request): JsonResponse
    {
        try {
            return response()->json($this->transfers->validate($request->validated(), $request->user()));
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }
    }

    public function complete(CompleteWarehouseRackTransferRequest $request): JsonResponse
    {
        try {
            return response()->json($this->transfers->complete($request->validated(), $request->user()));
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }
    }

    private function validationError(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => $exception->validator->errors()->first() ?: 'Raf transferi doğrulanamadı.',
            'errors' => $exception->errors(),
        ], 422);
    }
}
