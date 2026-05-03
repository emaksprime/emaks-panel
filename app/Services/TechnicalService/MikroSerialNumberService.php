<?php

namespace App\Services\TechnicalService;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class MikroSerialNumberService
{
    private const CONNECTION = 'mikro_readonly';
    private const REQUIRED_HOST = '10.0.29.120';
    private const REQUIRED_DATABASE = 'MikroDesktop_EMAKS_PRIME';
    private const INSTALLATION_STOCK_CODE = 'W-MONTAJ-1';

    /**
     * @return array<string, mixed>
     */
    public function checkInstallation(string $serialNo): array
    {
        $serialNo = $this->cleanSerialNo($serialNo);
        $this->assertReadOnlyConnection();

        $rows = DB::connection(self::CONNECTION)->select($this->decisionSql(), [
            $serialNo,
            self::INSTALLATION_STOCK_CODE,
        ]);

        if ($rows === []) {
            if ($this->serialExists($serialNo)) {
                return [
                    'found' => true,
                    'montaj_durumu' => 'Montaj Hariç',
                    'montaj_ek_aciklama' => 'Mikro’da seri no bulundu ancak geçerli çıkış/satış hareketi bulunamadı.',
                    'cihaz_seri_no' => $serialNo,
                    'farkli_cari_uyarisi' => false,
                    'history' => $this->history($serialNo)['items'],
                ];
            }

            return [
                'found' => false,
                'montaj_durumu' => 'Seri No Bulunamadı',
                'montaj_ek_aciklama' => 'Mikro’da seri no bulunamadı',
                'cihaz_seri_no' => $serialNo,
                'farkli_cari_uyarisi' => false,
            ];
        }

        $row = (array) $rows[0];
        $result = $this->normalizeDecisionRow($row);

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
        $this->assertReadOnlyConnection();

        $decisionRows = DB::connection(self::CONNECTION)->select($this->decisionSql(), [
            $serialNo,
            self::INSTALLATION_STOCK_CODE,
        ]);
        $decision = $decisionRows === []
            ? ($this->serialExists($serialNo)
                ? [
                    'found' => true,
                    'montaj_durumu' => 'Montaj Hariç',
                    'montaj_ek_aciklama' => 'Mikro’da seri no bulundu ancak geçerli çıkış/satış hareketi bulunamadı.',
                    'cihaz_seri_no' => $serialNo,
                    'farkli_cari_uyarisi' => false,
                ]
                : [
                    'found' => false,
                    'montaj_durumu' => 'Seri No Bulunamadı',
                    'montaj_ek_aciklama' => 'Mikro’da seri no bulunamadı',
                    'cihaz_seri_no' => $serialNo,
                    'farkli_cari_uyarisi' => false,
                ])
            : $this->normalizeDecisionRow((array) $decisionRows[0]);

        $rows = DB::connection(self::CONNECTION)->select($this->historySql(), [
            $serialNo,
            self::INSTALLATION_STOCK_CODE,
        ]);

        return [
            'serial_no' => $serialNo,
            'decision' => $decision,
            'items' => array_map(fn ($row) => $this->normalizeHistoryRow((array) $row), $rows),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestValidSale(string $serialNo): ?array
    {
        $serialNo = $this->cleanSerialNo($serialNo);
        $history = $this->history($serialNo);
        $decision = $history['decision'] ?? [];

        if (! is_array($decision) || ! ($decision['found'] ?? false)) {
            return null;
        }

        $latestSale = collect($history['items'] ?? [])
            ->first(fn ($item) => is_array($item) && ($item['is_latest_valid_sale'] ?? false));

        if (! is_array($latestSale)) {
            return null;
        }

        $date = $latestSale['event_date'] ?? ($decision['irsaliye_tarihi'] ?? null);
        $customerCode = $latestSale['cari_kodu'] ?? ($decision['asil_cari_kodu'] ?? null);
        $customerName = $latestSale['cari_unvani'] ?? ($decision['asil_cari_unvani'] ?? null);
        $documentNo = $this->documentNo(
            $latestSale['evrak_seri'] ?? ($decision['irsaliye_seri'] ?? null),
            $latestSale['evrak_sira'] ?? ($decision['irsaliye_sira'] ?? null),
        );
        $fingerprintParts = [
            $serialNo,
            $date,
            $customerCode,
            $documentNo,
            $latestSale['fatura_sira'] ?? ($decision['fatura_sira'] ?? null),
            $latestSale['siparis_seri'] ?? ($decision['siparis_seri'] ?? null),
            $latestSale['siparis_sira'] ?? ($decision['siparis_sira'] ?? null),
            $latestSale['stok_adi'] ?? ($decision['stok_adi'] ?? null),
        ];

        return [
            'serial_no' => $serialNo,
            'stock_code' => $this->nullableString($decision['stok_kodu'] ?? null),
            'stock_name' => $this->nullableString($latestSale['stok_adi'] ?? ($decision['stok_adi'] ?? null)),
            'date' => $this->nullableDate($date),
            'customer_code' => $this->nullableString($customerCode),
            'customer_name' => $this->nullableString($customerName),
            'document_type' => 'İrsaliye',
            'document_no' => $documentNo,
            'fingerprint' => hash('sha256', implode('|', array_map(fn ($value) => trim((string) ($value ?? '')), $fingerprintParts))),
            'installation_signal_date' => $this->nullableDate($decision['sonradan_montaj_tarihi'] ?? null),
            'installation_signal_source' => $this->nullableString($decision['sonradan_montaj_kaynagi'] ?? null),
            'different_customer_installation_warning' => (bool) ($decision['farkli_cari_uyarisi'] ?? false),
        ];
    }

    private function cleanSerialNo(string $serialNo): string
    {
        return trim($serialNo);
    }

    private function assertReadOnlyConnection(): void
    {
        $connection = config('database.connections.'.self::CONNECTION);

        if (($connection['host'] ?? null) !== self::REQUIRED_HOST) {
            throw new RuntimeException('Mikro read-only bağlantı host değeri 10.0.29.120 olmalıdır.');
        }

        if (($connection['database'] ?? null) !== self::REQUIRED_DATABASE) {
            throw new RuntimeException('Mikro read-only bağlantı database değeri MikroDesktop_EMAKS_PRIME olmalıdır.');
        }
    }

    private function serialExists(string $serialNo): bool
    {
        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT TOP 1 1 AS found FROM CIHAZ_HAREKETLERI WHERE LTRIM(RTRIM(ChHar_SeriNo)) = LTRIM(RTRIM(CAST(? AS NVARCHAR(100))))',
            [$serialNo],
        );

        return $rows !== [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeDecisionRow(array $row): array
    {
        $found = (bool) ($row['found'] ?? true);

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
            'farkli_cari_uyarisi' => (bool) ($row['farkli_cari_uyarisi'] ?? false),
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
            'is_latest_valid_sale' => (bool) ($row['is_latest_valid_sale'] ?? false),
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

        return is_string($value) ? $value : (string) $value;
    }

    private function documentNo(mixed $series, mixed $number): ?string
    {
        $parts = array_filter([
            $this->nullableString($series),
            $this->nullableString($number),
        ]);

        return $parts === [] ? null : implode('/', $parts);
    }

    private function decisionSql(): string
    {
        return <<<'SQL'
WITH query_params AS (
    SELECT
        LTRIM(RTRIM(CAST(? AS NVARCHAR(100)))) AS serial_no,
        LTRIM(RTRIM(CAST(? AS NVARCHAR(50)))) AS installation_stock_code
),
serial_stock_movements AS (
    SELECT
        ch.ChHar_SeriNo AS cihaz_seri_no,
        sh.sth_Guid AS stok_hareket_guid,
        sh.sth_tarih AS hareket_tarihi,
        sh.sth_evraktip AS evrak_tip,
        sh.sth_tip AS hareket_tip,
        sh.sth_cins AS hareket_cins,
        sh.sth_normal_iade AS normal_iade,
        CAST(sh.sth_evrakno_seri AS NVARCHAR(50)) AS irsaliye_seri,
        CAST(sh.sth_evrakno_sira AS NVARCHAR(50)) AS irsaliye_sira,
        sh.sth_belge_tarih AS fatura_tarihi,
        CAST(sh.sth_belge_no AS NVARCHAR(50)) AS fatura_belge_no,
        sh.sth_stok_kod AS stok_kodu,
        sh.sth_cari_kodu AS cari_kodu,
        sh.sth_sip_uid AS siparis_guid,
        sh.sth_fat_uid AS fatura_guid,
        sh.sth_aciklama AS hareket_aciklama,
        s.sto_isim AS stok_adi,
        c.cari_unvan1 AS cari_unvani
    FROM CIHAZ_HAREKETLERI AS ch
    INNER JOIN STOK_HAREKETLERI AS sh ON sh.sth_Guid = ch.ChHar_master_uid
    LEFT JOIN STOKLAR AS s ON s.sto_kod = sh.sth_stok_kod
    LEFT JOIN CARI_HESAPLAR AS c ON c.cari_kod = sh.sth_cari_kodu
    CROSS JOIN query_params AS params
    WHERE LTRIM(RTRIM(ch.ChHar_SeriNo)) = params.serial_no
),
sale_candidates AS (
    SELECT
        *,
        ROW_NUMBER() OVER (
            ORDER BY hareket_tarihi DESC, irsaliye_seri DESC, irsaliye_sira DESC, stok_hareket_guid DESC
        ) AS sale_rank
    FROM serial_stock_movements AS sale
    WHERE hareket_tip = 1
      AND ISNULL(normal_iade, 0) = 0
      AND NOT EXISTS (
          SELECT 1
          FROM serial_stock_movements AS later_return
          WHERE later_return.hareket_tarihi > sale.hareket_tarihi
            AND later_return.hareket_tip = 0
            AND ISNULL(later_return.normal_iade, 0) = 1
            AND NOT EXISTS (
                SELECT 1
                FROM serial_stock_movements AS later_sale
                WHERE later_sale.hareket_tarihi > later_return.hareket_tarihi
                  AND later_sale.hareket_tip = 1
                  AND ISNULL(later_sale.normal_iade, 0) = 0
            )
      )
),
last_sale AS (
    SELECT TOP 1 *
    FROM sale_candidates
    WHERE sale_rank = 1
),
order_installation AS (
    SELECT TOP 1
        sip.sip_tarih AS kaynak_tarihi,
        CAST(sip.sip_evrakno_seri AS NVARCHAR(50)) AS kaynak_seri,
        CAST(sip.sip_evrakno_sira AS NVARCHAR(50)) AS kaynak_sira,
        sip.sip_aciklama AS kaynak_aciklama,
        sip.sip_musteri_kod AS kaynak_cari_kodu,
        cari.cari_unvan1 AS kaynak_cari_unvani
    FROM SIPARISLER AS sip
    INNER JOIN last_sale AS ls ON ls.siparis_guid = sip.sip_Guid
    LEFT JOIN CARI_HESAPLAR AS cari ON cari.cari_kod = sip.sip_musteri_kod
    CROSS JOIN query_params AS params
    WHERE sip.sip_stok_kod = params.installation_stock_code
),
invoice_installation AS (
    SELECT TOP 1
        cha.cha_tarihi AS kaynak_tarihi,
        CAST(cha.cha_evrakno_seri AS NVARCHAR(50)) AS kaynak_seri,
        CAST(cha.cha_evrakno_sira AS NVARCHAR(50)) AS kaynak_sira,
        cha.cha_aciklama AS kaynak_aciklama,
        cha.cha_kod AS kaynak_cari_kodu,
        cari.cari_unvan1 AS kaynak_cari_unvani
    FROM CARI_HESAP_HAREKETLERI AS cha
    INNER JOIN last_sale AS ls ON ls.fatura_guid = cha.cha_Guid
    LEFT JOIN CARI_HESAPLAR AS cari ON cari.cari_kod = cha.cha_kod
    WHERE UPPER(ISNULL(cha.cha_aciklama, '')) LIKE '%MONTAJ%'
),
linked_stock_installation AS (
    SELECT TOP 1
        montaj.sth_tarih AS kaynak_tarihi,
        CAST(montaj.sth_evrakno_seri AS NVARCHAR(50)) AS kaynak_seri,
        CAST(montaj.sth_evrakno_sira AS NVARCHAR(50)) AS kaynak_sira,
        montaj.sth_aciklama AS kaynak_aciklama,
        montaj.sth_cari_kodu AS kaynak_cari_kodu,
        cari.cari_unvan1 AS kaynak_cari_unvani
    FROM STOK_HAREKETLERI AS montaj
    INNER JOIN last_sale AS ls ON ls.fatura_guid = montaj.sth_fat_uid
    LEFT JOIN CARI_HESAPLAR AS cari ON cari.cari_kod = montaj.sth_cari_kodu
    CROSS JOIN query_params AS params
    WHERE montaj.sth_stok_kod = params.installation_stock_code
      AND montaj.sth_tarih >= CONVERT(date, '2026-04-01')
),
later_installation AS (
    SELECT TOP 1 *
    FROM (
        SELECT
            'Sipariş' AS kaynak,
            sip.sip_tarih AS kaynak_tarihi,
            sip.sip_aciklama AS kaynak_aciklama,
            sip.sip_musteri_kod AS kaynak_cari_kodu,
            cari.cari_unvan1 AS kaynak_cari_unvani
        FROM SIPARISLER AS sip
        CROSS JOIN last_sale AS ls
        CROSS JOIN query_params AS params
        LEFT JOIN CARI_HESAPLAR AS cari ON cari.cari_kod = sip.sip_musteri_kod
        WHERE sip.sip_tarih >= ls.hareket_tarihi
          AND sip.sip_stok_kod = params.installation_stock_code
          AND ISNULL(sip.sip_aciklama, '') LIKE '%' + params.serial_no + '%'

        UNION ALL

        SELECT
            'Stok Hareketi' AS kaynak,
            sth.sth_tarih AS kaynak_tarihi,
            sth.sth_aciklama AS kaynak_aciklama,
            sth.sth_cari_kodu AS kaynak_cari_kodu,
            cari.cari_unvan1 AS kaynak_cari_unvani
        FROM STOK_HAREKETLERI AS sth
        CROSS JOIN last_sale AS ls
        CROSS JOIN query_params AS params
        LEFT JOIN CARI_HESAPLAR AS cari ON cari.cari_kod = sth.sth_cari_kodu
        WHERE sth.sth_tarih >= ls.hareket_tarihi
          AND sth.sth_stok_kod = params.installation_stock_code
          AND ISNULL(sth.sth_aciklama, '') LIKE '%' + params.serial_no + '%'

        UNION ALL

        SELECT
            'Cari Hizmet' AS kaynak,
            ISNULL(cha.cha_create_date, cha.cha_tarihi) AS kaynak_tarihi,
            cha.cha_aciklama AS kaynak_aciklama,
            cha.cha_kod AS kaynak_cari_kodu,
            cari.cari_unvan1 AS kaynak_cari_unvani
        FROM CARI_HESAP_HAREKETLERI AS cha
        CROSS JOIN last_sale AS ls
        CROSS JOIN query_params AS params
        LEFT JOIN CARI_HESAPLAR AS cari ON cari.cari_kod = cha.cha_kod
        WHERE ISNULL(cha.cha_create_date, cha.cha_tarihi) > ls.hareket_tarihi
          AND cha.cha_kasa_hizkod LIKE 'MONTAJ%'
          AND ISNULL(cha.cha_aciklama, '') LIKE '%' + params.serial_no + '%'
    ) AS later_rows
    ORDER BY kaynak_tarihi ASC
)
SELECT
    CAST(1 AS bit) AS found,
    CASE
        WHEN ls.hareket_tarihi < CONVERT(date, '2025-07-01') THEN 'Montaj Dahil'
        WHEN EXISTS (SELECT 1 FROM order_installation) THEN 'Montaj Dahil'
        WHEN EXISTS (SELECT 1 FROM invoice_installation) THEN 'Montaj Dahil'
        WHEN EXISTS (SELECT 1 FROM linked_stock_installation) THEN 'Montaj Dahil'
        WHEN EXISTS (SELECT 1 FROM later_installation) THEN 'Montaj Sonradan Dahil'
        ELSE 'Montaj Hariç'
    END AS montaj_durumu,
    CASE
        WHEN ls.hareket_tarihi < CONVERT(date, '2025-07-01') THEN '2025-07-01 öncesi satışlar montaj dahil kabul edilir.'
        WHEN EXISTS (SELECT 1 FROM order_installation) THEN 'Son geçerli satış siparişinde montaj satırı bulundu.'
        WHEN EXISTS (SELECT 1 FROM invoice_installation) THEN 'Son geçerli satış faturasında MONTAJ hizmet satırı bulundu.'
        WHEN EXISTS (SELECT 1 FROM linked_stock_installation) THEN '2026-04-01 sonrası faturaya bağlı W-MONTAJ-1 stok hareketi bulundu.'
        WHEN EXISTS (
            SELECT 1
            FROM later_installation AS later_check
            CROSS JOIN last_sale AS sale_check
            WHERE later_check.kaynak_cari_kodu IS NOT NULL
              AND sale_check.cari_kodu IS NOT NULL
              AND later_check.kaynak_cari_kodu <> sale_check.cari_kodu
        ) THEN 'Farklı Cari ile Sonradan Montaj'
        WHEN EXISTS (SELECT 1 FROM later_installation) THEN 'Son geçerli satıştan sonra seri no açıklamalı montaj kaydı bulundu.'
        ELSE 'Son geçerli satış için Mikro’da montaj ödemesi bulunamadı.'
    END AS montaj_ek_aciklama,
    ls.cihaz_seri_no,
    ls.stok_kodu,
    ls.stok_adi,
    ls.hareket_tarihi AS irsaliye_tarihi,
    ls.irsaliye_seri,
    ls.irsaliye_sira,
    ls.fatura_tarihi,
    NULL AS fatura_seri,
    ls.fatura_belge_no AS fatura_sira,
    sip.sip_tarih AS siparis_tarihi,
    CAST(sip.sip_evrakno_seri AS NVARCHAR(50)) AS siparis_seri,
    CAST(sip.sip_evrakno_sira AS NVARCHAR(50)) AS siparis_sira,
    ls.cari_kodu AS asil_cari_kodu,
    ls.cari_unvani AS asil_cari_unvani,
    later.kaynak AS sonradan_montaj_kaynagi,
    later.kaynak_tarihi AS sonradan_montaj_tarihi,
    later.kaynak_aciklama AS sonradan_montaj_aciklamasi,
    later.kaynak_cari_kodu AS sonradan_montaj_cari_kodu,
    later.kaynak_cari_unvani AS sonradan_montaj_cari_unvani,
    CASE
        WHEN later.kaynak_cari_kodu IS NOT NULL
         AND ls.cari_kodu IS NOT NULL
         AND later.kaynak_cari_kodu <> ls.cari_kodu THEN CAST(1 AS bit)
        ELSE CAST(0 AS bit)
    END AS farkli_cari_uyarisi
FROM last_sale AS ls
LEFT JOIN SIPARISLER AS sip ON sip.sip_Guid = ls.siparis_guid
OUTER APPLY (SELECT TOP 1 * FROM later_installation) AS later
SQL;
    }

    private function historySql(): string
    {
        return <<<'SQL'
WITH query_params AS (
    SELECT
        LTRIM(RTRIM(CAST(? AS NVARCHAR(100)))) AS serial_no,
        LTRIM(RTRIM(CAST(? AS NVARCHAR(50)))) AS installation_stock_code
),
serial_stock_movements AS (
    SELECT
        ch.ChHar_SeriNo AS cihaz_seri_no,
        sh.sth_Guid AS stok_hareket_guid,
        sh.sth_tarih AS hareket_tarihi,
        sh.sth_tip AS hareket_tip,
        sh.sth_normal_iade AS normal_iade,
        CAST(sh.sth_evrakno_seri AS NVARCHAR(50)) AS evrak_seri,
        CAST(sh.sth_evrakno_sira AS NVARCHAR(50)) AS evrak_sira,
        CAST(sh.sth_belge_no AS NVARCHAR(50)) AS fatura_sira,
        sh.sth_stok_kod AS stok_kodu,
        sh.sth_cari_kodu AS cari_kodu,
        sh.sth_sip_uid AS siparis_guid,
        sh.sth_fat_uid AS fatura_guid,
        sh.sth_aciklama AS description,
        CAST(sh.sth_HareketGrupKodu1 AS NVARCHAR(50)) AS hareket_grup_kodu_1,
        CAST(COALESCE(NULLIF(LTRIM(RTRIM(sh.sth_cari_srm_merkezi)), ''), NULLIF(LTRIM(RTRIM(sh.sth_stok_srm_merkezi)), '')) AS NVARCHAR(50)) AS sorumluluk_kodu,
        s.sto_isim AS stok_adi,
        c.cari_unvan1 AS cari_unvani
    FROM CIHAZ_HAREKETLERI AS ch
    INNER JOIN STOK_HAREKETLERI AS sh ON sh.sth_Guid = ch.ChHar_master_uid
    LEFT JOIN STOKLAR AS s ON s.sto_kod = sh.sth_stok_kod
    LEFT JOIN CARI_HESAPLAR AS c ON c.cari_kod = sh.sth_cari_kodu
    CROSS JOIN query_params AS params
    WHERE LTRIM(RTRIM(ch.ChHar_SeriNo)) = params.serial_no
),
sale_candidates AS (
    SELECT
        *,
        ROW_NUMBER() OVER (
            ORDER BY hareket_tarihi DESC, evrak_seri DESC, evrak_sira DESC, stok_hareket_guid DESC
        ) AS sale_rank
    FROM serial_stock_movements AS sale
    WHERE hareket_tip = 1
      AND ISNULL(normal_iade, 0) = 0
),
latest_valid_sale AS (
    SELECT TOP 1 *
    FROM sale_candidates AS sale
    WHERE NOT EXISTS (
        SELECT 1
        FROM serial_stock_movements AS later_return
        WHERE later_return.hareket_tarihi > sale.hareket_tarihi
          AND later_return.hareket_tip = 0
          AND ISNULL(later_return.normal_iade, 0) = 1
          AND NOT EXISTS (
              SELECT 1
              FROM serial_stock_movements AS later_sale
              WHERE later_sale.hareket_tarihi > later_return.hareket_tarihi
                AND later_sale.hareket_tip = 1
                AND ISNULL(later_sale.normal_iade, 0) = 0
          )
    )
    ORDER BY hareket_tarihi DESC, evrak_seri DESC, evrak_sira DESC, stok_hareket_guid DESC
)
SELECT
    CASE
        WHEN m.hareket_tip = 1 AND ISNULL(m.normal_iade, 0) = 0 THEN 'satış'
        WHEN m.hareket_tip = 0 AND ISNULL(m.normal_iade, 0) = 1 THEN 'iade'
        ELSE 'stok_hareketi'
    END AS event_type,
    m.hareket_tarihi AS event_date,
    CASE
        WHEN m.hareket_tip = 1 AND ISNULL(m.normal_iade, 0) = 0 THEN 'Satış / çıkış'
        WHEN m.hareket_tip = 0 AND ISNULL(m.normal_iade, 0) = 1 THEN 'İade / giriş'
        ELSE 'Stok hareketi'
    END AS title,
    m.description,
    m.stok_adi,
    m.cari_kodu,
    m.cari_unvani,
    m.evrak_seri,
    m.evrak_sira,
    CAST(sip.sip_evrakno_seri AS NVARCHAR(50)) AS siparis_seri,
    CAST(sip.sip_evrakno_sira AS NVARCHAR(50)) AS siparis_sira,
    CAST(NULL AS NVARCHAR(50)) AS fatura_seri,
    m.fatura_sira,
    m.hareket_grup_kodu_1,
    m.sorumluluk_kodu,
    CASE WHEN latest.stok_hareket_guid = m.stok_hareket_guid THEN CAST(1 AS bit) ELSE CAST(0 AS bit) END AS is_latest_valid_sale
FROM serial_stock_movements AS m
LEFT JOIN SIPARISLER AS sip ON sip.sip_Guid = m.siparis_guid
OUTER APPLY (SELECT TOP 1 * FROM latest_valid_sale) AS latest

UNION ALL

    SELECT
        'sonradan_montaj' AS event_type,
    x.event_date,
    'Sonradan montaj' AS title,
    x.description,
    NULL AS stok_adi,
    x.cari_kodu,
    cari.cari_unvan1 AS cari_unvani,
    x.evrak_seri,
    x.evrak_sira,
    x.siparis_seri,
    x.siparis_sira,
    x.fatura_seri,
    x.fatura_sira,
    x.hareket_grup_kodu_1,
    x.sorumluluk_kodu,
    CAST(0 AS bit) AS is_latest_valid_sale
FROM (
    SELECT
        sip.sip_tarih AS event_date,
        sip.sip_aciklama AS description,
        sip.sip_musteri_kod AS cari_kodu,
        CAST(sip.sip_evrakno_seri AS NVARCHAR(50)) AS evrak_seri,
        CAST(sip.sip_evrakno_sira AS NVARCHAR(50)) AS evrak_sira,
        CAST(sip.sip_evrakno_seri AS NVARCHAR(50)) AS siparis_seri,
        CAST(sip.sip_evrakno_sira AS NVARCHAR(50)) AS siparis_sira,
        CAST(NULL AS NVARCHAR(50)) AS fatura_seri,
        CAST(NULL AS NVARCHAR(50)) AS fatura_sira,
        CAST(sip.sip_HareketGrupKodu1 AS NVARCHAR(50)) AS hareket_grup_kodu_1,
        CAST(COALESCE(NULLIF(LTRIM(RTRIM(sip.sip_cari_sormerk)), ''), NULLIF(LTRIM(RTRIM(sip.sip_stok_sormerk)), '')) AS NVARCHAR(50)) AS sorumluluk_kodu
    FROM SIPARISLER AS sip
    CROSS JOIN query_params AS params
    WHERE sip.sip_stok_kod = params.installation_stock_code
      AND ISNULL(sip.sip_aciklama, '') LIKE '%' + params.serial_no + '%'

    UNION ALL

    SELECT
        sth.sth_tarih AS event_date,
        sth.sth_aciklama AS description,
        sth.sth_cari_kodu AS cari_kodu,
        CAST(sth.sth_evrakno_seri AS NVARCHAR(50)) AS evrak_seri,
        CAST(sth.sth_evrakno_sira AS NVARCHAR(50)) AS evrak_sira,
        CAST(NULL AS NVARCHAR(50)) AS siparis_seri,
        CAST(NULL AS NVARCHAR(50)) AS siparis_sira,
        CAST(NULL AS NVARCHAR(50)) AS fatura_seri,
        CAST(sth.sth_belge_no AS NVARCHAR(50)) AS fatura_sira,
        CAST(sth.sth_HareketGrupKodu1 AS NVARCHAR(50)) AS hareket_grup_kodu_1,
        CAST(COALESCE(NULLIF(LTRIM(RTRIM(sth.sth_cari_srm_merkezi)), ''), NULLIF(LTRIM(RTRIM(sth.sth_stok_srm_merkezi)), '')) AS NVARCHAR(50)) AS sorumluluk_kodu
    FROM STOK_HAREKETLERI AS sth
    CROSS JOIN query_params AS params
    WHERE sth.sth_stok_kod = params.installation_stock_code
      AND ISNULL(sth.sth_aciklama, '') LIKE '%' + params.serial_no + '%'

    UNION ALL

    SELECT
        ISNULL(cha.cha_create_date, cha.cha_tarihi) AS event_date,
        cha.cha_aciklama AS description,
        cha.cha_kod AS cari_kodu,
        CAST(cha.cha_evrakno_seri AS NVARCHAR(50)) AS evrak_seri,
        CAST(cha.cha_evrakno_sira AS NVARCHAR(50)) AS evrak_sira,
        CAST(NULL AS NVARCHAR(50)) AS siparis_seri,
        CAST(NULL AS NVARCHAR(50)) AS siparis_sira,
        CAST(cha.cha_evrakno_seri AS NVARCHAR(50)) AS fatura_seri,
        CAST(cha.cha_evrakno_sira AS NVARCHAR(50)) AS fatura_sira,
        CAST(cha.cha_HareketGrupKodu1 AS NVARCHAR(50)) AS hareket_grup_kodu_1,
        CAST(cha.cha_srmrkkodu AS NVARCHAR(50)) AS sorumluluk_kodu
    FROM CARI_HESAP_HAREKETLERI AS cha
    CROSS JOIN query_params AS params
    CROSS JOIN latest_valid_sale AS latest_sale
    WHERE ISNULL(cha.cha_create_date, cha.cha_tarihi) > latest_sale.hareket_tarihi
      AND cha.cha_kasa_hizkod LIKE 'MONTAJ%'
      AND ISNULL(cha.cha_aciklama, '') LIKE '%' + params.serial_no + '%'
) AS x
LEFT JOIN CARI_HESAPLAR AS cari ON cari.cari_kod = x.cari_kodu
ORDER BY event_date DESC, event_type ASC
SQL;
    }
}
