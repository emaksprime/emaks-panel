<?php

namespace App\Services\TechnicalService;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\TechnicalServiceTechnician;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

class TechnicianImportPreviewService
{
    private const MAX_PREVIEW_ROWS = 1000;

    /**
     * @var array<string, string>
     */
    private const HEADER_ALIASES = [
        'ad' => 'first_name',
        'isim' => 'first_name',
        'first name' => 'first_name',
        'soyad' => 'last_name',
        'soyisim' => 'last_name',
        'last name' => 'last_name',
        'ad soyad' => 'full_name',
        'isim soyisim' => 'full_name',
        'usta adi' => 'full_name',
        'usta adı' => 'full_name',
        'teknisyen adi' => 'full_name',
        'teknisyen adı' => 'full_name',
        'cilingir adi' => 'full_name',
        'çilingir adı' => 'full_name',
        'name' => 'full_name',
        'telefon' => 'phone',
        'telefon 90 format' => 'phone',
        'telefon okunur' => 'phone_display',
        'tel' => 'phone',
        'cep' => 'phone',
        'gsm' => 'phone',
        'phone' => 'phone',
        'plaka kodu' => 'city_plate_code',
        'il' => 'city',
        'sehir' => 'city',
        'şehir' => 'city',
        'city' => 'city',
        'ilce' => 'district',
        'ilçe' => 'district',
        'district' => 'district',
        'adres' => 'address',
        'acik adres' => 'address',
        'açık adres' => 'address',
        'address' => 'address',
        'cari adres' => 'address',
        'cari ilce il ulke' => 'cari_city_district_country',
        'cari ilçe il ülke' => 'cari_city_district_country',
        'konum adres kodu' => 'google_plus_code',
        'plus code' => 'google_plus_code',
        'google plus code' => 'google_plus_code',
        'google konum kodu' => 'google_plus_code',
        'konum kodu' => 'google_plus_code',
        'google adres' => 'google_formatted_address',
        'google dogrulanmis adres' => 'google_formatted_address',
        'google doğrulanmış adres' => 'google_formatted_address',
        'formatted address' => 'google_formatted_address',
        'baslangic adresi' => 'default_start_address',
        'başlangıç adresi' => 'default_start_address',
        'varsayilan baslangic adresi' => 'default_start_address',
        'varsayılan başlangıç adresi' => 'default_start_address',
        'start address' => 'default_start_address',
        'baslangic plus code' => 'default_start_plus_code',
        'başlangıç plus code' => 'default_start_plus_code',
        'start plus code' => 'default_start_plus_code',
        'lat' => 'latitude',
        'latitude' => 'latitude',
        'enlem' => 'latitude',
        'lng' => 'longitude',
        'lon' => 'longitude',
        'longitude' => 'longitude',
        'boylam' => 'longitude',
        'baslangic latitude' => 'start_latitude',
        'başlangıç latitude' => 'start_latitude',
        'start lat' => 'start_latitude',
        'baslangic longitude' => 'start_longitude',
        'başlangıç longitude' => 'start_longitude',
        'start lng' => 'start_longitude',
        'mikro cari kodu' => 'mikro_cari_kodu',
        'cari kodu' => 'mikro_cari_kodu',
        'cari kod' => 'mikro_cari_kodu',
        'cari' => 'mikro_cari_kodu',
        'mikro kod' => 'mikro_cari_kodu',
        'cari kodu' => 'mikro_cari_kodu',
        'cari unvan' => 'mikro_cari_adi',
        'cari ünvan' => 'mikro_cari_adi',
        'cari unvani' => 'mikro_cari_adi',
        'cari ünvanı' => 'mikro_cari_adi',
        'mikro cari adi' => 'mikro_cari_adi',
        'mikro cari adı' => 'mikro_cari_adi',
        'cari adi' => 'mikro_cari_adi',
        'cari adı' => 'mikro_cari_adi',
        'not' => 'note',
        'aciklama' => 'note',
        'açıklama' => 'note',
        'kontrol notu' => 'note',
        'aktif' => 'active',
        'durum' => 'active',
        'active' => 'active',
    ];

