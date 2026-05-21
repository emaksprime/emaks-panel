<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportTechnicalServiceTechniciansCsvRequest;
use App\Http\Requests\StoreTechnicalServiceTechnicianRequest;
use App\Http\Requests\UpdateTechnicalServiceTechnicianRequest;
use App\Models\TechnicalServiceTechnician;
use App\Services\TechnicalService\TechnicalServiceGeocodingService;
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
                ->get(),
        ]);
    }

    public function store(StoreTechnicalServiceTechnicianRequest $request): JsonResponse
    {
        $technician = TechnicalServiceTechnician::create($this->technicianPayload($request->validated()));

        return response()->json(['technician' => $technician], 201);
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

        return response()->json(['technician' => $technician->fresh()]);
    }

    public function geocode(
        TechnicalServiceTechnician $technician,
        TechnicianGeocodingService $geocodingService,
    ): JsonResponse {
        $result = $geocodingService->geocode($technician);

        if (! ($result['ok'] ?? false)) {
            $technician->forceFill([
                'needs_review' => true,
                'route_note' => (string) ($result['error_message'] ?? 'Geocoding başarısız.'),
            ])->save();

            return response()->json([
                'ok' => false,
                'message' => $result['error_message'] ?? 'Geocoding başarısız.',
                'result' => $result,
                'technician' => $technician->fresh(),
            ], 422);
        }

        $technician->forceFill([
            'latitude' => $result['latitude'],
            'longitude' => $result['longitude'],
            'start_latitude' => $result['latitude'],
            'start_longitude' => $result['longitude'],
            'location_source' => $result['provider'] ?? 'google_geocode',
            'route_note' => $this->geocodeRouteNote($result),
            'needs_review' => (bool) ($result['needs_review'] ?? false),
        ])->save();

        return response()->json([
            'ok' => true,
            'message' => 'Koordinat Google ile güncellendi.',
            'result' => $result,
            'technician' => $technician->fresh(),
        ]);
    }

    public function destroy(TechnicalServiceTechnician $technician): JsonResponse
    {
        $technician->update(['active' => false]);

        return response()->json(['technician' => $technician->fresh()]);
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
