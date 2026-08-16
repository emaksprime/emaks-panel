<?php

namespace Tests\Unit\Mikro;

use App\Services\Mikro\MikroFixedQueryCatalog;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MikroFixedQueryCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_preserves_runtime_queries_and_adds_bounded_stock_search(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);

        $this->assertCount(32, $catalog->queryIds());
        $this->assertSame([
            'customer.detail', 'customer.balance', 'customer.document.timeline',
            'stock.availability', 'stock.search', 'stock.physical_quantity', 'stock.tax_profile', 'stock.movement.list', 'serial.lookup', 'serial.history',
            'order.list', 'order.detail', 'order.lines', 'order.remaining.quantity',
            'invoice.list', 'invoice.detail', 'invoice.lines',
            'dispatch.list', 'dispatch.detail', 'dispatch.lines',
            'return.list', 'return.detail', 'exchange.status', 'replacement.serial.lookup',
        ], array_slice($catalog->queryIds(), 0, 24));
        $this->assertSame([
            'parity.customer.discovery.v2', 'parity.customer.detail.v2',
            'parity.stock.discovery.v1', 'parity.stock.detail.v1',
            'parity.serial.discovery.v1', 'parity.serial.detail.v1',
            'parity.order.discovery.v1', 'parity.order.detail.v1',
        ], array_slice($catalog->queryIds(), 24));

        foreach ($catalog->queryIds() as $queryId) {
            $definition = $catalog->definition($queryId);
            $this->assertMatchesRegularExpression('/^(SELECT|WITH)\b/i', ltrim($definition['sql']));
            $this->assertStringNotContainsString(';', $definition['sql']);
            $this->assertDoesNotMatchRegularExpression('/--|\/\*|\b(INSERT|UPDATE|DELETE|MERGE|EXEC)\b/i', $definition['sql']);
            $this->assertNotEmpty($definition['table_evidence']);
            foreach ($definition['table_evidence'] as $evidence) {
                $this->assertMatchesRegularExpression('/^https:\/\/www\.mikroelterminali\.com\/databasehelp17\//', $evidence['uri']);
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $evidence['sha256']);
            }
        }
    }

    public function test_stock_search_is_immutable_bounded_and_turkish_normalized(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);
        $definition = $catalog->definition('stock.search');
        $sql = $catalog->render('stock.search', ['search' => 'DIŞ DOKUMATİK']);
        $normalizedSql = $catalog->render('stock.search', ['search' => 'DIS DOKUMATIK']);

        $this->assertSame('technical_service_part_search_v1', $definition['contract_id']);
        $this->assertSame(['STOKLAR'], $definition['tables']);
        $this->assertStringContainsString('SELECT TOP (20)', $sql);
        $this->assertStringContainsString('sto.sto_kod', $sql);
        $this->assertStringContainsString('sto.sto_isim', $sql);
        $this->assertStringContainsString('sto.sto_kisa_ismi', $sql);
        $this->assertStringContainsString('sto.sto_cins', $sql);
        $this->assertStringContainsString('sto.sto_detay_takip', $sql);
        $this->assertStringContainsString('ISNULL(sto.sto_iptal, 0) = 0', $sql);
        $this->assertStringContainsString('ISNULL(sto.sto_hidden, 0) = 0', $sql);
        $this->assertStringContainsString("N'DIS DOKUMATIK'", $sql);
        $this->assertSame($sql, $normalizedSql);
        $this->assertStringNotContainsString('[[', $sql);
    }

    public function test_stock_search_rejects_query_fragments_and_extra_parameters(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);

        foreach ([
            ['', ['search' => 'A']],
            ['', ['search' => "PARCA'; DROP TABLE STOKLAR"]],
            ['', ['search' => 'PARCA -- test']],
            ['', ['search' => 'PARCA /* test */']],
            ['', ['search' => 'PARCA', 'sql' => 'SELECT * FROM STOKLAR']],
        ] as [, $parameters]) {
            try {
                $catalog->render('stock.search', $parameters);
                $this->fail('Unsafe stock search input must be rejected.');
            } catch (DomainException $exception) {
                $this->assertSame('MIKRO_QUERY_PARAMETER_INVALID', $exception->getMessage());
            }
        }

        try {
            $catalog->n8nTemplate('stock.search');
            $this->fail('Technical-service stock search must not gain an n8n template path.');
        } catch (DomainException $exception) {
            $this->assertSame('MIKRO_QUERY_PARAMETER_INVALID', $exception->getMessage());
        }
    }

    public function test_physical_stock_query_is_fixed_bounded_and_uses_only_warehouses_one_and_five(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);
        $definition = $catalog->definition('stock.physical_quantity');
        $sql = $catalog->render('stock.physical_quantity', [
            'item_codes' => ['TKN000009', 'EE.BCK.STD.0010'],
        ]);

        $this->assertSame('technical_service_part_physical_stock_v1', $definition['contract_id']);
        $this->assertSame([1, 5], $definition['warehouse_context']);
        $this->assertStringContainsString('dbo.fn_DepodakiMiktar(sto.sto_kod, 1, GETDATE())', $sql);
        $this->assertStringContainsString('dbo.fn_DepodakiMiktar(sto.sto_kod, 5, GETDATE())', $sql);
        $this->assertStringContainsString("IN (N'EE.BCK.STD.0010', N'TKN000009')", $sql);
        $this->assertStringNotContainsString('reserved', strtolower($sql));
        $this->assertStringNotContainsString('available', strtolower($sql));
        $this->assertStringNotContainsString('[[', $sql);

        try {
            $catalog->render('stock.physical_quantity', ['item_codes' => array_fill(0, 21, 'OVER-LIMIT')]);
            $this->fail('Physical-stock batch limit must fail closed.');
        } catch (DomainException $exception) {
            $this->assertSame('MIKRO_QUERY_PARAMETER_INVALID', $exception->getMessage());
        }

        try {
            $catalog->n8nTemplate('stock.physical_quantity');
            $this->fail('Physical stock must not gain an n8n path.');
        } catch (DomainException $exception) {
            $this->assertSame('MIKRO_QUERY_PARAMETER_INVALID', $exception->getMessage());
        }
    }

    public function test_stock_tax_profile_query_is_immutable_bounded_and_keeps_pointer_resolution_separate(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);
        $definition = $catalog->definition('stock.tax_profile');
        $sql = $catalog->render('stock.tax_profile', [
            'item_codes' => ['TKN000009', 'EE.BCK.STD.0010'],
        ]);

        $this->assertSame('technical_service_part_tax_profile_v1', $definition['contract_id']);
        $this->assertSame(['STOKLAR'], $definition['tables']);
        $this->assertSame('/Api/APIMethods/VergiListesiV2', $definition['rate_endpoint']);
        $this->assertStringContainsString('sto.sto_perakende_vergi', $sql);
        $this->assertStringContainsString('sto.sto_toptan_vergi', $sql);
        $this->assertStringContainsString('AS retail_tax_pointer', $sql);
        $this->assertStringContainsString('AS wholesale_tax_pointer', $sql);
        $this->assertStringContainsString("IN (N'EE.BCK.STD.0010', N'TKN000009')", $sql);
        $this->assertStringNotContainsString('VERGI_FON_TANIMLARI', $sql);
        $this->assertStringNotContainsString('tax_rate', $sql);
        $this->assertStringNotContainsString('[[', $sql);

        try {
            $catalog->render('stock.tax_profile', ['item_codes' => array_fill(0, 21, 'OVER-LIMIT')]);
            $this->fail('Tax-profile batch limit must fail closed.');
        } catch (DomainException $exception) {
            $this->assertSame('MIKRO_QUERY_PARAMETER_INVALID', $exception->getMessage());
        }

        try {
            $catalog->n8nTemplate('stock.tax_profile');
            $this->fail('Tax profile must not gain an n8n path.');
        } catch (DomainException $exception) {
            $this->assertSame('MIKRO_QUERY_PARAMETER_INVALID', $exception->getMessage());
        }
    }

    public function test_renderer_accepts_only_typed_values_and_enforces_result_limit(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);
        $sql = $catalog->render('customer.document.timeline', [
            'customer_code' => '120.01-TEST',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'limit' => 25,
        ]);

        $this->assertStringContainsString('TOP (25)', $sql);
        $this->assertStringContainsString("N'120.01-TEST'", $sql);
        $this->assertStringContainsString("CONVERT(date, '2026-01-01')", $sql);
        $this->assertStringNotContainsString('[[', $sql);
    }

    public function test_n8n_template_keeps_validated_string_guid_and_date_values_inside_sql_literals(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);

        $customer = $catalog->n8nTemplate('parity.customer.detail.v2');
        $stock = $catalog->n8nTemplate('parity.stock.detail.v1');
        $orderDiscovery = $catalog->n8nTemplate('parity.order.discovery.v1');
        $orderDetail = $catalog->n8nTemplate('parity.order.detail.v1');

        $this->assertStringContainsString("N'[[customer_code]]'", $customer);
        $this->assertStringContainsString("N'[[item_code]]'", $stock);
        $this->assertStringContainsString('[[warehouse_code]] AS warehouse_code', $stock);
        $this->assertStringContainsString("CONVERT(date, '[[as_of_date]]')", $stock);
        $this->assertStringContainsString("CONVERT(date, '[[date_from]]')", $orderDiscovery);
        $this->assertStringContainsString("CONVERT(date, '[[date_to]]')", $orderDiscovery);
        $this->assertStringContainsString("CONVERT(uniqueidentifier, '[[order_anchor_line_guid]]')", $orderDetail);
    }

    public function test_n8n_raw_value_substitution_compiles_to_the_exact_mikro_sql_for_every_parity_query(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);
        $cases = [
            'parity.customer.discovery.v2' => ['limit' => 50],
            'parity.customer.detail.v2' => ['customer_code' => '120.01-TEST'],
            'parity.stock.discovery.v1' => ['limit' => 100, 'as_of_date' => '2026-07-31'],
            'parity.stock.detail.v1' => ['item_code' => 'STOK-001', 'warehouse_code' => 5, 'as_of_date' => '2026-07-31'],
            'parity.serial.discovery.v1' => ['limit' => 50],
            'parity.serial.detail.v1' => ['serial_number' => 'SERIAL-001', 'item_code' => 'STOK-001'],
            'parity.order.discovery.v1' => ['date_from' => '2025-01-01', 'date_to' => '2026-07-31', 'limit' => 50],
            'parity.order.detail.v1' => ['order_anchor_line_guid' => '123e4567-e89b-42d3-a456-426614174000'],
        ];

        foreach ($cases as $queryId => $parameters) {
            $n8nSql = $catalog->n8nTemplate($queryId);
            foreach ($parameters as $name => $value) {
                $n8nSql = str_replace('[['.$name.']]', (string) $value, $n8nSql);
            }

            $this->assertSame($catalog->render($queryId, $parameters), $n8nSql, $queryId);
            $this->assertStringNotContainsString('[[', $n8nSql, $queryId);
        }
    }

    public function test_renderer_rejects_sql_text_extra_parameters_invalid_types_and_injection(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);
        $invalid = [
            ['customer.detail', ['customer_code' => "A'; DROP TABLE X"]],
            ['serial.lookup', ['serial_number' => 'ABC -- test']],
            ['order.detail', ['order_guid' => 'not-a-guid']],
            ['order.list', ['date_from' => '2026-02-31', 'date_to' => '2026-03-01', 'limit' => 10]],
            ['order.list', ['date_from' => '2026-02-01', 'date_to' => '2026-03-01', 'limit' => 501]],
            ['customer.detail', ['customer_code' => 'TEST', 'sql' => 'SELECT * FROM USERS']],
        ];

        foreach ($invalid as [$queryId, $parameters]) {
            try {
                $catalog->render($queryId, $parameters);
                $this->fail("{$queryId} should reject unsafe parameters.");
            } catch (DomainException $exception) {
                $this->assertSame('MIKRO_QUERY_PARAMETER_INVALID', $exception->getMessage());
            }
        }
    }

    public function test_approved_v17_relationships_are_preserved(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);
        $guid = '123e4567-e89b-42d3-a456-426614174000';

        $orderSql = $catalog->render('order.lines', ['order_guid' => $guid, 'limit' => 10]);
        $serialSql = $catalog->render('serial.history', ['serial_number' => 'SERIAL-001', 'limit' => 10]);

        $this->assertStringContainsString('sth.sth_sip_uid = sip.sip_Guid', $orderSql);
        $this->assertStringContainsString('sth.sth_Guid = ch.ChHar_master_uid', $serialSql);
        $this->assertStringContainsString("CONVERT(uniqueidentifier, '{$guid}')", $orderSql);
    }

    public function test_customer_currency_query_uses_the_verified_view_and_fails_closed(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);
        $definition = $catalog->definition('parity.customer.detail.v2');
        $sql = $catalog->render('parity.customer.detail.v2', ['customer_code' => '120.01-TEST']);

        $this->assertSame(['CARI_HESAPLAR', 'KUR_ISIMLERI_VIEW'], $definition['tables']);
        $this->assertStringContainsString('currency.KUR_NUMARASI = cari.cari_doviz_cinsi', $sql);
        $this->assertStringContainsString("= N'TL' THEN N'TRY'", $sql);
        $this->assertStringContainsString('ELSE NULL END AS currency_code', $sql);
        $this->assertStringNotContainsString('dbo.KUR_ISIMLERI AS', $sql);
        $this->assertStringNotContainsString('currency.Kur_Tip', $sql);
    }

    public function test_stock_availability_uses_the_server_owned_stock_warehouse_query_contract(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);
        $definition = $catalog->definition('stock.availability');
        $sql = $catalog->render('stock.availability', ['stock_code' => 'STOK-001']);

        $this->assertSame([1, 5], $definition['warehouse_context']);
        $this->assertSame('database/seeders/PanelKnownWorkflowDataSourcesSeeder.php', $definition['depot_source_file']);
        $this->assertSame('stock_warehouse', $definition['depot_source_id']);
        $this->assertStringContainsString('dbo.fn_DepodakiMiktar(sto.sto_kod, 1, GETDATE())', $sql);
        $this->assertStringContainsString('dbo.fn_DepodakiMiktar(sto.sto_kod, 5, GETDATE())', $sql);
        $this->assertStringContainsString("LTRIM(RTRIM(sto.sto_kod)) = N'STOK-001'", $sql);
        $this->assertStringNotContainsString('[[', $sql);
    }

    public function test_warehouse_one_and_five_are_allowed_and_other_warehouse_is_rejected_before_network(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);
        foreach ([1, 5] as $warehouse) {
            $sql = $catalog->render('parity.stock.detail.v1', [
                'item_code' => 'STOK-001',
                'warehouse_code' => $warehouse,
                'as_of_date' => '2026-07-31',
            ]);
            $this->assertStringContainsString($warehouse.' AS warehouse_code', $sql);
        }

        foreach ([0, 2, 999] as $warehouse) {
            try {
                $catalog->render('parity.stock.detail.v1', [
                    'item_code' => 'STOK-001',
                    'warehouse_code' => $warehouse,
                    'as_of_date' => '2026-07-31',
                ]);
                $this->fail('Warehouse '.$warehouse.' must be rejected.');
            } catch (DomainException $exception) {
                $this->assertSame('MIKRO_QUERY_PARAMETER_INVALID', $exception->getMessage());
            }
        }
    }
}
