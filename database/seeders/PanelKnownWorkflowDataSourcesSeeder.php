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
                $salesTemplate,
                ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code', 'search', 'page', 'bypass_cache'],
                'Geçici olarak sales_main_dashboard çalışan sorgusuna bağlıdır; SALES_ONLINE_PERAKENDE_DETAY_V1 workflow sorgusu ile değiştirilmeye hazırdır.',
                'sales_main_dashboard'
            );

            $this->upsert(
                'sales_bayi_proje_detail',
                'Bayi / Proje Detay',
                $salesTemplate,
                ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code', 'search', 'page', 'bypass_cache'],
                'Geçici olarak sales_main_dashboard çalışan sorgusuna bağlıdır; SALES_BAYI_PROJE_DETAY_V1 workflow sorgusu ile değiştirilmeye hazırdır.',
                'sales_main_dashboard'
            );
        }

        $this->upsert(
            'stock_dashboard',
            'Stok Dashboard',
            <<<'SQL_STOCK'
WITH depo_miktarlari AS
(
    SELECT
        sto.sto_kod,
        sto.sto_isim,
        CAST(ISNULL(dbo.fn_DepodakiMiktar(sto.sto_kod, 1, GETDATE()), 0) AS decimal(18,2)) AS miktar
    FROM STOKLAR sto

    UNION ALL

    SELECT
        sto.sto_kod,
        sto.sto_isim,
        CAST(ISNULL(dbo.fn_DepodakiMiktar(sto.sto_kod, 5, GETDATE()), 0) AS decimal(18,2)) AS miktar
    FROM STOKLAR sto
)
SELECT
    sto_kod AS [stok_kodu],
    sto_isim AS [stok_adi],
    SUM(miktar) AS [toplam_miktar]
FROM depo_miktarlari
GROUP BY
    sto_kod,
    sto_isim
HAVING SUM(miktar) <> 0
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
        AND ISNULL(sip.sip_miktar, 0) > ISNULL(sip.sip_teslim_miktar, 0)
        AND sip.sip_tarih >= @BasTar
)
SELECT
    sip_tarih AS [siparis_tarihi],
    cari_adi,
    stok_adi,
    model_adi,
    siparis_miktar,
    teslim_miktar,
    kalan_miktar,
    CAST(sip_tutar - iskonto_1 - iskonto_2 - iskonto_3 AS decimal(18,2)) AS net_tutar
FROM AcikSiparisler
ORDER BY sip_tarih DESC, cari_adi ASC;
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
        AND sip.sip_tarih >= @BasTar
        AND ISNULL(sip.sip_miktar, 0) > ISNULL(sip.sip_teslim_miktar, 0)
)
SELECT
    sip_tarih AS [siparis_tarihi],
    sip_teslim_tarih AS [teslim_tarihi],
    CONCAT(ISNULL(sip_evrakno_seri, ''), '-', ISNULL(CAST(sip_evrakno_sira AS nvarchar(50)), '')) AS [evrak_no],
    sip_stok_kod AS [stok_kodu],
    stok_adi,
    stok_kategori_adi,
    model_adi,
    siparis_miktari,
    teslim_miktari,
    kalan_miktar,
    birim_fiyat,
    CAST(sip_tutar - iskonto_1 - iskonto_2 - iskonto_3 AS decimal(18,2)) AS net_tutar
FROM VerilenSiparisler
ORDER BY sip_tarih DESC, sip_stok_kod ASC;
SQL_ORDERS_VERILEN,
            ['date_from', 'date_to', 'search', 'page', 'bypass_cache'],
            'EMAKS PRIME - Siparisler Workflow (TAM FIX) Code - Build SQL Verilen node sorgusu.',
            'EMAKS PRIME - Siparisler Workflow (TAM FIX).json'
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
}
