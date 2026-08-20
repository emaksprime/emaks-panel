<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\DataSourceCache;
use App\Services\Mikro\MikroParityContract;
use App\Services\Mikro\MikroParitySource;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class N8nPanelDataGateway
{
    private const PARITY_N8N_HOST = 'hook.emaksprime.com.tr';

    /**
     * Internal parity reads are code-owned and never resolve a DataSource row. This
     * keeps the dedicated SQL out of generic page/admin datasource execution paths.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function readForParity(MikroParitySource $source, array $parameters): array
    {
        $contract = app(MikroParityContract::class);
        $sourceDefinition = $contract->source($source);
        $parameters = $contract->validatedParameters($source, $parameters);
        $url = trim((string) config('panel.n8n_gateway_url'));
        $token = (string) config('panel.n8n_token');

        $this->assertParityEndpoint($url);
        if (app()->isProduction() && trim($token) === '') {
            throw new RuntimeException('Production ortaminda PANEL_N8N_TOKEN olmadan n8n gateway istegi atilamaz.');
        }

        $payload = [
            'source_code' => $source->value,
            'bypass_cache' => true,
            'params' => $parameters,
            'allowed_params' => $sourceDefinition['allowed_params'],
            'query_template' => $sourceDefinition['query_template'],
            'data_source' => [
                'code' => $source->value,
                'name' => 'Internal Mikro parity source',
                'db_type' => 'n8n_json',
                'active' => false,
                'query_template_available' => true,
                'connection_meta' => [
                    'driver' => 'n8n_json',
                    'sql_policy' => 'mikro_parity_read_only',
                ],
            ],
        ];
        $headers = ['Content-Type' => 'application/json'];
        if (trim($token) !== '') {
            $headers['x-panel-token'] = $token;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders($headers)
                ->timeout(60)
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('N8N_PARITY_CONNECTION_FAILED', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('N8N_PARITY_HTTP_'.$response->status());
        }

        $json = $response->json();
        if (! is_array($json) || ($json['ok'] ?? true) === false) {
            throw new RuntimeException('N8N_PARITY_INVALID_RESPONSE');
        }

        $rows = $json['rows'] ?? [];
        if (! is_array($rows) || count(array_filter($rows, 'is_array')) !== count($rows)) {
            throw new RuntimeException('N8N_PARITY_ROWS_INVALID');
        }

        return $contract->normalizeN8n($source, array_values($rows));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>, request: array<string, mixed>}
     */
    public function run(string $sourceCode, array $filters, ?DataSource $dataSource = null): array
    {
        if (str_starts_with($sourceCode, 'parity_')) {
            throw new RuntimeException('MIKRO_PARITY_SOURCE_REQUIRES_INTERNAL_TYPED_PATH');
        }

        $connectionMeta = $dataSource?->connection_meta ?? [];
        $url = trim((string) ($connectionMeta['endpoint_url'] ?? config('panel.n8n_gateway_url')));
        $token = (string) config('panel.n8n_token');
        $rowsKey = trim((string) ($connectionMeta['response_rows_key'] ?? 'rows')) ?: 'rows';
        $queryTemplate = $this->runnableQueryTemplate($dataSource);
        $cacheKey = $this->cacheKey($sourceCode, $filters);
        $bypassCache = (bool) ($filters['bypass_cache'] ?? false);

        if (! $bypassCache && $cached = $this->cachedResponse($cacheKey)) {
            return $cached;
        }

        if ($url === '') {
            throw new RuntimeException('n8n gateway endpoint_url veya PANEL_N8N_GATEWAY_URL tanimli degil.');
        }

        if (app()->isProduction() && trim($token) === '') {
            throw new RuntimeException('Production ortaminda PANEL_N8N_TOKEN olmadan n8n gateway istegi atilamaz.');
        }

        $payload = [
            'source_code' => $sourceCode,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'grain' => $filters['grain'] ?? null,
            'detail_type' => $filters['detail_type'] ?? null,
            'scope_key' => $filters['scope_key'] ?? null,
            'rep_code' => $filters['rep_code'] ?? null,
            'cari_filter' => $filters['cari_filter'] ?? $filters['customer_filter'] ?? null,
            'customer_filter' => $filters['customer_filter'] ?? $filters['cari_filter'] ?? null,
            'search' => $filters['search'] ?? null,
            'serial_no' => $filters['serial_no'] ?? null,
            'limit' => $filters['limit'] ?? null,
            'bypass_cache' => $bypassCache,
            'params' => $this->allowedParams($filters, $dataSource),
            'allowed_params' => $dataSource?->allowed_params ?? [],
            'query_template' => $queryTemplate,
            'data_source' => $dataSource ? [
                'code' => $dataSource->code,
                'name' => $dataSource->name,
                'db_type' => $dataSource->db_type,
                'active' => $dataSource->active,
                'query_template_available' => $queryTemplate !== '',
                'connection_meta' => $this->safeConnectionMeta($connectionMeta),
            ] : null,
        ];

        foreach (['brand_filter', 'category_filter', 'product_filter'] as $optionalFilter) {
            if (array_key_exists($optionalFilter, $filters)) {
                $payload[$optionalFilter] = $filters[$optionalFilter];
            }
        }

        $headers = ['Content-Type' => 'application/json'];

        if (trim($token) !== '') {
            $headers['x-panel-token'] = $token;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders($headers)
                ->timeout((int) ($connectionMeta['timeout_seconds'] ?? 60))
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('n8n gateway baglantisi kurulamadi: '.$exception->getMessage(), previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'n8n gateway hatasi: HTTP %s',
                $response->status(),
            ));
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('n8n gateway gecerli JSON donmedi.');
        }

        if (($json['ok'] ?? true) === false) {
            throw new RuntimeException((string) ($json['error'] ?? 'n8n gateway veri istegini isleyemedi.'));
        }

        $rows = data_get($json, $rowsKey, $json['rows'] ?? []);

        if (! is_array($rows)) {
            throw new RuntimeException('n8n gateway yanitinda rows alani dizi degil.');
        }

        $result = [
            'rows' => array_values(array_filter($rows, 'is_array')),
            'meta' => is_array($json['meta'] ?? null) ? $json['meta'] : [],
            'request' => is_array($json['request'] ?? null) ? $json['request'] : $payload,
        ];

        $this->storeCache($cacheKey, $sourceCode, $payload, $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function allowedParams(array $filters, ?DataSource $dataSource): array
    {
        $allowed = $dataSource?->allowed_params ?? [];

        if ($allowed === []) {
            return $filters;
        }

        return collect($filters)
            ->only($allowed)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function safeConnectionMeta(array $meta): array
    {
        return collect($meta)
            ->reject(function (mixed $value, string $key) {
                $lowerKey = strtolower($key);

                return str_contains($lowerKey, 'password')
                    || str_contains($lowerKey, 'token')
                    || str_contains($lowerKey, 'secret')
                    || str_contains($lowerKey, 'api_key');
            })
            ->all();
    }

    private function assertParityEndpoint(string $url): void
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? rtrim((string) ($parts['path'] ?? ''), '/') : '';

        if ($url === ''
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower(trim((string) ($parts['host'] ?? ''))) !== self::PARITY_N8N_HOST
            || $path !== '/webhook/panel-data-source-run-local-v2') {
            throw new RuntimeException('MIKRO_PARITY_N8N_ENDPOINT_NOT_ALLOWLISTED');
        }
    }

    private function runnableQueryTemplate(?DataSource $dataSource): string
    {
        $template = trim((string) ($dataSource?->query_template ?? ''));

        if ($template === '') {
            return '';
        }

        return preg_match('/\b(SELECT|WITH|EXEC)\b/i', $template) === 1 ? $template : '';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function cacheKey(string $sourceCode, array $filters): string
    {
        $payload = collect($filters)
            ->except(['bypass_cache'])
            ->sortKeys()
            ->all();

        return hash('sha256', $sourceCode.'|'.json_encode($payload));
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>, request: array<string, mixed>}|null
     */
    private function cachedResponse(string $cacheKey): ?array
    {
        try {
            $cached = DataSourceCache::query()
                ->where('cache_key', $cacheKey)
                ->where('expires_at', '>', now())
                ->first();
        } catch (QueryException $exception) {
            Log::warning('Panel datasource cache read skipped because schema is not ready.', [
                'cache_key' => $cacheKey,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $cached || ! is_array($cached->response_payload)) {
            return null;
        }

        return [
            'rows' => is_array($cached->response_payload['rows'] ?? null) ? $cached->response_payload['rows'] : [],
            'meta' => [
                ...(is_array($cached->response_payload['meta'] ?? null) ? $cached->response_payload['meta'] : []),
                'cache' => 'hit',
            ],
            'request' => is_array($cached->response_payload['request'] ?? null) ? $cached->response_payload['request'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $responsePayload
     */
    private function storeCache(string $cacheKey, string $sourceCode, array $requestPayload, array $responsePayload): void
    {
        try {
            DataSourceCache::query()->updateOrCreate(
                ['cache_key' => $cacheKey],
                [
                    'source_code' => $sourceCode,
                    'request_payload' => $requestPayload,
                    'response_payload' => [
                        ...$responsePayload,
                        'meta' => [
                            ...(is_array($responsePayload['meta'] ?? null) ? $responsePayload['meta'] : []),
                            'cache' => 'stored',
                        ],
                    ],
                    'expires_at' => now()->addMinutes($this->ttlMinutes($sourceCode)),
                ],
            );
        } catch (QueryException $exception) {
            Log::warning('Panel datasource cache write skipped because schema is not ready.', [
                'source_code' => $sourceCode,
                'cache_key' => $cacheKey,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function ttlMinutes(string $sourceCode): int
    {
        if (
            str_starts_with($sourceCode, 'cari_')
            || str_starts_with($sourceCode, 'customers_')
            || str_starts_with($sourceCode, 'customer_')
        ) {
            return 10;
        }

        if (str_starts_with($sourceCode, 'stock_')) {
            return 10;
        }

        return 5;
    }
}
