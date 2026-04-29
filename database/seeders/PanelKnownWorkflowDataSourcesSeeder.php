<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;

class PanelKnownWorkflowDataSourcesSeeder extends Seeder
{
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
                ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code', 'cari_filter', 'customer_filter', 'search', 'page', 'bypass_cache'],
                'SALES_ONLINE_PERAKENDE_DETAY_V1 kapsamı: online/perakende cari grup kodları sales_main_dashboard kanonik sorgusuna filtre olarak uygulanır.',
                'SALES_ONLINE_PERAKENDE_DETAY_V1.json'
            );

            $this->upsert(
                'sales_bayi_proje_detail',
                'Bayi / Proje Detay',
                $this->salesTemplateWithCustomerGroupScope($salesTemplate, false),
                ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code', 'cari_filter', 'customer_filter', 'search', 'page', 'bypass_cache'],
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
DECLARE @CanViewAll bit = CASE
    WHEN NULLIF(LTRIM(RTRIM(ISNULL(@RepCode, N''))), N'') IS NULL THEN 1
    ELSE 0
END;

SELECT TOP 80
    LTRIM(RTRIM(ISNULL(cari.cari_kod, N''))) AS cari_kodu,
    LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))) AS cari_unvani,
    LTRIM(RTRIM(ISNULL(grp.crg_isim, N''))) AS cari_grubu,
    CASE
        WHEN NULLIF(LTRIM(RTRIM(ISNULL(grp.crg_isim, N''))), N'') IS NULL
            THEN CONCAT(LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))), N' | ', LTRIM(RTRIM(ISNULL(cari.cari_kod, N''))))
        ELSE CONCAT(LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))), N' | ', LTRIM(RTRIM(ISNULL(cari.cari_kod, N''))), N' | ', LTRIM(RTRIM(ISNULL(grp.crg_isim, N''))))
    END AS display_text
FROM dbo.CARI_HESAPLAR cari WITH (NOLOCK)
LEFT JOIN dbo.CARI_HESAP_GRUPLARI grp WITH (NOLOCK)
    ON grp.crg_kod = cari.cari_grup_kodu
WHERE
    (@CanViewAll = 1 OR LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode)
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
        @Search = N''
        OR cari.cari_kod LIKE N'%' + @Search + N'%'
        OR cari.cari_unvan1 LIKE N'%' + @Search + N'%'
        OR ISNULL(grp.crg_isim, N'') LIKE N'%' + @Search + N'%'
    )
    AND NULLIF(LTRIM(RTRIM(ISNULL(cari.cari_kod, N''))), N'') IS NOT NULL
ORDER BY
    CASE WHEN cari.cari_kod = @Search THEN 0
         WHEN cari.cari_unvan1 = @Search THEN 1
         WHEN cari.cari_kod LIKE @Search + N'%' THEN 2
         WHEN cari.cari_unvan1 LIKE @Search + N'%' THEN 3
         ELSE 9 END,
    cari.cari_unvan1 ASC,
    cari.cari_kod ASC;
SQL_SALES_CUSTOMER_SEARCH,
            ['search', 'rep_code', 'scope_key', 'limit', 'bypass_cache'],
            'PrimeCRM SalesService.GetCustomerOptionsAsync müşteri arama sorgusu.',
            'SalesService.cs'
        );

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
DECLARE @BasTar DATE = '[[date_from]]';

WITH AcikSiparisler AS
(
    SELECT
        sip.sip_tarih,
        cari.cari_unvan1 AS cari_adi,
        sto.sto_isim AS stok_adi,
        mdl.mdl_ismi AS model_adi,
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
        AND CAST(sip.sip_tarih AS date) >= @BasTar
        AND sip.sip_tip = 0
        AND sip.sip_kapat_fl = 0
        AND ISNULL(sip.sip_miktar, 0) - ISNULL(sip.sip_teslim_miktar, 0) > 0
        AND UPPER(LTRIM(RTRIM(ISNULL(crg.crg_isim, N'')))) NOT LIKE N'%İHRACAT%'
)
SELECT
    CONVERT(varchar(10), sip_tarih, 23) AS [siparis_tarihi],
    cari_adi,
    ISNULL(NULLIF(model_adi, N''), stok_adi) AS [urun_adi],
    kalan_miktar,
    ROUND(
        CASE
            WHEN ISNULL(siparis_miktar, 0) = 0 THEN 0
            ELSE (
                (
                    ISNULL(sip_tutar, 0)
                    - ISNULL(iskonto_1, 0)
                    - ISNULL(iskonto_2, 0)
                    - ISNULL(iskonto_3, 0)
                ) / siparis_miktar
            )
        END, 2
    ) AS birim_fiyat,
    ROUND(
        CASE
            WHEN ISNULL(siparis_miktar, 0) = 0 THEN 0
            ELSE (
                (
                    ISNULL(sip_tutar, 0)
                    - ISNULL(iskonto_1, 0)
                    - ISNULL(iskonto_2, 0)
                    - ISNULL(iskonto_3, 0)
                ) / siparis_miktar
            ) * kalan_miktar
        END, 2
    ) AS kalan_tutar
