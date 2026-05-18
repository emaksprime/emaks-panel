<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceTechnician;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class TechnicianCoordinateDataService
{
    private const DUPLICATE_REVIEW_COORDINATE = '38.963745,35.243322';

    public function __construct(private readonly TechnicalServiceGeocodingService $geocodingService) {}

    /**
     * @return array<string, mixed>
     */
    public function report(bool $markReview = true): array
    {
        $technicians = TechnicalServiceTechnician::query()->orderBy('id')->get();
        $duplicateMap = $this->duplicateCoordinateMap($technicians);
        $sourceDistribution = [];
        $suspicious = [];

        foreach ($technicians as $technician) {
            $sourceType = $this->sourceType($technician);
            $sourceDistribution[$sourceType] = ($sourceDistribution[$sourceType] ?? 0) + 1;
            $reasons = $this->suspiciousReasons($technician, $duplicateMap);

            if ($reasons !== []) {
                $suspicious[] = [
                    'id' => $technician->id,
                    'name' => $technician->name,
                    'city' => $technician->city,
                    'coordinate_key' => $this->coordinateKey($technician),
                    'reasons' => $reasons,
                ];

                if ($markReview && ! (bool) $technician->needs_review) {
                    $technician->forceFill(['needs_review' => true])->save();
                }
            }
        }

        $withCoordinates = $technicians->filter(fn (TechnicalServiceTechnician $technician): bool => $this->coordinatePair($technician) !== null)->count();

        return [
            'total' => $technicians->count(),
            'with_coordinates' => $withCoordinates,
            'without_coordinates' => $technicians->count() - $withCoordinates,
            'duplicate_coordinates' => array_values($duplicateMap),
            'source_distribution' => $sourceDistribution,
            'needs_review' => TechnicalServiceTechnician::query()->where('needs_review', true)->count(),
            'suspicious' => $suspicious,
        ];
    }

    /**
     * @return array{checked:int,marked_review:int,cleared:int,suspicious:array<int,array<string,mixed>>}
     */
    public function validate(bool $clearInvalid = false): array
    {
        $technicians = TechnicalServiceTechnician::query()->orderBy('id')->get();
        $duplicateMap = $this->duplicateCoordinateMap($technicians);
        $summary = [
            'checked' => $technicians->count(),
            'marked_review' => 0,
            'cleared' => 0,
            'suspicious' => [],
        ];

        foreach ($technicians as $technician) {
            $reasons = $this->suspiciousReasons($technician, $duplicateMap);

            if ($reasons === []) {
                continue;
            }

            $summary['suspicious'][] = [
                'id' => $technician->id,
                'name' => $technician->name,
                'city' => $technician->city,
                'coordinate_key' => $this->coordinateKey($technician),
                'reasons' => $reasons,
            ];

            $payload = [
                'needs_review' => true,
                'route_note' => $this->appendReviewNote($technician, $reasons),
            ];

            if ($clearInvalid && $this->shouldClearCoordinates($reasons)) {
                $payload = array_merge($payload, [
                    'latitude' => null,
                    'longitude' => null,
                    'start_latitude' => null,
                    'start_longitude' => null,
                ]);
                $summary['cleared']++;
            }

            $technician->forceFill($payload)->save();
            $summary['marked_review']++;
        }

        return $summary;
    }

    /**
     * @return array{exported:int,needs_review_excluded:int,suspicious_excluded:int,path:string,items:array<int,array<string,mixed>>}
     */
    public function export(string $outputPath, bool $includeReview = false): array
    {
        $technicians = TechnicalServiceTechnician::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('city')
            ->orderBy('name')
            ->get();
        $duplicateMap = $this->duplicateCoordinateMap($technicians);
        $items = [];
        $needsReviewExcluded = 0;
        $suspiciousExcluded = 0;

        foreach ($technicians as $technician) {
            $reasons = $this->suspiciousReasons($technician, $duplicateMap);
            $isReview = (bool) $technician->needs_review;

            if (! $includeReview && $isReview) {
                $needsReviewExcluded++;
                continue;
            }

            if (! $includeReview && $reasons !== []) {
                $suspiciousExcluded++;
                continue;
            }

            $items[] = $this->exportRecord($technician);
        }

        File::ensureDirectoryExists(dirname($outputPath));
        file_put_contents($outputPath, json_encode([
            'source' => 'technical_service_technicians',
            'generated_at' => now()->toISOString(),
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'exported' => count($items),
            'needs_review_excluded' => $needsReviewExcluded,
            'suspicious_excluded' => $suspiciousExcluded,
            'path' => $outputPath,
            'items' => $items,
        ];
    }

    /**
     * @return array{updated:int,skipped:int,review_skipped:int}
     */
    public function seed(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Coordinate seed veri dosyası bulunamadı: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $records = is_array($decoded) ? ($decoded['items'] ?? $decoded) : null;

        if (! is_array($records)) {
            throw new \RuntimeException("Coordinate seed veri dosyası beklenen formatta değil: {$path}");
        }

        return DB::transaction(function () use ($records): array {
            $summary = [
                'updated' => 0,
                'skipped' => 0,
                'review_skipped' => 0,
            ];

            foreach ($records as $record) {
                if (! is_array($record)) {
                    $summary['skipped']++;
                    continue;
                }

                if ((bool) ($record['needs_review'] ?? false)) {
                    $summary['review_skipped']++;
                    continue;
                }

                $coordinates = $this->geocodingService->validCoordinatePair(
                    $record['latitude'] ?? null,
                    $record['longitude'] ?? null,
                );

                if ($coordinates === null) {
                    $summary['skipped']++;
                    continue;
                }

                $technician = $this->findTechnicianForRecord($record);

                if (! $technician instanceof TechnicalServiceTechnician) {
                    $summary['skipped']++;
                    continue;
                }

                $technician->forceFill([
                    'latitude' => $coordinates['latitude'],
                    'longitude' => $coordinates['longitude'],
                    'start_latitude' => $record['start_latitude'] ?? $coordinates['latitude'],
                    'start_longitude' => $record['start_longitude'] ?? $coordinates['longitude'],
                    'location_source' => $this->nullableText($record['location_source'] ?? null),
                    'route_note' => $this->nullableText($record['route_note'] ?? null),
                    'needs_review' => false,
                ])->save();

                $summary['updated']++;
            }

            return $summary;
        });
    }

    /**
     * @param Collection<int, TechnicalServiceTechnician> $technicians
     * @return array<string, array{coordinate:string,count:int,names:array<int,string>,cities:array<int,string>}>
     */
    private function duplicateCoordinateMap(Collection $technicians): array
    {
        return $technicians
            ->mapToGroups(function (TechnicalServiceTechnician $technician): array {
                $key = $this->coordinateKey($technician);

                return $key !== null ? [$key => $technician] : [];
            })
            ->map(function (Collection $items, string $key): ?array {
                $names = $items->pluck('name')->filter()->unique()->values()->all();
                $cities = $items->pluck('city')->filter()->unique()->values()->all();

                if ($items->count() <= 2 && count($cities) <= 1 && $key !== self::DUPLICATE_REVIEW_COORDINATE) {
                    return null;
                }

                return [
                    'coordinate' => $key,
                    'count' => $items->count(),
                    'names' => $names,
                    'cities' => $cities,
                ];
            })
            ->filter()
            ->all();
    }

    /**
     * @param array<string, array{coordinate:string,count:int,names:array<int,string>,cities:array<int,string>}> $duplicateMap
     * @return array<int, string>
     */
    private function suspiciousReasons(TechnicalServiceTechnician $technician, array $duplicateMap): array
    {
        $reasons = [];
        $coordinates = $this->coordinatePair($technician);
        $coordinateKey = $this->coordinateKey($technician);

        if ($this->hasZeroZeroCoordinates($technician)) {
            $reasons[] = 'zero_zero_coordinates';
        } elseif ($this->hasOutOfRangeCoordinates($technician)) {
            $reasons[] = 'invalid_coordinate_range';
        } elseif ($coordinates === null) {
            $reasons[] = 'missing_or_invalid_coordinates';
        } elseif (! $this->coordinatesInsideTurkey($coordinates['latitude'], $coordinates['longitude'])) {
            $reasons[] = 'coordinates_outside_turkey';
        }

        if ($coordinateKey !== null && isset($duplicateMap[$coordinateKey])) {
            $reasons[] = 'duplicate_coordinate';
        }

        if ($coordinateKey === self::DUPLICATE_REVIEW_COORDINATE) {
            $reasons[] = 'known_generic_turkey_coordinate';
        }

        if ($this->isBroadFormattedAddress($technician)) {
            $reasons[] = 'broad_formatted_address';
        }

        if ($this->hasGenericCityCountryResult($technician)) {
            $reasons[] = 'generic_city_country_result';
        }

        if ($this->hasCityMismatch($technician)) {
            $reasons[] = 'city_mismatch';
        }

        if ($this->sourceType($technician) === 'cari_address' && $this->sourceAddressTooShort($technician)) {
            $reasons[] = 'short_cari_address';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @return array<string, mixed>
     */
    private function exportRecord(TechnicalServiceTechnician $technician): array
    {
        return [
            'source_key' => $this->nullableText($technician->source_key),
            'phone_e164' => $this->nullableText($technician->phone_e164),
            'city' => $this->nullableText($technician->city),
            'name' => $this->nullableText($technician->name),
            'latitude' => $technician->latitude !== null ? (float) $technician->latitude : null,
            'longitude' => $technician->longitude !== null ? (float) $technician->longitude : null,
            'start_latitude' => $technician->start_latitude !== null ? (float) $technician->start_latitude : null,
            'start_longitude' => $technician->start_longitude !== null ? (float) $technician->start_longitude : null,
            'location_source' => $this->nullableText($technician->location_source),
            'route_note' => $this->safeRouteNote($technician),
            'geocode_quality' => $this->sourceType($technician),
            'needs_review' => (bool) $technician->needs_review,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function findTechnicianForRecord(array $record): ?TechnicalServiceTechnician
    {
        $sourceKey = $this->nullableText($record['source_key'] ?? null);
        if ($sourceKey !== null) {
            $technician = TechnicalServiceTechnician::query()->where('source_key', $sourceKey)->first();

            if ($technician instanceof TechnicalServiceTechnician) {
                return $technician;
            }
        }

        $city = $this->nullableText($record['city'] ?? null);
        $phoneE164 = $this->nullableText($record['phone_e164'] ?? null);
        if ($phoneE164 !== null && $city !== null) {
            $technician = TechnicalServiceTechnician::query()
                ->where('phone_e164', $phoneE164)
                ->where('city', $city)
                ->first();

            if ($technician instanceof TechnicalServiceTechnician) {
                return $technician;
            }
        }

        $phone = $this->nullableText($record['phone'] ?? null);
        if ($phone !== null && $city !== null) {
            $technician = TechnicalServiceTechnician::query()
                ->where('phone', $phone)
                ->where('city', $city)
                ->first();

            if ($technician instanceof TechnicalServiceTechnician) {
                return $technician;
            }
        }

        return null;
    }

    /**
     * @return array{latitude:float,longitude:float}|null
     */
    private function coordinatePair(TechnicalServiceTechnician $technician): ?array
    {
        return $this->geocodingService->validCoordinatePair($technician->latitude, $technician->longitude);
    }

    private function coordinateKey(TechnicalServiceTechnician $technician): ?string
    {
        $coordinates = $this->coordinatePair($technician);

        return $coordinates !== null
            ? sprintf('%.6f,%.6f', $coordinates['latitude'], $coordinates['longitude'])
            : null;
    }

    private function sourceType(TechnicalServiceTechnician $technician): string
    {
        $routeNote = (string) $technician->route_note;

        if (preg_match('/Geocoded from ([^;]+)/', $routeNote, $matches) === 1) {
            return trim($matches[1]);
        }

        return $this->nullableText($technician->location_source) ?? 'unknown';
    }

    private function safeRouteNote(TechnicalServiceTechnician $technician): ?string
    {
        $note = $this->nullableText($technician->route_note);

        return $note !== null ? Str::limit($note, 500, '') : null;
    }

    private function isBroadFormattedAddress(TechnicalServiceTechnician $technician): bool
    {
        $formatted = $this->formattedAddressFromRouteNote($technician);

        if ($formatted === null) {
            return false;
        }

        $normalized = Str::of($formatted)->lower()->ascii()->squish()->toString();

        return in_array($normalized, ['turkiye', 'turkey', 'turkiye cumhuriyeti'], true);
    }

    private function hasGenericCityCountryResult(TechnicalServiceTechnician $technician): bool
    {
        return $this->geocodingService->isGenericCityCountryResult($this->formattedAddressFromRouteNote($technician));
    }

    private function hasCityMismatch(TechnicalServiceTechnician $technician): bool
    {
        $formatted = $this->formattedAddressFromRouteNote($technician);
        $city = $this->nullableText($technician->city);

        if ($formatted === null || $city === null) {
            return false;
        }

        $expected = $this->geocodingService->normalizeLocationToken($city);
        $actual = $this->geocodingService->normalizeLocationToken($formatted);

        return $expected !== null && $actual !== null && ! str_contains($actual, $expected);
    }

    private function formattedAddressFromRouteNote(TechnicalServiceTechnician $technician): ?string
    {
        if (preg_match('/formatted:\s*([^;]+)/', (string) $technician->route_note, $matches) !== 1) {
            return null;
        }

        return $this->nullableText($matches[1] ?? null);
    }

    private function sourceAddressTooShort(TechnicalServiceTechnician $technician): bool
    {
        $address = $this->nullableText($technician->cari_address);

        return $address === null || mb_strlen($address) < 12;
    }

    private function coordinatesInsideTurkey(float $latitude, float $longitude): bool
    {
        return $this->geocodingService->coordinatesInsideTurkey($latitude, $longitude);
    }

    /**
     * @param array<int, string> $reasons
     */
    private function shouldClearCoordinates(array $reasons): bool
    {
        return collect($reasons)->contains(fn (string $reason): bool => in_array($reason, [
            'zero_zero_coordinates',
            'invalid_coordinate_range',
            'coordinates_outside_turkey',
        ], true));
    }

    private function hasZeroZeroCoordinates(TechnicalServiceTechnician $technician): bool
    {
        $latitude = $this->rawCoordinate($technician->latitude ?? $technician->start_latitude);
        $longitude = $this->rawCoordinate($technician->longitude ?? $technician->start_longitude);

        return $latitude === 0.0 && $longitude === 0.0;
    }

    private function hasOutOfRangeCoordinates(TechnicalServiceTechnician $technician): bool
    {
        $latitude = $this->rawCoordinate($technician->latitude ?? $technician->start_latitude);
        $longitude = $this->rawCoordinate($technician->longitude ?? $technician->start_longitude);

        return ($latitude !== null && abs($latitude) > 90) || ($longitude !== null && abs($longitude) > 180);
    }

    private function rawCoordinate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<int, string> $reasons
     */
    private function appendReviewNote(TechnicalServiceTechnician $technician, array $reasons): string
    {
        $current = $this->nullableText($technician->route_note);
        $review = 'Coordinate review required: '.implode(',', $reasons).'; at '.now()->toDateTimeString();

        return $current !== null ? Str::limit($current.'; '.$review, 2000, '') : $review;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
