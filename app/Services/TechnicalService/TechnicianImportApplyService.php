<?php

namespace App\Services\TechnicalService;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\TechnicalServiceTechnician;
use App\Models\TechnicalServiceTechnicianImportBatch;
use App\Models\TechnicalServiceTechnicianImportRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TechnicianImportApplyService
{
    public const CONFIRMATION_TEXT = 'IMPORT APPLY ONAY';

    private const MAX_ROWS = 50;

    public function __construct(
        private readonly TechnicianImportPreviewService $previewService,
        private readonly TechnicalServiceGeocodingService $geocodingService,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function apply(UploadedFile $file, array $options, ?int $userId = null): array
    {
        $confirmation = trim((string) ($options['confirmation_text'] ?? ''));
        if ($confirmation !== self::CONFIRMATION_TEXT) {
            throw new RuntimeException('İçe aktarma için onay metni tam olarak IMPORT APPLY ONAY olmalı.');
        }

        $expectedPreviewHash = $this->blankToNull($options['preview_hash'] ?? null);
        if ($expectedPreviewHash === null) {
            throw new RuntimeException('Güncel dry-run önizlemesi olmadan içe aktarma yapılamaz.');
        }

        $selectedRows = $this->selectedRowNumbers($options['selected_row_numbers'] ?? []);
        if ($selectedRows === []) {
            throw new RuntimeException('İçe aktarma için en az bir geçerli satır seçin.');
        }

        $maxRows = min(self::MAX_ROWS, max(1, (int) ($options['max_rows'] ?? self::MAX_ROWS)));
        if (count($selectedRows) > $maxRows) {
            throw new RuntimeException("Tek seferde en fazla {$maxRows} satır içe aktarılabilir. Filtreyi daraltın veya parça parça ilerleyin.");
        }

        $applyGeocodeMode = (string) ($options['geocode_mode'] ?? 'preserve_existing');
        if (! in_array($applyGeocodeMode, ['none', 'preserve_existing', 'apply_address'], true)) {
            throw new RuntimeException('Geocode modu geçersiz.');
        }

        $previewOptions = [
            'sheet_name' => $options['sheet_name'] ?? null,
            'dry_run' => true,
            'update_existing' => (bool) ($options['update_existing'] ?? false),
            'override_existing_coordinates' => (bool) ($options['override_existing_coordinates'] ?? false),
            'link_existing_partners' => ! array_key_exists('link_existing_partners', $options) || (bool) $options['link_existing_partners'],
            'geocode_mode' => $applyGeocodeMode === 'none' ? 'none' : 'plan_only',
        ];
        $preview = $this->previewService->preview($file, $previewOptions);

        if (($preview['preview_hash'] ?? null) !== $expectedPreviewHash) {
            throw new RuntimeException('Dry-run önizlemesi güncel değil. Dosyayı veya seçenekleri değiştirdiyseniz önce yeniden önizleme alın.');
        }

        $rowsByNumber = collect($preview['rows'] ?? [])->keyBy('row_number');
        $selectedPreviewRows = [];
        foreach ($selectedRows as $rowNumber) {
            $row = $rowsByNumber->get($rowNumber);
            if (! is_array($row)) {
                throw new RuntimeException("Seçilen {$rowNumber}. satır önizlemede yok.");
            }

            if (($row['errors'] ?? []) !== [] || ($row['action'] ?? null) === 'error') {
                throw new RuntimeException("{$rowNumber}. satır hatalı olduğu için apply edilemez.");
            }

            $selectedPreviewRows[] = $row;
        }

        return DB::transaction(function () use ($preview, $selectedPreviewRows, $options, $userId, $applyGeocodeMode): array {
            $batch = TechnicalServiceTechnicianImportBatch::query()->create([
                'file_name' => (string) ($preview['file']['original_name'] ?? 'import'),
                'file_hash' => (string) ($preview['file']['file_hash'] ?? ''),
                'preview_hash' => (string) ($preview['preview_hash'] ?? ''),
                'source_type' => (string) ($preview['file']['extension'] ?? 'unknown'),
                'sheet_name' => $preview['file']['sheet_name'] ?? null,
                'dry_run_summary' => $preview['summary'] ?? [],
                'apply_summary' => null,
                'status' => 'applying',
                'created_by' => $userId,
                'applied_by' => $userId,
                'applied_at' => now(),
            ]);

            $summary = $this->emptyApplySummary();
            $resultRows = [];

            foreach ($selectedPreviewRows as $row) {
                $result = $this->applyRow(
                    $batch,
                    $row,
                    [
                        'update_existing' => (bool) ($options['update_existing'] ?? false),
                        'override_existing_coordinates' => (bool) ($options['override_existing_coordinates'] ?? false),
                        'link_existing_partners' => ! array_key_exists('link_existing_partners', $options) || (bool) $options['link_existing_partners'],
                        'create_missing_partners' => (bool) ($options['create_missing_partners'] ?? false),
                        'geocode_mode' => $applyGeocodeMode,
                    ],
                    $userId,
                );

                $this->countResult($summary, $result);
                $resultRows[] = $result;
            }

            $batch->forceFill([
                'status' => $summary['error_count'] > 0 ? 'failed' : 'applied',
                'apply_summary' => $summary,
            ])->save();

            return [
                'ok' => $summary['error_count'] === 0,
                'writes_performed' => true,
                'batch_id' => $batch->id,
                'summary' => $summary,
                'rows' => $resultRows,
            ];
        });
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function applyRow(TechnicalServiceTechnicianImportBatch $batch, array $row, array $options, ?int $userId): array
    {
        $warnings = array_values($row['warnings'] ?? []);
        $errors = [];
        $normalized = $row['normalized'] ?? [];
        $technician = $this->matchedTechnician($row);
        $action = (string) ($row['action'] ?? 'skip');
        $status = 'skipped';
        $changedFields = $row['changed_fields'] ?? [];
        $geocodeResult = ['status' => 'not_requested'];

        if ($action === 'update' && ! (bool) $options['update_existing']) {
            $warnings[] = 'Mevcut kayıt güncelleme kapalı; satır atlandı.';
            $action = 'skip';
        }

        try {
            if ($action === 'create') {
                $technician = TechnicalServiceTechnician::query()->create($this->technicianCreatePayload($normalized, $warnings, $row));
                $status = 'applied';
            } elseif ($action === 'update' && $technician instanceof TechnicalServiceTechnician) {
                $payload = $this->technicianUpdatePayload($technician, $normalized, $warnings, $changedFields, (bool) $options['override_existing_coordinates']);
                if ($payload === []) {
                    $warnings[] = 'Güncellenecek güvenli alan bulunamadı; satır atlandı.';
                    $status = 'skipped';
                    $action = 'skip';
                } else {
                    $technician->forceFill($payload)->save();
                    $technician = $technician->fresh();
                    $status = 'applied';
                }
            } elseif ($action === 'skip') {
                $status = 'skipped';
            } else {
                $errors[] = 'Güvenilir teknisyen eşleşmesi bulunamadı.';
                $status = 'error';
            }

            $geocodeResult = $status === 'applied'
                ? $this->applyGeocodePlan($technician, $normalized, (string) $options['geocode_mode'], (bool) $options['override_existing_coordinates'], $warnings)
                : ['status' => 'skipped'];
            $link = $this->applyPartnerLink($technician, $row, $options, $userId, $warnings);
        } catch (\Throwable $exception) {
            $errors[] = $exception->getMessage();
            $status = 'error';
            $link = null;
        }

        $log = TechnicalServiceTechnicianImportRow::query()->create([
            'batch_id' => $batch->id,
            'row_number' => (int) ($row['row_number'] ?? 0),
            'action' => $action,
            'status' => $status,
            'technician_id' => $technician?->id,
            'partner_id' => $link?->partner_id ?? ($row['partner_match']['id'] ?? null),
            'link_id' => $link?->id,
            'normalized_payload' => $normalized,
            'changed_fields' => $changedFields,
            'warnings' => array_values(array_unique($warnings)),
            'errors' => array_values(array_unique($errors)),
            'geocode_result' => $geocodeResult,
        ]);

        return [
            'row_number' => (int) ($row['row_number'] ?? 0),
            'action' => $action,
            'status' => $status,
            'technician_id' => $technician?->id,
            'partner_id' => $log->partner_id,
            'link_id' => $link?->id,
            'warnings' => $log->warnings ?? [],
            'errors' => $log->errors ?? [],
            'geocode_result' => $geocodeResult,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matchedTechnician(array $row): ?TechnicalServiceTechnician
    {
        $id = $row['existing_match']['id'] ?? null;

        return $id === null ? null : TechnicalServiceTechnician::query()->withTrashed()->find($id);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $warnings
     * @return array<string, mixed>
     */
    private function technicianCreatePayload(array $row, array &$warnings, array $previewRow): array
    {
        $payload = $this->baseTechnicianPayload($row);
        $payload['import_status'] = 'Import apply';
        $payload['import_source'] = 'technician_import_apply';
        $payload['imported_at'] = now();
        $payload['note'] = $this->blankToNull($row['note'] ?? null);
        $payload['needs_review'] = $this->reviewReasons($payload, $warnings) !== [];
        $payload['review_status'] = $payload['needs_review'] ? 'review_required' : 'ready';
        $payload['review_reasons'] = $this->reviewReasons($payload, $warnings);
        $payload['review_reason'] = $payload['review_reasons'] === [] ? null : implode(' ', $payload['review_reasons']);

        if ($this->blankToNull($row['source_key'] ?? null) === null) {
            $payload['source_key'] = 'import:'.$previewRow['row_number'].':'.Str::uuid()->toString();
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $warnings
     * @param array<int, string> $changedFields
     * @return array<string, mixed>
     */
    private function technicianUpdatePayload(TechnicalServiceTechnician $technician, array $row, array &$warnings, array $changedFields, bool $overrideCoordinates): array
    {
        $payload = [];
        foreach ([
            'first_name',
            'last_name',
            'phone_e164',
            'phone_display',
            'city',
            'district',
            'address',
            'google_plus_code',
            'google_formatted_address',
            'mikro_cari_kodu',
            'mikro_cari_adi',
        ] as $field) {
            if (! in_array($field, $changedFields, true) || $this->blankToNull($row[$field] ?? null) === null) {
                continue;
            }

            $payload[$field] = $row[$field];
        }

        if (array_key_exists('first_name', $payload) || array_key_exists('last_name', $payload)) {
            $firstName = $payload['first_name'] ?? $technician->first_name;
            $lastName = $payload['last_name'] ?? $technician->last_name;
            $payload['name'] = trim((string) $firstName.' '.(string) $lastName);
        }

        if (array_key_exists('phone_e164', $payload)) {
            $payload['phone'] = $payload['phone_e164'];
        }

        if (($row['active'] ?? null) !== null) {
            $payload['active'] = (bool) $row['active'];
        }

        $coordinates = $this->rowCoordinates($row);
        if ($coordinates !== null) {
            if ($this->technicianHasCoordinates($technician) && ! $overrideCoordinates) {
                $warnings[] = 'Mevcut koordinat korundu.';
            } else {
                $payload['latitude'] = $coordinates['latitude'];
                $payload['longitude'] = $coordinates['longitude'];
                $payload['start_latitude'] = $coordinates['latitude'];
                $payload['start_longitude'] = $coordinates['longitude'];
                $payload['location_source'] = 'import_file';
                $payload['geocode_status'] = 'ok';
                $payload['geocode_source'] = 'import_file';
            }
        }

        $payload['import_status'] = 'Import apply';
        $payload['import_source'] = 'technician_import_apply';
        $payload['imported_at'] = now();

        $reviewReasons = $this->reviewReasons(array_merge($technician->toArray(), $payload), $warnings);
        $payload['needs_review'] = $reviewReasons !== [];
        $payload['review_status'] = $reviewReasons === [] ? 'ready' : 'review_required';
        $payload['review_reasons'] = $reviewReasons;
        $payload['review_reason'] = $reviewReasons === [] ? null : implode(' ', $reviewReasons);

        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function baseTechnicianPayload(array $row): array
    {
        $firstName = $this->blankToNull($row['first_name'] ?? null);
        $lastName = $this->blankToNull($row['last_name'] ?? null);
        $fullName = $this->blankToNull($row['full_name'] ?? null) ?? trim((string) $firstName.' '.(string) $lastName);
        $coordinates = $this->rowCoordinates($row);

        return [
            'name' => $fullName,
            'display_name' => $fullName,
            'technician_type' => 'locksmith',
            'first_name' => $firstName ?? $fullName,
            'last_name' => $lastName,
            'phone' => $this->blankToNull($row['phone_e164'] ?? null) ?? $this->blankToNull($row['phone'] ?? null),
            'phone_e164' => $this->blankToNull($row['phone_e164'] ?? null),
            'phone_display' => $this->blankToNull($row['phone_display'] ?? null),
            'city_plate_code' => $this->blankToNull($row['city_plate_code'] ?? null),
            'city' => $this->blankToNull($row['city'] ?? null),
            'district' => $this->blankToNull($row['district'] ?? null),
            'address' => $this->blankToNull($row['address'] ?? null),
            'google_plus_code' => $this->blankToNull($row['google_plus_code'] ?? null),
            'google_formatted_address' => $this->blankToNull($row['google_formatted_address'] ?? null),
            'default_start_address' => $this->blankToNull($row['address'] ?? null),
            'default_start_plus_code' => $this->blankToNull($row['google_plus_code'] ?? null),
            'latitude' => $coordinates['latitude'] ?? null,
            'longitude' => $coordinates['longitude'] ?? null,
            'start_latitude' => $coordinates['latitude'] ?? null,
            'start_longitude' => $coordinates['longitude'] ?? null,
            'location_source' => $coordinates === null ? null : 'import_file',
            'mikro_cari_kodu' => $this->blankToNull($row['mikro_cari_kodu'] ?? null),
            'mikro_cari_adi' => $this->blankToNull($row['mikro_cari_adi'] ?? null),
            'cari_code' => $this->blankToNull($row['mikro_cari_kodu'] ?? null),
            'cari_title' => $this->blankToNull($row['mikro_cari_adi'] ?? null),
            'active' => array_key_exists('active', $row) && $row['active'] !== null ? (bool) $row['active'] : true,
            'source_key' => $this->blankToNull($row['source_key'] ?? null),
            'geocode_status' => $coordinates === null ? null : 'ok',
            'geocode_source' => $coordinates === null ? null : 'import_file',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{latitude:float,longitude:float}|null
     */
    private function rowCoordinates(array $row): ?array
    {
        return $this->geocodingService->validCoordinatePair($row['latitude'] ?? null, $row['longitude'] ?? null)
            ?? $this->geocodingService->validCoordinatePair($row['start_latitude'] ?? null, $row['start_longitude'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $warnings
     * @return array<int, string>
     */
    private function reviewReasons(array $payload, array $warnings): array
    {
        $reasons = [];
        if ($this->blankToNull($payload['phone'] ?? null) === null && $this->blankToNull($payload['phone_e164'] ?? null) === null) {
            $reasons[] = 'Telefon eksik.';
        }
        if ($this->blankToNull($payload['city'] ?? null) === null || (
            $this->blankToNull($payload['address'] ?? null) === null
            && $this->blankToNull($payload['google_formatted_address'] ?? null) === null
            && $this->blankToNull($payload['default_start_address'] ?? null) === null
        )) {
            $reasons[] = 'Adres/şehir eksik.';
        }
        if ($this->geocodingService->validCoordinatePair($payload['latitude'] ?? null, $payload['longitude'] ?? null) === null
            && $this->geocodingService->validCoordinatePair($payload['start_latitude'] ?? null, $payload['start_longitude'] ?? null) === null
        ) {
            $reasons[] = 'Koordinat eksik.';
        }

        foreach ($warnings as $warning) {
            if (str_contains($warning, 'zayıf') || str_contains($warning, 'eksik')) {
                $reasons[] = $warning;
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $warnings
     * @return array<string, mixed>
     */
    private function applyGeocodePlan(?TechnicalServiceTechnician $technician, array $row, string $mode, bool $overrideCoordinates, array &$warnings): array
    {
        if (! $technician instanceof TechnicalServiceTechnician || $mode === 'none') {
            return ['status' => 'skipped'];
        }

        if ($this->technicianHasCoordinates($technician) && ! $overrideCoordinates) {
            return ['status' => 'preserved_existing'];
        }

        $coordinates = $this->rowCoordinates($row);
        if ($coordinates !== null) {
            $technician->forceFill([
                'latitude' => $coordinates['latitude'],
                'longitude' => $coordinates['longitude'],
                'start_latitude' => $coordinates['latitude'],
                'start_longitude' => $coordinates['longitude'],
                'location_source' => 'import_file',
                'geocode_status' => 'ok',
                'geocode_source' => 'import_file',
            ])->save();

            return ['status' => 'coordinates_written', 'latitude' => $coordinates['latitude'], 'longitude' => $coordinates['longitude']];
        }

        if ($mode === 'apply_address') {
            $warnings[] = 'Adres/Plus Code için geocode apply bu küçük fixture fazında provider çağırmadan review uyarısı bıraktı.';
        }

        return ['status' => 'review_required'];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $options
     * @param array<int, string> $warnings
     */
    private function applyPartnerLink(?TechnicalServiceTechnician $technician, array $row, array $options, ?int $userId, array &$warnings): ?B2BPartnerTechnician
    {
        if (! (bool) $options['link_existing_partners'] || ! $technician instanceof TechnicalServiceTechnician) {
            return null;
        }

        $partnerId = $row['partner_match']['id'] ?? null;
        if ($partnerId === null) {
            $warnings[] = 'Partner bulunamadı; partner oluşturulmadı.';

            return null;
        }

        $partner = B2BPartner::query()->find($partnerId);
        if (! $partner instanceof B2BPartner) {
            $warnings[] = 'Partner bulunamadı; partner oluşturulmadı.';

            return null;
        }

        $attributes = [
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
        ];
        $values = [
            'relationship_type' => 'field_technician',
            'is_primary' => false,
            'active' => true,
            'source' => 'technician_import_apply',
            'match_reason' => 'mikro_cari_kodu',
            'service_city' => $technician->city,
            'service_district' => $technician->district,
            'priority' => 1,
            'needs_review' => (bool) $technician->needs_review,
            'review_reason' => $technician->review_reason,
            'review_reasons' => $technician->review_reasons,
            'metadata' => [
                'source' => 'technician_import_apply',
                'row_number' => $row['row_number'] ?? null,
            ],
            'created_by' => $userId,
        ];

        return B2BPartnerTechnician::query()->updateOrCreate($attributes, $values);
    }

    private function technicianHasCoordinates(TechnicalServiceTechnician $technician): bool
    {
        return $this->geocodingService->validCoordinatePair($technician->latitude, $technician->longitude) !== null
            || $this->geocodingService->validCoordinatePair($technician->start_latitude, $technician->start_longitude) !== null;
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function selectedRowNumbers(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];

        return collect($items)
            ->map(fn (mixed $item): int => (int) $item)
            ->filter(fn (int $item): bool => $item > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function emptyApplySummary(): array
    {
        return [
            'create_count' => 0,
            'update_count' => 0,
            'skip_count' => 0,
            'error_count' => 0,
            'warning_count' => 0,
            'partner_link_create_count' => 0,
            'partner_link_update_count' => 0,
            'partner_link_skip_count' => 0,
            'geocode_success_count' => 0,
            'geocode_failed_count' => 0,
            'preserved_coordinate_count' => 0,
            'review_required_count' => 0,
        ];
    }

    /**
     * @param array<string, int> $summary
     * @param array<string, mixed> $result
     */
    private function countResult(array &$summary, array $result): void
    {
        match ($result['status']) {
            'applied' => $summary[$result['action'] === 'update' ? 'update_count' : 'create_count']++,
            'skipped' => $summary['skip_count']++,
            'error' => $summary['error_count']++,
            default => null,
        };

        $summary['warning_count'] += count($result['warnings'] ?? []);
        if (($result['link_id'] ?? null) !== null) {
            $summary['partner_link_create_count']++;
        } else {
            $summary['partner_link_skip_count']++;
        }

        match ($result['geocode_result']['status'] ?? '') {
            'coordinates_written' => $summary['geocode_success_count']++,
            'preserved_existing' => $summary['preserved_coordinate_count']++,
            'review_required' => $summary['geocode_failed_count']++,
            default => null,
        };

        if (in_array('Koordinat eksik.', $result['warnings'] ?? [], true) || ($result['geocode_result']['status'] ?? null) === 'review_required') {
            $summary['review_required_count']++;
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
