<?php

namespace App\Services\Mikro;

use LogicException;

final class MikroContractEvidenceCatalog
{
    public const ALLOWED_STATUSES = [
        'OFFICIAL_CURRENT',
        'OFFICIAL_AND_SERVER_VERIFIED',
        'DEPOT_AND_SERVER_VERIFIED',
        'DOCUMENTED_SERVER_UNVERIFIED',
        'DOCUMENTED_NOT_AVAILABLE_ON_INSTALLED_PATCH',
        'DEPOT_PROVEN_UNDOCUMENTED',
        'CONTRACT_BLOCKED',
    ];

    public const PARITY_STATUSES = [
        'VERIFIED_SOURCE',
        'PENDING_SOURCE',
        'NOT_APPLICABLE_SYSTEM',
        'WRITE_REQUIRES_READBACK_CONTRACT',
        'CONTRACT_BLOCKED',
    ];

    private const LOCAL_POSTMAN = 'C:\\dev\\datas\\MikroAPI.postman_collection_V17.json';

    private const LOCAL_POSTMAN_HASH = '2c58c76c6f34758108612f43a292ceda7ebc4c89edc5479d21934e0509301e73';

    private const SQL_PAGE = 'https://apidocs.mikro.com.tr/apis/sql-sorgulama/paths/~1api~1apimethods~1sqlveriokuv2/post.md';

    private const SQL_PAGE_HASH = '21c117dc4136d3ce97a41688d33a9ed26bd218f6f90a1a1bdf2f00821f724262';

