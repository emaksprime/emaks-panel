<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceTechnician;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class LocksmithImportService
{
    public const SHEET_NAME = 'Tam Liste';
    public const TYPE_LOCKSMITH = 'locksmith';
    public const IMPORT_SOURCE = 'clg_servis_konsolide_liste.xlsx';

    private const SKIP_STATUS = 'SERVIS BILGISI YOK';

    public function __construct(
        private readonly LocksmithXlsxReader $reader = new LocksmithXlsxReader(),
    ) {
    }

    /**
     * @return array{imported:int,updated:int,skipped:int,needs_review:int,errors:array<int,array<string,mixed>>,dry_run:bool}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        $plan = $this->payloadsFromExcel($path);

        return $dryRun
            ? $this->dryRunPayloads($plan)
            : DB::transaction(fn (): array => $this->upsertPayloads($plan));
    }

    /**
     * @return array{exported:int,skipped:int,needs_review:int,path:string,errors:array<int,array<string,mixed>>}
     */
    public function exportSeedData(string $sourcePath, string $outputPath): array
    {
        $plan = $this->payloadsFromExcel($sourcePath);
        $payloads = $this->uniquePayloads($plan['payloads']);
        $records = array_values(array_map(fn (array $payload): array => $this->seedRecord($payload), $payloads));

        File::ensureDirectoryExists(dirname($outputPath));
        file_put_contents($outputPath, json_encode([
            'source' => self::IMPORT_SOURCE,
            'items' => $records,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'exported' => count($records),
            'skipped' => $plan['summary']['skipped'],
            'needs_review' => count(array_filter($records, fn (array $record): bool => (bool) ($record['needs_review'] ?? false))),
            'path' => $outputPath,
            'errors' => $plan['summary']['errors'],
        ];
    }

    /**
     * @return array{imported:int,updated:int,skipped:int,needs_review:int,errors:array<int,array<string,mixed>>,dry_run:bool}
     */
    public function seedFromJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Seed veri dosyası bulunamadı: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Seed veri dosyası okunamadı: {$path}");
        }

        $records = $decoded['items'] ?? $decoded;
        if (! is_array($records)) {
            throw new RuntimeException("Seed veri dosyası beklenen formatta değil: {$path}");
        }

        $payloads = [];
        foreach ($records as $record) {
            if (is_array($record)) {
                $payloads[] = $this->payloadFromSeedRecord($record);
            }
        }

        $plan = [
            'payloads' => $payloads,
            'summary' => [
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'needs_review' => count(array_filter($payloads, fn (array $payload): bool => (bool) ($payload['needs_review'] ?? false))),
                'errors' => [],
                'dry_run' => false,
            ],
        ];

        return DB::transaction(fn (): array => $this->upsertPayloads($plan));
    }

    /**
     * @return array{payloads:array<int,array<string,mixed>>,summary:array{imported:int,updated:int,skipped:int,needs_review:int,errors:array<int,array<string,mixed>>,dry_run:bool}}
     */
    private function payloadsFromExcel(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Excel dosyası bulunamadı: {$path}");
        }

        $rows = $this->reader->rows($path, self::SHEET_NAME);
        $summary = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'needs_review' => 0,
            'errors' => [],
            'dry_run' => false,
        ];
        $seenPhoneCities = $this->existingPhoneCities();
        $payloads = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $mapped = $this->mapRow($row);

            if ($this->normalizeKey($mapped['import_status']) === self::SKIP_STATUS) {
                $summary['skipped']++;
                $summary['errors'][] = ['row' => $rowNumber, 'reason' => 'Servis bilgisi yok.', 'data' => $mapped];
                continue;
            }

            if ($mapped['name'] === null || $mapped['phone_e164'] === null) {
                $summary['skipped']++;
                $summary['errors'][] = ['row' => $rowNumber, 'reason' => 'İsim veya telefon boş/geçersiz.', 'data' => $mapped];
                continue;
            }

            $phoneKey = $mapped['phone_e164'];
            $cityKey = $this->normalizeKey($mapped['city']);
            $phoneCities = $seenPhoneCities[$phoneKey] ?? [];
            $differentCity = $phoneCities !== [] && $cityKey !== '' && ! in_array($cityKey, $phoneCities, true);
            $needsReview = $this->needsReview($mapped) || $differentCity;

            if ($needsReview) {
                $summary['needs_review']++;
            }

            $payloads[] = $this->payload($mapped, $needsReview, $differentCity);

            if ($phoneKey !== '' && $cityKey !== '' && ! in_array($cityKey, $seenPhoneCities[$phoneKey] ?? [], true)) {
                $seenPhoneCities[$phoneKey][] = $cityKey;
            }
        }

        return ['payloads' => $payloads, 'summary' => $summary];
    }

    /**
     * @param array{payloads:array<int,array<string,mixed>>,summary:array<string,mixed>} $plan
     * @return array{imported:int,updated:int,skipped:int,needs_review:int,errors:array<int,array<string,mixed>>,dry_run:bool}
     */
    private function dryRunPayloads(array $plan): array
    {
        $summary = $plan['summary'];
        $summary['dry_run'] = true;
        $seenSourceKeys = [];

        foreach ($plan['payloads'] as $payload) {
            $sourceKey = (string) ($payload['source_key'] ?? '');

            if ($sourceKey !== '' && isset($seenSourceKeys[$sourceKey])) {
                $summary['updated']++;
                continue;
            }

            $this->findTechnicianForPayload($payload) instanceof TechnicalServiceTechnician
                ? $summary['updated']++
                : $summary['imported']++;

            if ($sourceKey !== '') {
                $seenSourceKeys[$sourceKey] = true;
            }
        }

        return $summary;
    }

    /**
     * @param array{payloads:array<int,array<string,mixed>>,summary:array<string,mixed>} $plan
     * @return array{imported:int,updated:int,skipped:int,needs_review:int,errors:array<int,array<string,mixed>>,dry_run:bool}
     */
    private function upsertPayloads(array $plan): array
    {
        $summary = $plan['summary'];
        $summary['dry_run'] = false;
        $summary['imported'] = 0;
        $summary['updated'] = 0;

        foreach ($plan['payloads'] as $payload) {
            $technician = $this->findTechnicianForPayload($payload);

            if ($technician instanceof TechnicalServiceTechnician) {
                if (method_exists($technician, 'trashed') && $technician->trashed()) {
                    $technician->restore();
                }

                $technician->forceFill($payload)->save();
                $summary['updated']++;
                continue;
            }

            TechnicalServiceTechnician::query()->create($payload);
            $summary['imported']++;
        }

        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $payloads
     * @return array<int,array<string,mixed>>
     */
    private function uniquePayloads(array $payloads): array
    {
        $items = [];

        foreach ($payloads as $payload) {
            $key = (string) ($payload['source_key'] ?: $payload['cari_code'] ?: $payload['city'].'|'.$payload['name']);
            $items[$key] = $payload;
        }

        return array_values($items);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function existingPhoneCities(): array
    {
        $items = [];

        TechnicalServiceTechnician::withTrashed()
            ->where(function ($query): void {
                $query->whereNotNull('phone_e164')
                    ->orWhereNotNull('phone');
            })
            ->get(['phone', 'phone_e164', 'city'])
            ->each(function (TechnicalServiceTechnician $technician) use (&$items): void {
                $phone = trim((string) ($technician->phone_e164 ?: $technician->phone));
                $city = $this->normalizeKey($technician->city);

                if ($phone !== '' && $city !== '') {
                    $items[$phone][] = $city;
                    $items[$phone] = array_values(array_unique($items[$phone]));
                }
            });

        return $items;
    }

    /**
     * @param array<string, string|null> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $phoneE164 = $this->normalizePhone($row['Telefon (90 format)'] ?? null);

        return [
            'city_plate_code' => $this->nullableText($row['Plaka Kodu'] ?? null),
            'city' => $this->nullableText($row['Şehir'] ?? null),
            'priority' => $this->nullableInt($row['Öncelik'] ?? null),
            'name' => $this->nullableText($row['İsim Soyisim'] ?? null),
            'phone_e164' => $phoneE164,
            'phone_display' => $this->nullableText($row['Telefon (okunur)'] ?? null) ?? $phoneE164,
            'location_code' => $this->nullableText($row['Konum / Adres Kodu'] ?? null),
            'cari_code' => $this->nullableText($row['Cari Kodu'] ?? null),
            'cari_title' => $this->nullableText($row['Cari Ünvan'] ?? null),
            'cari_address' => $this->nullableText($row['Cari Adres'] ?? null),
            'cari_city_district_country' => $this->nullableText($row['Cari İlçe İl Ülke'] ?? null),
            'display_name' => $this->nullableText($row['Cari ADI'] ?? null),
            'import_status' => $this->nullableText($row['Durum'] ?? null),
            'import_note' => $this->nullableText($row['Kontrol Notu'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    private function payload(array $mapped, bool $needsReview, bool $differentCity): array
    {
        [$firstName, $lastName] = $this->splitName((string) $mapped['name']);
        $noteParts = array_values(array_filter([
            $mapped['import_note'],
            $differentCity ? 'Aynı telefon farklı şehirde geçti; kontrol gerekli.' : null,
        ], fn ($value): bool => $this->nullableText($value) !== null));

        return [
            'name' => $mapped['name'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'technician_type' => self::TYPE_LOCKSMITH,
            'city_plate_code' => $mapped['city_plate_code'],
            'priority' => $mapped['priority'],
            'phone' => $mapped['phone_e164'],
            'phone_e164' => $mapped['phone_e164'],
            'phone_display' => $mapped['phone_display'],
            'city' => $mapped['city'],
            'address' => $mapped['cari_address'],
            'location_code' => $mapped['location_code'],
            'google_plus_code' => $mapped['location_code'],
            'default_start_plus_code' => $mapped['location_code'],
            'default_start_address' => $mapped['cari_address'],
            'active' => true,
            'note' => $noteParts === [] ? null : implode("\n", $noteParts),
            'mikro_cari_kodu' => $mapped['cari_code'],
            'mikro_cari_adi' => $mapped['cari_title'],
            'cari_code' => $mapped['cari_code'],
            'cari_title' => $mapped['cari_title'],
            'cari_address' => $mapped['cari_address'],
            'cari_city_district_country' => $mapped['cari_city_district_country'],
            'display_name' => $mapped['display_name'],
            'import_status' => $mapped['import_status'],
            'import_note' => $mapped['import_note'],
            'needs_review' => $needsReview,
            'import_source' => self::IMPORT_SOURCE,
            'imported_at' => now(),
            'source_key' => $this->sourceKey($mapped['phone_e164'], $mapped['city']),
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function payloadFromSeedRecord(array $record): array
    {
        [$firstName, $lastName] = $this->splitName((string) ($record['name'] ?? ''));

        return [
            'name' => $record['name'] ?? '',
            'first_name' => $record['first_name'] ?? $firstName,
            'last_name' => $record['last_name'] ?? $lastName,
            'technician_type' => self::TYPE_LOCKSMITH,
            'city_plate_code' => $record['city_plate_code'] ?? null,
            'priority' => $record['priority'] ?? null,
            'phone' => $record['phone_e164'] ?? $record['phone'] ?? null,
            'phone_e164' => $record['phone_e164'] ?? $record['phone'] ?? null,
            'phone_display' => $record['phone_display'] ?? $record['phone_e164'] ?? $record['phone'] ?? null,
            'city' => $record['city'] ?? null,
            'address' => $record['cari_address'] ?? $record['address'] ?? null,
            'location_code' => $record['location_code'] ?? null,
            'google_plus_code' => $record['location_code'] ?? null,
            'default_start_plus_code' => $record['location_code'] ?? null,
            'default_start_address' => $record['cari_address'] ?? $record['address'] ?? null,
            'active' => (bool) ($record['active'] ?? true),
            'note' => $record['note'] ?? $record['import_note'] ?? null,
            'mikro_cari_kodu' => $record['cari_code'] ?? null,
            'mikro_cari_adi' => $record['cari_title'] ?? null,
            'cari_code' => $record['cari_code'] ?? null,
            'cari_title' => $record['cari_title'] ?? null,
            'cari_address' => $record['cari_address'] ?? null,
            'cari_city_district_country' => $record['cari_city_district_country'] ?? null,
            'display_name' => $record['display_name'] ?? null,
            'import_status' => $record['import_status'] ?? null,
            'import_note' => $record['import_note'] ?? null,
            'needs_review' => (bool) ($record['needs_review'] ?? false),
            'import_source' => self::IMPORT_SOURCE,
            'imported_at' => now(),
            'source_key' => $record['source_key'] ?? $this->sourceKey($record['phone_e164'] ?? $record['phone'] ?? null, $record['city'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function seedRecord(array $payload): array
    {
        return [
            'source_key' => $payload['source_key'],
            'name' => $payload['name'],
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'technician_type' => self::TYPE_LOCKSMITH,
            'city_plate_code' => $payload['city_plate_code'],
            'city' => $payload['city'],
            'priority' => $payload['priority'],
            'phone_e164' => $payload['phone_e164'],
            'phone_display' => $payload['phone_display'],
            'location_code' => $payload['location_code'],
            'cari_code' => $payload['cari_code'],
            'cari_title' => $payload['cari_title'],
            'cari_address' => $payload['cari_address'],
            'cari_city_district_country' => $payload['cari_city_district_country'],
            'display_name' => $payload['display_name'],
            'import_status' => $payload['import_status'],
            'import_note' => $payload['import_note'],
            'needs_review' => $payload['needs_review'],
            'active' => $payload['active'],
            'note' => $payload['note'],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function findTechnicianForPayload(array $payload): ?TechnicalServiceTechnician
    {
        $phone = $this->nullableText($payload['phone_e164'] ?? $payload['phone'] ?? null);
        $city = $this->nullableText($payload['city'] ?? null);

        if ($phone !== null && $city !== null) {
            $technician = TechnicalServiceTechnician::withTrashed()
                ->where(function ($query) use ($phone): void {
                    $query->where('phone_e164', $phone)
                        ->orWhere('phone', $phone);
                })
                ->where('city', $city)
                ->first();

            if ($technician instanceof TechnicalServiceTechnician) {
                return $technician;
            }
        }

        if (($payload['cari_code'] ?? null) !== null) {
            $technician = TechnicalServiceTechnician::withTrashed()
                ->where(function ($query) use ($payload): void {
                    $query->where('cari_code', $payload['cari_code'])
                        ->orWhere('mikro_cari_kodu', $payload['cari_code']);
                })
                ->first();

            if ($technician instanceof TechnicalServiceTechnician) {
                return $technician;
            }
        }

        if ($city !== null && ($payload['name'] ?? null) !== null) {
            $needle = $this->normalizeKey($payload['name']);

            return TechnicalServiceTechnician::withTrashed()
                ->where('city', $city)
                ->get()
                ->first(fn (TechnicalServiceTechnician $technician): bool => $this->normalizeKey($technician->name) === $needle);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $mapped
     */
    private function needsReview(array $mapped): bool
    {
        $status = $this->normalizeKey($mapped['import_status']);
        $note = $this->normalizeKey($mapped['import_note']);

        return str_contains($status, 'KONTROL')
            || str_contains($note, 'KONTROL');
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

    private function normalizeKey(mixed $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    private function sourceKey(mixed $phone, mixed $city): ?string
    {
        $phone = $this->nullableText($phone);
        $city = $this->normalizeKey($city);

        return $phone !== null && $city !== ''
            ? self::TYPE_LOCKSMITH.":{$phone}:{$city}"
            : null;
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

    private function nullableInt(mixed $value): ?int
    {
        $text = $this->nullableText($value);

        return $text === null || ! is_numeric($text) ? null : (int) $text;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
