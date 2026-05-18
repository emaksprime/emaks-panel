<?php

namespace App\Services\TechnicalService;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class TechnicalServiceGeocodingService
{
    private const GEOCODING_ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * @return array<string, mixed>
     */
    public function geocodeText(mixed $query, string $sourceType = 'address'): array
    {
        $normalizedQuery = $this->normalizeQuery($query);

        if (! $this->isUsableQuery($normalizedQuery)) {
            return [
                'ok' => false,
                'status' => 'skipped',
                'quality' => 'failed',
                'query' => $normalizedQuery,
                'source_type' => $sourceType,
                'latitude' => null,
                'longitude' => null,
                'formatted_address' => null,
                'error' => 'Geocode sorgusu boş veya yetersiz.',
                'error_message' => 'Geocode sorgusu boş veya yetersiz.',
            ];
        }

        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            return [
                'ok' => false,
                'status' => 'missing_api_key',
                'quality' => 'failed',
                'query' => $normalizedQuery,
                'source_type' => $sourceType,
                'latitude' => null,
                'longitude' => null,
                'formatted_address' => null,
                'error' => 'Google geocoding key tanımlı değil.',
                'error_message' => 'Google geocoding key tanımlı değil.',
            ];
        }

        $queryPayload = [
            'query' => $normalizedQuery,
            'source_type' => $sourceType,
            'quality' => $this->qualityFor($sourceType, $normalizedQuery),
        ];

        try {
            $response = Http::timeout(12)->get(self::GEOCODING_ENDPOINT, [
                'address' => $normalizedQuery,
                'key' => $apiKey,
                'language' => 'tr',
                'region' => 'tr',
            ]);
        } catch (Throwable) {
            return $this->failedResult($queryPayload, 'Google geocoding isteği tamamlanamadı.');
        }

        return $this->resultFromResponse($queryPayload, $response);
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
     * @param array<int, mixed> $parts
     */
    public function joinParts(array $parts): ?string
    {
        $joined = collect($parts)
            ->map(fn (mixed $part): string => trim((string) $part))
            ->filter()
            ->implode(', ');

        return $joined !== '' ? $joined : null;
    }

    public function normalizeQuery(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    public function isUsableQuery(string $query): bool
    {
        if (mb_strlen($query) < 5) {
            return false;
        }

        return ! in_array(mb_strtolower($query), ['türkiye', 'turkiye'], true);
    }

    public function qualityFor(string $sourceType, string $query): string
    {
        if (in_array($sourceType, ['google_plus_code', 'default_start_plus_code', 'location_code'], true) || str_contains($query, '+')) {
            return 'exact_plus_code';
        }

        if ($sourceType === 'google_formatted_address') {
            return 'formatted_address';
        }

        return 'address_fallback';
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
            'error' => null,
            'error_message' => null,
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
            'latitude' => null,
            'longitude' => null,
            'formatted_address' => null,
            'error' => $message,
            'error_message' => $message,
        ];
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