    /** @var array<string, array<string, mixed>> */
    private const DIRECT_READS = [
        'health.check' => [
            'page' => 'https://apidocs.mikro.com.tr/apis/login-logoff/paths/~1api~1apimethods~1healthcheck/get.md',
            'page_hash' => '741b8d685d89c9562b7ae6149f2ed6eca656460fd1205d4af50b882128c63f3a',
            'item' => 'Login-Logoff / HealthCheck',
            'local_item' => 'Login-Logoff / HealthCheck',
            'method' => 'GET',
            'path' => '/Api/APIMethods/HealthCheck',
            'request' => [],
            'response' => ['result'],
            'status' => 'OFFICIAL_AND_SERVER_VERIFIED',
            'canary' => 'PASS_HTTP_200_RESULT_KEY_2026-07-29',
            'evidence_hash' => 'fda21a86dc00acbb8f8f7d14ca9f30b19893d3679575d8213954021ff1f87f5f',
        ],
        'user.parameters' => [
            'page' => 'https://apidocs.mikro.com.tr/apis/listeler/paths/~1api~1apimethods~1kullaniciparametreleriv2/post.md',
            'page_hash' => 'a13a8af15078b11f9f0731b3246fdef4eb63b17e4212f64571bdb65c23eab164',
            'item' => 'Listeler / KullaniciParametreleriV2',
            'local_item' => 'Listeler / KullaniciParametreleriV2',
            'method' => 'POST',
            'path' => '/Api/APIMethods/KullaniciParametreleriV2',
            'request' => ['Mikro', 'Mikro.ApiKey', 'Mikro.CalismaYili', 'Mikro.FirmaKodu', 'Mikro.KullaniciKodu', 'Mikro.Sifre'],
            'response' => ['UNSPECIFIED_IN_SOURCE'],
            'evidence_hash' => '51036436854023f685b58b6ad02a0bb3b0f6daedff0c2e2975708ec4c81051a2',
        ],
        'user.list' => [
            'page' => 'https://apidocs.mikro.com.tr/apis/listeler/paths/~1api~1apimethods~1kullanicilistesiv2/post.md',
            'page_hash' => '40ea9ca079a451b2874e7b650f66260dceed59b59129110156b31fb9a9d668fc',
            'item' => 'Listeler / KullaniciListesiV2',
            'local_item' => 'Listeler / KullaniciListesiV2',
            'method' => 'POST',
            'path' => '/Api/APIMethods/KullaniciListesiV2',
            'request' => ['Mikro', 'Mikro.ApiKey', 'Mikro.CalismaYili', 'Mikro.FirmaKodu', 'Mikro.KullaniciKodu', 'Mikro.Sifre'],
            'response' => ['UNSPECIFIED_IN_SOURCE'],
            'evidence_hash' => '265a1e87549cc542fbabe5d09e37c83a5f12b3e3b31db1da5c8ef43da871e4ef',
        ],
        'customer.list' => [
            'page' => 'https://apidocs.mikro.com.tr/apis/cari/paths/~1api~1apimethods~1carilistesiv3/post.md',
            'page_hash' => 'f2e07568d9679247ae94a5e1eead2351a5c22d7c254f3b0493cade6899738286',
            'item' => 'Listeler / Cari Listesi V3',
            'local_item' => 'Listeler / Cari Listesi V3',
            'method' => 'POST',
            'path' => '/Api/APIMethods/CariListesiV3',
            'request' => ['CariKod', 'CariVKNTCNo', 'IlkTarih', 'Index', 'Mikro', 'Mikro.ApiKey', 'Mikro.CalismaYili', 'Mikro.FirmaKodu', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Size', 'SonTarih', 'Sort', 'TarihTipi'],
            'response' => ['UNSPECIFIED_IN_SOURCE'],
            'evidence_hash' => 'eafb66646baf2e0c2da9e9dc8400819e789b0e49753a1b67afa6f9efc73c3ae2',
        ],
        'stock.list' => [
            'page' => 'https://apidocs.mikro.com.tr/apis/stok/paths/~1api~1apimethods~1stoklistesiv2/post.md',
            'page_hash' => '67b8c41509d4981d3c6b3c86334c0653b42977fd3f495f84b6f9363b4cd797d7',
            'item' => 'Listeler / Stok Listesi V2',
            'local_item' => 'Listeler / Stok Listesi V2',
            'method' => 'POST',
            'path' => '/Api/APIMethods/StokListesiV2',
            'request' => ['IlkTarih', 'Index', 'Mikro', 'Mikro.ApiKey', 'Mikro.CalismaYili', 'Mikro.FirmaKodu', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Size', 'SonTarih', 'Sort', 'StokKod', 'TarihTipi'],
            'response' => ['result', 'result[].Data', 'result[].Data.StokListesi', 'result[].ErrorMessage', 'result[].IsError', 'result[].StatusCode'],
            'status' => 'OFFICIAL_AND_SERVER_VERIFIED',
            'canary' => 'PASS_3_BOUNDED_HTTP_200_STABLE_WRAPPER_2026-08-14',
            'runtime_enabled' => false,
            'contract_version' => MikroResponseSchemaCatalog::STOCK_LIST_CONTRACT_VERSION,
            'response_schema_fingerprint' => MikroResponseSchemaCatalog::STOCK_LIST_RESPONSE_SCHEMA_FINGERPRINT,
            'not_found_schema_fingerprint' => MikroResponseSchemaCatalog::STOCK_LIST_NOT_FOUND_FINGERPRINT,
            'installed_evidence' => [
                'type' => 'installed_server_structural_probe',
                'uri' => 'evidence://MikroStockContractDiscovery/20260814T120225Z',
                'sha256' => 'df832a6ade1c421c7decd0aa69ede26ba0abcfd1f5c0cc3f9178d3e24c0fdf6c',
            ],
            'evidence_hash' => 'df832a6ade1c421c7decd0aa69ede26ba0abcfd1f5c0cc3f9178d3e24c0fdf6c',
        ],
        'invoice.pdf' => [
            'page' => 'https://apidocs.mikro.com.tr/apis/e-fatura-islemleri/paths/~1api~1apimethods~1faturapdfv2/post.md',
            'page_hash' => 'effa426c9e32aa9158a0b3270fe441112bb1370ec4f6e8f4c7fbcfb58283963f',
            'item' => 'Alim Satim Evragi - Fatura / Fatura PDF V2',
            'local_item' => 'Alim Satim Evragi - Fatura / Fatura PDF V2',
            'method' => 'POST',
            'path' => '/API/APIMethods/FaturaPdfV2',
            'request' => ['Mikro', 'Mikro.ApiKey', 'Mikro.CalismaYili', 'Mikro.Fatura_Guid', 'Mikro.FirmaKodu', 'Mikro.KullaniciKodu', 'Mikro.Sifre'],
            'response' => ['UNSPECIFIED_IN_SOURCE'],
            'evidence_hash' => '3b28d97fd2562e638718544254898716b2b0be03750be57f6d9dbff8dfe6df7a',
        ],
        'dispatch.pdf' => [
            'page' => 'https://apidocs.mikro.com.tr/apis/e-irsaliye-islemleri/paths/~1api~1apimethods~1eirsaliyepdfv2/post.md',
            'page_hash' => '574bf897e203ff24cd480565b739aabc44bf19d5f8e4380d672e234295753f8a',
            'item' => 'E-Irsaliye Islemleri / EIrsaliyePdfV2',
            'local_item' => null,
            'method' => 'POST',
            'path' => '/API/APIMethods/EIrsaliyePdfV2',
            'request' => ['Mikro', 'Mikro.FirmaKodu', 'Mikro.CalismaYili', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Mikro.Apikey', 'Mikro.EFaturaTipi', 'Mikro.Id'],
            'response' => ['UNSPECIFIED_IN_SOURCE'],
            'api_key_field' => 'Apikey',
            'evidence_hash' => 'ba339654eb5c9169019191230d4429ffa9fe1b09631173f2361e8be490f44da5',
        ],
        'edocument.status' => [
            'page' => 'https://apidocs.mikro.com.tr/apis/e-fatura-islemleri/paths/~1api~1apimethods~1ebelgedurumsorgulamav2/post.md',
            'page_hash' => '9c58aa40fb940a93e756edc4f3899ee61ff5797ea94053a6be6761a0893c9e1b',
            'item' => 'E-Fatura Islemleri / EBelgeDurumSorgulamaV2',
            'local_item' => null,
            'method' => 'POST',
            'path' => '/API/APIMethods/EBelgeDurumSorgulamaV2',
            'request' => ['Mikro', 'Mikro.FirmaKodu', 'Mikro.CalismaYili', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Mikro.ApiKey', 'Mikro.EBelge', 'Mikro.EBelge.EFaturaTipi', 'Mikro.EBelge.EBelgeTipi', 'Mikro.EBelge.UUID'],
            'response' => ['status', 'data'],
            'evidence_hash' => '3a6d4a9e7b0788c78e897c2596a8b12d65a1fdd2fa29463132dc92de0b3b2dd7',
        ],
        'etaxpayer.check' => [
            'page' => 'https://apidocs.mikro.com.tr/apis/e-fatura-islemleri/paths/~1api~1apimethods~1emukellefsorgulamav2/post.md',
            'page_hash' => '10104054eef46ce7568f042ecc0d344da5f4740f85dc9679d1b8f22fef6c1565',
            'item' => 'E-Fatura Islemleri / EMukellefSorgulamaV2',
            'local_item' => null,
            'method' => 'POST',
            'path' => '/API/APIMethods/EMukellefSorgulamaV2',
            'request' => ['Mikro', 'Mikro.FirmaKodu', 'Mikro.CalismaYili', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Mikro.ApiKey', 'Mikro.EMukellef', 'Mikro.EMukellef.VKN_TCKN'],
            'response' => ['UNSPECIFIED_IN_SOURCE'],
            'evidence_hash' => '275369314d84533263249d14b63cef4be70ab08fbc9c2f6329a6b2b3cc12dd8a',
        ],
    ];

    /** @var array<string, string> */
    private const FIXED_QUERY_HASHES = [
        'customer.detail' => '79e03e50eadf97ad713574ca3d115de9f6ecc045798bd57d784bf7dba3835a48',
        'customer.balance' => '342098d3246cc84948062086050f0efca4781cfe4ee6f479704fb31c7644328a',
        'customer.document.timeline' => '77d202731c594bb6a67d048bf670e8bca8789130b700ea4a6de061bca2894ccb',
        'stock.availability' => '245e65f2a3f3a0fd4f7664559e225609b79869944f5a38a09a7ecdf18d8fdfb1',
        'stock.search' => '3b509a481a593be9f6b63fda83c796ecf5fe35e44b1a79beba8224f09f2981e1',
        'stock.physical_quantity' => '941539a9f84fcd69684f879fa725f54560f642309bfb260a64804aedc0777593',
        'stock.movement.list' => '7280b34fa4376964c60606c63b8101cbfcb136108895e443395f75016e0b40d3',
        'serial.lookup' => '53dbf0db942df656f470eff9c8a4b9d04dc384b5bde6d1268ec44ee6b344e9ea',
        'serial.history' => 'f7ea431026c2c8dce60ec0fe546704240d851a7f66d9ccf1f88f8ae98d63699b',
        'order.list' => 'aa1a67cacbcefbdf84407b8ba8f8b2dc50f977f374a6de06a72a2f1e0c13b49b',
        'order.detail' => '8812354f2e0ab3e5272251f0aa13a17be91493d0d4ae8f424d0630afc680d832',
        'order.lines' => 'cc8aff397c20a1960f8f69e08e9ebb34f53a79b8293f623ca3565808443fbd0b',
        'order.remaining.quantity' => '3af8aa8d464edd5191b05b6f20d004f119a5ad56d9222eb93a1169ecbf88c535',
        'invoice.list' => '26a78d7ba432ef9a1075158a5bbc6e266fc3488955f486af507569bdf9ac7dab',
        'invoice.detail' => '3fb281cf1aa64e09a9865cb8660a721363fd7e10c26cd3a4e292bc64ca2ec23e',
        'invoice.lines' => '9ab0e781e20eaaa6b0ed3c2658415f4d6dfb4e799ec077231b7503c5dc628776',
        'dispatch.list' => '20289f00d36a2e7ad33aa0336b559a32844ca48a2635922136ca22e31e5ae99d',
        'dispatch.detail' => '6e19d668ac7b93ef02775367b2b6210355e82bcc2c89274b01d0a13471f109c3',
        'dispatch.lines' => '57ccf5105177f083d1bb9fc4f6a70de237a0e228f19c0155dcb91e8573bbf1a0',
        'return.list' => 'bfcee1c314b97320e7474abb8d2cdfc75dd7b04e84f3f13f37b8d1944159675e',
        'return.detail' => 'd6508192a14046cbb663a7457618eb3d0d21fac1e657464b089e1f9535fa2b78',
        'exchange.status' => '6b6ff7242bb6b25026e0c4a5daa6b32d692acf6594b8be4c87c4662bc25f046a',
        'replacement.serial.lookup' => '4d69bfaac58ef3b48cb2deede41909e1ee38f23275d84785e6ebfdc89c5cd8b9',
    ];

    /** @var array<string, array<string, mixed>> */
    private const WRITES = [
        'customer.save' => ['path' => '/API/APIMethods/CariKaydetV2', 'item' => 'Cari / Cari Kaydet V2 Save', 'request' => ['Mikro', 'Mikro.FirmaKodu', 'Mikro.CalismaYili', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Mikro.cariler'], 'evidence_hash' => '2bbcb2d53f57e935ce5e5a07278b7637fcb4fa6b6022a20bab9725ad054a0c41'],
        'order.save' => ['path' => '/api/APIMethods/SiparisKaydetV2', 'item' => 'Siparis / Siparis Kaydet V2 Save', 'request' => ['Mikro', 'Mikro.FirmaKodu', 'Mikro.CalismaYili', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Mikro.ApiKey', 'Mikro.FirmaNo', 'Mikro.SubeNo', 'Mikro.evraklar'], 'evidence_hash' => '119051e452f2f991da7aea575eb577c5b1332f7260b482cffb59730c9339c71c'],
        'invoice.create' => ['path' => '/api/APIMethods/FaturaKaydetV3', 'item' => 'Alim Satim Evragi - Fatura / Fatura Kaydet V3 Save', 'request' => ['Mikro', 'Mikro.FirmaKodu', 'Mikro.CalismaYili', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Mikro.evraklar'], 'evidence_hash' => 'ca788b34f5459528a194960046edf9dd92949a6cfdc217363a0590d8a8e76f0c'],
        'dispatch.create' => ['path' => '/Api/apiMethods/IrsaliyeKaydetV2', 'item' => 'Irsaliye / IrsaliyeKaydet V2 Save', 'request' => ['Mikro', 'Mikro.FirmaKodu', 'Mikro.CalismaYili', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Mikro.FirmaNo', 'Mikro.SubeNo', 'Mikro.evraklar'], 'evidence_hash' => '4f4f26142afe5f0e1e9e8aa9909ea1ce9113a4a1eadde6d7ac5c002e346a01e2'],
        'record.link.save' => ['path' => '/Api/apiMethods/KayitKaydetV2', 'item' => 'Kayit Kaydet / Kayit Kaydet V2 Save', 'request' => ['Mikro', 'Mikro.FirmaKodu', 'Mikro.CalismaYili', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Mikro.Tablo', 'Mikro.Kayit'], 'evidence_hash' => '7d5a2b5d0c33c468a6b3f50e5bcfb9b02eeaf380ec85c5295018feb0a91c3178'],
        'record.bulk.save' => ['path' => '/Api/apiMethods/KayitKaydetTopluV2', 'item' => 'Kayit Kaydet Toplu / Kayit Kaydet Toplu V2 Save', 'request' => ['Mikro', 'Mikro.FirmaKodu', 'Mikro.CalismaYili', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Mikro.Kayit'], 'evidence_hash' => 'c9cda9e5bc0f83498311d778775bad70d378e182a9b576cef34cda301fde2edb'],
        'stock.transfer.create' => ['path' => '/Api/apiMethods/DahiliStokHareketKaydetV2', 'item' => 'Dahili Stok Hareket / Dahili Stok Hareket Kaydet V2 Save', 'request' => ['Mikro', 'Mikro.FirmaKodu', 'Mikro.CalismaYili', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Mikro.FirmaNo', 'Mikro.SubeNo', 'Mikro.evraklar'], 'evidence_hash' => 'a3163ab64b311f57a33a4802b49f406556d7583fe4f3f07e9ba2df0a3422f158'],
        'order.dispatch.legacy.create' => ['path' => '/api/APIMethods/SiparistenIrsaliyeOlusturmaV2', 'item' => 'Irsaliye / Siparisten Irsaliye Olusturma V2 Save', 'request' => ['Mikro', 'Mikro.FirmaKodu', 'Mikro.CalismaYili', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Mikro.evraklar'], 'evidence_hash' => 'b27d444fede6ff336c3273577c7e611e89d435d29c250f98d863a4158eb700c3'],
        'proforma.create' => ['path' => '/Api/apiMethods/ProformaSiparisKaydetV2', 'item' => 'Proforma Siparis / Proforma Siparis Kaydet V2 Save', 'request' => ['Mikro', 'Mikro.FirmaKodu', 'Mikro.CalismaYili', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'Mikro.evraklar'], 'evidence_hash' => '0831d56ca255389265cd5e9af1ed48579182116d287ceec7aa4df24a71fe30a9'],
    ];

    /** @var array<string, array{evidence_hash:string,blocker:string}> */
    private const BLOCKED = [
        'proforma.list' => ['evidence_hash' => 'c44af447b2fd77ae08609c2d81959040b605c222fe441be9fd2711e892f2d280', 'blocker' => 'Exact official/depot read contract is unavailable.'],
        'proforma.detail' => ['evidence_hash' => '07c4d23e0e87dc9530a84866fc1b34612b5093f3633225f0ffb0a6e017286e91', 'blocker' => 'Exact official/depot read contract is unavailable.'],
        'return.create' => ['evidence_hash' => '1d500819e2d1e6e9a7992fefba3b8a2df6625260a2bfd90450eed7eaf49f97fc', 'blocker' => 'Exact official/depot write contract is unavailable.'],
        'exchange.create' => ['evidence_hash' => '20deb28fc2d686c9b9a4e6f75ca0213b50b2759cf184be566defe5f55ffc3257', 'blocker' => 'Exact official/depot write contract is unavailable.'],
    ];

    /** @return array<string, mixed> */
    public static function for(string $operationKey, string $mode, string $adapterType): array
    {
        if (isset(self::BLOCKED[$operationKey])) {
            return self::blocked($operationKey, $mode);
        }
        if ($mode === 'READ' && $adapterType === 'FIXED_QUERY') {
            return self::fixedQuery($operationKey);
        }
        if ($mode === 'READ' && isset(self::DIRECT_READS[$operationKey])) {
            return self::directRead($operationKey);
        }
        if ($mode === 'WRITE' && isset(self::WRITES[$operationKey])) {
            return self::write($operationKey);
        }

        throw new LogicException('MIKRO_CONTRACT_EVIDENCE_MISSING');
    }

    /** @return array<string, mixed> */
    private static function directRead(string $operationKey): array
    {
        $entry = self::DIRECT_READS[$operationKey];
        $sources = [[
            'type' => 'official_current',
            'uri' => $entry['page'],
            'sha256' => $entry['page_hash'],
        ]];
        if ($entry['local_item'] !== null) {
            $sources[] = self::localPostmanSource();
        }
        if (is_array($entry['installed_evidence'] ?? null)) {
            $sources[] = $entry['installed_evidence'];
        }
        $status = $entry['status'] ?? 'DOCUMENTED_SERVER_UNVERIFIED';

        return self::descriptor([
            'operation_key' => $operationKey,
            'mode' => 'READ',
            'adapter_type' => 'DIRECT_ENDPOINT',
            'contract_status' => 'DOCUMENTED',
            'evidence_status' => $status,
            'runtime_eligible' => $status === 'OFFICIAL_AND_SERVER_VERIFIED',
            'installed_server_canary' => $entry['canary'] ?? 'NOT_RUN_PANEL_CREDENTIALS_MISSING',
            'official_api_page' => $entry['page'],
            // The checked collection is an offline snapshot, not a live official Postman export.
            'official_postman_item' => null,
            'local_postman_item' => $entry['local_item'],
            'exact_http_method' => $entry['method'],
            'exact_path' => $entry['path'],
            'exact_path_casing' => $entry['path'],
            'request_root_keys' => $entry['request'],
            'response_root_keys' => $entry['response'],
            'source_documents' => $sources,
            'source_item_category' => $entry['item'],
            'evidence_hash' => $entry['evidence_hash'],
            'api_key_field' => $entry['api_key_field'] ?? 'ApiKey',
            'blocker' => $status === 'OFFICIAL_AND_SERVER_VERIFIED' ? null : 'Authenticated installed-server canary is pending Panel credentials.',
            'contract_version' => $entry['contract_version'] ?? null,
            'response_schema_fingerprint' => $entry['response_schema_fingerprint'] ?? null,
            'not_found_schema_fingerprint' => $entry['not_found_schema_fingerprint'] ?? null,
            'runtime_enabled' => (bool) ($entry['runtime_enabled'] ?? ($status === 'OFFICIAL_AND_SERVER_VERIFIED')),
        ]);
    }

    /** @return array<string, mixed> */
    private static function fixedQuery(string $operationKey): array
    {
        $evidenceHash = self::FIXED_QUERY_HASHES[$operationKey] ?? null;
        if (! is_string($evidenceHash)) {
            throw new LogicException('MIKRO_FIXED_QUERY_EVIDENCE_MISSING');
        }
        $definition = (new MikroFixedQueryCatalog)->definition($operationKey);
        $sources = [[
            'type' => 'official_current',
            'uri' => self::SQL_PAGE,
            'sha256' => self::SQL_PAGE_HASH,
        ], self::localPostmanSource()];
        foreach ($definition['table_evidence'] as $table => $source) {
            $sources[] = ['type' => 'fly_v17_table', 'table' => $table, ...$source];
        }
        if ($operationKey === 'stock.search') {
            $sources[] = [
                'type' => 'installed_server_authenticated_canary',
                'uri' => 'evidence://MikroPartNameMultiLine/20260814T144119Z/stock-search-canary-evidence.md',
                'sha256' => $evidenceHash,
            ];

            return self::descriptor([
                'operation_key' => $operationKey,
                'mode' => 'READ',
                'adapter_type' => 'FIXED_QUERY',
                'contract_status' => 'DOCUMENTED',
                'evidence_status' => 'OFFICIAL_AND_SERVER_VERIFIED',
                'runtime_eligible' => true,
                'runtime_enabled' => false,
                'installed_server_canary' => 'PASS_3_BOUNDED_HTTP_200_TYPED_2026_08_14',
                'official_api_page' => self::SQL_PAGE,
                'official_postman_item' => null,
                'local_postman_item' => 'Listeler / SqlVeriOkuV2',
                'exact_http_method' => 'POST',
                'exact_path' => '/Api/apiMethods/SqlVeriOkuV2',
                'exact_path_casing' => 'SERVER_VERIFIED=/Api/apiMethods/SqlVeriOkuV2',
                'request_root_keys' => ['Mikro', 'Mikro.ApiKey', 'Mikro.CalismaYili', 'Mikro.FirmaKodu', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'SQLSorgu'],
                'response_root_keys' => ['result', 'result[].Data[].SQLResult1', 'result[].IsError', 'result[].StatusCode'],
                'source_documents' => $sources,
                'source_item_category' => 'SqlVeriOkuV2 / technical_service_part_search_v1',
                'evidence_hash' => $evidenceHash,
                'api_key_field' => 'ApiKey',
                'blocker' => null,
                'contract_version' => MikroResponseSchemaCatalog::STOCK_SEARCH_CONTRACT_VERSION,
                'response_schema_fingerprint' => MikroResponseSchemaCatalog::STOCK_SEARCH_RESPONSE_SCHEMA_FINGERPRINT,
            ]);
        }
        if ($operationKey === 'stock.physical_quantity') {
            $depotSourceFile = 'database/seeders/PanelKnownWorkflowDataSourcesSeeder.php';
            $depotMethod = 'stock_warehouse';
            $sources[] = [
                'type' => 'depot_implementation',
                'uri' => $depotSourceFile,
                'source_id' => $depotMethod,
                'sha256' => '245e65f2a3f3a0fd4f7664559e225609b79869944f5a38a09a7ecdf18d8fdfb1',
            ];
            $sources[] = [
                'type' => 'installed_server_authenticated_canary',
                'uri' => 'evidence://MikroAccessoryPhysicalStock/20260814T172833Z/physical-stock-authenticated-canary.json',
                'sha256' => $evidenceHash,
            ];

            return self::descriptor([
                'operation_key' => $operationKey,
                'mode' => 'READ',
                'adapter_type' => 'FIXED_QUERY',
                'contract_status' => 'DOCUMENTED',
                'evidence_status' => 'OFFICIAL_AND_SERVER_VERIFIED',
                'runtime_eligible' => true,
                'runtime_enabled' => false,
                'installed_server_canary' => 'PASS_AUTHENTICATED_TYPED_PHYSICAL_STOCK_2026_08_14',
                'official_api_page' => self::SQL_PAGE,
                'official_postman_item' => null,
                'local_postman_item' => 'Listeler / SqlVeriOkuV2',
                'exact_http_method' => 'POST',
                'exact_path' => '/Api/apiMethods/SqlVeriOkuV2',
                'exact_path_casing' => 'SERVER_VERIFIED=/Api/apiMethods/SqlVeriOkuV2',
                'request_root_keys' => ['Mikro', 'Mikro.ApiKey', 'Mikro.CalismaYili', 'Mikro.FirmaKodu', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'SQLSorgu'],
                'response_root_keys' => ['result', 'result[].Data[].SQLResult1', 'result[].IsError', 'result[].StatusCode'],
                'source_documents' => $sources,
                'source_item_category' => 'SqlVeriOkuV2 / technical_service_part_physical_stock_v1',
                'evidence_hash' => $evidenceHash,
                'api_key_field' => 'ApiKey',
                'blocker' => null,
                'depot_source_file' => $depotSourceFile,
                'depot_method' => $depotMethod,
                'contract_version' => MikroResponseSchemaCatalog::PHYSICAL_STOCK_CONTRACT_VERSION,
                'response_schema_fingerprint' => MikroResponseSchemaCatalog::PHYSICAL_STOCK_RESPONSE_SCHEMA_FINGERPRINT,
            ]);
        }
        $depotSourceFile = null;
        $depotMethod = null;
        if ($operationKey === 'stock.availability') {
            $depotSourceFile = 'database/seeders/PanelKnownWorkflowDataSourcesSeeder.php';
            $depotMethod = 'stock_warehouse';
            $sources[] = [
                'type' => 'depot_implementation',
                'uri' => $depotSourceFile,
                'source_id' => $depotMethod,
                'sha256' => self::FIXED_QUERY_HASHES[$operationKey],
            ];
        }

        return self::descriptor([
            'operation_key' => $operationKey,
            'mode' => 'READ',
            'adapter_type' => 'FIXED_QUERY',
            'contract_status' => 'DOCUMENTED',
            'evidence_status' => 'DOCUMENTED_SERVER_UNVERIFIED',
            'runtime_eligible' => false,
            'installed_server_canary' => 'NOT_RUN_PANEL_CREDENTIALS_MISSING',
            'official_api_page' => self::SQL_PAGE,
            'official_postman_item' => null,
            'local_postman_item' => 'Listeler / SqlVeriOkuV2',
            'exact_http_method' => 'POST',
            'exact_path' => '/Api/apiMethods/SqlVeriOkuV2',
            'exact_path_casing' => 'OFFICIAL=/Api/apiMethods/SqlVeriOkuV2;LOCAL=/Api/APIMethods/SqlVeriOkuV2;SERVER_CANARY_PENDING',
            'request_root_keys' => ['Mikro', 'Mikro.ApiKey', 'Mikro.CalismaYili', 'Mikro.FirmaKodu', 'Mikro.KullaniciKodu', 'Mikro.Sifre', 'SQLSorgu'],
            'response_root_keys' => ['UNSPECIFIED_IN_SOURCE'],
            'source_documents' => $sources,
            'source_item_category' => $operationKey === 'stock.availability'
                ? 'SqlVeriOkuV2 / depot stock_warehouse fixed query'
                : 'SqlVeriOkuV2 / fixed query '.$operationKey,
            'evidence_hash' => $evidenceHash,
            'api_key_field' => 'ApiKey',
            'blocker' => 'SqlVeriOkuV2 authenticated installed-server canary and exact path-casing verification are pending Panel credentials.',
            'depot_source_file' => $depotSourceFile,
            'depot_method' => $depotMethod,
        ]);
    }

    /** @return array<string, mixed> */
    private static function write(string $operationKey): array
    {
        $entry = self::WRITES[$operationKey];

        return self::descriptor([
            'operation_key' => $operationKey,
            'mode' => 'WRITE',
            'adapter_type' => 'CONTRACT_BLOCKED',
            'contract_status' => 'CONTRACT_BLOCKED',
            'evidence_status' => 'CONTRACT_BLOCKED',
            'runtime_eligible' => false,
            'installed_server_canary' => 'NOT_RUN_CONTRACT_BLOCKED',
            'official_api_page' => null,
            'official_postman_item' => null,
            'local_postman_item' => $entry['item'],
            'exact_http_method' => null,
            'exact_path' => null,
            'exact_path_casing' => null,
            'request_root_keys' => [],
            'response_root_keys' => [],
            'source_documents' => [self::localPostmanSource()],
            'source_item_category' => 'LOCAL_SNAPSHOT_CROSSCHECK / '.$entry['item'],
            'evidence_hash' => $entry['evidence_hash'],
            'api_key_field' => null,
            'blocker' => 'OFFICIAL_OR_DEPOT_CONTRACT_NOT_VERIFIED',
        ]);
    }

    /** @return array<string, mixed> */
    private static function blocked(string $operationKey, string $mode): array
    {
        $entry = self::BLOCKED[$operationKey];

        return self::descriptor([
            'operation_key' => $operationKey,
            'mode' => $mode,
            'adapter_type' => 'CONTRACT_BLOCKED',
            'contract_status' => 'CONTRACT_BLOCKED',
            'evidence_status' => 'CONTRACT_BLOCKED',
            'runtime_eligible' => false,
            'installed_server_canary' => 'NOT_RUN_CONTRACT_BLOCKED',
            'official_api_page' => null,
            'official_postman_item' => null,
            'local_postman_item' => null,
            'exact_http_method' => null,
            'exact_path' => null,
            'exact_path_casing' => null,
            'request_root_keys' => [],
            'response_root_keys' => ['UNSPECIFIED_IN_SOURCE'],
            'source_documents' => [],
            'source_item_category' => null,
            'evidence_hash' => $entry['evidence_hash'],
            'api_key_field' => null,
            'blocker' => $entry['blocker'],
            'mode' => $mode,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function descriptor(array $values): array
    {
        $sources = $values['source_documents'];
        $tableEvidence = array_values(array_filter(
            $sources,
            static fn (array $source): bool => ($source['type'] ?? null) === 'fly_v17_table',
        ));

        return [
            ...$values,
            'runtime_enabled' => (bool) ($values['runtime_enabled'] ?? $values['runtime_eligible']),
            'source_document' => $sources[0]['uri'] ?? null,
            'official_doc_reference' => $values['official_api_page'],
            'official_method' => $values['exact_http_method'],
            'request_schema' => $values['request_root_keys'],
            'response_schema' => $values['response_root_keys'],
            'depot_evidence' => $values['depot_source_file'] ?? null,
            'v17_table_evidence' => $tableEvidence,
            'business_parity_source' => self::businessParity(
                (string) $values['operation_key'],
                (string) $values['mode'],
                (string) $values['adapter_type'],
                (string) $values['contract_status'],
            ),
            'official_changelog_reference' => null,
            'depot_source_file' => $values['depot_source_file'] ?? null,
            'depot_method' => $values['depot_method'] ?? null,
            'contract_version' => $values['contract_version'] ?? null,
            'response_schema_fingerprint' => $values['response_schema_fingerprint'] ?? null,
            'not_found_schema_fingerprint' => $values['not_found_schema_fingerprint'] ?? null,
        ];
    }

    /** @return array{status:string,source_type:?string,source_id:?string,evidence_reference:?string,blocker:?string} */
    private static function businessParity(string $operationKey, string $mode, string $adapterType, string $contractStatus): array
    {
        if ($mode === 'WRITE') {
            return [
                'status' => 'WRITE_REQUIRES_READBACK_CONTRACT',
                'source_type' => null,
                'source_id' => null,
                'evidence_reference' => null,
                'blocker' => 'READBACK_CONTRACT_NOT_VERIFIED',
            ];
        }

        if ($operationKey === 'health.check') {
            return [
                'status' => 'NOT_APPLICABLE_SYSTEM',
                'source_type' => null,
                'source_id' => null,
                'evidence_reference' => null,
                'blocker' => null,
            ];
        }

        if ($contractStatus === 'CONTRACT_BLOCKED') {
            return [
                'status' => 'CONTRACT_BLOCKED',
                'source_type' => null,
                'source_id' => null,
                'evidence_reference' => null,
                'blocker' => 'PARITY_SOURCE_UNAVAILABLE_FOR_BLOCKED_CONTRACT',
            ];
        }

        if ($adapterType === 'FIXED_QUERY') {
            if (in_array($operationKey, ['stock.availability', 'stock.physical_quantity'], true)) {
                return [
                    'status' => 'VERIFIED_SOURCE',
                    'source_type' => 'DEPOT_FIXED_QUERY_AND_V17_TABLE_CONTRACT',
                    'source_id' => 'stock_warehouse',
                    'evidence_reference' => self::FIXED_QUERY_HASHES[$operationKey],
                    'blocker' => null,
                ];
            }

            return [
                'status' => 'VERIFIED_SOURCE',
                'source_type' => 'FIXED_QUERY_AND_V17_TABLE_CONTRACT',
                'source_id' => $operationKey,
                'evidence_reference' => self::FIXED_QUERY_HASHES[$operationKey],
                'blocker' => null,
            ];
        }

        return [
            'status' => 'PENDING_SOURCE',
            'source_type' => null,
            'source_id' => null,
            'evidence_reference' => null,
            'blocker' => 'PARITY_SOURCE_MISSING',
        ];
    }

    /** @return array{type:string,uri:string,sha256:string} */
    private static function localPostmanSource(): array
    {
        return [
            'type' => 'local_postman_snapshot',
            'uri' => self::LOCAL_POSTMAN,
            'sha256' => self::LOCAL_POSTMAN_HASH,
        ];
    }
}
