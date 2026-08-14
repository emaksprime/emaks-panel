<?php

namespace App\Services\Mikro;

use DateTimeImmutable;
use DomainException;

class MikroFixedQueryCatalog
{
    private const NORMALIZED_STOCK_CODE = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(LTRIM(RTRIM(sto.sto_kod))), N'İ', N'I'), N'Ş', N'S'), N'Ğ', N'G'), N'Ü', N'U'), N'Ö', N'O'), N'Ç', N'C')";

    private const NORMALIZED_STOCK_NAME = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(LTRIM(RTRIM(sto.sto_isim))), N'İ', N'I'), N'Ş', N'S'), N'Ğ', N'G'), N'Ü', N'U'), N'Ö', N'O'), N'Ç', N'C')";

    private const NORMALIZED_STOCK_SHORT_NAME = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(LTRIM(RTRIM(sto.sto_kisa_ismi))), N'İ', N'I'), N'Ş', N'S'), N'Ğ', N'G'), N'Ü', N'U'), N'Ö', N'O'), N'Ç', N'C')";

    /** @var array<string, array{uri:string,sha256:string}> */
    private const TABLE_EVIDENCE = [
        'CARI_HESAPLAR' => [
            'uri' => 'https://www.mikroelterminali.com/databasehelp17/SDKv17/SDK/Tablolar/cari_hesaplar.htm',
            'sha256' => 'a215880cbb4678518555cda24ea4c9b4bd83714603b7e2105aff955a53c07b00',
        ],
        'KUR_ISIMLERI_VIEW' => [
            'uri' => 'https://www.mikroelterminali.com/databasehelp17/SDKv17/SDK/Tablolar/kur_isimleri.htm',
            'sha256' => '97f0d70e20ed56b7844eed79c9fd2dbadbfb24ecd94d6ef090d74d723d6e626c',
        ],
        'CARI_HESAP_HAREKETLERI' => [
            'uri' => 'https://www.mikroelterminali.com/databasehelp17/SDKv17/SDK/Tablolar/cari_hesap_hareketleri.htm',
            'sha256' => 'ab3788f4254b5d8ba3e3e4c6e96c098bdd0235c2595f573e0000c171359c210e',
        ],
        'CIHAZ_HAREKETLERI' => [
            'uri' => 'https://www.mikroelterminali.com/databasehelp17/SDKv17/SDK/Tablolar/cihaz_hareketleri.htm',
            'sha256' => '170b709709b942d144e9106914fc82efcf78edf543be3028518f4934a0fe784c',
        ],
        'SIPARISLER' => [
            'uri' => 'https://www.mikroelterminali.com/databasehelp17/SDKv17/SDK/Tablolar/siparisler.htm',
            'sha256' => '03c9d67489cd9508baf738d7aecd2609607e768cdc169395656ad9a3986ef99d',
        ],
        'STOKLAR' => [
            'uri' => 'https://www.mikroelterminali.com/databasehelp17/SDKv17/SDK/Tablolar/stoklar.htm',
            'sha256' => '4d8c28a3b3e11eb669282cacd7b47b9996dfab46e616e51ed3457f85d0a91c75',
        ],
        'STOK_HAREKETLERI' => [
            'uri' => 'https://www.mikroelterminali.com/databasehelp17/SDKv17/SDK/Tablolar/stok_hareketleri.htm',
            'sha256' => 'afefd6614826d36fea5b298cf3f98d1ee9c5af2a9656d602b7bdd6b98f30264d',
        ],
        'STOK_SERINO_TANIMLARI' => [
            'uri' => 'https://www.mikroelterminali.com/databasehelp17/SDKv17/SDK/Tablolar/stok_serino_tanimlari.htm',
            'sha256' => 'db831ff499caf57b083b835941518eebe83037a47ca72e444c859be5940ab477',
        ],
    ];

    /**
     * Every template is server-owned. Table and column identifiers are fixed against
     * the cited FLY V17 SDK pages; n8n is used only for business-parity comparison.
     * Callers can supply values only; table, column, expression and ordering are immutable.
     *
     * @var array<string, array<string, mixed>>
     */
    private const QUERIES = [
        'customer.detail' => [
            'sql' => 'SELECT TOP 1 cari.cari_kod AS customer_code, cari.cari_unvan1 AS title, cari.cari_unvan2 AS title_2, cari.cari_grup_kodu AS group_code, cari.cari_temsilci_kodu AS representative_code FROM dbo.CARI_HESAPLAR AS cari WITH (NOLOCK) WHERE LTRIM(RTRIM(cari.cari_kod)) = [[customer_code]]',
            'parameters' => ['customer_code' => 'code'],
            'tables' => ['CARI_HESAPLAR'],
        ],
        'customer.balance' => [
            'sql' => 'SELECT TOP 1 cha.cha_kod AS customer_code, CAST(SUM(CASE WHEN ISNULL(cha.cha_tip, 0) = 0 THEN ISNULL(cha.cha_meblag, 0) ELSE -ISNULL(cha.cha_meblag, 0) END) AS decimal(18,2)) AS balance FROM dbo.CARI_HESAP_HAREKETLERI AS cha WITH (NOLOCK) WHERE LTRIM(RTRIM(cha.cha_kod)) = [[customer_code]] GROUP BY cha.cha_kod',
            'parameters' => ['customer_code' => 'code'],
            'tables' => ['CARI_HESAP_HAREKETLERI'],
        ],
        'customer.document.timeline' => [
            'sql' => 'SELECT TOP ([[limit]]) cha.cha_Guid AS document_guid, cha.cha_kod AS customer_code, cha.cha_tarihi AS document_date, cha.cha_evrak_tip AS document_type, cha.cha_evrakno_seri AS document_series, cha.cha_evrakno_sira AS document_number, cha.cha_aciklama AS description, cha.cha_meblag AS amount FROM dbo.CARI_HESAP_HAREKETLERI AS cha WITH (NOLOCK) WHERE LTRIM(RTRIM(cha.cha_kod)) = [[customer_code]] AND CAST(cha.cha_tarihi AS date) BETWEEN [[date_from]] AND [[date_to]] ORDER BY cha.cha_tarihi DESC, cha.cha_Guid DESC',
            'parameters' => ['customer_code' => 'code', 'date_from' => 'date', 'date_to' => 'date', 'limit' => 'limit'],
            'tables' => ['CARI_HESAP_HAREKETLERI'],
        ],
        'stock.availability' => [
            'sql' => 'SELECT TOP 1 sto.sto_kod AS stock_code, CAST(ISNULL(dbo.fn_DepodakiMiktar(sto.sto_kod, 1, GETDATE()), 0) AS decimal(18,2)) AS depot_1_quantity, CAST(ISNULL(dbo.fn_DepodakiMiktar(sto.sto_kod, 5, GETDATE()), 0) AS decimal(18,2)) AS depot_5_quantity, CAST(ISNULL(dbo.fn_DepodakiMiktar(sto.sto_kod, 1, GETDATE()), 0) + ISNULL(dbo.fn_DepodakiMiktar(sto.sto_kod, 5, GETDATE()), 0) AS decimal(18,2)) AS available_quantity FROM dbo.STOKLAR AS sto WITH (NOLOCK) WHERE LTRIM(RTRIM(sto.sto_kod)) = [[stock_code]]',
            'parameters' => ['stock_code' => 'code'],
            'tables' => ['STOKLAR'],
            'warehouse_context' => [1, 5],
            'depot_source_file' => 'database/seeders/PanelKnownWorkflowDataSourcesSeeder.php',
            'depot_source_id' => 'stock_warehouse',
        ],
        'stock.search' => [
            'sql' => "SELECT TOP (20) LTRIM(RTRIM(sto.sto_kod)) AS item_code, LTRIM(RTRIM(sto.sto_isim)) AS item_name, NULLIF(LTRIM(RTRIM(sto.sto_kisa_ismi)), N'') AS item_short_name, NULLIF(LTRIM(RTRIM(sto.sto_birim1_ad)), N'') AS unit_code, CAST(sto.sto_cins AS int) AS stock_type, CAST(sto.sto_detay_takip AS int) AS detail_tracking_type, CAST(ISNULL(sto.sto_iptal, 0) AS int) AS cancelled, CAST(ISNULL(sto.sto_hidden, 0) AS int) AS hidden FROM dbo.STOKLAR AS sto WITH (NOLOCK) WHERE ISNULL(sto.sto_iptal, 0) = 0 AND ISNULL(sto.sto_hidden, 0) = 0 AND (".self::NORMALIZED_STOCK_CODE." LIKE N'%' + [[search]] + N'%' ESCAPE N'~' OR ".self::NORMALIZED_STOCK_NAME." LIKE N'%' + [[search]] + N'%' ESCAPE N'~' OR ".self::NORMALIZED_STOCK_SHORT_NAME." LIKE N'%' + [[search]] + N'%' ESCAPE N'~') ORDER BY CASE WHEN ".self::NORMALIZED_STOCK_CODE.' = [[search]] THEN 0 WHEN '.self::NORMALIZED_STOCK_CODE." LIKE [[search]] + N'%' ESCAPE N'~' THEN 1 WHEN ".self::NORMALIZED_STOCK_NAME.' = [[search]] THEN 2 WHEN '.self::NORMALIZED_STOCK_NAME." LIKE [[search]] + N'%' ESCAPE N'~' THEN 3 WHEN ".self::NORMALIZED_STOCK_SHORT_NAME." LIKE [[search]] + N'%' ESCAPE N'~' THEN 4 ELSE 5 END, LTRIM(RTRIM(sto.sto_kod)) ASC",
            'parameters' => ['search' => 'search'],
            'tables' => ['STOKLAR'],
            'contract_id' => 'technical_service_part_search_v1',
        ],
        'stock.movement.list' => [
            'sql' => 'SELECT TOP ([[limit]]) sth.sth_Guid AS movement_guid, sth.sth_tarih AS movement_date, sth.sth_stok_kod AS stock_code, sth.sth_cari_kodu AS customer_code, sth.sth_tip AS movement_type, sth.sth_normal_iade AS is_return, sth.sth_miktar AS quantity, sth.sth_evrakno_seri AS document_series, sth.sth_evrakno_sira AS document_number FROM dbo.STOK_HAREKETLERI AS sth WITH (NOLOCK) WHERE LTRIM(RTRIM(sth.sth_stok_kod)) = [[stock_code]] AND CAST(sth.sth_tarih AS date) BETWEEN [[date_from]] AND [[date_to]] ORDER BY sth.sth_tarih DESC, sth.sth_Guid DESC',
            'parameters' => ['stock_code' => 'code', 'date_from' => 'date', 'date_to' => 'date', 'limit' => 'limit'],
            'tables' => ['STOK_HAREKETLERI'],
        ],
        'serial.lookup' => [
            'sql' => 'SELECT TOP 1 ch.ChHar_SeriNo AS serial_number, sth.sth_Guid AS movement_guid, sth.sth_tarih AS movement_date, sth.sth_stok_kod AS stock_code, sth.sth_cari_kodu AS customer_code, sth.sth_sip_uid AS order_guid, sth.sth_fat_uid AS invoice_guid FROM dbo.CIHAZ_HAREKETLERI AS ch WITH (NOLOCK) INNER JOIN dbo.STOK_HAREKETLERI AS sth WITH (NOLOCK) ON sth.sth_Guid = ch.ChHar_master_uid WHERE LTRIM(RTRIM(ch.ChHar_SeriNo)) = [[serial_number]] ORDER BY sth.sth_tarih DESC, sth.sth_Guid DESC',
            'parameters' => ['serial_number' => 'serial'],
            'tables' => ['CIHAZ_HAREKETLERI', 'STOK_HAREKETLERI'],
        ],
        'serial.history' => [
            'sql' => 'SELECT TOP ([[limit]]) ch.ChHar_SeriNo AS serial_number, sth.sth_Guid AS movement_guid, sth.sth_tarih AS movement_date, sth.sth_tip AS movement_type, sth.sth_normal_iade AS is_return, sth.sth_stok_kod AS stock_code, sth.sth_cari_kodu AS customer_code, sth.sth_evrakno_seri AS document_series, sth.sth_evrakno_sira AS document_number FROM dbo.CIHAZ_HAREKETLERI AS ch WITH (NOLOCK) INNER JOIN dbo.STOK_HAREKETLERI AS sth WITH (NOLOCK) ON sth.sth_Guid = ch.ChHar_master_uid WHERE LTRIM(RTRIM(ch.ChHar_SeriNo)) = [[serial_number]] ORDER BY sth.sth_tarih DESC, sth.sth_Guid DESC',
            'parameters' => ['serial_number' => 'serial', 'limit' => 'limit'],
            'tables' => ['CIHAZ_HAREKETLERI', 'STOK_HAREKETLERI'],
        ],
        'order.list' => [
            'sql' => 'SELECT TOP ([[limit]]) sip.sip_Guid AS order_guid, sip.sip_tarih AS order_date, sip.sip_evrakno_seri AS document_series, sip.sip_evrakno_sira AS document_number, sip.sip_musteri_kod AS customer_code, sip.sip_satici_kod AS representative_code, sip.sip_stok_kod AS stock_code, sip.sip_miktar AS ordered_quantity, sip.sip_teslim_miktar AS delivered_quantity FROM dbo.SIPARISLER AS sip WITH (NOLOCK) WHERE sip.sip_iptal = 0 AND CAST(sip.sip_tarih AS date) BETWEEN [[date_from]] AND [[date_to]] ORDER BY sip.sip_tarih DESC, sip.sip_Guid DESC',
            'parameters' => ['date_from' => 'date', 'date_to' => 'date', 'limit' => 'limit'],
            'tables' => ['SIPARISLER'],
        ],
        'order.detail' => [
            'sql' => 'SELECT TOP 1 sip.sip_Guid AS order_guid, sip.sip_tarih AS order_date, sip.sip_evrakno_seri AS document_series, sip.sip_evrakno_sira AS document_number, sip.sip_musteri_kod AS customer_code, sip.sip_satici_kod AS representative_code, sip.sip_aciklama AS description FROM dbo.SIPARISLER AS sip WITH (NOLOCK) WHERE sip.sip_Guid = [[order_guid]]',
            'parameters' => ['order_guid' => 'guid'],
            'tables' => ['SIPARISLER'],
        ],
        'order.lines' => [
            'sql' => 'SELECT TOP ([[limit]]) sip.sip_Guid AS order_guid, sip.sip_stok_kod AS stock_code, sip.sip_miktar AS ordered_quantity, sip.sip_teslim_miktar AS delivered_quantity, sth.sth_Guid AS movement_guid FROM dbo.SIPARISLER AS sip WITH (NOLOCK) LEFT JOIN dbo.STOK_HAREKETLERI AS sth WITH (NOLOCK) ON sth.sth_sip_uid = sip.sip_Guid WHERE sip.sip_Guid = [[order_guid]] ORDER BY sip.sip_Guid, sth.sth_Guid',
            'parameters' => ['order_guid' => 'guid', 'limit' => 'limit'],
            'tables' => ['SIPARISLER', 'STOK_HAREKETLERI'],
        ],
        'order.remaining.quantity' => [
            'sql' => 'SELECT TOP 1 sip.sip_Guid AS order_guid, CAST(ISNULL(sip.sip_miktar, 0) - ISNULL(sip.sip_teslim_miktar, 0) AS decimal(18,2)) AS remaining_quantity FROM dbo.SIPARISLER AS sip WITH (NOLOCK) WHERE sip.sip_Guid = [[order_guid]]',
            'parameters' => ['order_guid' => 'guid'],
            'tables' => ['SIPARISLER'],
        ],
        'invoice.list' => [
            'sql' => 'SELECT TOP ([[limit]]) cha.cha_Guid AS invoice_guid, cha.cha_tarihi AS invoice_date, cha.cha_kod AS customer_code, cha.cha_evrakno_seri AS document_series, cha.cha_evrakno_sira AS document_number, cha.cha_meblag AS amount FROM dbo.CARI_HESAP_HAREKETLERI AS cha WITH (NOLOCK) WHERE cha.cha_cinsi IN (6, 7, 13) AND CAST(cha.cha_tarihi AS date) BETWEEN [[date_from]] AND [[date_to]] ORDER BY cha.cha_tarihi DESC, cha.cha_Guid DESC',
            'parameters' => ['date_from' => 'date', 'date_to' => 'date', 'limit' => 'limit'],
            'tables' => ['CARI_HESAP_HAREKETLERI'],
            'business_scope' => 'ALL_INVOICES',
        ],
        'invoice.detail' => [
            'sql' => 'SELECT TOP 1 cha.cha_Guid AS invoice_guid, cha.cha_tarihi AS invoice_date, cha.cha_kod AS customer_code, cha.cha_evrakno_seri AS document_series, cha.cha_evrakno_sira AS document_number, cha.cha_aciklama AS description, cha.cha_meblag AS amount FROM dbo.CARI_HESAP_HAREKETLERI AS cha WITH (NOLOCK) WHERE cha.cha_Guid = [[invoice_guid]]',
            'parameters' => ['invoice_guid' => 'guid'],
            'tables' => ['CARI_HESAP_HAREKETLERI'],
        ],
        'invoice.lines' => [
            'sql' => 'SELECT TOP ([[limit]]) sth.sth_Guid AS movement_guid, sth.sth_fat_uid AS invoice_guid, sth.sth_stok_kod AS stock_code, sth.sth_miktar AS quantity, sth.sth_tutar AS amount, sth.sth_aciklama AS description FROM dbo.STOK_HAREKETLERI AS sth WITH (NOLOCK) WHERE sth.sth_fat_uid = [[invoice_guid]] ORDER BY sth.sth_Guid',
            'parameters' => ['invoice_guid' => 'guid', 'limit' => 'limit'],
            'tables' => ['STOK_HAREKETLERI'],
        ],
        'dispatch.list' => [
            'sql' => 'SELECT TOP ([[limit]]) sth.sth_Guid AS dispatch_guid, sth.sth_tarih AS dispatch_date, sth.sth_evrakno_seri AS document_series, sth.sth_evrakno_sira AS document_number, sth.sth_cari_kodu AS customer_code FROM dbo.STOK_HAREKETLERI AS sth WITH (NOLOCK) WHERE sth.sth_evraktip IN (1, 4) AND CAST(sth.sth_tarih AS date) BETWEEN [[date_from]] AND [[date_to]] ORDER BY sth.sth_tarih DESC, sth.sth_Guid DESC',
            'parameters' => ['date_from' => 'date', 'date_to' => 'date', 'limit' => 'limit'],
            'tables' => ['STOK_HAREKETLERI'],
        ],
        'dispatch.detail' => [
            'sql' => 'SELECT TOP 1 sth.sth_Guid AS dispatch_guid, sth.sth_tarih AS dispatch_date, sth.sth_evrakno_seri AS document_series, sth.sth_evrakno_sira AS document_number, sth.sth_cari_kodu AS customer_code, sth.sth_aciklama AS description FROM dbo.STOK_HAREKETLERI AS sth WITH (NOLOCK) WHERE sth.sth_Guid = [[dispatch_guid]]',
            'parameters' => ['dispatch_guid' => 'guid'],
            'tables' => ['STOK_HAREKETLERI'],
        ],
        'dispatch.lines' => [
            'sql' => 'SELECT TOP ([[limit]]) line.sth_Guid AS movement_guid, line.sth_stok_kod AS stock_code, line.sth_miktar AS quantity, line.sth_tutar AS amount FROM dbo.STOK_HAREKETLERI AS header WITH (NOLOCK) INNER JOIN dbo.STOK_HAREKETLERI AS line WITH (NOLOCK) ON line.sth_evrakno_seri = header.sth_evrakno_seri AND line.sth_evrakno_sira = header.sth_evrakno_sira AND line.sth_tarih = header.sth_tarih WHERE header.sth_Guid = [[dispatch_guid]] ORDER BY line.sth_Guid',
            'parameters' => ['dispatch_guid' => 'guid', 'limit' => 'limit'],
            'tables' => ['STOK_HAREKETLERI'],
        ],
        'return.list' => [
            'sql' => 'SELECT TOP ([[limit]]) sth.sth_Guid AS return_guid, sth.sth_tarih AS return_date, sth.sth_stok_kod AS stock_code, sth.sth_cari_kodu AS customer_code, sth.sth_miktar AS quantity, sth.sth_evrakno_seri AS document_series, sth.sth_evrakno_sira AS document_number FROM dbo.STOK_HAREKETLERI AS sth WITH (NOLOCK) WHERE ISNULL(sth.sth_normal_iade, 0) = 1 AND CAST(sth.sth_tarih AS date) BETWEEN [[date_from]] AND [[date_to]] ORDER BY sth.sth_tarih DESC, sth.sth_Guid DESC',
            'parameters' => ['date_from' => 'date', 'date_to' => 'date', 'limit' => 'limit'],
            'tables' => ['STOK_HAREKETLERI'],
        ],
        'return.detail' => [
            'sql' => 'SELECT TOP 1 sth.sth_Guid AS return_guid, sth.sth_tarih AS return_date, sth.sth_stok_kod AS stock_code, sth.sth_cari_kodu AS customer_code, sth.sth_miktar AS quantity, sth.sth_aciklama AS description FROM dbo.STOK_HAREKETLERI AS sth WITH (NOLOCK) WHERE sth.sth_Guid = [[return_guid]] AND ISNULL(sth.sth_normal_iade, 0) = 1',
            'parameters' => ['return_guid' => 'guid'],
            'tables' => ['STOK_HAREKETLERI'],
        ],
        'exchange.status' => [
            'sql' => 'SELECT TOP ([[limit]]) ch.ChHar_SeriNo AS serial_number, sth.sth_Guid AS movement_guid, sth.sth_tarih AS movement_date, sth.sth_tip AS movement_type, sth.sth_normal_iade AS is_return, sth.sth_stok_kod AS stock_code, sth.sth_cari_kodu AS customer_code FROM dbo.CIHAZ_HAREKETLERI AS ch WITH (NOLOCK) INNER JOIN dbo.STOK_HAREKETLERI AS sth WITH (NOLOCK) ON sth.sth_Guid = ch.ChHar_master_uid WHERE LTRIM(RTRIM(ch.ChHar_SeriNo)) = [[serial_number]] ORDER BY sth.sth_tarih DESC, sth.sth_Guid DESC',
            'parameters' => ['serial_number' => 'serial', 'limit' => 'limit'],
            'tables' => ['CIHAZ_HAREKETLERI', 'STOK_HAREKETLERI'],
        ],
        'replacement.serial.lookup' => [
            'sql' => 'SELECT TOP 1 ch.ChHar_SeriNo AS serial_number, sth.sth_Guid AS movement_guid, sth.sth_tarih AS movement_date, sth.sth_stok_kod AS stock_code, sth.sth_cari_kodu AS customer_code, sth.sth_aciklama AS replacement_context FROM dbo.CIHAZ_HAREKETLERI AS ch WITH (NOLOCK) INNER JOIN dbo.STOK_HAREKETLERI AS sth WITH (NOLOCK) ON sth.sth_Guid = ch.ChHar_master_uid WHERE LTRIM(RTRIM(ch.ChHar_SeriNo)) = [[serial_number]] ORDER BY sth.sth_tarih DESC, sth.sth_Guid DESC',
            'parameters' => ['serial_number' => 'serial'],
            'tables' => ['CIHAZ_HAREKETLERI', 'STOK_HAREKETLERI'],
        ],
        'parity.customer.discovery.v2' => [
            'sql' => 'SELECT TOP ([[limit]]) CONVERT(varchar(36), cari.cari_Guid) AS record_id, LTRIM(RTRIM(cari.cari_kod)) AS customer_code, cari.cari_unvan1 AS title_1, cari.cari_unvan2 AS title_2, cari.cari_grup_kodu AS customer_group_code, CAST(cari.cari_faal_terk AS int) AS active_abandon_code, CAST(cari.cari_firma_acik_kapal AS int) AS company_open_closed_flag, CAST(cari.cari_cari_kilitli_flg AS int) AS locked_flag, CAST(cari.cari_doviz_cinsi AS int) AS currency_index, CASE WHEN UPPER(LTRIM(RTRIM(currency.KUR_SEMBOLU))) = N\'TL\' THEN N\'TRY\' WHEN LEN(UPPER(LTRIM(RTRIM(currency.KUR_SEMBOLU)))) = 3 AND UPPER(LTRIM(RTRIM(currency.KUR_SEMBOLU))) COLLATE Latin1_General_100_BIN2 NOT LIKE N\'%[^A-Z]%\' THEN UPPER(LTRIM(RTRIM(currency.KUR_SEMBOLU))) ELSE NULL END AS currency_code, cari.cari_lastup_date AS source_updated_at FROM dbo.CARI_HESAPLAR AS cari LEFT JOIN dbo.KUR_ISIMLERI_VIEW AS currency ON currency.KUR_NUMARASI = cari.cari_doviz_cinsi WHERE LTRIM(RTRIM(cari.cari_kod)) <> N\'\' ORDER BY LTRIM(RTRIM(cari.cari_kod)), cari.cari_Guid',
            'parameters' => ['limit' => 'limit'],
            'tables' => ['CARI_HESAPLAR', 'KUR_ISIMLERI_VIEW'],
            'parity_only' => true,
        ],
        'parity.customer.detail.v2' => [
            'sql' => 'SELECT TOP 1 CONVERT(varchar(36), cari.cari_Guid) AS record_id, LTRIM(RTRIM(cari.cari_kod)) AS customer_code, cari.cari_unvan1 AS title_1, cari.cari_unvan2 AS title_2, cari.cari_grup_kodu AS customer_group_code, CAST(cari.cari_faal_terk AS int) AS active_abandon_code, CAST(cari.cari_firma_acik_kapal AS int) AS company_open_closed_flag, CAST(cari.cari_cari_kilitli_flg AS int) AS locked_flag, CAST(cari.cari_doviz_cinsi AS int) AS currency_index, CASE WHEN UPPER(LTRIM(RTRIM(currency.KUR_SEMBOLU))) = N\'TL\' THEN N\'TRY\' WHEN LEN(UPPER(LTRIM(RTRIM(currency.KUR_SEMBOLU)))) = 3 AND UPPER(LTRIM(RTRIM(currency.KUR_SEMBOLU))) COLLATE Latin1_General_100_BIN2 NOT LIKE N\'%[^A-Z]%\' THEN UPPER(LTRIM(RTRIM(currency.KUR_SEMBOLU))) ELSE NULL END AS currency_code, cari.cari_lastup_date AS source_updated_at FROM dbo.CARI_HESAPLAR AS cari LEFT JOIN dbo.KUR_ISIMLERI_VIEW AS currency ON currency.KUR_NUMARASI = cari.cari_doviz_cinsi WHERE LTRIM(RTRIM(cari.cari_kod)) = [[customer_code]]',
            'parameters' => ['customer_code' => 'code'],
            'tables' => ['CARI_HESAPLAR', 'KUR_ISIMLERI_VIEW'],
            'parity_only' => true,
        ],
        'parity.stock.discovery.v1' => [
            'sql' => 'SELECT TOP ([[limit]]) CONVERT(varchar(36), sto.sto_Guid) AS record_id, LTRIM(RTRIM(sto.sto_kod)) AS item_code, dep.warehouse_code, sto.sto_birim1_ad AS unit_name, CAST(dbo.fn_DepodakiMiktar(sto.sto_kod, dep.warehouse_code, [[as_of_date]]) AS decimal(18,6)) AS on_hand_quantity, CAST(sto.sto_detay_takip AS int) AS serial_tracking_code, CAST(sto.sto_pasif_fl AS int) AS item_active_flag, sto.sto_lastup_date AS source_updated_at FROM dbo.STOKLAR AS sto CROSS JOIN (SELECT 1 AS warehouse_code UNION ALL SELECT 5 AS warehouse_code) AS dep WHERE LTRIM(RTRIM(sto.sto_kod)) <> N\'\' ORDER BY LTRIM(RTRIM(sto.sto_kod)), dep.warehouse_code',
            'parameters' => ['limit' => 'limit', 'as_of_date' => 'date'],
            'tables' => ['STOKLAR'],
            'parity_only' => true,
        ],
        'parity.stock.detail.v1' => [
            'sql' => 'SELECT TOP 1 CONVERT(varchar(36), sto.sto_Guid) AS record_id, LTRIM(RTRIM(sto.sto_kod)) AS item_code, [[warehouse_code]] AS warehouse_code, sto.sto_birim1_ad AS unit_name, CAST(dbo.fn_DepodakiMiktar(sto.sto_kod, [[warehouse_code]], [[as_of_date]]) AS decimal(18,6)) AS on_hand_quantity, CAST(sto.sto_detay_takip AS int) AS serial_tracking_code, CAST(sto.sto_pasif_fl AS int) AS item_active_flag, sto.sto_lastup_date AS source_updated_at FROM dbo.STOKLAR AS sto WHERE LTRIM(RTRIM(sto.sto_kod)) = [[item_code]]',
            'parameters' => ['item_code' => 'code', 'warehouse_code' => 'warehouse', 'as_of_date' => 'date'],
            'tables' => ['STOKLAR'],
            'parity_only' => true,
        ],
        'parity.serial.discovery.v1' => [
            'sql' => 'SELECT TOP ([[limit]]) CONVERT(varchar(36), serials.chz_Guid) AS record_id, LTRIM(RTRIM(serials.chz_serino)) AS serial_number, LTRIM(RTRIM(serials.chz_stok_kodu)) AS item_code, serials.chz_lastup_date AS source_updated_at FROM dbo.STOK_SERINO_TANIMLARI AS serials WHERE LTRIM(RTRIM(serials.chz_serino)) <> N\'\' AND LTRIM(RTRIM(serials.chz_stok_kodu)) <> N\'\' ORDER BY LTRIM(RTRIM(serials.chz_serino)), LTRIM(RTRIM(serials.chz_stok_kodu))',
            'parameters' => ['limit' => 'limit'],
            'tables' => ['STOK_SERINO_TANIMLARI'],
            'parity_only' => true,
        ],
        'parity.serial.detail.v1' => [
            'sql' => 'SELECT TOP 1 CONVERT(varchar(36), serials.chz_Guid) AS record_id, LTRIM(RTRIM(serials.chz_serino)) AS serial_number, LTRIM(RTRIM(serials.chz_stok_kodu)) AS item_code, CAST(movement.ChHar_rezerve_fl AS int) AS reserved_flag, CAST(movement.sth_tip AS int) AS movement_type, movement.sth_giris_depo_no AS ingress_warehouse_code, movement.sth_cikis_depo_no AS egress_warehouse_code, movement.sth_cari_kodu AS customer_code, CONVERT(varchar(36), movement.sth_sip_uid) AS order_line_guid, CONVERT(varchar(36), movement.sth_fat_uid) AS invoice_line_guid, movement.sth_evrakno_seri AS movement_document_series, movement.sth_evrakno_sira AS movement_document_number, movement.sth_tarih AS movement_timestamp, serials.chz_lastup_date AS source_updated_at FROM dbo.STOK_SERINO_TANIMLARI AS serials OUTER APPLY (SELECT TOP 1 ch.ChHar_rezerve_fl, sth.sth_tip, sth.sth_giris_depo_no, sth.sth_cikis_depo_no, sth.sth_cari_kodu, sth.sth_sip_uid, sth.sth_fat_uid, sth.sth_evrakno_seri, sth.sth_evrakno_sira, sth.sth_tarih, sth.sth_Guid FROM dbo.CIHAZ_HAREKETLERI AS ch INNER JOIN dbo.STOK_HAREKETLERI AS sth ON sth.sth_Guid = ch.ChHar_master_uid WHERE ch.ChHar_master_tablo = 0 AND LTRIM(RTRIM(ch.ChHar_SeriNo)) = LTRIM(RTRIM(serials.chz_serino)) AND LTRIM(RTRIM(ch.ChHar_StokKodu)) = LTRIM(RTRIM(serials.chz_stok_kodu)) ORDER BY sth.sth_tarih DESC, sth.sth_Guid DESC) AS movement WHERE LTRIM(RTRIM(serials.chz_serino)) = [[serial_number]] AND LTRIM(RTRIM(serials.chz_stok_kodu)) = [[item_code]]',
            'parameters' => ['serial_number' => 'serial', 'item_code' => 'code'],
            'tables' => ['STOK_SERINO_TANIMLARI', 'CIHAZ_HAREKETLERI', 'STOK_HAREKETLERI'],
            'parity_only' => true,
        ],
        'parity.order.discovery.v1' => [
            'sql' => 'SELECT TOP ([[limit]]) MIN(CONVERT(varchar(36), sip.sip_Guid)) AS anchor_line_guid, CONCAT(CAST(sip.sip_tip AS varchar(4)), N\'|\', CAST(sip.sip_cins AS varchar(4)), N\'|\', LTRIM(RTRIM(sip.sip_evrakno_seri)), N\'|\', CAST(sip.sip_evrakno_sira AS varchar(20))) AS document_identity, LTRIM(RTRIM(sip.sip_evrakno_seri)) AS document_series, sip.sip_evrakno_sira AS document_number, MIN(sip.sip_musteri_kod) AS customer_code, MIN(sip.sip_tarih) AS order_date, MIN(sip.sip_teslim_tarih) AS requested_delivery_date, MIN(CAST(sip.sip_doviz_cinsi AS int)) AS currency_index, MIN(CAST(sip.sip_depono AS int)) AS warehouse_code, COUNT_BIG(*) AS line_count, MAX(sip.sip_lastup_date) AS source_updated_at FROM dbo.SIPARISLER AS sip WHERE CAST(sip.sip_tarih AS date) BETWEEN [[date_from]] AND [[date_to]] GROUP BY sip.sip_tip, sip.sip_cins, sip.sip_evrakno_seri, sip.sip_evrakno_sira ORDER BY MIN(sip.sip_tarih) DESC, LTRIM(RTRIM(sip.sip_evrakno_seri)), sip.sip_evrakno_sira',
            'parameters' => ['date_from' => 'date', 'date_to' => 'date', 'limit' => 'limit'],
            'tables' => ['SIPARISLER'],
            'parity_only' => true,
        ],
        'parity.order.detail.v1' => [
            'sql' => 'WITH anchor AS (SELECT TOP 1 sip_tip, sip_cins, sip_evrakno_seri, sip_evrakno_sira FROM dbo.SIPARISLER WHERE sip_Guid = [[order_anchor_line_guid]]) SELECT CONVERT(varchar(36), sip.sip_Guid) AS line_guid, CONCAT(CAST(sip.sip_tip AS varchar(4)), N\'|\', CAST(sip.sip_cins AS varchar(4)), N\'|\', LTRIM(RTRIM(sip.sip_evrakno_seri)), N\'|\', CAST(sip.sip_evrakno_sira AS varchar(20))) AS document_identity, LTRIM(RTRIM(sip.sip_evrakno_seri)) AS document_series, sip.sip_evrakno_sira AS document_number, sip.sip_satirno AS line_number, sip.sip_musteri_kod AS customer_code, sip.sip_tarih AS order_date, sip.sip_teslim_tarih AS requested_delivery_date, CAST(sip.sip_iptal AS int) AS cancelled_flag, CAST(sip.sip_kapat_fl AS int) AS closed_flag, CAST(sip.sip_durumu AS int) AS raw_order_state, CAST(sip.sip_doviz_cinsi AS int) AS currency_index, CAST(sip.sip_depono AS int) AS warehouse_code, sip.sip_stok_kod AS item_code, CAST(sip.sip_birim_pntr AS int) AS unit_pointer, CAST(sip.sip_miktar AS decimal(18,6)) AS ordered_quantity, CAST(sip.sip_teslim_miktar AS decimal(18,6)) AS delivered_quantity, CAST(sip.sip_miktar - sip.sip_teslim_miktar AS decimal(18,6)) AS open_quantity, CAST(sip.sip_b_fiyat AS decimal(18,6)) AS unit_price, CAST(sip.sip_tutar AS decimal(18,6)) AS line_net_amount, CAST(sip.sip_vergi AS decimal(18,6)) AS line_tax_amount, sip.sip_lastup_date AS source_updated_at FROM dbo.SIPARISLER AS sip INNER JOIN anchor ON anchor.sip_tip = sip.sip_tip AND anchor.sip_cins = sip.sip_cins AND anchor.sip_evrakno_seri = sip.sip_evrakno_seri AND anchor.sip_evrakno_sira = sip.sip_evrakno_sira ORDER BY sip.sip_satirno, sip.sip_Guid',
            'parameters' => ['order_anchor_line_guid' => 'guid'],
            'tables' => ['SIPARISLER'],
            'parity_only' => true,
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(string $queryId): array
    {
        $definition = self::QUERIES[$queryId] ?? null;
        if (! is_array($definition)) {
            throw new DomainException('MIKRO_FIXED_QUERY_UNKNOWN');
        }

        $tableEvidence = [];
        foreach ($definition['tables'] as $table) {
            $evidence = self::TABLE_EVIDENCE[$table] ?? null;
            if (! is_array($evidence)) {
                throw new DomainException('MIKRO_FIXED_QUERY_TABLE_EVIDENCE_MISSING');
            }
            $tableEvidence[$table] = $evidence;
        }

        return ['query_id' => $queryId, ...$definition, 'table_evidence' => $tableEvidence];
    }

    public function render(string $queryId, array $parameters): string
    {
        $definition = $this->definition($queryId);
        $expected = array_keys($definition['parameters']);

        if (array_diff(array_keys($parameters), $expected) !== [] || array_diff($expected, array_keys($parameters)) !== []) {
            throw new DomainException('MIKRO_QUERY_PARAMETER_INVALID');
        }

        $sql = (string) $definition['sql'];
        foreach ($definition['parameters'] as $name => $type) {
            $sql = str_replace('[[['.$name.']]]', $this->renderValue($type, $parameters[$name]), $sql);
            $sql = str_replace('[['.$name.']]', $this->renderValue($type, $parameters[$name]), $sql);
        }

        $this->assertSafeSql($sql);

        return $sql;
    }

    /**
     * The local-v2 workflow performs validated value substitution inside the
     * supplied template. Keep those placeholders inside SQL literals while the
     * Mikro API path continues to receive fully rendered SQL from render().
     */
    public function n8nTemplate(string $queryId): string
    {
        $definition = $this->definition($queryId);
        $sql = (string) $definition['sql'];

        foreach ($definition['parameters'] as $name => $type) {
            $placeholder = '[['.$name.']]';
            $replacement = match ($type) {
                'guid' => "CONVERT(uniqueidentifier, '[[{$name}]]')",
                'date' => "CONVERT(date, '[[{$name}]]')",
                'code', 'serial' => "N'[[{$name}]]'",
                'limit', 'warehouse' => $placeholder,
                default => throw new DomainException('MIKRO_QUERY_PARAMETER_INVALID'),
            };
            $sql = str_replace($placeholder, $replacement, $sql);
        }

        if (preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|TRUNCATE|EXEC(?:UTE)?|GRANT|REVOKE)\b/i', $sql)) {
            throw new DomainException('MIKRO_FIXED_QUERY_UNSAFE');
        }

        return $sql;
    }

    /**
     * @return array<int, string>
     */
    public function queryIds(): array
    {
        return array_keys(self::QUERIES);
    }

    private function renderValue(string $type, mixed $value): string
    {
        return match ($type) {
            'guid' => $this->quotedGuid($value),
            'date' => $this->quotedDate($value),
            'code' => $this->quotedRestrictedString($value, 80),
            'serial' => $this->quotedRestrictedString($value, 120),
            'search' => $this->quotedSearch($value),
            'limit' => (string) $this->boundedInteger($value, 1, 500),
            'warehouse' => (string) $this->allowedPilotWarehouse($value),
            default => throw new DomainException('MIKRO_QUERY_PARAMETER_INVALID'),
        };
    }

    private function quotedGuid(mixed $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            throw new DomainException('MIKRO_QUERY_PARAMETER_INVALID');
        }

        return "CONVERT(uniqueidentifier, '".strtolower($value)."')";
    }

    private function quotedDate(mixed $value): string
    {
        $value = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new DomainException('MIKRO_QUERY_PARAMETER_INVALID');
        }

        return "CONVERT(date, '".$value."')";
    }

    private function quotedRestrictedString(mixed $value, int $maxLength): string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $maxLength || ! preg_match('/^[\pL\pN._\/-]+$/u', $value)) {
            throw new DomainException('MIKRO_QUERY_PARAMETER_INVALID');
        }

        return "N'".$value."'";
    }

    private function quotedSearch(mixed $value): string
    {
        $value = trim((string) $value);
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        if (mb_strlen($value) < 2
            || mb_strlen($value) > 60
            || preg_match('/[\x00-\x1F\x7F]/u', $value)
            || str_contains($value, ';')
            || str_contains($value, '--')
            || str_contains($value, '/*')
            || str_contains($value, '*/')) {
            throw new DomainException('MIKRO_QUERY_PARAMETER_INVALID');
        }

        $value = strtr($value, [
            'İ' => 'I', 'I' => 'I', 'ı' => 'I', 'i' => 'I',
            'Ş' => 'S', 'ş' => 'S', 'Ğ' => 'G', 'ğ' => 'G',
            'Ü' => 'U', 'ü' => 'U', 'Ö' => 'O', 'ö' => 'O',
            'Ç' => 'C', 'ç' => 'C',
        ]);
        $value = mb_strtoupper($value, 'UTF-8');
        $value = str_replace(['~', '%', '_', "'"], ['~~', '~%', '~_', "''"], $value);

        return "N'".$value."'";
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new DomainException('MIKRO_QUERY_PARAMETER_INVALID');
        }

        $value = (int) $value;
        if ($value < $minimum || $value > $maximum) {
            throw new DomainException('MIKRO_QUERY_PARAMETER_INVALID');
        }

        return $value;
    }

    private function allowedPilotWarehouse(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || ! in_array((int) $value, [1, 5], true)) {
            throw new DomainException('MIKRO_QUERY_PARAMETER_INVALID');
        }

        return (int) $value;
    }

    private function assertSafeSql(string $sql): void
    {
        $trimmed = ltrim($sql);
        if (! preg_match('/^(SELECT|WITH)\b/i', $trimmed)
            || str_contains($sql, ';')
            || preg_match('/--|\/\*|\*\//', $sql)
            || preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|TRUNCATE|EXEC(?:UTE)?|GRANT|REVOKE)\b/i', $sql)
            || preg_match('/\[\[[a-z0-9_.-]+\]\]/i', $sql)) {
            throw new DomainException('MIKRO_FIXED_QUERY_UNSAFE');
        }
    }
}
