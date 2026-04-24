<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesMainDataRequest;
use App\Services\SalesMainPageService;
use Illuminate\Http\JsonResponse;

class SalesMainDataController extends Controller
{
    public function __invoke(SalesMainDataRequest $request, SalesMainPageService $salesMain): JsonResponse
    {
        return response()->json(
            $salesMain->dataset($request->user(), $request->validated()),
        );
    }
}
