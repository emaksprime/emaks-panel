<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceTechnician;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class LocksmithImportService
{
    public const SHEET_NAME = 'Tam Liste';

    public const TYPE_LOCKSMITH = 'locksmith';

    public const IMPORT_SOURCE = 'private_locksmith_master';

    public const WRITE_TABLES = [
        'technical_service_technicians',
    ];

    public const WRITE_COLUMNS = [
        'name',
        'first_name',
        'last_name',
        'technician_type',
        'city_plate_code',
        'priority',
        'phone',
        'phone_e164',
        'phone_display',
        'city',
        'address',
        'location_code',
        'google_plus_code',
        'default_start_plus_code',
        'default_start_address',
        'active',
        'note',
        'mikro_cari_kodu',
        'mikro_cari_adi',
        'cari_code',
        'cari_title',
        'cari_address',
        'cari_city_district_country',
        'display_name',
        'import_status',
        'import_note',
        'needs_review',
        'import_source',
        'imported_at',
        'source_key',
    ];

    private const SKIP_STATUS = 'SERVIS BILGISI YOK';

    public function __construct(
        private readonly LocksmithXlsxReader $reader = new LocksmithXlsxReader,
        private readonly TechnicalServicePrivateDatasetPathPolicy $pathPolicy = new TechnicalServicePrivateDatasetPathPolicy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(string $path, bool $apply = false): array
    {
        $sourcePath = $this->pathPolicy->source($path);
        $plan = $this->payloadsFromExcel($sourcePath);
        $analysis = $this->analyze($plan, false);

        if (! $apply) {
            return $this->publicSummary($analysis, true);
        }

        $this->assertApplicable($analysis);

        return DB::transaction(function () use ($plan): array {
            $this->lockImportTableForPostgres();
            $locked = $this->analyze($plan, true);
            $this->assertApplicable($locked);

            foreach ($locked['operations'] as $operation) {
                $payload = $operation['payload'];

                if ($operation['type'] === 'insert') {
                    $payload['imported_at'] = now();
                    TechnicalServiceTechnician::query()->create($payload);

                    continue;
                }

                if ($operation['type'] === 'update') {
                    /** @var TechnicalServiceTechnician $technician */
                    $technician = $operation['technician'];
                    $technician->forceFill($operation['changes'])->save();
                }
            }

            return $this->publicSummary($locked, false);
        }, 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function exportSeedData(string $sourcePath, string $outputPath): array
    {
        $source = $this->pathPolicy->source($sourcePath);
        $output = $this->pathPolicy->output($outputPath);
        $this->pathPolicy->assertDifferent($source, $output);

        $plan = $this->payloadsFromExcel($source);
        $analysis = $this->analyze($plan, false);
        $this->assertApplicable($analysis);

        $records = array_map(
            fn (array $payload): array => $this->seedRecord($payload),
            $plan['payloads'],
        );

        try {
            $contents = json_encode([
                'synthetic' => false,
                'schema_version' => 1,
                'source' => self::IMPORT_SOURCE,
                'items' => array_values($records),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Private locksmith export JSON olusturulamadi.', previous: $exception);
        }

        $this->pathPolicy->writeAtomically($output, $contents.PHP_EOL);

        return [
            'total' => $analysis['total'],
            'valid' => $analysis['valid'],
            'exported' => count($records),
            'skip' => $analysis['skip'],
            'conflict' => $analysis['conflict'],
            'invalid' => $analysis['invalid'],
            'delete' => 0,
        ];
    }

    /**
     * Explicit non-production seeder entrypoint. Production seed is forbidden.
     *
     * @return array<string, mixed>
     */
    public function seedFromJson(string $path): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Production ortaminda locksmith seeder yasaktir.');
        }

        $sourcePath = $this->pathPolicy->source($path);
        $plan = $this->payloadsFromJson($sourcePath);
        $analysis = $this->analyze($plan, false);
        $this->assertApplicable($analysis);

        return DB::transaction(function () use ($plan): array {
            $this->lockImportTableForPostgres();
            $locked = $this->analyze($plan, true);
            $this->assertApplicable($locked);

            foreach ($locked['operations'] as $operation) {
                if ($operation['type'] === 'insert') {
                    $payload = $operation['payload'];
                    $payload['imported_at'] = now();
                    TechnicalServiceTechnician::query()->create($payload);
                } elseif ($operation['type'] === 'update') {
                    $operation['technician']->forceFill($operation['changes'])->save();
                }
            }

            return $this->publicSummary($locked, false);
        }, 1);
    }

    /**
     * @return array{payloads:array<int,array<string,mixed>>,total:int,valid:int,skip:int,conflict:int,invalid:int,needs_review:int,errors:array<int,array{row:int,reason:string}>}
     */
    private function payloadsFromExcel(string $path): array
    {
        $rows = $this->reader->rows($path, self::SHEET_NAME);
        $plan = $this->emptyPlan(count($rows));
        $seenSourceKeys = [];
        $seenPhoneCities = $this->existingPhoneCities();

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $mapped = $this->mapRow($row);

            if ($this->normalizeKey($mapped['import_status']) === self::SKIP_STATUS) {
                $plan['skip']++;

                continue;
            }

            if ($mapped['name'] === null || $mapped['phone_e164'] === null || $mapped['city'] === null) {
                $plan['invalid']++;
                $plan['errors'][] = ['row' => $rowNumber, 'reason' => 'missing_required_identity'];

                continue;
            }

            $phoneKey = $mapped['phone_e164'];
            $cityKey = $this->normalizeKey($mapped['city']);
            $phoneCities = $seenPhoneCities[$phoneKey] ?? [];
            $differentCity = $phoneCities !== [] && ! in_array($cityKey, $phoneCities, true);
            $needsReview = $this->needsReview($mapped) || $differentCity;
            $payload = $this->payload($mapped, $needsReview, $differentCity);
            $sourceKey = (string) $payload['source_key'];

            if (isset($seenSourceKeys[$sourceKey])) {
                $plan['conflict']++;
                $plan['errors'][] = ['row' => $rowNumber, 'reason' => 'duplicate_source_identity'];

                continue;
            }

            $seenSourceKeys[$sourceKey] = true;
            $plan['payloads'][] = $payload;
            $plan['valid']++;
            $plan['needs_review'] += $needsReview ? 1 : 0;
            $seenPhoneCities[$phoneKey][] = $cityKey;
            $seenPhoneCities[$phoneKey] = array_values(array_unique($seenPhoneCities[$phoneKey]));
        }

        return $plan;
    }

    /**
     * @return array{payloads:array<int,array<string,mixed>>,total:int,valid:int,skip:int,conflict:int,invalid:int,needs_review:int,errors:array<int,array{row:int,reason:string}>}
     */
    private function payloadsFromJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        $records = is_array($decoded) ? ($decoded['items'] ?? null) : null;

        if (! is_array($records)) {
            throw new RuntimeException('Private locksmith JSON semasi gecersiz.');
        }

        $plan = $this->emptyPlan(count($records));
        $seenSourceKeys = [];

        foreach ($records as $index => $record) {
            if (! is_array($record)) {
                $plan['invalid']++;
                $plan['errors'][] = ['row' => $index + 1, 'reason' => 'invalid_record'];

                continue;
            }

            $payload = $this->payloadFromSeedRecord($record);
            $sourceKey = (string) ($payload['source_key'] ?? '');

            if ($payload['name'] === '' || $payload['phone_e164'] === null || $payload['city'] === null || $sourceKey === '') {
                $plan['invalid']++;
                $plan['errors'][] = ['row' => $index + 1, 'reason' => 'missing_required_identity'];

                continue;
            }

            if (isset($seenSourceKeys[$sourceKey])) {
                $plan['conflict']++;
                $plan['errors'][] = ['row' => $index + 1, 'reason' => 'duplicate_source_identity'];

                continue;
            }

            $seenSourceKeys[$sourceKey] = true;
            $plan['payloads'][] = $payload;
            $plan['valid']++;
            $plan['needs_review'] += (bool) $payload['needs_review'] ? 1 : 0;
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function analyze(array $plan, bool $lock): array
    {
        $analysis = array_merge($plan, [
            'insert' => 0,
            'update' => 0,
            'operations' => [],
        ]);

        foreach ($plan['payloads'] as $payload) {
            $identity = $this->resolveIdentity($payload, $lock);

            if ($identity['status'] === 'conflict') {
                $analysis['conflict']++;

                continue;
            }

            if ($identity['status'] === 'new') {
                $analysis['insert']++;
                $analysis['operations'][] = ['type' => 'insert', 'payload' => $payload];

                continue;
            }

            /** @var TechnicalServiceTechnician $technician */
            $technician = $identity['technician'];
            $changes = $this->changesForExisting($technician, $payload);

            if ($changes === []) {
                $analysis['skip']++;

                continue;
            }

            $changes['import_source'] = self::IMPORT_SOURCE;
            $changes['imported_at'] = now();
            $analysis['update']++;
            $analysis['operations'][] = [
                'type' => 'update',
                'technician' => $technician,
                'payload' => $payload,
                'changes' => $changes,
            ];
        }

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status:'new'|'matched'|'conflict',technician?:TechnicalServiceTechnician}
     */
    private function resolveIdentity(array $payload, bool $lock): array
    {
        $sourceKey = (string) ($payload['source_key'] ?? '');
        if ($sourceKey === '') {
            return ['status' => 'conflict'];
        }

        $sourceMatches = $this->identityQuery($lock)
            ->where('source_key', $sourceKey)
            ->get();

        if ($sourceMatches->count() > 1) {
            return ['status' => 'conflict'];
        }

        if ($sourceMatches->count() === 1) {
            $match = $sourceMatches->first();

            return $this->safeExistingMatch($match, $sourceKey);
        }

        $phone = (string) $payload['phone_e164'];
        $city = (string) $payload['city'];
        $legacyMatches = $this->identityQuery($lock)
            ->where(function (Builder $query) use ($phone): void {
                $query->where('phone_e164', $phone)->orWhere('phone', $phone);
            })
            ->where('city', $city)
            ->get();

        if ($legacyMatches->count() > 1) {
            return ['status' => 'conflict'];
        }

        if ($legacyMatches->count() === 1) {
            $match = $legacyMatches->first();
            $storedSourceKey = $this->nullableText($match->source_key);

            if ($storedSourceKey !== null && $storedSourceKey !== $sourceKey) {
                return ['status' => 'conflict'];
            }

            return $this->safeExistingMatch($match, $sourceKey);
        }

        $cariCode = $this->nullableText($payload['cari_code'] ?? null);
        if ($cariCode !== null) {
            $cariMatches = $this->identityQuery($lock)
                ->where(function (Builder $query) use ($cariCode): void {
                    $query->where('cari_code', $cariCode)->orWhere('mikro_cari_kodu', $cariCode);
                })
                ->limit(1)
                ->get(['id']);

            if ($cariMatches->isNotEmpty()) {
                return ['status' => 'conflict'];
            }
        }

        return ['status' => 'new'];
    }

    /**
     * @return Builder<TechnicalServiceTechnician>
     */
    private function identityQuery(bool $lock): Builder
    {
        $query = TechnicalServiceTechnician::withTrashed();

        return $lock ? $query->lockForUpdate() : $query;
    }

    /**
     * @return array{status:'matched'|'conflict',technician?:TechnicalServiceTechnician}
     */
    private function safeExistingMatch(TechnicalServiceTechnician $technician, string $sourceKey): array
    {
        if ($technician->trashed() || $technician->technician_type !== self::TYPE_LOCKSMITH) {
            return ['status' => 'conflict'];
        }

        $storedSourceKey = $this->nullableText($technician->source_key);
        if ($storedSourceKey !== null && $storedSourceKey !== $sourceKey) {
            return ['status' => 'conflict'];
        }

        return ['status' => 'matched', 'technician' => $technician];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function changesForExisting(TechnicalServiceTechnician $technician, array $payload): array
    {
        $changes = [];

        foreach (self::WRITE_COLUMNS as $column) {
            if (in_array($column, ['active', 'imported_at'], true) || ! array_key_exists($column, $payload)) {
                continue;
            }

            $incoming = $payload[$column];
            if ($incoming === null || $incoming === '') {
                continue;
            }

            if ($column === 'needs_review' && (bool) $technician->needs_review && ! (bool) $incoming) {
                continue;
            }

            if (! $this->valuesEqual($technician->getAttribute($column), $incoming)) {
                $changes[$column] = $incoming;
            }
        }

        return $changes;
    }

    private function valuesEqual(mixed $current, mixed $incoming): bool
    {
        if (is_bool($current) || is_bool($incoming)) {
            return (bool) $current === (bool) $incoming;
        }

        return (string) ($current ?? '') === (string) ($incoming ?? '');
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function assertApplicable(array $analysis): void
    {
        if ($analysis['invalid'] > 0 || $analysis['conflict'] > 0) {
            throw new RuntimeException(sprintf(
                'Locksmith import reddedildi: invalid=%d conflict=%d.',
                $analysis['invalid'],
                $analysis['conflict'],
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function publicSummary(array $analysis, bool $dryRun): array
    {
        return [
            'total' => $analysis['total'],
            'valid' => $analysis['valid'],
            'insert' => $analysis['insert'],
            'update' => $analysis['update'],
            'skip' => $analysis['skip'],
            'conflict' => $analysis['conflict'],
            'invalid' => $analysis['invalid'],
            'delete' => 0,
            'needs_review' => $analysis['needs_review'],
            'dry_run' => $dryRun,
            'imported' => $analysis['insert'],
            'updated' => $analysis['update'],
            'skipped' => $analysis['skip'],
            'errors' => $analysis['errors'],
        ];
    }

    /**
     * @return array{payloads:array<int,array<string,mixed>>,total:int,valid:int,skip:int,conflict:int,invalid:int,needs_review:int,errors:array<int,array{row:int,reason:string}>}
     */
    private function emptyPlan(int $total): array
    {
        return [
            'payloads' => [],
            'total' => $total,
            'valid' => 0,
            'skip' => 0,
            'conflict' => 0,
            'invalid' => 0,
            'needs_review' => 0,
            'errors' => [],
        ];
    }

    private function lockImportTableForPostgres(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE technical_service_technicians IN SHARE ROW EXCLUSIVE MODE');
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function existingPhoneCities(): array
    {
        $items = [];

        TechnicalServiceTechnician::withTrashed()
            ->where(function (Builder $query): void {
                $query->whereNotNull('phone_e164')->orWhereNotNull('phone');
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
     * @param  array<string, string|null>  $row
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
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    private function payload(array $mapped, bool $needsReview, bool $differentCity): array
    {
        [$firstName, $lastName] = $this->splitName((string) $mapped['name']);
        $noteParts = array_values(array_filter([
            $mapped['import_note'],
            $differentCity ? 'Ayni telefon farkli sehirde gecti; kontrol gerekli.' : null,
        ], fn (mixed $value): bool => $this->nullableText($value) !== null));

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
            'note' => $noteParts === [] ? null : implode(PHP_EOL, $noteParts),
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
            'source_key' => $this->sourceKey($mapped['phone_e164'], $mapped['city']),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function payloadFromSeedRecord(array $record): array
    {
        [$firstName, $lastName] = $this->splitName((string) ($record['name'] ?? ''));
        $phone = $this->normalizePhone($record['phone_e164'] ?? $record['phone'] ?? null);
        $city = $this->nullableText($record['city'] ?? null);

        return [
            'name' => (string) ($record['name'] ?? ''),
            'first_name' => $record['first_name'] ?? $firstName,
            'last_name' => $record['last_name'] ?? $lastName,
            'technician_type' => self::TYPE_LOCKSMITH,
            'city_plate_code' => $record['city_plate_code'] ?? null,
            'priority' => $record['priority'] ?? null,
            'phone' => $phone,
            'phone_e164' => $phone,
            'phone_display' => $record['phone_display'] ?? $phone,
            'city' => $city,
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
            'source_key' => $record['source_key'] ?? $this->sourceKey($phone, $city),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function seedRecord(array $payload): array
    {
        return array_intersect_key($payload, array_flip([
            'source_key',
            'name',
            'first_name',
            'last_name',
            'technician_type',
            'city_plate_code',
            'city',
            'priority',
            'phone_e164',
            'phone_display',
            'location_code',
            'cari_code',
            'cari_title',
            'cari_address',
            'cari_city_district_country',
            'display_name',
            'import_status',
            'import_note',
            'needs_review',
            'active',
            'note',
        ]));
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function needsReview(array $mapped): bool
    {
        $status = $this->normalizeKey($mapped['import_status']);
        $note = $this->normalizeKey($mapped['import_note']);

        return str_contains($status, 'KONTROL') || str_contains($note, 'KONTROL');
    }

    private function normalizePhone(mixed $value): ?string
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
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
