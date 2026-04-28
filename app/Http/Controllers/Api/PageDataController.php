<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\PanelAccessService;
use App\Services\PanelPageDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PageDataController extends Controller
{
    public function __invoke(
        Request $request,
        string $code,
        PanelPageDataService $pageData,
        PanelAccessService $access,
    ): JsonResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $page = Page::query()
            ->where('code', str_replace('-', '_', $code))
            ->where('active', true)
            ->firstOrFail();

        abort_unless($access->userCanAccess($user, $page->resource_code ?? $page->code), 403);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'grain' => ['nullable', 'in:day,week,month,year'],
            'detail_type' => ['nullable', 'in:cari,urun'],
            'scope_key' => ['nullable', 'string', 'max:80'],
            'rep_code' => ['nullable', 'string', 'max:40'],
            'customer_code' => ['nullable', 'string', 'max:80'],
            'proforma_no' => ['nullable', 'string', 'max:80'],
            'price_list' => ['nullable', 'integer'],
            'discount_code' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'bypass_cache' => ['nullable', 'boolean'],
        ]);

        try {
            return response()->json(
                $pageData->dataset($user, $code, $validated),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $this->friendlyErrorMessage($page),
                'mode' => 'page_data_error',
            ], 502);
        }
    }

    private function friendlyErrorMessage(Page $page): string
    {
        $resourceCode = $page->resource_code ?? $page->code;

        return match ($resourceCode) {
            'customers' => 'Müşteri veri kaynağı çalıştırılamadı.',
            'proforma', 'proforma_create', 'proforma_detail', 'proforma_edit' => 'Proforma veri kaynağı çalıştırılamadı.',
            'stock', 'stock_critical', 'stock_warehouse' => 'Stok veri kaynağı çalıştırılamadı.',
            'orders', 'orders_alinan', 'orders_verilen' => 'Sipariş veri kaynağı çalıştırılamadı.',
            default => 'Veri kaynağı çalıştırılamadı.',
        };
    }
}