FROM AcikSiparisler
ORDER BY
    sip_tarih DESC,
    cari_adi,
    urun_adi;
SQL_ORDERS_ALINAN,
            ['date_from', 'date_to', 'search', 'page', 'bypass_cache'],
            'EMAKS PRIME - Siparisler Workflow (TAM FIX) Code - Build SQL Alinan node sorgusu.',
            'EMAKS PRIME - Siparisler Workflow (TAM FIX).json'
        );

        $this->upsert(
            'orders_verilen',
            'Verilen Siparişler',
            <<<'SQL_ORDERS_VERILEN'
DECLARE @BasTar DATE = '[[date_from]]';

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
        AND ISNULL(sip.sip_miktar, 0) - ISNULL(sip.sip_teslim_miktar, 0) > 0
)
SELECT
    CONVERT(varchar(10), sip_teslim_tarih, 104) AS [teslim_tarihi],
    CASE DATEPART(MONTH, sip_teslim_tarih)
        WHEN 1 THEN N'Ocak'
        WHEN 2 THEN N'Şubat'
        WHEN 3 THEN N'Mart'
        WHEN 4 THEN N'Nisan'
        WHEN 5 THEN N'Mayıs'
        WHEN 6 THEN N'Haziran'
        WHEN 7 THEN N'Temmuz'
        WHEN 8 THEN N'Ağustos'
        WHEN 9 THEN N'Eylül'
        WHEN 10 THEN N'Ekim'
        WHEN 11 THEN N'Kasım'
        WHEN 12 THEN N'Aralık'
        ELSE N''
    END + N' ' +
    CASE
        WHEN DATEPART(DAY, sip_teslim_tarih) BETWEEN 1 AND 7 THEN N'1. Haftası'
        WHEN DATEPART(DAY, sip_teslim_tarih) BETWEEN 8 AND 14 THEN N'2. Haftası'
        WHEN DATEPART(DAY, sip_teslim_tarih) BETWEEN 15 AND 21 THEN N'3. Haftası'
        ELSE N'4. Haftası'
    END AS [teslim_tarihi_hafta],
    sip_stok_kod AS [stok_kodu],
    ISNULL(NULLIF(model_adi, N''), stok_adi) AS [stok_adi],
    stok_kategori_adi,
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
ORDER BY
    sip_teslim_tarih,
    ISNULL(NULLIF(model_adi, N''), stok_adi);
SQL_ORDERS_VERILEN,
            ['date_from', 'date_to', 'search', 'page', 'bypass_cache'],
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
DECLARE @PanelFilter NVARCHAR(50) = N'[[scope_key]]';
DECLARE @Take int = 200;

