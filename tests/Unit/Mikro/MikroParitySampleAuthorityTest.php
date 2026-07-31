<?php

namespace Tests\Unit\Mikro;

use App\Services\Mikro\MikroFixedQueryCatalog;
use App\Services\Mikro\MikroParityContract;
use App\Services\Mikro\MikroParitySampleAuthority;
use DomainException;
use PHPUnit\Framework\TestCase;

class MikroParitySampleAuthorityTest extends TestCase
{
    public function test_sample_manifest_is_deterministic_has_no_duplicate_identity_and_can_be_reused(): void
    {
        $contract = new MikroParityContract(new MikroFixedQueryCatalog);
        $authority = new MikroParitySampleAuthority($contract);
        $results = $this->discoveryResults();

        $first = $authority->build($results);
        $second = $authority->build(array_reverse($results, true));

        $this->assertSame($first, $second);
        $this->assertSame(0, $first['operations']['customer.lookup']['duplicate_identity_count']);
        $this->assertSame(50, $first['operations']['customer.lookup']['selected_count']);
        $authority->assertReusable($first);
    }

    public function test_insufficient_sample_is_not_pass_and_does_not_block_other_operations(): void
    {
        $contract = new MikroParityContract(new MikroFixedQueryCatalog);
        $authority = new MikroParitySampleAuthority($contract);
        $results = $this->discoveryResults();
        $results['serial.lookup']['envelope']['samples'] = array_slice($results['serial.lookup']['envelope']['samples'], 0, 12);

        $manifest = $authority->build($results);

        $this->assertSame('INSUFFICIENT_SAMPLE', $manifest['operations']['serial.lookup']['status']);
        $this->assertSame(12, $manifest['operations']['serial.lookup']['selected_count']);
        $this->assertSame('READY', $manifest['operations']['customer.lookup']['status']);
        $this->assertSame('READY', $manifest['operations']['stock.availability']['status']);
        $this->assertSame('READY', $manifest['operations']['order.detail']['status']);
    }

    public function test_tampered_run_two_manifest_is_rejected(): void
    {
        $contract = new MikroParityContract(new MikroFixedQueryCatalog);
        $authority = new MikroParitySampleAuthority($contract);
        $manifest = $authority->build($this->discoveryResults());
        $manifest['operations']['customer.lookup']['samples'][0]['identity'] = 'tampered';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('MIKRO_PARITY_SAMPLE_MANIFEST_INVALID');
        $authority->assertReusable($manifest);
    }

    public function test_discovery_contract_error_is_not_relabelled_as_source_unavailable(): void
    {
        $contract = new MikroParityContract(new MikroFixedQueryCatalog);
        $authority = new MikroParitySampleAuthority($contract);
        $results = $this->discoveryResults();
        $results['order.detail'] = [
            'status' => 'CONTRACT_ERROR',
            'error_code' => 'MIKRO_PARITY_SOURCE_FIELD_MISSING',
            'envelope' => ['samples' => []],
        ];

        $manifest = $authority->build($results);

        $this->assertSame('CONTRACT_ERROR', $manifest['operations']['order.detail']['status']);
        $this->assertSame('MIKRO_PARITY_SOURCE_FIELD_MISSING', $manifest['operations']['order.detail']['error_code']);
        $this->assertSame('READY', $manifest['operations']['customer.lookup']['status']);
    }

    /** @return array<string, array<string, mixed>> */
    private function discoveryResults(): array
    {
        return [
            'customer.lookup' => $this->discoveryResult('customer.lookup', 50, fn (int $index): array => [
                'identity' => sprintf('C%03d', $index),
                'lookup' => ['customer_code' => sprintf('C%03d', $index)],
                'record_fingerprint' => hash('sha256', 'customer-'.$index),
            ]),
            'stock.availability' => $this->discoveryResult('stock.availability', 100, fn (int $index): array => [
                'identity' => sprintf('S%03d|%d', intdiv($index, 2), $index % 2 === 0 ? 1 : 5),
                'lookup' => ['item_code' => sprintf('S%03d', intdiv($index, 2)), 'warehouse_code' => $index % 2 === 0 ? 1 : 5],
                'record_fingerprint' => hash('sha256', 'stock-'.$index),
            ]),
            'serial.lookup' => $this->discoveryResult('serial.lookup', 50, fn (int $index): array => [
                'identity' => sprintf('SER%03d|S%03d', $index, $index),
                'lookup' => ['serial_number' => sprintf('SER%03d', $index), 'item_code' => sprintf('S%03d', $index)],
                'record_fingerprint' => hash('sha256', 'serial-'.$index),
            ]),
            'order.detail' => $this->discoveryResult('order.detail', 50, fn (int $index): array => [
                'identity' => sprintf('0|0|S|%d', $index),
                'lookup' => ['order_anchor_line_guid' => sprintf('00000000-0000-4000-8000-%012d', $index)],
                'record_fingerprint' => hash('sha256', 'order-'.$index),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function discoveryResult(string $operationKey, int $count, callable $factory): array
    {
        $samples = [];
        for ($index = 1; $index <= $count; $index++) {
            $samples[] = $factory($index);
        }

        return [
            'status' => 'READY',
            'operation_key' => $operationKey,
            'envelope' => ['samples' => $samples],
        ];
    }
}
