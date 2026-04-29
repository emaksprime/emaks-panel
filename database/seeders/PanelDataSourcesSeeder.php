<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;

class PanelDataSourcesSeeder extends Seeder
{
    public function run(): void
    {
        $connectionMeta = [
            'driver' => 'n8n_json',
            'method' => 'POST',
            'endpoint_url' => 'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1',
            'response_rows_key' => 'rows',
            'source_workflow' => 'PANEL - MSSQL Gateway - DataSource Runner v1',
            'sql_policy' => 'unchanged',
        ];

        DataSource::query()->updateOrCreate(
            ['code' => 'sales_main_dashboard'],
            [
                'name' => 'Satış Yönetimi Dashboard',
                'db_type' => 'n8n_json',
                'query_template' => <<<'SQL_SALES_MAIN_DASHBOARD'
DECLARE @date_from DATE = '[[date_from]]';
DECLARE @date_to   DATE = '[[date_to]]';
DECLARE @detail_type NVARCHAR(10) = N'[[detail_type]]';
DECLARE @rep_code NVARCHAR(20) = N'[[rep_code]]';

IF OBJECT_ID('tempdb..#cube') IS NOT NULL
    DROP TABLE #cube;

IF OBJECT_ID('tempdb..#filtered') IS NOT NULL
    DROP TABLE #filtered;

SELECT
    CAST(msg_S_0089 AS date) AS hareket_tarihi,
    LTRIM(RTRIM(ISNULL(msg_S_1032, N''))) AS cari_kodu,
    LTRIM(RTRIM(ISNULL(msg_S_0201, N''))) AS cari_adi_raw,
    LTRIM(RTRIM(ISNULL(msg_S_0471, N''))) AS cari_grup_raw,
    LTRIM(RTRIM(ISNULL(msg_S_2663, N''))) AS stok_kodu_raw,
    LTRIM(RTRIM(ISNULL(msg_S_2664, N''))) AS urun_adi_raw,
    LTRIM(RTRIM(ISNULL(msg_S_0059, N''))) AS model_adi_raw,
    LTRIM(RTRIM(ISNULL(msg_S_0012, N''))) AS kategori_kodu_raw,
    CAST(N'' AS nvarchar(255)) AS kategori_adi_raw,
    CAST(ISNULL(msg_S_0165, 0) AS decimal(18,2)) AS adet,
    UPPER(LTRIM(RTRIM(ISNULL(msg_S_0118, N'')))) AS belge_tipi,
    UPPER(LTRIM(RTRIM(ISNULL(msg_S_2663, N'')))) AS stok_kodu_u,
    UPPER(LTRIM(RTRIM(ISNULL(msg_S_2664, N'')))) AS urun_adi_u,
    UPPER(LTRIM(RTRIM(ISNULL(msg_S_0059, N'')))) AS model_adi_u,
    UPPER(LTRIM(RTRIM(ISNULL(msg_S_0012, N'')))) AS kategori_kodu_u,
    CAST(ISNULL(msg_S_0535, 0) AS decimal(18,2)) AS net_tutar
INTO #cube
FROM dbo.fn_Stok_Masraf_Musteri_Grup_Hareket_Kubu(
    CONVERT(char(8), @date_from, 112),
    CONVERT(char(8), @date_to, 112),
    1,
    1
)
WHERE ISNULL(LTRIM(RTRIM(msg_S_1032)), N'') <> N'';

SELECT
    CASE
        WHEN NULLIF(c.cari_grup_raw, N'') IS NULL THEN N'KANALSIZ'
        ELSE c.cari_grup_raw
    END AS cari_grup_adi,
    c.cari_kodu,
    CASE
        WHEN REPLACE(REPLACE(UPPER(LTRIM(RTRIM(ISNULL(c.cari_kodu, N'')))), N'Ş', N'S'), N'İ', N'I') LIKE N'%TESHIR%'
        THEN
            CASE
                WHEN NULLIF(LTRIM(RTRIM(ISNULL(ch.cari_unvan1, N''))), N'') IS NULL
                    THEN c.cari_adi_raw + N' - TEŞHİR HESABI'
                ELSE LTRIM(RTRIM(ch.cari_unvan1)) + N' - TEŞHİR HESABI'
            END
        ELSE
            CASE
                WHEN NULLIF(LTRIM(RTRIM(ISNULL(ch.cari_unvan1, N''))), N'') IS NULL
                    THEN c.cari_adi_raw
                ELSE LTRIM(RTRIM(ch.cari_unvan1))
            END
    END AS cari_adi,
    CASE
        WHEN REPLACE(REPLACE(UPPER(LTRIM(RTRIM(ISNULL(c.cari_kodu, N'')))), N'Ş', N'S'), N'İ', N'I') LIKE N'%TESHIR%'
        THEN
            CASE
                WHEN NULLIF(LTRIM(RTRIM(ISNULL(ch.cari_unvan1, N''))), N'') IS NULL
                    THEN c.cari_adi_raw + N' - <strong>TEŞHİR HESABI</strong>'
                ELSE LTRIM(RTRIM(ch.cari_unvan1)) + N' - <strong>TEŞHİR HESABI</strong>'
            END
        ELSE
            CASE
                WHEN NULLIF(LTRIM(RTRIM(ISNULL(ch.cari_unvan1, N''))), N'') IS NULL
                    THEN c.cari_adi_raw
                ELSE LTRIM(RTRIM(ch.cari_unvan1))
            END
    END AS cari_adi_html,
    CASE
        WHEN NULLIF(LTRIM(RTRIM(ISNULL(c.model_adi_raw, N''))), N'') IS NULL
            THEN CASE
                    WHEN NULLIF(LTRIM(RTRIM(ISNULL(c.urun_adi_raw, N''))), N'') IS NULL THEN c.stok_kodu_raw
                    ELSE LTRIM(RTRIM(c.urun_adi_raw))
                 END
        ELSE LTRIM(RTRIM(c.model_adi_raw))
    END AS model_adi,
    CASE
        WHEN NULLIF(LTRIM(RTRIM(ISNULL(c.kategori_kodu_raw, N''))), N'') IS NULL THEN N'DİĞER GELİRLER'
        ELSE LTRIM(RTRIM(c.kategori_kodu_raw))
    END AS kategori_kodu,
    CASE
        WHEN NULLIF(LTRIM(RTRIM(ISNULL(c.kategori_adi_raw, N''))), N'') IS NULL THEN N'DİĞER GELİRLER'
        ELSE LTRIM(RTRIM(c.kategori_adi_raw))
    END AS kategori_adi,
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
    END AS net_tutar,
    CASE
        WHEN UPPER(LTRIM(RTRIM(ISNULL(c.cari_kodu, N'')))) LIKE N'%.KONSİNYE%'
          OR UPPER(LTRIM(RTRIM(ISNULL(c.cari_kodu, N'')))) LIKE N'%.KONSINYE%'
          OR UPPER(LTRIM(RTRIM(ISNULL(c.cari_kodu, N'')))) LIKE N'%KONSİNYE%'
          OR UPPER(LTRIM(RTRIM(ISNULL(c.cari_kodu, N'')))) LIKE N'%KONSINYE%'
        THEN 1 ELSE 0
    END AS is_konsinye,
    CASE
        WHEN UPPER(LTRIM(RTRIM(ISNULL(c.cari_kodu, N'')))) LIKE N'%.KONSİNYE%'
            THEN LEFT(c.cari_kodu, CHARINDEX('.KONSİNYE', UPPER(c.cari_kodu)) - 1)
        WHEN UPPER(LTRIM(RTRIM(ISNULL(c.cari_kodu, N'')))) LIKE N'%.KONSINYE%'
            THEN LEFT(c.cari_kodu, CHARINDEX('.KONSINYE', UPPER(c.cari_kodu)) - 1)
        ELSE c.cari_kodu
    END AS ana_cari_kodu
INTO #filtered
FROM #cube c
INNER JOIN CARI_HESAPLAR ch
    ON ch.cari_kod = c.cari_kodu
WHERE
    ABS(c.net_tutar) > 1
    AND NOT (
        c.belge_tipi IN (N'DEĞİŞİM', N'PROJE İÇİN NUMUNE ÜRÜN')
        AND ABS(c.net_tutar) < 10
    )
    AND (@rep_code = N'' OR LTRIM(RTRIM(ISNULL(ch.cari_temsilci_kodu, N''))) = @rep_code)
    AND (
        @cari_filter = N''
        OR (
            CHARINDEX(N',', @cari_filter) > 0
            AND c.cari_kodu IN
            (
                SELECT LTRIM(RTRIM(value))
                FROM STRING_SPLIT(@cari_filter, N',')
                WHERE LTRIM(RTRIM(value)) <> N''
            )
        )
        OR (
            CHARINDEX(N',', @cari_filter) = 0
            AND
            (
                c.cari_kodu = @cari_filter
                OR c.cari_kodu LIKE N'%' + @cari_filter + N'%'
                OR c.cari_adi_raw LIKE N'%' + @cari_filter + N'%'
                OR ch.cari_unvan1 LIKE N'%' + @cari_filter + N'%'
            )
        )
    )
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
    AND c.model_adi_u NOT LIKE N'%KESIF%';

IF @detail_type = N'urun'
BEGIN
    ;WITH filtered_main AS
    (
        SELECT * FROM #filtered WHERE is_konsinye = 0
    ),
    model_totals AS
    (
        SELECT model_adi, CAST(SUM(adet) AS decimal(18,2)) AS adet, CAST(SUM(net_tutar) AS decimal(18,2)) AS ciro,
               ROW_NUMBER() OVER (ORDER BY SUM(net_tutar) DESC, model_adi ASC) AS model_sira
        FROM filtered_main
        GROUP BY model_adi
    ),
    model_cari_detay AS
    (
        SELECT model_adi, cari_kodu, cari_adi, cari_adi_html, CAST(SUM(adet) AS decimal(18,2)) AS adet,
               CAST(SUM(net_tutar) AS decimal(18,2)) AS ciro,
               ROW_NUMBER() OVER (PARTITION BY model_adi ORDER BY SUM(net_tutar) DESC, cari_adi ASC) AS cari_sira
        FROM filtered_main
        GROUP BY model_adi, cari_kodu, cari_adi, cari_adi_html
    ),
    kategori_totals AS
    (
        SELECT kategori_kodu, kategori_adi, CAST(SUM(adet) AS decimal(18,2)) AS adet,
               ROW_NUMBER() OVER (ORDER BY SUM(adet) DESC, kategori_kodu ASC) AS kategori_sira
        FROM filtered_main
        GROUP BY kategori_kodu, kategori_adi
    ),
    konsinye_total AS
    (
        SELECT CAST(SUM(net_tutar) AS decimal(18,2)) AS konsinye_tutari
        FROM #filtered
        WHERE is_konsinye = 1
    )
    SELECT period_label, satir_tipi, cari_grup_adi, cari_kodu, satir_adi, satir_adi_html, adet, ciro, siralama_1, siralama_2, parent_key, konsinye_tutari, kategori_kodu, kategori_adi
    FROM
    (
        SELECT CONCAT(CONVERT(varchar(10), @date_from, 23), N' / ', CONVERT(varchar(10), @date_to, 23)) AS period_label,
               N'GRUP' AS satir_tipi, mt.model_adi AS cari_grup_adi, CAST(NULL AS nvarchar(50)) AS cari_kodu,
               mt.model_adi AS satir_adi, mt.model_adi AS satir_adi_html, mt.adet, mt.ciro, mt.model_sira AS siralama_1,
               0 AS siralama_2, CAST(NULL AS nvarchar(50)) AS parent_key,
               (SELECT TOP 1 konsinye_tutari FROM konsinye_total) AS konsinye_tutari,
               CAST(NULL AS nvarchar(50)) AS kategori_kodu, CAST(NULL AS nvarchar(255)) AS kategori_adi
        FROM model_totals mt
        UNION ALL
        SELECT CONCAT(CONVERT(varchar(10), @date_from, 23), N' / ', CONVERT(varchar(10), @date_to, 23)) AS period_label,
               N'DETAY' AS satir_tipi, md.model_adi AS cari_grup_adi, md.cari_kodu, md.cari_adi AS satir_adi,
               md.cari_adi_html AS satir_adi_html, md.adet, md.ciro, mt.model_sira AS siralama_1, md.cari_sira AS siralama_2,
               md.model_adi AS parent_key, (SELECT TOP 1 konsinye_tutari FROM konsinye_total) AS konsinye_tutari,
               CAST(NULL AS nvarchar(50)) AS kategori_kodu, CAST(NULL AS nvarchar(255)) AS kategori_adi
        FROM model_cari_detay md
        INNER JOIN model_totals mt ON mt.model_adi = md.model_adi
        UNION ALL
        SELECT CONCAT(CONVERT(varchar(10), @date_from, 23), N' / ', CONVERT(varchar(10), @date_to, 23)) AS period_label,
               N'KATEGORI' AS satir_tipi, CAST(NULL AS nvarchar(255)) AS cari_grup_adi, CAST(NULL AS nvarchar(50)) AS cari_kodu,
               kt.kategori_kodu AS satir_adi, kt.kategori_kodu AS satir_adi_html, kt.adet, CAST(0 AS decimal(18,2)) AS ciro,
               999999 AS siralama_1, kt.kategori_sira AS siralama_2, CAST(NULL AS nvarchar(50)) AS parent_key,
               (SELECT TOP 1 konsinye_tutari FROM konsinye_total) AS konsinye_tutari, kt.kategori_kodu, kt.kategori_adi
        FROM kategori_totals kt
    ) q
    ORDER BY siralama_1 ASC, CASE satir_tipi WHEN N'GRUP' THEN 0 WHEN N'DETAY' THEN 1 ELSE 2 END ASC, siralama_2 ASC;
END
ELSE
BEGIN
    ;WITH filtered_main AS
    (
        SELECT * FROM #filtered WHERE is_konsinye = 0
    ),
    group_totals AS
    (
        SELECT cari_grup_adi, CAST(SUM(adet) AS decimal(18,2)) AS adet, CAST(SUM(net_tutar) AS decimal(18,2)) AS ciro,
               ROW_NUMBER() OVER (ORDER BY SUM(net_tutar) DESC, cari_grup_adi ASC) AS grup_sira
        FROM filtered_main
        GROUP BY cari_grup_adi
    ),
    cari_totals AS
    (
        SELECT cari_grup_adi, cari_kodu, cari_adi, cari_adi_html, CAST(SUM(adet) AS decimal(18,2)) AS adet,
               CAST(SUM(net_tutar) AS decimal(18,2)) AS ciro,
               ROW_NUMBER() OVER (PARTITION BY cari_grup_adi ORDER BY SUM(net_tutar) DESC, cari_adi ASC) AS cari_sira
        FROM filtered_main
        GROUP BY cari_grup_adi, cari_kodu, cari_adi, cari_adi_html
    ),
    cari_urun_detay AS
    (
        SELECT cari_grup_adi, cari_kodu, cari_adi, cari_adi_html, model_adi, CAST(SUM(adet) AS decimal(18,2)) AS adet,
               CAST(SUM(net_tutar) AS decimal(18,2)) AS ciro,
               ROW_NUMBER() OVER (PARTITION BY cari_grup_adi, cari_kodu ORDER BY SUM(net_tutar) DESC, model_adi ASC) AS urun_sira
        FROM filtered_main
        GROUP BY cari_grup_adi, cari_kodu, cari_adi, cari_adi_html, model_adi
    ),
    kategori_totals AS
    (
        SELECT kategori_kodu, kategori_adi, CAST(SUM(adet) AS decimal(18,2)) AS adet,
               ROW_NUMBER() OVER (ORDER BY SUM(adet) DESC, kategori_kodu ASC) AS kategori_sira
        FROM filtered_main
        GROUP BY kategori_kodu, kategori_adi
    ),
    konsinye_total AS
    (
        SELECT CAST(SUM(net_tutar) AS decimal(18,2)) AS konsinye_tutari
        FROM #filtered
        WHERE is_konsinye = 1
    )
    SELECT period_label, satir_tipi, cari_grup_adi, cari_kodu, satir_adi, satir_adi_html, adet, ciro, siralama_1, siralama_2, parent_key, konsinye_tutari, kategori_kodu, kategori_adi
    FROM
    (
        SELECT CONCAT(CONVERT(varchar(10), @date_from, 23), N' / ', CONVERT(varchar(10), @date_to, 23)) AS period_label,
               N'GRUP' AS satir_tipi, gt.cari_grup_adi, CAST(NULL AS nvarchar(50)) AS cari_kodu,
               gt.cari_grup_adi AS satir_adi, gt.cari_grup_adi AS satir_adi_html, gt.adet, gt.ciro, gt.grup_sira AS siralama_1,
               0 AS siralama_2, CAST(NULL AS nvarchar(50)) AS parent_key,
               (SELECT TOP 1 konsinye_tutari FROM konsinye_total) AS konsinye_tutari,
               CAST(NULL AS nvarchar(50)) AS kategori_kodu, CAST(NULL AS nvarchar(255)) AS kategori_adi
        FROM group_totals gt
        UNION ALL
        SELECT CONCAT(CONVERT(varchar(10), @date_from, 23), N' / ', CONVERT(varchar(10), @date_to, 23)) AS period_label,
               N'CARI' AS satir_tipi, ct.cari_grup_adi, ct.cari_kodu, ct.cari_adi AS satir_adi,
               ct.cari_adi_html AS satir_adi_html, ct.adet, ct.ciro, gt.grup_sira AS siralama_1, ct.cari_sira AS siralama_2,
               ct.cari_kodu AS parent_key, (SELECT TOP 1 konsinye_tutari FROM konsinye_total) AS konsinye_tutari,
               CAST(NULL AS nvarchar(50)) AS kategori_kodu, CAST(NULL AS nvarchar(255)) AS kategori_adi
        FROM cari_totals ct
        INNER JOIN group_totals gt ON gt.cari_grup_adi = ct.cari_grup_adi
        UNION ALL
        SELECT CONCAT(CONVERT(varchar(10), @date_from, 23), N' / ', CONVERT(varchar(10), @date_to, 23)) AS period_label,
               N'URUN' AS satir_tipi, cud.cari_grup_adi, cud.cari_kodu, cud.model_adi AS satir_adi,
               cud.model_adi AS satir_adi_html, cud.adet, cud.ciro, gt.grup_sira AS siralama_1, cud.urun_sira AS siralama_2,
               cud.cari_kodu AS parent_key, (SELECT TOP 1 konsinye_tutari FROM konsinye_total) AS konsinye_tutari,
               CAST(NULL AS nvarchar(50)) AS kategori_kodu, CAST(NULL AS nvarchar(255)) AS kategori_adi
        FROM cari_urun_detay cud
        INNER JOIN group_totals gt ON gt.cari_grup_adi = cud.cari_grup_adi
        UNION ALL
        SELECT CONCAT(CONVERT(varchar(10), @date_from, 23), N' / ', CONVERT(varchar(10), @date_to, 23)) AS period_label,
               N'KATEGORI' AS satir_tipi, CAST(NULL AS nvarchar(255)) AS cari_grup_adi, CAST(NULL AS nvarchar(50)) AS cari_kodu,
               kt.kategori_kodu AS satir_adi, kt.kategori_kodu AS satir_adi_html, kt.adet, CAST(0 AS decimal(18,2)) AS ciro,
               999999 AS siralama_1, kt.kategori_sira AS siralama_2, CAST(NULL AS nvarchar(50)) AS parent_key,
               (SELECT TOP 1 konsinye_tutari FROM konsinye_total) AS konsinye_tutari, kt.kategori_kodu, kt.kategori_adi
        FROM kategori_totals kt
    ) q
    ORDER BY siralama_1 ASC, CASE satir_tipi WHEN N'GRUP' THEN 0 WHEN N'CARI' THEN 1 WHEN N'URUN' THEN 2 ELSE 3 END ASC, siralama_2 ASC;
END
SQL_SALES_MAIN_DASHBOARD,
                'allowed_params' => ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code', 'cari_filter', 'customer_filter', 'search', 'page', 'bypass_cache'],
                'connection_meta' => $connectionMeta,
                'preview_payload' => [],
                'active' => true,
                'sort_order' => 10,
                'description' => 'Ana satış yönetimi için eski çalışan Sales TEST sorgusu.',
            ],
        );

        foreach ($this->metadataOnlySources() as $index => $source) {
            DataSource::query()->updateOrCreate(
                ['code' => $source['code']],
                [
                    'name' => $source['name'],
                    'db_type' => 'n8n_json',
                    'query_template' => '',
                    'allowed_params' => ['date_from', 'date_to', 'grain', 'detail_type', 'scope_key', 'rep_code', 'search', 'page', 'bypass_cache'],
                    'connection_meta' => [
                        ...$connectionMeta,
                        'query_status' => 'missing',
                        'reference' => $source['reference'],
                    ],
                    'preview_payload' => [
                        'mode' => 'query_missing',
                        'message' => 'Gercek sorgu PrimeCRM referansindan dogrulanip Admin > Veri Kaynaklari ekranindan eklenecek.',
                    ],
                    'active' => true,
                    'sort_order' => 30 + $index,
                    'description' => $source['description'],
                ],
            );
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function metadataOnlySources(): array
    {
        return [
            ['code' => 'sales_online_perakende_detail', 'name' => 'Online / Perakende Detay', 'reference' => 'SALES_ONLINE_PERAKENDE_DETAY_V1.json', 'description' => 'Online / Perakende satış datası; gerçek sorgu metadata ekranından yönetilir.'],
            ['code' => 'sales_bayi_proje_detail', 'name' => 'Bayi / Proje Detay', 'reference' => 'SALES_BAYI_PROJE_DETAY_V1.json', 'description' => 'Bayi / Proje satış datası; gerçek sorgu metadata ekranından yönetilir.'],
            ['code' => 'stock_dashboard', 'name' => 'Stok Listesi', 'reference' => 'StockService.cs, Views/Stock/Index.cshtml', 'description' => 'PrimeCRM stok listesi mantığına göre n8n datasource kaydı.'],
            ['code' => 'stock_critical', 'name' => 'Kritik Stoklar', 'reference' => 'StockService.cs', 'description' => 'Kritik stok görünümü için n8n datasource kaydı.'],
            ['code' => 'stock_warehouse', 'name' => 'Depo / Raf Durumu', 'reference' => 'StockService.cs', 'description' => 'Depo ve raf durumları için n8n datasource kaydı.'],
            ['code' => 'orders_alinan', 'name' => 'Alınan Siparişler', 'reference' => 'OrderService.cs, Orders/Alinan.cshtml', 'description' => 'Alınan sipariş listesi için n8n datasource kaydı.'],
            ['code' => 'orders_verilen', 'name' => 'Verilen Siparişler', 'reference' => 'OrderService.cs, Orders/Verilen.cshtml', 'description' => 'Verilen sipariş listesi için n8n datasource kaydı.'],
            ['code' => 'cari_list', 'name' => 'Müşteri Listesi', 'reference' => 'CariService.cs, Cari/Index.cshtml', 'description' => 'Cari arama ve müşteri listesi için n8n datasource kaydı.'],
            ['code' => 'cari_balance', 'name' => 'Müşteri Bakiyesi', 'reference' => 'CariBalanceController.cs, CariBalance/Index.cshtml', 'description' => 'Müşteri bakiye özeti için n8n datasource kaydı.'],
            ['code' => 'cari_statement', 'name' => 'Müşteri Ekstre', 'reference' => 'CariService.cs, Cari/Detail.cshtml', 'description' => 'Müşteri hareket ve ekstre satırları için n8n datasource kaydı.'],
            ['code' => 'cari_document_detail', 'name' => 'Cari Evrak Detayı', 'reference' => 'Cari/DocumentDetail.cshtml', 'description' => 'Cari evrak detayı için n8n datasource kaydı.'],
            ['code' => 'proforma_list', 'name' => 'Proforma Listesi', 'reference' => 'ProformaService.cs, Proforma/Index.cshtml', 'description' => 'Proforma liste ekranı için n8n datasource kaydı.'],
            ['code' => 'proforma_detail', 'name' => 'Proforma Detay', 'reference' => 'ProformaService.cs, Proforma/Detail.cshtml', 'description' => 'Proforma detay ve print görünümü için n8n datasource kaydı.'],
        ];
    }
}
