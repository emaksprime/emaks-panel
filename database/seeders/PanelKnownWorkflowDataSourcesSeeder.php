<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;

class PanelKnownWorkflowDataSourcesSeeder extends Seeder
{
    private const ACCOUNTING_FINANCE_RESMI_STOK_KONTROL = 'accounting_finance_resmi_stok_kontrol';

    public function run(): void
    {
        $salesTemplate = (string) DataSource::query()
            ->where('code', 'sales_main_dashboard')
            ->value('query_template');

        if ($salesTemplate !== '') {
            $this->upsert(
                'sales_online_perakende_detail',
                'Online / Perakende Detay',
                $this->salesTemplateWithCustomerGroupScope($salesTemplate, true),
                ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code', 'cari_filter', 'customer_filter', 'allowed_cari_group_codes', 'denied_cari_group_codes', 'brand_filter', 'category_filter', 'product_filter', 'search', 'page', 'bypass_cache'],
                'SALES_ONLINE_PERAKENDE_DETAY_V1 kapsamı: online/perakende cari grup kodları sales_main_dashboard kanonik sorgusuna filtre olarak uygulanır.',
                'SALES_ONLINE_PERAKENDE_DETAY_V1.json'
            );

            $this->upsert(
                'sales_bayi_proje_detail',
                'Bayi / Proje Detay',
                $this->salesTemplateWithCustomerGroupScope($salesTemplate, false),
                ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code', 'cari_filter', 'customer_filter', 'allowed_cari_group_codes', 'denied_cari_group_codes', 'brand_filter', 'category_filter', 'product_filter', 'search', 'page', 'bypass_cache'],
                'SALES_BAYI_PROJE_DETAY_V1 kapsamı: online/perakende dışı cari grup kodları sales_main_dashboard kanonik sorgusuna filtre olarak uygulanır.',
                'SALES_BAYI_PROJE_DETAY_V1.json'
            );
        }

        $this->upsert(
            'sales_customer_search',
            'Satış Müşteri Arama',
            <<<'SQL_SALES_CUSTOMER_SEARCH'
DECLARE @Search NVARCHAR(255) = N'[[search]]';
DECLARE @RepCode NVARCHAR(50) = N'[[rep_code]]';
DECLARE @ScopeKey NVARCHAR(80) = REPLACE(N'[[scope_key]]', N'-', N'_');
DECLARE @allowed_cari_group_codes NVARCHAR(MAX) = LTRIM(RTRIM(N'[[allowed_cari_group_codes]]'));
DECLARE @denied_cari_group_codes NVARCHAR(MAX) = LTRIM(RTRIM(N'[[denied_cari_group_codes]]'));
DECLARE @date_from DATE = '[[date_from]]';
DECLARE @date_to DATE = '[[date_to]]';
DECLARE @detail_type NVARCHAR(10) = N'[[detail_type]]';
DECLARE @CanViewAll bit = CASE
    WHEN NULLIF(LTRIM(RTRIM(ISNULL(@RepCode, N''))), N'') IS NULL THEN 1
    ELSE 0
END;

;WITH cube AS
(
    SELECT
        LTRIM(RTRIM(ISNULL(msg_S_1032, N''))) AS cari_kodu,
        LTRIM(RTRIM(ISNULL(msg_S_0201, N''))) AS cari_adi_raw,
        LTRIM(RTRIM(ISNULL(msg_S_2663, N''))) AS stok_kodu_raw,
        LTRIM(RTRIM(ISNULL(msg_S_2664, N''))) AS urun_adi_raw,
        LTRIM(RTRIM(ISNULL(msg_S_0059, N''))) AS model_adi_raw,
        UPPER(LTRIM(RTRIM(ISNULL(msg_S_0118, N'')))) AS belge_tipi,
        UPPER(LTRIM(RTRIM(ISNULL(msg_S_2663, N'')))) AS stok_kodu_u,
        UPPER(LTRIM(RTRIM(ISNULL(msg_S_2664, N'')))) AS urun_adi_u,
        UPPER(LTRIM(RTRIM(ISNULL(msg_S_0059, N'')))) AS model_adi_u,
        CAST(ISNULL(msg_S_0165, 0) AS decimal(18,2)) AS adet,
        CAST(ISNULL(msg_S_0535, 0) AS decimal(18,2)) AS net_tutar
    FROM dbo.fn_Stok_Masraf_Musteri_Grup_Hareket_Kubu(
        CONVERT(char(8), @date_from, 112),
        CONVERT(char(8), @date_to, 112),
        1,
        1
    )
    WHERE ISNULL(LTRIM(RTRIM(msg_S_1032)), N'') <> N''
),
filtered AS
(
    SELECT
        c.cari_kodu,
        LTRIM(RTRIM(ISNULL(cari.cari_unvan1, c.cari_adi_raw))) AS cari_unvani,
        LTRIM(RTRIM(ISNULL(grp.crg_isim, N''))) AS cari_grubu,
        CASE
            WHEN c.belge_tipi LIKE N'%İADE%'
              OR c.belge_tipi LIKE N'%IADE%'
            THEN -ABS(c.adet)
            ELSE c.adet
        END AS adet,
        CASE
            WHEN c.belge_tipi LIKE N'%İADE%'
              OR c.belge_tipi LIKE N'%IADE%'
            THEN -ABS(c.net_tutar)
            ELSE c.net_tutar
        END AS net_tutar
    FROM cube c
    INNER JOIN dbo.CARI_HESAPLAR cari WITH (NOLOCK)
        ON cari.cari_kod = c.cari_kodu
    INNER JOIN dbo.STOKLAR sto WITH (NOLOCK)
        ON sto.sto_kod = c.stok_kodu_raw
    LEFT JOIN dbo.CARI_HESAP_GRUPLARI grp WITH (NOLOCK)
        ON grp.crg_kod = cari.cari_grup_kodu
    WHERE
        ABS(c.net_tutar) > 1
        AND NOT (
            c.belge_tipi IN (N'DEĞİŞİM', N'PROJE İÇİN NUMUNE ÜRÜN')
            AND ABS(c.net_tutar) < 10
        )
        AND LTRIM(RTRIM(ISNULL(sto.sto_kategori_kodu, N''))) IN (N'A1',N'AS1',N'D1',N'G1',N'K1',N'KA1',N'M1',N'O1',N'OT1',N'YM1')
        AND (@CanViewAll = 1 OR LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode)
        AND (
            @ScopeKey NOT IN (N'online_perakende', N'bayi_proje')
            OR (
                @ScopeKey = N'online_perakende'
                AND ISNULL(cari.cari_grup_kodu, N'') IN (N'120.01',N'120.02',N'120.03',N'120.04',N'120.05',N'120.06',N'120.07',N'120.08',N'120.09',N'120.16')
            )
            OR (
                @ScopeKey = N'bayi_proje'
                AND (
                    NULLIF(LTRIM(RTRIM(ISNULL(cari.cari_grup_kodu, N''))), N'') IS NULL
                    OR cari.cari_grup_kodu NOT IN (N'120.01',N'120.02',N'120.03',N'120.04',N'120.05',N'120.06',N'120.07',N'120.08',N'120.09',N'120.16')
                )
            )
        )
        AND (
            NULLIF(@allowed_cari_group_codes, N'') IS NULL
            OR ISNULL(cari.cari_grup_kodu, N'') IN
            (
                SELECT LTRIM(RTRIM(value))
                FROM STRING_SPLIT(@allowed_cari_group_codes, N',')
                WHERE LTRIM(RTRIM(value)) <> N''
            )
        )
        AND (
            NULLIF(@denied_cari_group_codes, N'') IS NULL
            OR ISNULL(cari.cari_grup_kodu, N'') NOT IN
            (
                SELECT LTRIM(RTRIM(value))
                FROM STRING_SPLIT(@denied_cari_group_codes, N',')
                WHERE LTRIM(RTRIM(value)) <> N''
            )
        )
        AND (
            @Search = N''
            OR cari.cari_kod LIKE N'%' + @Search + N'%'
            OR cari.cari_unvan1 LIKE N'%' + @Search + N'%'
            OR ISNULL(grp.crg_isim, N'') LIKE N'%' + @Search + N'%'
        )
        AND NULLIF(LTRIM(RTRIM(ISNULL(cari.cari_kod, N''))), N'') IS NOT NULL
        AND c.stok_kodu_u NOT LIKE 'W-%'
        AND c.stok_kodu_u NOT LIKE N'%HİZMET%'
        AND c.stok_kodu_u NOT LIKE N'%HIZMET%'
        AND c.stok_kodu_u NOT LIKE N'%SERVİS%'
        AND c.stok_kodu_u NOT LIKE N'%SERVIS%'
        AND c.stok_kodu_u NOT LIKE N'%MONTAJ%'
        AND c.stok_kodu_u NOT LIKE N'%YOL%'
        AND c.stok_kodu_u NOT LIKE N'%KEŞİF%'
        AND c.stok_kodu_u NOT LIKE N'%KESIF%'
        AND c.urun_adi_u NOT LIKE N'%HİZMET%'
        AND c.urun_adi_u NOT LIKE N'%HIZMET%'
        AND c.urun_adi_u NOT LIKE N'%SERVİS%'
        AND c.urun_adi_u NOT LIKE N'%SERVIS%'
        AND c.urun_adi_u NOT LIKE N'%MONTAJ%'
        AND c.urun_adi_u NOT LIKE N'%YOL%'
        AND c.urun_adi_u NOT LIKE N'%KEŞİF%'
        AND c.urun_adi_u NOT LIKE N'%KESIF%'
        AND c.model_adi_u NOT LIKE N'%HİZMET%'
        AND c.model_adi_u NOT LIKE N'%HIZMET%'
        AND c.model_adi_u NOT LIKE N'%SERVİS%'
        AND c.model_adi_u NOT LIKE N'%SERVIS%'
        AND c.model_adi_u NOT LIKE N'%MONTAJ%'
        AND c.model_adi_u NOT LIKE N'%YOL%'
        AND c.model_adi_u NOT LIKE N'%KEŞİF%'
        AND c.model_adi_u NOT LIKE N'%KESIF%'
),
customers AS
(
    SELECT
        cari_kodu,
        cari_unvani,
        cari_grubu,
        SUM(ABS(adet)) AS toplam_adet,
        SUM(ABS(net_tutar)) AS toplam_ciro
    FROM filtered
    GROUP BY cari_kodu, cari_unvani, cari_grubu
)
SELECT TOP 80
    cari_kodu,
    cari_unvani,
    cari_grubu,
    CASE
        WHEN NULLIF(cari_grubu, N'') IS NULL
            THEN CONCAT(cari_unvani, N' | ', cari_kodu)
        ELSE CONCAT(cari_unvani, N' | ', cari_kodu, N' | ', cari_grubu)
    END AS display_text
FROM customers
ORDER BY
    CASE WHEN cari_kodu = @Search THEN 0
         WHEN cari_unvani = @Search THEN 1
         WHEN cari_kodu LIKE @Search + N'%' THEN 2
         WHEN cari_unvani LIKE @Search + N'%' THEN 3
         ELSE 9 END,
    toplam_ciro DESC,
    cari_unvani ASC,
    cari_kodu ASC;
SQL_SALES_CUSTOMER_SEARCH,
            ['search', 'scope_key', 'date_from', 'date_to', 'grain', 'detail_type', 'rep_code', 'allowed_cari_group_codes', 'denied_cari_group_codes', 'limit', 'bypass_cache'],
            'PrimeCRM SalesService.GetCustomerOptionsAsync arama mantığı aktif satış hareketi datasıyla sınırlandırılır.',
            'SalesService.cs'
        );

        $this->upsert(
            'technical_service_serial_check',
            'Teknik Servis Seri No Montaj Kontrol',
            $this->technicalServiceSerialDecisionQuery(),
            ['serial_no', 'bypass_cache'],
            'PR #36 MikroSerialNumberService montaj karar sorgusu n8n gateway datasource olarak çalışır.',
            'MikroSerialNumberService.php'
        );

        $this->upsert(
            'technical_service_serial_history',
            'Teknik Servis Seri No Hareket Geçmişi',
            $this->technicalServiceSerialHistoryQuery(),
            ['serial_no', 'bypass_cache'],
            'PR #36 MikroSerialNumberService seri hareket geçmişi sorgusu n8n gateway datasource olarak çalışır.',
            'MikroSerialNumberService.php'
        );

        $this->upsert(
            'technical_service_warranty_serial',
            'Teknik Servis Garanti Seri Sorgu',
            $this->technicalServiceSerialHistoryQuery(),
            ['serial_no', 'bypass_cache'],
            'Garanti başlangıcı için son geçerli Mikro satış fingerprint verisi n8n gateway üzerinden okunur.',
            'MikroSerialNumberService.php'
        );

        $this->upsertAccountingFinanceResmiStokKontrol();

        $this->upsert(
            'stock_dashboard',
            'Stok Dashboard',
            <<<'SQL_STOCK'
WITH depo_miktarlari AS
(
    SELECT
        sto.sto_kod,
        sto.sto_isim,
        sto.sto_kategori_kodu,
        ISNULL(ktg.ktg_isim, sto.sto_kategori_kodu) AS kategori,
        mdl.mdl_ismi AS model_adi,
        CAST(ISNULL(dbo.fn_DepodakiMiktar(sto.sto_kod, 1, GETDATE()), 0) AS decimal(18,2)) AS miktar
    FROM STOKLAR sto
    LEFT JOIN STOK_KATEGORILERI ktg
        ON ktg.ktg_kod = sto.sto_kategori_kodu
    LEFT JOIN STOK_MODEL_TANIMLARI mdl
        ON mdl.mdl_kodu = sto.sto_model_kodu

    UNION ALL

    SELECT
        sto.sto_kod,
        sto.sto_isim,
        sto.sto_kategori_kodu,
        ISNULL(ktg.ktg_isim, sto.sto_kategori_kodu) AS kategori,
        mdl.mdl_ismi AS model_adi,
        CAST(ISNULL(dbo.fn_DepodakiMiktar(sto.sto_kod, 5, GETDATE()), 0) AS decimal(18,2)) AS miktar
    FROM STOKLAR sto
    LEFT JOIN STOK_KATEGORILERI ktg
        ON ktg.ktg_kod = sto.sto_kategori_kodu
    LEFT JOIN STOK_MODEL_TANIMLARI mdl
        ON mdl.mdl_kodu = sto.sto_model_kodu
)
SELECT
    sto_kod AS [stok_kodu],
    sto_isim AS [stok_adi],
    sto_kategori_kodu AS [kategori_kodu],
    kategori,
    model_adi,
    SUM(miktar) AS [toplam_miktar]
FROM depo_miktarlari
GROUP BY
    sto_kod,
    sto_isim,
    sto_kategori_kodu,
    kategori,
    model_adi
HAVING SUM(miktar) > 0
ORDER BY
    sto_kod;
SQL_STOCK,
            ['search', 'page', 'bypass_cache'],
            'Twenty - Stok Dashboard - Corrected v2 workflow Code - Build SQL node sorgusu.',
            'Twenty - Stok Dashboard - Corrected v2.json'
        );

        $this->upsert(
            'stock_warehouse',
            'Depo / Raf Durumu',
            <<<'SQL_STOCK_WAREHOUSE'
WITH depo_miktarlari AS
(
    SELECT
        sto.sto_kod,
        sto.sto_isim,
        CAST(ISNULL(dbo.fn_DepodakiMiktar(sto.sto_kod, 1, GETDATE()), 0) AS decimal(18,2)) AS depo_1_miktar,
        CAST(ISNULL(dbo.fn_DepodakiMiktar(sto.sto_kod, 5, GETDATE()), 0) AS decimal(18,2)) AS depo_5_miktar
    FROM STOKLAR sto
)
SELECT
    sto_kod AS [stok_kodu],
    sto_isim AS [stok_adi],
    depo_1_miktar,
    depo_5_miktar,
    CAST(depo_1_miktar + depo_5_miktar AS decimal(18,2)) AS toplam_miktar
FROM depo_miktarlari
WHERE depo_1_miktar <> 0 OR depo_5_miktar <> 0
ORDER BY sto_kod;
SQL_STOCK_WAREHOUSE,
            ['search', 'page', 'bypass_cache'],
            'Stok workflowundaki fn_DepodakiMiktar mantığından depo 1 ve depo 5 kırılımı.',
            'Twenty - Stok Dashboard - Corrected v2.json'
        );

        $this->upsert(
            'stock_critical',
            'Kritik Stoklar',
            <<<'SQL_STOCK_CRITICAL'
WITH depo_miktarlari AS
(
    SELECT
        sto.sto_kod,
        sto.sto_isim,
        CAST(ISNULL(dbo.fn_DepodakiMiktar(sto.sto_kod, 1, GETDATE()), 0) AS decimal(18,2)) AS miktar_1,
        CAST(ISNULL(dbo.fn_DepodakiMiktar(sto.sto_kod, 5, GETDATE()), 0) AS decimal(18,2)) AS miktar_5
    FROM STOKLAR sto
),
toplamlar AS
(
    SELECT
        sto_kod,
        sto_isim,
        CAST(miktar_1 + miktar_5 AS decimal(18,2)) AS toplam_miktar
    FROM depo_miktarlari
)
SELECT
    sto_kod AS [stok_kodu],
    sto_isim AS [stok_adi],
    toplam_miktar
FROM toplamlar
WHERE toplam_miktar > 0 AND toplam_miktar <= 5
ORDER BY toplam_miktar ASC, sto_kod ASC;
SQL_STOCK_CRITICAL,
            ['search', 'page', 'bypass_cache'],
            'Stok workflowundaki fn_DepodakiMiktar mantığından toplam miktarı 5 ve altında olan kayıtlar.',
            'Twenty - Stok Dashboard - Corrected v2.json'
        );

        $this->upsert(
            'orders_alinan',
            'Alınan Siparişler',
            <<<'SQL_ORDERS_ALINAN'
DECLARE @BasTar DATE = COALESCE(TRY_CONVERT(date, NULLIF(N'[[date_from]]', N'')), CONVERT(date, '2025-01-01'));
DECLARE @BitTar DATE = COALESCE(TRY_CONVERT(date, NULLIF(N'[[date_to]]', N'')), CONVERT(date, GETDATE()));
DECLARE @Search NVARCHAR(255) = LTRIM(RTRIM(N'[[search]]'));
DECLARE @Status NVARCHAR(30) = LOWER(LTRIM(RTRIM(N'[[status]]')));
DECLARE @RepCode NVARCHAR(50) = LTRIM(RTRIM(N'[[rep_code]]'));
DECLARE @OrdersScope NVARCHAR(30) = LOWER(LTRIM(RTRIM(N'[[orders_scope]]')));
DECLARE @BrandFilter NVARCHAR(50) = LOWER(REPLACE(LTRIM(RTRIM(N'[[brand_filter]]')), N'-', N'_'));
DECLARE @ProductFilter NVARCHAR(MAX) = LTRIM(RTRIM(N'[[product_filter]]'));
DECLARE @Limit INT = TRY_CONVERT(int, NULLIF(N'[[limit]]', N''));

IF @Status = N''
    SET @Status = N'all';

IF @OrdersScope = N''
    SET @OrdersScope = N'legacy';

IF @BrandFilter = N''
    SET @BrandFilter = N'all';

IF @Limit IS NULL OR @Limit <= 0
    SET @Limit = 500;

WITH HamVeri AS
(
    SELECT
        sip.sip_tarih,
        sip.sip_evrakno_seri,
        sip.sip_evrakno_sira,
        sip.sip_aciklama2,
        sip.sip_stok_kod,
        cari.cari_unvan1 AS cari_adi,
        sto.sto_isim AS stok_adi,
        mdl.mdl_ismi AS model_adi,
        crg.crg_isim AS cari_grup_adi,
        LTRIM(RTRIM(ISNULL(sto.sto_kategori_kodu, N''))) AS kategori_kodu,
        LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N''))) AS brand_code,
        CASE
            WHEN UPPER(LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N'')))) = N'PHILIPS' THEN N'philips'
            WHEN UPPER(LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N'')))) IN (N'EMAKS PRIME', N'EMAKS') THEN N'emaks_prime'
            ELSE N'other'
        END AS brand_key,
        CASE
            WHEN UPPER(LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N'')))) = N'PHILIPS' THEN N'PHILIPS'
            WHEN UPPER(LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N'')))) IN (N'EMAKS PRIME', N'EMAKS') THEN N'EMAKS PRIME'
            ELSE N'Diğer Marka'
        END AS marka,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM SIPARISLER montaj_sip
                WHERE montaj_sip.sip_evrakno_seri = sip.sip_evrakno_seri
                    AND montaj_sip.sip_evrakno_sira = sip.sip_evrakno_sira
                    AND montaj_sip.sip_stok_kod = N'W-MONTAJ-1'
                    AND montaj_sip.sip_iptal = 0
                    AND ISNULL(montaj_sip.sip_miktar, 0) > 0
            ) THEN N'Montaj Dahil'
            ELSE N'Montaj Hariç'
        END AS montaj_durumu,
        CASE
            WHEN UPPER(REPLACE(REPLACE(CONCAT(
                LTRIM(RTRIM(ISNULL(sip.sip_musteri_kod, N''))), N' ',
                LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))), N' ',
                LTRIM(RTRIM(ISNULL(crg.crg_isim, N'')))
            ), N'İ', N'I'), N'ı', N'I')) LIKE N'%KONSINYE%' THEN 1
            ELSE 0
        END AS konsinye_mi,
        LTRIM(RTRIM(ISNULL(sip.sip_satici_kod, N''))) AS temsilci_kodu,
        LTRIM(RTRIM(
            CASE
                WHEN ISNULL(sip.sip_cari_sormerk, N'') <> N'' THEN sip.sip_cari_sormerk
                WHEN ISNULL(sip.sip_stok_sormerk, N'') <> N'' THEN sip.sip_stok_sormerk
                ELSE N''
            END
        )) AS sorumluluk_kodu,
        ISNULL(sip.sip_miktar, 0) AS siparis_miktar,
        ISNULL(sip.sip_teslim_miktar, 0) AS teslim_miktar,
        ISNULL(sip.sip_miktar, 0) - ISNULL(sip.sip_teslim_miktar, 0) AS kalan_miktar,
        ISNULL(sip.sip_tutar, 0) AS sip_tutar,
        ISNULL(sip.sip_iskonto_1, 0) AS iskonto_1,
        ISNULL(sip.sip_iskonto_2, 0) AS iskonto_2,
        ISNULL(sip.sip_iskonto_3, 0) AS iskonto_3
    FROM SIPARISLER sip
    LEFT JOIN CARI_HESAPLAR cari
        ON cari.cari_kod = sip.sip_musteri_kod
    LEFT JOIN CARI_HESAP_GRUPLARI crg
        ON crg.crg_kod = cari.cari_grup_kodu
    LEFT JOIN STOKLAR sto
        ON sto.sto_kod = sip.sip_stok_kod
    LEFT JOIN STOK_MODEL_TANIMLARI mdl
        ON mdl.mdl_kodu = sto.sto_model_kodu
    WHERE
        sip.sip_iptal = 0
        AND sip.sip_tip = 0
        AND sip.sip_kapat_fl = 0
        AND CAST(sip.sip_tarih AS date) >= @BasTar
        AND CAST(sip.sip_tarih AS date) <= @BitTar
        AND ISNULL(sip.sip_miktar, 0) - ISNULL(sip.sip_teslim_miktar, 0) > 0
        AND LTRIM(RTRIM(ISNULL(sto.sto_kategori_kodu, N''))) IN (N'A1',N'AS1',N'D1',N'G1',N'K1',N'KA1',N'M1',N'O1',N'OT1',N'YM1')
        AND (
            @OrdersScope <> N'temsilci'
            OR (
                @RepCode <> N''
                AND LTRIM(RTRIM(ISNULL(sip.sip_satici_kod, N''))) = @RepCode
            )
        )
        AND (
            @BrandFilter IN (N'all', N'tumu')
            OR (@BrandFilter = N'philips' AND UPPER(LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N'')))) = N'PHILIPS')
            OR (@BrandFilter = N'emaks_prime' AND UPPER(LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N'')))) IN (N'EMAKS PRIME', N'EMAKS'))
        )
        AND UPPER(LTRIM(RTRIM(ISNULL(crg.crg_isim, N'')))) NOT LIKE N'%İHRACAT%'
),
Hesaplanmis AS
(
    SELECT
        sip_tarih,
        sip_evrakno_seri,
        sip_evrakno_sira,
        sip_aciklama2,
        sip_stok_kod,
        cari_adi,
        stok_adi,
        model_adi,
        kategori_kodu,
        brand_code,
        brand_key,
        marka,
        montaj_durumu,
        konsinye_mi,
        temsilci_kodu,
        sorumluluk_kodu,
        CASE
            WHEN UPPER(ISNULL(stok_adi, N'')) LIKE N'%STAND%'
              OR UPPER(ISNULL(model_adi, N'')) LIKE N'%STAND%'
              OR UPPER(ISNULL(ISNULL(NULLIF(model_adi, N''), stok_adi), N'')) LIKE N'%STAND%'
            THEN stok_adi
            ELSE ISNULL(NULLIF(model_adi, N''), stok_adi)
        END AS urun_adi,
        kalan_miktar,
        ROUND(
            CASE
                WHEN ISNULL(siparis_miktar, 0) = 0 THEN 0
                ELSE ((ISNULL(sip_tutar, 0) - ISNULL(iskonto_1, 0) - ISNULL(iskonto_2, 0) - ISNULL(iskonto_3, 0)) / siparis_miktar)
            END, 2
        ) AS birim_fiyat,
        ROUND(
            CASE
                WHEN ISNULL(siparis_miktar, 0) = 0 THEN 0
                ELSE ((ISNULL(sip_tutar, 0) - ISNULL(iskonto_1, 0) - ISNULL(iskonto_2, 0) - ISNULL(iskonto_3, 0)) / siparis_miktar) * kalan_miktar
            END, 2
        ) AS kalan_tutar
    FROM HamVeri
),
NormalizeEdilmis AS
(
    SELECT
        *,
        UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(ISNULL(urun_adi, N''), N'İ', N'I'), N'I', N'I'), N'ı', N'I'), N'Ö', N'O'), N'ö', N'O'), N'Ü', N'U'), N'ü', N'U'), N'Ç', N'C'), N'ç', N'C'), N'Ş', N'S')) AS urun_adi_norm,
        UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(ISNULL(sorumluluk_kodu, N''), N'İ', N'I'), N'I', N'I'), N'ı', N'I'), N'Ö', N'O'), N'ö', N'O'), N'Ü', N'U'), N'ü', N'U'), N'Ç', N'C'), N'ç', N'C'), N'Ş', N'S')) AS sorumluluk_norm
    FROM Hesaplanmis
),
Filtreli AS
(
    SELECT
        sip_tarih,
        sip_evrakno_seri,
        sip_evrakno_sira,
        sip_aciklama2,
        sip_stok_kod AS stok_kodu,
        cari_adi,
        urun_adi,
        kategori_kodu,
        brand_code,
        brand_key,
        marka,
        montaj_durumu,
        konsinye_mi,
        temsilci_kodu,
        sorumluluk_kodu,
        kalan_miktar,
        birim_fiyat,
        kalan_tutar,
        CASE
            WHEN sip_evrakno_seri = N'B' THEN N'Onay Bekleyen Siparişler'
            ELSE N'Onaylı Siparişler'
        END AS siparis_grubu
    FROM NormalizeEdilmis
    WHERE
        urun_adi_norm NOT LIKE N'%KILIT DONUSUM APARAT%'
        AND urun_adi_norm NOT LIKE N'%KILIT DONUSUM APATAR%'
        AND urun_adi_norm NOT LIKE N'%DONUSUM APARAT%'
        AND urun_adi_norm NOT LIKE N'%DONUSUM APATAR%'
        AND LTRIM(RTRIM(ISNULL(sip_stok_kod, N''))) <> N'W-MONTAJ-1'
        AND urun_adi_norm NOT LIKE N'%MONTAJ%'
        AND urun_adi_norm NOT LIKE N'%HIZMET%'
        AND urun_adi_norm NOT LIKE N'%SERVIS%'
        AND urun_adi_norm NOT LIKE N'%YOL%'
        AND urun_adi_norm NOT LIKE N'%KESIF%'
        AND NOT (urun_adi_norm LIKE N'%YEDEK PARCA%' AND kalan_tutar <= 10)
        AND (
            NOT (
                sorumluluk_norm LIKE N'%DEGISIM%'
                OR sorumluluk_norm LIKE N'%GARANTI DISI KONTROL%'
                OR sorumluluk_norm LIKE N'%GARANTI KAPSAMI KONTROL%'
                OR LTRIM(RTRIM(sorumluluk_norm)) = N'GR'
            )
            OR kalan_tutar > 10
        )
        AND (
            @ProductFilter = N''
            OR EXISTS (
                SELECT 1
                FROM STRING_SPLIT(@ProductFilter, N',') pf
                WHERE LTRIM(RTRIM(pf.value)) <> N''
                    AND (
                        sip_stok_kod LIKE N'%' + LTRIM(RTRIM(pf.value)) + N'%'
                        OR urun_adi LIKE N'%' + LTRIM(RTRIM(pf.value)) + N'%'
                    )
            )
        )
)
SELECT TOP (@Limit)
    CONVERT(varchar(10), sip_tarih, 23) AS siparis_tarihi,
    CONVERT(varchar(10), sip_tarih, 104) AS siparis_tarihi_gosterim,
    sip_evrakno_seri,
    sip_evrakno_sira,
    sip_aciklama2,
    stok_kodu,
    cari_adi,
    urun_adi,
    kategori_kodu,
    brand_code,
    brand_key,
    marka,
    montaj_durumu,
    konsinye_mi,
    temsilci_kodu,
    sorumluluk_kodu,
    siparis_grubu,
    kalan_miktar,
    N'Adet' AS birim,
    birim_fiyat,
    kalan_tutar,
    kalan_tutar AS satir_net_tutar_kdv_haric
