<?php

namespace Tests\Unit\Mikro;

use App\Services\Mikro\MikroContractEvidenceCatalog;
use App\Services\Mikro\MikroFixedQueryCatalog;
use App\Services\Mikro\MikroOperationRegistry;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MikroOperationRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_contains_every_required_read_and_write_capability(): void
    {
        $registry = app(MikroOperationRegistry::class);
        $summary = $registry->summary();

        $this->assertSame('active', $summary['status']);
        $this->assertSame(32, $summary['read_count']);
        $this->assertSame(29, $summary['implemented_read_count']);
        $this->assertSame(1, $summary['enabled_read_count']);
        $this->assertSame(11, $summary['write_count']);
        $this->assertSame(0, $summary['enabled_write_count']);
        $this->assertSame(9, $summary['direct_endpoint_count']);
        $this->assertSame(20, $summary['fixed_query_count']);
        $this->assertSame(14, $summary['contract_blocked_count']);
        $this->assertSame(1, $summary['server_verified_read_count']);
        $this->assertSame(28, $summary['server_unverified_count']);
        $this->assertSame(1, $summary['runtime_eligible_read_count']);
        $this->assertSame(21, $summary['response_schema_verified_count']);
        $this->assertSame(11, $summary['response_schema_missing_count']);
        $this->assertSame(20, $summary['parity_status_counts']['VERIFIED_SOURCE']);
        $this->assertSame(8, $summary['parity_status_counts']['PENDING_SOURCE']);
        $this->assertSame(1, $summary['parity_status_counts']['NOT_APPLICABLE_SYSTEM']);
        $this->assertSame(11, $summary['parity_status_counts']['WRITE_REQUIRES_READBACK_CONTRACT']);
        $this->assertSame(3, $summary['parity_status_counts']['CONTRACT_BLOCKED']);
        $this->assertTrue($summary['matrix_complete']);
        $this->assertSame(['health.check'], $summary['enabled_keys']);

        $keys = array_column($summary['operations'], 'operation_key');
        foreach ([
            'health.check', 'user.parameters', 'user.list',
            'customer.list', 'customer.detail', 'customer.balance', 'customer.document.timeline',
            'stock.list', 'stock.availability', 'stock.movement.list', 'serial.lookup', 'serial.history',
            'order.list', 'order.detail', 'order.lines', 'order.remaining.quantity',
            'invoice.list', 'invoice.detail', 'invoice.lines', 'invoice.pdf',
            'dispatch.list', 'dispatch.detail', 'dispatch.lines', 'dispatch.pdf',
            'edocument.status', 'etaxpayer.check', 'return.list', 'return.detail',
            'exchange.status', 'replacement.serial.lookup', 'proforma.list', 'proforma.detail',
        ] as $key) {
            $this->assertContains($key, $keys);
        }
        foreach ([
            'customer.save', 'order.save', 'invoice.create', 'dispatch.create',
            'record.link.save', 'record.bulk.save', 'stock.transfer.create',
            'order.dispatch.legacy.create', 'proforma.create', 'return.create', 'exchange.create',
        ] as $key) {
            $this->assertContains($key, $keys);
            $write = $registry->writeCapability($key);
            $this->assertSame('CONTRACT_BLOCKED', $write['contract_status']);
            $this->assertFalse($write['runtime_enabled']);
        }
    }

    public function test_post_reads_are_classified_by_registry_not_http_method(): void
    {
        $registry = app(MikroOperationRegistry::class);

        foreach (['customer.list', 'stock.list', 'invoice.pdf', 'customer.detail'] as $key) {
            $operation = $registry->read($key);
            $this->assertSame('READ', $operation['mode']);
            $this->assertSame('POST', $operation['method']);
        }

        $this->assertSame('/Api/apiMethods/SqlVeriOkuV2', $registry->read('order.list')['endpoint']);
        $this->assertSame('order.list', $registry->read('order.list')['fixed_query_id']);
        $this->assertSame('DOCUMENTED_SERVER_UNVERIFIED', $registry->read('customer.list')['evidence_status']);
        $this->assertFalse($registry->read('customer.list')['runtime_eligible']);

        $queries = app(MikroFixedQueryCatalog::class);
        $definition = $queries->definition('invoice.list');
        $invoiceSql = $queries->render('invoice.list', [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'limit' => 50,
        ]);
        $this->assertSame('ALL_INVOICES', $definition['business_scope']);
        $this->assertStringContainsString('cha.cha_cinsi IN (6, 7, 13)', $invoiceSql);
        $this->assertStringNotContainsString('cha.cha_evrak_tip IN (6, 7, 13)', $invoiceSql);
        $this->assertStringNotContainsString('cha.cha_evrak_tip =', $invoiceSql);
        $this->assertStringNotContainsString('cha.cha_normal_Iade', $invoiceSql);
        $this->assertStringContainsString('TOP (50)', $invoiceSql);
        $this->assertMatchesRegularExpression('/^SELECT\b/i', ltrim($invoiceSql));
    }

    public function test_contract_blocked_unknown_and_generic_operations_fail_closed(): void
    {
        $registry = app(MikroOperationRegistry::class);

        foreach (['generic.call', 'sql.read', 'sql.write', 'raw.sql', 'table.read'] as $operation) {
            try {
                $registry->operation($operation);
                $this->fail("{$operation} should be denied.");
            } catch (DomainException $exception) {
                $this->assertSame(MikroOperationRegistry::BLOCKED_DENIED, $exception->getMessage());
            }
        }

        foreach (['stock.availability', 'proforma.list', 'proforma.detail'] as $operation) {
            try {
                $registry->read($operation);
                $this->fail("{$operation} should be contract-blocked.");
            } catch (DomainException $exception) {
                $this->assertSame(MikroOperationRegistry::BLOCKED_DISABLED, $exception->getMessage());
            }
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(MikroOperationRegistry::BLOCKED_UNKNOWN);
        $registry->operation('invented.operation');
    }

    public function test_master_and_per_operation_read_gates_are_fail_closed(): void
    {
        $registry = app(MikroOperationRegistry::class);

        foreach ([
            ['enabled' => false, 'read_sync_enabled' => true],
            ['enabled' => true, 'read_sync_enabled' => false],
            ['enabled' => true, 'read_sync_enabled' => true, 'operation_controls' => ['customer.list' => ['runtime_enabled' => false]]],
        ] as $settings) {
            try {
                $registry->assertReadAllowed('customer.list', $settings);
                $this->fail('Read gate should reject the operation.');
            } catch (DomainException $exception) {
                $this->assertContains($exception->getMessage(), ['MIKRO_DISABLED', 'MIKRO_READ_SYNC_DISABLED', MikroOperationRegistry::BLOCKED_RESPONSE_SCHEMA]);
            }
        }

        try {
            $registry->assertReadAllowed('order.lines', ['enabled' => true, 'read_sync_enabled' => true]);
            $this->fail('n8n source mode must not open the Mikro client.');
        } catch (DomainException $exception) {
            $this->assertSame(MikroOperationRegistry::BLOCKED_SERVER_CANARY, $exception->getMessage());
        }
    }

    public function test_write_gate_requires_master_operation_approval_and_idempotency(): void
    {
        $registry = app(MikroOperationRegistry::class);
        $settings = [
            'enabled' => true,
            'write_enabled' => true,
            'operation_controls' => ['customer.save' => ['runtime_enabled' => true]],
        ];

        foreach ([[false, 'op-1'], [true, null], [true, 'op-1']] as [$approved, $idempotency]) {
            try {
                $registry->assertWriteAllowed('customer.save', $settings, $approved, $idempotency);
                $this->fail('Write gate must remain physically disabled.');
            } catch (DomainException $exception) {
                $this->assertSame('MIKRO_WRITE_DISABLED', $exception->getMessage());
            }
        }
    }

    public function test_every_operation_carries_deterministic_authority_fields(): void
    {
        $registry = app(MikroOperationRegistry::class);
        $queries = app(MikroFixedQueryCatalog::class);

        foreach ($registry->catalog() as $operation) {
            foreach (['operation_key', 'mode', 'official_doc_reference', 'official_method', 'exact_path', 'exact_path_casing', 'request_schema', 'response_schema', 'depot_evidence', 'installed_server_canary', 'v17_table_evidence', 'business_parity_source', 'evidence_status', 'runtime_enabled', 'blocker', 'evidence_hash'] as $field) {
                $this->assertArrayHasKey($field, $operation, $operation['operation_key']." misses {$field}");
            }
            $this->assertContains($operation['evidence_status'], MikroContractEvidenceCatalog::ALLOWED_STATUSES);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $operation['evidence_hash']);
            $this->assertIsArray($operation['business_parity_source']);
            $this->assertContains($operation['business_parity_source']['status'], MikroContractEvidenceCatalog::PARITY_STATUSES);
            if (! $operation['runtime_eligible']) {
                $this->assertFalse($operation['runtime_enabled']);
            }
            if ($operation['adapter_type'] === 'FIXED_QUERY') {
                $this->assertNotEmpty($operation['v17_table_evidence']);
                $sql = $queries->definition($operation['fixed_query_id'])['sql'];
                foreach ($operation['allowed_response_fields'] as $field) {
                    $this->assertMatchesRegularExpression('/\bAS\s+'.preg_quote($field, '/').'\b/i', $sql);
                }
            }
        }
    }

    public function test_offline_only_write_contracts_are_blocked_without_promoted_evidence(): void
    {
        $registry = app(MikroOperationRegistry::class);

        foreach ([
            'customer.save', 'order.save', 'invoice.create', 'dispatch.create',
            'record.link.save', 'record.bulk.save', 'stock.transfer.create',
            'order.dispatch.legacy.create', 'proforma.create',
        ] as $operationKey) {
            $operation = $registry->writeCapability($operationKey);
            $this->assertSame('CONTRACT_BLOCKED', $operation['contract_status']);
            $this->assertSame('CONTRACT_BLOCKED', $operation['evidence_status']);
            $this->assertSame('OFFICIAL_OR_DEPOT_CONTRACT_NOT_VERIFIED', $operation['blocker']);
            $this->assertNull($operation['endpoint']);
            $this->assertNull($operation['method']);
            $this->assertNotNull($operation['local_postman_item']);
            $this->assertFalse($operation['runtime_eligible']);
            $this->assertFalse($operation['runtime_enabled']);
            $this->assertSame('WRITE_REQUIRES_READBACK_CONTRACT', $operation['business_parity_source']['status']);
        }
    }

    public function test_base_url_policy_accepts_test_and_private_origins_but_rejects_public_or_composed_urls(): void
    {
        $registry = app(MikroOperationRegistry::class);

        $this->assertNull($registry->baseUrlBlocker(null));
        $this->assertNull($registry->baseUrlBlocker('https://mikro-api.example.test'));
        $this->assertNull($registry->baseUrlBlocker('http://10.20.30.40:8094'));
        $this->assertSame('MIKRO_BASE_URL_PUBLIC_HOST_DENIED', $registry->baseUrlBlocker('https://api.example.com'));
        $this->assertSame('MIKRO_BASE_URL_AUTHORITY_INVALID', $registry->baseUrlBlocker('https://user:secret@mikro-api.example.test'));
        $this->assertSame('MIKRO_BASE_URL_MUST_BE_ORIGIN', $registry->baseUrlBlocker('https://mikro-api.example.test/Api/APIMethods'));
        $this->assertSame('MIKRO_BASE_URL_AUTHORITY_INVALID', $registry->baseUrlBlocker('https://mikro-api.example.test?mode=read'));
    }

    public function test_production_private_origin_requires_an_explicit_allowlist_entry(): void
    {
        $registry = app(MikroOperationRegistry::class);
        $originalEnvironment = app()->environment();

        try {
            app()->detectEnvironment(static fn (): string => 'production');
            config()->set('services.mikro_api.allowed_hosts', []);
            $this->assertSame('MIKRO_BASE_URL_PUBLIC_HOST_DENIED', $registry->baseUrlBlocker('http://10.20.30.40:8094'));
            config()->set('services.mikro_api.allowed_hosts', ['10.20.30.40']);
            $this->assertNull($registry->baseUrlBlocker('http://10.20.30.40:8094'));
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }
    }
}
