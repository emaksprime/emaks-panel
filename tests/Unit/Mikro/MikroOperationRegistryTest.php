<?php

namespace Tests\Unit\Mikro;

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
        $this->assertSame(30, $summary['implemented_read_count']);
        $this->assertSame(30, $summary['enabled_read_count']);
        $this->assertSame(11, $summary['write_count']);
        $this->assertSame(0, $summary['enabled_write_count']);
        $this->assertSame(9, $summary['direct_endpoint_count']);
        $this->assertSame(21, $summary['fixed_query_count']);
        $this->assertSame(5, $summary['contract_blocked_count']);

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
            $this->assertFalse($registry->writeCapability($key)['runtime_enabled']);
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

        $this->assertSame('/Api/APIMethods/SqlVeriOkuV2', $registry->read('order.list')['endpoint']);
        $this->assertSame('order.list', $registry->read('order.list')['fixed_query_id']);
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

        foreach (['proforma.list', 'proforma.detail'] as $operation) {
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
                $this->assertContains($exception->getMessage(), ['MIKRO_DISABLED', 'MIKRO_READ_SYNC_DISABLED', 'MIKRO_OPERATION_DISABLED']);
            }
        }

        try {
            $registry->assertReadAllowed('order.lines', ['enabled' => true, 'read_sync_enabled' => true]);
            $this->fail('n8n source mode must not open the Mikro client.');
        } catch (DomainException $exception) {
            $this->assertSame(MikroOperationRegistry::BLOCKED_DENIED, $exception->getMessage());
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

        foreach ([[false, 'op-1', 'MIKRO_WRITE_APPROVAL_REQUIRED'], [true, null, 'MIKRO_RECONCILIATION_REQUIRED']] as [$approved, $idempotency, $message]) {
            try {
                $registry->assertWriteAllowed('customer.save', $settings, $approved, $idempotency);
                $this->fail('Write gate should reject incomplete authorization.');
            } catch (DomainException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }

        $this->assertSame('customer.save', $registry->assertWriteAllowed('customer.save', $settings, true, 'op-1')['operation_key']);
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
