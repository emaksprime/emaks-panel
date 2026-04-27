<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class N8nPanelDataGateway
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>, request: array<string, mixed>}
     */
    public function run(string $sourceCode, array $filters): array
    {
        $url = trim((string) config('panel.n8n_gateway_url'));
        $token = (string) config('panel.n8n_token');

        if ($url === '') {
            throw new RuntimeException('PANEL_N8N_GATEWAY_URL tanımlı değil.');
        }

        if (app()->isProduction() && trim($token) === '') {
            throw new RuntimeException('Production ortamında PANEL_N8N_TOKEN olmadan n8n gateway isteği atılamaz.');
        }

        $payload = [
            'source_code' => $sourceCode,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'grain' => $filters['grain'] ?? null,
            'detail_type' => $filters['detail_type'] ?? null,
            'scope_key' => $filters['scope_key'] ?? null,
            'rep_code' => $filters['rep_code'] ?? null,
        ];

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders(['x-panel-token' => $token])
                ->timeout(60)
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('n8n gateway bağlantısı kurulamadı: '.$exception->getMessage(), previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'n8n gateway hatası: HTTP %s',
                $response->status(),
            ));
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('n8n gateway geçerli JSON dönmedi.');
        }

        $rows = $json['rows'] ?? [];

        if (! is_array($rows)) {
            throw new RuntimeException('n8n gateway yanıtında rows alanı dizi değil.');
        }

        return [
            'rows' => array_values(array_filter($rows, 'is_array')),
            'meta' => is_array($json['meta'] ?? null) ? $json['meta'] : [],
            'request' => is_array($json['request'] ?? null) ? $json['request'] : $payload,
        ];
    }
}
