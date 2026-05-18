<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceRequestSerial;
use Illuminate\Support\Str;

class MikroInvoiceSerialsService
{
    private const RESPONSIBILITY_CODE_BLOCKED = [
        'PROJE',
        'BAYI SATIS',
        'CILINGIR SATISLARI',
        'CILINIGIR SATISLARI',
        'GR',
    ];

    public function __construct(
        private readonly ?string $mode = null,
        private readonly ?string $fixturePath = null,
    ) {
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,all_invoice_serials:array<int,array<string,mixed>>,selectable_customer_serials:array<int,array<string,mixed>>,returned_serials:array<int,array<string,mixed>>,meta:array<string,mixed>,request:array<string,mixed>}
     */
    public function forSerial(string $serialNo): array
    {
        $serialNo = trim($serialNo);

        if ($this->invoiceSerialsMode() === 'fixture') {
            $rows = $this->normalizeRows($this->fixtureRowsForSerial($serialNo), $serialNo);

            return [
                'rows' => $rows,
                'all_invoice_serials' => $rows,
                'selectable_customer_serials' => array_values(array_filter(
                    $rows,
                    fn (array $row): bool => (bool) ($row['customer_selectable'] ?? false),
                )),
                'returned_serials' => array_values(array_filter(
                    $rows,
                    fn (array $row): bool => (bool) ($row['is_returned'] ?? false),
                )),
                'meta' => [
                    'status' => 'fixture',
                    'message' => 'Fatura seri kontrolÃ¼ local fixture datasÄ±ndan okundu.',
                    'fixture_path' => 'database/data/technical_service_invoice_serials_fixture.json',
                ],
                'request' => [
                    'serial_no' => $serialNo,
                ],
            ];
        }

        $rows = $this->normalizeRows([], $serialNo);

        return [
            'rows' => $rows,
            'all_invoice_serials' => $rows,
            'selectable_customer_serials' => [],
            'returned_serials' => [],
            'meta' => [
                'status' => 'review_required',
                'message' => 'Fatura seri kontrolü ayrı review paketindeki bağlantı onayından sonra aktif olur.',
            ],
            'request' => [
                'serial_no' => $serialNo,
            ],
        ];
    }

