<?php

namespace Tests\Unit\Mikro;

use App\Services\Mikro\MikroFixedQueryCatalog;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MikroFixedQueryCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_preserves_the_twenty_one_runtime_queries_and_adds_eight_isolated_parity_queries(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);

        $this->assertCount(29, $catalog->queryIds());
        $this->assertSame([
            'customer.detail', 'customer.balance', 'customer.document.timeline',
            'stock.availability', 'stock.movement.list', 'serial.lookup', 'serial.history',
            'order.list', 'order.detail', 'order.lines', 'order.remaining.quantity',
            'invoice.list', 'invoice.detail', 'invoice.lines',
            'dispatch.list', 'dispatch.detail', 'dispatch.lines',
            'return.list', 'return.detail', 'exchange.status', 'replacement.serial.lookup',
        ], array_slice($catalog->queryIds(), 0, 21));
        $this->assertSame([
            'parity.customer.discovery.v1', 'parity.customer.detail.v1',
            'parity.stock.discovery.v1', 'parity.stock.detail.v1',
            'parity.serial.discovery.v1', 'parity.serial.detail.v1',
            'parity.order.discovery.v1', 'parity.order.detail.v1',
        ], array_slice($catalog->queryIds(), 21));

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

        $customer = $catalog->n8nTemplate('parity.customer.detail.v1');
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
            'parity.customer.discovery.v1' => ['limit' => 50],
            'parity.customer.detail.v1' => ['customer_code' => '120.01-TEST'],
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
