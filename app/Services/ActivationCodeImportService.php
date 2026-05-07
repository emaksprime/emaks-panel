<?php

namespace App\Services;

use App\Models\ActivationCodeRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ActivationCodeImportService
{
    private const CSV_COLUMNS = [
        'STOK_KODU',
        'STOK_ADI',
        'SERI_NO',
    ];

    public function __construct(
        private readonly ActivationCodeSearchService $searchService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $contents = $path ? file_get_contents($path) : false;

        if ($contents === false) {
            return [
                'created_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'errors' => [['row' => 0, 'reason' => 'CSV dosyası okunamadı.', 'data' => []]],
            ];
        }

        $contents = $this->normalizeCsvEncoding($contents);
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $lines = array_values(array_filter($lines, fn ($line) => trim((string) $line) !== ''));
        $delimiter = $this->detectCsvDelimiter($lines[0] ?? '');
        $header = isset($lines[0]) ? str_getcsv($lines[0], $delimiter) : [];
        $columns = array_map(fn ($value) => Str::upper(trim((string) $value)), $header ?: []);

        if ($columns !== self::CSV_COLUMNS) {
            return [
                'created_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'errors' => [[
                    'row' => 1,
                    'reason' => 'CSV başlığı beklenen kolonlarla eşleşmiyor. Beklenen sıra: '.implode(',', self::CSV_COLUMNS),
                    'data' => [
                        'detected_delimiter' => $delimiter,
                        'received_columns' => $columns,
                        'expected_columns' => self::CSV_COLUMNS,
                    ],
                ]],
            ];
        }

        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $errors = [];
        $rowNumber = 1;
        $sourceFileName = $file->getClientOriginalName();

        foreach (array_slice($lines, 1) as $line) {
            $rowNumber++;
            $row = str_getcsv($line, $delimiter);
            $data = array_combine(self::CSV_COLUMNS, array_slice(array_pad($row, count(self::CSV_COLUMNS), ''), 0, count(self::CSV_COLUMNS)));

            if ($data === false) {
                $skippedCount++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'Satır okunamadı.', 'data' => $row];

                continue;
            }

            $serialNo = trim((string) ($data['SERI_NO'] ?? ''));

            if ($serialNo === '') {
                $skippedCount++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'SERI_NO zorunlu.', 'data' => $data];

                continue;
            }

            $payload = $this->searchService->buildRecordPayload(
                $serialNo,
                (string) ($data['STOK_KODU'] ?? ''),
                (string) ($data['STOK_ADI'] ?? ''),
                $sourceFileName,
            );

            $existing = ActivationCodeRecord::query()->where('serial_no', $serialNo)->first();

            if ($existing) {
                $existing->update($payload);
                $updatedCount++;

                continue;
            }

            ActivationCodeRecord::query()->create($payload);
            $createdCount++;
        }

        return [
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'skipped_count' => $skippedCount,
            'errors' => $errors,
            'source_file_name' => $sourceFileName,
        ];
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
}
