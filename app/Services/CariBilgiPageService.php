<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\Page;
use App\Models\PageConfig;
use App\Models\User;
use Illuminate\Support\Collection;

class CariBilgiPageService
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
        $source = $pageConfig->dataSource ?? $this->source();
        $filters = $pageConfig->filters_json ?? [];
        $scope = $this->resolveScope($user, $filters['defaults']['scopeKey'] ?? 'own');

        return [
            'page' => [
                'title' => $page->name,
                'description' => $page->description,
                'routePath' => $page->route,
                'component' => $page->component,
            ],
            'defaults' => [
                'search' => '',
                'scopeKey' => $scope['key'],
                'limit' => $filters['defaults']['limit'] ?? 20,
            ],
            'limits' => $filters['limits'] ?? [20, 50, 100],
            'scopes' => $this->visibleScopes($user)->values()->all(),
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
        $scope = $this->resolveScope($user, $filters['scope_key']);
        $source = $this->pageConfig()->dataSource ?? $this->source();
        $repCode = $this->effectiveRepresentativeCode($user, $scope);

        $payload = [
            'date_from' => null,
            'date_to' => null,
            'grain' => null,
            'detail_type' => null,
            'scope_key' => $scope['key'],
            'rep_code' => $repCode,
            'search' => $filters['search'],
            'limit' => $filters['limit'],
        ];

        $allowed = collect($source->allowed_params ?? []);
        $whitelistedParameters = collect($payload)->only($allowed)->all();
        $gatewayResult = $this->n8nGateway->run($source->code, $payload);

        $rows = collect($gatewayResult['rows'])
            ->map(fn (array $row) => $this->normalizeRow($row))
            ->sortByDesc('genel_durum')
            ->values()
            ->all();

        $summary = $this->summary(collect($rows));

        return [
            'filters' => [
                'search' => $filters['search'],
                'scopeKey' => $scope['key'],
                'limit' => $filters['limit'],
            ],
            'scope' => [
                'key' => $scope['key'],
                'label' => $scope['label'],
                'effectiveRepresentativeCode' => $repCode,
                'canSeeAll' => $this->canSeeAll($user),
            ],
            'summary' => $summary,
            'rows' => $rows,
            'queryMeta' => [
                'dataSource' => $source->code,
                'driver' => $source->db_type,
                'status' => $source->active ? 'active' : 'inactive',
                'mode' => 'n8n_gateway',
                'notice' => 'Canli veri n8n gateway uzerinden alindi.',
                'payload' => $payload,
                'whitelistedParameters' => $whitelistedParameters,
                'gatewayMeta' => $gatewayResult['meta'] ?? null,
                'gatewayRequest' => $gatewayResult['request'] ?? null,
            ],
            'navigation' => $this->navigation->sharedForUser($user, $this->page()->route),
        ];
    }

    /**
     * @return array<string, string|bool>
     */
    private function resolveScope(?User $user, string $scopeKey): array
    {
        if ($scopeKey === 'all' && $this->canSeeAll($user)) {
            return ['key' => 'all', 'label' => 'Tum cariler', 'allowAll' => true];
        }

        return ['key' => 'own', 'label' => 'Kendi carilerim', 'allowAll' => false];
    }

    /**
     * @return Collection<int, array<string, string|bool>>
     */
    private function visibleScopes(?User $user): Collection
    {
        $scopes = collect([
            ['key' => 'own', 'label' => 'Kendi carilerim', 'allowAll' => false],
        ]);

        if ($this->canSeeAll($user)) {
            $scopes->prepend(['key' => 'all', 'label' => 'Tum cariler', 'allowAll' => true]);
        }

        return $scopes;
    }

    private function effectiveRepresentativeCode(?User $user, array $scope): ?string
    {
        if (($scope['key'] ?? 'own') === 'all' && $this->canSeeAll($user)) {
            return null;
        }

        $repCode = trim((string) ($user?->temsilci_kodu ?? ''));

        return $repCode !== '' ? $repCode : null;
    }

    private function canSeeAll(?User $user): bool
    {
        return $this->access->userCanAccess($user, 'finance_cari_bilgi_all');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{search: string, scope_key: string, limit: int}
     */
    private function normalizeFilters(array $input): array
    {
        $limit = (int) ($input['limit'] ?? 20);

        if (! in_array($limit, [20, 50, 100], true)) {
            $limit = 20;
        }

        return [
            'search' => trim((string) ($input['search'] ?? '')),
            'scope_key' => (string) ($input['scope_key'] ?? 'own'),
            'limit' => $limit,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $balance = $this->number($row['bakiye'] ?? 0);
        $approvedOpenOrder = $this->number($row['onayli_acik_siparis_tutari'] ?? 0);
        $pendingOrder = $this->number($row['onay_bekleyen_siparis_tutari'] ?? 0);
        $general = array_key_exists('genel_durum', $row)
            ? $this->number($row['genel_durum'])
            : $balance + $approvedOpenOrder;
        $balanceStatus = (string) ($row['bakiye_durumu'] ?? $row['bakiye_tipi'] ?? $this->statusLabel($balance));

        return [
            'cari_kodu' => (string) ($row['cari_kodu'] ?? ''),
            'cari_unvani' => (string) ($row['cari_unvani'] ?? ''),
            'bakiye' => $balance,
            'bakiye_durumu' => $balanceStatus,
            'onayli_acik_siparis_tutari' => $approvedOpenOrder,
            'onay_bekleyen_siparis_tutari' => $pendingOrder,
            'genel_durum' => $general,
            'temsilci_kodu' => $row['temsilci_kodu'] ?? null,
            'formatted' => [
                'bakiye' => $this->money($balance),
                'onayli_acik_siparis_tutari' => $this->money($approvedOpenOrder),
                'onay_bekleyen_siparis_tutari' => $this->money($pendingOrder),
                'genel_durum' => $this->money($general),
            ],
            'tone' => $this->tone($general),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function summary(Collection $rows): array
    {
        $receivable = $rows->sum(fn (array $row) => max((float) $row['bakiye'], 0));
        $debt = $rows->sum(fn (array $row) => abs(min((float) $row['bakiye'], 0)));
        $approved = $rows->sum('onayli_acik_siparis_tutari');
        $pending = $rows->sum('onay_bekleyen_siparis_tutari');
        $general = $rows->sum('genel_durum');

        return [
            ['key' => 'receivable', 'label' => 'Toplam Alacak Bakiyesi', 'value' => $this->money($receivable), 'raw' => $receivable],
            ['key' => 'debt', 'label' => 'Toplam Borc Bakiyesi', 'value' => $this->money($debt), 'raw' => $debt],
            ['key' => 'approved_open_order', 'label' => 'Onayli Acik Siparis', 'value' => $this->money($approved), 'raw' => $approved],
            ['key' => 'pending_order', 'label' => 'Onay Bekleyen Siparis', 'value' => $this->money($pending), 'raw' => $pending],
            ['key' => 'general', 'label' => 'Siparis Sonrasi Genel Durum', 'value' => $this->money($general), 'raw' => $general],
        ];
    }

    private function number(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return (float) str_replace(',', '.', preg_replace('/[^\d,\.-]/', '', (string) $value));
    }

    private function statusLabel(float $value): string
    {
        return match (true) {
            $value > 0 => 'Alacak',
            $value < 0 => 'Borc',
            default => 'Dengede',
        };
    }

    private function tone(float $value): string
    {
        return match (true) {
            $value > 0 => 'positive',
            $value < 0 => 'negative',
            default => 'neutral',
        };
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', '.').' TL';
    }

    private function page(): Page
    {
        return Page::query()->where('code', 'cari_bilgi')->firstOrFail();
    }

    private function pageConfig(): PageConfig
    {
        return PageConfig::query()
            ->with('dataSource')
            ->where('page_code', 'cari_bilgi')
            ->firstOrFail();
    }

    private function source(): DataSource
    {
        return DataSource::query()->where('code', 'cari_bilgi_dashboard')->firstOrFail();
    }
}
