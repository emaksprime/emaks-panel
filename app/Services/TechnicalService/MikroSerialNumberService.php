<?php

namespace App\Services\TechnicalService;

use App\Models\DataSource;
use App\Services\N8nPanelDataGateway;
use RuntimeException;

class MikroSerialNumberService
{
    public const SOURCE_SERIAL_CHECK = 'technical_service_serial_check';
    public const SOURCE_SERIAL_HISTORY = 'technical_service_serial_history';
    public const SOURCE_WARRANTY_SERIAL = 'technical_service_warranty_serial';

    public function __construct(
        private readonly N8nPanelDataGateway $gateway,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function checkInstallation(string $serialNo): array
    {
        $serialNo = $this->cleanSerialNo($serialNo);
        $rows = $this->rowsFromGateway(self::SOURCE_SERIAL_CHECK, $serialNo);

        if ($rows === []) {
            $history = $this->history($serialNo);

            return $this->fallbackDecision($serialNo, $history['items'] !== []);
        }

        $result = $this->normalizeDecisionRow($rows[0]);

        if (! $result['found']) {
            return $result;
        }

        $result['history'] = $this->history($serialNo)['items'];

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function history(string $serialNo): array
    {
        $serialNo = $this->cleanSerialNo($serialNo);
        $decisionRows = $this->rowsFromGateway(self::SOURCE_SERIAL_CHECK, $serialNo);
        $historyRows = $this->rowsFromGateway(self::SOURCE_SERIAL_HISTORY, $serialNo);
        $items = array_map(fn (array $row): array => $this->normalizeHistoryRow($row), $historyRows);

        return [
            'serial_no' => $serialNo,
            'decision' => $decisionRows === []
                ? $this->fallbackDecision($serialNo, $items !== [])
                : $this->normalizeDecisionRow($decisionRows[0]),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestValidSale(string $serialNo): ?array
    {
        $serialNo = $this->cleanSerialNo($serialNo);
        $items = array_map(
            fn (array $row): array => $this->normalizeHistoryRow($row),
            $this->rowsFromGateway(self::SOURCE_WARRANTY_SERIAL, $serialNo),
        );
        $latestSale = collect($items)
            ->first(fn ($item) => is_array($item) && ($item['is_latest_valid_sale'] ?? false));

        if (! is_array($latestSale)) {
            return null;
        }

        $installationSignal = collect($items)
            ->first(fn ($item) => is_array($item) && ($item['event_type'] ?? null) === 'sonradan_montaj');
        $date = $latestSale['event_date'] ?? null;
        $customerCode = $latestSale['cari_kodu'] ?? null;
        $customerName = $latestSale['cari_unvani'] ?? null;
        $documentNo = $this->documentNo($latestSale['evrak_seri'] ?? null, $latestSale['evrak_sira'] ?? null);
        $fingerprintParts = [
            $serialNo,
            $date,
            $customerCode,
            $documentNo,
            $latestSale['fatura_sira'] ?? null,
            $latestSale['siparis_seri'] ?? null,
            $latestSale['siparis_sira'] ?? null,
            $latestSale['stok_adi'] ?? null,
        ];

        return [
            'serial_no' => $serialNo,
            'stock_code' => $this->nullableString($latestSale['stok_kodu'] ?? null),
            'stock_name' => $this->nullableString($latestSale['stok_adi'] ?? null),
            'date' => $this->nullableDate($date),
            'customer_code' => $this->nullableString($customerCode),
            'customer_name' => $this->nullableString($customerName),
            'document_type' => 'İrsaliye',
            'document_no' => $documentNo,
            'fingerprint' => hash('sha256', implode('|', array_map(fn ($value) => trim((string) ($value ?? '')), $fingerprintParts))),
            'installation_signal_date' => is_array($installationSignal) ? $this->nullableDate($installationSignal['event_date'] ?? null) : null,
            'installation_signal_source' => is_array($installationSignal) ? $this->nullableString($installationSignal['title'] ?? null) : null,
            'different_customer_installation_warning' => is_array($installationSignal)
                && $this->nullableString($installationSignal['cari_kodu'] ?? null) !== null
                && $this->nullableString($customerCode) !== null
                && $this->nullableString($installationSignal['cari_kodu'] ?? null) !== $this->nullableString($customerCode),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsFromGateway(string $sourceCode, string $serialNo): array
    {
        $dataSource = DataSource::query()->where('code', $sourceCode)->first();

        if (! $dataSource || ! $dataSource->active) {
            throw new RuntimeException("Teknik servis veri kaynağı aktif değil: {$sourceCode}");
        }

        return $this->gateway->run($sourceCode, [
            'serial_no' => $serialNo,
            'bypass_cache' => true,
        ], $dataSource)['rows'];
    }

    private function cleanSerialNo(string $serialNo): string
    {
        return trim($serialNo);
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackDecision(string $serialNo, bool $serialExists): array
    {
        return [
            'found' => $serialExists,
            'montaj_durumu' => $serialExists ? 'Montaj Hariç' : 'Seri No Bulunamadı',
            'montaj_ek_aciklama' => $serialExists
                ? 'Mikro’da seri no bulundu ancak geçerli çıkış/satış hareketi bulunamadı.'
                : 'Mikro’da seri no bulunamadı',
            'cihaz_seri_no' => $serialNo,
            'farkli_cari_uyarisi' => false,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeDecisionRow(array $row): array
    {
        $found = $this->booleanValue($row['found'] ?? true);

        return [
            'found' => $found,
            'montaj_durumu' => (string) ($row['montaj_durumu'] ?? ($found ? 'Montaj Hariç' : 'Seri No Bulunamadı')),
            'montaj_ek_aciklama' => (string) ($row['montaj_ek_aciklama'] ?? ''),
            'cihaz_seri_no' => $this->nullableString($row['cihaz_seri_no'] ?? null),
            'stok_kodu' => $this->nullableString($row['stok_kodu'] ?? null),
            'stok_adi' => $this->nullableString($row['stok_adi'] ?? null),
            'irsaliye_tarihi' => $this->nullableDate($row['irsaliye_tarihi'] ?? null),
            'irsaliye_seri' => $this->nullableString($row['irsaliye_seri'] ?? null),
            'irsaliye_sira' => $this->nullableString($row['irsaliye_sira'] ?? null),
            'fatura_tarihi' => $this->nullableDate($row['fatura_tarihi'] ?? null),
            'fatura_seri' => $this->nullableString($row['fatura_seri'] ?? null),
            'fatura_sira' => $this->nullableString($row['fatura_sira'] ?? null),
            'siparis_tarihi' => $this->nullableDate($row['siparis_tarihi'] ?? null),
            'siparis_seri' => $this->nullableString($row['siparis_seri'] ?? null),
            'siparis_sira' => $this->nullableString($row['siparis_sira'] ?? null),
            'asil_cari_kodu' => $this->nullableString($row['asil_cari_kodu'] ?? null),
            'asil_cari_unvani' => $this->nullableString($row['asil_cari_unvani'] ?? null),
            'sonradan_montaj_kaynagi' => $this->nullableString($row['sonradan_montaj_kaynagi'] ?? null),
            'sonradan_montaj_tarihi' => $this->nullableDate($row['sonradan_montaj_tarihi'] ?? null),
            'sonradan_montaj_aciklamasi' => $this->nullableString($row['sonradan_montaj_aciklamasi'] ?? null),
            'sonradan_montaj_cari_kodu' => $this->nullableString($row['sonradan_montaj_cari_kodu'] ?? null),
            'sonradan_montaj_cari_unvani' => $this->nullableString($row['sonradan_montaj_cari_unvani'] ?? null),
            'farkli_cari_uyarisi' => $this->booleanValue($row['farkli_cari_uyarisi'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeHistoryRow(array $row): array
    {
        return [
            'event_type' => (string) ($row['event_type'] ?? ''),
            'event_date' => $this->nullableDate($row['event_date'] ?? null),
            'title' => (string) ($row['title'] ?? ''),
            'description' => $this->nullableString($row['description'] ?? null),
            'stok_kodu' => $this->nullableString($row['stok_kodu'] ?? null),
            'stok_adi' => $this->nullableString($row['stok_adi'] ?? null),
            'cari_kodu' => $this->nullableString($row['cari_kodu'] ?? null),
            'cari_unvani' => $this->nullableString($row['cari_unvani'] ?? null),
            'evrak_seri' => $this->nullableString($row['evrak_seri'] ?? null),
            'evrak_sira' => $this->nullableString($row['evrak_sira'] ?? null),
            'siparis_seri' => $this->nullableString($row['siparis_seri'] ?? null),
            'siparis_sira' => $this->nullableString($row['siparis_sira'] ?? null),
            'fatura_seri' => $this->nullableString($row['fatura_seri'] ?? null),
            'fatura_sira' => $this->nullableString($row['fatura_sira'] ?? null),
            'hareket_grup_kodu_1' => $this->nullableString($row['hareket_grup_kodu_1'] ?? null),
            'sorumluluk_kodu' => $this->nullableString($row['sorumluluk_kodu'] ?? null),
            'is_latest_valid_sale' => $this->booleanValue($row['is_latest_valid_sale'] ?? false),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = is_string($value) ? $value : (string) $value;

        return substr($value, 0, 10);
    }

    private function documentNo(mixed $series, mixed $number): ?string
    {
        $parts = array_filter([
            $this->nullableString($series),
            $this->nullableString($number),
        ]);

        return $parts === [] ? null : implode('/', $parts);
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'evet'], true);
        }

        return false;
    }
}
