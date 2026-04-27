<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CariBilgiDataRequest;
use App\Services\CariBilgiPageService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class CariBilgiDataController extends Controller
{
    public function __invoke(CariBilgiDataRequest $request, CariBilgiPageService $cariBilgi): JsonResponse
    {
        try {
            return response()->json(
                $cariBilgi->dataset($request->user(), $request->validated()),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'mode' => 'n8n_gateway_error',
            ], 502);
        }
    }
}
