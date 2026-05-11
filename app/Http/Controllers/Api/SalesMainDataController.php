<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesMainDataRequest;
use App\Services\AuditLogger;
use App\Services\SalesMainPageService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class SalesMainDataController extends Controller
{
    public function __invoke(
        SalesMainDataRequest $request,
        SalesMainPageService $salesMain,
        AuditLogger $auditLogger,
    ): JsonResponse
    {
        try {
            $validated = $request->validated();
            $dataset = $salesMain->dataset($request->user(), $validated);

            $auditLogger->log($request->user(), 'sales.data.filter', [
                'page' => 'sales_main',
                'scope_key' => $dataset['filters']['scopeKey'] ?? $validated['scope_key'] ?? null,
                'detail_type' => $dataset['filters']['detailType'] ?? $validated['detail_type'] ?? null,
                'customer_filter' => $dataset['filters']['customerFilter'] ?? $validated['customer_filter'] ?? null,
                'cari_filter' => $validated['cari_filter'] ?? null,
                'product_filter' => $dataset['filters']['productFilter'] ?? $validated['product_filter'] ?? null,
                'brand_filter' => $dataset['filters']['brandFilter'] ?? $validated['brand_filter'] ?? null,
                'category_filter' => $dataset['filters']['categoryFilter'] ?? $validated['category_filter'] ?? null,
                'date_from' => $dataset['filters']['dateFrom'] ?? $validated['date_from'] ?? null,
                'date_to' => $dataset['filters']['dateTo'] ?? $validated['date_to'] ?? null,
            ], $request);

            return response()->json($dataset);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'mode' => 'n8n_gateway_error',
            ], 502);
        }
    }
}
