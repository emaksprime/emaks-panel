<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportTechnicalServiceTechniciansCsvRequest;
use App\Http\Requests\StoreTechnicalServiceTechnicianRequest;
use App\Http\Requests\UpdateTechnicalServiceTechnicianRequest;
use App\Models\TechnicalServiceTechnician;
use App\Services\TechnicalService\TechnicalServiceGeocodingService;
use App\Services\TechnicalService\TechnicalServiceUiLabelService;
use App\Services\TechnicalService\TechnicianGeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TechnicalServiceTechnicianController extends Controller
{
    private const CSV_COLUMNS = [
        'first_name',
        'last_name',
        'phone',
        'city',
        'district',
        'address',
        'google_plus_code',
        'google_formatted_address',
        'default_start_address',
        'default_start_plus_code',
        'mikro_cari_kodu',
        'mikro_cari_adi',
        'note',
        'active',
    ];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'active' => ['nullable', 'boolean'],
            'needs_review' => ['nullable', 'boolean'],
            'technician_type' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:128'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = TechnicalServiceTechnician::query()
            ->with(['b2bPartnerLinks' => function ($query): void {
                $query
                    ->with('partner')
                    ->where('active', true)
                    ->orderByDesc('is_primary')
                    ->orderBy('id');
            }]);
        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        if (array_key_exists('active', $filters)) {
            $query->where('active', $filters['active']);
        }

        if (array_key_exists('needs_review', $filters)) {
            $query->where('needs_review', $filters['needs_review']);
        }

        if (! empty($filters['technician_type'])) {
            $query->where('technician_type', $filters['technician_type']);
        }

        if (! empty($filters['city'])) {
            $query->where('city', $likeOperator, $filters['city']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($query) use ($likeOperator, $search) {
                $query->where('name', $likeOperator, "%{$search}%")
                    ->orWhere('first_name', $likeOperator, "%{$search}%")
                    ->orWhere('last_name', $likeOperator, "%{$search}%")
                    ->orWhere('phone', $likeOperator, "%{$search}%")
                    ->orWhere('phone_e164', $likeOperator, "%{$search}%")
                    ->orWhere('city', $likeOperator, "%{$search}%")
                    ->orWhere('district', $likeOperator, "%{$search}%")
                    ->orWhere('mikro_cari_kodu', $likeOperator, "%{$search}%")
                    ->orWhere('mikro_cari_adi', $likeOperator, "%{$search}%")
                    ->orWhere('cari_code', $likeOperator, "%{$search}%")
                    ->orWhere('cari_title', $likeOperator, "%{$search}%")
                    ->orWhere('location_code', $likeOperator, "%{$search}%");
            });
        }

        return response()->json([
            'items' => $query
                ->orderByDesc('active')
                ->orderBy('technician_type')
                ->orderBy('city')
                ->orderByRaw('priority is null')
                ->orderBy('priority')
                ->orderBy('name')
                ->get()
                ->map(fn (TechnicalServiceTechnician $technician): array => $this->technicianResponsePayload($technician))
                ->values(),
        ]);
    }

    public function store(StoreTechnicalServiceTechnicianRequest $request): JsonResponse
    {
        $technician = TechnicalServiceTechnician::create($this->technicianPayload($request->validated()));

        return response()->json(['technician' => $this->technicianResponsePayload($technician)], 201);
    }

    public function update(
        UpdateTechnicalServiceTechnicianRequest $request,
        TechnicalServiceTechnician $technician,
    ): JsonResponse {
        $payload = $this->technicianPayload($request->validated(), $technician);
        $coordinateChanged = $this->payloadCoordinatePairChanged($technician, $payload);
        $this->applyManualCoordinateState($payload, $coordinateChanged);

        if ($this->addressFieldsChanged($technician, $payload)
            && $this->hasStoredCoordinates($technician)
            && ! $coordinateChanged
        ) {
            $payload['needs_review'] = true;
            $payload['route_note'] = 'Adres değişti, koordinat yeniden doğrulanmalı. Mevcut koordinat eski adrese ait olabilir.';
        }

        $technician->update($payload);

        return response()->json(['technician' => $this->technicianResponsePayload($technician->fresh())]);
    }

    public function geocode(
        Request $request,
        TechnicalServiceTechnician $technician,
        TechnicianGeocodingService $geocodingService,
    ): JsonResponse {
        $options = $request->validate([
            'dry_run' => ['nullable', 'boolean'],
            'apply' => ['nullable', 'boolean'],
            'override_existing_coordinates' => ['nullable', 'boolean'],
        ]);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $apply = array_key_exists('apply', $options) ? (bool) $options['apply'] : ! $dryRun;
        $overrideExisting = (bool) ($options['override_existing_coordinates'] ?? false);
        $plan = $geocodingService->bestQueryFor($technician);

        if ($dryRun || ! $apply) {
            return response()->json([
                'ok' => $plan !== null,
                'dry_run' => true,
                'message' => $plan ? 'Geocode planı hazır.' : 'Adres veya Plus Code bulunamadı.',
                'plan' => $plan,
                'technician' => $this->technicianResponsePayload($technician),
            ], $plan ? 200 : 422);
        }

        if ($this->hasStoredCoordinates($technician) && ! $overrideExisting) {
            return response()->json([
                'ok' => true,
                'message' => 'Mevcut koordinat korundu.',
                'result' => [
                    'status' => 'skipped_existing_coordinates',
                    'needs_review' => (bool) $technician->needs_review,
                ],
                'technician' => $this->technicianResponsePayload($technician),
            ]);
        }

        $result = $geocodingService->geocode($technician);

        if (! ($result['ok'] ?? false)) {
            $reviewReasons = $this->technicianReviewReasons($technician, $result);
            $technician->forceFill([
                'needs_review' => true,
                'review_status' => 'review_required',
                'review_reason' => implode(' ', $reviewReasons),
                'review_reasons' => $reviewReasons,
                'route_note' => (string) ($result['error_message'] ?? 'Geocoding başarısız.'),
                ...$this->geocodePersistencePayload($result),
            ])->save();

            return response()->json([
                'ok' => false,
                'message' => $result['error_message'] ?? 'Geocoding başarısız.',
                'result' => $result,
                'technician' => $this->technicianResponsePayload($technician->fresh()),
            ], 422);
        }

        $technician->forceFill([
            'latitude' => $result['latitude'],
            'longitude' => $result['longitude'],
            'start_latitude' => $result['latitude'],
            'start_longitude' => $result['longitude'],
            'google_formatted_address' => $this->blankToNull($result['formatted_address'] ?? null) ?? $technician->google_formatted_address,
            'location_source' => $result['provider'] ?? 'google_geocode',
            'route_note' => $this->geocodeRouteNote($result),
            ...$this->geocodePersistencePayload($result),
        ])->save();
        $reviewReasons = $this->technicianReviewReasons($technician->fresh(), $result);
        $technician->forceFill([
            'needs_review' => $reviewReasons !== [],
            'review_status' => $reviewReasons === [] ? 'ready' : 'review_required',
            'review_reason' => $reviewReasons === [] ? null : implode(' ', $reviewReasons),
            'review_reasons' => $reviewReasons,
        ])->save();

        return response()->json([
            'ok' => true,
            'message' => 'Koordinat Google ile güncellendi.',
            'result' => $result,
            'technician' => $this->technicianResponsePayload($technician->fresh()),
        ]);
    }

    public function locationReview(Request $request, TechnicalServiceTechnician $technician): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:128'],
            'district' => ['nullable', 'string', 'max:128'],
            'address' => ['nullable', 'string', 'max:2000'],
            'google_plus_code' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'default_start_address' => ['nullable', 'string', 'max:2000'],
            'start_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'start_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:2000'],
            'mark_reviewed' => ['nullable', 'boolean'],
        ]);

        $payload = collect($data)
            ->except(['mark_reviewed'])
            ->filter(fn (mixed $value): bool => $value !== '')
            ->all();
        $coordinateChanged = $this->payloadCoordinatePairChanged($technician, $payload);
        $this->applyManualCoordinateState($payload, $coordinateChanged);
        $technician->fill($payload);
        $reviewReasons = $this->technicianReviewReasons($technician);

        if ((bool) ($data['mark_reviewed'] ?? false) && $reviewReasons !== []) {
            throw ValidationException::withMessages([
                'mark_reviewed' => 'Kontrol kapatılamaz: telefon/adres/koordinat eksik.',
            ]);
        }

        $technician->forceFill([
            'needs_review' => $reviewReasons !== [],
            'review_status' => $reviewReasons === [] ? ((bool) ($data['mark_reviewed'] ?? false) ? 'reviewed' : 'ready') : 'review_required',
            'review_reason' => $reviewReasons === [] ? null : implode(' ', $reviewReasons),
            'review_reasons' => $reviewReasons,
            'reviewed_at' => ((bool) ($data['mark_reviewed'] ?? false) && $reviewReasons === []) ? now() : $technician->reviewed_at,
            'reviewed_by' => ((bool) ($data['mark_reviewed'] ?? false) && $reviewReasons === []) ? $request->user()?->id : $technician->reviewed_by,
        ])->save();

        return response()->json([
            'technician' => $this->technicianResponsePayload($technician->fresh()),
        ]);
    }

    public function markReviewed(Request $request, TechnicalServiceTechnician $technician): JsonResponse
    {
        $reviewReasons = $this->technicianReviewReasons($technician);

        if ($reviewReasons !== []) {
            throw ValidationException::withMessages([
                'mark_reviewed' => 'Kontrol kapatılamaz: telefon/adres/koordinat eksik.',
            ]);
        }

        $technician->forceFill([
            'needs_review' => false,
            'review_status' => 'reviewed',
            'review_reason' => null,
            'review_reasons' => [],
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()?->id,
        ])->save();

        return response()->json([
            'technician' => $this->technicianResponsePayload($technician->fresh()),
        ]);
    }

    public function destroy(TechnicalServiceTechnician $technician): JsonResponse
    {
        $technician->update(['active' => false]);

        return response()->json(['technician' => $this->technicianResponsePayload($technician->fresh())]);
    }

    public function importCsv(ImportTechnicalServiceTechniciansCsvRequest $request): JsonResponse
    {
        $path = $request->file('file')->getRealPath();
        $contents = $path ? file_get_contents($path) : false;

        if ($contents === false) {
            return response()->json([
                'created_count' => 0,
                'skipped_count' => 0,
                'errors' => [['row' => 0, 'reason' => 'CSV dosyası okunamadı.', 'data' => []]],
            ], 422);
        }

        $contents = $this->normalizeCsvEncoding($contents);
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $lines = array_values(array_filter($lines, fn ($line) => trim((string) $line) !== ''));
        $delimiter = $this->detectCsvDelimiter($lines[0] ?? '');
        $header = isset($lines[0]) ? str_getcsv($lines[0], $delimiter) : [];
        $columns = array_map(fn ($value) => Str::lower(trim((string) $value)), $header ?: []);

        if ($columns !== self::CSV_COLUMNS) {
            return response()->json([
                'created_count' => 0,
                'skipped_count' => 0,
                'errors' => [[
                    'row' => 1,
                    'reason' => 'CSV başlığı beklenen kolonlarla eşleşmiyor. Virgül veya noktalı virgül ayracı desteklenir. Beklenen sıra: '.implode(',', self::CSV_COLUMNS),
                    'data' => [
                        'detected_delimiter' => $delimiter,
                        'received_columns' => $columns,
                        'expected_columns' => self::CSV_COLUMNS,
                    ],
                ]],
            ], 422);
        }

        $createdCount = 0;
        $skippedCount = 0;
        $errors = [];
        $seenPhones = [];
        $seenNamePhones = [];
        $rowNumber = 1;

        foreach (array_slice($lines, 1) as $line) {
            $rowNumber++;
            $row = str_getcsv($line, $delimiter);
            $data = array_combine(self::CSV_COLUMNS, array_slice(array_pad($row, count(self::CSV_COLUMNS), ''), 0, count(self::CSV_COLUMNS)));

            if ($data === false) {
                $skippedCount++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'Satır okunamadı.', 'data' => $row];
                continue;
            }

            $active = $this->parseActive($data['active']);
            if ($active === null) {
                $skippedCount++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'active alanı 1 veya 0 olmalı.', 'data' => $data];
                continue;
            }

            $payload = $this->technicianPayload([
                'first_name' => trim((string) $data['first_name']),
                'last_name' => $this->blankToNull($data['last_name']),
                'phone' => $this->blankToNull($data['phone']),
                'city' => $this->blankToNull($data['city']),
                'district' => $this->blankToNull($data['district']),
                'address' => $this->blankToNull($data['address']),
                'google_plus_code' => $this->blankToNull($data['google_plus_code']),
                'google_formatted_address' => $this->blankToNull($data['google_formatted_address']),
                'default_start_address' => $this->blankToNull($data['default_start_address']),
                'default_start_plus_code' => $this->blankToNull($data['default_start_plus_code']),
                'mikro_cari_kodu' => $this->blankToNull($data['mikro_cari_kodu']),
                'mikro_cari_adi' => $this->blankToNull($data['mikro_cari_adi']),
                'note' => $this->blankToNull($data['note']),
                'active' => $active,
            ]);

            if ($payload['first_name'] === '') {
                $skippedCount++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'first_name zorunlu.', 'data' => $data];
                continue;
            }

            $phoneKey = $this->normalizeDuplicateKey($payload['phone']);
            $namePhoneKey = $this->normalizeDuplicateKey($payload['name'].'|'.($payload['phone'] ?? ''));

            $isDuplicate = false;
            $duplicateReason = null;

            if ($phoneKey !== '' && (isset($seenPhones[$phoneKey]) || TechnicalServiceTechnician::withTrashed()->where('phone', $payload['phone'])->exists())) {
                $isDuplicate = true;
                $duplicateReason = 'Aynı phone değerine sahip kayıt var.';
            }

            if (! $isDuplicate && (isset($seenNamePhones[$namePhoneKey]) || TechnicalServiceTechnician::withTrashed()
                ->where('name', $payload['name'])
                ->where('phone', $payload['phone'])
                ->exists())) {
                $isDuplicate = true;
                $duplicateReason = 'Aynı first_name + last_name + phone değerine sahip kayıt var.';
            }

            if ($isDuplicate) {
                $skippedCount++;
                $errors[] = ['row' => $rowNumber, 'reason' => $duplicateReason, 'data' => $data];
                continue;
            }

            TechnicalServiceTechnician::create($payload);
            $createdCount++;

            if ($phoneKey !== '') {
                $seenPhones[$phoneKey] = true;
            }
            $seenNamePhones[$namePhoneKey] = true;
        }

        return response()->json([
            'created_count' => $createdCount,
            'skipped_count' => $skippedCount,
            'errors' => $errors,
        ]);
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function parseActive(mixed $value): ?bool
    {
        $normalized = trim((string) $value);

        if ($normalized === '1') {
            return true;
        }

        if ($normalized === '0') {
            return false;
        }

        return null;
    }

    private function normalizeDuplicateKey(?string $value): string
    {
        return Str::lower(trim((string) $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function technicianResponsePayload(TechnicalServiceTechnician $technician): array
    {
        $payload = $technician->toArray();
        $city = TechnicalServiceUiLabelService::cityLabel($technician->city);

        $payload['name'] = TechnicalServiceUiLabelService::displayName($technician->name);
        $payload['first_name'] = TechnicalServiceUiLabelService::displayName($technician->first_name);
        $payload['last_name'] = TechnicalServiceUiLabelService::displayName($technician->last_name);
        $payload['display_name'] = TechnicalServiceUiLabelService::displayName($technician->display_name);
        $payload['city'] = $city;
        $payload['district'] = TechnicalServiceUiLabelService::districtLabel($technician->district, $city);

        foreach ([
            'address',
            'google_formatted_address',
            'default_start_address',
            'mikro_cari_adi',
            'cari_title',
            'cari_address',
            'cari_city_district_country',
            'note',
            'route_note',
        ] as $field) {
            $payload[$field] = TechnicalServiceUiLabelService::addressLabel($technician->{$field});
        }

        return $payload;
    }

    private function normalizeCsvEncoding(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        foreach (['Windows-1254', 'ISO-8859-9', 'ISO-8859-1'] as $encoding) {
            $converted = @mb_convert_encoding($contents, 'UTF-8', $encoding);

            if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        return $contents;
    }

    private function detectCsvDelimiter(string $header): string
    {
        $commaCount = substr_count($header, ',');
        $semicolonCount = substr_count($header, ';');

        return $semicolonCount > $commaCount ? ';' : ',';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function technicianPayload(array $payload, ?TechnicalServiceTechnician $technician = null): array
    {
        $firstName = trim((string) ($payload['first_name'] ?? $technician?->first_name ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? $technician?->last_name ?? ''));

        $payload['first_name'] = $firstName;
        $payload['last_name'] = $lastName === '' ? null : $lastName;
        $payload['name'] = trim($firstName.' '.$lastName);
        $payload['phone_e164'] = $payload['phone_e164'] ?? $payload['phone'] ?? $technician?->phone_e164;

        if (($payload['source_key'] ?? null) === null && ($payload['phone_e164'] ?? null) !== null && ($payload['city'] ?? $technician?->city) !== null) {
            $type = $payload['technician_type'] ?? $technician?->technician_type ?? 'technician';
            $payload['source_key'] = $type.':'.$payload['phone_e164'].':'.Str::of((string) ($payload['city'] ?? $technician?->city))
                ->ascii()
                ->upper()
                ->replaceMatches('/[^A-Z0-9]+/', ' ')
                ->squish()
                ->value();
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyManualCoordinateState(array &$payload, bool $coordinateChanged): void
    {
        $primaryPair = $this->coordinatePairFromPayload($payload, 'latitude', 'longitude');
        $startPair = $this->coordinatePairFromPayload($payload, 'start_latitude', 'start_longitude');

        if ($coordinateChanged && ($primaryPair !== null || $startPair !== null)) {
            $payload['location_source'] = 'manual';
            $payload['needs_review'] = false;
            $payload['route_note'] = 'Manuel koordinat doğrulandı: '.now()->toDateTimeString();
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{latitude:float,longitude:float}|null
     */
    private function coordinatePairFromPayload(array $payload, string $latitudeKey, string $longitudeKey): ?array
    {
        if (! array_key_exists($latitudeKey, $payload) && ! array_key_exists($longitudeKey, $payload)) {
            return null;
        }

        $latitude = $payload[$latitudeKey] ?? null;
        $longitude = $payload[$longitudeKey] ?? null;

        if ($latitude === null && $longitude === null) {
            return null;
        }

        $coordinates = app(TechnicalServiceGeocodingService::class)->validCoordinatePair($latitude, $longitude);

        if ($coordinates === null) {
            throw ValidationException::withMessages([
                $latitudeKey => ['Koordinat geçersiz. Latitude/Longitude numeric olmalı ve 0/0 olamaz.'],
            ]);
        }

        return $coordinates;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadCoordinatePairChanged(TechnicalServiceTechnician $technician, array $payload): bool
    {
        foreach (['latitude', 'longitude', 'start_latitude', 'start_longitude'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $new = $payload[$field] === null || $payload[$field] === '' ? null : (float) $payload[$field];
            $old = $technician->{$field} === null || $technician->{$field} === '' ? null : (float) $technician->{$field};

            if ($new === null && $old === null) {
                continue;
            }

            if ($new === null || $old === null || abs($new - $old) > 0.000001) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addressFieldsChanged(TechnicalServiceTechnician $technician, array $payload): bool
    {
        foreach ([
            'city',
            'district',
            'address',
            'location_code',
            'google_plus_code',
            'google_formatted_address',
            'default_start_address',
            'default_start_plus_code',
            'cari_address',
            'cari_city_district_country',
        ] as $field) {
            if (array_key_exists($field, $payload) && trim((string) $payload[$field]) !== trim((string) $technician->{$field})) {
                return true;
            }
        }

        return false;
    }

    private function hasStoredCoordinates(TechnicalServiceTechnician $technician): bool
    {
        return app(TechnicalServiceGeocodingService::class)->validCoordinatePair($technician->latitude, $technician->longitude) !== null
            || app(TechnicalServiceGeocodingService::class)->validCoordinatePair($technician->start_latitude, $technician->start_longitude) !== null;
    }

    /**
     * @param array<string, mixed> $geocodeResult
     * @return array<int, string>
     */
    private function technicianReviewReasons(TechnicalServiceTechnician $technician, array $geocodeResult = []): array
    {
        $reasons = [];

        if ($this->blankToNull($technician->phone) === null && $this->blankToNull($technician->phone_e164) === null) {
            $reasons[] = 'Telefon eksik.';
        }

        if (($this->blankToNull($technician->address) === null
            && $this->blankToNull($technician->cari_address) === null
            && $this->blankToNull($technician->google_formatted_address) === null
            && $this->blankToNull($technician->default_start_address) === null)
            || $this->blankToNull($technician->city) === null) {
            $reasons[] = 'Adres/şehir eksik.';
        }

        if (! $this->hasStoredCoordinates($technician)) {
            $reasons[] = 'Koordinat eksik.';
        }

        $geocodeReason = $this->blankToNull($geocodeResult['review_reason'] ?? $geocodeResult['error_message'] ?? null);
        if ((bool) ($geocodeResult['needs_review'] ?? false) && $geocodeReason !== null) {
            $reasons[] = $geocodeReason;
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function geocodePersistencePayload(array $result): array
    {
        $ok = (bool) ($result['ok'] ?? false);

        return [
            'geocode_status' => $ok ? ((bool) ($result['needs_review'] ?? false) ? 'review_required' : 'ok') : ((string) ($result['status'] ?? 'failed')),
            'geocode_source' => $this->blankToNull($result['source_type'] ?? null) ?? $this->blankToNull($result['provider'] ?? null),
            'geocode_confidence' => $this->geocodeConfidence($result),
            'geocoded_at' => now(),
            'geocode_payload' => collect($result)
                ->only(['ok', 'status', 'provider', 'query', 'source_type', 'quality', 'needs_review', 'review_reason', 'location_type', 'latitude', 'longitude', 'formatted_address', 'error_message'])
                ->all(),
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function geocodeConfidence(array $result): int
    {
        if (! (bool) ($result['ok'] ?? false)) {
            return 0;
        }

        return match ((string) ($result['quality'] ?? '')) {
            'exact_plus_code' => 95,
            'formatted_address' => 88,
            'address_fallback' => (bool) ($result['needs_review'] ?? false) ? 60 : 78,
            default => (bool) ($result['needs_review'] ?? false) ? 50 : 70,
        };
    }

    /**
     * @param array<string, mixed> $result
     */
    private function geocodeRouteNote(array $result): string
    {
        $source = trim((string) ($result['source_type'] ?? 'unknown'));
        $formatted = trim((string) ($result['formatted_address'] ?? ''));
        $locationType = trim((string) ($result['location_type'] ?? ''));
        $note = "Geocoded from {$source}";

        if ($formatted !== '') {
            $note .= "; formatted: {$formatted}";
        }

        $reviewReason = trim((string) ($result['review_reason'] ?? ''));
        if ((bool) ($result['needs_review'] ?? false) && $reviewReason !== '') {
            $note .= "; {$reviewReason}";
        }

        if ($locationType !== '') {
            $note .= "; location_type: {$locationType}";
        }

        return $note.'; at '.now()->toDateTimeString();
    }
}
