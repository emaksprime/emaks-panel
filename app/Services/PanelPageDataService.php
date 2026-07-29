<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\Page;
use App\Models\User;
use App\Models\UserCariGroupPermission;
use Carbon\CarbonImmutable;
use Carbon\WeekDay;
use RuntimeException;

class PanelPageDataService
{
    private const SALES_DATA_SOURCE_CODES = [
        'sales_main_dashboard',
        'sales_online_perakende_detail',
        'sales_bayi_proje_detail',
        'sales_customer_search',
    ];

    public function __construct(
        private readonly PanelDataSourceManager $dataSources,
        private readonly PanelAccessService $access,
    ) {}

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

        $stockScope = $this->stockScopeFor($source, $page, $user);
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
                'stockScope' => $stockScope,
                ...$this->ordersAlinanDebugMeta($source, $user, $payload),
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
            $customerSearchScope = $this->salesCustomerSearchScopeFor($user, $filters['scope_key']);
            $filters['scope_key'] = $customerSearchScope['scope_key'];
            $filters['rep_code'] = $customerSearchScope['rep_code'] ?? '';
        }

        if ($source->db_type === 'n8n_json' && trim((string) $source->query_template) === '') {
            return $this->emptyDataset($page, $filters, $this->missingQueryMessage($page));
        }

        $stockScope = $this->stockScopeFor($source, $page, $user);
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
                'stockScope' => $stockScope,
                ...$this->ordersAlinanDebugMeta($source, $user, $payload),
            ],
        ];
    }

    private function stockScopeFor(DataSource $source, Page $page, User $user): ?string
    {
        if ($source->code !== 'stock_dashboard' && ! in_array($page->code, ['stock', 'stock_critical'], true)) {
            return null;
        }

        $scope = $this->access->stockScopeFor($user);

        abort_unless($scope !== null, 403);

        return $scope;
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

    /**
     * @return array{scope_key: string, rep_code: string|null}
     */
    private function salesCustomerSearchScopeFor(User $user, string $scopeKey): array
    {
        $scopeKey = $this->normalizeScopeKey($scopeKey);

        if ($this->access->isPrivileged($user) || $this->access->userCanAccess($user, 'sales_main_all')) {
            return [
                'scope_key' => $scopeKey,
                'rep_code' => $this->salesCustomerSearchRepCodeForScope($scopeKey),
            ];
        }

        $ownScope = $this->ownSalesRepresentativeScopeKey($user);
        $isOwnRepresentativeSalesUser = $ownScope !== null && $this->access->userCanAccess($user, 'sales_main');

        if ($isOwnRepresentativeSalesUser && in_array($scopeKey, ['online_perakende', 'bayi_proje'], true)) {
            abort(403);
        }

        if ($scopeKey === 'online_perakende') {
            abort_unless($this->access->userCanAccess($user, 'sales_online'), 403);

            return ['scope_key' => $scopeKey, 'rep_code' => null];
        }

        if ($scopeKey === 'bayi_proje') {
            abort_unless($this->access->userCanAccess($user, 'sales_bayi'), 403);

            return ['scope_key' => $scopeKey, 'rep_code' => null];
        }

        $representativeScopeRepCodes = $this->salesRepresentativeScopeRepCodes();

        if (array_key_exists($scopeKey, $representativeScopeRepCodes)) {
            abort_unless(
                $this->access->userCanAccess($user, $this->salesRepresentativeScopeResources()[$scopeKey])
                    || $this->ownSalesRepresentativeScopeKey($user) === $scopeKey,
                403,
            );

            return [
                'scope_key' => $scopeKey,
                'rep_code' => $representativeScopeRepCodes[$scopeKey],
            ];
        }

        if ($scopeKey === 'all') {
            $availableScopes = collect();

            if (! $isOwnRepresentativeSalesUser && $this->access->userCanAccess($user, 'sales_online')) {
                $availableScopes->push(['scope_key' => 'online_perakende', 'rep_code' => null]);
            }

            if (! $isOwnRepresentativeSalesUser && $this->access->userCanAccess($user, 'sales_bayi')) {
                $availableScopes->push(['scope_key' => 'bayi_proje', 'rep_code' => null]);
            }

            if ($ownScope !== null && $this->access->userCanAccess($user, 'sales_main')) {
                $availableScopes->push([
                    'scope_key' => $ownScope,
                    'rep_code' => $representativeScopeRepCodes[$ownScope] ?? trim((string) ($user->temsilci_kodu ?? '')),
                ]);
            }

            $availableScopes = $availableScopes
                ->unique(fn (array $scope): string => $scope['scope_key'])
                ->values();

            if ($availableScopes->count() === 1) {
                return $availableScopes->first();
            }
        }

        abort(403);
    }

    private function salesCustomerSearchRepCodeForScope(string $scopeKey): ?string
    {
        return $this->salesRepresentativeScopeRepCodes()[$scopeKey] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function salesRepresentativeScopeRepCodes(): array
    {
        return [
            'umit' => '0003',
            'bulent_saglam' => '0035',
            'mehmet_can' => '0039',
            'orkun_genc' => '0040',
        ];
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

    private function ownSalesRepresentativeScopeKey(User $user): ?string
    {
        return match (trim((string) ($user->temsilci_kodu ?? ''))) {
            '0003' => 'umit',
            '0035' => 'bulent_saglam',
            '0039' => 'mehmet_can',
            '0040' => 'orkun_genc',
            default => null,
        };
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
        $customerScopeKey = $filters['customer_scope_key'] ?? null;
        $customerGroupScope = $filters['customer_group_scope'] ?? null;

        if ($source->code === 'sales_customer_search') {
            $representativeCode = trim((string) ($filters['rep_code'] ?? '')) ?: null;
        } elseif (str_starts_with($source->code, 'sales_') && $this->access->userCanAccess($user, 'sales_main_all')) {
            $representativeCode = null;
        }

        if ($this->isCustomerDataSource($source->code)) {
            [$customerScopeKey, $representativeCode] = $this->customerScopeFor($user);
            $customerGroupScope = $customerScopeKey;
        }

        $ordersScope = $filters['orders_scope'] ?? null;

        if ($source->code === 'orders_alinan') {
            [$ordersScope, $representativeCode] = $this->ordersAlinanScopeFor($user, $representativeCode);
        }

        $payload = [
            ...$filters,
            'rep_code' => $representativeCode,
            'orders_scope' => $ordersScope,
            'customer_scope_key' => $customerScopeKey,
            'customer_group_scope' => $customerGroupScope,
            'role_code' => $user->role_code,
            'search' => $filters['search'] ?? null,
            'page' => $filters['page'] ?? 1,
            'bypass_cache' => (bool) ($filters['bypass_cache'] ?? false),
        ];

        if ($this->isSalesDataSource($source->code)) {
            $payload = [
                ...$payload,
                ...$this->salesCariGroupFiltersFor($user),
            ];
        }

        $allowed = $source->allowed_params ?? [];

        if ($allowed === []) {
            return $payload;
        }

        return collect($payload)
            ->only([...$allowed, 'role_code', 'bypass_cache'])
            ->all();
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function ordersAlinanScopeFor(User $user, ?string $fallbackRepresentativeCode): array
    {
        if ($this->access->isPrivileged($user)) {
            return ['all', null];
        }

        $canSeeAll = ! $this->access->userHasDenyOverride($user, 'orders_alinan_all')
            && $this->access->userCanAccess($user, 'orders_alinan_all');

        if ($canSeeAll) {
            return ['all', null];
        }

        $representativeCode = trim((string) ($user->temsilci_kodu ?? '')) ?: null;

        if ($this->access->userCanAccess($user, 'orders_alinan_temsilci')) {
            return ['temsilci', $representativeCode ?? '__NO_REP_CODE__'];
        }

        return ['temsilci', $fallbackRepresentativeCode ?? '__NO_REP_CODE__'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ordersAlinanDebugMeta(DataSource $source, User $user, array $payload): array
    {
        if ($source->code !== 'orders_alinan') {
            return [];
        }

        $deniedAll = $this->access->userHasDenyOverride($user, 'orders_alinan_all');

        return [
            'ordersScope' => $payload['orders_scope'] ?? null,
            'effectiveRepCode' => $payload['rep_code'] ?? null,
            'canOrdersAlinanAll' => $this->access->isPrivileged($user)
                || (! $deniedAll && $this->access->userCanAccess($user, 'orders_alinan_all')),
            'canOrdersAlinanTemsilci' => $this->access->userCanAccess($user, 'orders_alinan_temsilci'),
            'deniedOrdersAlinanAll' => $deniedAll,
        ];
    }

    private function isCustomerDataSource(string $sourceCode): bool
    {
        return str_starts_with($sourceCode, 'customer_')
            || str_starts_with($sourceCode, 'customers_');
    }

    private function isSalesDataSource(string $sourceCode): bool
    {
        return in_array($sourceCode, self::SALES_DATA_SOURCE_CODES, true);
    }

    /**
     * @return array{allowed_cari_group_codes: string, denied_cari_group_codes: string}
     */
    private function salesCariGroupFiltersFor(User $user): array
    {
        $permissions = UserCariGroupPermission::query()
            ->where('user_id', $user->id)
            ->orderBy('cari_group_code')
            ->get(['cari_group_code', 'mode']);

        $denied = $this->safeCariGroupCodes(
            $permissions
                ->where('mode', UserCariGroupPermission::MODE_DENY)
                ->pluck('cari_group_code')
                ->all()
        );
        $allowed = array_values(array_diff($this->safeCariGroupCodes(
            $permissions
                ->where('mode', UserCariGroupPermission::MODE_ALLOW)
                ->pluck('cari_group_code')
                ->all()
        ), $denied));

        return [
            'allowed_cari_group_codes' => implode(',', $allowed),
            'denied_cari_group_codes' => implode(',', $denied),
        ];
    }

    /**
     * @param  iterable<int, mixed>  $codes
     * @return array<int, string>
     */
    private function safeCariGroupCodes(iterable $codes): array
    {
        return collect($codes)
            ->map(fn (mixed $code): string => trim((string) $code))
            ->filter(fn (string $code): bool => $code !== '' && preg_match('/^[0-9A-Za-zÇĞİÖŞÜçğıöşü_.-]+$/u', $code) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function customerScopeFor(User $user): array
    {
        if ($this->access->isPrivileged($user) || $this->access->userCanAccess($user, 'customers_all')) {
            return ['all', null];
        }

        $canSeeOnline = $this->access->userCanAccess($user, 'customers_online');
        $canSeeBayi = $this->access->userCanAccess($user, 'customers_bayi');

        if ($canSeeOnline && $canSeeBayi) {
            return ['all_segments', null];
        }

        if ($canSeeOnline) {
            return ['online_perakende', null];
        }

        if ($canSeeBayi) {
            return ['bayi_proje', null];
        }

        if ($this->access->userCanAccess($user, 'customers_own_rep')) {
            return ['own_rep', trim((string) ($user->temsilci_kodu ?? '')) ?: null];
        }

        abort(403);
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
            'brand_filter' => $this->normalizeBrandFilter($input['brand_filter'] ?? 'all'),
            'category_filter' => $this->normalizeCategoryFilter($input['category_filter'] ?? 'all'),
            'product_filter' => $this->normalizeListFilter($input['product_filter'] ?? ''),
            'delivery_week' => $this->normalizeDeliveryWeek($input['delivery_week'] ?? 'all'),
            'delivery_date' => $this->normalizeOptionalDate($input['delivery_date'] ?? null),
            'orders_scope' => (string) ($input['orders_scope'] ?? ''),
            'rep_code' => (string) ($input['rep_code'] ?? ''),
            'customer_code' => (string) ($input['customer_code'] ?? ''),
            'guid' => (string) ($input['guid'] ?? ''),
            'hareket_guid' => (string) ($input['hareket_guid'] ?? ''),
            'document_guid' => (string) ($input['document_guid'] ?? ''),
            'evrak_guid' => (string) ($input['evrak_guid'] ?? ''),
            'proforma_no' => (string) ($input['proforma_no'] ?? ''),
            'price_list' => $input['price_list'] ?? null,
            'discount_code' => (string) ($input['discount_code'] ?? ''),
            'search' => (string) ($input['search'] ?? ''),
            'status' => (string) ($input['status'] ?? ''),
            'page' => (string) max(1, (int) ($input['page'] ?? 1)),
            'limit' => max(1, min(500, (int) ($input['limit'] ?? 100))),
            'bypass_cache' => (bool) ($input['bypass_cache'] ?? false),
        ];
    }

    private function normalizeBrandFilter(mixed $value): string
    {
        $normalized = str_replace('-', '_', strtolower(trim((string) $value)));

        return in_array($normalized, ['philips', 'emaks_prime'], true) ? $normalized : 'all';
    }

    private function normalizeCategoryFilter(mixed $value): string
    {
        $normalized = strtoupper(trim((string) $value));

        return $normalized !== '' ? $normalized : 'all';
    }

    private function normalizeDeliveryWeek(mixed $value): string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : 'all';
    }

    private function normalizeOptionalDate(mixed $value): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? $value
            : '';
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
            default => ($isStart ? $today->startOfWeek(WeekDay::Monday) : $today)->format('Y-m-d'),
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
