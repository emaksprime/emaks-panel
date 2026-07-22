<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceTechnician;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class TechnicianCoordinateDataService
{
    private const DUPLICATE_REVIEW_COORDINATE = '38.963745,35.243322';

    public const WRITE_TABLES = [
        'technical_service_technicians',
    ];

    public const WRITE_COLUMNS = [
        'latitude',
        'longitude',
        'start_latitude',
        'start_longitude',
        'location_source',
        'route_note',
        'needs_review',
    ];

    public function __construct(
        private readonly TechnicalServiceGeocodingService $geocodingService,
        private readonly TechnicalServicePrivateDatasetPathPolicy $pathPolicy = new TechnicalServicePrivateDatasetPathPolicy,
    ) {}

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
     * @return array{exported:int,needs_review_excluded:int,suspicious_excluded:int,items:array<int,array<string,mixed>>}
     */
    public function export(string $outputPath, bool $includeReview = false): array
    {
        $output = $this->pathPolicy->output($outputPath);
        $technicians = TechnicalServiceTechnician::query()
            ->where('technician_type', LocksmithImportService::TYPE_LOCKSMITH)
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

        try {
            $contents = json_encode([
                'synthetic' => false,
                'schema_version' => 1,
                'source' => 'technical_service_technicians',
                'generated_at' => now()->toISOString(),
                'items' => $items,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Private coordinate export JSON olusturulamadi.', previous: $exception);
        }

        $this->pathPolicy->writeAtomically($output, $contents.PHP_EOL);

        return [
            'exported' => count($items),
            'needs_review_excluded' => $needsReviewExcluded,
            'suspicious_excluded' => $suspiciousExcluded,
            'items' => $items,
        ];
    }

    /**
     * @return array{updated:int,skipped:int,review_skipped:int}
     */
    public function seed(string $path): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Production ortaminda coordinate seeder yasaktir.');
        }

        $result = $this->import($path, true);

        return [
            'updated' => $result['update'],
            'skipped' => $result['skip'],
            'review_skipped' => 0,
        ];
    }

    /**
     * @return array{total:int,valid:int,insert:int,update:int,skip:int,conflict:int,invalid:int,delete:int,dry_run:bool}
     */
    public function import(string $path, bool $apply = false): array
    {
        $sourcePath = $this->pathPolicy->source($path);
        $decoded = json_decode((string) file_get_contents($sourcePath), true);
        $records = is_array($decoded) ? ($decoded['items'] ?? null) : null;

        if (! is_array($records)) {
            throw new RuntimeException('Private coordinate JSON semasi gecersiz.');
        }

        $analysis = $this->analyzeCoordinateRecords($records, false);

        if (! $apply) {
            return $this->coordinateSummary($analysis, true);
        }

        $this->assertCoordinateApplicable($analysis);

        return DB::transaction(function () use ($records): array {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE technical_service_technicians IN SHARE ROW EXCLUSIVE MODE');
            }

            $locked = $this->analyzeCoordinateRecords($records, true);
            $this->assertCoordinateApplicable($locked);

            foreach ($locked['operations'] as $operation) {
                $operation['technician']->forceFill($operation['changes'])->save();
            }

            return $this->coordinateSummary($locked, false);
        }, 1);
    }

    /**
     * @param  array<int, mixed>  $records
     * @return array<string, mixed>
     */
    private function analyzeCoordinateRecords(array $records, bool $lock): array
    {
        $analysis = [
            'total' => count($records),
            'valid' => 0,
            'insert' => 0,
            'update' => 0,
            'skip' => 0,
            'conflict' => 0,
            'invalid' => 0,
            'delete' => 0,
            'operations' => [],
        ];
        $seenSourceKeys = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                $analysis['invalid']++;

                continue;
            }

            if ((bool) ($record['needs_review'] ?? false)) {
                $analysis['skip']++;

                continue;
            }

            $sourceKey = $this->nullableText($record['source_key'] ?? null);
            $coordinates = $this->geocodingService->validCoordinatePair(
                $record['latitude'] ?? null,
                $record['longitude'] ?? null,
            );

            if ($sourceKey === null || $coordinates === null) {
                $analysis['invalid']++;

                continue;
            }

            if (isset($seenSourceKeys[$sourceKey])) {
                $analysis['conflict']++;

                continue;
            }
            $seenSourceKeys[$sourceKey] = true;

            $startLatitude = $record['start_latitude'] ?? $coordinates['latitude'];
            $startLongitude = $record['start_longitude'] ?? $coordinates['longitude'];
            $startCoordinates = $this->geocodingService->validCoordinatePair($startLatitude, $startLongitude);

            if ($startCoordinates === null) {
                $analysis['invalid']++;

                continue;
            }

            $identity = $this->resolveCoordinateTechnician($record, $sourceKey, $lock);
            if ($identity['status'] !== 'matched') {
                $analysis['conflict']++;

                continue;
            }

            /** @var TechnicalServiceTechnician $technician */
            $technician = $identity['technician'];
            $payload = [
                'latitude' => $this->normalizedCoordinate($coordinates['latitude']),
                'longitude' => $this->normalizedCoordinate($coordinates['longitude']),
                'start_latitude' => $this->normalizedCoordinate($startCoordinates['latitude']),
                'start_longitude' => $this->normalizedCoordinate($startCoordinates['longitude']),
                'location_source' => $this->nullableText($record['location_source'] ?? null),
                'route_note' => $this->nullableText($record['route_note'] ?? null),
                'needs_review' => false,
            ];
            $changes = [];

            foreach (self::WRITE_COLUMNS as $column) {
                $incoming = $payload[$column] ?? null;
                if ($incoming === null || $incoming === '') {
                    continue;
                }
                if ($column === 'needs_review' && (bool) $technician->needs_review) {
                    continue;
                }
                if ((string) ($technician->getAttribute($column) ?? '') !== (string) $incoming) {
                    $changes[$column] = $incoming;
                }
            }

            $analysis['valid']++;
            if ($changes === []) {
                $analysis['skip']++;

                continue;
            }

            $analysis['update']++;
            $analysis['operations'][] = [
                'technician' => $technician,
                'changes' => $changes,
            ];
        }

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{status:'matched'|'conflict',technician?:TechnicalServiceTechnician}
     */
    private function resolveCoordinateTechnician(array $record, string $sourceKey, bool $lock): array
    {
        $query = TechnicalServiceTechnician::withTrashed()->where('source_key', $sourceKey);
        $sourceMatches = ($lock ? $query->lockForUpdate() : $query)->get();

        if ($sourceMatches->count() === 1) {
            $technician = $sourceMatches->first();

            return ! $technician->trashed() && $technician->technician_type === LocksmithImportService::TYPE_LOCKSMITH
                ? ['status' => 'matched', 'technician' => $technician]
                : ['status' => 'conflict'];
        }

        if ($sourceMatches->count() > 1) {
            return ['status' => 'conflict'];
        }

        $phone = $this->nullableText($record['phone_e164'] ?? null);
        $city = $this->nullableText($record['city'] ?? null);
        if ($phone === null || $city === null) {
            return ['status' => 'conflict'];
        }

        $legacy = TechnicalServiceTechnician::withTrashed()
            ->where('phone_e164', $phone)
            ->where('city', $city);
        $legacyMatches = ($lock ? $legacy->lockForUpdate() : $legacy)->get();

        if ($legacyMatches->count() !== 1) {
            return ['status' => 'conflict'];
        }

        $technician = $legacyMatches->first();
        $storedSourceKey = $this->nullableText($technician->source_key);

        if ($technician->trashed()
            || $technician->technician_type !== LocksmithImportService::TYPE_LOCKSMITH
            || ($storedSourceKey !== null && $storedSourceKey !== $sourceKey)) {
            return ['status' => 'conflict'];
        }

        return ['status' => 'matched', 'technician' => $technician];
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function assertCoordinateApplicable(array $analysis): void
    {
        if ($analysis['invalid'] > 0 || $analysis['conflict'] > 0) {
            throw new RuntimeException(sprintf(
                'Coordinate import reddedildi: invalid=%d conflict=%d.',
                $analysis['invalid'],
                $analysis['conflict'],
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array{total:int,valid:int,insert:int,update:int,skip:int,conflict:int,invalid:int,delete:int,dry_run:bool}
     */
    private function coordinateSummary(array $analysis, bool $dryRun): array
    {
        return [
            'total' => $analysis['total'],
            'valid' => $analysis['valid'],
            'insert' => 0,
            'update' => $analysis['update'],
            'skip' => $analysis['skip'],
            'conflict' => $analysis['conflict'],
            'invalid' => $analysis['invalid'],
            'delete' => 0,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @param  Collection<int, TechnicalServiceTechnician>  $technicians
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
     * @param  array<string, array{coordinate:string,count:int,names:array<int,string>,cities:array<int,string>}>  $duplicateMap
     * @return array<int, string>
     */
    private function suspiciousReasons(TechnicalServiceTechnician $technician, array $duplicateMap): array
    {
        $reasons = [];
        $hasNonNumericCoordinates = $this->hasNonNumericCoordinates($technician);
        $coordinates = $hasNonNumericCoordinates ? null : $this->coordinatePair($technician);
        $coordinateKey = $this->coordinateKey($technician);

        if ($hasNonNumericCoordinates) {
            $reasons[] = 'non_numeric_coordinates';
        } elseif ($this->hasZeroZeroCoordinates($technician)) {
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
     * @param  array<string, mixed>  $record
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
        if ($this->hasNonNumericCoordinates($technician)) {
            return null;
        }

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
     * @param  array<int, string>  $reasons
     */
    private function shouldClearCoordinates(array $reasons): bool
    {
        return collect($reasons)->contains(fn (string $reason): bool => in_array($reason, [
            'zero_zero_coordinates',
            'non_numeric_coordinates',
            'invalid_coordinate_range',
            'coordinates_outside_turkey',
        ], true));
    }

    private function hasNonNumericCoordinates(TechnicalServiceTechnician $technician): bool
    {
        foreach (['latitude', 'longitude', 'start_latitude', 'start_longitude'] as $field) {
            $value = $technician->getRawOriginal($field);

            if ($value !== null && $value !== '' && ! is_numeric($value)) {
                return true;
            }
        }

        return false;
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

    private function normalizedCoordinate(float $value): string
    {
        return number_format($value, 7, '.', '');
    }

    /**
     * @param  array<int, string>  $reasons
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