FROM Filtreli
WHERE
    (
        @Status IN (N'all', N'tumu', N'tüm', N'tümü')
        OR (@Status IN (N'approved', N'onayli') AND siparis_grubu = N'Onaylı Siparişler')
        OR (@Status IN (N'pending', N'bekleyen') AND siparis_grubu = N'Onay Bekleyen Siparişler')
    )
    AND (
        @Search = N''
        OR cari_adi LIKE N'%' + @Search + N'%'
        OR urun_adi LIKE N'%' + @Search + N'%'
        OR sip_aciklama2 LIKE N'%' + @Search + N'%'
        OR temsilci_kodu LIKE N'%' + @Search + N'%'
        OR sorumluluk_kodu LIKE N'%' + @Search + N'%'
    )
ORDER BY
    CASE WHEN sip_evrakno_seri = N'B' THEN 1 ELSE 0 END,
    sip_tarih DESC,
    cari_adi,
    urun_adi;
SQL_ORDERS_ALINAN,
            ['search', 'date_from', 'date_to', 'status', 'rep_code', 'orders_scope', 'brand_filter', 'product_filter', 'page', 'limit', 'bypass_cache'],
            'EMAKS PRIME - Siparisler Workflow (TAM FIX) Code - Build SQL Alinan node sorgusu.',
            'EMAKS PRIME - Siparisler Workflow (TAM FIX).json'
        );

        $this->upsert(
            'orders_verilen',
            'Verilen Siparişler',
            <<<'SQL_ORDERS_VERILEN'
DECLARE @BasTar DATE = COALESCE(TRY_CONVERT(date, NULLIF(N'[[date_from]]', N'')), CONVERT(date, '2025-01-01'));
DECLARE @BitTar DATE = COALESCE(TRY_CONVERT(date, NULLIF(N'[[date_to]]', N'')), CONVERT(date, GETDATE()));
DECLARE @Search NVARCHAR(255) = LTRIM(RTRIM(N'[[search]]'));
DECLARE @BrandFilter NVARCHAR(50) = LOWER(REPLACE(LTRIM(RTRIM(N'[[brand_filter]]')), N'-', N'_'));
DECLARE @ProductFilter NVARCHAR(MAX) = LTRIM(RTRIM(N'[[product_filter]]'));
DECLARE @DeliveryWeek NVARCHAR(120) = LTRIM(RTRIM(N'[[delivery_week]]'));
DECLARE @DeliveryDate DATE = TRY_CONVERT(date, NULLIF(N'[[delivery_date]]', N''));
DECLARE @Limit INT = TRY_CONVERT(int, NULLIF(N'[[limit]]', N''));

IF @BrandFilter = N''
    SET @BrandFilter = N'all';

IF @DeliveryWeek = N''
    SET @DeliveryWeek = N'all';

IF @DeliveryDate IS NOT NULL AND @DeliveryWeek IN (N'all', N'tumu')
    SET @DeliveryWeek =
        CASE DATEPART(MONTH, @DeliveryDate)
            WHEN 1 THEN N'OCAK'
            WHEN 2 THEN N'ŞUBAT'
            WHEN 3 THEN N'MART'
            WHEN 4 THEN N'NİSAN'
            WHEN 5 THEN N'MAYIS'
            WHEN 6 THEN N'HAZİRAN'
            WHEN 7 THEN N'TEMMUZ'
            WHEN 8 THEN N'AĞUSTOS'
            WHEN 9 THEN N'EYLÜL'
            WHEN 10 THEN N'EKİM'
            WHEN 11 THEN N'KASIM'
            WHEN 12 THEN N'ARALIK'
            ELSE N''
        END + N'''IN ' +
        CASE
            WHEN DATEPART(DAY, @DeliveryDate) BETWEEN 1 AND 7 THEN N'1. HAFTASI'
            WHEN DATEPART(DAY, @DeliveryDate) BETWEEN 8 AND 14 THEN N'2. HAFTASI'
            WHEN DATEPART(DAY, @DeliveryDate) BETWEEN 15 AND 21 THEN N'3. HAFTASI'
            ELSE N'4. HAFTASI'
        END;

IF @Limit IS NULL OR @Limit <= 0
    SET @Limit = 500;

WITH VerilenSiparisler AS
(
    SELECT
        sip.sip_Guid,
        sip.sip_tarih,
        sip.sip_teslim_tarih,
        sip.sip_evrakno_seri,
        sip.sip_evrakno_sira,
        sip.sip_stok_kod,
        sto.sto_isim AS stok_adi,
        sto.sto_kategori_kodu,
        ktg.ktg_isim AS stok_kategori_adi,
        mdl.mdl_ismi AS model_adi,
        LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N''))) AS brand_code,
        CASE
            WHEN UPPER(LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N'')))) = N'PHILIPS' THEN N'philips'
            WHEN UPPER(LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N'')))) IN (N'EMAKS PRIME', N'EMAKS') THEN N'emaks_prime'
            ELSE N'other'
        END AS brand_key,
        CASE
            WHEN UPPER(LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N'')))) = N'PHILIPS' THEN N'PHILIPS'
            WHEN UPPER(LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N'')))) IN (N'EMAKS PRIME', N'EMAKS') THEN N'EMAKS PRIME'
            ELSE N'Diğer Marka'
        END AS marka,
        ISNULL(sip.sip_miktar, 0) AS siparis_miktari,
        ISNULL(sip.sip_teslim_miktar, 0) AS teslim_miktari,
        ISNULL(sip.sip_miktar, 0) - ISNULL(sip.sip_teslim_miktar, 0) AS kalan_miktar,
        ISNULL(sip.sip_b_fiyat, 0) AS birim_fiyat,
        ISNULL(sip.sip_tutar, 0) AS sip_tutar,
        ISNULL(sip.sip_iskonto_1, 0) AS iskonto_1,
        ISNULL(sip.sip_iskonto_2, 0) AS iskonto_2,
        ISNULL(sip.sip_iskonto_3, 0) AS iskonto_3,
        sip.sip_tip,
        sip.sip_kapat_fl,
        sip.sip_iptal
    FROM SIPARISLER sip
    LEFT JOIN STOKLAR sto
        ON sto.sto_kod = sip.sip_stok_kod
    LEFT JOIN STOK_KATEGORILERI ktg
        ON ktg.ktg_kod = sto.sto_kategori_kodu
    LEFT JOIN STOK_MODEL_TANIMLARI mdl
        ON mdl.mdl_kodu = sto.sto_model_kodu
    WHERE
        sip.sip_iptal = 0
        AND sip.sip_tip = 1
        AND sip.sip_kapat_fl = 0
        AND CAST(sip.sip_tarih AS date) >= @BasTar
        AND CAST(sip.sip_tarih AS date) <= @BitTar
        AND ISNULL(sip.sip_miktar, 0) - ISNULL(sip.sip_teslim_miktar, 0) > 0
        AND (
            @BrandFilter IN (N'all', N'tumu')
            OR (@BrandFilter = N'philips' AND UPPER(LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N'')))) = N'PHILIPS')
            OR (@BrandFilter = N'emaks_prime' AND UPPER(LTRIM(RTRIM(ISNULL(sto.sto_marka_kodu, N'')))) IN (N'EMAKS PRIME', N'EMAKS'))
        )
        AND (
            @ProductFilter = N''
            OR EXISTS (
                SELECT 1
                FROM STRING_SPLIT(@ProductFilter, N',') pf
                WHERE LTRIM(RTRIM(pf.value)) <> N''
                    AND (
                        sip.sip_stok_kod LIKE N'%' + LTRIM(RTRIM(pf.value)) + N'%'
                        OR sto.sto_isim LIKE N'%' + LTRIM(RTRIM(pf.value)) + N'%'
                        OR mdl.mdl_ismi LIKE N'%' + LTRIM(RTRIM(pf.value)) + N'%'
                    )
            )
        )
)
SELECT
    TOP (@Limit)
    CONVERT(varchar(10), sip_teslim_tarih, 23) AS teslim_tarihi,
    CONVERT(varchar(10), sip_teslim_tarih, 104) AS teslim_tarihi_gosterim,
    CASE
        WHEN sip_teslim_tarih IS NULL THEN N'TESLİM TARİHİ BELİRSİZ'
        ELSE
            CASE DATEPART(MONTH, sip_teslim_tarih)
                WHEN 1 THEN N'OCAK'
                WHEN 2 THEN N'ŞUBAT'
                WHEN 3 THEN N'MART'
                WHEN 4 THEN N'NİSAN'
                WHEN 5 THEN N'MAYIS'
                WHEN 6 THEN N'HAZİRAN'
                WHEN 7 THEN N'TEMMUZ'
                WHEN 8 THEN N'AĞUSTOS'
                WHEN 9 THEN N'EYLÜL'
                WHEN 10 THEN N'EKİM'
                WHEN 11 THEN N'KASIM'
                WHEN 12 THEN N'ARALIK'
                ELSE N''
            END + N'''IN ' +
            CASE
                WHEN DATEPART(DAY, sip_teslim_tarih) BETWEEN 1 AND 7 THEN N'1. HAFTASI'
                WHEN DATEPART(DAY, sip_teslim_tarih) BETWEEN 8 AND 14 THEN N'2. HAFTASI'
                WHEN DATEPART(DAY, sip_teslim_tarih) BETWEEN 15 AND 21 THEN N'3. HAFTASI'
                ELSE N'4. HAFTASI'
            END
    END AS tahmini_teslim_haftasi,
    CASE WHEN sip_teslim_tarih IS NULL THEN 99991231 ELSE CONVERT(int, CONVERT(char(8), sip_teslim_tarih, 112)) END AS teslim_sira,
    sip_stok_kod AS [stok_kodu],
    ISNULL(NULLIF(model_adi, N''), stok_adi) AS [stok_adi],
    stok_kategori_adi,
    brand_code,
    brand_key,
    marka,
    kalan_miktar AS [siparis_miktari],
    ROUND(
        CASE
            WHEN ISNULL(siparis_miktari, 0) = 0 THEN 0
            ELSE (
                (
                    ISNULL(sip_tutar, 0)
                    - ISNULL(iskonto_1, 0)
                    - ISNULL(iskonto_2, 0)
                    - ISNULL(iskonto_3, 0)
                ) / siparis_miktari
            )
        END, 2
    ) AS birim_fiyat,
    ROUND(
        CASE
            WHEN ISNULL(siparis_miktari, 0) = 0 THEN 0
            ELSE (
                (
                    ISNULL(sip_tutar, 0)
                    - ISNULL(iskonto_1, 0)
                    - ISNULL(iskonto_2, 0)
                    - ISNULL(iskonto_3, 0)
                ) / siparis_miktari
            ) * kalan_miktar
        END, 2
    ) AS siparis_tutari
FROM VerilenSiparisler
WHERE
    (
        @Search = N''
        OR sip_stok_kod LIKE N'%' + @Search + N'%'
        OR ISNULL(NULLIF(model_adi, N''), stok_adi) LIKE N'%' + @Search + N'%'
        OR stok_kategori_adi LIKE N'%' + @Search + N'%'
    )
    AND (
        @DeliveryWeek IN (N'all', N'tumu')
        OR (
            CASE
                WHEN sip_teslim_tarih IS NULL THEN N'TESLİM TARİHİ BELİRSİZ'
                ELSE
                    CASE DATEPART(MONTH, sip_teslim_tarih)
                        WHEN 1 THEN N'OCAK'
                        WHEN 2 THEN N'ŞUBAT'
                        WHEN 3 THEN N'MART'
                        WHEN 4 THEN N'NİSAN'
                        WHEN 5 THEN N'MAYIS'
                        WHEN 6 THEN N'HAZİRAN'
                        WHEN 7 THEN N'TEMMUZ'
                        WHEN 8 THEN N'AĞUSTOS'
                        WHEN 9 THEN N'EYLÜL'
                        WHEN 10 THEN N'EKİM'
                        WHEN 11 THEN N'KASIM'
                        WHEN 12 THEN N'ARALIK'
                        ELSE N''
                    END + N'''IN ' +
                    CASE
                        WHEN DATEPART(DAY, sip_teslim_tarih) BETWEEN 1 AND 7 THEN N'1. HAFTASI'
                        WHEN DATEPART(DAY, sip_teslim_tarih) BETWEEN 8 AND 14 THEN N'2. HAFTASI'
                        WHEN DATEPART(DAY, sip_teslim_tarih) BETWEEN 15 AND 21 THEN N'3. HAFTASI'
                        ELSE N'4. HAFTASI'
                    END
            END
        ) = @DeliveryWeek
    )
ORDER BY
    CASE WHEN sip_teslim_tarih IS NULL THEN 1 ELSE 0 END,
    sip_teslim_tarih ASC,
    ISNULL(NULLIF(model_adi, N''), stok_adi);
SQL_ORDERS_VERILEN,
            ['search', 'date_from', 'date_to', 'brand_filter', 'product_filter', 'delivery_week', 'delivery_date', 'page', 'limit', 'bypass_cache'],
            'EMAKS PRIME - Siparisler Workflow (TAM FIX) Code - Build SQL Verilen node sorgusu.',
            'EMAKS PRIME - Siparisler Workflow (TAM FIX).json'
        );

        $this->upsert(
            'customers_list',
            'Müşteri Listesi',
            <<<'SQL_CUSTOMERS_LIST'
DECLARE @Search NVARCHAR(255) = N'[[search]]';
DECLARE @RepCode NVARCHAR(50) = N'[[rep_code]]';
DECLARE @CanViewAll bit = CASE WHEN NULLIF(LTRIM(RTRIM(ISNULL(@RepCode, N''))), N'') IS NULL THEN 1 ELSE 0 END;
DECLARE @CustomerScopeKey NVARCHAR(80) = N'[[customer_scope_key]]';
DECLARE @PanelFilter NVARCHAR(50) = N'[[scope_key]]';
DECLARE @Take int = 200;

WITH CariBaz AS
(
    SELECT
        LTRIM(RTRIM(ISNULL(cari.cari_kod, N''))) AS CariKodu,
        LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))) AS FirmaUnvani,
        LTRIM(RTRIM(ISNULL(cari.cari_unvan2, N''))) AS FirmaUnvani2,
        LTRIM(RTRIM(ISNULL(grp.crg_isim, N''))) AS Grup,
        LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) AS TemsilciKodu,
        LTRIM(RTRIM(ISNULL(cpt.cari_per_adi, N'') + CASE WHEN ISNULL(cpt.cari_per_soyadi, N'') = N'' THEN N'' ELSE N' ' + cpt.cari_per_soyadi END)) AS TemsilciAdi,
        CAST(ISNULL(
            CASE
                WHEN Cari_F10da_detay = 1 THEN dbo.fn_CariHesapAnaDovizBakiye('',0,cari.cari_kod,'','',NULL,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
                WHEN Cari_F10da_detay = 2 THEN dbo.fn_CariHesapAlternatifDovizBakiye('',0,cari.cari_kod,'','',NULL,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
                WHEN Cari_F10da_detay = 3 THEN dbo.fn_CariHesapOrjinalDovizBakiye('',0,cari.cari_kod,'','',0,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
                WHEN Cari_F10da_detay = 4 THEN dbo.fn_CariHareketSayisi(0,cari.cari_kod,'')
                ELSE 0
            END, 0) AS decimal(18,2)
        ) AS BakiyeDurumu
    FROM dbo.CARI_HESAPLAR cari WITH (NOLOCK)
    LEFT OUTER JOIN dbo.CARI_HESAP_GRUPLARI grp WITH (NOLOCK) ON grp.crg_kod = cari.cari_grup_kodu
    LEFT OUTER JOIN dbo.CARI_PERSONEL_TANIMLARI cpt WITH (NOLOCK) ON cpt.cari_per_kod = cari.cari_temsilci_kodu
    LEFT OUTER JOIN dbo.vw_Gendata ON 1 = 1
    WHERE
        ((cari.cari_kod NOT LIKE N'320%' AND cari.cari_kod NOT LIKE N'331%') OR cari.cari_kod LIKE N'320.ÇLG%')
        AND (@CanViewAll = 1 OR LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode)
        AND (
            @CustomerScopeKey IN (N'', N'all', N'all_segments')
            OR (@CustomerScopeKey = N'own_rep' AND LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode)
            OR (@CustomerScopeKey = N'online_perakende' AND ISNULL(cari.cari_grup_kodu, N'') IN (N'120.01', N'120.02', N'120.03', N'120.04', N'120.05', N'120.06', N'120.07', N'120.08', N'120.09', N'120.16'))
            OR (@CustomerScopeKey = N'bayi_proje' AND (NULLIF(LTRIM(RTRIM(ISNULL(cari.cari_grup_kodu, N''))), N'') IS NULL OR ISNULL(cari.cari_grup_kodu, N'') NOT IN (N'120.01', N'120.02', N'120.03', N'120.04', N'120.05', N'120.06', N'120.07', N'120.08', N'120.09', N'120.16')))
        )
        AND (@Search = N'' OR cari.cari_kod LIKE N'%' + @Search + N'%' OR cari.cari_unvan1 LIKE N'%' + @Search + N'%' OR cari.cari_unvan2 LIKE N'%' + @Search + N'%' OR ISNULL(grp.crg_isim, N'') LIKE N'%' + @Search + N'%' OR ISNULL(cpt.cari_per_adi, N'') LIKE N'%' + @Search + N'%' OR ISNULL(cpt.cari_per_soyadi, N'') LIKE N'%' + @Search + N'%')
),
SiparisHam AS
(
    SELECT
        sip.sip_tarih,
        sip.sip_evrakno_seri,
        sip.sip_aciklama2,
        LTRIM(RTRIM(ISNULL(sip.sip_musteri_kod, N''))) AS CariKodu,
        sto.sto_isim AS stok_adi,
        mdl.mdl_ismi AS model_adi,
        LTRIM(RTRIM(CASE WHEN ISNULL(sip.sip_cari_sormerk, N'') <> N'' THEN sip.sip_cari_sormerk WHEN ISNULL(sip.sip_stok_sormerk, N'') <> N'' THEN sip.sip_stok_sormerk ELSE N'' END)) AS sorumluluk_kodu,
        ISNULL(sip.sip_miktar, 0) AS siparis_miktar,
        ISNULL(sip.sip_teslim_miktar, 0) AS teslim_miktar,
        ISNULL(sip.sip_miktar, 0) - ISNULL(sip.sip_teslim_miktar, 0) AS kalan_miktar,
        ISNULL(sip.sip_tutar, 0) AS sip_tutar,
        ISNULL(sip.sip_iskonto_1, 0) AS iskonto_1,
        ISNULL(sip.sip_iskonto_2, 0) AS iskonto_2,
        ISNULL(sip.sip_iskonto_3, 0) AS iskonto_3
    FROM dbo.SIPARISLER sip WITH (NOLOCK)
    INNER JOIN CariBaz cb ON cb.CariKodu = LTRIM(RTRIM(ISNULL(sip.sip_musteri_kod, N'')))
    LEFT JOIN dbo.CARI_HESAPLAR cari WITH (NOLOCK) ON cari.cari_kod = sip.sip_musteri_kod
    LEFT JOIN dbo.CARI_HESAP_GRUPLARI crg WITH (NOLOCK) ON crg.crg_kod = cari.cari_grup_kodu
    LEFT JOIN dbo.STOKLAR sto WITH (NOLOCK) ON sto.sto_kod = sip.sip_stok_kod
    LEFT JOIN dbo.STOK_MODEL_TANIMLARI mdl WITH (NOLOCK) ON mdl.mdl_kodu = sto.sto_model_kodu
    WHERE
        ISNULL(sip.sip_iptal, 0) = 0
        AND ISNULL(sip.sip_tip, 0) = 0
        AND ISNULL(sip.sip_kapat_fl, 0) = 0
        AND CAST(sip.sip_tarih AS date) >= '2025-01-01'
        AND ISNULL(sip.sip_miktar, 0) - ISNULL(sip.sip_teslim_miktar, 0) > 0
        AND UPPER(LTRIM(RTRIM(ISNULL(crg.crg_isim, N'')))) NOT LIKE N'%İHRACAT%'
),
SiparisHesaplanmis AS
(
    SELECT
        sip_evrakno_seri,
        CariKodu,
        CASE WHEN UPPER(ISNULL(stok_adi, N'')) LIKE N'%STAND%' OR UPPER(ISNULL(model_adi, N'')) LIKE N'%STAND%' OR UPPER(ISNULL(ISNULL(NULLIF(model_adi, N''), stok_adi), N'')) LIKE N'%STAND%' THEN stok_adi ELSE ISNULL(NULLIF(model_adi, N''), stok_adi) END AS urun_adi,
        sorumluluk_kodu,
        kalan_miktar,
        ROUND(CASE WHEN ISNULL(siparis_miktar, 0) = 0 THEN 0 ELSE ((ISNULL(sip_tutar, 0) - ISNULL(iskonto_1, 0) - ISNULL(iskonto_2, 0) - ISNULL(iskonto_3, 0)) / siparis_miktar) * kalan_miktar END, 2) AS kalan_tutar
    FROM SiparisHam
),
SiparisNormalizeEdilmis AS
(
    SELECT
        sip_evrakno_seri,
        CariKodu,
        kalan_miktar,
        kalan_tutar,
        UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(ISNULL(urun_adi, N''), N'İ', N'I'), N'I', N'I'), N'ı', N'I'), N'Ö', N'O'), N'ö', N'O'), N'Ü', N'U'), N'ü', N'U'), N'Ç', N'C'), N'ç', N'C'), N'Ş', N'S')) AS urun_adi_norm,
        UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(ISNULL(sorumluluk_kodu, N''), N'İ', N'I'), N'I', N'I'), N'ı', N'I'), N'Ö', N'O'), N'ö', N'O'), N'Ü', N'U'), N'ü', N'U'), N'Ç', N'C'), N'ç', N'C'), N'Ş', N'S')) AS sorumluluk_norm
    FROM SiparisHesaplanmis
),
SiparisFiltreli AS
(
    SELECT sip_evrakno_seri, CariKodu, kalan_miktar, kalan_tutar
    FROM SiparisNormalizeEdilmis
    WHERE
        urun_adi_norm NOT LIKE N'%KILIT DONUSUM APARAT%'
        AND urun_adi_norm NOT LIKE N'%KILIT DONUSUM APATAR%'
        AND urun_adi_norm NOT LIKE N'%DONUSUM APARAT%'
        AND urun_adi_norm NOT LIKE N'%DONUSUM APATAR%'
        AND NOT (urun_adi_norm LIKE N'%YEDEK PARCA%' AND kalan_tutar <= 10)
        AND (NOT (sorumluluk_norm LIKE N'%DEGISIM%' OR sorumluluk_norm LIKE N'%GARANTI DISI KONTROL%' OR sorumluluk_norm LIKE N'%GARANTI KAPSAMI KONTROL%' OR LTRIM(RTRIM(sorumluluk_norm)) = N'GR') OR kalan_tutar > 10)
),
SiparisOzet AS
(
    SELECT
        CariKodu,
        CAST(SUM(CASE WHEN ISNULL(sip_evrakno_seri, N'') <> N'B' THEN kalan_miktar ELSE 0 END) AS decimal(18,2)) AS AcikSiparisAdet,
        CAST(SUM(CASE WHEN ISNULL(sip_evrakno_seri, N'') = N'B' THEN kalan_miktar ELSE 0 END) AS decimal(18,2)) AS BekleyenSiparisAdet,
        CAST(SUM(CASE WHEN ISNULL(sip_evrakno_seri, N'') <> N'B' THEN kalan_tutar ELSE 0 END) AS decimal(18,2)) AS AcikSiparisTutar,
        CAST(SUM(CASE WHEN ISNULL(sip_evrakno_seri, N'') = N'B' THEN kalan_tutar ELSE 0 END) AS decimal(18,2)) AS BekleyenSiparisTutar
    FROM SiparisFiltreli
    GROUP BY CariKodu
),
CariFinal AS
(
    SELECT
        cb.CariKodu,
        cb.FirmaUnvani,
        cb.FirmaUnvani2,
        cb.Grup,
        cb.TemsilciKodu,
        cb.TemsilciAdi,
        cb.BakiyeDurumu,
        CAST(ISNULL(so.AcikSiparisAdet, 0) AS decimal(18,2)) AS AcikSiparisAdet,
        CAST(ISNULL(so.AcikSiparisTutar, 0) AS decimal(18,2)) AS AcikSiparisTutar,
        CAST(ISNULL(so.BekleyenSiparisAdet, 0) AS decimal(18,2)) AS BekleyenSiparisAdet,
        CAST(ISNULL(so.BekleyenSiparisTutar, 0) AS decimal(18,2)) AS BekleyenSiparisTutar,
        CAST(cb.BakiyeDurumu + ISNULL(so.AcikSiparisTutar, 0) AS decimal(18,2)) AS GenelDurumTutar
    FROM CariBaz cb
    LEFT JOIN SiparisOzet so ON so.CariKodu = cb.CariKodu
),
FilteredFinal AS
(
    SELECT *
    FROM CariFinal
    WHERE
        @PanelFilter = N''
        OR @PanelFilter = N'all'
        OR (@PanelFilter = N'receivable' AND BakiyeDurumu > 0)
        OR (@PanelFilter = N'payable' AND BakiyeDurumu < 0)
        OR (@PanelFilter = N'approvedOrders' AND AcikSiparisTutar > 0)
        OR (@PanelFilter = N'generalOpen' AND GenelDurumTutar <> 0)
        OR (@PanelFilter = N'pendingOrders' AND BekleyenSiparisTutar > 0)
),
SummaryTotals AS
(
    SELECT
        CAST(ISNULL(SUM(CASE WHEN BakiyeDurumu > 0 THEN BakiyeDurumu ELSE 0 END), 0) AS decimal(18,2)) AS ToplamAlacakBakiyesi,
        CAST(ABS(ISNULL(SUM(CASE WHEN BakiyeDurumu < 0 THEN BakiyeDurumu ELSE 0 END), 0)) AS decimal(18,2)) AS ToplamBorcBakiyesi,
        CAST(ISNULL(SUM(AcikSiparisTutar), 0) AS decimal(18,2)) AS ToplamOnayliAcikSiparis,
        CAST(ISNULL(SUM(BekleyenSiparisTutar), 0) AS decimal(18,2)) AS ToplamOnayBekleyenSiparis,
        CAST(ISNULL(SUM(BakiyeDurumu + AcikSiparisTutar), 0) AS decimal(18,2)) AS GenelSonuc,
        COUNT(1) AS ToplamCariSayisi
    FROM CariFinal
)
SELECT TOP (@Take)
    CariKodu AS [musteri_kodu],
    FirmaUnvani AS [firma_unvani],
    FirmaUnvani AS [musteri_adi],
    FirmaUnvani2 AS [firma_unvani_2],
    Grup AS [grup],
    TemsilciKodu AS [temsilci_kodu],
    TemsilciAdi AS [temsilci],
    BakiyeDurumu AS [bakiye_durumu],
    BakiyeDurumu AS [bakiye],
    AcikSiparisAdet AS [acik_siparis_adet],
    AcikSiparisTutar AS [acik_siparis_tutar],
    AcikSiparisTutar AS [onayli_acik_siparis_tutari],
    GenelDurumTutar AS [genel_durum_tutar],
    GenelDurumTutar AS [genel_durum],
    BekleyenSiparisAdet AS [bekleyen_siparis_adet],
    BekleyenSiparisTutar AS [bekleyen_siparis_tutar],
    BekleyenSiparisTutar AS [onay_bekleyen_siparis_tutari],
    st.ToplamAlacakBakiyesi AS [toplam_alacak_bakiyesi],
    st.ToplamBorcBakiyesi AS [toplam_borc_bakiyesi],
    st.ToplamOnayliAcikSiparis AS [toplam_onayli_acik_siparis],
    st.ToplamOnayBekleyenSiparis AS [toplam_onay_bekleyen_siparis],
    st.GenelSonuc AS [genel_sonuc],
    st.ToplamCariSayisi AS [toplam_cari_sayisi]
FROM FilteredFinal
CROSS JOIN SummaryTotals st
ORDER BY
    CASE WHEN @PanelFilter IN (N'approvedOrders', N'pendingOrders') THEN 0 ELSE 1 END,
    CASE WHEN @PanelFilter = N'approvedOrders' THEN AcikSiparisTutar END DESC,
    CASE WHEN @PanelFilter = N'pendingOrders' THEN BekleyenSiparisTutar END DESC,
    CariKodu ASC;
SQL_CUSTOMERS_LIST,
            ['search', 'scope_key', 'rep_code', 'customer_scope_key', 'customer_group_scope', 'page', 'bypass_cache'],
            'PrimeCRM CariService.SearchAsync müşteri liste ve bakiye mantığından uyarlanan kanonik sorgu.',
            'CariService.cs'
        );

        $this->upsert(
            'customers_balance',
            'Müşteri Bakiye Özeti',
            <<<'SQL_CUSTOMERS_BALANCE'
DECLARE @Search NVARCHAR(255) = N'[[search]]';
DECLARE @RepCode NVARCHAR(50) = N'[[rep_code]]';
DECLARE @CanViewAll bit = CASE WHEN NULLIF(LTRIM(RTRIM(ISNULL(@RepCode, N''))), N'') IS NULL THEN 1 ELSE 0 END;
DECLARE @CustomerScopeKey NVARCHAR(80) = N'[[customer_scope_key]]';

WITH CariScope AS
(
    SELECT
        LTRIM(RTRIM(ISNULL(cari.cari_kod, N''))) AS [musteri_kodu],
        LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))) AS [firma_unvani],
        LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))) AS [musteri_adi],
        LTRIM(RTRIM(ISNULL(cari.cari_unvan2, N''))) AS [firma_unvani_2],
        LTRIM(RTRIM(ISNULL(grp.crg_isim, N''))) AS [grup],
        LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) AS [temsilci_kodu],
        LTRIM(RTRIM(ISNULL(cpt.cari_per_adi, N'') + CASE WHEN ISNULL(cpt.cari_per_soyadi, N'') = N'' THEN N'' ELSE N' ' + cpt.cari_per_soyadi END)) AS [temsilci],
        CAST(ISNULL(
            CASE
                WHEN Cari_F10da_detay = 1 THEN dbo.fn_CariHesapAnaDovizBakiye('',0,cari.cari_kod,'','',NULL,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
                WHEN Cari_F10da_detay = 2 THEN dbo.fn_CariHesapAlternatifDovizBakiye('',0,cari.cari_kod,'','',NULL,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
                WHEN Cari_F10da_detay = 3 THEN dbo.fn_CariHesapOrjinalDovizBakiye('',0,cari.cari_kod,'','',0,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
                WHEN Cari_F10da_detay = 4 THEN dbo.fn_CariHareketSayisi(0,cari.cari_kod,'')
                ELSE 0
            END, 0) AS decimal(18,2)
        ) AS [net_bakiye]
    FROM dbo.CARI_HESAPLAR cari WITH (NOLOCK)
    LEFT OUTER JOIN dbo.CARI_HESAP_GRUPLARI grp WITH (NOLOCK) ON grp.crg_kod = cari.cari_grup_kodu
    LEFT OUTER JOIN dbo.CARI_PERSONEL_TANIMLARI cpt WITH (NOLOCK) ON cpt.cari_per_kod = cari.cari_temsilci_kodu
    LEFT OUTER JOIN dbo.vw_Gendata ON 1 = 1
    WHERE
        ((cari.cari_kod NOT LIKE N'320%' AND cari.cari_kod NOT LIKE N'331%') OR cari.cari_kod LIKE N'320.ÇLG%')
        AND (@CanViewAll = 1 OR LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode)
        AND (
            @CustomerScopeKey IN (N'', N'all', N'all_segments')
            OR (@CustomerScopeKey = N'own_rep' AND LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode)
            OR (@CustomerScopeKey = N'online_perakende' AND ISNULL(cari.cari_grup_kodu, N'') IN (N'120.01', N'120.02', N'120.03', N'120.04', N'120.05', N'120.06', N'120.07', N'120.08', N'120.09', N'120.16'))
            OR (@CustomerScopeKey = N'bayi_proje' AND (NULLIF(LTRIM(RTRIM(ISNULL(cari.cari_grup_kodu, N''))), N'') IS NULL OR ISNULL(cari.cari_grup_kodu, N'') NOT IN (N'120.01', N'120.02', N'120.03', N'120.04', N'120.05', N'120.06', N'120.07', N'120.08', N'120.09', N'120.16')))
        )
        AND (@Search = N'' OR cari.cari_kod LIKE N'%' + @Search + N'%' OR cari.cari_unvan1 LIKE N'%' + @Search + N'%' OR cari.cari_unvan2 LIKE N'%' + @Search + N'%' OR ISNULL(grp.crg_isim, N'') LIKE N'%' + @Search + N'%' OR ISNULL(cpt.cari_per_adi, N'') LIKE N'%' + @Search + N'%' OR ISNULL(cpt.cari_per_soyadi, N'') LIKE N'%' + @Search + N'%')
)
SELECT
    musteri_kodu,
    firma_unvani,
    musteri_adi,
    firma_unvani_2,
    grup,
    temsilci_kodu,
    temsilci,
    CAST(CASE WHEN net_bakiye < 0 THEN ABS(net_bakiye) ELSE 0 END AS decimal(18,2)) AS [borc],
    CAST(CASE WHEN net_bakiye > 0 THEN net_bakiye ELSE 0 END AS decimal(18,2)) AS [alacak],
    net_bakiye,
    net_bakiye AS [bakiye_durumu]
FROM CariScope
WHERE net_bakiye <> 0
ORDER BY ABS(net_bakiye) DESC, musteri_kodu ASC;
SQL_CUSTOMERS_BALANCE,
            ['search', 'rep_code', 'customer_scope_key', 'customer_group_scope', 'page', 'bypass_cache'],
            'PrimeCRM CariService.GetSearchSummaryAsync bakiye hesaplama mantığından müşteri bazlı liste sorgusu.',
            'CariService.cs'
        );

        $this->upsert(
            'customer_statement',
            'Müşteri Ekstre',
            <<<'SQL_CUSTOMER_STATEMENT'
DECLARE @CustomerCode NVARCHAR(80) = N'[[customer_code]]';
DECLARE @DateFrom DATE = '[[date_from]]';
DECLARE @DateTo DATE = '[[date_to]]';
DECLARE @RepCode NVARCHAR(50) = N'[[rep_code]]';
DECLARE @CanViewAll bit = CASE WHEN NULLIF(LTRIM(RTRIM(ISNULL(@RepCode, N''))), N'') IS NULL THEN 1 ELSE 0 END;
DECLARE @CustomerScopeKey NVARCHAR(80) = N'[[customer_scope_key]]';

;WITH Hareketler AS
(
    SELECT
        cha.cha_Guid AS [hareket_guid],
        CAST(cha.cha_tarihi AS date) AS [tarih],
        CASE cha.cha_evrak_tip
            WHEN 0 THEN N'Açılış Fişi'
            WHEN 1 THEN N'Tahsilat Makbuzu'
            WHEN 2 THEN N'Tediye Makbuzu'
            WHEN 3 THEN N'Gelen Havale'
            WHEN 4 THEN N'Gönderilen Havale'
            WHEN 5 THEN N'Mahsup Fişi'
            WHEN 6 THEN N'Satış Faturası'
            WHEN 7 THEN N'Alış Faturası'
            WHEN 8 THEN N'Portföye Giriş Bordrosu'
            WHEN 9 THEN N'Portföyden Çıkış Bordrosu'
            WHEN 10 THEN N'Çek/Senet Bordrosu'
            WHEN 13 THEN N'Gelen Fatura'
            WHEN 63 THEN N'Satış İrsaliyesi'
            ELSE N'Evrak Tipi ' + CONVERT(nvarchar(10), cha.cha_evrak_tip)
        END AS [evrak_tipi],
        LTRIM(RTRIM(ISNULL(cha.cha_evrakno_seri, N''))) AS [evrak_seri],
        ISNULL(cha.cha_evrakno_sira, 0) AS [evrak_sira],
        LTRIM(RTRIM(CONCAT(ISNULL(cha.cha_evrakno_seri, N''), CASE WHEN ISNULL(cha.cha_evrakno_seri, N'') = N'' THEN N'' ELSE N'-' END, CONVERT(nvarchar(30), ISNULL(cha.cha_evrakno_sira, 0))))) AS [evrak_no],
        LTRIM(RTRIM(ISNULL(cha.cha_belge_no, N''))) AS [belge_no],
        LTRIM(RTRIM(ISNULL(cha.cha_aciklama, N''))) AS [aciklama],
        CAST(CASE WHEN ISNULL(cha.cha_tip, 0) = 0 THEN ISNULL(cha.cha_meblag, 0) ELSE 0 END AS decimal(18,2)) AS [borc],
        CAST(CASE WHEN ISNULL(cha.cha_tip, 0) = 1 THEN ISNULL(cha.cha_meblag, 0) ELSE 0 END AS decimal(18,2)) AS [alacak],
        cha.cha_Guid AS SortGuid
    FROM dbo.CARI_HESAP_HAREKETLERI cha WITH (NOLOCK)
    INNER JOIN dbo.CARI_HESAPLAR cari WITH (NOLOCK) ON cari.cari_kod = cha.cha_kod
    WHERE cha.cha_kod = @CustomerCode
      AND cha.cha_tarihi >= @DateFrom
      AND cha.cha_tarihi < DATEADD(day, 1, @DateTo)
      AND ISNULL(cha.cha_iptal, 0) = 0
      AND (@CanViewAll = 1 OR LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode)
      AND (
          @CustomerScopeKey IN (N'', N'all', N'all_segments')
          OR (@CustomerScopeKey = N'own_rep' AND LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode)
          OR (@CustomerScopeKey = N'online_perakende' AND ISNULL(cari.cari_grup_kodu, N'') IN (N'120.01', N'120.02', N'120.03', N'120.04', N'120.05', N'120.06', N'120.07', N'120.08', N'120.09', N'120.16'))
          OR (@CustomerScopeKey = N'bayi_proje' AND (NULLIF(LTRIM(RTRIM(ISNULL(cari.cari_grup_kodu, N''))), N'') IS NULL OR ISNULL(cari.cari_grup_kodu, N'') NOT IN (N'120.01', N'120.02', N'120.03', N'120.04', N'120.05', N'120.06', N'120.07', N'120.08', N'120.09', N'120.16')))
      )
)
SELECT
    hareket_guid,
    tarih,
    evrak_tipi,
    evrak_seri,
    evrak_sira,
    evrak_no,
    belge_no,
    aciklama,
    borc,
    alacak,
    CAST(SUM(borc - alacak) OVER (ORDER BY tarih ASC, SortGuid ASC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS decimal(18,2)) AS [bakiye]
FROM Hareketler
ORDER BY tarih ASC, SortGuid ASC;
SQL_CUSTOMER_STATEMENT,
            ['customer_code', 'date_from', 'date_to', 'rep_code', 'customer_scope_key', 'customer_group_scope', 'bypass_cache'],
            'PrimeCRM CariService.GetStatementRowsAsync ekstre sorgusu.',
            'CariService.cs'
        );

        $this->upsert(
            'customer_detail',
            'Müşteri Detay',
            <<<'SQL_CUSTOMER_DETAIL'
DECLARE @CustomerCode NVARCHAR(80) = N'[[customer_code]]';
DECLARE @RepCode NVARCHAR(50) = N'[[rep_code]]';
DECLARE @CanViewAll bit = CASE WHEN NULLIF(LTRIM(RTRIM(ISNULL(@RepCode, N''))), N'') IS NULL THEN 1 ELSE 0 END;
DECLARE @CustomerScopeKey NVARCHAR(80) = N'[[customer_scope_key]]';

SELECT TOP 1
    LTRIM(RTRIM(ISNULL(cari.cari_kod, N''))) AS [musteri_kodu],
    LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))) AS [firma_unvani],
    LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))) AS [musteri_adi],
    LTRIM(RTRIM(ISNULL(cari.cari_unvan2, N''))) AS [firma_unvani_2],
    LTRIM(RTRIM(ISNULL(grp.crg_isim, N''))) AS [grup],
    LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) AS [temsilci_kodu],
    LTRIM(RTRIM(ISNULL(cpt.cari_per_adi, N'') + CASE WHEN ISNULL(cpt.cari_per_soyadi, N'') = N'' THEN N'' ELSE N' ' + cpt.cari_per_soyadi END)) AS [temsilci],
    CAST(ISNULL(
        CASE
            WHEN Cari_F10da_detay = 1 THEN dbo.fn_CariHesapAnaDovizBakiye('',0,cari.cari_kod,'','',NULL,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
            WHEN Cari_F10da_detay = 2 THEN dbo.fn_CariHesapAlternatifDovizBakiye('',0,cari.cari_kod,'','',NULL,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
            WHEN Cari_F10da_detay = 3 THEN dbo.fn_CariHesapOrjinalDovizBakiye('',0,cari.cari_kod,'','',0,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
            WHEN Cari_F10da_detay = 4 THEN dbo.fn_CariHareketSayisi(0,cari.cari_kod,'')
            ELSE 0
        END, 0) AS decimal(18,2)
    ) AS [bakiye]
    ,LTRIM(RTRIM(ISNULL(CariHareketIsim, N''))) AS [hareket_tipi]
FROM dbo.CARI_HESAPLAR cari WITH (NOLOCK)
LEFT OUTER JOIN dbo.CARI_HESAP_GRUPLARI grp WITH (NOLOCK) ON grp.crg_kod = cari.cari_grup_kodu
LEFT OUTER JOIN dbo.CARI_PERSONEL_TANIMLARI cpt WITH (NOLOCK) ON cpt.cari_per_kod = cari.cari_temsilci_kodu
LEFT OUTER JOIN dbo.vw_Cari_Hesap_Hareket_Tip_Isimleri ON CariHareketNo = cari.cari_hareket_tipi
LEFT OUTER JOIN dbo.vw_Gendata ON 1 = 1
WHERE
    LTRIM(RTRIM(ISNULL(cari.cari_kod, N''))) = @CustomerCode
    AND ((cari.cari_kod NOT LIKE N'320%' AND cari.cari_kod NOT LIKE N'331%') OR cari.cari_kod LIKE N'320.ÇLG%')
    AND (@CanViewAll = 1 OR LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode)
    AND (
        @CustomerScopeKey IN (N'', N'all', N'all_segments')
        OR (@CustomerScopeKey = N'own_rep' AND LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode)
        OR (@CustomerScopeKey = N'online_perakende' AND ISNULL(cari.cari_grup_kodu, N'') IN (N'120.01', N'120.02', N'120.03', N'120.04', N'120.05', N'120.06', N'120.07', N'120.08', N'120.09', N'120.16'))
        OR (@CustomerScopeKey = N'bayi_proje' AND (NULLIF(LTRIM(RTRIM(ISNULL(cari.cari_grup_kodu, N''))), N'') IS NULL OR ISNULL(cari.cari_grup_kodu, N'') NOT IN (N'120.01', N'120.02', N'120.03', N'120.04', N'120.05', N'120.06', N'120.07', N'120.08', N'120.09', N'120.16')))
    );
SQL_CUSTOMER_DETAIL,
            ['customer_code', 'rep_code', 'customer_scope_key', 'customer_group_scope', 'bypass_cache'],
            'PrimeCRM CariService.GetCariSummaryAsync müşteri detay mantığından uyarlanan kanonik sorgu.',
            'CariService.cs'
        );
        $this->upsert(
            'customer_documents',
            'Müşteri Evrak Detay',
            <<<'SQL_CUSTOMER_DOCUMENTS'
DECLARE @HareketGuid uniqueidentifier = COALESCE(
    TRY_CONVERT(uniqueidentifier, NULLIF(N'[[guid]]', N'')),
    TRY_CONVERT(uniqueidentifier, NULLIF(N'[[hareket_guid]]', N'')),
    TRY_CONVERT(uniqueidentifier, NULLIF(N'[[document_guid]]', N'')),
    TRY_CONVERT(uniqueidentifier, NULLIF(N'[[evrak_guid]]', N''))
);
DECLARE @RepCode NVARCHAR(50) = N'[[rep_code]]';
DECLARE @CanViewAll bit = CASE WHEN NULLIF(LTRIM(RTRIM(ISNULL(@RepCode, N''))), N'') IS NULL THEN 1 ELSE 0 END;
DECLARE @CustomerScopeKey NVARCHAR(80) = N'[[customer_scope_key]]';

;WITH Header AS
(
    SELECT TOP 1
        cha.cha_Guid AS hareket_guid,
        LTRIM(RTRIM(ISNULL(cha.cha_kod, N''))) AS cari_kodu,
        LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))) AS firma_unvani,
        LTRIM(RTRIM(ISNULL(grp.crg_isim, N''))) AS grup,
        LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) AS temsilci_kodu,
        LTRIM(RTRIM(ISNULL(cpt.cari_per_adi, N'') + CASE WHEN ISNULL(cpt.cari_per_soyadi, N'') = N'' THEN N'' ELSE N' ' + cpt.cari_per_soyadi END)) AS temsilci,
        CAST(cha.cha_tarihi AS date) AS tarih,
        ISNULL(cha.cha_evrak_tip, 0) AS evrak_tip_no,
        CASE cha.cha_evrak_tip
            WHEN 0 THEN N'Açılış Fişi'
            WHEN 1 THEN N'Tahsilat Makbuzu'
            WHEN 2 THEN N'Tediye Makbuzu'
            WHEN 3 THEN N'Gelen Havale'
            WHEN 4 THEN N'Gönderilen Havale'
            WHEN 5 THEN N'Mahsup Fişi'
            WHEN 6 THEN N'Satış Faturası'
            WHEN 7 THEN N'Alış Faturası'
            WHEN 8 THEN N'Portföye Giriş Bordrosu'
            WHEN 9 THEN N'Portföyden Çıkış Bordrosu'
            WHEN 10 THEN N'Çek/Senet Bordrosu'
            WHEN 13 THEN N'Gelen Fatura'
            WHEN 63 THEN N'Satış İrsaliyesi'
            ELSE N'Evrak Tipi ' + CONVERT(nvarchar(10), cha.cha_evrak_tip)
        END AS evrak_tipi,
        LTRIM(RTRIM(ISNULL(cha.cha_evrakno_seri, N''))) AS evrak_seri,
        ISNULL(cha.cha_evrakno_sira, 0) AS evrak_sira,
        LTRIM(RTRIM(CONCAT(ISNULL(cha.cha_evrakno_seri, N''), CASE WHEN ISNULL(cha.cha_evrakno_seri, N'') = N'' THEN N'' ELSE N'-' END, CONVERT(nvarchar(30), ISNULL(cha.cha_evrakno_sira, 0))))) AS evrak_no,
        LTRIM(RTRIM(ISNULL(cha.cha_aciklama, N''))) AS aciklama,
        CAST(CASE WHEN ISNULL(cha.cha_tip, 0) = 0 THEN ISNULL(cha.cha_meblag, 0) ELSE 0 END AS decimal(18,2)) AS borc,
        CAST(CASE WHEN ISNULL(cha.cha_tip, 0) = 1 THEN ISNULL(cha.cha_meblag, 0) ELSE 0 END AS decimal(18,2)) AS alacak
    FROM dbo.CARI_HESAP_HAREKETLERI cha WITH (NOLOCK)
    INNER JOIN dbo.CARI_HESAPLAR cari WITH (NOLOCK) ON cari.cari_kod = cha.cha_kod
    LEFT JOIN dbo.CARI_HESAP_GRUPLARI grp WITH (NOLOCK) ON grp.crg_kod = cari.cari_grup_kodu
    LEFT JOIN dbo.CARI_PERSONEL_TANIMLARI cpt WITH (NOLOCK) ON cpt.cari_per_kod = cari.cari_temsilci_kodu
    WHERE
        cha.cha_Guid = @HareketGuid
        AND ISNULL(cha.cha_iptal, 0) = 0
        AND (@CanViewAll = 1 OR LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode)
        AND (
            @CustomerScopeKey IN (N'', N'all', N'all_segments')
            OR (@CustomerScopeKey = N'own_rep' AND LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode)
            OR (@CustomerScopeKey = N'online_perakende' AND ISNULL(cari.cari_grup_kodu, N'') IN (N'120.01', N'120.02', N'120.03', N'120.04', N'120.05', N'120.06', N'120.07', N'120.08', N'120.09', N'120.16'))
            OR (@CustomerScopeKey = N'bayi_proje' AND (NULLIF(LTRIM(RTRIM(ISNULL(cari.cari_grup_kodu, N''))), N'') IS NULL OR ISNULL(cari.cari_grup_kodu, N'') NOT IN (N'120.01', N'120.02', N'120.03', N'120.04', N'120.05', N'120.06', N'120.07', N'120.08', N'120.09', N'120.16')))
        )
)
SELECT
    N'header' AS line_type,
    CONVERT(nvarchar(36), hareket_guid) AS hareket_guid,
    cari_kodu,
    cari_kodu AS musteri_kodu,
    firma_unvani,
    firma_unvani AS musteri_adi,
    grup,
    temsilci_kodu,
    temsilci,
    tarih,
    evrak_tipi,
    evrak_tip_no,
    evrak_seri,
    evrak_sira,
    evrak_no,
    aciklama,
    borc,
    alacak,
    CAST(CASE WHEN borc <> 0 THEN borc ELSE alacak END AS decimal(18,2)) AS tutar,
    CAST(NULL AS nvarchar(100)) AS stok_kodu,
    CAST(NULL AS nvarchar(500)) AS urun_adi,
    CAST(NULL AS decimal(18,2)) AS miktar,
    CAST(NULL AS decimal(18,2)) AS net_birim_fiyat,
    CAST(NULL AS decimal(18,2)) AS iskonto_1,
    CAST(NULL AS decimal(18,2)) AS iskonto_2,
    CAST(NULL AS decimal(18,2)) AS iskonto_3,
    CAST(NULL AS decimal(18,2)) AS iskonto,
    CAST(NULL AS decimal(18,2)) AS net_tutar
FROM Header
UNION ALL
SELECT
    N'cari' AS line_type,
    CONVERT(nvarchar(36), cha.cha_Guid) AS hareket_guid,
    LTRIM(RTRIM(ISNULL(cha.cha_kod, N''))) AS cari_kodu,
    LTRIM(RTRIM(ISNULL(cha.cha_kod, N''))) AS musteri_kodu,
    LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))) AS firma_unvani,
    LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))) AS musteri_adi,
    h.grup,
    h.temsilci_kodu,
    h.temsilci,
    CAST(cha.cha_tarihi AS date) AS tarih,
    h.evrak_tipi,
    h.evrak_tip_no,
    LTRIM(RTRIM(ISNULL(cha.cha_evrakno_seri, N''))) AS evrak_seri,
    ISNULL(cha.cha_evrakno_sira, 0) AS evrak_sira,
    LTRIM(RTRIM(CONCAT(ISNULL(cha.cha_evrakno_seri, N''), CASE WHEN ISNULL(cha.cha_evrakno_seri, N'') = N'' THEN N'' ELSE N'-' END, CONVERT(nvarchar(30), ISNULL(cha.cha_evrakno_sira, 0))))) AS evrak_no,
    LTRIM(RTRIM(ISNULL(cha.cha_aciklama, N''))) AS aciklama,
    CAST(CASE WHEN ISNULL(cha.cha_tip, 0) = 0 THEN ISNULL(cha.cha_meblag, 0) ELSE 0 END AS decimal(18,2)) AS borc,
    CAST(CASE WHEN ISNULL(cha.cha_tip, 0) = 1 THEN ISNULL(cha.cha_meblag, 0) ELSE 0 END AS decimal(18,2)) AS alacak,
    CAST(ISNULL(cha.cha_meblag, 0) AS decimal(18,2)) AS tutar,
    CAST(NULL AS nvarchar(100)) AS stok_kodu,
    CAST(NULL AS nvarchar(500)) AS urun_adi,
    CAST(NULL AS decimal(18,2)) AS miktar,
    CAST(NULL AS decimal(18,2)) AS net_birim_fiyat,
    CAST(NULL AS decimal(18,2)) AS iskonto_1,
    CAST(NULL AS decimal(18,2)) AS iskonto_2,
    CAST(NULL AS decimal(18,2)) AS iskonto_3,
    CAST(NULL AS decimal(18,2)) AS iskonto,
    CAST(NULL AS decimal(18,2)) AS net_tutar
FROM dbo.CARI_HESAP_HAREKETLERI cha WITH (NOLOCK)
INNER JOIN Header h ON h.cari_kodu = LTRIM(RTRIM(ISNULL(cha.cha_kod, N'')))
LEFT JOIN dbo.CARI_HESAPLAR cari WITH (NOLOCK) ON cari.cari_kod = cha.cha_kod
WHERE
    cha.cha_evrak_tip = h.evrak_tip_no
    AND ISNULL(cha.cha_evrakno_seri, N'') = h.evrak_seri
    AND ISNULL(cha.cha_evrakno_sira, 0) = h.evrak_sira
    AND ABS(DATEDIFF(day, cha.cha_tarihi, h.tarih)) <= 7
    AND ISNULL(cha.cha_iptal, 0) = 0
UNION ALL
SELECT
    N'stock' AS line_type,
    CONVERT(nvarchar(36), sth.sth_Guid) AS hareket_guid,
    h.cari_kodu,
    h.cari_kodu AS musteri_kodu,
    h.firma_unvani,
    h.firma_unvani AS musteri_adi,
    h.grup,
    h.temsilci_kodu,
    h.temsilci,
    CAST(sth.sth_tarih AS date) AS tarih,
    h.evrak_tipi,
    h.evrak_tip_no,
    LTRIM(RTRIM(ISNULL(sth.sth_evrakno_seri, N''))) AS evrak_seri,
    ISNULL(sth.sth_evrakno_sira, 0) AS evrak_sira,
    LTRIM(RTRIM(CONCAT(ISNULL(sth.sth_evrakno_seri, N''), CASE WHEN ISNULL(sth.sth_evrakno_seri, N'') = N'' THEN N'' ELSE N'-' END, CONVERT(nvarchar(30), ISNULL(sth.sth_evrakno_sira, 0))))) AS evrak_no,
    CAST(NULL AS nvarchar(500)) AS aciklama,
    CAST(NULL AS decimal(18,2)) AS borc,
    CAST(NULL AS decimal(18,2)) AS alacak,
    CAST(ISNULL(sth.sth_tutar, 0) AS decimal(18,2)) AS tutar,
    LTRIM(RTRIM(ISNULL(sth.sth_stok_kod, N''))) AS stok_kodu,
    LTRIM(RTRIM(ISNULL(sto.sto_isim, N''))) AS urun_adi,
    CAST(ISNULL(sth.sth_miktar, 0) AS decimal(18,2)) AS miktar,
    CAST(CASE WHEN ISNULL(sth.sth_miktar, 0) = 0 THEN 0 ELSE (ISNULL(sth.sth_tutar, 0) - ISNULL(sth.sth_iskonto1, 0) - ISNULL(sth.sth_iskonto2, 0) - ISNULL(sth.sth_iskonto3, 0)) / NULLIF(sth.sth_miktar, 0) END AS decimal(18,2)) AS net_birim_fiyat,
    CAST(ISNULL(sth.sth_iskonto1, 0) AS decimal(18,2)) AS iskonto_1,
    CAST(ISNULL(sth.sth_iskonto2, 0) AS decimal(18,2)) AS iskonto_2,
    CAST(ISNULL(sth.sth_iskonto3, 0) AS decimal(18,2)) AS iskonto_3,
    CAST(ISNULL(sth.sth_iskonto1, 0) + ISNULL(sth.sth_iskonto2, 0) + ISNULL(sth.sth_iskonto3, 0) AS decimal(18,2)) AS iskonto,
    CAST(ISNULL(sth.sth_tutar, 0) - ISNULL(sth.sth_iskonto1, 0) - ISNULL(sth.sth_iskonto2, 0) - ISNULL(sth.sth_iskonto3, 0) AS decimal(18,2)) AS net_tutar
FROM dbo.STOK_HAREKETLERI sth WITH (NOLOCK)
INNER JOIN Header h ON
    LTRIM(RTRIM(ISNULL(sth.sth_cari_kodu, N''))) = h.cari_kodu
    AND CAST(sth.sth_tarih AS date) BETWEEN DATEADD(day, -7, h.tarih) AND DATEADD(day, 7, h.tarih)
    AND LTRIM(RTRIM(ISNULL(sth.sth_evrakno_seri, N''))) = h.evrak_seri
    AND ISNULL(sth.sth_evrakno_sira, 0) = h.evrak_sira
LEFT JOIN dbo.STOKLAR sto WITH (NOLOCK) ON sto.sto_kod = sth.sth_stok_kod
WHERE ISNULL(sth.sth_iptal, 0) = 0
ORDER BY line_type, tarih, hareket_guid;
SQL_CUSTOMER_DOCUMENTS,
            [
                'guid',
                'hareket_guid',
                'document_guid',
                'evrak_guid',
                'customer_code',
                'document_id',
                'rep_code',
                'customer_scope_key',
                'customer_group_scope',
                'bypass_cache',
            ],
            'PrimeCRM CariService.GetDocumentDetailAsync evrak detay, cari satırları ve stok/hizmet satırları sorguları.',
            'CariService.cs',
        );

        $this->upsert(
            'proforma_customer_search',
            'Proforma Müşteri Arama',
            <<<'SQL_PROFORMA_CUSTOMERS'
DECLARE @search NVARCHAR(255) = N'[[search]]';

SELECT TOP 30
    cari_kod AS [musteri_kodu],
    cari_unvan1 AS [musteri_adi],
    ISNULL(cari_grup_kodu, N'') AS [grup]
FROM CARI_HESAPLAR WITH (NOLOCK)
WHERE (@search = N'' OR cari_kod LIKE N'%' + @search + N'%' OR cari_unvan1 LIKE N'%' + @search + N'%')
ORDER BY cari_unvan1;
SQL_PROFORMA_CUSTOMERS,
            ['search', 'bypass_cache'],
            'PrimeCRM ProformaService.SearchCustomersAsync müşteri arama sorgusu.',
            'ProformaService.cs'
        );

        $this->upsert(
            'proforma_stock_search',
            'Proforma Stok Arama',
            <<<'SQL_PROFORMA_STOCK'
DECLARE @search NVARCHAR(255) = N'[[search]]';
DECLARE @price_list int = TRY_CONVERT(int, NULLIF(N'[[price_list]]', N''));

SELECT TOP 50
    sto.sto_kod AS [stok_kodu],
    sto.sto_isim AS [stok_adi],
    ISNULL(f.sfiyat_fiyati, 0) AS [birim_fiyat]
FROM STOKLAR sto WITH (NOLOCK)
OUTER APPLY
(
    SELECT TOP 1 sfiyat_fiyati
    FROM STOK_SATIS_FIYAT_LISTELERI WITH (NOLOCK)
    WHERE sfiyat_stokkod = sto.sto_kod
      AND (@price_list IS NULL OR sfiyat_listesirano = @price_list)
      AND ISNULL(sfiyat_iptal, 0) = 0
    ORDER BY sfiyat_lastup_date DESC
) f
WHERE @search = N'' OR sto.sto_kod LIKE N'%' + @search + N'%' OR sto.sto_isim LIKE N'%' + @search + N'%'
ORDER BY sto.sto_kod;
SQL_PROFORMA_STOCK,
            ['search', 'price_list', 'bypass_cache'],
            'PrimeCRM ProformaService.GetLinesAsync stok/fiyat arama sorgusu.',
            'ProformaService.cs'
        );

        $this->upsert('proforma_list', 'Proforma Liste', '', ['search', 'proforma_no', 'bypass_cache'], 'PrimeCRM ProformaService.List dosya tabanli calisir; panel SQL datasource bulunmadi.', 'ProformaService.cs');
        $this->upsert('proforma_detail', 'Proforma Detay', '', ['proforma_no', 'bypass_cache'], 'PrimeCRM ProformaService.Find dosya tabanli calisir; panel SQL datasource bulunmadi.', 'ProformaService.cs');
        $this->upsert('proforma_draft', 'Proforma Taslak', '', ['customer_code', 'items', 'bypass_cache'], 'Proforma taslak akisi frontend localStorage ile korunur; SQL datasource bulunmadi.', 'ProformaService.cs');
        $this->upsert('proforma_items', 'Proforma Satırları', '', ['proforma_no', 'bypass_cache'], 'Proforma satır metadata kaydı; SQL datasource bulunmadı.', 'ProformaService.cs');
        $this->upsert(
            'proforma_price_list',
            'Proforma Fiyat Listesi',
            <<<'SQL_PROFORMA_PRICE_LIST'
DECLARE @CustomerCode NVARCHAR(80) = N'[[customer_code]]';
DECLARE @PriceColumn sysname =
    CASE
        WHEN COL_LENGTH('dbo.CARI_HESAPLAR', 'cari_satis_fiyat_liste_no') IS NOT NULL THEN N'cari_satis_fiyat_liste_no'
        WHEN COL_LENGTH('dbo.CARI_HESAPLAR', 'cari_fiyat_liste_no') IS NOT NULL THEN N'cari_fiyat_liste_no'
        WHEN COL_LENGTH('dbo.CARI_HESAPLAR', 'cari_fiyatliste_no') IS NOT NULL THEN N'cari_fiyatliste_no'
        WHEN COL_LENGTH('dbo.CARI_HESAPLAR', 'cari_satis_fk') IS NOT NULL THEN N'cari_satis_fk'
        WHEN COL_LENGTH('dbo.CARI_HESAPLAR', 'cari_fiyat_liste') IS NOT NULL THEN N'cari_fiyat_liste'
        ELSE NULL
    END;
DECLARE @Sql nvarchar(max) = N'
SELECT TOP 1
    cari.cari_kod AS [musteri_kodu],
    cari.cari_unvan1 AS [musteri_adi],
    TRY_CONVERT(int, ' + COALESCE(QUOTENAME(@PriceColumn), N'0') + N') AS [fiyat_liste_no],
    ISNULL(sfl.sfl_aciklama, N'''') AS [fiyat_liste_adi]
FROM dbo.CARI_HESAPLAR cari WITH (NOLOCK)
LEFT JOIN dbo.STOK_SATIS_FIYAT_LISTE_TANIMLARI sfl WITH (NOLOCK)
    ON sfl.sfl_sirano = TRY_CONVERT(int, ' + COALESCE(QUOTENAME(@PriceColumn), N'0') + N')
WHERE cari.cari_kod = @CustomerCode;';

EXEC sp_executesql @Sql, N'@CustomerCode nvarchar(80)', @CustomerCode = @CustomerCode;
SQL_PROFORMA_PRICE_LIST,
            ['customer_code', 'bypass_cache'],
            'PrimeCRM ProformaService.GetCariInfoAsync ve GetFiyatListeAdiAsync mantığından uyarlanan fiyat listesi sorgusu.',
            'ProformaService.cs'
        );
        $this->upsert(
            'proforma_discount_defs',
            'Proforma İskonto Tanımları',
            <<<'SQL_PROFORMA_DISCOUNT_DEFS'
DECLARE @DiscountCode NVARCHAR(80) = N'[[discount_code]]';

SELECT TOP 1
    ISNULL(isk_isk1_yuzde, 0) AS [iskonto_1],
    ISNULL(isk_isk2_yuzde, 0) AS [iskonto_2],
    ISNULL(isk_isk3_yuzde, 0) AS [iskonto_3]
FROM dbo.STOK_CARI_ISKONTO_TANIMLARI WITH (NOLOCK)
WHERE isk_cari_kod = @DiscountCode
ORDER BY isk_lastup_date DESC;
SQL_PROFORMA_DISCOUNT_DEFS,
            ['discount_code', 'bypass_cache'],
            'PrimeCRM ProformaService.GetDiscountAsync iskonto tanımı sorgusu.',
            'ProformaService.cs'
        );
    }

    public function refreshSource(string $sourceCode): bool
    {
        if ($sourceCode !== self::ACCOUNTING_FINANCE_RESMI_STOK_KONTROL) {
            return false;
        }

        $this->upsertAccountingFinanceResmiStokKontrol();

        return true;
    }

    /**
     * @param  array<int, string>  $allowedParams
     */
    private function technicalServiceSerialDecisionQuery(): string
    {
        return <<<'SQL_TECH_SERIAL_DECISION'
WITH query_params AS (
    SELECT
        LTRIM(RTRIM(N'[[serial_no]]')) AS serial_no,
        N'W-MONTAJ-1' AS installation_stock_code
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
    WHERE UPPER(ISNULL(cha.cha_aciklama, N'')) LIKE N'%MONTAJ%'
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
            N'Sipariş' AS kaynak,
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
          AND ISNULL(sip.sip_aciklama, N'') LIKE N'%' + params.serial_no + N'%'

        UNION ALL

        SELECT
            N'Stok Hareketi' AS kaynak,
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
          AND ISNULL(sth.sth_aciklama, N'') LIKE N'%' + params.serial_no + N'%'

        UNION ALL

        SELECT
            N'Cari Hizmet' AS kaynak,
            ISNULL(cha.cha_create_date, cha.cha_tarihi) AS kaynak_tarihi,
            cha.cha_aciklama AS kaynak_aciklama,
            cha.cha_kod AS kaynak_cari_kodu,
            cari.cari_unvan1 AS kaynak_cari_unvani
        FROM CARI_HESAP_HAREKETLERI AS cha
        CROSS JOIN last_sale AS ls
        CROSS JOIN query_params AS params
        LEFT JOIN CARI_HESAPLAR AS cari ON cari.cari_kod = cha.cha_kod
        WHERE ISNULL(cha.cha_create_date, cha.cha_tarihi) > ls.hareket_tarihi
          AND cha.cha_kasa_hizkod LIKE N'MONTAJ%'
          AND ISNULL(cha.cha_aciklama, N'') LIKE N'%' + params.serial_no + N'%'
    ) AS later_rows
    ORDER BY kaynak_tarihi ASC
)
SELECT
    CAST(1 AS bit) AS found,
    CASE
        WHEN ls.hareket_tarihi < CONVERT(date, '2025-07-01') THEN N'Montaj Dahil'
        WHEN EXISTS (SELECT 1 FROM order_installation) THEN N'Montaj Dahil'
        WHEN EXISTS (SELECT 1 FROM invoice_installation) THEN N'Montaj Dahil'
        WHEN EXISTS (SELECT 1 FROM linked_stock_installation) THEN N'Montaj Dahil'
        WHEN EXISTS (SELECT 1 FROM later_installation) THEN N'Montaj Sonradan Dahil'
        ELSE N'Montaj Hariç'
    END AS montaj_durumu,
    CASE
        WHEN ls.hareket_tarihi < CONVERT(date, '2025-07-01') THEN N'2025-07-01 öncesi satışlar montaj dahil kabul edilir.'
        WHEN EXISTS (SELECT 1 FROM order_installation) THEN N'Son geçerli satış siparişinde montaj satırı bulundu.'
        WHEN EXISTS (SELECT 1 FROM invoice_installation) THEN N'Son geçerli satış faturasında MONTAJ hizmet satırı bulundu.'
        WHEN EXISTS (SELECT 1 FROM linked_stock_installation) THEN N'2026-04-01 sonrası faturaya bağlı W-MONTAJ-1 stok hareketi bulundu.'
        WHEN EXISTS (
            SELECT 1
            FROM later_installation AS later_check
            CROSS JOIN last_sale AS sale_check
            WHERE later_check.kaynak_cari_kodu IS NOT NULL
              AND sale_check.cari_kodu IS NOT NULL
              AND later_check.kaynak_cari_kodu <> sale_check.cari_kodu
        ) THEN N'Farklı Cari ile Sonradan Montaj'
        WHEN EXISTS (SELECT 1 FROM later_installation) THEN N'Son geçerli satıştan sonra seri no açıklamalı montaj kaydı bulundu.'
        ELSE N'Son geçerli satış için Mikro’da montaj ödemesi bulunamadı.'
    END AS montaj_ek_aciklama,
    ls.cihaz_seri_no,
    ls.stok_kodu,
    ls.stok_adi,
    ls.hareket_tarihi AS irsaliye_tarihi,
    ls.irsaliye_seri,
    ls.irsaliye_sira,
    ls.fatura_tarihi,
    CAST(NULL AS NVARCHAR(50)) AS fatura_seri,
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
SQL_TECH_SERIAL_DECISION;
    }

    private function technicalServiceSerialHistoryQuery(): string
    {
        return <<<'SQL_TECH_SERIAL_HISTORY'
WITH query_params AS (
    SELECT
        LTRIM(RTRIM(N'[[serial_no]]')) AS serial_no,
        N'W-MONTAJ-1' AS installation_stock_code
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
        CAST(COALESCE(NULLIF(LTRIM(RTRIM(sh.sth_cari_srm_merkezi)), N''), NULLIF(LTRIM(RTRIM(sh.sth_stok_srm_merkezi)), N'')) AS NVARCHAR(50)) AS sorumluluk_kodu,
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
        WHEN m.hareket_tip = 1 AND ISNULL(m.normal_iade, 0) = 0 THEN N'satış'
        WHEN m.hareket_tip = 0 AND ISNULL(m.normal_iade, 0) = 1 THEN N'iade'
        ELSE N'stok_hareketi'
    END AS event_type,
    m.hareket_tarihi AS event_date,
    CASE
        WHEN m.hareket_tip = 1 AND ISNULL(m.normal_iade, 0) = 0 THEN N'Satış / çıkış'
        WHEN m.hareket_tip = 0 AND ISNULL(m.normal_iade, 0) = 1 THEN N'İade / giriş'
        ELSE N'Stok hareketi'
    END AS title,
    m.description,
    m.stok_kodu,
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
    N'sonradan_montaj' AS event_type,
    x.event_date,
    N'Sonradan montaj' AS title,
    x.description,
    CAST(NULL AS NVARCHAR(50)) AS stok_kodu,
    CAST(NULL AS NVARCHAR(255)) AS stok_adi,
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
        CAST(COALESCE(NULLIF(LTRIM(RTRIM(sip.sip_cari_sormerk)), N''), NULLIF(LTRIM(RTRIM(sip.sip_stok_sormerk)), N'')) AS NVARCHAR(50)) AS sorumluluk_kodu
    FROM SIPARISLER AS sip
    CROSS JOIN query_params AS params
    WHERE sip.sip_stok_kod = params.installation_stock_code
      AND ISNULL(sip.sip_aciklama, N'') LIKE N'%' + params.serial_no + N'%'

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
        CAST(COALESCE(NULLIF(LTRIM(RTRIM(sth.sth_cari_srm_merkezi)), N''), NULLIF(LTRIM(RTRIM(sth.sth_stok_srm_merkezi)), N'')) AS NVARCHAR(50)) AS sorumluluk_kodu
    FROM STOK_HAREKETLERI AS sth
    CROSS JOIN query_params AS params
    WHERE sth.sth_stok_kod = params.installation_stock_code
      AND ISNULL(sth.sth_aciklama, N'') LIKE N'%' + params.serial_no + N'%'

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
      AND cha.cha_kasa_hizkod LIKE N'MONTAJ%'
      AND ISNULL(cha.cha_aciklama, N'') LIKE N'%' + params.serial_no + N'%'
) AS x
LEFT JOIN CARI_HESAPLAR AS cari ON cari.cari_kod = x.cari_kodu
ORDER BY event_date DESC, event_type ASC
SQL_TECH_SERIAL_HISTORY;
    }

    private function accountingFinanceResmiStokKontrolPreviewTemplate(): string
    {
        return <<<'SQL_ACCOUNTING_FINANCE_RESMI_STOK_KONTROL'
DECLARE @RaporTarihi date = COALESCE(TRY_CONVERT(date, NULLIF(N'[[date_to]]', N'')), CONVERT(date, '2026-03-31'));
DECLARE @HareketBaslangic date = CONVERT(date, '2025-01-01');

;WITH Devir2024 AS (
    SELECT
        v.RaporModelAdi,
        v.Kategori,
        CAST(v.Devir2024Adet AS decimal(18,2)) AS Devir2024Adet,
        CAST(v.IsSmart AS bit) AS IsSmart,
        CAST(v.IsSmartPL AS bit) AS IsSmartPL
    FROM (VALUES
        (N'5100 - 5HBKS', N'AKILLI KİLİT', -6, 0, 0),
        (N'5100 - 6HBS', N'AKILLI KİLİT', 11, 0, 0),
        (N'9300-5HBGS', N'AKILLI KİLİT', 42, 0, 0),
        (N'ALPHA-5HB', N'AKILLI KİLİT', 170, 0, 0),
        (N'ALPHA-V-5HWS', N'AKILLI KİLİT', 5, 0, 0),
        (N'ALPHA-VP-5HWS', N'AKILLI KİLİT', 43, 0, 0),
        (N'DDL303-VP-5HWS', N'AKILLI KİLİT', 725, 0, 0),
        (N'DDL603E-5HWS', N'AKILLI KİLİT', 139, 0, 0),
        (N'DDL608-5HWS', N'AKILLI KİLİT', 16, 0, 0),
        (N'DDL702-1HWS', N'AKILLI KİLİT', 37, 0, 0),
        (N'DDL702-8HWS', N'AKILLI KİLİT', 6, 0, 0),
        (N'DDL702E-5HWS', N'AKILLI KİLİT', 6, 0, 0),
        (N'DDL709-FVP-7HWS', N'AKILLI KİLİT', 25, 0, 0),
        (N'DV001', N'AKILLI KİLİT', 4, 0, 0),
        (N'DW6280', N'AKILLI KİLİT', 46, 0, 0),
        (N'DW6280-TUYA APP', N'AKILLI KİLİT', 29, 0, 0),
        (N'E22', N'AKILLI KİLİT', 35, 0, 0),
        (N'E35', N'AKILLI KİLİT', 403, 0, 0),
        (N'E50', N'AKILLI KİLİT', 85, 0, 0),
        (N'E55', N'AKILLI KİLİT', 69, 0, 0),
        (N'EH-1034', N'AKILLI KİLİT', 55, 0, 0),
        (N'EH-1284', N'AKILLI KİLİT', 56, 0, 0),
        (N'EH-1424', N'AKILLI KİLİT', 11, 0, 0),
        (N'G10', N'AKILLI KİLİT', 39, 0, 0),
        (N'G20 PRO', N'AKILLI KİLİT', 26, 0, 0),
        (N'G30', N'AKILLI KİLİT', 5, 0, 0),
        (N'G35', N'AKILLI KİLİT', 20, 0, 0),
        (N'GALAXY 10', N'AKILLI KİLİT', 16, 0, 0),
        (N'GALAXY 20', N'AKILLI KİLİT', 135, 0, 0),
        (N'GALAXY 30', N'AKILLI KİLİT', 154, 0, 0),
        (N'H1080', N'AKILLI KİLİT', 98, 0, 0),
        (N'RETİNA 10', N'AKILLI KİLİT', 22, 0, 0),
        (N'RETİNA 20', N'AKILLI KİLİT', 44, 0, 0),
        (N'RETİNA 30', N'AKILLI KİLİT', 40, 0, 0),
        (N'VİVA 10 PRO', N'AKILLI KİLİT', 36, 0, 0),
        (N'SMART', N'AKILLI KİLİT', -79, 1, 0)
    ) AS v(RaporModelAdi, Kategori, Devir2024Adet, IsSmart, IsSmartPL)
),
KesinStokRaw AS (
    SELECT
        N'SMART' AS RaporModelAdi,
        N'AKILLI KİLİT' AS Kategori,
        CAST(1 AS bit) AS IsSmart,
        CAST(0 AS bit) AS IsSmartPL,
        sto.sto_kod,
        sto.sto_isim,
        sto.sto_marka_kodu
    FROM STOKLAR sto WITH (NOLOCK)
    WHERE sto.sto_kod = N'M153.007.000.000.00001'

    UNION ALL

    SELECT
        N'SMART PL',
        N'MEKANİK KAPI KOLU',
        CAST(0 AS bit),
        CAST(1 AS bit),
        sto.sto_kod,
        sto.sto_isim,
        sto.sto_marka_kodu
    FROM STOKLAR sto WITH (NOLOCK)
    WHERE sto.sto_kod = N'M153.007.000.001.00001'

    UNION ALL

    SELECT
        CASE
            WHEN sto.sto_kod LIKE N'EE.MKK.001.01.%' THEN N'PL40'
            WHEN sto.sto_kod LIKE N'EE.MKK.001.02.%' THEN N'PL41'
            ELSE N'DİĞER MEKANİK KAPI KOLU'
        END,
        N'MEKANİK KAPI KOLU',
        CAST(0 AS bit),
        CAST(0 AS bit),
        sto.sto_kod,
        sto.sto_isim,
        sto.sto_marka_kodu
    FROM STOKLAR sto WITH (NOLOCK)
    WHERE
        (
            sto.sto_kod LIKE N'EE.MKK.001.01.%'
            OR sto.sto_kod LIKE N'EE.MKK.001.02.%'
        )
        AND UPPER(ISNULL(sto.sto_marka_kodu, N'')) COLLATE Turkish_CI_AI IN
            (N'EMAKS PRIME', N'EMAKSPRIME', N'EMAKS')

    UNION ALL

    SELECT
        CASE
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%SBX001%1M4%' THEN N'SBX001-1M4 MİNİ BOY'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%SBX%202%5C0%' THEN N'SBX202-5C0 ORTA BOY'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%SBX%202%6C0%' THEN N'SBX202-6C0 BÜYÜK BOY'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%SBX%301%5PC%' THEN N'SBX301-5PC ORTA BOY'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%SBX%501%5C0%' THEN N'SBX501-5C0 ORTA BOY'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%SBX%501%7C0%' THEN N'SBX501-7C0 BÜYÜK BOY'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%SBX%602%5CU%' THEN N'SBX602-5CU ORTA BOY'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%SBX%602%6CU%' THEN N'SBX602-6CU BÜYÜK BOY'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%SBX%602%8CU%' THEN N'SBX602-8CU'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%SBX%602%AC%' THEN N'SBX602-ACU'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%SBX%701%4B0%' THEN N'SBX701-4B0 KÜÇÜK BOY'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%SBX%701%8B0%' THEN N'SBX701-8B0 BÜYÜK BOY'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%SBX%702%CBX%' THEN N'SBX702-CBX BÜYÜK BOY'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%VALIS%6BU%' THEN N'VALIS-6BU'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%VALIS%8BU%' THEN N'VALIS-8BU'
            WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%VALIS%PRO%' THEN N'VALIS-PRO'
            ELSE N'DİĞER GÜVENLİK KASASI'
        END,
        N'GÜVENLİK KASASI',
        CAST(0 AS bit),
        CAST(0 AS bit),
        sto.sto_kod,
        sto.sto_isim,
        sto.sto_marka_kodu
    FROM STOKLAR sto WITH (NOLOCK)
    WHERE
        (
            sto.sto_kod LIKE N'EP.SBX.%'
            OR sto.sto_kod LIKE N'EE.SBX.%'
        )
        AND UPPER(ISNULL(sto.sto_marka_kodu, N'')) COLLATE Turkish_CI_AI IN
            (N'PHILIPS', N'EMAKS PRIME', N'EMAKSPRIME', N'EMAKS')

    UNION ALL

    SELECT
    CASE
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%5100%5HBKS%' THEN N'5100 - 5HBKS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%5100%6HBS%' THEN N'5100 - 6HBS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%9300%5HBGS%' THEN N'9300-5HBGS'

        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%ALPHA%VP%5HWS%' THEN N'ALPHA-VP-5HWS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%ALPHA%V%5HWS%' THEN N'ALPHA-V-5HWS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%ALPHA%5HB%' THEN N'ALPHA-5HB'

        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL230X%' THEN N'DDL230X'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL303%VP%5HWS%' THEN N'DDL303-VP-5HWS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL505%5HBS%' THEN N'DDL505-5HBS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL603E%5HWS%' THEN N'DDL603E-5HWS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL608%5HWS%' THEN N'DDL608-5HWS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL610%5HBS%' THEN N'DDL610-5HBS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL615%' THEN N'DDL615-5HWS'

        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL702%FVP%' THEN N'DDL702-FVP-17HWS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL702E%' THEN N'DDL702E-5HWS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL702%8HWS%' THEN N'DDL702-8HWS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL702%1HWS%' THEN N'DDL702-1HWS'

        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL709%FVP%7HWS%' THEN N'DDL709-FVP-7HWS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL720%FVP%' THEN N'DDL720-FVP-17HWSE'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL720%MVP%' THEN N'DDL720-MVP-17HWSE'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL801%5HBS%' THEN N'DDL801-5HBS'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DDL902%MFVP%' THEN N'DDL902-MFVP-11HWS'

        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DV001%' THEN N'DV001'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DW6280%TUYA%' THEN N'DW6280-TUYA APP'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%DW6280%' THEN N'DW6280'

        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%E10%' THEN N'E10'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%E22%' THEN N'E22'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%E24%' THEN N'E24'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%E35 PRO%' THEN N'E35 PRO'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%E35%' THEN N'E35'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%E50%' THEN N'E50'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%E55%' THEN N'E55'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%E60%KISA%' THEN N'E60 - KISA'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%E60%UZUN%' THEN N'E60 - UZUN'

        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%EH%1034%' THEN N'EH-1034'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%EH%1084%' THEN N'EH-1084'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%EH%1284%' THEN N'EH-1284'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%EH%1424%' THEN N'EH-1424'

        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%G10%' THEN N'G10'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%G20 PRO%' THEN N'G20 PRO'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%G30%' THEN N'G30'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%G35%' THEN N'G35'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%G40%' THEN N'G40'

        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%GALAXY%10%ULTRA%' THEN N'GALAXY 10 ULTRA'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%GALAXY%10%' THEN N'GALAXY 10'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%GALAXY%20%ULTRA%' THEN N'GALAXY 20 ULTRA'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%GALAXY%20%' THEN N'GALAXY 20'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%GALAXY%30%ULTRA%' THEN N'GALAXY 30 ULTRA'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%GALAXY%30%' THEN N'GALAXY 30'

        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%H1080%' THEN N'H1080'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%H1081F%' THEN N'H1081F'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%H1180%' THEN N'H1180'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%H1480%' THEN N'H1480'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%H1680%TUYA%' THEN N'H1680 TUYA APP'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%H1680%' THEN N'H1680'

        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%RETINA%10%' OR UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%RETİNA%10%' THEN N'RETİNA 10'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%RETINA%20%' OR UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%RETİNA%20%' THEN N'RETİNA 20'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%RETINA%30%ULTRA%' OR UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%RETİNA%30%ULTRA%' THEN N'RETİNA 30 ULTRA'
        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%RETINA%30%' OR UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%RETİNA%30%' THEN N'RETİNA 30'

        WHEN UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%VIVA%10%PRO%' OR UPPER(sto.sto_kod + N' ' + sto.sto_isim) COLLATE Turkish_CI_AI LIKE N'%VİVA%10%PRO%' THEN N'VİVA 10 PRO'

        ELSE N'DİĞER AKILLI KİLİT'
    END,
    N'AKILLI KİLİT',
    CAST(0 AS bit),
    CAST(0 AS bit),
    sto.sto_kod,
    sto.sto_isim,
    sto.sto_marka_kodu
FROM STOKLAR sto WITH (NOLOCK)
WHERE
    (
        UPPER(ISNULL(sto.sto_marka_kodu, N'')) COLLATE Turkish_CI_AI IN
            (N'PHILIPS', N'EMAKS PRIME', N'EMAKSPRIME', N'EMAKS')
        OR sto.sto_kod LIKE N'HTN.EKK.006.0002.%'
    )
    AND (
        sto.sto_kod LIKE N'EP.BCK.%'
        OR sto.sto_kod LIKE N'EP.EKK.%'
        OR sto.sto_kod LIKE N'EE.BCK.%'
        OR sto.sto_kod LIKE N'EE.EKK.%'
        OR sto.sto_kod LIKE N'EE.YMK.%'
        OR sto.sto_kod LIKE N'EE.MAK.%'
        OR sto.sto_kod LIKE N'EE.ACS.%'
        OR sto.sto_kod LIKE N'EP.ACS.%'
        OR sto.sto_kod LIKE N'EP.YMK.004.%'
        OR sto.sto_kod LIKE N'HTN.EKK.006.0002.%'
    )
    AND sto.sto_kod NOT LIKE N'%.STD.%'
    AND sto.sto_kod NOT LIKE N'%STD%'
    AND sto.sto_kod NOT LIKE N'EP.YDP.%'
    AND sto.sto_kod NOT LIKE N'EE.YDP.%'
    AND sto.sto_kod NOT LIKE N'EP.KDA.%'
    AND sto.sto_kod <> N'W-MONTAJ-1'
    AND sto.sto_kod NOT LIKE N'EE.AOC.%'
    AND UPPER(ISNULL(sto.sto_isim, N'')) COLLATE Turkish_CI_AI NOT LIKE N'%MONTAJ%'
    AND UPPER(ISNULL(sto.sto_isim, N'')) COLLATE Turkish_CI_AI NOT LIKE N'%HİZMET%'
    AND UPPER(ISNULL(sto.sto_isim, N'')) COLLATE Turkish_CI_AI NOT LIKE N'%HIZMET%'
    AND UPPER(ISNULL(sto.sto_isim, N'')) COLLATE Turkish_CI_AI NOT LIKE N'%AKILLI KART%'
    AND UPPER(ISNULL(sto.sto_isim, N'')) COLLATE Turkish_CI_AI NOT LIKE N'%ANAKART%'
    AND UPPER(ISNULL(sto.sto_isim, N'')) COLLATE Turkish_CI_AI NOT LIKE N'%BATARYA KAPAĞI%'
    AND UPPER(ISNULL(sto.sto_isim, N'')) COLLATE Turkish_CI_AI NOT LIKE N'%VİDA%'
    AND UPPER(ISNULL(sto.sto_isim, N'')) COLLATE Turkish_CI_AI NOT LIKE N'%VIDA%'
    AND UPPER(ISNULL(sto.sto_isim, N'')) COLLATE Turkish_CI_AI NOT LIKE N'%PLAKA%'
    AND UPPER(ISNULL(sto.sto_isim, N'')) COLLATE Turkish_CI_AI NOT LIKE N'%KAPAK%'
),
KesinStokRanked AS (
    SELECT
        raw.*,
        ROW_NUMBER() OVER (
            PARTITION BY raw.sto_kod
            ORDER BY
                CASE
                    WHEN raw.IsSmart = 1 OR raw.IsSmartPL = 1 THEN 1
                    WHEN raw.Kategori = N'MEKANİK KAPI KOLU' THEN 2
                    WHEN raw.Kategori = N'GÜVENLİK KASASI' THEN 3
                    WHEN raw.Kategori = N'AKILLI KİLİT' THEN 4
                    ELSE 9
                END,
                raw.RaporModelAdi
        ) AS rn
    FROM KesinStokRaw raw
),
KesinStok AS (
    SELECT
        RaporModelAdi,
        Kategori,
        IsSmart,
        IsSmartPL,
        sto_kod,
        sto_isim,
        sto_marka_kodu
    FROM KesinStokRanked
    WHERE rn = 1
),
Resmi2025Sonrasi AS (
    SELECT
        ks.RaporModelAdi,
        ks.Kategori,
        ks.IsSmart,
        ks.IsSmartPL,
        SUM(
            CASE
                WHEN UPPER(LTRIM(RTRIM(ISNULL(sth.sth_evrakno_seri, N'')))) COLLATE Turkish_CI_AI = N'Q'
                    THEN 0
                WHEN sth.sth_tip = 0
                    THEN ABS(ISNULL(sth.sth_miktar, 0))
                WHEN sth.sth_tip = 1
                    THEN -ABS(ISNULL(sth.sth_miktar, 0))
                ELSE 0
            END
        ) AS ResmiNetAdet
    FROM STOK_HAREKETLERI sth WITH (NOLOCK)
    INNER JOIN KesinStok ks ON ks.sto_kod = sth.sth_stok_kod
    WHERE
        sth.sth_tarih >= @HareketBaslangic
        AND sth.sth_tarih < DATEADD(DAY, 1, @RaporTarihi)
    GROUP BY
        ks.RaporModelAdi,
        ks.Kategori,
        ks.IsSmart,
        ks.IsSmartPL
),
ResmiModel AS (
    SELECT
        RaporModelAdi,
        Kategori,
        IsSmart,
        IsSmartPL,
        SUM(ResmiNetAdet) AS ResmiNetAdet
    FROM (
        SELECT
            RaporModelAdi,
            Kategori,
            IsSmart,
            IsSmartPL,
            Devir2024Adet AS ResmiNetAdet
        FROM Devir2024

        UNION ALL

        SELECT
            RaporModelAdi,
            Kategori,
            IsSmart,
            IsSmartPL,
            ResmiNetAdet
        FROM Resmi2025Sonrasi
    ) x
    GROUP BY
        RaporModelAdi,
        Kategori,
        IsSmart,
        IsSmartPL
),
FiiliModel AS (
    SELECT
        ks.RaporModelAdi,
        ks.Kategori,
        ks.IsSmart,
        ks.IsSmartPL,
        SUM(
            CASE
                WHEN ks.IsSmart = 1 OR ks.IsSmartPL = 1
                    THEN 0
                ELSE
                    ISNULL(dbo.fn_DepodakiMiktar(ks.sto_kod, 0, @RaporTarihi), 0)
                    - ISNULL(dbo.fn_DepodakiMiktar(ks.sto_kod, 6, @RaporTarihi), 0)
            END
        ) AS FiiliAdet
    FROM KesinStok ks
    GROUP BY
        ks.RaporModelAdi,
        ks.Kategori,
        ks.IsSmart,
        ks.IsSmartPL
),
ModelFinal AS (
    SELECT
        COALESCE(r.RaporModelAdi, f.RaporModelAdi) AS RaporModelAdi,
        COALESCE(r.Kategori, f.Kategori) AS Kategori,
        ISNULL(r.IsSmart, ISNULL(f.IsSmart, 0)) AS IsSmart,
        ISNULL(r.IsSmartPL, ISNULL(f.IsSmartPL, 0)) AS IsSmartPL,
        ISNULL(r.ResmiNetAdet, 0) AS ResmiNetAdet,
        CASE
            WHEN ISNULL(r.IsSmart, ISNULL(f.IsSmart, 0)) = 1
              OR ISNULL(r.IsSmartPL, ISNULL(f.IsSmartPL, 0)) = 1
                THEN 0
            ELSE ISNULL(f.FiiliAdet, 0)
        END AS FiiliAdet
    FROM ResmiModel r
    FULL OUTER JOIN FiiliModel f
        ON f.RaporModelAdi = r.RaporModelAdi
        AND f.Kategori = r.Kategori
        AND f.IsSmart = r.IsSmart
        AND f.IsSmartPL = r.IsSmartPL
),
KategoriOzet AS (
    SELECT
        Kategori,
        SUM(CASE WHEN IsSmart = 0 AND IsSmartPL = 0 THEN ResmiNetAdet ELSE 0 END) AS BrutResmiStok,
        CASE
            WHEN SUM(CASE WHEN IsSmart = 1 OR IsSmartPL = 1 THEN ResmiNetAdet ELSE 0 END) < 0
            THEN ABS(SUM(CASE WHEN IsSmart = 1 OR IsSmartPL = 1 THEN ResmiNetAdet ELSE 0 END))
            ELSE 0
        END AS SmartMahsupAdedi,
        SUM(CASE WHEN IsSmart = 0 AND IsSmartPL = 0 THEN FiiliAdet ELSE 0 END) AS FiiliStok,
        SUM(
            CASE
                WHEN IsSmart = 0 AND IsSmartPL = 0
                     AND ResmiNetAdet - FiiliAdet > 0
                    THEN ResmiNetAdet - FiiliAdet
                ELSE 0
            END
        ) AS BrutPozitifFark,
        SUM(
            CASE
                WHEN IsSmart = 0 AND IsSmartPL = 0
                     AND ResmiNetAdet - FiiliAdet < 0
                    THEN ABS(ResmiNetAdet - FiiliAdet)
                ELSE 0
            END
        ) AS GirisDuzeltmeAdedi
    FROM ModelFinal
    GROUP BY Kategori
),
Final AS (
    SELECT
        Kategori,
        BrutResmiStok,
        SmartMahsupAdedi,
        BrutResmiStok - SmartMahsupAdedi AS NetResmiStok,
        FiiliStok,
        BrutPozitifFark,
        GirisDuzeltmeAdedi,
        CASE
            WHEN BrutPozitifFark - SmartMahsupAdedi > 0
                THEN BrutPozitifFark - SmartMahsupAdedi
            ELSE 0
        END AS KesilecekFaturaAdedi
    FROM KategoriOzet
),
FinalRows AS (
    SELECT
        1 AS SortNo,
        CASE
            WHEN Kategori = N'MEKANİK KAPI KOLU' THEN 1
            WHEN Kategori = N'GÜVENLİK KASASI' THEN 2
            WHEN Kategori = N'AKILLI KİLİT' THEN 3
            ELSE 9
        END AS SortNo2,
        N'summary' AS row_type,
        Kategori,
        CAST(BrutResmiStok AS decimal(18,2)) AS BrutResmiStok,
        CAST(SmartMahsupAdedi AS decimal(18,2)) AS SmartMahsup,
        CAST(NetResmiStok AS decimal(18,2)) AS NetResmiStok,
        CAST(FiiliStok AS decimal(18,2)) AS MikroFiiliStokDepo6Haric,
        CAST(KesilecekFaturaAdedi AS decimal(18,2)) AS SatisFaturasiKesilecekAdet,
        CAST(GirisDuzeltmeAdedi AS decimal(18,2)) AS AlisGirisDuzeltmeAdedi,
        CAST(KesilecekFaturaAdedi - GirisDuzeltmeAdedi AS decimal(18,2)) AS NetStokEtkisi,
        CASE
            WHEN KesilecekFaturaAdedi > 0 AND GirisDuzeltmeAdedi > 0
                THEN N'Satış faturası kes + giriş/kod düzeltme kontrolü'
            WHEN KesilecekFaturaAdedi > 0
                THEN N'Satış faturası / resmi çıkış'
            WHEN GirisDuzeltmeAdedi > 0
                THEN N'Alış faturası / giriş düzelt'
            ELSE N'Uyumlu'
        END AS NetAksiyon,
        CAST(NULL AS nvarchar(255)) AS RaporModelAdi,
        CAST(NULL AS bit) AS IsSmart,
        CAST(NULL AS bit) AS IsSmartPL,
        CAST(NULL AS decimal(18,2)) AS ResmiNetAdet,
        CAST(NULL AS decimal(18,2)) AS FiiliAdet,
        CAST(NULL AS decimal(18,2)) AS Fark,
        CAST(NULL AS nvarchar(120)) AS Aksiyon
    FROM Final

    UNION ALL

    SELECT
        1 AS SortNo,
        4 AS SortNo2,
        N'summary',
        N'Toplam',
        CAST(SUM(BrutResmiStok) AS decimal(18,2)),
        CAST(SUM(SmartMahsupAdedi) AS decimal(18,2)),
        CAST(SUM(NetResmiStok) AS decimal(18,2)),
        CAST(SUM(FiiliStok) AS decimal(18,2)),
        CAST(SUM(KesilecekFaturaAdedi) AS decimal(18,2)),
        CAST(SUM(GirisDuzeltmeAdedi) AS decimal(18,2)),
        CAST(SUM(KesilecekFaturaAdedi - GirisDuzeltmeAdedi) AS decimal(18,2)),
        N'Genel toplam',
        CAST(NULL AS nvarchar(255)),
        CAST(NULL AS bit),
        CAST(NULL AS bit),
        CAST(NULL AS decimal(18,2)),
        CAST(NULL AS decimal(18,2)),
        CAST(NULL AS decimal(18,2)),
        CAST(NULL AS nvarchar(120))
    FROM Final

    UNION ALL

    SELECT
        2 AS SortNo,
        CASE
            WHEN Kategori = N'MEKANİK KAPI KOLU' THEN 1
            WHEN Kategori = N'GÜVENLİK KASASI' THEN 2
            WHEN Kategori = N'AKILLI KİLİT' THEN 3
            ELSE 9
        END AS SortNo2,
        N'detail' AS row_type,
        Kategori,
        CAST(NULL AS decimal(18,2)) AS BrutResmiStok,
        CAST(NULL AS decimal(18,2)) AS SmartMahsup,
        CAST(NULL AS decimal(18,2)) AS NetResmiStok,
        CAST(NULL AS decimal(18,2)) AS MikroFiiliStokDepo6Haric,
        CAST(NULL AS decimal(18,2)) AS SatisFaturasiKesilecekAdet,
        CAST(NULL AS decimal(18,2)) AS AlisGirisDuzeltmeAdedi,
        CAST(NULL AS decimal(18,2)) AS NetStokEtkisi,
        CAST(NULL AS nvarchar(120)) AS NetAksiyon,
        RaporModelAdi,
        CAST(IsSmart AS bit) AS IsSmart,
        CAST(IsSmartPL AS bit) AS IsSmartPL,
        CAST(ResmiNetAdet AS decimal(18,2)) AS ResmiNetAdet,
        CAST(FiiliAdet AS decimal(18,2)) AS FiiliAdet,
        CAST(ResmiNetAdet - FiiliAdet AS decimal(18,2)) AS Fark,
        CASE
            WHEN IsSmart = 1 OR IsSmartPL = 1 THEN N'Sanal mahsup'
            WHEN ResmiNetAdet - FiiliAdet > 0 THEN N'Satış faturası / resmi çıkış'
            WHEN ResmiNetAdet - FiiliAdet < 0 THEN N'Alış / giriş / kod düzeltme'
            ELSE N'Uyumlu'
        END AS Aksiyon
    FROM ModelFinal
    WHERE
        ISNULL(ResmiNetAdet, 0) <> 0
        OR ISNULL(FiiliAdet, 0) <> 0
)
SELECT
    row_type,
    Kategori,
    BrutResmiStok,
    SmartMahsup,
    NetResmiStok,
    MikroFiiliStokDepo6Haric,
    SatisFaturasiKesilecekAdet,
    AlisGirisDuzeltmeAdedi,
    NetStokEtkisi,
    NetAksiyon,
    RaporModelAdi,
    IsSmart,
    IsSmartPL,
    ResmiNetAdet,
    FiiliAdet,
    Fark,
    Aksiyon
FROM FinalRows
ORDER BY
    SortNo,
    SortNo2,
    Kategori,
    RaporModelAdi;
SQL_ACCOUNTING_FINANCE_RESMI_STOK_KONTROL;
    }

    private function upsertAccountingFinanceResmiStokKontrol(): void
    {
        $this->upsert(
            self::ACCOUNTING_FINANCE_RESMI_STOK_KONTROL,
            'Resmi Stok Kontrolü',
            $this->accountingFinanceResmiStokKontrolPreviewTemplate(),
            ['date_from', 'date_to', 'bypass_cache'],
            'Muhasebe / Finans resmi stok, fiili stok ve kontrol farkları raporu n8n gateway üzerinden okunur.',
            'accounting_finance_resmi_stok_kontrol.sql'
        );
    }

    private function upsert(
        string $code,
        string $name,
        string $queryTemplate,
        array $allowedParams,
        string $description,
        string $sourceReference
    ): void {
        DataSource::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'db_type' => 'n8n_json',
                'query_template' => $queryTemplate,
                'allowed_params' => $allowedParams,
                'connection_meta' => [
                    'driver' => 'n8n_json',
                    'method' => 'POST',
                    'endpoint_url' => 'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1',
                    'response_rows_key' => 'rows',
                    'source_workflow' => 'PANEL - MSSQL Gateway - DataSource Runner v1',
                    'source_reference' => $sourceReference,
                    'sql_policy' => 'crm_workflow_source',
                ],
                'preview_payload' => [],
                'active' => true,
                'description' => $description,
            ],
        );
    }

    private function salesTemplateWithCustomerGroupScope(string $template, bool $onlinePerakende): string
    {
        $groupCodes = "N'120.01',N'120.02',N'120.03',N'120.04',N'120.05',N'120.06',N'120.07',N'120.08',N'120.09',N'120.16'";
        $filter = $onlinePerakende
            ? "    AND ISNULL(ch.cari_grup_kodu, N'') IN ({$groupCodes})"
            : "    AND (NULLIF(LTRIM(RTRIM(ISNULL(ch.cari_grup_kodu, N''))), N'') IS NULL OR ch.cari_grup_kodu NOT IN ({$groupCodes}))";

        $needle = "WHERE\n    ABS(c.net_tutar) > 1";

        if (! str_contains($template, $needle) || str_contains($template, '120.01')) {
            return $template;
        }

        return str_replace($needle, $needle."\n".$filter, $template);
    }
}
