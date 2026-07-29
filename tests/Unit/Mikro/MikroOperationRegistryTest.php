<?php

namespace Tests\Unit\Mikro;

use App\Services\Mikro\MikroOperationRegistry;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MikroOperationRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_exposes_only_confirmed_read_operations(): void
    {
        $registry = app(MikroOperationRegistry::class);
        $summary = $registry->summary();

        $this->assertSame('active', $summary['status']);
        $this->assertSame(3, $summary['read_count']);
        $this->assertSame(0, $summary['write_count']);
        $this->assertSame(['health.check', 'customer.list', 'stock.list'], $summary['enabled_keys']);
        $this->assertSame('GET', $registry->read('health.check')['method']);
        $this->assertSame('POST', $registry->read('customer.list')['method']);
        $this->assertSame('READ', $registry->read('customer.list')['mode']);
        $this->assertSame('POST', $registry->read('stock.list')['method']);
        $this->assertSame('READ', $registry->read('stock.list')['mode']);
    }

    public function test_write_generic_and_unknown_operations_fail_closed(): void
    {
        $registry = app(MikroOperationRegistry::class);

        foreach (['generic.call', 'sql.read', 'record.save', 'stock.movement.create', 'invoice.create'] as $operation) {
            try {
                $registry->read($operation);
                $this->fail("{$operation} should be denied.");
            } catch (DomainException $exception) {
                $this->assertSame(MikroOperationRegistry::BLOCKED_DENIED, $exception->getMessage());
            }
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(MikroOperationRegistry::BLOCKED_UNKNOWN);

        $registry->read('serial.lookup');
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

            $this->assertSame(
                'MIKRO_BASE_URL_PUBLIC_HOST_DENIED',
                $registry->baseUrlBlocker('http://10.20.30.40:8094'),
            );

            config()->set('services.mikro_api.allowed_hosts', ['10.20.30.40']);

            $this->assertNull($registry->baseUrlBlocker('http://10.20.30.40:8094'));
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }
    }
}
