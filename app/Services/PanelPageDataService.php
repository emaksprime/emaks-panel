<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\Page;
use App\Models\User;
use Carbon\CarbonImmutable;
use RuntimeException;

class PanelPageDataService
{
    public function __construct(
        private readonly PanelDataSourceManager $dataSources,
        private readonly PanelAccessService $access,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function dataset(User $user, string $pageCode, array $input = []): array
    {
        $page = Page::query()
            ->with('pageConfig.dataSource')
            ->where('code', str_replace('-', '_', $pageCode))
            ->where('active', true)
            ->firstOrFail();

        if (! $this->access->userCanAccess($user, $page->resource_code ?? $page->code)) {
            abort(403);
        }

        $source = $page->pageConfig?->dataSource;
        $filters = $this->normalizeFilters($input);

        if (! $source || ! $source->active) {
            return $this->emptyDataset($page, $filters, 'Bu ekran için aktif veri kaynağı tanımlı değil.');
        }

        if ($source->db_type === 'n8n_json' && trim((string) $source->query_template) === '') {
            return $this->emptyDataset($page, $filters, $this->missingQueryMessage($page));
        }

        $payload = $this->payloadFor($source, $filters, $user);
        $result = $this->dataSources->execute($source, $payload);
        $rows = $this->rowsFrom($result);

        return [
            'page' => [
                'code' => $page->code,
                'title' => $page->name,
                'routePath' => $page->route,
            ],
            'filters' => $filters,
            'columns' => $this->columnsFor($rows),
            'rows' => $rows,
            'queryMeta' => [
                'dataSource' => $source->code,
                'driver' => $source->db_type,
                'mode' => $source->db_type === 'n8n_json' ? 'live' : $source->db_type,
                'notice' => $rows === []
                    ? 'Seçili filtrelerde kayıt bulunamadı.'
                    : 'Canlı veri alındı.',
                'gatewayMeta' => $result['meta'] ?? null,
                'gatewayRequest' => $result['request'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function datasetForSource(User $user, string $sourceCode, string $resourceCode, array $input = []): array
    {
        $source = DataSource::query()
            ->where('code', str_replace('-', '_', $sourceCode))
            ->where('active', true)
            ->firstOrFail();

        $page = new Page([
            'code' => $source->code,
            'name' => $source->name,
            'route' => '/api/data/'.$source->code,
            'resource_code' => $resourceCode,
        ]);

        $filters = $this->normalizeFilters($input);

        if (! $this->userCanAccessSource($user, $source->code, $resourceCode)) {
            abort(403);
        }

        if ($source->code === 'sales_customer_search') {
            $filters['scope_key'] = $this->normalizeSalesCustomerSearchScope($user, $filters['scope_key']);
        }

        if ($source->db_type === 'n8n_json' && trim((string) $source->query_template) === '') {
            return $this->emptyDataset($page, $filters, $this->missingQueryMessage($page));
        }

        $payload = $this->payloadFor($source, $filters, $user);
        $result = $this->dataSources->execute($source, $payload);
        $rows = $this->rowsFrom($result);

        return [
            'page' => [
                'code' => $page->code,
                'title' => $page->name,
                'routePath' => $page->route,
            ],
            'filters' => $filters,
            'columns' => $this->columnsFor($rows),
            'rows' => $rows,
            'queryMeta' => [
                'dataSource' => $source->code,
                'driver' => $source->db_type,
                'mode' => $source->db_type === 'n8n_json' ? 'live' : $source->db_type,
                'notice' => $rows === []
                    ? 'Seçili filtrelerde kayıt bulunamadı.'
                    : 'Canlı veri alındı.',
                'gatewayMeta' => $result['meta'] ?? null,
                'gatewayRequest' => $result['request'] ?? null,
            ],
        ];
    }

    private function userCanAccessSource(User $user, string $sourceCode, string $resourceCode): bool
    {
        if ($sourceCode === 'sales_customer_search') {
            return $this->access->userCanAccess($user, 'sales_main')
                || $this->access->userCanAccess($user, 'sales_online')
                || $this->access->userCanAccess($user, 'sales_bayi');
        }

        return $this->access->userCanAccess($user, $resourceCode);
    }

    private function normalizeSalesCustomerSearchScope(User $user, string $scopeKey): string
    {
        $scopeKey = $this->normalizeScopeKey($scopeKey);

        if ($this->access->userCanAccess($user, 'sales_main_all')) {
            return $scopeKey;
        }

        if ($scopeKey === 'online_perakende') {
            abort_unless($this->access->userCanAccess($user, 'sales_online'), 403);

            return $scopeKey;
        }

        if ($scopeKey === 'bayi_proje') {
            abort_unless($this->access->userCanAccess($user, 'sales_bayi'), 403);

            return $scopeKey;
        }

        if ($this->access->userCanAccess($user, 'sales_main')) {
            return $scopeKey;
        }

        if ($scopeKey === 'all' && $this->access->userCanAccess($user, 'sales_online')) {
            return 'online_perakende';
        }

        if ($scopeKey === 'all' && $this->access->userCanAccess($user, 'sales_bayi')) {
            return 'bayi_proje';
        }

        abort(403);
    }

    private function normalizeScopeKey(string $scopeKey): string
    {
        $scopeKey = trim($scopeKey);

        return str_replace('-', '_', $scopeKey !== '' ? $scopeKey : 'all');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function payloadFor(DataSource $source, array $filters, User $user): array
    {
        $representativeCode = trim((string) ($user->temsilci_kodu ?? '')) ?: null;

        if (str_starts_with($source->code, 'sales_') && $this->access->userCanAccess($user, 'sales_main_all')) {
            $representativeCode = null;
        }

        $payload = [
            ...$filters,
            'rep_code' => $representativeCode,
            'role_code' => $user->role_code,
            'search' => $filters['search'] ?? null,
            'page' => $filters['page'] ?? 1,
            'bypass_cache' => (bool) ($filters['bypass_cache'] ?? false),
        ];

        $allowed = $source->allowed_params ?? [];

        if ($allowed === []) {
            return $payload;
        }

        return collect($payload)
            ->only([...$allowed, 'role_code', 'bypass_cache'])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private function rowsFrom(array $result): array
    {
        $rows = $result['rows'] ?? [];

        if (! is_array($rows)) {
            throw new RuntimeException('Veri kaynagi rows alanini dizi olarak dondurmedi.');
        }

        return array_values(array_filter($rows, function (mixed $row): bool {
            if (! is_array($row)) {
                return false;
            }

            $keys = array_map('strtolower', array_keys($row));
            $message = (string) ($row['message'] ?? $row['Message'] ?? '');

            if (count($keys) === 1 && in_array($keys[0], ['message', 'bilgi'], true)) {
                return false;
            }

            return ! str_contains(strtolower($message), 'query executed successfully');
        }));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{key: string, label: string}>
     */
    private function columnsFor(array $rows): array
    {
        return collect($rows)
            ->take(25)
            ->flatMap(fn (array $row) => array_keys($row))
            ->unique()
            ->values()
            ->map(fn (string $key) => [
                'key' => $key,
                'label' => $this->labelFor($key),
            ])
            ->all();
    }

    private function labelFor(string $key): string
    {
        return mb_convert_case(str_replace('_', ' ', $key), MB_CASE_TITLE, 'UTF-8');
    }

    private function missingQueryMessage(Page $page): string
    {
        return match ($page->resource_code ?? $page->code) {
            'customers', 'customer_detail' => 'Müşteri veri kaynağı henüz tanımlı değil.',
            'proforma' => 'Proforma veri kaynağı henüz tanımlı değil.',
            'stock', 'stock_critical', 'stock_warehouse' => 'Stok veri kaynağı henüz tanımlı değil.',
            'orders', 'orders_alinan', 'orders_verilen' => 'Sipariş veri kaynağı henüz tanımlı değil.',
            default => 'Veri kaynağı henüz tanımlı değil.',
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $input): array
    {
        $grain = in_array(($input['grain'] ?? 'week'), ['day', 'week', 'month', 'year'], true)
            ? (string) ($input['grain'] ?? 'week')
            : 'week';

        $today = CarbonImmutable::now();

        return [
            'date_from' => $this->normalizeDate($input['date_from'] ?? null, $grain, true, $today),
            'date_to' => $this->normalizeDate($input['date_to'] ?? null, $grain, false, $today),
            'grain' => $grain,
            'detail_type' => in_array(($input['detail_type'] ?? 'cari'), ['cari', 'urun'], true)
                ? (string) ($input['detail_type'] ?? 'cari')
                : 'cari',
            'scope_key' => (string) ($input['scope_key'] ?? 'all'),
            'customer_filter' => $this->normalizeListFilter($input['customer_filter'] ?? $input['cari_filter'] ?? ''),
            'cari_filter' => $this->normalizeListFilter($input['cari_filter'] ?? $input['customer_filter'] ?? ''),
            'customer_code' => (string) ($input['customer_code'] ?? ''),
            'proforma_no' => (string) ($input['proforma_no'] ?? ''),
            'price_list' => $input['price_list'] ?? null,
            'discount_code' => (string) ($input['discount_code'] ?? ''),
            'search' => (string) ($input['search'] ?? ''),
            'page' => (string) max(1, (int) ($input['page'] ?? 1)),
            'limit' => max(1, min(500, (int) ($input['limit'] ?? 100))),
            'bypass_cache' => (bool) ($input['bypass_cache'] ?? false),
        ];
    }

    private function normalizeListFilter(mixed $value): string
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item) => trim((string) $item))
                ->filter()
                ->unique()
                ->implode(',');
        }

        return collect(explode(',', (string) $value))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->unique()
            ->implode(',');
    }

    private function normalizeDate(mixed $value, string $grain, bool $isStart, CarbonImmutable $today): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return match ($grain) {
            'day' => $today->format('Y-m-d'),
            'month' => ($isStart ? $today->startOfMonth() : $today)->format('Y-m-d'),
            'year' => ($isStart ? $today->startOfYear() : $today)->format('Y-m-d'),
            default => ($isStart ? $today->startOfWeek(\Carbon\WeekDay::Monday) : $today)->format('Y-m-d'),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function emptyDataset(Page $page, array $filters, string $notice): array
    {
        return [
            'page' => [
                'code' => $page->code,
                'title' => $page->name,
                'routePath' => $page->route,
            ],
            'filters' => $filters,
            'columns' => [],
            'rows' => [],
            'queryMeta' => [
                'dataSource' => null,
                'driver' => null,
                'mode' => 'empty',
                'notice' => $notice,
            ],
        ];
    }
}
