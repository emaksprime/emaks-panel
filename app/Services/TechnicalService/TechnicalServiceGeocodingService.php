<?php

namespace App\Services\TechnicalService;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class TechnicalServiceGeocodingService
{
    private const GEOCODING_ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * @return array<string, mixed>
     */
    public function geocodeText(mixed $query, string $sourceType = 'address', array $context = []): array
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

        return $this->resultFromResponse($queryPayload, $response, $context);
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
    private function resultFromResponse(array $query, Response $response, array $context = []): array
    {
        if (! $response->successful()) {
            return $this->failedResult($query, 'Google geocoding yanıtı başarısız.');
        }

        $body = $response->json();
        $status = is_array($body) ? (string) ($body['status'] ?? '') : '';
        $result = is_array($body) ? ($body['results'][0] ?? null) : null;
        $location = is_array($result) ? ($result['geometry']['location'] ?? null) : null;
        $locationType = is_array($result) ? (string) ($result['geometry']['location_type'] ?? '') : '';
        $formattedAddress = is_string($result['formatted_address'] ?? null) ? $result['formatted_address'] : null;
        $coordinates = is_array($location)
            ? $this->validCoordinatePair($location['lat'] ?? null, $location['lng'] ?? null)
            : null;

        if ($status !== 'OK' || ! is_array($result) || $coordinates === null) {
            return $this->failedResult($query, $status !== '' ? "Google geocoding sonucu geçersiz: {$status}." : 'Google geocoding sonucu geçersiz.');
        }

        $quality = $this->qualityGate($query, $coordinates, $formattedAddress, $locationType, $context);

        if (! $quality['accepted']) {
            return $this->failedResult($query, $quality['message'], 'rejected');
        }

        return [
            'ok' => true,
            'status' => 'ok',
            'provider' => 'google_geocode',
            'query' => $query['query'],
            'source_type' => $query['source_type'],
            'quality' => $query['quality'],
            'needs_review' => $quality['needs_review'],
            'review_reason' => $quality['message'] ?? null,
            'location_type' => $locationType !== '' ? $locationType : null,
            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],
            'formatted_address' => $formattedAddress,
            'error' => null,
            'error_message' => null,
        ];
    }

    /**
     * @param array{query:string,source_type:string,quality:string} $query
     *
     * @return array<string, mixed>
     */
    private function failedResult(array $query, string $message, string $status = 'failed'): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'quality' => 'failed',
            'query' => $query['query'],
            'source_type' => $query['source_type'],
            'needs_review' => true,
            'latitude' => null,
            'longitude' => null,
            'formatted_address' => null,
            'error' => $message,
            'error_message' => $message,
        ];
    }

    /**
     * @param array{query:string,source_type:string,quality:string} $query
     * @param array{latitude:float,longitude:float} $coordinates
     * @param array<string, mixed> $context
     * @return array{accepted:bool,needs_review:bool,message?:string}
     */
    private function qualityGate(array $query, array $coordinates, ?string $formattedAddress, string $locationType, array $context): array
    {
        if (! $this->coordinatesInsideTurkey($coordinates['latitude'], $coordinates['longitude'])) {
            return [
                'accepted' => false,
                'needs_review' => true,
                'message' => 'Geocode rejected: coordinates outside Turkey',
            ];
        }

        if ($this->isGenericCityCountryResult($formattedAddress)) {
            return [
                'accepted' => true,
                'needs_review' => true,
                'message' => 'review_required: generic_city_result',
            ];
        }

        $expectedCity = $this->normalizeLocationToken($context['city'] ?? null);
        if ($expectedCity !== null && $formattedAddress !== null) {
            $formatted = $this->normalizeLocationToken($formattedAddress) ?? '';

            if (! str_contains($formatted, $expectedCity)) {
                $displayCity = trim((string) ($context['city'] ?? ''));
                $actualCity = $this->extractDisplayCity($formattedAddress) ?? $formattedAddress;

                return [
                    'accepted' => true,
                    'needs_review' => true,
                    'message' => "review_required: city_mismatch {$displayCity} vs {$actualCity}",
                ];
            }
        }

        $normalizedLocationType = strtoupper(trim($locationType));
        if ($normalizedLocationType === 'APPROXIMATE') {
            if ($this->isTrustedPlusCodeSource($query['source_type']) || $this->looksDetailedQuery($query['query'])) {
                return [
                    'accepted' => true,
                    'needs_review' => true,
                ];
            }

            return [
                'accepted' => true,
                'needs_review' => true,
                'message' => 'review_required: approximate_city_level_result',
            ];
        }

        return [
            'accepted' => true,
            'needs_review' => false,
        ];
    }

    public function isGenericCityCountryResult(?string $formattedAddress): bool
    {
        $formattedAddress = trim((string) $formattedAddress);

        if ($formattedAddress === '') {
            return false;
        }

        $parts = collect(explode(',', $formattedAddress))
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->values();

        $locationParts = $parts
            ->reject(fn (string $part): bool => in_array($this->normalizeLocationToken($part), ['turkiye', 'turkey'], true))
            ->values();

        if ($locationParts->count() <= 1) {
            return true;
        }

        if ($locationParts->count() === 2) {
            $first = $this->normalizeLocationToken($locationParts[0]);
            $second = $this->normalizeLocationToken($locationParts[1]);
            $secondTail = $this->normalizeLocationToken(Str::of($locationParts[1])->afterLast('/')->toString());

            if ($first !== null && ($first === $second || $first === $secondTail)) {
                return true;
            }
        }

        return false;
    }

    public function coordinatesInsideTurkey(float $latitude, float $longitude): bool
    {
        return $latitude >= 35.0 && $latitude <= 43.5 && $longitude >= 25.0 && $longitude <= 46.0;
    }

    public function normalizeLocationToken(mixed $value): ?string
    {
        $normalized = Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();

        return $normalized !== '' ? $normalized : null;
    }

    private function isTrustedPlusCodeSource(string $sourceType): bool
    {
        return in_array($sourceType, ['google_plus_code', 'location_code', 'default_start_plus_code'], true);
    }

    private function looksDetailedQuery(string $query): bool
    {
        $normalized = $this->normalizeLocationToken($query) ?? '';

        return preg_match('/\b(no|sokak|sk|cadde|cad|mah|mahalle|apt|apartman|blok)\b/u', $normalized) === 1
            || preg_match('/\d/', $normalized) === 1
            || mb_strlen($normalized) >= 32;
    }

    private function extractDisplayCity(string $formattedAddress): ?string
    {
        $parts = collect(explode(',', $formattedAddress))
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->reject(fn (string $part): bool => in_array($this->normalizeLocationToken($part), ['turkiye', 'turkey'], true))
            ->values();

        if ($parts->isEmpty()) {
            return null;
        }

        $candidate = (string) $parts->last();

        if (str_contains($candidate, '/')) {
            $candidate = Str::of($candidate)->afterLast('/')->trim()->toString();
        }

        return $candidate !== '' ? $candidate : (string) $parts->first();
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
