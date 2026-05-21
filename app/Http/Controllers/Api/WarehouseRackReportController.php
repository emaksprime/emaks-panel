<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WarehouseRackReportRequest;
use App\Services\WarehouseRackReportService;
use Illuminate\Http\JsonResponse;

class WarehouseRackReportController extends Controller
{
    public function __construct(
        private readonly WarehouseRackReportService $reports,
    ) {
    }

    public function index(WarehouseRackReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->report($request->validated()));
    }
}