WITH CariBaz AS
(
    SELECT
        LTRIM(RTRIM(ISNULL(cari.cari_kod, N''))) AS [musteri_kodu],
        LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))) AS [musteri_adi],
        LTRIM(RTRIM(ISNULL(cari.cari_unvan2, N''))) AS [firma_unvani_2],
        LTRIM(RTRIM(ISNULL(grp.crg_isim, N''))) AS [grup],
        LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) AS [temsilci_kodu],
        LTRIM(RTRIM(ISNULL(cpt.cari_per_adi, N'') + CASE WHEN ISNULL(cpt.cari_per_soyadi, N'') = N'' THEN N'' ELSE N' ' + cpt.cari_per_soyadi END)) AS [temsilci],
        LTRIM(RTRIM(ISNULL(cari.cari_CepTel, N''))) AS [telefon],
        LTRIM(RTRIM(ISNULL(cari.cari_EMail, N''))) AS [email],
        LTRIM(RTRIM(ISNULL(cari.cari_il, N''))) AS [il],
        LTRIM(RTRIM(ISNULL(cari.cari_ilce, N''))) AS [ilce],
        CAST(ISNULL(
            CASE
                WHEN Cari_F10da_detay = 1 THEN dbo.fn_CariHesapAnaDovizBakiye('',0,cari.cari_kod,'','',NULL,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
                WHEN Cari_F10da_detay = 2 THEN dbo.fn_CariHesapAlternatifDovizBakiye('',0,cari.cari_kod,'','',NULL,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
                WHEN Cari_F10da_detay = 3 THEN dbo.fn_CariHesapOrjinalDovizBakiye('',0,cari.cari_kod,'','',0,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
                WHEN Cari_F10da_detay = 4 THEN dbo.fn_CariHareketSayisi(0,cari.cari_kod,'')
                ELSE 0
            END, 0) AS decimal(18,2)
        ) AS [bakiye]
    FROM dbo.CARI_HESAPLAR cari WITH (NOLOCK)
    LEFT OUTER JOIN dbo.CARI_HESAP_GRUPLARI grp WITH (NOLOCK) ON grp.crg_kod = cari.cari_grup_kodu
    LEFT OUTER JOIN dbo.CARI_PERSONEL_TANIMLARI cpt WITH (NOLOCK) ON cpt.cari_per_kod = cari.cari_temsilci_kodu
    LEFT OUTER JOIN dbo.vw_Gendata ON 1 = 1
    WHERE
        ((cari.cari_kod NOT LIKE N'320%' AND cari.cari_kod NOT LIKE N'331%') OR cari.cari_kod LIKE N'320.ÇLG%')
        AND (@CanViewAll = 1 OR LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode)
        AND (@Search = N'' OR cari.cari_kod LIKE N'%' + @Search + N'%' OR cari.cari_unvan1 LIKE N'%' + @Search + N'%' OR cari.cari_unvan2 LIKE N'%' + @Search + N'%' OR ISNULL(grp.crg_isim, N'') LIKE N'%' + @Search + N'%' OR ISNULL(cpt.cari_per_adi, N'') LIKE N'%' + @Search + N'%' OR ISNULL(cpt.cari_per_soyadi, N'') LIKE N'%' + @Search + N'%')
)
SELECT TOP (@Take)
    musteri_kodu,
    musteri_adi,
    firma_unvani_2,
    grup,
    telefon,
    email,
    il,
    ilce,
    bakiye,
    temsilci_kodu,
    temsilci
FROM CariBaz
WHERE @PanelFilter = N'' OR @PanelFilter = N'all' OR (@PanelFilter = N'receivable' AND bakiye > 0) OR (@PanelFilter = N'payable' AND bakiye < 0)
ORDER BY musteri_kodu ASC;
SQL_CUSTOMERS_LIST,
            ['search', 'scope_key', 'rep_code', 'page', 'bypass_cache'],
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

WITH CariScope AS
(
    SELECT
        LTRIM(RTRIM(ISNULL(cari.cari_kod, N''))) AS [musteri_kodu],
        LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))) AS [musteri_adi],
        LTRIM(RTRIM(ISNULL(grp.crg_isim, N''))) AS [grup],
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
        AND (@Search = N'' OR cari.cari_kod LIKE N'%' + @Search + N'%' OR cari.cari_unvan1 LIKE N'%' + @Search + N'%' OR cari.cari_unvan2 LIKE N'%' + @Search + N'%' OR ISNULL(grp.crg_isim, N'') LIKE N'%' + @Search + N'%' OR ISNULL(cpt.cari_per_adi, N'') LIKE N'%' + @Search + N'%' OR ISNULL(cpt.cari_per_soyadi, N'') LIKE N'%' + @Search + N'%')
)
SELECT
    musteri_kodu,
    musteri_adi,
    grup,
    CAST(CASE WHEN net_bakiye < 0 THEN ABS(net_bakiye) ELSE 0 END AS decimal(18,2)) AS [borc],
    CAST(CASE WHEN net_bakiye > 0 THEN net_bakiye ELSE 0 END AS decimal(18,2)) AS [alacak],
    net_bakiye
FROM CariScope
WHERE net_bakiye <> 0
ORDER BY ABS(net_bakiye) DESC, musteri_kodu ASC;
SQL_CUSTOMERS_BALANCE,
            ['search', 'rep_code', 'page', 'bypass_cache'],
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

