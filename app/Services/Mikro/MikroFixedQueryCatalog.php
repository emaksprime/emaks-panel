<?php

namespace App\Services\Mikro;

use DateTimeImmutable;
use DomainException;

class MikroFixedQueryCatalog
{
    /** @var array<string, array{uri:string,sha256:string}> */
    private const TABLE_EVIDENCE = [
        'CARI_HESAPLAR' => [
            'uri' => 'https://www.mikroelterminali.com/databasehelp17/SDKv17/SDK/Tablolar/cari_hesaplar.htm',
            'sha256' => 'a215880cbb4678518555cda24ea4c9b4bd83714603b7e2105aff955a53c07b00',
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
        'STOK_HAREKETLERI' => [
            'uri' => 'https://www.mikroelterminali.com/databasehelp17/SDKv17/SDK/Tablolar/stok_hareketleri.htm',
            'sha256' => 'afefd6614826d36fea5b298cf3f98d1ee9c5af2a9656d602b7bdd6b98f30264d',
        ],
    ];

    /**
     * Every template is server-owned. Table and column identifiers are fixed against
     * the cited FLY V17 SDK pages; n8n is used only for business-parity comparison.
     * Callers can supply values only; table, column, expression and ordering are immutable.
     *
     * @var array<string, array{sql:string,parameters:array<string,string>,tables:array<int,string>}>
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
            'limit' => (string) $this->boundedInteger($value, 1, 500),
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
