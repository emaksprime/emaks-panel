<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportTechnicalServiceTechniciansCsvRequest;
use App\Http\Requests\StoreTechnicalServiceTechnicianRequest;
use App\Http\Requests\UpdateTechnicalServiceTechnicianRequest;
use App\Models\TechnicalServiceTechnician;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = TechnicalServiceTechnician::query();

        if (array_key_exists('active', $filters)) {
            $query->where('active', $filters['active']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($query) use ($search) {
                $query->where('name', 'ilike', "%{$search}%")
                    ->orWhere('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('city', 'ilike', "%{$search}%")
                    ->orWhere('district', 'ilike', "%{$search}%")
                    ->orWhere('mikro_cari_kodu', 'ilike', "%{$search}%")
                    ->orWhere('mikro_cari_adi', 'ilike', "%{$search}%");
            });
        }

        return response()->json([
            'items' => $query
                ->orderByDesc('active')
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
        $technician->update($this->technicianPayload($request->validated(), $technician));

        return response()->json(['technician' => $technician->fresh()]);
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

        return $payload;
    }
}
