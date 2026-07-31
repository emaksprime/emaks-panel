<?php

namespace Tests\Unit\Mikro;

use App\Services\Mikro\MikroFixedQueryCatalog;
use App\Services\Mikro\MikroParityContract;
use App\Services\Mikro\MikroParitySource;
use DomainException;
use PHPUnit\Framework\TestCase;

class MikroParityContractTest extends TestCase
{
    private MikroFixedQueryCatalog $queries;

    private MikroParityContract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queries = new MikroFixedQueryCatalog;
        $this->contract = new MikroParityContract($this->queries);
    }

    public function test_parity_sources_are_dedicated_and_do_not_modify_dashboard_sources(): void
    {
        $runtimeQueries = array_slice($this->queries->queryIds(), 0, 21);

        foreach (MikroParitySource::cases() as $source) {
            $definition = $this->contract->source($source);
            $this->assertTrue(str_starts_with($source->value, 'parity_'));
            $this->assertTrue(str_starts_with($source->queryId(), 'parity.'));
            $this->assertNotContains($source->queryId(), $runtimeQueries);
            $this->assertSame(1, $definition['schema_version']);
        }
    }

    public function test_parity_query_templates_are_server_owned_and_read_only(): void
    {
        foreach (MikroParitySource::cases() as $source) {
            $definition = $this->contract->source($source);
            $sql = $definition['query_template'];

            $this->assertMatchesRegularExpression('/^(SELECT|WITH)\b/i', ltrim($sql));
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|TRUNCATE|EXEC(?:UTE)?)\b/i', $sql);
            $this->assertSame(hash('sha256', $sql), $definition['query_template_sha256']);
        }
    }

    public function test_parity_allowed_params_are_explicit(): void
    {
        $parameters = [
            'item_code' => 'STOK-001',
            'warehouse_code' => 5,
            'as_of_date' => '2026-07-31',
        ];

        $this->assertSame($parameters, $this->contract->validatedParameters(MikroParitySource::STOCK_DETAIL, $parameters));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('MIKRO_QUERY_PARAMETER_INVALID');
        $this->contract->validatedParameters(MikroParitySource::STOCK_DETAIL, [...$parameters, 'sql' => 'SELECT 1']);
    }

    public function test_contract_fingerprint_is_deterministic_and_changes_with_contract_content(): void
    {
        $first = $this->contract->fingerprint();
        $second = (new MikroParityContract(new MikroFixedQueryCatalog))->fingerprint();
        $changed = $this->contract->contract();
        $changed['normalization_version'] = 'changed';

        $this->assertSame($first, $second);
        $this->assertNotSame($first, hash('sha256', $this->contract->canonicalJson($changed)));
        $this->assertSame('mikro-shadow-parity-normalization.v1', MikroParityContract::NORMALIZATION_VERSION);
        $this->assertSame('mikro-shadow-parity-operations.v1', MikroParityContract::OPERATION_CONTRACT_VERSION);
    }

    public function test_customer_contract_classifies_missing_fields_without_defaults(): void
    {
        $result = $this->contract->normalizeN8n(MikroParitySource::CUSTOMER_DETAIL, [[
            'record_id' => '123e4567-e89b-42d3-a456-426614174000',
            'customer_code' => '120.01',
        ]]);

        $this->assertSame('CONTRACT_ERROR', $result['status']);
        $this->assertContains('title_1', $result['missing_source_fields']);
        $this->assertSame([], $result['envelope']);
        $this->assertArrayNotHasKey('currency_code', $result['envelope']);
    }

    public function test_stock_on_hand_is_not_mislabelled_as_available_and_identity_includes_warehouse(): void
    {
        $source = $this->contract->source(MikroParitySource::STOCK_DETAIL);
        $operation = $this->contract->operation('stock.availability');

        $this->assertStringContainsString('AS on_hand_quantity', $source['query_template']);
        $this->assertStringNotContainsString('AS available_quantity', $source['query_template']);
        $this->assertSame(['item_code', 'warehouse_code'], $operation['identity_key']);
        $this->assertArrayHasKey('reserved_quantity', $operation['unavailable_fields']);
        $this->assertArrayHasKey('available_quantity', $operation['unavailable_fields']);
    }

    public function test_detail_mappers_reject_missing_selected_aliases_instead_of_defaulting_them(): void
    {
        $stock = $this->contract->normalizeN8n(MikroParitySource::STOCK_DETAIL, [[
            'record_id' => '123e4567-e89b-42d3-a456-426614174000',
            'item_code' => 'STOK-001',
            'warehouse_code' => 5,
            'on_hand_quantity' => '4.000000',
            'serial_tracking_code' => 3,
            'item_active_flag' => 1,
            'source_updated_at' => '2026-07-31T10:00:00',
        ]]);
        $serial = $this->contract->normalizeN8n(MikroParitySource::SERIAL_DETAIL, [[
            'record_id' => '123e4567-e89b-42d3-a456-426614174000',
            'serial_number' => 'SERIAL-001',
            'item_code' => 'STOK-001',
            'source_updated_at' => '2026-07-31T10:00:00',
        ]]);
        $order = $this->contract->normalizeN8n(MikroParitySource::ORDER_DETAIL, [[
            'line_guid' => '123e4567-e89b-42d3-a456-426614174000',
            'document_identity' => '0|0|SIP|1',
            'document_series' => 'SIP',
            'document_number' => 1,
            'line_number' => 0,
            'customer_code' => '120.01',
            'order_date' => '2026-07-31',
            'warehouse_code' => 5,
            'item_code' => 'STOK-001',
            'ordered_quantity' => '1.000000',
            'delivered_quantity' => '0.000000',
            'open_quantity' => '1.000000',
            'unit_price' => '10.000000',
            'line_net_amount' => '10.000000',
            'line_tax_amount' => '2.000000',
            'cancelled_flag' => 0,
            'closed_flag' => 0,
            'raw_order_state' => 0,
            'source_updated_at' => '2026-07-31T10:00:00',
        ]]);

        $this->assertSame('CONTRACT_ERROR', $stock['status']);
        $this->assertSame(['unit_name'], $stock['missing_source_fields']);
        $this->assertSame('CONTRACT_ERROR', $serial['status']);
        $this->assertContains('movement_timestamp', $serial['missing_source_fields']);
        $this->assertSame('CONTRACT_ERROR', $order['status']);
        $this->assertSame(['requested_delivery_date'], $order['missing_source_fields']);
    }

    public function test_serial_discovery_returns_stable_lookup_inputs(): void
    {
        $result = $this->contract->normalizeN8n(MikroParitySource::SERIAL_DISCOVERY, [[
            'record_id' => '123e4567-e89b-42d3-a456-426614174000',
            'serial_number' => 'SERIAL-001',
            'item_code' => 'STOK-001',
            'source_updated_at' => '2026-07-31T10:00:00',
        ]]);

        $this->assertSame('READY', $result['status']);
        $this->assertSame('SERIAL-001|STOK-001', $result['envelope']['samples'][0]['identity']);
        $this->assertSame(['serial_number' => 'SERIAL-001', 'item_code' => 'STOK-001'], $result['envelope']['samples'][0]['lookup']);
    }

    public function test_order_discovery_uses_an_authoritative_anchor_line_guid_and_order_number_cannot_replace_it(): void
    {
        $source = $this->contract->source(MikroParitySource::ORDER_DISCOVERY);
        $this->assertStringContainsString('anchor_line_guid', $source['query_template']);
        $this->assertStringContainsString('document_identity', $source['query_template']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('MIKRO_QUERY_PARAMETER_INVALID');
        $this->contract->validatedParameters(MikroParitySource::ORDER_DETAIL, ['order_anchor_line_guid' => 'SIP-2026-42']);
    }

    public function test_one_blocked_operation_does_not_hide_other_operation_readiness(): void
    {
        $customer = $this->contract->operationReadiness('customer.lookup');
        $stock = $this->contract->operationReadiness('stock.availability');

        $this->assertSame('READY', $customer['source_contract']);
        $this->assertSame('READY', $stock['source_contract']);
        $this->assertSame('CONTRACT_FIELD_UNAVAILABLE', $customer['parity_readiness']);
        $this->assertSame('CONTRACT_FIELD_UNAVAILABLE', $stock['parity_readiness']);
        $this->assertArrayHasKey('currency_code', $customer['unavailable_promotion_fields']);
        $this->assertArrayHasKey('reserved_quantity', $stock['unavailable_promotion_fields']);
    }

    public function test_contract_contains_no_secret_or_raw_pii_field(): void
    {
        $json = $this->contract->canonicalJson($this->contract->contract());

        foreach (['api_key', 'password', 'authorization', 'tax_number', 'phone_number', 'street_address'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($json));
        }
    }
}
