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
            return $this->emptyDataset($page, $filters, 'Bu sayfa icin aktif veri kaynagi tanimli degil.');
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
                'mode' => $source->db_type === 'n8n_json' ? 'n8n_gateway' : $source->db_type,
                'notice' => $rows === []
                    ? 'Canli veri kaynagi bos dondu.'
                    : 'Canli veri n8n gateway uzerinden alindi.',
                'gatewayMeta' => $result['meta'] ?? null,
                'gatewayRequest' => $result['request'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function payloadFor(DataSource $source, array $filters, User $user): array
    {
        $payload = [
            ...$filters,
            'rep_code' => trim((string) ($user->temsilci_kodu ?? '')) ?: null,
            'role_code' => $user->role_code,
        ];

        $allowed = $source->allowed_params ?? [];

        if ($allowed === []) {
            return $payload;
        }

        return collect($payload)
            ->only([...$allowed, 'role_code'])
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

        return array_values(array_filter($rows, 'is_array'));
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

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
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

    /**
     * @param  array<string, string>  $filters
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
