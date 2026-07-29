<?php

namespace App\Services\TechnicalService;

use App\Models\DataSource;
use App\Models\TechnicalServiceRequestSerial;
use App\Services\N8nPanelDataGateway;
use Illuminate\Support\Str;
use RuntimeException;

class MikroInvoiceSerialsService
{
    public const SOURCE_INVOICE_SERIALS = 'technical_service_invoice_serials';

    private const SOURCE_SERIAL_CHECK = 'technical_service_serial_check';

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
        private readonly ?N8nPanelDataGateway $gateway = null,
    ) {
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,all_invoice_serials:array<int,array<string,mixed>>,selectable_customer_serials:array<int,array<string,mixed>>,returned_serials:array<int,array<string,mixed>>,meta:array<string,mixed>,request:array<string,mixed>}
     */
    public function forSerial(string $serialNo): array
    {
        $serialNo = trim($serialNo);
        $mode = $this->invoiceSerialsMode();

        if ($mode === 'fixture') {
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
                    'message' => 'Fatura seri kontrolü local fixture datasından okundu.',
                    'fixture_path' => 'database/data/technical_service_invoice_serials_fixture.json',
                ],
                'request' => [
                    'serial_no' => $serialNo,
                ],
            ];
        }

        if ($mode === 'gateway') {
            $gatewayResult = $this->gatewayRowsForSerial($serialNo);
            $rows = $this->normalizeRows($gatewayResult['rows'], $serialNo);

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
                    'status' => 'gateway',
                    'source' => self::SOURCE_INVOICE_SERIALS,
                    'message' => 'Fatura seri kontrolü Mikro gateway üzerinden okundu.',
                    ...($gatewayResult['meta'] ?? []),
                ],
                'request' => [
                    'serial_no' => $serialNo,
                    ...($gatewayResult['request'] ?? []),
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

    public function mode(): string
    {
        return $this->invoiceSerialsMode();
    }

    private function invoiceSerialsMode(): string
    {
        $mode = strtolower(trim((string) (
            $this->mode ?? config('services.technical_service.invoice_serials_mode', 'gateway')
        )));

        if ($this->mode === null && $mode === 'fixture' && ! app()->environment('testing')) {
            return 'gateway';
        }

        return in_array($mode, ['disabled', 'fixture', 'gateway'], true) ? $mode : 'gateway';
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,meta?:array<string,mixed>,request?:array<string,mixed>}
     */
    private function gatewayRowsForSerial(string $serialNo): array
    {
        $gateway = $this->gateway ?? app(N8nPanelDataGateway::class);
        $dataSource = $this->invoiceSerialsDataSource();
        $result = $gateway->run(self::SOURCE_INVOICE_SERIALS, [
            'serial_no' => $serialNo,
            'bypass_cache' => true,
        ], $dataSource);

        $rows = $result['rows'] ?? [];

        return [
            'rows' => is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
            'meta' => is_array($result['meta'] ?? null) ? $result['meta'] : [],
            'request' => is_array($result['request'] ?? null) ? $result['request'] : [],
        ];
    }

    private function invoiceSerialsDataSource(): DataSource
    {
        $queryTemplateKey = 'query'.'_template';
        $allowedParamsKey = 'allowed'.'_params';
        $connectionMetaKey = 'connection'.'_meta';

        $persisted = DataSource::query()
            ->where('code', self::SOURCE_INVOICE_SERIALS)
            ->where('active', true)
            ->first();

        if ($persisted && trim((string) $persisted->getAttribute($queryTemplateKey)) !== '') {
            return $persisted;
        }

        $serialCheck = DataSource::query()
            ->where('code', self::SOURCE_SERIAL_CHECK)
            ->where('active', true)
            ->first();

        if (! $serialCheck) {
            throw new RuntimeException('Teknik servis seri kontrol veri kaynağı aktif değil.');
        }

        $dataSource = new DataSource();
        $dataSource->forceFill([
            'code' => self::SOURCE_INVOICE_SERIALS,
            'name' => 'Teknik Servis Fatura Seri Sorgu',
            'db_type' => 'n8n_json',
            $queryTemplateKey => $this->invoiceSerialsSql(),
            $allowedParamsKey => ['serial_no', 'bypass_cache'],
            $connectionMetaKey => $serialCheck->getAttribute($connectionMetaKey) ?? [],
            'active' => true,
        ]);

        return $dataSource;
    }

    private function invoiceSerialsSql(): string
    {
        return <<<'SQL'
WITH query_params AS (
    SELECT NULLIF(LTRIM(RTRIM(N'[[serial_no]]')), N'') AS serial_no
),
ArananSeriSonSatis AS (
    SELECT TOP 1
        CH.ChHar_SeriNo AS SeriNo,
        STH.sth_tarih AS SatisTarihi,
        STH.sth_evrakno_seri AS EvrakSeri,
        STH.sth_evrakno_sira AS EvrakSira,
        STH.sth_cari_kodu AS CariKodu,
        C.cari_unvan1 AS CariAdi,
        STH.sth_stok_kod AS StokKodu
    FROM dbo.CIHAZ_HAREKETLERI CH WITH (NOLOCK)
    CROSS JOIN query_params qp
    INNER JOIN dbo.STOK_HAREKETLERI STH WITH (NOLOCK)
        ON STH.sth_Guid = CH.ChHar_master_uid
    LEFT JOIN dbo.CARI_HESAPLAR C WITH (NOLOCK)
        ON C.cari_kod = STH.sth_cari_kodu
    WHERE qp.serial_no IS NOT NULL
      AND LTRIM(RTRIM(CH.ChHar_SeriNo)) = qp.serial_no
      AND STH.sth_cins = 0
      AND STH.sth_tip = 1
      AND STH.sth_evraktip IN (1, 4)
    ORDER BY STH.sth_tarih DESC, STH.sth_evrakno_sira DESC, STH.sth_Guid DESC
),
FaturaSatirlari AS (
    SELECT
        STH.sth_Guid AS HareketGuid,
        STH.sth_tarih AS SatisTarihi,
        STH.sth_evrakno_seri AS EvrakSeri,
        STH.sth_evrakno_sira AS EvrakSira,
        STH.sth_cari_kodu AS CariKodu,
        C.cari_unvan1 AS CariAdi,
        C.cari_grup_kodu AS CariGrupKodu,
        CG.crg_isim AS CariGrupAdi,
        STH.sth_stok_kod AS StokKodu,
        STK.sto_isim AS StokAdi,
        CAST(
            COALESCE(
                NULLIF(LTRIM(RTRIM(STH.sth_cari_srm_merkezi)), N''),
                NULLIF(LTRIM(RTRIM(STH.sth_stok_srm_merkezi)), N'')
            ) AS NVARCHAR(50)
        ) AS sorumluluk_kodu,
        STK.sto_model_kodu AS ModelKodu,
        MDL.mdl_ismi AS ModelAdi,
        STK.sto_marka_kodu AS MarkaKodu,
        MRK.mrk_ismi AS MarkaAdi,
        DEP.dep_adi AS DepoAdi
    FROM ArananSeriSonSatis REF
    INNER JOIN dbo.STOK_HAREKETLERI STH WITH (NOLOCK)
        ON STH.sth_evrakno_seri = REF.EvrakSeri
       AND STH.sth_evrakno_sira = REF.EvrakSira
       AND STH.sth_cari_kodu = REF.CariKodu
       AND STH.sth_tarih = REF.SatisTarihi
       AND STH.sth_cins = 0
       AND STH.sth_tip = 1
       AND STH.sth_evraktip IN (1, 4)
    LEFT JOIN dbo.CARI_HESAPLAR C WITH (NOLOCK)
        ON C.cari_kod = STH.sth_cari_kodu
    LEFT JOIN dbo.CARI_HESAP_GRUPLARI CG WITH (NOLOCK)
        ON CG.crg_kod = C.cari_grup_kodu
    LEFT JOIN dbo.STOKLAR STK WITH (NOLOCK)
        ON STK.sto_kod = STH.sth_stok_kod
    LEFT JOIN dbo.STOK_MODEL_TANIMLARI MDL WITH (NOLOCK)
        ON MDL.mdl_kodu = STK.sto_model_kodu
    LEFT JOIN dbo.STOK_MARKALARI MRK WITH (NOLOCK)
        ON MRK.mrk_kod = STK.sto_marka_kodu
    LEFT JOIN dbo.DEPOLAR DEP WITH (NOLOCK)
        ON DEP.dep_no = STH.sth_giris_depo_no
),
FaturaSerileri AS (
    SELECT
        F.*,
        CH.ChHar_SeriNo AS FaturaSeriNo
    FROM FaturaSatirlari F
    INNER JOIN dbo.CIHAZ_HAREKETLERI CH WITH (NOLOCK)
        ON CH.ChHar_master_uid = F.HareketGuid
),
SeriHareketleri AS (
    SELECT
        CH.ChHar_SeriNo AS SeriNo,
        STH.sth_Guid AS HareketGuid,
        STH.sth_tarih AS HareketTarihi,
        STH.sth_tip AS HareketTip,
        STH.sth_evraktip AS EvrakTip,
        STH.sth_evrakno_seri AS EvrakSeri,
        STH.sth_evrakno_sira AS EvrakSira,
        STH.sth_cari_kodu AS CariKodu,
        C.cari_unvan1 AS CariAdi,
        STH.sth_stok_kod AS StokKodu,
        STK.sto_isim AS StokAdi,
        CAST(
            COALESCE(
                NULLIF(LTRIM(RTRIM(STH.sth_cari_srm_merkezi)), N''),
                NULLIF(LTRIM(RTRIM(STH.sth_stok_srm_merkezi)), N'')
            ) AS NVARCHAR(50)
        ) AS sorumluluk_kodu,
        ROW_NUMBER() OVER (
            PARTITION BY CH.ChHar_SeriNo
            ORDER BY STH.sth_tarih DESC, STH.sth_evrakno_sira DESC, STH.sth_Guid DESC
        ) AS HareketSira,
        MAX(CASE
            WHEN STH.sth_tip = 1
             AND STH.sth_cins = 0
             AND STH.sth_evraktip IN (1, 4)
            THEN STH.sth_tarih
        END) OVER (PARTITION BY CH.ChHar_SeriNo) AS SonSatisTarihi
    FROM dbo.CIHAZ_HAREKETLERI CH WITH (NOLOCK)
    INNER JOIN dbo.STOK_HAREKETLERI STH WITH (NOLOCK)
        ON STH.sth_Guid = CH.ChHar_master_uid
    LEFT JOIN dbo.CARI_HESAPLAR C WITH (NOLOCK)
        ON C.cari_kod = STH.sth_cari_kodu
    LEFT JOIN dbo.STOKLAR STK WITH (NOLOCK)
        ON STK.sto_kod = STH.sth_stok_kod
    WHERE EXISTS (
        SELECT 1
        FROM FaturaSerileri FS
        WHERE FS.FaturaSeriNo = CH.ChHar_SeriNo
    )
),
FaturaSeriSatirlari AS (
    SELECT
        FS.*,
        IADE.HareketTarihi AS IadeTarihi,
        IADE.EvrakSeri AS IadeEvrakSeri,
        IADE.EvrakSira AS IadeEvrakSira,
        CASE
            WHEN IADE.SeriNo IS NOT NULL THEN N'Bu seri için son hareket iade/red görünüyor'
            ELSE NULL
        END AS IadeNotu,
        SON_SATIS.HareketTarihi AS GuncelSonSatisTarihi,
        SON_SATIS.EvrakSeri AS GuncelSonSatisEvrakSeri,
        SON_SATIS.EvrakSira AS GuncelSonSatisEvrakSira,
        SON_SATIS.CariKodu AS GuncelSonSatisCariKodu,
        SON_SATIS.CariAdi AS GuncelSonSatisCariAdi,
        CASE
            WHEN SON_SATIS.HareketGuid = FS.HareketGuid THEN N'Evet'
            ELSE N'Hayır'
        END AS BuFaturaSonSatisMi
    FROM FaturaSerileri FS
    OUTER APPLY (
        SELECT TOP 1 SH.*
        FROM SeriHareketleri SH
        WHERE SH.SeriNo = FS.FaturaSeriNo
          AND SH.HareketTip = 0
          AND SH.HareketTarihi >= FS.SatisTarihi
        ORDER BY SH.HareketTarihi DESC, SH.EvrakSira DESC, SH.HareketGuid DESC
    ) IADE
    OUTER APPLY (
        SELECT TOP 1 SH.*
        FROM SeriHareketleri SH
        WHERE SH.SeriNo = FS.FaturaSeriNo
          AND SH.HareketTip = 1
          AND SH.EvrakTip IN (1, 4)
        ORDER BY SH.HareketTarihi DESC, SH.EvrakSira DESC, SH.HareketGuid DESC
    ) SON_SATIS
)
SELECT
    qp.serial_no AS [Aranan Seri No],
    F.FaturaSeriNo AS [Faturadaki Seri No],
    CASE
        WHEN F.IadeNotu IS NOT NULL THEN N'İade/Red'
        WHEN F.BuFaturaSonSatisMi = N'Hayır' THEN N'Güncel Son Satış Değil'
        ELSE N'Uygun'
    END AS [Seri Durumu],
    F.SatisTarihi AS [Satış Tarihi],
    F.EvrakSeri AS [Satış Evrak Seri],
    F.EvrakSira AS [Satış Evrak Sıra],
    F.CariKodu AS [Satış Cari Kodu],
    F.CariAdi AS [Satış Cari Adı],
    F.CariGrupKodu AS [Satış Cari Grup Kodu],
    F.CariGrupAdi AS [Satış Cari Grup Adı],
    F.StokKodu AS [Stok Kodu],
    F.StokAdi AS [Stok Adı],
    F.ModelKodu AS [Model Kodu],
    F.ModelAdi AS [Model Adı],
    F.MarkaKodu AS [Marka Kodu],
    F.MarkaAdi AS [Marka Adı],
    F.DepoAdi AS [Depo Adı],
    F.IadeTarihi AS [İade Tarihi],
    F.IadeEvrakSeri AS [İade Evrak Seri],
    F.IadeEvrakSira AS [İade Evrak Sıra],
    F.IadeNotu AS [İade Notu],
    F.BuFaturaSonSatisMi AS [Bu Fatura Bu Seri İçin Son Satış mı],
    F.GuncelSonSatisTarihi AS [Serinin Güncel Son Satış Tarihi],
    F.GuncelSonSatisEvrakSeri AS [Serinin Güncel Son Satış Evrak Seri],
    F.GuncelSonSatisEvrakSira AS [Serinin Güncel Son Satış Evrak Sıra],
    F.GuncelSonSatisCariKodu AS [Serinin Güncel Son Satış Cari Kodu],
    F.GuncelSonSatisCariAdi AS [Serinin Güncel Son Satış Cari Adı],
    F.sorumluluk_kodu AS [Sorumluluk Kodu],
    F.sorumluluk_kodu AS sorumluluk_kodu
FROM FaturaSeriSatirlari F
CROSS JOIN query_params qp
ORDER BY
    CASE WHEN F.FaturaSeriNo = qp.serial_no THEN 0 ELSE 1 END,
    F.StokAdi,
    F.FaturaSeriNo;
SQL;
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

            $aliases = $group['aliases'] ?? $group['serial_aliases'] ?? [];
            if ($this->matchesAnySerial($aliases, $serialNo)) {
                return array_values(array_filter($rows, 'is_array'));
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $searchedFixtureSerial = $this->firstText($row, [
                    'Aranan Seri No',
                    'aranan_seri_no',
                    'searched_serial',
                    'searched_serial_number',
                ]);
                $fixtureSerial = $this->firstText($row, [
                    'Faturadaki Seri No',
                    'faturadaki_seri_no',
                    'serial_number',
                ]);

                if ($this->sameSerial($fixtureSerial, $serialNo) || $this->sameSerial($searchedFixtureSerial, $serialNo)) {
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

    private function matchesAnySerial(mixed $serials, string $searchedSerial): bool
    {
        if (! is_array($serials)) {
            $serials = [$serials];
        }

        foreach ($serials as $serial) {
            if (! is_scalar($serial) && ! $serial instanceof \Stringable) {
                continue;
            }

            if ($this->sameSerial($this->nullableText($serial), $searchedSerial)) {
                return true;
            }
        }

        return false;
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