    public function __construct(
        private readonly LocksmithXlsxReader $xlsxReader = new LocksmithXlsxReader(),
        private readonly TechnicalServiceGeocodingService $geocodingService = new TechnicalServiceGeocodingService(),
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function preview(UploadedFile $file, array $options = []): array
    {
        $extension = mb_strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()), 'UTF-8');

        if (! in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
            throw new RuntimeException($extension === 'xls'
                ? 'XLS dosyası bu fazda desteklenmiyor. Lütfen XLSX veya CSV yükleyin.'
                : 'Desteklenmeyen dosya türü. Lütfen CSV veya XLSX yükleyin.');
        }

        $path = $file->getRealPath();
        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException('Import dosyası okunamadı.');
        }

        $parsed = $extension === 'xlsx'
            ? $this->parseXlsx($path, $this->blankToNull($options['sheet_name'] ?? null))
            : $this->parseCsv($path);
        $rows = array_slice($parsed['rows'], 0, self::MAX_PREVIEW_ROWS);
        $fileHash = hash_file('sha256', $path) ?: null;
        $summary = $this->emptySummary(count($parsed['rows']));
        $previewRows = [];
        $seenPhones = [];
        $seenCariCodes = [];

        foreach ($rows as $row) {
            $preview = $this->previewRow(
                $row['row_number'],
                $row['data'],
                $seenPhones,
                $seenCariCodes,
                [
                    'update_existing' => (bool) ($options['update_existing'] ?? false),
                    'override_existing_coordinates' => (bool) ($options['override_existing_coordinates'] ?? false),
                    'link_existing_partners' => ! array_key_exists('link_existing_partners', $options) || (bool) $options['link_existing_partners'],
                    'geocode_mode' => (string) ($options['geocode_mode'] ?? 'plan_only'),
                ],
            );
            $previewRows[] = $preview;
            $this->countRow($summary, $preview);
        }

        $summary['parsed_rows'] = count($rows);
        $summary['valid_rows'] = count(array_filter($previewRows, fn (array $row): bool => $row['action'] !== 'error'));
        $summary['error_count'] = count(array_filter($previewRows, fn (array $row): bool => $row['errors'] !== []));
        $summary['warning_count'] = array_sum(array_map(fn (array $row): int => count($row['warnings']), $previewRows));

        return [
            'ok' => true,
            'dry_run' => true,
            'writes_performed' => false,
            'file' => [
                'original_name' => $file->getClientOriginalName(),
                'extension' => $extension,
                'sheet_name' => $parsed['sheet_name'],
                'available_sheets' => $parsed['available_sheets'],
                'detected_header_row' => $parsed['detected_header_row'],
                'row_count' => count($parsed['rows']),
                'file_hash' => $fileHash,
            ],
            'summary' => $summary,
            'rows' => $previewRows,
        ];
    }

    /**
     * @return array{sheet_name:?string,available_sheets:array<int,string>,detected_header_row:int,row_count:int,rows:array<int,array{row_number:int,data:array<string,mixed>}>}
     */
    private function parseCsv(string $path): array
    {
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('CSV dosyası okunamadı.');
        }

        $contents = $this->normalizeCsvEncoding($contents);
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $rawRows = [];
        $delimiter = $this->detectCsvDelimiter($lines);

        foreach ($lines as $line) {
            if (trim((string) $line) === '') {
                continue;
            }

            $rawRows[] = str_getcsv((string) $line, $delimiter);
        }

        return $this->rowsFromRawRows($rawRows, null, []);
    }

    /**
     * @return array{sheet_name:?string,available_sheets:array<int,string>,detected_header_row:int,row_count:int,rows:array<int,array{row_number:int,data:array<string,mixed>}>}
     */
    private function parseXlsx(string $path, ?string $requestedSheetName): array
    {
        $availableSheets = $this->xlsxReader->sheetNames($path);
        if ($availableSheets === []) {
            throw new RuntimeException('Excel dosyasında sheet bulunamadı.');
        }

        $sheetName = $requestedSheetName;
        if ($sheetName === null) {
            $sheetName = collect($availableSheets)
                ->first(fn (string $name): bool => mb_strtolower($name, 'UTF-8') === mb_strtolower(LocksmithImportService::SHEET_NAME, 'UTF-8'))
                ?? $availableSheets[0];
        }

        if (! in_array($sheetName, $availableSheets, true)) {
            throw new RuntimeException("Excel sheet bulunamadı: {$sheetName}");
        }

        return $this->rowsFromRawRows($this->xlsxReader->rawRows($path, $sheetName), $sheetName, $availableSheets);
    }

    /**
     * @param array<int, array<int, mixed>> $rawRows
     * @param array<int, string> $availableSheets
     * @return array{sheet_name:?string,available_sheets:array<int,string>,detected_header_row:int,row_count:int,rows:array<int,array{row_number:int,data:array<string,mixed>}>}
     */
    private function rowsFromRawRows(array $rawRows, ?string $sheetName, array $availableSheets): array
    {
        $header = $this->detectHeader($rawRows);
        $headers = $header['headers'];
        $items = [];

        foreach (array_slice($rawRows, $header['index'] + 1, null, true) as $rowIndex => $row) {
            $data = [];
            $hasValue = false;

            foreach ($headers as $columnIndex => $canonical) {
                if ($canonical === null) {
                    continue;
                }

                $value = $this->blankToNull($row[$columnIndex] ?? null);
                $data[$canonical] = $value;
                $hasValue = $hasValue || $value !== null;
            }

            if ($hasValue) {
                $items[] = [
                    'row_number' => $rowIndex + 1,
                    'data' => $data,
                ];
            }
        }

        return [
            'sheet_name' => $sheetName,
            'available_sheets' => $availableSheets,
            'detected_header_row' => $header['index'] + 1,
            'row_count' => count($items),
            'rows' => $items,
        ];
    }

    /**
     * @param array<int, array<int, mixed>> $rawRows
     * @return array{index:int,headers:array<int,string|null>}
     */
    private function detectHeader(array $rawRows): array
    {
        foreach (array_slice($rawRows, 0, 20, true) as $index => $row) {
            $headers = [];
            foreach ($row as $columnIndex => $value) {
                $headers[$columnIndex] = $this->canonicalHeader($value);
            }

            if (count(array_unique(array_filter($headers))) >= 4) {
                return [
                    'index' => $index,
                    'headers' => $headers,
                ];
            }
        }

        throw new RuntimeException('Başlık satırı bulunamadı. İlk 20 satırda en az 4 tanınan kolon olmalı.');
    }

    private function canonicalHeader(mixed $value): ?string
    {
        $key = $this->normalizeHeader($value);

        return self::HEADER_ALIASES[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, int> $seenPhones
     * @param array<string, int> $seenCariCodes
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function previewRow(int $rowNumber, array $data, array &$seenPhones, array &$seenCariCodes, array $options): array
    {
        $normalized = $this->normalizedRow($data);
        $warnings = [];
        $errors = [];
        $duplicates = [];

        if ($normalized['full_name'] === null) {
            $errors[] = 'Ad soyad veya ad alanı zorunlu.';
        }

        if (($data['active'] ?? null) !== null && $normalized['active'] === null) {
            $errors[] = 'Aktif alanı sadece 1/0, true/false, aktif/pasif veya evet/hayır olabilir.';
        }

        $coordinateError = $this->coordinateError($normalized);
        if ($coordinateError !== null) {
            $errors[] = $coordinateError;
        }

        if ($normalized['phone_e164'] === null) {
            $warnings[] = 'Telefon eksik; otomatik eşleşme zayıf olur.';
        } elseif (isset($seenPhones[$normalized['phone_e164']])) {
            $warnings[] = 'Aynı telefon dosyada birden fazla satırda var.';
            $duplicates[] = 'phone';
        }

        if ($normalized['mikro_cari_kodu'] !== null) {
            if (isset($seenCariCodes[$normalized['mikro_cari_kodu']])) {
                $warnings[] = 'Aynı Mikro cari kodu dosyada birden fazla satırda var; partner bağlantısı için normal olabilir.';
                $duplicates[] = 'mikro_cari_kodu';
            }
            $seenCariCodes[$normalized['mikro_cari_kodu']] = $rowNumber;
        }

        if ($normalized['city'] === null) {
            $warnings[] = 'Şehir eksik; rota ve geocode için manuel kontrol gerekir.';
        }

        if ($normalized['address'] === null && $normalized['google_plus_code'] === null && $normalized['google_formatted_address'] === null) {
            $warnings[] = 'Adres veya Plus Code eksik; geocode uyarısı oluşur.';
        }

        if ($normalized['phone_e164'] !== null) {
            $seenPhones[$normalized['phone_e164']] = $rowNumber;
        }

        $match = $errors === [] ? $this->findExistingMatch($normalized) : null;
        if ($match !== null && $match['confidence'] === 'weak') {
            $warnings[] = 'İsim + şehir benzerliği zayıf eşleşme; otomatik update yapılmayacak.';
        }

        $partnerMatch = $this->partnerMatch($normalized);
        $linkPlan = $this->linkPlan($partnerMatch, $match, $normalized, (bool) $options['link_existing_partners']);
        $geocodePlan = $this->geocodePlan($normalized, $match, (bool) $options['override_existing_coordinates'], (string) $options['geocode_mode']);
        $changedFields = $match !== null && $match['technician'] instanceof TechnicalServiceTechnician
            ? $this->changedFields($normalized, $match['technician'])
            : [];
        $action = $this->actionFor($errors, $match, $changedFields);

        if ($match !== null && $match['confidence'] === 'weak') {
            $action = 'create';
        }

        return [
            'row_number' => $rowNumber,
            'action' => $action,
            'confidence' => $match['confidence'] ?? 'new',
            'normalized' => $normalized,
            'existing_match' => $match === null ? null : [
                'id' => $match['technician']->id,
                'name' => $match['technician']->name,
                'phone_e164' => $match['technician']->phone_e164,
                'city' => $match['technician']->city,
                'match_type' => $match['type'],
                'confidence' => $match['confidence'],
            ],
            'partner_match' => $partnerMatch,
            'link_plan' => $linkPlan,
            'geocode_plan' => $geocodePlan,
            'warnings' => array_values(array_unique($warnings)),
            'errors' => $errors,
            'duplicates' => array_values(array_unique($duplicates)),
            'changed_fields' => $changedFields,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizedRow(array $data): array
    {
        $firstName = $this->blankToNull($data['first_name'] ?? null);
        $lastName = $this->blankToNull($data['last_name'] ?? null);
        $fullName = $this->blankToNull($data['full_name'] ?? null) ?? $this->blankToNull(trim(($firstName ?? '').' '.($lastName ?? '')));

        if ($fullName !== null && $firstName === null) {
            [$firstName, $lastNameFromFullName] = $this->splitName($fullName);
            $lastName = $lastName ?? $lastNameFromFullName;
        }

        $phone = $this->blankToNull($data['phone'] ?? null);
        $phoneE164 = $this->normalizePhone($phone);
        $city = $this->blankToNull($data['city'] ?? null);
        $address = $this->blankToNull($data['address'] ?? null)
            ?? $this->blankToNull($data['default_start_address'] ?? null);
        $plusCode = $this->blankToNull($data['google_plus_code'] ?? null)
            ?? $this->blankToNull($data['default_start_plus_code'] ?? null);

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'phone' => $phone,
            'phone_e164' => $phoneE164,
            'phone_display' => $this->blankToNull($data['phone_display'] ?? null) ?? $phoneE164 ?? $phone,
            'city_plate_code' => $this->blankToNull($data['city_plate_code'] ?? null),
            'city' => $city,
            'district' => $this->blankToNull($data['district'] ?? null),
            'address' => $address,
            'google_plus_code' => $plusCode,
            'google_formatted_address' => $this->blankToNull($data['google_formatted_address'] ?? null),
            'default_start_address' => $this->blankToNull($data['default_start_address'] ?? null),
            'default_start_plus_code' => $this->blankToNull($data['default_start_plus_code'] ?? null),
            'start_location_contract' => 'primary_location',
            'latitude' => $this->blankToNull($data['latitude'] ?? null),
            'longitude' => $this->blankToNull($data['longitude'] ?? null),
            'start_latitude' => $this->blankToNull($data['start_latitude'] ?? null),
            'start_longitude' => $this->blankToNull($data['start_longitude'] ?? null),
            'mikro_cari_kodu' => $this->blankToNull($data['mikro_cari_kodu'] ?? null),
            'mikro_cari_adi' => $this->blankToNull($data['mikro_cari_adi'] ?? null),
            'note' => $this->blankToNull($data['note'] ?? null),
            'active' => $this->parseActive($data['active'] ?? null),
            'source_key' => $phoneE164 !== null && $city !== null
                ? 'locksmith:'.$phoneE164.':'.$this->normalizeKey($city)
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{technician:TechnicalServiceTechnician,type:string,confidence:string}|null
     */
    private function findExistingMatch(array $row): ?array
    {
        if ($row['phone_e164'] !== null) {
            $technician = TechnicalServiceTechnician::withTrashed()
                ->where(function ($query) use ($row): void {
                    $query->where('phone_e164', $row['phone_e164'])
                        ->orWhere('phone', $row['phone_e164']);
                })
                ->first();

            if ($technician instanceof TechnicalServiceTechnician) {
                return ['technician' => $technician, 'type' => 'phone_e164', 'confidence' => 'reliable'];
            }
        }

        if ($row['mikro_cari_kodu'] !== null && $row['full_name'] !== null) {
            $needle = $this->normalizeKey($row['full_name']);
            $technician = TechnicalServiceTechnician::withTrashed()
                ->where(function ($query) use ($row): void {
                    $query->where('mikro_cari_kodu', $row['mikro_cari_kodu'])
                        ->orWhere('cari_code', $row['mikro_cari_kodu']);
                })
                ->get()
                ->first(fn (TechnicalServiceTechnician $candidate): bool => $this->normalizeKey($candidate->name) === $needle);

            if ($technician instanceof TechnicalServiceTechnician) {
                return ['technician' => $technician, 'type' => 'mikro_cari_kodu_name', 'confidence' => 'reliable'];
            }
        }

        if ($row['source_key'] !== null) {
            $technician = TechnicalServiceTechnician::withTrashed()
                ->where('source_key', $row['source_key'])
                ->first();

            if ($technician instanceof TechnicalServiceTechnician) {
                return ['technician' => $technician, 'type' => 'source_key', 'confidence' => 'reliable'];
            }
        }

        if ($row['full_name'] !== null && $row['city'] !== null) {
            $needle = $this->normalizeKey($row['full_name']);
            $city = $this->normalizeKey($row['city']);
            $technician = TechnicalServiceTechnician::withTrashed()
                ->get()
                ->first(fn (TechnicalServiceTechnician $candidate): bool => $this->normalizeKey($candidate->name) === $needle
                    && $this->normalizeKey($candidate->city) === $city);

            if ($technician instanceof TechnicalServiceTechnician) {
                return ['technician' => $technician, 'type' => 'name_city', 'confidence' => 'weak'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function partnerMatch(array $row): ?array
    {
        if ($row['mikro_cari_kodu'] === null) {
            return null;
        }

        $partner = B2BPartner::query()
            ->where('mikro_cari_kodu', $row['mikro_cari_kodu'])
            ->orWhere('partner_code', $row['mikro_cari_kodu'])
            ->first();

        if (! $partner instanceof B2BPartner) {
            return null;
        }

        return [
            'id' => $partner->id,
            'name' => $partner->display_name,
            'display_name' => $partner->display_name,
            'mikro_cari_kodu' => $partner->mikro_cari_kodu,
            'city' => $partner->city,
            'status' => 'matched',
        ];
    }

    /**
     * @param array<string, mixed>|null $partner
     * @param array{technician:TechnicalServiceTechnician,type:string,confidence:string}|null $match
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function linkPlan(?array $partner, ?array $match, array $row, bool $linkExistingPartners): array
    {
        if (! $linkExistingPartners) {
            return ['action' => 'skipped', 'reason' => 'Partner eşleşmesi kapalı.'];
        }

        if ($partner === null) {
            return ['action' => 'partner_missing', 'reason' => 'Partner bulunamadı; Faz 2B’de seçime bağlı oluşturulabilir.'];
        }

        if ($match !== null && $match['confidence'] === 'reliable') {
            $exists = B2BPartnerTechnician::query()
                ->where('partner_id', $partner['id'])
                ->where('technical_service_technician_id', $match['technician']->id)
                ->where('active', true)
                ->exists();

            return [
                'action' => $exists ? 'skip' : 'create',
                'partner_id' => $partner['id'],
                'technician_id' => $match['technician']->id,
                'reason' => $exists ? 'Partner-teknisyen bağı zaten var.' : 'Mevcut partner ile teknisyen bağı kurulabilir.',
            ];
        }

        return [
            'action' => 'create_after_technician',
            'partner_id' => $partner['id'],
            'technician_id' => null,
            'reason' => 'Teknisyen oluşturulursa mevcut partnerle bağ kurulabilir.',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{technician:TechnicalServiceTechnician,type:string,confidence:string}|null $match
     * @return array<string, mixed>
     */
    private function geocodePlan(array $row, ?array $match, bool $overrideExistingCoordinates, string $geocodeMode): array
    {
        if ($geocodeMode === 'none') {
            return ['status' => 'skipped', 'reason' => 'Geocode planı kapalı.'];
        }

        $coordinateError = $this->coordinateError($row);
        if ($coordinateError !== null) {
            return ['status' => 'invalid_coordinate', 'reason' => $coordinateError];
        }

        $rowCoordinates = $this->validCoordinatePair($row['latitude'], $row['longitude'])
            ?? $this->validCoordinatePair($row['start_latitude'], $row['start_longitude']);
        if ($rowCoordinates !== null) {
            return ['status' => 'coordinates_present', 'reason' => 'Dosyada koordinat var.', 'coordinates' => $rowCoordinates];
        }

        $existing = $match['technician'] ?? null;
        if ($existing instanceof TechnicalServiceTechnician && ! $overrideExistingCoordinates && $this->technicianHasCoordinates($existing)) {
            return ['status' => 'preserve_existing', 'reason' => 'Mevcut koordinat korunacak.'];
        }

        $plusCode = $row['google_plus_code'] ?? null;
        if ($plusCode !== null) {
            return ['status' => 'ready_plus_code', 'reason' => 'Plus Code ile geocode planı hazır.', 'query' => $plusCode];
        }

        $address = $row['google_formatted_address'] ?? $row['address'] ?? null;
        if ($address !== null && $row['city'] !== null) {
            return [
                'status' => 'ready_address',
                'reason' => 'Adres ve şehir ile geocode planı hazır.',
                'query' => $this->geocodingService->joinParts([$address, $row['district'], $row['city'], 'Türkiye']),
            ];
        }

        if ($row['city'] !== null || $row['district'] !== null) {
            return ['status' => 'warning_city_only', 'reason' => 'Sadece şehir/ilçe var; rota için yeterli değil.'];
        }

        return ['status' => 'warning_missing_address', 'reason' => 'Adres veya Plus Code yok.'];
    }

    /**
     * @param array<string, mixed> $row
     * @param TechnicalServiceTechnician $technician
     * @return array<int, string>
     */
    private function changedFields(array $row, TechnicalServiceTechnician $technician): array
    {
        $map = [
            'first_name' => $technician->first_name,
            'last_name' => $technician->last_name,
            'phone_e164' => $technician->phone_e164 ?: $technician->phone,
            'city' => $technician->city,
            'district' => $technician->district,
            'address' => $technician->address,
            'google_plus_code' => $technician->google_plus_code,
            'google_formatted_address' => $technician->google_formatted_address,
            'mikro_cari_kodu' => $technician->mikro_cari_kodu ?: $technician->cari_code,
            'mikro_cari_adi' => $technician->mikro_cari_adi ?: $technician->cari_title,
        ];
        $changed = [];

        foreach ($map as $field => $current) {
            if ($row[$field] !== null && $this->normalizeCompare($row[$field]) !== $this->normalizeCompare($current)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * @param array<int, string> $errors
     * @param array{technician:TechnicalServiceTechnician,type:string,confidence:string}|null $match
     * @param array<int, string> $changedFields
     */
    private function actionFor(array $errors, ?array $match, array $changedFields): string
    {
        if ($errors !== []) {
            return 'error';
        }

        if ($match === null) {
            return 'create';
        }

        if ($match['confidence'] === 'weak') {
            return 'create';
        }

        return $changedFields === [] ? 'skip' : 'update';
    }

    /**
     * @param array<string, int> $summary
     * @param array<string, mixed> $row
     */
    private function countRow(array &$summary, array $row): void
    {
        match ($row['action']) {
            'create' => $summary['create_count']++,
            'update' => $summary['update_count']++,
            'skip' => $summary['skip_count']++,
            'error' => $summary['error_count']++,
            default => null,
        };

        if ($row['duplicates'] !== []) {
            $summary['duplicate_count']++;
        }

        match ($row['link_plan']['action'] ?? '') {
            'create', 'create_after_technician' => $summary['partner_link_create_count']++,
            'update' => $summary['partner_link_update_count']++,
            'skip', 'skipped' => $summary['partner_link_skip_count']++,
            'partner_missing' => $summary['partner_missing_count']++,
            default => null,
        };

        match ($row['geocode_plan']['status'] ?? '') {
            'coordinates_present' => $summary['geocode_existing_coordinates_count']++,
            'preserve_existing' => $summary['geocode_preserve_existing_count']++,
            'ready_plus_code', 'ready_address' => $summary['geocode_ready_count']++,
            'warning_city_only', 'warning_missing_address' => $summary['geocode_warning_count']++,
            'invalid_coordinate' => $summary['geocode_error_count']++,
            default => null,
        };
    }

    /**
     * @return array<string, int>
     */
    private function emptySummary(int $totalRows): array
    {
        return [
            'total_rows' => $totalRows,
            'parsed_rows' => 0,
            'valid_rows' => 0,
            'create_count' => 0,
            'update_count' => 0,
            'skip_count' => 0,
            'error_count' => 0,
            'warning_count' => 0,
            'duplicate_count' => 0,
            'partner_link_create_count' => 0,
            'partner_link_update_count' => 0,
            'partner_link_skip_count' => 0,
            'partner_missing_count' => 0,
            'geocode_ready_count' => 0,
            'geocode_warning_count' => 0,
            'geocode_existing_coordinates_count' => 0,
            'geocode_preserve_existing_count' => 0,
            'geocode_error_count' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function coordinateError(array $row): ?string
    {
        foreach ([['latitude', 'longitude'], ['start_latitude', 'start_longitude']] as [$latitudeKey, $longitudeKey]) {
            $latitude = $row[$latitudeKey] ?? null;
            $longitude = $row[$longitudeKey] ?? null;

            if ($latitude === null && $longitude === null) {
                continue;
            }

            if ($latitude === null || $longitude === null) {
                return 'Latitude ve longitude birlikte dolu olmalı.';
            }

            if ($this->validCoordinatePair($latitude, $longitude) === null) {
                return 'Koordinat geçersiz. Latitude -90..90, longitude -180..180 aralığında ve 0/0 olmamalı.';
            }
        }

        return null;
    }

    /**
     * @return array{latitude:float,longitude:float}|null
     */
    private function validCoordinatePair(mixed $latitude, mixed $longitude): ?array
    {
        return $this->geocodingService->validCoordinatePair($latitude, $longitude);
    }

    private function technicianHasCoordinates(TechnicalServiceTechnician $technician): bool
    {
        return $this->validCoordinatePair($technician->latitude, $technician->longitude) !== null
            || $this->validCoordinatePair($technician->start_latitude, $technician->start_longitude) !== null;
    }

    private function parseActive(mixed $value): ?bool
    {
        $text = $this->normalizeKey($value);
        if ($text === '') {
            return true;
        }

        return match ($text) {
            '1', 'TRUE', 'AKTIF', 'EVET', 'YES' => true,
            '0', 'FALSE', 'PASIF', 'HAYIR', 'NO' => false,
            default => null,
        };
    }

    private function normalizePhone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = '90'.substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            $digits = '90'.$digits;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '90')) {
            return '+'.$digits;
        }

        return null;
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

    /**
     * @param array<int, string> $lines
     */
    private function detectCsvDelimiter(array $lines): string
    {
        $sample = implode("\n", array_slice($lines, 0, 5));
        $scores = [
            ',' => substr_count($sample, ','),
            ';' => substr_count($sample, ';'),
            "\t" => substr_count($sample, "\t"),
        ];
        arsort($scores);

        return (string) array_key_first($scores);
    }

    private function normalizeHeader(mixed $value): string
    {
        return Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    private function normalizeKey(mixed $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    private function normalizeCompare(mixed $value): string
    {
        return Str::of((string) $value)
            ->trim()
            ->lower()
            ->squish()
            ->value();
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $firstName = array_shift($parts) ?: trim($name);
        $lastName = trim(implode(' ', $parts));

        return [$firstName, $lastName === '' ? null : $lastName];
    }

    private function blankToNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
