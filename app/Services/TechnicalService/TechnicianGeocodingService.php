<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceTechnician;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class TechnicianGeocodingService
{
    private const GEOCODING_ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * @return array{query:string,source_type:string,quality:string}|null
     */
    public function bestQueryFor(TechnicalServiceTechnician $technician): ?array
    {
        $candidates = [
            ['source_type' => 'google_plus_code', 'value' => $technician->google_plus_code],
            ['source_type' => 'location_code', 'value' => $technician->location_code],
            ['source_type' => 'default_start_plus_code', 'value' => $technician->default_start_plus_code],
            ['source_type' => 'google_formatted_address', 'value' => $technician->google_formatted_address],
            ['source_type' => 'default_start_address', 'value' => $technician->default_start_address],
            ['source_type' => 'cari_address', 'value' => $this->joinParts([$technician->cari_address, $technician->cari_city_district_country])],
            ['source_type' => 'address', 'value' => $this->joinParts([$technician->address, $technician->district, $technician->city, 'Türkiye'])],
        ];

        foreach ($candidates as $candidate) {
            $query = $this->normalizeQuery($candidate['value']);

            if (! $this->isUsableQuery($query)) {
                continue;
            }

            return [
                'query' => $query,
                'source_type' => $candidate['source_type'],
                'quality' => $this->qualityFor($candidate['source_type'], $query),
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function geocode(TechnicalServiceTechnician $technician): array
    {
        $query = $this->bestQueryFor($technician);

        if ($query === null) {
            return [
                'ok' => false,
                'status' => 'skipped',
                'quality' => 'failed',
                'error_message' => 'Adres veya Plus Code bulunamadı.',
            ];
        }

        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            return [
                'ok' => false,
                'status' => 'missing_api_key',
                'quality' => 'failed',
                'query' => $query['query'],
                'source_type' => $query['source_type'],
                'error_message' => 'Google geocoding key tanımlı değil.',
            ];
        }

        try {
            $response = Http::timeout(12)->get(self::GEOCODING_ENDPOINT, [
                'address' => $query['query'],
                'key' => $apiKey,
                'language' => 'tr',
                'region' => 'tr',
            ]);
        } catch (Throwable) {
            return $this->failedResult($query, 'Google geocoding isteği tamamlanamadı.');
        }

        return $this->resultFromResponse($query, $response);
    }

    public function hasValidCoordinates(TechnicalServiceTechnician $technician): bool
    {
        return $this->validCoordinatePair($technician->latitude, $technician->longitude) !== null
            || $this->validCoordinatePair($technician->start_latitude, $technician->start_longitude) !== null;
    }

    /**
     * @return array{latitude:float,longitude:float}|null
     */
    public function validCoordinatePair(mixed $latitude, mixed $longitude): ?array
    {
        $parsedLatitude = $this->parseCoordinate($latitude);
        $parsedLongitude = $this->parseCoordinate($longitude);

        if ($parsedLatitude === null || $parsedLongitude === null) {
            return null;
        }

        if (($parsedLatitude === 0.0 && $parsedLongitude === 0.0) || abs($parsedLatitude) > 90 || abs($parsedLongitude) > 180) {
            return null;
        }

        return [
            'latitude' => $parsedLatitude,
            'longitude' => $parsedLongitude,
        ];
    }

    public function apiKey(): ?string
    {
        foreach ([
            config('services.google.geocoding_api_key'),
            config('services.google.places_api_key'),
            config('services.google.routes_api_key'),
        ] as $candidate) {
            $key = trim((string) $candidate);

            if ($key !== '') {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array{query:string,source_type:string,quality:string} $query
     *
     * @return array<string, mixed>
     */
    private function resultFromResponse(array $query, Response $response): array
    {
        if (! $response->successful()) {
            return $this->failedResult($query, 'Google geocoding yanıtı başarısız.');
        }

        $body = $response->json();
        $status = is_array($body) ? (string) ($body['status'] ?? '') : '';
        $result = is_array($body) ? ($body['results'][0] ?? null) : null;
        $location = is_array($result) ? ($result['geometry']['location'] ?? null) : null;
        $coordinates = is_array($location)
            ? $this->validCoordinatePair($location['lat'] ?? null, $location['lng'] ?? null)
            : null;

        if ($status !== 'OK' || ! is_array($result) || $coordinates === null) {
            return $this->failedResult($query, $status !== '' ? "Google geocoding sonucu geçersiz: {$status}." : 'Google geocoding sonucu geçersiz.');
        }

        return [
            'ok' => true,
            'status' => 'ok',
            'provider' => 'google_geocode',
            'query' => $query['query'],
            'source_type' => $query['source_type'],
            'quality' => $query['quality'],
            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],
            'formatted_address' => is_string($result['formatted_address'] ?? null) ? $result['formatted_address'] : null,
        ];
    }

    /**
     * @param array{query:string,source_type:string,quality:string} $query
     *
     * @return array<string, mixed>
     */
    private function failedResult(array $query, string $message): array
    {
        return [
            'ok' => false,
            'status' => 'failed',
            'quality' => 'failed',
            'query' => $query['query'],
            'source_type' => $query['source_type'],
            'error_message' => $message,
        ];
    }

    /**
     * @param array<int, mixed> $parts
     */
    private function joinParts(array $parts): ?string
    {
        $joined = collect($parts)
            ->map(fn (mixed $part): string => trim((string) $part))
            ->filter()
            ->implode(', ');

        return $joined !== '' ? $joined : null;
    }

    private function normalizeQuery(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    private function isUsableQuery(string $query): bool
    {
        if (mb_strlen($query) < 5) {
            return false;
        }

        return ! in_array(mb_strtolower($query), ['türkiye', 'turkiye'], true);
    }

    private function qualityFor(string $sourceType, string $query): string
    {
        if (in_array($sourceType, ['google_plus_code', 'default_start_plus_code'], true) || str_contains($query, '+')) {
            return 'exact_plus_code';
        }

        if ($sourceType === 'google_formatted_address') {
            return 'formatted_address';
        }

        return 'address_fallback';
    }

    private function parseCoordinate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = is_numeric($value) ? (float) $value : null;

        return is_float($parsed) && is_finite($parsed) ? $parsed : null;
    }
}
