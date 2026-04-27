<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesMainDataRequest;
use App\Services\SalesMainPageService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class SalesMainDataController extends Controller
{
    public function __invoke(SalesMainDataRequest $request, SalesMainPageService $salesMain): JsonResponse
    {
        try {
            return response()->json(
                $salesMain->dataset($request->user(), $request->validated()),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'mode' => 'n8n_gateway_error',
            ], 502);
        }
    }
}
