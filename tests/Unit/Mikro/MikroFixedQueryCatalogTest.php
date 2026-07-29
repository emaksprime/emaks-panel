<?php

namespace Tests\Unit\Mikro;

use App\Services\Mikro\MikroFixedQueryCatalog;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MikroFixedQueryCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_contains_only_the_twenty_one_immutable_read_queries(): void
    {
        $catalog = app(MikroFixedQueryCatalog::class);

        $this->assertCount(21, $catalog->queryIds());
        $this->assertSame([
            'customer.detail', 'customer.balance', 'customer.document.timeline',
            'stock.availability', 'stock.movement.list', 'serial.lookup', 'serial.history',
            'order.list', 'order.detail', 'order.lines', 'order.remaining.quantity',
            'invoice.list', 'invoice.detail', 'invoice.lines',
            'dispatch.list', 'dispatch.detail', 'dispatch.lines',
            'return.list', 'return.detail', 'exchange.status', 'replacement.serial.lookup',
        ], $catalog->queryIds());

        foreach ($catalog->queryIds() as $queryId) {
            $definition = $catalog->definition($queryId);
            $this->assertMatchesRegularExpression('/^SELECT\b/i', ltrim($definition['sql']));
            $this->assertStringNotContainsString(';', $definition['sql']);
            $this->assertDoesNotMatchRegularExpression('/--|\/\*|\b(INSERT|UPDATE|DELETE|MERGE|EXEC)\b/i', $definition['sql']);
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
}
