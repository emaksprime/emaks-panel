<?php

namespace App\Services;

use App\Models\SupportActivationCode;
use App\Models\SupportActivationImportBatch;
use App\Models\SupportGuideEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportManagementService
{
    private const CSV_COLUMNS = [
        'STOK_KODU' => 'stok_kodu',
        'STOK_ADI' => 'stok_adi',
        'SERI_NO' => 'seri_no',
        'SERI_NO_TEMIZ' => 'seri_no_temiz',
        'ARAMA_KODU' => 'arama_kodu',
    ];

    public function __construct(
        private readonly SupportActivationCodeService $activationCodes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function activationCodeList(?string $search = null, int $limit = 100): array
    {
        $query = SupportActivationCode::query();
        $search = trim((string) $search);

        if ($search !== '') {
            $needle = Str::lower($search);
            $normalized = Str::lower($this->activationCodes->normalizeSearchValue($search));

            $query->where(function ($builder) use ($needle, $normalized): void {
                foreach ([
                    'stock_code',
                    'stock_name',
                    'serial_number',
                    'serial_number_clean',
                    'search_code',
                    'activation_code',
                    'search_text',
                ] as $column) {
                    $builder->orWhereRaw("LOWER({$column}) like ?", ['%'.$needle.'%']);
                }

                if ($normalized !== '') {
                    $builder->orWhere('code', 'like', '%'.$normalized.'%');
                }
            });
        }

        $items = $query
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (SupportActivationCode $record): array => $this->activationCodePayload($record))
            ->values()
            ->all();

        return [
            'items' => $items,
            'total' => SupportActivationCode::query()->count(),
            'filtered_total' => count($items),
            'last_import' => $this->lastImportBatchPayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function previewActivationImport(
        string $contents,
        string $source = 'paste',
        ?string $filename = null,
    ): array {
        $parsedRows = $this->parseCsvRows($contents);
        $existingCleanSerials = SupportActivationCode::query()
            ->whereNotNull('serial_number_clean')
            ->pluck('id', 'serial_number_clean');
        $seenCleanSerials = [];
        $rows = [];
        $errors = [];
        $skippedRows = [];
        $newCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        foreach ($parsedRows as $parsedRow) {
            $normalized = $this->normalizeImportRow($parsedRow['data']);

            if ($normalized['seri_no_temiz'] === null) {
                $failedCount++;
                $errors[] = [
                    'row' => $parsedRow['row'],
                    'reason' => 'Seri no temiz değeri çıkarılamadı.',
                    'data' => $parsedRow['data'],
                ];

                continue;
            }

            if (isset($seenCleanSerials[$normalized['seri_no_temiz']])) {
                $skippedCount++;
                $skippedRows[] = [
                    'row' => $parsedRow['row'],
                    'reason' => 'Aynı import içinde tekrar eden temiz seri atlandı.',
                    'seri_no_temiz' => $normalized['seri_no_temiz'],
                ];

                continue;
            }

            $seenCleanSerials[$normalized['seri_no_temiz']] = true;
            $action = $existingCleanSerials->has($normalized['seri_no_temiz']) ? 'update' : 'create';
            $action === 'create' ? $newCount++ : $updatedCount++;
            $rows[] = [
                'row' => $parsedRow['row'],
                'action' => $action,
                ...$normalized,
            ];
        }

        return [
            'source' => $source,
            'filename' => $filename,
            'total_rows' => count($parsedRows),
            'new_count' => $newCount,
            'created_count' => $newCount,
            'updated_count' => $updatedCount,
            'skipped_count' => $skippedCount,
            'failed_count' => $failedCount,
            'rows' => $rows,
            'errors' => array_slice($errors, 0, 20),
            'skipped_rows' => array_slice($skippedRows, 0, 20),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function commitActivationImport(
        array $rows,
        int $userId,
        string $source = 'csv',
        ?string $filename = null,
    ): array {
        return DB::transaction(function () use ($rows, $userId, $source, $filename): array {
            $batch = SupportActivationImportBatch::query()->create([
                'filename' => $filename,
                'source' => $source,
                'status' => 'committing',
                'total_rows' => count($rows),
                'created_by' => $userId,
                'preview_payload' => ['rows' => $rows],
            ]);

            $createdCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $failedCount = 0;
            $errors = [];
            $seenCleanSerials = [];

            foreach ($rows as $row) {
                $normalized = $this->normalizeCommitRow($row);
                $cleanSerial = $normalized['seri_no_temiz'];

                if ($cleanSerial === null) {
                    $failedCount++;
                    $errors[] = [
                        'row' => $row['row'] ?? null,
                        'reason' => 'Temiz seri olmadan commit yapılamaz.',
                    ];

                    continue;
                }

                if (isset($seenCleanSerials[$cleanSerial])) {
                    $skippedCount++;

                    continue;
                }

                $seenCleanSerials[$cleanSerial] = true;
                $payload = $this->activationImportPayload($normalized, $source, $userId, $batch->id);
                $record = SupportActivationCode::query()
                    ->where('serial_number_clean', $cleanSerial)
                    ->first()
                    ?? SupportActivationCode::query()->where('code', $payload['code'])->first();

                if ($record) {
                    $record->update($payload);
                    $updatedCount++;
                } else {
                    SupportActivationCode::query()->create([
                        ...$payload,
                        'created_by' => $userId,
                    ]);
                    $createdCount++;
                }
            }

            $batch->update([
                'status' => 'committed',
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'skipped_count' => $skippedCount,
                'failed_count' => $failedCount,
                'result_payload' => ['errors' => array_slice($errors, 0, 20)],
            ]);

            return [
                'batch' => $this->batchPayload($batch->fresh()),
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'skipped_count' => $skippedCount,
                'failed_count' => $failedCount,
                'errors' => array_slice($errors, 0, 20),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function saveActivationCode(array $data, int $userId, ?SupportActivationCode $record = null): array
    {
        $normalized = $this->normalizeManualActivationData($data);
        $payload = $this->activationImportPayload($normalized, 'manual', $userId, null);
        $target = $record;

        if (! $target && $normalized['seri_no_temiz']) {
            $target = SupportActivationCode::query()
                ->where('serial_number_clean', $normalized['seri_no_temiz'])
                ->first();
        }

        if ($target) {
            $target->update($payload);
            $saved = $target->fresh();
        } else {
            $saved = SupportActivationCode::query()->create([
                ...$payload,
                'created_by' => $userId,
            ]);
        }

        return ['item' => $this->activationCodePayload($saved)];
    }

    /**
     * @return array<string, mixed>
     */
    public function guideList(?string $search = null): array
    {
        $query = SupportGuideEntry::query();
        $search = trim((string) $search);

        if ($search !== '') {
            $needle = Str::lower($search);
            $query->where(function ($builder) use ($needle): void {
                $builder
                    ->orWhereRaw('LOWER(title) like ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(stok_kodu) like ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(product_keyword) like ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(guide_content) like ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(search_text) like ?', ['%'.$needle.'%']);
            });
        }

        return [
            'items' => $query
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (SupportGuideEntry $entry): array => $this->guidePayload($entry))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saveGuideEntry(array $data, int $userId, ?SupportGuideEntry $entry = null): array
    {
        $title = $this->nullableText($data['title'] ?? null) ?? 'Tuşlama rehberi';
        $guideContent = $this->nullableText($data['guide_content'] ?? null) ?? '';
        $productKeyword = $this->nullableText($data['product_keyword'] ?? null);
        $stockCode = $this->nullableText($data['stok_kodu'] ?? null);
        $sortOrder = (int) ($data['sort_order'] ?? 100);
        $sections = $this->sectionsFromGuideContent($title, $guideContent);
        $devices = array_values(array_filter([$productKeyword ?? $title]));
        $aliases = array_values(array_filter([$stockCode]));
        $payload = [
            'title' => $title,
            'source_sheet' => 'Destek Yönetimi',
            'source_row' => null,
            'stok_kodu' => $stockCode,
            'product_keyword' => $productKeyword,
            'guide_content' => $guideContent,
            'devices' => $devices,
            'device_aliases' => $aliases,
            'method' => $this->nullableText($data['method'] ?? null),
            'guide_type' => $title,
            'sections' => $sections,
            'warnings' => [],
            'extra_notes' => [],
            'search_text' => $this->guideSearchText($title, $stockCode, $productKeyword, $guideContent),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => $sortOrder,
            'updated_by' => $userId,
        ];

        if ($entry) {
            $entry->update($payload);
            $saved = $entry->fresh();
        } else {
            $saved = SupportGuideEntry::query()->create([
                ...$payload,
                'code' => 'support_guide_manual_'.sha1($title.'|'.$guideContent.'|'.Str::uuid()),
                'created_by' => $userId,
            ]);
        }

        return ['item' => $this->guidePayload($saved)];
    }

    /**
     * @return list<array{row: int, data: array<string, ?string>}>
     */
    private function parseCsvRows(string $contents): array
    {
        $contents = $this->normalizeCsvEncoding($contents);
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            return [];
        }

        $delimiter = $this->detectCsvDelimiter($lines[0]);
        $firstRow = str_getcsv($lines[0], $delimiter);
        $headerMap = $this->headerMap($firstRow);
        $dataLines = $headerMap ? array_slice($lines, 1) : $lines;
        $startRow = $headerMap ? 2 : 1;
        $rows = [];

        foreach ($dataLines as $index => $line) {
            $values = str_getcsv($line, $delimiter);
            $mapped = $headerMap
                ? $this->mapHeaderRow($values, $headerMap)
                : $this->mapPositionalRow($values);
            $rows[] = [
                'row' => $startRow + $index,
                'data' => $mapped,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string|null>  $headers
     * @return array<int, string>|null
     */
    private function headerMap(array $headers): ?array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader((string) $header);

            foreach (self::CSV_COLUMNS as $expected => $field) {
                if ($normalized === $this->normalizeHeader($expected)) {
                    $map[$index] = $field;
                }
            }
        }

        return count(array_unique($map)) >= 3 ? $map : null;
    }

    /**
     * @param  list<string|null>  $values
     * @param  array<int, string>  $headerMap
     * @return array<string, ?string>
     */
    private function mapHeaderRow(array $values, array $headerMap): array
    {
        $row = $this->emptyCsvRow();

        foreach ($headerMap as $index => $field) {
            $row[$field] = $this->nullableText($values[$index] ?? null);
        }

        return $row;
    }

    /**
     * @param  list<string|null>  $values
     * @return array<string, ?string>
     */
    private function mapPositionalRow(array $values): array
    {
        $fields = array_values(self::CSV_COLUMNS);
        $row = $this->emptyCsvRow();

        foreach ($fields as $index => $field) {
            $row[$field] = $this->nullableText($values[$index] ?? null);
        }

        return $row;
    }

    /**
     * @return array<string, ?string>
     */
    private function emptyCsvRow(): array
    {
        return [
            'stok_kodu' => null,
            'stok_adi' => null,
            'seri_no' => null,
            'seri_no_temiz' => null,
            'arama_kodu' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{stok_kodu: ?string, stok_adi: ?string, seri_no: ?string, seri_no_temiz: ?string, aktivasyon_kodu: ?string, arama_kodu: ?string}
     */
    private function normalizeImportRow(array $row): array
    {
        $serialNumber = $this->nullableText($row['seri_no'] ?? null);
        $cleanSerial = $this->nullableText($row['seri_no_temiz'] ?? null);
        [$cleanSerial, $activationCode] = $this->extractActivationFromSerial($serialNumber, $cleanSerial);

        return [
            'stok_kodu' => $this->nullableText($row['stok_kodu'] ?? null),
            'stok_adi' => $this->nullableText($row['stok_adi'] ?? null),
            'seri_no' => $serialNumber,
            'seri_no_temiz' => $cleanSerial,
            'aktivasyon_kodu' => $activationCode,
            'arama_kodu' => $this->nullableText($row['arama_kodu'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{stok_kodu: ?string, stok_adi: ?string, seri_no: ?string, seri_no_temiz: ?string, aktivasyon_kodu: ?string, arama_kodu: ?string}
     */
    private function normalizeCommitRow(array $row): array
    {
        $serialNumber = $this->nullableText($row['seri_no'] ?? $row['serial_number'] ?? null);
        $cleanSerial = $this->nullableText($row['seri_no_temiz'] ?? $row['serial_number_clean'] ?? null);
        $activationCode = $this->nullableText($row['aktivasyon_kodu'] ?? $row['activation_code'] ?? null);

        if ($cleanSerial === null || $activationCode === null) {
            [$cleanSerial, $extractedActivationCode] = $this->extractActivationFromSerial($serialNumber, $cleanSerial);
            $activationCode ??= $extractedActivationCode;
        }

        return [
            'stok_kodu' => $this->nullableText($row['stok_kodu'] ?? $row['stock_code'] ?? null),
            'stok_adi' => $this->nullableText($row['stok_adi'] ?? $row['stock_name'] ?? null),
            'seri_no' => $serialNumber,
            'seri_no_temiz' => $cleanSerial,
            'aktivasyon_kodu' => $activationCode,
            'arama_kodu' => $this->nullableText($row['arama_kodu'] ?? $row['search_code'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{stok_kodu: ?string, stok_adi: ?string, seri_no: ?string, seri_no_temiz: ?string, aktivasyon_kodu: ?string, arama_kodu: ?string}
     */
    private function normalizeManualActivationData(array $data): array
    {
        $serialNumber = $this->nullableText($data['serial_number'] ?? null);
        $cleanSerial = $this->nullableText($data['serial_number_clean'] ?? null);
        $activationCode = $this->nullableText($data['activation_code'] ?? null);

        if ($cleanSerial === null || $activationCode === null) {
            [$cleanSerial, $extractedActivationCode] = $this->extractActivationFromSerial($serialNumber, $cleanSerial);
            $activationCode ??= $extractedActivationCode;
        }

        return [
            'stok_kodu' => $this->nullableText($data['stock_code'] ?? null),
            'stok_adi' => $this->nullableText($data['stock_name'] ?? null),
            'seri_no' => $serialNumber,
            'seri_no_temiz' => $cleanSerial,
            'aktivasyon_kodu' => $activationCode,
            'arama_kodu' => $this->nullableText($data['search_code'] ?? null),
        ];
    }

    /**
     * @param  array{stok_kodu: ?string, stok_adi: ?string, seri_no: ?string, seri_no_temiz: ?string, aktivasyon_kodu: ?string, arama_kodu: ?string}  $row
     * @return array<string, mixed>
     */
    private function activationImportPayload(array $row, string $source, int $userId, ?int $batchId): array
    {
        $payload = $this->activationCodes->buildRecordPayload([
            'stock_code' => $row['stok_kodu'],
            'stock_name' => $row['stok_adi'],
            'serial_number' => $row['seri_no'],
            'serial_number_clean' => $row['seri_no_temiz'],
            'search_code' => $row['arama_kodu'],
            'activation_code' => $row['aktivasyon_kodu'],
            'metadata' => [
                'import_source' => $source,
                'import_batch_id' => $batchId,
            ],
        ]);

        return [
            ...$payload,
            'source' => $source,
            'imported_at' => now(),
            'updated_by' => $userId,
            'import_batch_id' => $batchId,
            'is_active' => true,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function extractActivationFromSerial(?string $serialNumber, ?string $cleanSerial): array
    {
        if ($serialNumber === null || ! str_contains($serialNumber, '-')) {
            return [$cleanSerial, null];
        }

        if ($cleanSerial !== null && str_starts_with($serialNumber, $cleanSerial.'-')) {
            return [$cleanSerial, $this->nullableText(substr($serialNumber, strlen($cleanSerial) + 1))];
        }

        $lastDash = strrpos($serialNumber, '-');

        if ($lastDash === false) {
            return [$cleanSerial, null];
        }

        $prefix = $this->nullableText(substr($serialNumber, 0, $lastDash));
        $suffix = $this->nullableText(substr($serialNumber, $lastDash + 1));

        return [$cleanSerial ?? $prefix, $suffix];
    }

    /**
     * @return array<string, mixed>
     */
    private function activationCodePayload(SupportActivationCode $record): array
    {
        return [
            'id' => $record->id,
            'code' => $record->code,
            'stock_code' => $record->stock_code,
            'stock_name' => $record->stock_name,
            'serial_number' => $record->serial_number,
            'serial_number_clean' => $record->serial_number_clean,
            'search_code' => $record->search_code,
            'activation_code' => $record->activation_code,
            'source' => $record->source,
            'imported_at' => $record->imported_at?->toIso8601String(),
            'is_active' => (bool) $record->is_active,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastImportBatchPayload(): ?array
    {
        $batch = SupportActivationImportBatch::query()
            ->orderByDesc('id')
            ->first();

        return $batch ? $this->batchPayload($batch) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function batchPayload(SupportActivationImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'filename' => $batch->filename,
            'source' => $batch->source,
            'status' => $batch->status,
            'total_rows' => $batch->total_rows,
            'created_count' => $batch->created_count,
            'updated_count' => $batch->updated_count,
            'skipped_count' => $batch->skipped_count,
            'failed_count' => $batch->failed_count,
            'created_at' => $batch->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function guidePayload(SupportGuideEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title ?? $entry->guide_type,
            'stok_kodu' => $entry->stok_kodu,
            'product_keyword' => $entry->product_keyword,
            'guide_content' => $entry->guide_content,
            'method' => $entry->method,
            'is_active' => (bool) $entry->is_active,
            'sort_order' => (int) $entry->sort_order,
            'source_sheet' => $entry->source_sheet,
        ];
    }

    /**
     * @return list<array{title: string|null, steps: list<string>}>
     */
    private function sectionsFromGuideContent(string $title, string $guideContent): array
    {
        $steps = collect(preg_split('/\r\n|\n|\r/', $guideContent) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values()
            ->all();

        return [
            [
                'title' => $title,
                'steps' => $steps === [] ? [$guideContent] : $steps,
            ],
        ];
    }

    private function guideSearchText(
        string $title,
        ?string $stockCode,
        ?string $productKeyword,
        string $guideContent,
    ): string {
        return collect([$title, $stockCode, $productKeyword, $guideContent])
            ->filter(fn ($value): bool => $value !== null && trim((string) $value) !== '')
            ->flatMap(fn ($value): array => [
                Str::lower((string) $value),
                Str::lower($this->activationCodes->normalizeSearchValue((string) $value)),
            ])
            ->filter()
            ->unique()
            ->implode(' ');
    }

    private function normalizeHeader(string $value): string
    {
        $value = str_replace(['İ', 'ı'], ['I', 'I'], Str::upper($value));

        return preg_replace('/[^A-Z0-9]+/u', '', $value) ?? '';
    }

    private function normalizeCsvEncoding(string $contents): string
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
            $encoding = mb_detect_encoding($contents, ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ISO-8859-1'], true);

            if ($encoding && $encoding !== 'UTF-8') {
                return mb_convert_encoding($contents, 'UTF-8', $encoding);
            }
        }

        return $contents;
    }

    private function detectCsvDelimiter(string $line): string
    {
        $delimiters = [',', ';', "\t"];
        $scores = [];

        foreach ($delimiters as $delimiter) {
            $scores[$delimiter] = count(str_getcsv($line, $delimiter));
        }

        arsort($scores);

        return (string) array_key_first($scores);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
