<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\Page;
use App\Models\PageConfig;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

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
    public function config(?User $user): array
    {
        $page = $this->page();
        $pageConfig = $this->pageConfig();
        $layout = $pageConfig->layout_json ?? [];
        $filters = $pageConfig->filters_json ?? [];
        $scopes = $this->visibleScopes($user, collect($filters['managementScopes'] ?? []));
        $scope = $scopes->first();
        $source = $pageConfig->dataSource ?? $this->source();

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
            'managementScopes' => $scopes->values()->all(),
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
        $source = $this->pageConfig()->dataSource ?? $this->source();

        $effectiveRepresentativeCode = $this->effectiveRepresentativeCode($user, $scope);
        $allowed = collect($source->allowed_params ?? []);
        $whitelistedParameters = collect([
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'grain' => $filters['grain'],
            'detail_type' => $filters['detail_type'],
            'scope_key' => $normalizedScopeKey,
            'rep_code' => $effectiveRepresentativeCode,
            'search' => $input['search'] ?? null,
            'page' => $input['page'] ?? 1,
            'bypass_cache' => (bool) ($input['bypass_cache'] ?? false),
        ])->only($allowed)->all();

        $this->compileTemplate($source, $whitelistedParameters);

        $gatewayResult = null;
        $rows = $this->usesN8nGateway($source)
            ? collect($this->fetchN8nRows($source, $filters, $scope, $effectiveRepresentativeCode, $whitelistedParameters, $gatewayResult))
            : collect($source->preview_payload[$filters['detail_type']] ?? []);

        if ($rows->isEmpty()) {
            throw new RuntimeException(
                $this->usesN8nGateway($source)
                    ? 'n8n gateway rows alanında satış verisi dönmedi.'
                    : 'Satış Yönetimi önizleme verisi panel.data_sources içinde tanımlı değil.'
            );
        }

        $groupRows = $rows
            ->where('satir_tipi', 'GRUP')
            ->sortBy('siralama_1')
            ->values();

        $detailRows = $rows
            ->filter(fn (array $row) => ($row['satir_tipi'] ?? null) !== 'GRUP')
            ->values();

        $positiveTotal = $groupRows->where('ciro', '>', 0)->sum('ciro');
        $netTotal = $groupRows->sum('ciro');
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
                    'label' => 'Secili Donem',
                    'value' => $periodLabel,
                    'raw' => $periodLabel,
                ],
                [
                    'label' => 'Konsinye Haric',
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
                'title' => $filters['detail_type'] === 'urun' ? 'Urun Ciro Dagilimi' : 'Cari Grup Ciro Dagilimi',
                'subtitle' => $filters['detail_type'] === 'urun'
                    ? 'Urun ve model bazli paylarin dagilimi.'
                    : 'Cari gruplarin toplam ciro icindeki paylari.',
                'totalNet' => $netTotal,
                'konsinyeAmount' => $konsinye,
                'items' => $groupRows->map(function (array $row, int $index) use ($positiveTotal) {
                    $amount = (float) $row['ciro'];
                    $percentage = $positiveTotal > 0 && $amount > 0
                        ? round(($amount / $positiveTotal) * 100, 1)
                        : 0;

                    return [
                        'label' => $row['cari_grup_adi'],
                        'amount' => $amount,
                        'amountLabel' => $this->money($amount),
                        'quantity' => (float) $row['adet'],
                        'quantityLabel' => $this->quantity((float) $row['adet']),
                        'percentage' => $percentage,
                        'color' => $this->palette($index),
                        'isNegative' => $amount < 0,
                    ];
                })->values()->all(),
            ],
            'breakdown' => [
                'mode' => $filters['detail_type'],
                'title' => $filters['detail_type'] === 'urun' ? 'Urun -> Cari Kirilimi' : 'Cari Grup -> Cari -> Urun Kirilimi',
                'groups' => $this->breakdownGroups($filters['detail_type'], $groupRows, $detailRows),
            ],
            'table' => [
                'columns' => [
                    ['key' => 'label', 'label' => 'Baslik'],
                    ['key' => 'quantity', 'label' => 'Adet'],
                    ['key' => 'amount', 'label' => 'Ciro'],
                ],
                'rows' => $this->breakdownGroups($filters['detail_type'], $groupRows, $detailRows),
            ],
            'queryMeta' => [
                'dataSource' => $source->code,
                'driver' => $source->db_type,
                'status' => $source->active ? 'active' : 'inactive',
                'mode' => $isLive ? 'n8n_gateway' : 'preview',
                'notice' => $isLive ? 'Canlı veri n8n gateway üzerinden alındı.' : 'Önizleme verisi; canlı veri kaynağı henüz bağlı değil.',
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
        $scopes = $this->visibleScopes($user, collect($this->pageConfig()->filters_json['managementScopes'] ?? []));
        $normalizedScopeKey = $this->normalizeScopeKey($scopeKey);
        $scope = $scopes->first(fn (array $scope) => $this->normalizeScopeKey((string) ($scope['key'] ?? '')) === $normalizedScopeKey);

        return $scope ?? $scopes->first() ?? [
            'key' => 'all',
            'label' => 'Tumu',
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
     * @param  Collection<int, array<string, mixed>>  $scopes
     * @return Collection<int, array<string, mixed>>
     */
    private function visibleScopes(?User $user, Collection $scopes): Collection
    {
        $canSeeAll = $this->access->userCanAccess($user, 'sales_main_all');
        $userRepCode = trim((string) ($user?->temsilci_kodu ?? ''));

        return $scopes
            ->filter(function (array $scope) use ($canSeeAll, $userRepCode) {
                if ($canSeeAll) {
                    return true;
                }

                if (($scope['navigateTo'] ?? null) !== null) {
                    return true;
                }

                if (($scope['repCode'] ?? null) === null) {
                    return false;
                }

                return $userRepCode !== '' && $userRepCode === $scope['repCode'];
            })
            ->values();
    }

    private function effectiveRepresentativeCode(?User $user, array $scope): ?string
    {
        $canSeeAll = $this->access->userCanAccess($user, 'sales_main_all');
        $userRepCode = trim((string) ($user?->temsilci_kodu ?? ''));
        $scopeRepCode = trim((string) ($scope['repCode'] ?? ''));

        if ($canSeeAll) {
            if (($scope['allowAll'] ?? false) === true && ($scope['salesView'] ?? 'tumu') === 'tumu') {
                return null;
            }

            return $scopeRepCode !== '' ? $scopeRepCode : null;
        }

        return $userRepCode !== '' ? $userRepCode : ($scopeRepCode !== '' ? $scopeRepCode : null);
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

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'grain' => $grain,
            'detail_type' => $detailType,
            'scope_key' => $this->normalizeScopeKey((string) ($input['scope_key'] ?? $defaults['scopeKey'] ?? 'all')),
        ];
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
        return number_format($value, 2, ',', '.');
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
     * @return array<int, array<string, mixed>>
     */
    private function breakdownGroups(string $detailType, Collection $groupRows, Collection $detailRows): array
    {
        if ($detailType === 'urun') {
            return $groupRows->map(function (array $group) use ($detailRows) {
                $children = $detailRows
                    ->where('parent_key', $group['cari_grup_adi'])
                    ->values()
                    ->map(fn (array $row) => $this->rowPayload($row['satir_adi'], (float) $row['adet'], (float) $row['ciro']))
                    ->all();

                return [
                    ...$this->rowPayload($group['cari_grup_adi'], (float) $group['adet'], (float) $group['ciro']),
                    'children' => $children,
                ];
            })->values()->all();
        }

        return $groupRows->map(function (array $group) use ($detailRows) {
            $cariRows = $detailRows
                ->where('satir_tipi', 'CARI')
                ->where('cari_grup_adi', $group['cari_grup_adi'])
                ->values();

            $urunRows = $detailRows
                ->where('satir_tipi', 'URUN')
                ->where('cari_grup_adi', $group['cari_grup_adi'])
                ->values();

            $children = $cariRows->map(function (array $cari) use ($urunRows) {
                return [
                    ...$this->rowPayload($cari['satir_adi'], (float) $cari['adet'], (float) $cari['ciro']),
                    'children' => $urunRows
                        ->where('parent_key', $cari['cari_kodu'])
                        ->values()
                        ->map(fn (array $urun) => $this->rowPayload($urun['satir_adi'], (float) $urun['adet'], (float) $urun['ciro']))
                        ->all(),
                ];
            })->all();

            return [
                ...$this->rowPayload($group['cari_grup_adi'], (float) $group['adet'], (float) $group['ciro']),
                'children' => $children,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function rowPayload(string $label, float $quantity, float $amount): array
    {
        return [
            'label' => $label,
            'quantity' => $quantity,
            'quantityLabel' => $this->quantity($quantity),
            'amount' => $amount,
            'amountLabel' => $this->money($amount),
        ];
    }

    private function page(): Page
    {
        return Page::query()->where('code', 'sales_main')->firstOrFail();
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
            'bypass_cache' => (bool) ($whitelistedParameters['bypass_cache'] ?? false),
        ], $source);

        return $gatewayResult['rows'];
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
