<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Models\Page;
use App\Services\PanelAccessService;
use App\Services\PanelPageDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        $normalizedCode = str_replace('-', '_', $code);
        $page = Page::query()
            ->where('code', $normalizedCode)
            ->where('active', true)
            ->first();

        $source = null;
        $sourceResourceCode = null;

        if ($page === null) {
            $source = DataSource::query()
                ->where('code', $normalizedCode)
                ->where('active', true)
                ->firstOrFail();
            $sourceResourceCode = $this->resourceForDataSource($source->code);
        }

        abort_unless(
            $page !== null
                ? $access->userCanAccess($user, $page->resource_code ?? $page->code)
                : $this->userCanAccessDataSource($access, $user, (string) $source?->code, (string) $sourceResourceCode),
            403,
        );

        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'grain' => ['nullable', 'in:day,week,month,year'],
            'detail_type' => ['nullable', 'in:cari,urun'],
            'scope_key' => ['nullable', 'string', 'max:80'],
            'rep_code' => ['nullable', 'string', 'max:40'],
            'customer_filter' => ['nullable', 'string', 'max:1000'],
            'cari_filter' => ['nullable', 'string', 'max:1000'],
            'customer_code' => ['nullable', 'string', 'max:80'],
            'proforma_no' => ['nullable', 'string', 'max:80'],
            'price_list' => ['nullable', 'integer'],
            'discount_code' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'bypass_cache' => ['nullable', 'boolean'],
        ]);

        if (($source?->code ?? null) === 'sales_customer_search') {
            $validated['scope_key'] = $this->normalizeSalesCustomerSearchScope(
                $access,
                $user,
                (string) ($validated['scope_key'] ?? 'all'),
            );
        }

        try {
            return response()->json(
                $page !== null
                    ? $pageData->dataset($user, $code, $validated)
                    : $pageData->datasetForSource($user, (string) $source?->code, (string) $sourceResourceCode, $validated),
            );
        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return response()->json([
                'message' => $this->friendlyErrorMessage($page, $sourceResourceCode),
                'mode' => 'page_data_error',
            ], 502);
        }
    }

    private function friendlyErrorMessage(?Page $page, ?string $resourceCode = null): string
    {
        $resourceCode ??= $page?->resource_code ?? $page?->code;

        return match ($resourceCode) {
            'customers' => 'Müşteri veri kaynağı çalıştırılamadı.',
            'proforma', 'proforma_create', 'proforma_detail', 'proforma_edit' => 'Proforma veri kaynağı çalıştırılamadı.',
            'stock', 'stock_critical', 'stock_warehouse' => 'Stok veri kaynağı çalıştırılamadı.',
            'orders', 'orders_alinan', 'orders_verilen' => 'Sipariş veri kaynağı çalıştırılamadı.',
            default => 'Veri kaynağı çalıştırılamadı.',
        };
    }

    private function resourceForDataSource(string $sourceCode): string
    {
        if (str_starts_with($sourceCode, 'customer') || str_starts_with($sourceCode, 'customers_')) {
            return 'customers';
        }

        if (str_starts_with($sourceCode, 'proforma_')) {
            return 'proforma';
        }

        if (str_starts_with($sourceCode, 'sales_')) {
            return 'sales_main';
        }

        return $sourceCode;
    }

    private function userCanAccessDataSource(PanelAccessService $access, mixed $user, string $sourceCode, string $resourceCode): bool
    {
        if ($sourceCode === 'sales_customer_search') {
            return $access->userCanAccess($user, 'sales_main')
                || $access->userCanAccess($user, 'sales_online')
                || $access->userCanAccess($user, 'sales_bayi');
        }

        return $access->userCanAccess($user, $resourceCode);
    }

    private function normalizeSalesCustomerSearchScope(PanelAccessService $access, mixed $user, string $scopeKey): string
    {
        $scopeKey = str_replace('-', '_', trim($scopeKey) !== '' ? trim($scopeKey) : 'all');

        if ($access->userCanAccess($user, 'sales_main_all')) {
            return $scopeKey;
        }

        if ($scopeKey === 'online_perakende') {
            abort_unless($access->userCanAccess($user, 'sales_online'), 403);

            return $scopeKey;
        }

        if ($scopeKey === 'bayi_proje') {
            abort_unless($access->userCanAccess($user, 'sales_bayi'), 403);

            return $scopeKey;
        }

        if ($access->userCanAccess($user, 'sales_main')) {
            return $scopeKey;
        }

        if ($scopeKey === 'all' && $access->userCanAccess($user, 'sales_online')) {
            return 'online_perakende';
        }

        if ($scopeKey === 'all' && $access->userCanAccess($user, 'sales_bayi')) {
            return 'bayi_proje';
        }

        abort(403);
    }
}
