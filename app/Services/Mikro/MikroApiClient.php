<?php

namespace App\Services\Mikro;

use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class MikroApiClient
{
    public function __construct(
        private readonly MikroOperationRegistry $registry,
        private readonly TechnicalServiceMessagingSettingsService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        return $this->execute('health.check');
    }

    /**
     * @return array<string, mixed>
     */
    public function listCustomers(
        string $customerCode = '',
        string $taxNumber = '',
        int $dateType = 0,
        string $startDate = '',
        string $endDate = '',
        string $sort = '-cari_kod',
        int $size = 50,
        int $index = 0,
    ): array {
        $this->assertPage($size, $index);

        return $this->execute('customer.list', [
            'CariKod' => $customerCode,
            'CariVKNTCNo' => $taxNumber,
            'TarihTipi' => $dateType,
            'IlkTarih' => $startDate,
            'SonTarih' => $endDate,
            'Sort' => $sort,
            'Size' => (string) $size,
            'Index' => $index,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function listStocks(
        string $stockCode = '',
        int $dateType = 0,
        string $startDate = '',
        string $endDate = '',
        string $sort = '-sto_kod',
        int $size = 50,
        int $index = 0,
    ): array {
        $this->assertPage($size, $index);

        return $this->execute('stock.list', [
            'StokKod' => $stockCode,
            'TarihTipi' => $dateType,
            'IlkTarih' => $startDate,
            'SonTarih' => $endDate,
            'Sort' => $sort,
            'Size' => (string) $size,
            'Index' => $index,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function execute(string $operationKey, array $payload = []): array
    {
        $operation = $this->registry->read($operationKey);
        $context = $this->settings->mikroApiConnectionContext();
        $correlationId = (string) Str::uuid();

        if (! ($context['live_configuration_ready'] ?? false)) {
            return $this->failureResult('MIKRO_LIVE_CONFIGURATION_MISSING', $correlationId);
        }

        $baseUrlBlocker = $this->registry->baseUrlBlocker($context['base_url'] ?? null);
        if ($baseUrlBlocker !== null) {
            return $this->failureResult($baseUrlBlocker, $correlationId);
        }

        $requestPayload = $payload;
        if (($operation['method'] ?? null) === 'POST') {
            $requestPayload = [
                'Mikro' => [
                    'ApiKey' => $context['api_key'],
                    'CalismaYili' => $context['working_year'],
                    'FirmaKodu' => $context['firm_code'],
                    'KullaniciKodu' => $context['user_code'],
                    'Sifre' => $context['password'],
                ],
                ...$payload,
            ];
        }

        $startedAt = microtime(true);

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->withHeaders(['X-Correlation-ID' => $correlationId])
                ->connectTimeout(min(5, (int) $context['timeout_seconds']))
                ->timeout((int) $context['timeout_seconds']);
            $url = rtrim((string) $context['base_url'], '/').$operation['endpoint'];
            $response = ($operation['method'] ?? null) === 'GET'
                ? $request->get($url)
                : $request->post($url, $requestPayload);
        } catch (ConnectionException $exception) {
            $errorCode = str_contains(strtolower($exception->getMessage()), 'timed out')
                || str_contains(strtolower($exception->getMessage()), 'timeout')
                    ? 'MIKRO_TIMEOUT'
                    : 'MIKRO_CONNECTION_FAILED';

            return $this->failureResult($errorCode, $correlationId, $startedAt);
        } catch (Throwable) {
            return $this->failureResult('MIKRO_REQUEST_FAILED', $correlationId, $startedAt);
        }

        return $this->responseResult($operationKey, $response, $correlationId, $startedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseResult(
        string $operationKey,
        Response $response,
        string $correlationId,
        float $startedAt,
    ): array {
        $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));

        if (! $response->successful()) {
            return $this->failureResult(
                'MIKRO_HTTP_'.$response->status(),
                $correlationId,
                $startedAt,
                $response->status(),
            );
        }

        if ($operationKey === 'health.check') {
            return [
                'status' => $response->status(),
                'success' => true,
                'error_code' => null,
                'duration_ms' => $durationMs,
                'result_count' => 1,
                'normalized_data' => ['service_status' => 'UP'],
                'source' => 'mikro_api',
                'freshness_at' => now()->toIso8601String(),
                'correlation_id' => $correlationId,
            ];
        }

        $json = $response->json();
        if (! is_array($json)) {
            return $this->failureResult('MIKRO_RESPONSE_INVALID', $correlationId, $startedAt, $response->status());
        }

        $rows = $this->rows($json);

        return [
            'status' => $response->status(),
            'success' => true,
            'error_code' => null,
            'duration_ms' => $durationMs,
            'result_count' => count($rows),
            'normalized_data' => $rows,
            'source' => 'mikro_api',
            'freshness_at' => now()->toIso8601String(),
            'correlation_id' => $correlationId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failureResult(
        string $errorCode,
        string $correlationId,
        ?float $startedAt = null,
        ?int $status = null,
    ): array {
        return [
            'status' => $status,
            'success' => false,
            'error_code' => $errorCode,
            'duration_ms' => $startedAt === null
                ? 0
                : max(0, (int) round((microtime(true) - $startedAt) * 1000)),
            'result_count' => 0,
            'normalized_data' => [],
            'source' => 'mikro_api',
            'freshness_at' => now()->toIso8601String(),
            'correlation_id' => $correlationId,
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $json): array
    {
        foreach (['data', 'Data', 'result', 'Result', 'rows', 'Rows'] as $key) {
            if (is_array($json[$key] ?? null)) {
                return array_values(array_filter($json[$key], 'is_array'));
            }
        }

        return array_is_list($json)
            ? array_values(array_filter($json, 'is_array'))
            : [];
    }

    private function assertPage(int $size, int $index): void
    {
        if ($size < 1 || $size > 100 || $index < 0) {
            throw new DomainException('MIKRO_QUERY_INVALID');
        }
    }
}
