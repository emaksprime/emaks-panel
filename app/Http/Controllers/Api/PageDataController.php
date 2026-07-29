<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Models\Page;
use App\Services\AuditLogger;
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
        AuditLogger $auditLogger,
    ): JsonResponse {
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
            'brand_filter' => ['nullable', 'string', 'max:50'],
            'category_filter' => ['nullable', 'string', 'max:50'],
            'product_filter' => ['nullable', 'string', 'max:1000'],
            'delivery_week' => ['nullable', 'string', 'max:120'],
            'delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'customer_code' => ['nullable', 'string', 'max:80'],
            'proforma_no' => ['nullable', 'string', 'max:80'],
            'guid' => ['nullable', 'string', 'max:120'],
            'hareket_guid' => ['nullable', 'string', 'max:120'],
            'document_guid' => ['nullable', 'string', 'max:120'],
            'evrak_guid' => ['nullable', 'string', 'max:120'],
            'price_list' => ['nullable', 'integer'],
            'discount_code' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:40'],
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
            $dataset = $page !== null
                ? $pageData->dataset($user, $code, $validated)
                : $pageData->datasetForSource($user, (string) $source?->code, (string) $sourceResourceCode, $validated);

            if (($source?->code ?? null) === 'sales_customer_search') {
                $this->logSalesCustomerSearch($auditLogger, $request, $validated, $dataset);
            }

            return response()->json($dataset);
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

        if ($access->isPrivileged($user) || $access->userCanAccess($user, 'sales_main_all')) {
            if (array_key_exists($scopeKey, $this->salesRepresentativeScopeResources())) {
                return $scopeKey;
            }

            return $scopeKey;
        }

        $ownScope = $this->ownSalesRepresentativeScopeKey($user);
        $isOwnRepresentativeSalesUser = $ownScope !== null && $access->userCanAccess($user, 'sales_main');

        if ($isOwnRepresentativeSalesUser && in_array($scopeKey, ['online_perakende', 'bayi_proje'], true)) {
            abort(403);
        }

        if ($scopeKey === 'online_perakende') {
            abort_unless($access->userCanAccess($user, 'sales_online'), 403);

            return $scopeKey;
        }

        if ($scopeKey === 'bayi_proje') {
            abort_unless($access->userCanAccess($user, 'sales_bayi'), 403);

            return $scopeKey;
        }

        $representativeResources = $this->salesRepresentativeScopeResources();

        if (array_key_exists($scopeKey, $representativeResources)) {
            abort_unless(
                $access->userCanAccess($user, $representativeResources[$scopeKey])
                    || $this->ownSalesRepresentativeScopeKey($user) === $scopeKey,
                403,
            );

            return $scopeKey;
        }

        if ($scopeKey === 'all') {
            $availableScopes = collect();

            if (! $isOwnRepresentativeSalesUser && $access->userCanAccess($user, 'sales_online')) {
                $availableScopes->push('online_perakende');
            }

            if (! $isOwnRepresentativeSalesUser && $access->userCanAccess($user, 'sales_bayi')) {
                $availableScopes->push('bayi_proje');
            }

            if ($ownScope !== null && $access->userCanAccess($user, 'sales_main')) {
                $availableScopes->push($ownScope);
            }

            $availableScopes = $availableScopes->unique()->values();

            if ($availableScopes->count() === 1) {
                return (string) $availableScopes->first();
            }
        }

        abort(403);
    }

    /**
     * @return array<string, string>
     */
    private function salesRepresentativeScopeResources(): array
    {
        return [
            'umit' => 'sales_rep_umit_yildiz',
            'bulent_saglam' => 'sales_rep_bulent_saglam',
            'mehmet_can' => 'sales_rep_mehmet_can',
            'orkun_genc' => 'sales_rep_orkun_genc',
        ];
    }

    private function ownSalesRepresentativeScopeKey(mixed $user): ?string
    {
        return match (trim((string) ($user?->temsilci_kodu ?? ''))) {
            '0003' => 'umit',
            '0035' => 'bulent_saglam',
            '0039' => 'mehmet_can',
            '0040' => 'orkun_genc',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $dataset
     */
    private function logSalesCustomerSearch(AuditLogger $auditLogger, Request $request, array $validated, array $dataset): void
    {
        $search = trim((string) ($validated['search'] ?? ''));

        if (strlen($search) < 2) {
            return;
        }

        $gatewayRequest = $dataset['queryMeta']['gatewayRequest'] ?? [];
        $params = is_array($gatewayRequest['params'] ?? null) ? $gatewayRequest['params'] : [];
        $filters = is_array($dataset['filters'] ?? null) ? $dataset['filters'] : [];

        $auditLogger->log($request->user(), 'sales.customer.search', [
            'page' => 'sales_main',
            'search' => $search,
            'scope_key' => $params['scope_key'] ?? $filters['scope_key'] ?? $validated['scope_key'] ?? null,
            'rep_code' => $params['rep_code'] ?? $filters['rep_code'] ?? $validated['rep_code'] ?? null,
            'date_from' => $params['date_from'] ?? $filters['date_from'] ?? $validated['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? $filters['date_to'] ?? $validated['date_to'] ?? null,
            'result_count' => count(is_array($dataset['rows'] ?? null) ? $dataset['rows'] : []),
        ], $request);
    }
}
