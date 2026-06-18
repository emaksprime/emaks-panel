<?php

namespace App\Services\TechnicalService;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class LocksmithXlsxReader
{
    /**
     * @return array<int, string>
     */
    public function sheetNames(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Excel dosyası bulunamadı: {$path}");
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Excel dosyası açılamadı: {$path}");
        }

        try {
            $workbookXml = $zip->getFromName('xl/workbook.xml');

            if (! is_string($workbookXml)) {
                throw new RuntimeException('Excel workbook metadata okunamadı.');
            }

            $workbook = new SimpleXMLElement($workbookXml);
            $sheets = $workbook->xpath('//*[local-name()="sheet"]') ?: [];

            return array_values(array_filter(array_map(
                fn (SimpleXMLElement $sheet): string => (string) $sheet['name'],
                $sheets,
            )));
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    public function rawRows(string $path, ?string $sheetName = null): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Excel dosyası bulunamadı: {$path}");
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Excel dosyası açılamadı: {$path}");
        }

        try {
            $sheetPath = $this->worksheetPath($zip, $sheetName ?? $this->defaultSheetName($zip));
            $sharedStrings = $this->sharedStrings($zip);
            $sheetXml = $zip->getFromName($sheetPath);

            if (! is_string($sheetXml)) {
                throw new RuntimeException('Excel sheet okunamadı.');
            }

            return $this->worksheetRows($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    public function rows(string $path, string $sheetName): array
    {
        $rows = $this->rawRows($path, $sheetName);

        if ($rows === []) {
            return [];
        }

        $headerRowIndex = $this->headerRowIndex($rows);
        $headers = array_map(
            fn (?string $value): string => trim((string) $value),
            $rows[$headerRowIndex],
        );
        $rows = array_slice($rows, $headerRowIndex + 1);

        $items = [];
        foreach ($rows as $row) {
            $item = [];
            $hasValue = false;

            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $value = $row[$index] ?? null;
                $value = is_string($value) ? trim($value) : $value;
                $item[$header] = $value === '' ? null : $value;
                $hasValue = $hasValue || $item[$header] !== null;
            }

            if ($hasValue) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<int, array<int, string|null>> $rows
     */
    private function headerRowIndex(array $rows): int
    {
        foreach ($rows as $index => $row) {
            $headers = array_map(fn (?string $value): string => $this->normalizeHeader($value), $row);
            $matches = count(array_intersect($headers, [
                'plaka kodu',
                'sehir',
                'isim soyisim',
                'telefon 90 format',
                'durum',
            ]));

            if ($matches >= 3) {
                return $index;
            }
        }

        return 0;
    }

    private function normalizeHeader(?string $value): string
    {
        $normalized = strtr((string) $value, [
            'Ç' => 'C',
            'Ğ' => 'G',
            'İ' => 'I',
            'Ö' => 'O',
            'Ş' => 'S',
            'Ü' => 'U',
            'ç' => 'c',
            'ğ' => 'g',
            'ı' => 'i',
            'i' => 'i',
            'ö' => 'o',
            'ş' => 's',
            'ü' => 'u',
        ]);
        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    private function worksheetPath(ZipArchive $zip, string $sheetName): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (! is_string($workbookXml) || ! is_string($relsXml)) {
            throw new RuntimeException('Excel workbook metadata okunamadı.');
        }

        $workbook = new SimpleXMLElement($workbookXml);
        $sheets = $workbook->xpath('//*[local-name()="sheet"]') ?: [];
        $relationshipId = null;

        foreach ($sheets as $sheet) {
            if ((string) $sheet['name'] !== $sheetName) {
                continue;
            }

            $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationshipId = (string) ($attributes['id'] ?? '');
            break;
        }

        if ($relationshipId === null || $relationshipId === '') {
            throw new RuntimeException("Excel sheet bulunamadı: {$sheetName}");
        }

        $rels = new SimpleXMLElement($relsXml);
        $relationships = $rels->xpath('//*[local-name()="Relationship"]') ?: [];

        foreach ($relationships as $relationship) {
            if ((string) $relationship['Id'] !== $relationshipId) {
                continue;
            }

            $target = ltrim((string) $relationship['Target'], '/');

            return str_starts_with($target, 'xl/')
                ? $target
                : 'xl/'.$target;
        }

        throw new RuntimeException("Excel sheet ilişki kaydı bulunamadı: {$sheetName}");
    }

    private function defaultSheetName(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');

        if (! is_string($workbookXml)) {
            throw new RuntimeException('Excel workbook metadata okunamadı.');
        }

        $workbook = new SimpleXMLElement($workbookXml);
        $sheets = $workbook->xpath('//*[local-name()="sheet"]') ?: [];
        $names = array_values(array_filter(array_map(
            fn (SimpleXMLElement $sheet): string => (string) $sheet['name'],
            $sheets,
        )));

        if ($names === []) {
            throw new RuntimeException('Excel dosyasında sheet bulunamadı.');
        }

        foreach ($names as $name) {
            if (mb_strtolower($name, 'UTF-8') === mb_strtolower(LocksmithImportService::SHEET_NAME, 'UTF-8')) {
                return $name;
            }
        }

        return $names[0];
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml)) {
            return [];
        }

        $shared = new SimpleXMLElement($xml);
        $items = $shared->xpath('//*[local-name()="si"]') ?: [];
        $strings = [];

        foreach ($items as $item) {
            $strings[] = $this->flattenText($item);
        }

        return $strings;
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, array<int, string|null>>
     */
    private function worksheetRows(string $xml, array $sharedStrings): array
    {
        $worksheet = new SimpleXMLElement($xml);
        $xmlRows = $worksheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];
        $rows = [];

        foreach ($xmlRows as $xmlRow) {
            $row = [];
            $cells = $xmlRow->xpath('./*[local-name()="c"]') ?: [];

            foreach ($cells as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $index = $this->cellIndex($reference);
                $type = (string) ($cell['t'] ?? '');
                $value = null;

                if ($type === 's') {
                    $sharedIndex = (int) ((string) ($cell->xpath('./*[local-name()="v"]')[0] ?? '0'));
                    $value = $sharedStrings[$sharedIndex] ?? null;
                } elseif ($type === 'inlineStr') {
                    $value = $this->flattenText($cell);
                } else {
                    $nodes = $cell->xpath('./*[local-name()="v"]') ?: [];
                    $value = isset($nodes[0]) ? (string) $nodes[0] : null;
                }

                $row[$index] = $value === '' ? null : $value;
            }

            if ($row !== []) {
                ksort($row);
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function flattenText(SimpleXMLElement $element): string
    {
        $texts = $element->xpath('.//*[local-name()="t"]') ?: [];

        return implode('', array_map(fn (SimpleXMLElement $text): string => (string) $text, $texts));
    }

    private function cellIndex(string $reference): int
    {
        preg_match('/^([A-Z]+)/i', $reference, $matches);
        $letters = strtoupper($matches[1] ?? 'A');
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }
}
