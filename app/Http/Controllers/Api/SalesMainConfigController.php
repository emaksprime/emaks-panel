<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SalesMainPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesMainConfigController extends Controller
{
    public function __invoke(Request $request, SalesMainPageService $salesMain): JsonResponse
    {
        return response()->json(
            $salesMain->config($request->user()),
        );
    }
}