;WITH Hareketler AS
(
    SELECT
        cha.cha_Guid AS [hareket_guid],
        CAST(cha.cha_tarihi AS date) AS [tarih],
        LTRIM(RTRIM(ISNULL(cha.cha_evrakno_seri, N''))) AS [evrak_seri],
        ISNULL(cha.cha_evrakno_sira, 0) AS [evrak_sira],
        LTRIM(RTRIM(ISNULL(cha.cha_belge_no, N''))) AS [belge_no],
        LTRIM(RTRIM(ISNULL(cha.cha_aciklama, N''))) AS [aciklama],
        CAST(CASE WHEN ISNULL(cha.cha_tip, 0) = 0 THEN ISNULL(cha.cha_meblag, 0) ELSE 0 END AS decimal(18,2)) AS [borc],
        CAST(CASE WHEN ISNULL(cha.cha_tip, 0) = 1 THEN ISNULL(cha.cha_meblag, 0) ELSE 0 END AS decimal(18,2)) AS [alacak],
        cha.cha_Guid AS SortGuid
    FROM dbo.CARI_HESAP_HAREKETLERI cha WITH (NOLOCK)
    WHERE cha.cha_kod = @CustomerCode
      AND cha.cha_tarihi >= @DateFrom
      AND cha.cha_tarihi < DATEADD(day, 1, @DateTo)
)
SELECT
    hareket_guid,
    tarih,
    evrak_seri,
    evrak_sira,
    belge_no,
    aciklama,
    borc,
    alacak,
    CAST(SUM(borc - alacak) OVER (ORDER BY tarih ASC, SortGuid ASC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS decimal(18,2)) AS [bakiye]
FROM Hareketler
ORDER BY tarih ASC, SortGuid ASC;
SQL_CUSTOMER_STATEMENT,
            ['customer_code', 'date_from', 'date_to', 'bypass_cache'],
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

SELECT TOP 1
    LTRIM(RTRIM(ISNULL(cari.cari_kod, N''))) AS [musteri_kodu],
    LTRIM(RTRIM(ISNULL(cari.cari_unvan1, N''))) AS [musteri_adi],
    LTRIM(RTRIM(ISNULL(cari.cari_unvan2, N''))) AS [firma_unvani_2],
    LTRIM(RTRIM(ISNULL(grp.crg_isim, N''))) AS [grup],
    LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) AS [temsilci_kodu],
    LTRIM(RTRIM(ISNULL(cpt.cari_per_adi, N'') + CASE WHEN ISNULL(cpt.cari_per_soyadi, N'') = N'' THEN N'' ELSE N' ' + cpt.cari_per_soyadi END)) AS [temsilci],
    LTRIM(RTRIM(ISNULL(cari.cari_CepTel, N''))) AS [telefon],
    LTRIM(RTRIM(ISNULL(cari.cari_EMail, N''))) AS [email],
    LTRIM(RTRIM(ISNULL(cari.cari_VergiKimlikNo, N''))) AS [vergi_no],
    LTRIM(RTRIM(ISNULL(cari.cari_vdaire_adi, N''))) AS [vergi_dairesi],
    LTRIM(RTRIM(ISNULL(cari.cari_il, N''))) AS [il],
    LTRIM(RTRIM(ISNULL(cari.cari_ilce, N''))) AS [ilce],
    CAST(ISNULL(
        CASE
            WHEN Cari_F10da_detay = 1 THEN dbo.fn_CariHesapAnaDovizBakiye('',0,cari.cari_kod,'','',NULL,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
            WHEN Cari_F10da_detay = 2 THEN dbo.fn_CariHesapAlternatifDovizBakiye('',0,cari.cari_kod,'','',NULL,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
            WHEN Cari_F10da_detay = 3 THEN dbo.fn_CariHesapOrjinalDovizBakiye('',0,cari.cari_kod,'','',0,NULL,NULL,0,MusteriTeminatMektubu_Bakiyeyi_Etkilemesin_fl,FirmaTeminatMektubu_Bakiyeyi_Etkilemesin_fl,DepozitoCeki_Bakiyeyi_Etkilemesin_fl,DepozitoSenedi_Bakiyeyi_Etkilemesin_fl,DepozitoNakitIslemler_Bakiyeyi_Etkilemesin_fl)
            WHEN Cari_F10da_detay = 4 THEN dbo.fn_CariHareketSayisi(0,cari.cari_kod,'')
            ELSE 0
        END, 0) AS decimal(18,2)
    ) AS [bakiye]
FROM dbo.CARI_HESAPLAR cari WITH (NOLOCK)
LEFT OUTER JOIN dbo.CARI_HESAP_GRUPLARI grp WITH (NOLOCK) ON grp.crg_kod = cari.cari_grup_kodu
LEFT OUTER JOIN dbo.CARI_PERSONEL_TANIMLARI cpt WITH (NOLOCK) ON cpt.cari_per_kod = cari.cari_temsilci_kodu
LEFT OUTER JOIN dbo.vw_Gendata ON 1 = 1
WHERE
    LTRIM(RTRIM(ISNULL(cari.cari_kod, N''))) = @CustomerCode
    AND (@CanViewAll = 1 OR LTRIM(RTRIM(ISNULL(cari.cari_temsilci_kodu, N''))) = @RepCode);
SQL_CUSTOMER_DETAIL,
            ['customer_code', 'rep_code', 'bypass_cache'],
            'PrimeCRM CariService.GetCariSummaryAsync müşteri detay mantığından uyarlanan kanonik sorgu.',
            'CariService.cs'
        );
        $this->upsert('customer_documents', 'Müşteri Evrak Detay', '', ['document_id', 'customer_code', 'bypass_cache'], 'PrimeCRM CariService.GetDocumentDetailAsync evrak detay mantığı için metadata kaydı. Query template admin panelden tamamlanacak.', 'CariService.cs');

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

    /**
     * @param  array<int, string>  $allowedParams
     */
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