    private function invoiceSerialsMode(): string
    {
        $mode = strtolower(trim((string) (
            $this->mode ?? config('services.technical_service.invoice_serials_mode', 'disabled')
        )));

        return in_array($mode, ['disabled', 'fixture'], true) ? $mode : 'disabled';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fixtureRowsForSerial(string $serialNo): array
    {
        $path = $this->fixturePath ?? database_path('data/technical_service_invoice_serials_fixture.json');

        if (! is_file($path)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (! is_array($payload)) {
            return [];
        }

        $groups = $payload['invoice_groups'] ?? [];
        if (! is_array($groups)) {
            return [];
        }

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $rows = $group['rows'] ?? [];
            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $fixtureSerial = $this->firstText($row, [
                    'Faturadaki Seri No',
                    'faturadaki_seri_no',
                    'serial_number',
                ]);

                if ($this->sameSerial($fixtureSerial, $serialNo)) {
                    return array_values(array_filter($rows, 'is_array'));
                }
            }
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function normalizeRows(array $rows, string $searchedSerial): array
    {
        $searchedSerial = trim($searchedSerial);

        return array_values(array_map(
            fn (array $row): array => $this->normalizeRow($row, $searchedSerial),
            $rows,
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, string $searchedSerial): array
    {
        $serialNumber = $this->firstText($row, [
            'Faturadaki Seri No',
            'Faturadaki SeriNo',
            'faturadaki_seri_no',
            'serial_number',
            'SeriNo',
            'Seri No',
        ]);
        $productName = $this->firstText($row, ['Stok Adı', 'Stok Adi', 'stok_adi', 'stock_name', 'product_name']);
        $stockCode = $this->firstText($row, ['Stok Kodu', 'stok_kodu', 'stock_code']);
        $model = $this->firstText($row, ['Model Adı', 'Model Adi', 'model_adi', 'model_name', 'product_model'])
            ?? $this->deriveModel($productName);
        $brand = $this->firstText($row, ['Marka Kodu', 'marka_kodu', 'brand', 'brand_code'])
            ?? $this->deriveBrand($productName);
        $returnNote = $this->firstText($row, ['İade Notu', 'Iade Notu', 'iade_notu', 'return_note']);
        $returnDate = $this->firstText($row, ['İade Tarihi', 'Iade Tarihi', 'iade_tarihi', 'return_date']);
        $returnSeries = $this->firstText($row, ['İade Evrak Seri', 'Iade Evrak Seri', 'iade_evrak_seri', 'return_document_series']);
        $returnNumber = $this->firstText($row, ['İade Evrak Sıra', 'Iade Evrak Sira', 'iade_evrak_sira', 'return_document_number']);
        $latestSaleText = $this->firstText($row, [
            'Bu Fatura Bu Seri İçin Son Satış mı',
            'Bu Fatura Bu Seri Icin Son Satis mi',
            'bu_fatura_bu_seri_icin_son_satis_mi',
            'is_current_latest_sale',
        ]);

        $isReturned = $returnNote !== null || $returnDate !== null;
        $isCurrentLatestSale = $this->latestSaleFlag($latestSaleText);
        $isPrimary = $this->sameSerial($serialNumber, $searchedSerial);
        $latestSaleConflict = $isPrimary && $isCurrentLatestSale === false;
        $invoiceCustomerType = $this->invoiceCustomerType($row);
        $responsibilityCode = $this->extractResponsibilityCode($row);
        $normalizedResponsibilityCode = $this->normalizeResponsibilityCode($responsibilityCode);
        $isResponsibilityBlocked = $this->isResponsibilityCodeBlocked($normalizedResponsibilityCode);
        $responsibilityCodeLabel = $this->responsibilityCodeLabel($responsibilityCode);
        $customerSelectable = ! $isReturned && ! $isResponsibilityBlocked;
        $hiddenReason = $this->hiddenReason($isReturned, $isResponsibilityBlocked);
        $warningLabels = $this->warningLabels(
            $isCurrentLatestSale,
            $latestSaleConflict,
            $isResponsibilityBlocked,
            $responsibilityCodeLabel,
        );
        $sourcePayload = [
            ...$row,
            'current_latest_sale_date' => $this->firstText($row, ['Serinin Güncel Son Satış Tarihi', 'Serinin Guncel Son Satis Tarihi']),
            'current_latest_sale_invoice_series' => $this->firstText($row, ['Serinin Güncel Son Satış Evrak Seri', 'Serinin Guncel Son Satis Evrak Seri']),
            'current_latest_sale_invoice_number' => $this->firstText($row, ['Serinin Güncel Son Satış Evrak Sıra', 'Serinin Guncel Son Satis Evrak Sira']),
            'latest_sale_conflict' => $latestSaleConflict,
            'operation_warning' => $warningLabels[0] ?? null,
            'warning_labels' => $warningLabels,
            'responsibility_code' => $responsibilityCode,
            'normalized_responsibility_code' => $normalizedResponsibilityCode,
            'is_responsibility_blocked' => $isResponsibilityBlocked,
        ];

        return [
            'serial_number' => $serialNumber,
            'serial_status' => $this->firstText($row, ['Seri Durumu', 'seri_durumu', 'serial_status']),
            'product_name' => $productName,
            'product_model' => $model,
            'brand' => $brand,
            'stock_code' => $stockCode,
            'invoice_series' => $this->firstText($row, ['Satış Evrak Seri', 'Satis Evrak Seri', 'satis_evrak_seri', 'invoice_series']),
            'invoice_number' => $this->firstText($row, ['Satış Evrak Sıra', 'Satis Evrak Sira', 'satis_evrak_sira', 'invoice_number']),
            'sale_date' => $this->firstText($row, ['Satış Tarihi', 'Satis Tarihi', 'satis_tarihi', 'sale_date']),
            'sales_customer_code' => $this->firstText($row, ['Satış Cari Kodu', 'Satis Cari Kodu', 'satis_cari_kodu']),
            'sales_customer_name' => $this->firstText($row, ['Satış Cari Adı', 'Satis Cari Adi', 'satis_cari_adi']),
            'sales_customer_group_code' => $this->firstText($row, ['Satış Cari Grup Kodu', 'Satis Cari Grup Kodu', 'satis_cari_grup_kodu']),
            'sales_customer_group_name' => $this->firstText($row, ['Satış Cari Grup Adı', 'Satis Cari Grup Adi', 'satis_cari_grup_adi']),
            'is_primary' => $isPrimary,
            'is_returned' => $isReturned,
            'return_note' => $returnNote,
            'return_date' => $returnDate,
            'return_document_no' => $this->documentNo($returnSeries, $returnNumber),
            'is_current_latest_sale' => $isCurrentLatestSale,
            'latest_sale_conflict' => $latestSaleConflict,
            'current_latest_sale_date' => $this->firstText($row, ['Serinin Güncel Son Satış Tarihi', 'Serinin Guncel Son Satis Tarihi']),
            'current_latest_sale_invoice_series' => $this->firstText($row, ['Serinin Güncel Son Satış Evrak Seri', 'Serinin Guncel Son Satis Evrak Seri']),
            'current_latest_sale_invoice_number' => $this->firstText($row, ['Serinin Güncel Son Satış Evrak Sıra', 'Serinin Guncel Son Satis Evrak Sira']),
            'current_latest_sale_customer_code' => $this->firstText($row, ['Serinin Güncel Son Satış Cari Kodu', 'Serinin Guncel Son Satis Cari Kodu']),
            'current_latest_sale_customer_name' => $this->firstText($row, ['Serinin Güncel Son Satış Cari Adı', 'Serinin Guncel Son Satis Cari Adi']),
            'invoice_customer_type' => $invoiceCustomerType,
            'responsibility_code' => $responsibilityCode,
            'normalized_responsibility_code' => $normalizedResponsibilityCode,
            'is_responsibility_blocked' => $isResponsibilityBlocked,
            'customer_selectable' => $customerSelectable,
            'customer_visible' => $customerSelectable,
            'hidden_reason' => $hiddenReason,
            'operation_warning' => $warningLabels[0] ?? null,
            'warning_labels' => $warningLabels,
            'color_status' => $isReturned ? 'red' : 'orange',
            'source_payload' => $sourcePayload,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function firstText(array $row, array $keys): ?string
    {
        $tokenized = [];

        foreach ($row as $key => $value) {
            $tokenized[$this->keyToken((string) $key)] = $value;
        }

        foreach ($keys as $key) {
            $value = $this->nullableText($row[$key] ?? null);

            if ($value !== null) {
                return $value;
            }

            $value = $this->nullableText($tokenized[$this->keyToken($key)] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function keyToken(string $key): string
    {
        return Str::of($key)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function invoiceCustomerType(array $row): string
    {
        $text = $this->customerSignalText($row);

        if ($text === '') {
            return TechnicalServiceRequestSerial::CUSTOMER_UNKNOWN;
        }

        if ($this->isDealerProjectPartner($row)) {
            return TechnicalServiceRequestSerial::CUSTOMER_DEALER_OR_PARTNER;
        }

        if (preg_match('/\b120\.(0[1-9]|16)\b/', $text) === 1) {
            return TechnicalServiceRequestSerial::CUSTOMER_DIRECT;
        }

        foreach ([
            'DIRECT',
            'PERAKENDE',
            'BIREYSEL',
            'SON MUSTERI',
            'MUSTERI',
            'PERAKENDE MUSTERI',
            'SON KULLANICI',
            'NIHAI',
            'FINAL CUSTOMER',
            'NIHAI MUSTERI',
            'ONLINE',
            'ON LINE',
            'WEB',
            'ETICARET',
            'E TICARET',
            'E-TICARET',
            'MAGAZA',
            'PAZARYERI',
            'PAZAR YERI',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return TechnicalServiceRequestSerial::CUSTOMER_DIRECT;
            }
        }

        return TechnicalServiceRequestSerial::CUSTOMER_UNKNOWN;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isDealerProjectPartner(array $row): bool
    {
        $text = $this->customerSignalText($row);

        if ($text === '') {
            return false;
        }

        return preg_match('/\b(BAYI|BAYII|DEALER|PROJE|PROJECT|PARTNER|TOPTAN|KURUMSAL|DISTRIBUTOR|B2B)\b/', $text) === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function customerSignalText(array $row): string
    {
        $sourcePayload = $row['source_payload'] ?? [];
        $lookupRow = is_array($sourcePayload) ? array_merge($sourcePayload, $row) : $row;
        $values = [
            $this->firstText($lookupRow, ['invoice_customer_type']),
            $this->firstText($lookupRow, ['Cari Grup Kodu', 'cari_grup_kodu']),
            $this->firstText($lookupRow, ['Cari Grup Adı', 'Cari Grup Adi', 'cari_grup_adi']),
            $this->firstText($lookupRow, ['Satış Cari Grup Kodu', 'Satis Cari Grup Kodu', 'satis_cari_grup_kodu']),
            $this->firstText($lookupRow, ['Satış Cari Grup Adı', 'Satis Cari Grup Adi', 'satis_cari_grup_adi']),
            $this->firstText($lookupRow, ['Cari Kodu', 'cari_kodu']),
            $this->firstText($lookupRow, ['Cari Adı', 'Cari Adi', 'cari_adi']),
            $this->firstText($lookupRow, ['Satış Cari Kodu', 'Satis Cari Kodu', 'satis_cari_kodu']),
            $this->firstText($lookupRow, ['Satış Cari Adı', 'Satis Cari Adi', 'satis_cari_adi']),
            $this->firstText($lookupRow, ['Sorumluluk Kodu', 'sorumluluk_kodu']),
        ];

        return Str::of(implode(' ', array_filter($values)))
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9.]+/', ' ')
            ->squish()
            ->value();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractResponsibilityCode(array $row): ?string
    {
        $keys = [
            'Sorumluluk Kodu',
            'SorumlulukKodu',
            'sorumluluk_kodu',
            'sorumlulukKodu',
            'sorumluluk_kodu_1',
            'Sorumluluk Kodu 1',
        ];

        foreach ($keys as $key) {
            $value = $this->nullableText($row[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        $sourcePayload = $row['source_payload'] ?? [];
        if (! is_array($sourcePayload)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $this->nullableText($sourcePayload[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeResponsibilityCode(?string $value): ?string
    {
        $value = $this->nullableText($value);
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtoupper(trim($value), 'UTF-8');
        $normalized = strtr($normalized, [
            "\u{0130}" => 'I',
            "\u{0131}" => 'I',
            "\u{011E}" => 'G',
            "\u{011F}" => 'G',
            "\u{015E}" => 'S',
            "\u{015F}" => 'S',
            "\u{00C7}" => 'C',
            "\u{00E7}" => 'C',
            "\u{00DC}" => 'U',
            "\u{00FC}" => 'U',
            "\u{00D6}" => 'O',
            "\u{00F6}" => 'O',
        ]);

        return Str::of($normalized)
            ->ascii()
            ->replaceMatches('/[^A-Z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    private function isResponsibilityCodeBlocked(?string $normalizedResponsibilityCode): bool
    {
        if ($normalizedResponsibilityCode === null || $normalizedResponsibilityCode === '') {
            return true;
        }

        return in_array($normalizedResponsibilityCode, self::RESPONSIBILITY_CODE_BLOCKED, true);
    }

    private function responsibilityCodeLabel(?string $responsibilityCode): string
    {
        return $this->nullableText($responsibilityCode) ?? 'Boş';
    }

    private function hiddenReason(bool $isReturned, bool $isResponsibilityBlocked): ?string
    {
        if ($isReturned) {
            return 'returned';
        }

        if ($isResponsibilityBlocked) {
            return 'responsibility_code_blocked';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function warningLabels(
        ?bool $isCurrentLatestSale,
        bool $latestSaleConflict,
        bool $isResponsibilityBlocked,
        string $responsibilityCodeLabel,
    ): array {
        $labels = [];

        if ($isResponsibilityBlocked) {
            $labels[] = 'Müşteriye gösterilmedi - sorumluluk kodu: '.$responsibilityCodeLabel;
        }

        if ($latestSaleConflict) {
            $labels[] = 'Son satış kontrolü çelişkili';
        } elseif ($isCurrentLatestSale === false) {
            $labels[] = 'Güncel son satış değil';
        }

        return $labels;
    }

    private function deriveBrand(?string $productName): ?string
    {
        $value = mb_strtoupper((string) $productName, 'UTF-8');

        if (str_contains($value, 'PHILIPS') || preg_match('/\bDDL[0-9A-Z-]*/u', $value)) {
            return 'PHILIPS';
        }

        if (
            str_contains($value, 'EMAKS PRIME')
            || str_contains($value, 'GALAXY')
            || str_contains($value, 'ALPHA')
            || preg_match('/\bE(?:10B?|20B?)\b/u', $value)
        ) {
            return 'EMAKS PRIME';
        }

        return null;
    }

    private function deriveModel(?string $productName): ?string
    {
        $value = trim((string) $productName);

        if ($value === '') {
            return null;
        }

        if (preg_match('/\b(DDL[0-9A-Z]+(?:-[0-9A-Z]+)*)\b/iu', $value, $matches)) {
            return mb_strtoupper($matches[1], 'UTF-8');
        }

        if (preg_match('/\b(E(?:10B?|20B?))\b/iu', $value, $matches)) {
            return mb_strtoupper($matches[1], 'UTF-8');
        }

        if (preg_match('/\b(ALPHA-[0-9A-Z]+)\b/iu', $value, $matches)) {
            return mb_strtoupper($matches[1], 'UTF-8');
        }

        if (preg_match('/\b(GALAXY\s*\d+)\b/iu', $value, $matches)) {
            $model = preg_replace('/\s+/u', ' ', mb_strtoupper($matches[1], 'UTF-8')) ?? mb_strtoupper($matches[1], 'UTF-8');
            $color = $this->firstColor($value);

            return $color ? "{$model} - {$color}" : $model;
        }

        return null;
    }

    private function firstColor(string $value): ?string
    {
        $upper = mb_strtoupper($value, 'UTF-8');
        $colors = [
            'GRİ' => ['GRİ', 'GRI', 'GREY', 'GRAY'],
            'SİYAH' => ['SİYAH', 'SIYAH', 'BLACK'],
            'BEYAZ' => ['BEYAZ', 'WHITE'],
            'ALTIN' => ['ALTIN', 'GOLD'],
            'GÜMÜŞ' => ['GÜMÜŞ', 'GUMUS', 'SILVER'],
        ];

        foreach ($colors as $normalized => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($upper, $needle)) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private function latestSaleFlag(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        $normalized = Str::of($value)->ascii()->lower()->trim()->value();

        if (in_array($normalized, ['1', 'true', 'yes', 'evet'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'hayir'], true)) {
            return false;
        }

        return null;
    }

    private function sameSerial(?string $left, string $right): bool
    {
        return Str::of((string) $left)->trim()->upper()->value() === Str::of($right)->trim()->upper()->value();
    }

    private function documentNo(?string $series, ?string $number): ?string
    {
        $parts = array_values(array_filter([$series, $number], fn (?string $value): bool => $this->nullableText($value) !== null));

        return $parts === [] ? null : implode('/', $parts);
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
