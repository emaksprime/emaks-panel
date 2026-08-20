<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceTechnician;

class TechnicianGeocodingService
{
    public function __construct(private readonly TechnicalServiceGeocodingService $geocodingService) {}

    /**
     * @return array{query:string,source_type:string,quality:string}|null
     */
    public function bestQueryFor(TechnicalServiceTechnician $technician): ?array
    {
        $candidates = [
            ['source_type' => 'google_plus_code', 'value' => $this->plusCodeQuery($technician->google_plus_code, $technician)],
            ['source_type' => 'location_code', 'value' => $this->plusCodeQuery($technician->location_code, $technician)],
            ['source_type' => 'default_start_plus_code', 'value' => $this->plusCodeQuery($technician->default_start_plus_code, $technician)],
            ['source_type' => 'google_formatted_address', 'value' => $technician->google_formatted_address],
            ['source_type' => 'default_start_address', 'value' => $technician->default_start_address],
            ['source_type' => 'address', 'value' => $technician->address ? $this->geocodingService->joinParts([$technician->address, $technician->district, $technician->city, 'Türkiye']) : null],
            ['source_type' => 'cari_address', 'value' => $technician->cari_address ? $this->geocodingService->joinParts([$technician->cari_address, $technician->cari_city_district_country]) : null],
        ];

        foreach ($candidates as $candidate) {
            $query = $this->geocodingService->normalizeQuery($candidate['value']);

            if (! $this->geocodingService->isUsableQuery($query)) {
                continue;
            }

            return [
                'query' => $query,
                'source_type' => $candidate['source_type'],
                'quality' => $this->geocodingService->qualityFor($candidate['source_type'], $query),
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
                'needs_review' => true,
                'latitude' => null,
                'longitude' => null,
                'formatted_address' => null,
                'error' => 'Adres veya Plus Code bulunamadı.',
                'error_message' => 'Adres veya Plus Code bulunamadı.',
            ];
        }

        $result = $this->geocodingService->geocodeText($query['query'], $query['source_type'], [
            'city' => $technician->city,
            'district' => $technician->district,
        ]);

        if (! ($result['ok'] ?? false)
            && ($result['provider_status'] ?? null) === 'ZERO_RESULTS'
            && in_array($query['source_type'], ['google_plus_code', 'location_code', 'default_start_plus_code'], true)
        ) {
            $fallback = $this->addressFallbackQuery($technician);

            if ($fallback !== null && $fallback['query'] !== $query['query']) {
                $fallbackResult = $this->geocodingService->geocodeText($fallback['query'], $fallback['source_type'], [
                    'city' => $technician->city,
                    'district' => $technician->district,
                    'fallback_from' => $query['source_type'],
                ]);
                $fallbackResult['fallback_from'] = $query;

                return $fallbackResult;
            }
        }

        return $result;
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
        return $this->geocodingService->validCoordinatePair($latitude, $longitude);
    }

    public function apiKey(): ?string
    {
        return $this->geocodingService->apiKey();
    }

    private function plusCodeQuery(mixed $value, TechnicalServiceTechnician $technician): ?string
    {
        $query = $this->geocodingService->normalizeQuery($value);

        if ($query === '') {
            return null;
        }

        if (! $this->looksLikeShortPlusCode($query)) {
            return $query;
        }

        return $this->geocodingService->joinParts([
            $query,
            $technician->district,
            $technician->city,
            'Türkiye',
        ]) ?? $query;
    }

    private function looksLikeShortPlusCode(string $value): bool
    {
        $normalized = strtoupper(trim($value));

        return preg_match('/^[23456789CFGHJMPQRVWX]{4}\+[23456789CFGHJMPQRVWX]{2,3}$/', $normalized) === 1;
    }

    /**
     * @return array{query:string,source_type:string}|null
     */
    private function addressFallbackQuery(TechnicalServiceTechnician $technician): ?array
    {
        foreach ([
            ['source_type' => 'google_formatted_address', 'value' => $technician->google_formatted_address],
            ['source_type' => 'address', 'value' => $technician->address ? $this->geocodingService->joinParts([$technician->address, $technician->district, $technician->city, 'Türkiye']) : null],
            ['source_type' => 'default_start_address', 'value' => $technician->default_start_address],
            ['source_type' => 'cari_address', 'value' => $technician->cari_address ? $this->geocodingService->joinParts([$technician->cari_address, $technician->cari_city_district_country]) : null],
        ] as $candidate) {
            $query = $this->geocodingService->normalizeQuery($candidate['value']);

            if ($this->geocodingService->isUsableQuery($query)) {
                return [
                    'query' => $query,
                    'source_type' => $candidate['source_type'],
                ];
            }
        }

        return null;
    }
}
