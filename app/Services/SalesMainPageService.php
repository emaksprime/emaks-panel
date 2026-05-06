<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\Page;
use App\Models\PageConfig;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SalesMainPageService
{
    public function __construct(
        private readonly PanelNavigationService $navigation,
        private readonly PanelDataSourceManager $dataSources,
        private readonly PanelAccessService $access,
        private readonly N8nPanelDataGateway $n8nGateway,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function config(?User $user, string $pageCode = 'sales_main'): array
    {
        $page = $this->page($pageCode);
        $pageConfig = $this->pageConfig();
        $layout = $pageConfig->layout_json ?? [];
        $filters = $pageConfig->filters_json ?? [];
        $scopes = $this->visibleScopes($user, $this->configuredManagementScopes($filters));
        $defaultScopeKey = $this->defaultScopeKeyForPage($pageCode);
        $scope = $scopes->first(fn (array $scope) => $this->normalizeScopeKey((string) ($scope['key'] ?? '')) === $defaultScopeKey)
            ?? $scopes->first();
        $source = $this->sourceForScope($scope ?? ['key' => 'all']) ?? $pageConfig->dataSource ?? $this->source();

        return [
            'page' => [
                'title' => $page->name,
                'description' => $page->description,
                'routePath' => $page->route,
                'component' => $page->component,
            ],
            'topNav' => $layout['topNav'] ?? [],
            'grains' => $filters['grains'] ?? [],
            'detailModes' => $filters['detailModes'] ?? [],
            'managementScopes' => $scopes
                ->values()
                ->map(fn (array $scope) => [
                    ...$scope,
                    'key' => $this->normalizeScopeKey((string) ($scope['key'] ?? '')),
                ])
                ->all(),
            'defaults' => [
                'grain' => $filters['defaults']['grain'] ?? 'week',
                'detailType' => $filters['defaults']['detailType'] ?? 'cari',
                'scopeKey' => $this->normalizeScopeKey((string) ($scope['key'] ?? 'all')),
            ],
            'dataSource' => [
                'slug' => $source->code,
                'status' => $source->active ? 'active' : 'inactive',
                'drivers' => $this->dataSources->drivers()->values()->all(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function dataset(?User $user, array $input = []): array
    {
        $filters = $this->normalizeFilters($input);
        $page = $this->page();
        $scope = $this->resolveScope($user, $filters['scope_key']);
        $normalizedScopeKey = $this->normalizeScopeKey((string) ($scope['key'] ?? $filters['scope_key']));
        $filters['scope_key'] = $normalizedScopeKey;
        $source = $this->sourceForScope($scope) ?? $this->pageConfig()->dataSource ?? $this->source();

        $effectiveRepresentativeCode = $this->effectiveRepresentativeCode($user, $scope);
        $whitelistedParameters = $this->whitelistedParameters($source, $filters, $effectiveRepresentativeCode, $input);

        $this->compileTemplate($source, $whitelistedParameters);

        $gatewayResult = null;
        $rows = $this->usesN8nGateway($source)
            ? collect($this->fetchN8nRows($source, $filters, $scope, $effectiveRepresentativeCode, $whitelistedParameters, $gatewayResult))
            : collect($source->preview_payload[$filters['detail_type']] ?? []);

        if ($rows->isEmpty()) {
            $fallbackSource = $this->fallbackSourceForEmptyRows($source, $normalizedScopeKey);

            if ($fallbackSource !== null) {
                $source = $fallbackSource;
                $whitelistedParameters = $this->whitelistedParameters($source, $filters, $effectiveRepresentativeCode, $input);
                $this->compileTemplate($source, $whitelistedParameters);

                $rows = $this->usesN8nGateway($source)
                    ? collect($this->fetchN8nRows($source, $filters, $scope, $effectiveRepresentativeCode, $whitelistedParameters, $gatewayResult))
                    : collect($source->preview_payload[$filters['detail_type']] ?? []);
            }
        }

        if ($rows->isEmpty()) {
            return $this->emptySalesDataset($user, $page, $source, $scope, $filters, $normalizedScopeKey, $effectiveRepresentativeCode, $whitelistedParameters, $gatewayResult);
        }

        $groupRows = $rows
            ->where('satir_tipi', 'GRUP')
            ->sortBy('siralama_1')
            ->values();

        $detailRows = $rows
            ->filter(fn (array $row) => ($row['satir_tipi'] ?? null) !== 'GRUP')
            ->values();

        $totalGroupRows = $groupRows
            ->reject(fn (array $row): bool => $this->rowExcludedFromTotal($row))
            ->values();

        $positiveTotal = $totalGroupRows->where('ciro', '>', 0)->sum('ciro');
        $netTotal = $totalGroupRows->sum('ciro');
        $konsinye = (float) ($rows->first()['konsinye_tutari'] ?? 0);
        $periodLabel = $this->periodLabel($filters['date_from'], $filters['date_to']);
        $isLive = $this->usesN8nGateway($source);

        return [
            'filters' => [
                'dateFrom' => $filters['date_from'],
                'dateTo' => $filters['date_to'],
                'grain' => $filters['grain'],
                'detailType' => $filters['detail_type'],
                'scopeKey' => $normalizedScopeKey,
                'periodLabel' => $periodLabel,
                'customerFilter' => $filters['cari_filter'],
                'brandFilter' => $filters['brand_filter'],
                'categoryFilter' => $filters['category_filter'],
                'productFilter' => $filters['product_filter'],
            ],
            'scope' => [
                'key' => $normalizedScopeKey,
                'label' => $scope['label'],
                'note' => $scope['note'],
                'effectiveRepresentativeCode' => $effectiveRepresentativeCode,
                'canSeeAll' => $this->access->userCanAccess($user, 'sales_main_all'),
            ],
            'kpis' => [
                [
                    'label' => 'Toplam Net Ciro',
                    'value' => $this->money($netTotal),
                    'raw' => $netTotal,
                ],
                [
                    'label' => 'Seçili Dönem',
                    'value' => $periodLabel,
                    'raw' => $periodLabel,
                ],
                [
                    'label' => 'Konsinye Hariç',
                    'value' => $this->money($konsinye),
                    'raw' => $konsinye,
                ],
                [
                    'label' => 'Aktif Kapsam',
                    'value' => $scope['label'],
                    'raw' => $normalizedScopeKey,
                ],
            ],
            'chart' => [
                'title' => $this->chartTitle($filters),
                'subtitle' => $filters['detail_type'] === 'urun'
                    ? 'Ürün ve model bazlı payların dağılımı.'
                    : 'Satış gruplarının toplam ciro içindeki payları.',
                'totalNet' => $netTotal,
                'totalNetLabel' => $this->money($netTotal),
                'konsinyeAmount' => $konsinye,
                'items' => $this->chartItems($groupRows, $detailRows, $filters, $positiveTotal),
            ],
            'breakdown' => [
                'mode' => $filters['detail_type'],
                'title' => $filters['detail_type'] === 'urun' ? 'Ürün / Müşteri Özeti' : 'Satış Detayı',
                'groups' => $this->breakdownGroups($filters['detail_type'], $groupRows, $detailRows),
            ],
            'table' => [
                'columns' => [
                    ['key' => 'label', 'label' => 'Başlık'],
                    ['key' => 'quantity', 'label' => 'Adet'],
                    ['key' => 'amount', 'label' => 'Ciro'],
                ],
                'rows' => $this->breakdownGroups($filters['detail_type'], $groupRows, $detailRows),
            ],
            'queryMeta' => [
                'dataSource' => $source->code,
                'driver' => $source->db_type,
                'status' => $source->active ? 'active' : 'inactive',
                'mode' => $isLive ? 'live' : 'preview',
                'notice' => $isLive ? 'Canlı veri alındı.' : 'Önizleme verisi; canlı veri kaynağı henüz bağlı değil.',
                'whitelistedParameters' => $whitelistedParameters,
                'gatewayMeta' => $gatewayResult['meta'] ?? null,
                'gatewayRequest' => $gatewayResult['request'] ?? null,
            ],
            'navigation' => $this->navigation->sharedForUser($user, $page->route),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $whitelistedParameters
     * @param  array<string, mixed>|null  $gatewayResult
     * @return array<string, mixed>
     */
    private function emptySalesDataset(
        ?User $user,
        Page $page,
        DataSource $source,
        array $scope,
        array $filters,
        string $normalizedScopeKey,
        ?string $effectiveRepresentativeCode,
        array $whitelistedParameters,
        ?array $gatewayResult,
    ): array {
        $periodLabel = $this->periodLabel($filters['date_from'], $filters['date_to']);
        $isLive = $this->usesN8nGateway($source);
        $notice = $filters['cari_filter'] !== ''
            ? 'Seçili müşteri için bu kapsam/dönemde satış kaydı bulunamadı.'
            : 'Seçili filtrelerde satış kaydı bulunamadı.';

        return [
            'filters' => [
                'dateFrom' => $filters['date_from'],
                'dateTo' => $filters['date_to'],
                'grain' => $filters['grain'],
                'detailType' => $filters['detail_type'],
                'scopeKey' => $normalizedScopeKey,
                'periodLabel' => $periodLabel,
                'customerFilter' => $filters['cari_filter'],
                'brandFilter' => $filters['brand_filter'],
                'categoryFilter' => $filters['category_filter'],
                'productFilter' => $filters['product_filter'],
            ],
            'scope' => [
                'key' => $normalizedScopeKey,
                'label' => $scope['label'],
                'note' => $scope['note'],
                'effectiveRepresentativeCode' => $effectiveRepresentativeCode,
                'canSeeAll' => $this->access->userCanAccess($user, 'sales_main_all'),
            ],
            'kpis' => [
                ['label' => 'Toplam Net Ciro', 'value' => $this->money(0), 'raw' => 0],
                ['label' => 'Seçili Dönem', 'value' => $periodLabel, 'raw' => $periodLabel],
                ['label' => 'Konsinye Hariç', 'value' => $this->money(0), 'raw' => 0],
                ['label' => 'Aktif Kapsam', 'value' => $scope['label'], 'raw' => $normalizedScopeKey],
            ],
            'chart' => [
                'title' => $this->chartTitle($filters),
                'subtitle' => $filters['detail_type'] === 'urun'
                    ? 'Ürün ve model bazlı payların dağılımı.'
                    : 'Satış gruplarının toplam ciro içindeki payları.',
                'totalNet' => 0,
                'totalNetLabel' => $this->money(0),
                'konsinyeAmount' => 0,
                'items' => [],
            ],
            'breakdown' => [
                'mode' => $filters['detail_type'],
                'title' => $filters['detail_type'] === 'urun' ? 'Ürün / Müşteri Özeti' : 'Satış Detayı',
                'groups' => [],
            ],
            'table' => [
                'columns' => [
                    ['key' => 'label', 'label' => 'Başlık'],
                    ['key' => 'quantity', 'label' => 'Adet'],
                    ['key' => 'amount', 'label' => 'Ciro'],
                ],
                'rows' => [],
            ],
            'queryMeta' => [
                'dataSource' => $source->code,
                'driver' => $source->db_type,
                'status' => $source->active ? 'active' : 'inactive',
                'mode' => $isLive ? 'live' : 'preview',
                'notice' => $notice,
                'whitelistedParameters' => $whitelistedParameters,
                'gatewayMeta' => $gatewayResult['meta'] ?? null,
                'gatewayRequest' => $gatewayResult['request'] ?? null,
            ],
            'navigation' => $this->navigation->sharedForUser($user, $page->route),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveScope(?User $user, string $scopeKey): array
    {
        $scopes = $this->visibleScopes($user, $this->configuredManagementScopes($this->pageConfig()->filters_json ?? []));
        $normalizedScopeKey = $this->normalizeScopeKey($scopeKey);
        $scope = $scopes->first(fn (array $scope) => $this->normalizeScopeKey((string) ($scope['key'] ?? '')) === $normalizedScopeKey);

        if ($user !== null && $scopes->isEmpty()) {
            abort(403);
        }

        return $scope ?? $scopes->first() ?? [
            'key' => 'all',
            'label' => 'Tümü',
            'repCode' => null,
            'navigateTo' => null,
            'note' => '',
            'salesView' => 'tumu',
            'allowAll' => true,
        ];
    }

    private function normalizeScopeKey(string $scopeKey): string
    {
        return str_replace('-', '_', $scopeKey);
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function whitelistedParameters(DataSource $source, array $filters, ?string $effectiveRepresentativeCode, array $input): array
    {
        $parameters = [
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'grain' => $filters['grain'],
            'detail_type' => $filters['detail_type'],
            'scope_key' => $filters['scope_key'],
            'rep_code' => $effectiveRepresentativeCode,
            'cari_filter' => $filters['cari_filter'],
            'customer_filter' => $filters['cari_filter'],
            'search' => $input['search'] ?? null,
            'page' => $input['page'] ?? 1,
            'bypass_cache' => (bool) ($input['bypass_cache'] ?? false),
        ];

        if ($filters['detail_type'] === 'urun') {
            $parameters['brand_filter'] = $filters['brand_filter'];
            $parameters['category_filter'] = $filters['category_filter'];
            $parameters['product_filter'] = $filters['product_filter'];
        }

        return collect($parameters)->only(collect($source->allowed_params ?? []))->all();
    }

    private function fallbackSourceForEmptyRows(DataSource $source, string $scopeKey): ?DataSource
    {
        if ($source->code === 'sales_main_dashboard') {
            return null;
        }

        if (! in_array($this->normalizeScopeKey($scopeKey), ['online_perakende', 'bayi_proje'], true)) {
            return null;
        }

        return DataSource::query()
            ->where('code', 'sales_main_dashboard')
            ->where('active', true)
            ->first();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $scopes
     * @return Collection<int, array<string, mixed>>
     */
    private function visibleScopes(?User $user, Collection $scopes): Collection
    {
        if ($user === null) {
            return $scopes->values();
        }

        $canSeeAll = $this->access->userCanAccess($user, 'sales_main_all');
        $canSeeOnline = $this->access->userCanAccess($user, 'sales_online');
        $canSeeBayi = $this->access->userCanAccess($user, 'sales_bayi');
        $userRepCode = trim((string) ($user?->temsilci_kodu ?? ''));

        return $scopes
            ->filter(function (array $scope) use ($user, $canSeeAll, $userRepCode, $canSeeOnline, $canSeeBayi) {
                $normalizedKey = $this->normalizeScopeKey((string) ($scope['key'] ?? ''));
                $scopeResourceCode = $this->scopeResourceCode($scope);

                if ($scopeResourceCode !== null && $this->access->userHasDenyOverride($user, $scopeResourceCode)) {
                    return false;
                }

                if ($canSeeAll) {
                    return true;
                }

                if (($scope['navigateTo'] ?? null) !== null) {
                    return match ($normalizedKey) {
                        'online_perakende' => $canSeeOnline,
                        'bayi_proje' => $canSeeBayi,
                        default => false,
                    };
                }

                if (($scope['repCode'] ?? null) === null) {
                    return false;
                }

                if ($scopeResourceCode !== null && $this->access->userCanAccess($user, $scopeResourceCode)) {
                    return true;
                }

                return $this->isOwnRepresentativeScope($userRepCode, $scope);
            })
            ->values();
    }

    private function scopeResourceCode(array $scope): ?string
    {
        $configuredResourceCode = trim((string) ($scope['resourceCode'] ?? ''));

        if ($configuredResourceCode !== '') {
            return $configuredResourceCode;
        }

        return match ($this->normalizeScopeKey((string) ($scope['key'] ?? ''))) {
            'all' => 'sales_main_all',
            'online_perakende' => 'sales_online',
            'bayi_proje' => 'sales_bayi',
            'umit' => 'sales_rep_umit_yildiz',
            'salih' => 'sales_rep_salih_cakir',
            'bulent_saglam' => 'sales_rep_bulent_saglam',
            default => null,
        };
    }

    private function isOwnRepresentativeScope(string $userRepCode, array $scope): bool
    {
        if ($userRepCode === '') {
            return false;
        }

        return $this->ownRepresentativeScopeResourceCode($userRepCode) === $this->scopeResourceCode($scope);
    }

    private function ownRepresentativeScopeResourceCode(string $repCode): ?string
    {
        return match ($repCode) {
            '0003' => 'sales_rep_umit_yildiz',
            '0024' => 'sales_rep_salih_cakir',
            default => null,
        };
    }

    private function configuredManagementScopes(array $filters): Collection
    {
        $scopes = collect($filters['managementScopes'] ?? []);

        if ($scopes->contains(fn (array $scope): bool => $this->normalizeScopeKey((string) ($scope['key'] ?? '')) === 'bulent_saglam')) {
            return $scopes;
        }

        return $scopes->push([
            'key' => 'bulent_saglam',
            'label' => 'Bülent Sağlam',
            'repCode' => '0024',
            'allowAll' => false,
            'salesView' => 'temsilci',
            'note' => 'Bülent Sağlam temsilci kapsamı',
            'navigateTo' => null,
            'resourceCode' => 'sales_rep_bulent_saglam',
        ]);
    }

    private function effectiveRepresentativeCode(?User $user, array $scope): ?string
    {
        $canSeeAll = $this->access->userCanAccess($user, 'sales_main_all');
        $userRepCode = trim((string) ($user?->temsilci_kodu ?? ''));
        $scopeRepCode = trim((string) ($scope['repCode'] ?? ''));
        $scopeResourceCode = $this->scopeResourceCode($scope);

        if (in_array($scopeResourceCode, ['sales_online', 'sales_bayi'], true)) {
            return null;
        }

        if ($canSeeAll) {
            if (($scope['allowAll'] ?? false) === true && ($scope['salesView'] ?? 'tumu') === 'tumu') {
                return null;
            }

            return $scopeRepCode !== '' ? $scopeRepCode : null;
        }

        return $scopeRepCode !== '' ? $scopeRepCode : ($userRepCode !== '' ? $userRepCode : null);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function normalizeFilters(array $input): array
    {
        $defaults = $this->pageConfig()->filters_json['defaults'] ?? [];
        $grain = in_array(($input['grain'] ?? $defaults['grain'] ?? 'week'), ['day', 'week', 'month', 'year'], true)
            ? (string) ($input['grain'] ?? $defaults['grain'] ?? 'week')
            : 'week';

        $detailType = in_array(($input['detail_type'] ?? $defaults['detailType'] ?? 'cari'), ['cari', 'urun'], true)
            ? (string) ($input['detail_type'] ?? $defaults['detailType'] ?? 'cari')
            : 'cari';

        $today = CarbonImmutable::now();
        $dateFrom = $this->normalizeDate($input['date_from'] ?? null, $grain, true, $today);
        $dateTo = $this->normalizeDate($input['date_to'] ?? null, $grain, false, $today);

        $brandFilter = $detailType === 'urun'
            ? $this->normalizeBrandFilter($input['brand_filter'] ?? 'all')
            : 'all';
        $categoryFilter = $detailType === 'urun'
            ? $this->normalizeCategoryFilter($input['category_filter'] ?? 'all')
            : 'all';
        $productFilter = $detailType === 'urun'
            ? $this->normalizeProductFilter($input['product_filter'] ?? '')
            : '';

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'grain' => $grain,
            'detail_type' => $detailType,
            'scope_key' => $this->normalizeScopeKey((string) ($input['scope_key'] ?? $defaults['scopeKey'] ?? 'all')),
            'cari_filter' => $this->normalizeCustomerFilter($input['customer_filter'] ?? $input['cari_filter'] ?? ''),
            'brand_filter' => $brandFilter,
            'category_filter' => $categoryFilter,
            'product_filter' => $productFilter,
        ];
    }

    private function normalizeBrandFilter(mixed $value): string
    {
        $value = strtolower(str_replace('-', '_', trim((string) $value)));

        return in_array($value, ['philips', 'emaks_prime'], true) ? $value : 'all';
    }

    private function normalizeCategoryFilter(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));
        $allowedCategories = ['A1', 'AS1', 'D1', 'G1', 'K1', 'KA1', 'M1', 'O1', 'OT1', 'YM1'];

        return in_array($value, $allowedCategories, true) ? $value : 'all';
    }

    private function normalizeProductFilter(mixed $value): string
    {
        return mb_substr(trim((string) $value), 0, 255);
    }

    private function normalizeCustomerFilter(mixed $value): string
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

    private function periodLabel(string $from, string $to): string
    {
        return CarbonImmutable::parse($from)->format('d.m.Y').' - '.CarbonImmutable::parse($to)->format('d.m.Y');
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', '.').' TL';
    }

    private function quantity(float $value): string
    {
        $decimals = abs($value - round($value)) < 0.00001 ? 0 : 2;

        return number_format($value, $decimals, ',', '.');
    }

    private function palette(int $index): string
    {
        return [
            '#0f172a',
            '#2563eb',
            '#10b981',
            '#f59e0b',
            '#ef4444',
            '#8b5cf6',
            '#06b6d4',
            '#f97316',
        ][$index % 8];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function chartTitle(array $filters): string
    {
        if (($filters['cari_filter'] ?? '') !== '') {
            return 'Seçili Müşteri Karşılaştırması';
        }

        if (($filters['detail_type'] ?? 'cari') !== 'urun') {
            return 'Satış Dağılımı';
        }

        return match ($filters['brand_filter'] ?? 'all') {
            'philips' => 'PHILIPS Ürün Satış Dağılımı',
            'emaks_prime' => 'EMAKS PRIME Ürün Satış Dağılımı',
            default => 'Marka Satış Karşılaştırması',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groupRows
     * @param  Collection<int, array<string, mixed>>  $detailRows
     * @param  array<string, string>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function chartItems(Collection $groupRows, Collection $detailRows, array $filters, float $positiveTotal): array
    {
        if (($filters['cari_filter'] ?? '') === '') {
            if (($filters['detail_type'] ?? 'cari') === 'urun' && ($filters['brand_filter'] ?? 'all') === 'all') {
                return $this->brandChartItems($groupRows, $detailRows);
            }

            return $groupRows
                ->reject(fn (array $row): bool => $this->rowExcludedFromTotal($row))
                ->values()
                ->map(function (array $row, int $index) use ($positiveTotal) {
                    $amount = (float) $row['ciro'];
                    $percentage = $positiveTotal > 0 && $amount > 0
                        ? round(($amount / $positiveTotal) * 100, 1)
                        : 0;

                    return [
                        'label' => $this->groupName($row),
                        'amount' => $amount,
                        'amountLabel' => $this->money($amount),
                        'quantity' => (float) $row['adet'],
                        'quantityLabel' => $this->quantity((float) $row['adet']),
                        'percentage' => $percentage,
                        'color' => $this->palette($index),
                        'isNegative' => $amount < 0,
                        'excludedFromTotal' => false,
                        'isConsignment' => false,
                        'isTeshir' => $this->isTeshirAccount($this->groupName($row), ''),
                    ];
                })
                ->all();
        }

        $customerRows = $detailRows
            ->filter(fn (array $row): bool => in_array(($row['satir_tipi'] ?? null), ['CARI', 'KONSINYE'], true))
            ->values();

        if ($customerRows->isEmpty()) {
            $customerRows = $detailRows
                ->filter(fn (array $row): bool => trim((string) ($row['cari_kodu'] ?? '')) !== '')
                ->values();
        }

        $customers = [];

        foreach ($customerRows as $row) {
            $customerCode = trim((string) ($row['cari_kodu'] ?? ''));

            if ($customerCode === '') {
                continue;
            }

            $label = $this->rowLabel($row);
            $groupLabel = $this->groupName($row);
            $excluded = $this->rowExcludedFromTotal($row) || $this->isConsignmentAccount($label, $customerCode);

            if (! isset($customers[$customerCode])) {
                $customers[$customerCode] = [
                    'label' => $label,
                    'customerCode' => $customerCode,
                    'groupLabel' => $groupLabel,
                    'amount' => 0.0,
                    'quantity' => 0.0,
                    'excludedFromTotal' => $excluded,
                    'isConsignment' => $this->isConsignmentAccount($label, $customerCode) || $excluded,
                    'isTeshir' => $this->isTeshirAccount($label, $customerCode),
                ];
            }

            $customers[$customerCode]['amount'] += (float) ($row['ciro'] ?? 0);
            $customers[$customerCode]['quantity'] += (float) ($row['adet'] ?? 0);
            $customers[$customerCode]['excludedFromTotal'] = (bool) $customers[$customerCode]['excludedFromTotal'] || $excluded;
            $customers[$customerCode]['isConsignment'] = (bool) $customers[$customerCode]['isConsignment'] || $this->isConsignmentAccount($label, $customerCode);
            $customers[$customerCode]['isTeshir'] = (bool) $customers[$customerCode]['isTeshir'] || $this->isTeshirAccount($label, $customerCode);
        }

        $includedPositiveTotal = collect($customers)
            ->reject(fn (array $item): bool => (bool) $item['excludedFromTotal'])
            ->where('amount', '>', 0)
            ->sum('amount');

        return collect($customers)
            ->sort(function (array $left, array $right): int {
                $leftExcluded = (bool) $left['excludedFromTotal'];
                $rightExcluded = (bool) $right['excludedFromTotal'];

                if ($leftExcluded !== $rightExcluded) {
                    return $leftExcluded ? 1 : -1;
                }

                return abs((float) $right['amount']) <=> abs((float) $left['amount']);
            })
            ->values()
            ->map(function (array $item, int $index) use ($includedPositiveTotal) {
                $amount = (float) $item['amount'];
                $excluded = (bool) $item['excludedFromTotal'];
                $percentage = ! $excluded && $includedPositiveTotal > 0 && $amount > 0
                    ? round(($amount / $includedPositiveTotal) * 100, 1)
                    : 0;

                return [
                    ...$item,
                    'amount' => $amount,
                    'amountLabel' => $this->money($amount),
                    'quantity' => (float) $item['quantity'],
                    'quantityLabel' => $this->quantity((float) $item['quantity']),
                    'percentage' => $percentage,
                    'color' => $this->palette($index),
                    'isNegative' => $amount < 0,
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groupRows
     * @param  Collection<int, array<string, mixed>>  $detailRows
     * @return array<int, array<string, mixed>>
     */
    private function brandChartItems(Collection $groupRows, Collection $detailRows): array
    {
        $rows = $groupRows
            ->filter(fn (array $row): bool => ($row['satir_tipi'] ?? null) === 'GRUP')
            ->reject(fn (array $row): bool => $this->rowExcludedFromTotal($row))
            ->values();

        if ($rows->isEmpty()) {
            $rows = $detailRows
                ->filter(fn (array $row): bool => ($row['satir_tipi'] ?? null) === 'DETAY')
                ->reject(fn (array $row): bool => $this->rowExcludedFromTotal($row))
                ->values();
        }

        $brands = [];

        foreach ($rows as $row) {
            $brand = $this->brandBucket($row);
            $key = $brand['label'];

            if (! isset($brands[$key])) {
                $brands[$key] = [
                    'label' => $brand['label'],
                    'brandCode' => $brand['code'],
                    'brandName' => $brand['name'],
                    'marka_adi' => $brand['name'],
                    'amount' => 0.0,
                    'quantity' => 0.0,
                    'excludedFromTotal' => false,
                    'isConsignment' => false,
                    'isTeshir' => false,
                ];
            }

            $brands[$key]['amount'] += (float) ($row['ciro'] ?? 0);
            $brands[$key]['quantity'] += (float) ($row['adet'] ?? 0);
        }

        $includedPositiveTotal = collect($brands)
            ->where('amount', '>', 0)
            ->sum('amount');

        return collect($brands)
            ->sortByDesc(fn (array $item): float => abs((float) $item['amount']))
            ->values()
            ->map(function (array $item, int $index) use ($includedPositiveTotal) {
                $amount = (float) $item['amount'];

                return [
                    ...$item,
                    'amount' => $amount,
                    'amountLabel' => $this->money($amount),
                    'quantity' => (float) $item['quantity'],
                    'quantityLabel' => $this->quantity((float) $item['quantity']),
                    'percentage' => $includedPositiveTotal > 0 && $amount > 0
                        ? round(($amount / $includedPositiveTotal) * 100, 1)
                        : 0,
                    'color' => $this->palette($index),
                    'isNegative' => $amount < 0,
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{label: string, code: string, name: string}
     */
    private function brandBucket(array $row): array
    {
        $rawCode = trim((string) ($row['brand_code'] ?? ''));
        $rawName = trim((string) ($row['brand_name'] ?? $row['marka_adi'] ?? ''));
        $codeText = $this->asciiAccountText($rawCode);
        $nameText = $this->asciiAccountText($rawName);

        if ($codeText === 'PHILIPS' || $nameText === 'PHILIPS') {
            return ['label' => 'PHILIPS', 'code' => $rawCode, 'name' => 'PHILIPS'];
        }

        if (in_array($codeText, ['EMAKS PRIME', 'EMAKS'], true) || in_array($nameText, ['EMAKS PRIME', 'EMAKS'], true)) {
            return ['label' => 'EMAKS PRIME', 'code' => $rawCode, 'name' => 'EMAKS PRIME'];
        }

        return ['label' => 'Diğer Marka', 'code' => $rawCode, 'name' => $rawName !== '' ? $rawName : 'Diğer Marka'];
    }

    private function isConsignmentAccount(string $label, string $customerCode): bool
    {
        $text = $this->asciiAccountText($label.' '.$customerCode);

        return str_contains($text, 'KONSINYE');
    }

    private function isTeshirAccount(string $label, string $customerCode): bool
    {
        $text = $this->asciiAccountText($label.' '.$customerCode);

        return str_contains($text, 'TESHIR');
    }

    private function asciiAccountText(string $value): string
    {
        return str_replace(
            ['İ', 'İ', 'ı', 'Ş', 'ş', 'Ğ', 'ğ', 'Ü', 'ü', 'Ö', 'ö', 'Ç', 'ç'],
            ['I', 'I', 'I', 'S', 'S', 'G', 'G', 'U', 'U', 'O', 'O', 'C', 'C'],
            mb_strtoupper($value, 'UTF-8'),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breakdownGroups(string $detailType, Collection $groupRows, Collection $detailRows): array
    {
        if ($detailType === 'urun') {
            $detailRows = $this->normalizeProductDetailRows($groupRows, $detailRows);
            $groupRows = $this->normalizeProductGroupRows($groupRows, $detailRows);

            return $groupRows->map(function (array $group) use ($detailRows) {
                $groupLabel = $this->groupName($group);
                $children = $detailRows
                    ->filter(fn (array $row) => $this->parentKey($row) === $groupLabel)
                    ->values()
                    ->map(fn (array $row) => $this->rowPayload(
                        $this->rowLabel($row),
                        (float) $row['adet'],
                        (float) $row['ciro'],
                        $this->rowId($row, $groupLabel),
                        [
                            'type' => (string) ($row['satir_tipi'] ?? 'DETAY'),
                            'customerCode' => trim((string) ($row['cari_kodu'] ?? '')),
                            'cari_kodu' => trim((string) ($row['cari_kodu'] ?? '')),
                            'parent_key' => $this->parentKey($row),
                            'excludedFromTotal' => $this->rowExcludedFromTotal($row),
                        ],
                    ))
                    ->all();

                return [
                    ...$this->rowPayload($groupLabel, (float) $group['adet'], (float) $group['ciro'], 'GRUP:'.$groupLabel, [
                        'type' => 'GRUP',
                        'excludedFromTotal' => $this->rowExcludedFromTotal($group),
                    ]),
                    'children' => $children,
                ];
            })->values()->all();
        }

        $groupRows = $this->normalizeCustomerGroupRows($groupRows, $detailRows);

        return $groupRows->map(function (array $group) use ($detailRows) {
            $groupLabel = $this->groupName($group);
            $cariRows = $detailRows
                ->filter(fn (array $row) => in_array(($row['satir_tipi'] ?? null), ['CARI', 'KONSINYE'], true) && $this->groupName($row) === $groupLabel)
                ->values();

            $urunRows = $detailRows
                ->where('satir_tipi', 'URUN')
                ->filter(fn (array $row) => $this->groupName($row) === $groupLabel)
                ->values();

            $children = $cariRows->map(function (array $cari) use ($urunRows) {
                $cariCode = trim((string) ($cari['cari_kodu'] ?? ''));

                return [
                    ...$this->rowPayload(
                        $this->rowLabel($cari),
                        (float) $cari['adet'],
                        (float) $cari['ciro'],
                        'CARI:'.($cariCode !== '' ? $cariCode : $this->rowFingerprint($cari)),
                        [
                            'type' => (string) ($cari['satir_tipi'] ?? 'CARI'),
                            'customerCode' => $cariCode,
                            'cari_kodu' => $cariCode,
                            'excludedFromTotal' => $this->rowExcludedFromTotal($cari),
                        ],
                    ),
                    'children' => $urunRows
                        ->filter(fn (array $urun) => $cariCode !== '' && $this->parentKey($urun) === $cariCode)
                        ->values()
                        ->map(fn (array $urun) => $this->rowPayload(
                            $this->rowLabel($urun),
                            (float) $urun['adet'],
                            (float) $urun['ciro'],
                            $this->rowId($urun, $cariCode),
                            [
                                'type' => 'URUN',
                                'customerCode' => $cariCode,
                                'cari_kodu' => $cariCode,
                                'parent_key' => $this->parentKey($urun),
                                'excludedFromTotal' => $this->rowExcludedFromTotal($urun),
                            ],
                        ))
                        ->all(),
                ];
            })->all();

            return [
                ...$this->rowPayload($groupLabel, (float) $group['adet'], (float) $group['ciro'], 'GRUP:'.$groupLabel, [
                    'type' => 'GRUP',
                    'excludedFromTotal' => $this->rowExcludedFromTotal($group),
                ]),
                'children' => $children,
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groupRows
     * @param  Collection<int, array<string, mixed>>  $detailRows
     * @return Collection<int, array<string, mixed>>
     */
    private function normalizeCustomerGroupRows(Collection $groupRows, Collection $detailRows): Collection
    {
        $groups = $groupRows
            ->map(fn (array $group) => [...$group, 'cari_grup_adi' => $this->groupName($group)])
            ->keyBy(fn (array $group) => $this->groupName($group));

        $detailRows
            ->filter(fn (array $row) => in_array(($row['satir_tipi'] ?? null), ['CARI', 'KONSINYE'], true))
            ->each(function (array $row) use ($groups): void {
                $groupName = $this->groupName($row);

                if (! $groups->has($groupName)) {
                    $groups->put($groupName, [
                        'satir_tipi' => 'GRUP',
                        'cari_grup_adi' => $groupName,
                        'adet' => 0,
                        'ciro' => 0,
                        'siralama_1' => 999999,
                    ]);
                }
            });

        return $groups->values()->sortBy('siralama_1')->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groupRows
     * @param  Collection<int, array<string, mixed>>  $detailRows
     * @return Collection<int, array<string, mixed>>
     */
    private function normalizeProductGroupRows(Collection $groupRows, Collection $detailRows): Collection
    {
        $groups = $groupRows
            ->map(fn (array $group) => [...$group, 'cari_grup_adi' => $this->groupName($group)])
            ->keyBy(fn (array $group) => $this->groupName($group));

        $detailRows->each(function (array $row) use ($groups): void {
            $groupName = $this->parentKey($row);

            if ($groupName !== '' && ! $groups->has($groupName)) {
                $groups->put($groupName, [
                    'satir_tipi' => 'GRUP',
                    'cari_grup_adi' => $groupName,
                    'adet' => 0,
                    'ciro' => 0,
                    'siralama_1' => 999999,
                ]);
            }
        });

        return $groups->values()->sortBy('siralama_1')->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groupRows
     * @param  Collection<int, array<string, mixed>>  $detailRows
     * @return Collection<int, array<string, mixed>>
     */
    private function normalizeProductDetailRows(Collection $groupRows, Collection $detailRows): Collection
    {
        $knownGroups = $groupRows
            ->map(fn (array $group) => $this->groupName($group))
            ->filter()
            ->values()
            ->all();

        return $detailRows->map(function (array $row) use ($knownGroups) {
            if ($this->parentKey($row) === '') {
                $fallbackGroup = $this->groupName($row);

                return [
                    ...$row,
                    'parent_key' => in_array($fallbackGroup, $knownGroups, true) ? $fallbackGroup : 'Diğer Gelirler',
                ];
            }

            return $row;
        });
    }

    private function fallbackSalesGroupLabel(string $value): string
    {
        $label = trim($value);

        if ($label === '' || $label === '-') {
            return 'Diğer Gelirler';
        }

        return $label;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function groupName(array $row): string
    {
        return $this->fallbackSalesGroupLabel(trim((string) ($row['cari_grup_adi'] ?? '')));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parentKey(array $row): string
    {
        return trim((string) ($row['parent_key'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowLabel(array $row): string
    {
        $label = $this->fallbackSalesGroupLabel(trim((string) ($row['satir_adi'] ?? $row['cari_grup_adi'] ?? '')));

        if (($row['satir_tipi'] ?? null) === 'KONSINYE' && $label !== '' && ! str_contains(mb_strtoupper($label, 'UTF-8'), 'KONS')) {
            return 'KONSİNYE - '.$label;
        }

        return $label;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowExcludedFromTotal(array $row): bool
    {
        $value = $row['excluded_from_total'] ?? $row['excludedFromTotal'] ?? 0;

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowPayload(string $label, float $quantity, float $amount, ?string $id = null, array $extra = []): array
    {
        return [
            'id' => $id ?? 'ROW:'.sha1($label.'|'.$quantity.'|'.$amount),
            'label' => $label,
            'quantity' => $quantity,
            'quantityLabel' => $this->quantity($quantity),
            'amount' => $amount,
            'amountLabel' => $this->money($amount),
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowId(array $row, string $parent = ''): string
    {
        $type = strtoupper(trim((string) ($row['satir_tipi'] ?? '')));
        $customerCode = trim((string) ($row['cari_kodu'] ?? ''));
        $label = $this->rowLabel($row);
        $categoryCode = trim((string) ($row['kategori_kodu'] ?? ''));

        return match ($type) {
            'GRUP' => 'GRUP:'.$this->groupName($row),
            'CARI' => 'CARI:'.($customerCode !== '' ? $customerCode : $this->rowFingerprint($row)),
            'KONSINYE' => 'KONSINYE:'.($customerCode !== '' ? $customerCode : $this->rowFingerprint($row)),
            'URUN', 'DETAY' => 'URUN:'.($customerCode !== '' ? $customerCode : $parent).':'.$label,
            'KATEGORI' => 'KATEGORI:'.($categoryCode !== '' ? $categoryCode : $label),
            default => 'ROW:'.$this->rowFingerprint($row),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowFingerprint(array $row): string
    {
        return sha1(json_encode([
            $row['satir_tipi'] ?? '',
            $row['cari_grup_adi'] ?? '',
            $row['cari_kodu'] ?? '',
            $row['parent_key'] ?? '',
            $row['model_adi'] ?? '',
            $row['stok_kodu'] ?? '',
            $row['kategori_kodu'] ?? '',
            $row['satir_adi'] ?? '',
        ], JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function page(string $pageCode = 'sales_main'): Page
    {
        return Page::query()->where('code', $pageCode)->firstOrFail();
    }

    private function pageConfig(): PageConfig
    {
        return PageConfig::query()
            ->with('dataSource')
            ->where('page_code', 'sales_main')
            ->firstOrFail();
    }

    private function source(): DataSource
    {
        return DataSource::query()->where('code', 'sales_main_dashboard')->firstOrFail();
    }

    private function sourceForScope(array $scope): ?DataSource
    {
        $code = match ($this->normalizeScopeKey((string) ($scope['key'] ?? 'all'))) {
            'online_perakende' => 'sales_online_perakende_detail',
            'bayi_proje' => 'sales_bayi_proje_detail',
            default => 'sales_main_dashboard',
        };

        return DataSource::query()->where('code', $code)->where('active', true)->first();
    }

    private function defaultScopeKeyForPage(string $pageCode): string
    {
        return match ($pageCode) {
            'sales_online' => 'online_perakende',
            'sales_bayi' => 'bayi_proje',
            default => 'all',
        };
    }

    private function usesN8nGateway(DataSource $source): bool
    {
        return ($source->connection_meta['driver'] ?? $source->db_type) === 'n8n_json';
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $whitelistedParameters
     * @param  array<string, mixed>|null  $gatewayResult
     * @return array<int, array<string, mixed>>
     */
    private function fetchN8nRows(
        DataSource $source,
        array $filters,
        array $scope,
        ?string $effectiveRepresentativeCode,
        array $whitelistedParameters,
        ?array &$gatewayResult,
    ): array {
        $gatewayResult = $this->n8nGateway->run($source->code, [
            ...$whitelistedParameters,
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'grain' => $filters['grain'],
            'detail_type' => $filters['detail_type'],
            'scope_key' => $filters['scope_key'],
            'rep_code' => $effectiveRepresentativeCode,
            'cari_filter' => $filters['cari_filter'],
            'customer_filter' => $filters['cari_filter'],
            'bypass_cache' => (bool) ($whitelistedParameters['bypass_cache'] ?? false),
        ], $source);

        return array_values(array_filter($gatewayResult['rows'], function (mixed $row): bool {
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
     * @param  array<string, mixed>  $params
     */
    private function compileTemplate(DataSource $dataSource, array $params): string
    {
        $template = (string) $dataSource->query_template;

        if ($template === '') {
            return '';
        }

        $replacements = collect($params)->mapWithKeys(fn ($value, $key) => [
            '{{'.$key.'}}' => str_replace("'", "''", (string) $value),
        ])->all();

        return strtr($template, $replacements);
    }
}
