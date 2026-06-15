<?php

namespace App\Services;

use App\Models\SupportActivationCode;
use App\Models\SupportActivationImportBatch;
use App\Models\SupportGuideEntry;
use App\Models\SupportKeyingGuideProduct;
use App\Models\SupportKeyingGuideStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

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
    public function activationCodeList(?string $search = null, int $page = 1, int $perPage = 25): array
    {
        $query = SupportActivationCode::query();
        $search = trim((string) $search);
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50], true) ? $perPage : 25;

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

        $paginator = $query
            ->orderByDesc('updated_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => $paginator
                ->getCollection()
                ->map(fn (SupportActivationCode $record): array => $this->activationCodePayload($record))
                ->values()
                ->all(),
            'total' => SupportActivationCode::query()->count(),
            'filtered_total' => $paginator->total(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
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
        $source = $this->normalizedImportSource($source, $filename);
        $parsedRows = $this->parseImportRows($contents, $source);
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
    public function guideProductList(?string $search = null): array
    {
        $search = trim((string) $search);
        $managedItems = $this->managedGuideProductList($search);
        $legacyItems = $this->legacyGuideProductList($search);

        $items = collect($managedItems)
            ->merge($legacyItems)
            ->sortBy([
                ['sort_order', 'asc'],
                ['product_name', 'asc'],
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saveGuideProduct(array $data, int $userId, SupportKeyingGuideProduct|string|int|null $product = null): array
    {
        if ($this->isLegacyProductId($product)) {
            return $this->saveLegacyGuideProduct((string) $product, $data);
        }

        $product = $product instanceof SupportKeyingGuideProduct
            ? $product
            : ($product === null ? null : SupportKeyingGuideProduct::query()->findOrFail($product));
        $productName = $this->nullableText($data['product_name'] ?? null) ?? 'Ürün';
        $sortOrder = array_key_exists('sort_order', $data)
            ? (int) $data['sort_order']
            : (int) ($product?->sort_order ?? $this->nextManagedProductSortOrder());
        $payload = [
            'product_name' => $productName,
            'search_keywords' => $this->nullableText($data['search_keywords'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => $sortOrder,
            'updated_by' => $userId,
        ];

        if ($product) {
            $product->update($payload);
            $saved = $product->fresh('steps');
        } else {
            $saved = SupportKeyingGuideProduct::query()->create([
                ...$payload,
                'created_by' => $userId,
            ])->fresh('steps');
        }

        return ['item' => $this->guideProductPayload($saved)];
    }

    /**
     * @return array<string, mixed>
     */
    public function saveGuideStep(
        SupportKeyingGuideProduct|string|int $product,
        array $data,
        int $userId,
        SupportKeyingGuideStep|string|int|null $step = null,
    ): array {
        if ($this->isLegacyProductId($product)) {
            return $this->saveLegacyGuideStep((string) $product, $data, $step);
        }

        $product = $product instanceof SupportKeyingGuideProduct
            ? $product
            : SupportKeyingGuideProduct::query()->findOrFail($product);
        $step = $step instanceof SupportKeyingGuideStep
            ? $step
            : ($step === null ? null : SupportKeyingGuideStep::query()->findOrFail($step));
        $sectionType = $this->nullableText($data['section_type'] ?? null) ?? 'other';
        $customTitle = $sectionType === 'other'
            ? $this->nullableText($data['custom_title'] ?? null)
            : null;
        $title = $this->guideStepTitle($sectionType, $customTitle);
        $payload = [
            'product_id' => $product->id,
            'section_type' => $sectionType,
            'custom_title' => $customTitle,
            'entry_method' => $this->nullableText($data['entry_method'] ?? null),
            'entry_format' => $this->nullableText($data['entry_format'] ?? null),
            'title' => $title,
            'content' => $this->nullableText($data['content'] ?? null) ?? '',
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => array_key_exists('sort_order', $data)
                ? (int) $data['sort_order']
                : (int) ($step?->sort_order ?? $this->nextManagedStepSortOrder($product)),
            'updated_by' => $userId,
        ];

        if ($step) {
            if ((int) $step->product_id !== (int) $product->id) {
                abort(404);
            }

            $step->update($payload);
        } else {
            $step = SupportKeyingGuideStep::query()->create([
                ...$payload,
                'created_by' => $userId,
            ]);
        }

        return [
            'item' => $this->guideStepPayload($step->fresh()),
            'product' => $this->guideProductPayload($product->fresh('steps')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function duplicateGuideProduct(string|int $product, int $userId): array
    {
        if ($this->isLegacyProductId($product)) {
            $source = $this->legacyGuideProductPayload($this->legacyProductEntries((string) $product));
        } else {
            $sourceProduct = SupportKeyingGuideProduct::query()
                ->with(['steps' => fn ($steps) => $steps->orderBy('sort_order')->orderBy('id')])
                ->findOrFail($product);
            $source = $this->guideProductPayload($sourceProduct);
        }

        $copy = SupportKeyingGuideProduct::query()->create([
            'product_name' => $source['product_name'].' - Kopya',
            'search_keywords' => $source['search_keywords'],
            'is_active' => true,
            'sort_order' => $this->nextManagedProductSortOrder(),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        foreach (($source['steps'] ?? []) as $index => $step) {
            SupportKeyingGuideStep::query()->create([
                'product_id' => $copy->id,
                'section_type' => $step['section_type'] ?? 'other',
                'custom_title' => $step['custom_title'] ?? null,
                'entry_method' => $step['entry_method'] ?? null,
                'entry_format' => $step['entry_format'] ?? ($step['title'] ?? 'Diğer'),
                'title' => $step['title'] ?? ($step['entry_format'] ?? 'Diğer'),
                'content' => $step['content'] ?? '',
                'is_active' => (bool) ($step['is_active'] ?? true),
                'sort_order' => ($index + 1) * 10,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        }

        return ['item' => $this->guideProductPayload($copy->fresh('steps'))];
    }

    /**
     * @return list<array{row: int, data: array<string, ?string>}>
     */
    private function parseImportRows(string $contents, string $source): array
    {
        return $source === 'xlsx'
            ? $this->parseXlsxRows($contents)
            : $this->parseCsvRows($contents);
    }

    /**
     * @return list<array{row: int, data: array<string, ?string>}>
     */
    private function parseXlsxRows(string $contents): array
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'support-xlsx-');

        if ($temporaryPath === false) {
            return [];
        }

        file_put_contents($temporaryPath, $contents);

        $zip = new ZipArchive();
        $opened = $zip->open($temporaryPath);

        if ($opened !== true) {
            @unlink($temporaryPath);

            return [];
        }

        try {
            $sharedStrings = $this->xlsxSharedStrings($zip);
            $sheetXml = $this->xlsxFirstSheetXml($zip);

            if ($sheetXml === null) {
                return [];
            }

            $xml = simplexml_load_string($sheetXml);

            if (! $xml) {
                return [];
            }

            $rows = [];
            $sheetRows = $xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];

            foreach ($sheetRows as $sheetRow) {
                $rowNumber = (int) ($sheetRow['r'] ?? 0);
                $values = [];

                foreach ($sheetRow->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                    $cellReference = (string) ($cell['r'] ?? '');
                    $columnIndex = $this->xlsxColumnIndex($cellReference);

                    if ($columnIndex === null) {
                        continue;
                    }

                    $values[$columnIndex] = $this->xlsxCellValue($cell, $sharedStrings);
                }

                if ($values !== []) {
                    ksort($values);
                    $rows[] = [
                        'row' => $rowNumber ?: count($rows) + 1,
                        'values' => $values,
                    ];
                }
            }

            if ($rows === []) {
                return [];
            }

            $firstValues = $this->denseXlsxRow($rows[0]['values']);
            $headerMap = $this->headerMap($firstValues);
            $dataRows = $headerMap ? array_slice($rows, 1) : $rows;
            $mappedRows = [];

            foreach ($dataRows as $row) {
                $values = $this->denseXlsxRow($row['values']);
                $mappedRows[] = [
                    'row' => $row['row'],
                    'data' => $headerMap
                        ? $this->mapHeaderRow($values, $headerMap)
                        : $this->mapPositionalRow($values),
                ];
            }

            return $mappedRows;
        } finally {
            $zip->close();
            @unlink($temporaryPath);
        }
    }

    /**
     * @return list<string>
     */
    private function xlsxSharedStrings(ZipArchive $zip): array
    {
        $contents = $zip->getFromName('xl/sharedStrings.xml');

        if (! is_string($contents)) {
            return [];
        }

        $xml = simplexml_load_string($contents);

        if (! $xml) {
            return [];
        }

        $strings = [];

        foreach ($xml->xpath('//*[local-name()="si"]') ?: [] as $item) {
            $parts = [];

            foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $text) {
                $parts[] = (string) $text;
            }

            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    private function xlsxFirstSheetXml(ZipArchive $zip): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (! is_string($workbook) || ! is_string($rels)) {
            return $zip->getFromName('xl/worksheets/sheet1.xml') ?: null;
        }

        $workbookXml = simplexml_load_string($workbook);
        $relsXml = simplexml_load_string($rels);

        if (! $workbookXml || ! $relsXml) {
            return null;
        }

        $sheet = ($workbookXml->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [])[0] ?? null;
        $relationshipId = $sheet ? (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';

        foreach ($relsXml->xpath('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            if ((string) $relationship['Id'] !== $relationshipId) {
                continue;
            }

            $target = ltrim((string) $relationship['Target'], '/');
            $path = str_starts_with($target, 'xl/')
                ? $target
                : 'xl/'.$target;

            return $zip->getFromName($path) ?: null;
        }

        return null;
    }

    private function xlsxColumnIndex(string $cellReference): ?int
    {
        if (! preg_match('/^([A-Z]+)/i', $cellReference, $matches)) {
            return null;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * @param  array<int, string|null>  $values
     * @return list<string|null>
     */
    private function denseXlsxRow(array $values): array
    {
        $max = max(array_keys($values));
        $dense = [];

        for ($index = 0; $index <= $max; $index++) {
            $dense[] = $values[$index] ?? null;
        }

        return $dense;
    }

    private function xlsxCellValue(\SimpleXMLElement $cell, array $sharedStrings): ?string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 'inlineStr') {
            $parts = [];

            foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $text) {
                $parts[] = (string) $text;
            }

            return $this->nullableText(implode('', $parts));
        }

        $valueNodes = $cell->xpath('./*[local-name()="v"]') ?: [];
        $value = isset($valueNodes[0]) ? (string) $valueNodes[0] : '';

        if ($type === 's') {
            return $this->nullableText($sharedStrings[(int) $value] ?? null);
        }

        return $this->nullableText($value);
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
     * @return list<array<string, mixed>>
     */
    private function managedGuideProductList(string $search): array
    {
        $query = SupportKeyingGuideProduct::query()->with([
            'steps' => fn ($steps) => $steps->orderBy('sort_order')->orderBy('id'),
        ]);

        if ($search !== '') {
            $needle = Str::lower($search);
            $query->where(function ($builder) use ($needle): void {
                $builder
                    ->orWhereRaw('LOWER(product_name) like ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(search_keywords) like ?', ['%'.$needle.'%'])
                    ->orWhereHas('steps', function ($steps) use ($needle): void {
                        $steps
                            ->whereRaw('LOWER(title) like ?', ['%'.$needle.'%'])
                            ->orWhereRaw('LOWER(entry_method) like ?', ['%'.$needle.'%'])
                            ->orWhereRaw('LOWER(entry_format) like ?', ['%'.$needle.'%'])
                            ->orWhereRaw('LOWER(content) like ?', ['%'.$needle.'%']);
                    });
            });
        }

        return $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (SupportKeyingGuideProduct $product): array => $this->guideProductPayload($product))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function legacyGuideProductList(string $search): array
    {
        $items = $this->legacyGuideProductGroups()
            ->map(fn ($entries): array => $this->legacyGuideProductPayload($entries));

        if ($search !== '') {
            $needle = $this->normalizedText($search);
            $items = $items->filter(function (array $item) use ($needle): bool {
                $haystack = $this->normalizedText(json_encode($item, JSON_UNESCAPED_UNICODE) ?: '');

                return str_contains($haystack, $needle);
            });
        }

        return $items->values()->all();
    }

    private function legacyGuideProductGroups()
    {
        return SupportGuideEntry::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (SupportGuideEntry $entry): string => $this->legacyProductGroupKey($entry))
            ->values();
    }

    private function legacyProductGroupKey(SupportGuideEntry $entry): string
    {
        $devices = $this->normalizedList($entry->devices ?? []);

        if ($devices === []) {
            return 'entry-'.$entry->id;
        }

        sort($devices);

        return md5(implode('|', $devices));
    }

    private function legacyProductEntries(string $productId)
    {
        $entryId = $this->legacyIdNumber($productId, 'legacy-product-');
        $anchor = SupportGuideEntry::query()->findOrFail($entryId);
        $groupKey = $this->legacyProductGroupKey($anchor);

        return SupportGuideEntry::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (SupportGuideEntry $entry): bool => $this->legacyProductGroupKey($entry) === $groupKey)
            ->values();
    }

    /**
     * @param  mixed  $entries
     * @return array<string, mixed>
     */
    private function legacyGuideProductPayload($entries): array
    {
        $entries = collect($entries)->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
        $first = $entries->first();
        $productId = 'legacy-product-'.$first->id;
        $devices = $this->legacyProductDevices($entries);
        $aliases = $this->legacyProductAliases($entries, $devices);

        return [
            'id' => $productId,
            'source' => 'legacy',
            'product_name' => $this->legacyProductName($entries),
            'search_keywords' => $aliases === [] ? null : implode("\n", $aliases),
            'is_active' => $entries->contains(fn (SupportGuideEntry $entry): bool => (bool) $entry->is_active),
            'sort_order' => (int) $entries->min('sort_order'),
            'steps' => $entries
                ->map(fn (SupportGuideEntry $entry): array => $this->legacyGuideStepPayload($entry, $productId))
                ->values()
                ->all(),
        ];
    }

    private function saveLegacyGuideProduct(string $productId, array $data): array
    {
        $entries = $this->legacyProductEntries($productId);
        $currentName = $this->legacyProductName($entries);
        $requestedName = $this->nullableText($data['product_name'] ?? null) ?? $currentName;
        $devices = $requestedName === $currentName
            ? $this->legacyProductDevices($entries)
            : [$requestedName];
        $aliases = $this->keywords($data['search_keywords'] ?? null);
        $sortOrder = array_key_exists('sort_order', $data)
            ? (int) $data['sort_order']
            : (int) ($entries->min('sort_order') ?? 100);
        $isActive = (bool) ($data['is_active'] ?? true);

        foreach ($entries as $index => $entry) {
            $sections = $entry->sections ?? [];
            $entry->devices = $devices;
            $entry->device_aliases = $aliases;
            $entry->is_active = $isActive;
            $entry->sort_order = $sortOrder + $index;
            $entry->search_text = $this->guideEntrySearchText(
                $entry->code,
                $devices,
                $aliases,
                $entry->method,
                $entry->guide_type,
                $sections,
            );
            $entry->save();
        }

        return ['item' => $this->legacyGuideProductPayload($this->legacyProductEntries($productId))];
    }

    /**
     * @param  SupportKeyingGuideStep|string|int|null  $step
     * @return array<string, mixed>
     */
    private function saveLegacyGuideStep(string $productId, array $data, SupportKeyingGuideStep|string|int|null $step = null): array
    {
        $entries = $this->legacyProductEntries($productId);
        $anchor = $entries->first();
        $devices = $this->legacyProductDevices($entries);
        $aliases = $this->legacyProductAliases($entries, $devices);
        $sectionType = $this->nullableText($data['section_type'] ?? null) ?? 'other';
        $customTitle = $sectionType === 'other'
            ? $this->nullableText($data['custom_title'] ?? null)
            : null;
        $title = $this->guideStepTitle($sectionType, $customTitle);
        $guideType = $this->nullableText($data['entry_format'] ?? null) ?? $title;
        $method = $this->nullableText($data['entry_method'] ?? null);
        $content = $this->nullableText($data['content'] ?? null) ?? '';
        $sections = [[
            'title' => $title,
            'steps' => $this->contentLines($content),
        ]];

        if ($step !== null) {
            if (! $this->isLegacyStepId($step)) {
                abort(404);
            }

            $entry = SupportGuideEntry::query()->findOrFail($this->legacyIdNumber((string) $step, 'legacy-step-'));

            if (! $entries->contains(fn (SupportGuideEntry $candidate): bool => (int) $candidate->id === (int) $entry->id)) {
                abort(404);
            }
        } else {
            $entry = new SupportGuideEntry();
            $entry->code = $this->uniqueGuideEntryCode($devices, $method, $guideType);
            $entry->source_sheet = 'Destek Yönetimi';
            $entry->source_row = null;
            $entry->warnings = [];
            $entry->extra_notes = [];
        }

        $entry->devices = $devices ?: ($anchor->devices ?? []);
        $entry->device_aliases = $aliases;
        $entry->method = $method;
        $entry->guide_type = $guideType;
        $entry->sections = $sections;
        $entry->is_active = (bool) ($data['is_active'] ?? true);
        $entry->sort_order = array_key_exists('sort_order', $data)
            ? (int) $data['sort_order']
            : (int) ($step !== null ? $entry->sort_order : $this->nextLegacyStepSortOrder($entries));
        $entry->search_text = $this->guideEntrySearchText(
            $entry->code,
            $entry->devices ?? [],
            $entry->device_aliases ?? [],
            $entry->method,
            $entry->guide_type,
            $entry->sections ?? [],
        );
        $entry->save();

        $product = $this->legacyGuideProductPayload($this->legacyProductEntries($productId));

        return [
            'item' => $this->legacyGuideStepPayload($entry->fresh(), $product['id']),
            'product' => $product,
        ];
    }

    private function legacyGuideStepPayload(SupportGuideEntry $entry, string $productId): array
    {
        $sectionType = $this->sectionTypeFromGuideType($entry->guide_type);

        return [
            'id' => 'legacy-step-'.$entry->id,
            'source' => 'legacy',
            'product_id' => $productId,
            'section_type' => $sectionType,
            'custom_title' => $sectionType === 'other' ? $entry->guide_type : null,
            'entry_method' => $entry->method,
            'entry_format' => $entry->guide_type,
            'title' => $entry->guide_type,
            'content' => $this->entryContentText($entry),
            'is_active' => (bool) $entry->is_active,
            'sort_order' => (int) $entry->sort_order,
        ];
    }

    private function legacyProductName($entries): string
    {
        $devices = $this->legacyProductDevices($entries);

        return $devices === [] ? 'Rehber' : implode(' / ', $devices);
    }

    private function legacyProductDevices($entries): array
    {
        return collect($entries)
            ->flatMap(fn (SupportGuideEntry $entry): array => $entry->devices ?? [])
            ->map(fn ($device): string => trim((string) $device))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function legacyProductAliases($entries, array $devices): array
    {
        $deviceKeys = collect($devices)
            ->map(fn (string $device): string => $this->normalizedText($device))
            ->all();

        return collect($entries)
            ->flatMap(fn (SupportGuideEntry $entry): array => $entry->device_aliases ?? [])
            ->map(fn ($alias): string => trim((string) $alias))
            ->filter()
            ->reject(fn (string $alias): bool => in_array($this->normalizedText($alias), $deviceKeys, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function guideProductPayload(SupportKeyingGuideProduct $product): array
    {
        return [
            'id' => $product->id,
            'source' => 'managed',
            'product_name' => $product->product_name,
            'search_keywords' => $product->search_keywords,
            'is_active' => (bool) $product->is_active,
            'sort_order' => (int) $product->sort_order,
            'steps' => $product->steps
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->map(fn (SupportKeyingGuideStep $step): array => $this->guideStepPayload($step))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function guideStepPayload(SupportKeyingGuideStep $step): array
    {
        return [
            'id' => $step->id,
            'source' => 'managed',
            'product_id' => $step->product_id,
            'section_type' => $step->section_type,
            'custom_title' => $step->custom_title,
            'entry_method' => $step->entry_method,
            'entry_format' => $step->entry_format,
            'title' => $step->title,
            'content' => $step->content,
            'is_active' => (bool) $step->is_active,
            'sort_order' => (int) $step->sort_order,
        ];
    }

    private function guideStepTitle(string $sectionType, ?string $customTitle): string
    {
        return match ($sectionType) {
            'pairing' => 'Cihaz Eşleme',
            'fingerprint' => 'Parmak İzi Ekleme',
            'pin' => 'Pin Ekleme',
            'card' => 'Kart Ekleme',
            'remote' => 'Kumanda Ekleme',
            'reset' => 'Resetleme',
            default => $customTitle ?: 'Diğer',
        };
    }

    private function nextManagedProductSortOrder(): int
    {
        return ((int) SupportKeyingGuideProduct::query()->max('sort_order')) + 10;
    }

    private function nextManagedStepSortOrder(SupportKeyingGuideProduct $product): int
    {
        return ((int) SupportKeyingGuideStep::query()
            ->where('product_id', $product->id)
            ->max('sort_order')) + 10;
    }

    private function nextLegacyStepSortOrder($entries): int
    {
        return ((int) collect($entries)->max('sort_order')) + 10;
    }

    private function isLegacyProductId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^legacy-product-\d+$/', $value) === 1;
    }

    private function isLegacyStepId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^legacy-step-\d+$/', $value) === 1;
    }

    private function legacyIdNumber(string $value, string $prefix): int
    {
        if (! str_starts_with($value, $prefix)) {
            abort(404);
        }

        $id = substr($value, strlen($prefix));

        if (! ctype_digit($id)) {
            abort(404);
        }

        return (int) $id;
    }

    /**
     * @return list<string>
     */
    private function keywords(mixed $value): array
    {
        return collect(preg_split('/[\r\n,;]+/', (string) $value) ?: [])
            ->map(fn (string $keyword): string => trim($keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function contentLines(string $content): array
    {
        return collect(preg_split('/\r\n|\n|\r/', $content) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    private function entryContentText(SupportGuideEntry $entry): string
    {
        return collect($entry->sections ?? [])
            ->flatMap(function (array $section): array {
                $lines = [];

                foreach (($section['steps'] ?? []) as $step) {
                    $line = $this->nullableText($step);

                    if ($line) {
                        $lines[] = $line;
                    }
                }

                return $lines;
            })
            ->values()
            ->implode("\n");
    }

    private function sectionTypeFromGuideType(?string $guideType): string
    {
        $normalized = $this->normalizedText((string) $guideType);

        return match (true) {
            str_contains($normalized, 'cihaz esleme') => 'pairing',
            str_contains($normalized, 'parmak izi') => 'fingerprint',
            str_contains($normalized, 'pin') || str_contains($normalized, 'sifre') || str_contains($normalized, 'parola') => 'pin',
            str_contains($normalized, 'kart') => 'card',
            str_contains($normalized, 'kumanda') => 'remote',
            str_contains($normalized, 'reset') || str_contains($normalized, 'sifirlama') => 'reset',
            default => 'other',
        };
    }

    private function uniqueGuideEntryCode(array $devices, ?string $method, string $guideType): string
    {
        $base = Str::slug(collect($devices)->push($method)->push($guideType)->filter()->implode(' '));
        $base = $base !== '' ? 'support-management-'.$base : 'support-management-guide';
        $code = $base;
        $counter = 1;

        while (SupportGuideEntry::query()->where('code', $code)->exists()) {
            $counter++;
            $code = $base.'-'.$counter;
        }

        return $code;
    }

    private function guideEntrySearchText(
        string $code,
        array $devices,
        array $aliases,
        ?string $method,
        string $guideType,
        array $sections,
    ): string {
        $sectionText = collect($sections)
            ->flatMap(function (array $section): array {
                return collect([$section['title'] ?? null])
                    ->merge($section['steps'] ?? [])
                    ->filter()
                    ->values()
                    ->all();
            })
            ->all();

        return collect([$code])
            ->merge($devices)
            ->merge($aliases)
            ->push($method)
            ->push($guideType)
            ->merge($sectionText)
            ->filter(fn ($value): bool => $this->nullableText($value) !== null)
            ->implode(' ');
    }

    /**
     * @return list<string>
     */
    private function normalizedList(array $values): array
    {
        return collect($values)
            ->map(fn ($value): string => $this->normalizedText((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizedText(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    private function normalizedImportSource(string $source, ?string $filename): string
    {
        $source = strtolower(trim($source));
        $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));

        if ($source === 'xlsx' || $extension === 'xlsx') {
            return 'xlsx';
        }

        return $source === 'paste' ? 'paste' : 'csv';
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
