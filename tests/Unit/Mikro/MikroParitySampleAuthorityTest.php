<?php

namespace Tests\Unit\Mikro;

use App\Services\Mikro\MikroFixedQueryCatalog;
use App\Services\Mikro\MikroParityContract;
use App\Services\Mikro\MikroParitySampleAuthority;
use DomainException;
use PHPUnit\Framework\TestCase;

class MikroParitySampleAuthorityTest extends TestCase
{
    private MikroParityContract $contract;

    private MikroParitySampleAuthority $authority;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contract = new MikroParityContract(new MikroFixedQueryCatalog);
        $this->authority = new MikroParitySampleAuthority($this->contract);
    }

    public function test_exact_contract_and_sample_manifest_can_be_reused(): void
    {
        $bundle = $this->bundle();

        $this->authority->assertReusable($bundle['public_manifest'], $bundle['protected_manifest'], $this->context());
        $this->addToAssertionCount(1);
    }

    public function test_sample_manifest_and_selection_are_deterministic(): void
    {
        $first = $this->bundle();
        $second = $this->authority->build(
            array_reverse($this->discoveryResults(), true),
            $this->context(),
            $this->key(),
            $this->salt(),
            $this->retention(),
        );

        $this->assertSame($first, $second);
        $this->assertSame(0, $first['public_manifest']['operations']['customer.lookup']['duplicate_identity_count']);
    }

    public function test_public_sample_manifest_contains_no_raw_lookup_or_business_identifier(): void
    {
        $public = $this->bundle()['public_manifest'];
        $json = $this->contract->canonicalJson($public);

        foreach (['"identity":', '"lookup":', '"customer_code":', '"item_code":', '"serial_number":', '"order_anchor_line_guid":', '"document_identity":', 'C001', 'STOK-001', 'SER001'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->assertMatchesRegularExpression('/sample_[a-f0-9]{32}/', $json);
    }

    public function test_public_and_private_manifest_counts_match(): void
    {
        $bundle = $this->bundle();

        foreach ($this->contract->operationKeys() as $operationKey) {
            $public = $bundle['public_manifest']['operations'][$operationKey];
            $private = $bundle['protected_manifest']['operations'][$operationKey];
            $this->assertSame($private['selected_count'], $public['selected_count']);
            $this->assertCount($private['selected_count'], $public['samples']);
            $this->assertCount($private['selected_count'], $private['samples']);
        }
    }

    public function test_public_fingerprint_is_stable_for_same_private_manifest(): void
    {
        $first = $this->bundle();
        $second = $this->bundle();

        $this->assertSame($first['protected_manifest']['protected_manifest_sha256'], $second['protected_manifest']['protected_manifest_sha256']);
        $this->assertSame($first['public_manifest']['public_manifest_sha256'], $second['public_manifest']['public_manifest_sha256']);
        $this->assertSame($first['public_manifest']['public_manifest_hmac'], $second['public_manifest']['public_manifest_hmac']);
    }

    public function test_tampered_public_manifest_is_rejected_even_when_sha_is_recomputed(): void
    {
        $bundle = $this->bundle();
        $public = $bundle['public_manifest'];
        $public['operations']['customer.lookup']['status'] = 'READY';
        unset($public['public_manifest_sha256'], $public['public_manifest_hmac']);
        $public['public_manifest_sha256'] = hash('sha256', $this->contract->canonicalJson($public));
        $public['public_manifest_hmac'] = $bundle['public_manifest']['public_manifest_hmac'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('MIKRO_PARITY_PUBLIC_SAMPLE_MANIFEST_INVALID');
        $this->authority->assertReusable($public, $bundle['protected_manifest'], $this->context());
    }

    public function test_tampered_private_manifest_is_rejected(): void
    {
        $bundle = $this->bundle();
        $private = $bundle['protected_manifest'];
        $private['operations']['customer.lookup']['samples'][0]['lookup']['customer_code'] = 'TAMPERED';
        unset($private['protected_manifest_sha256']);
        $private['protected_manifest_sha256'] = hash('sha256', $this->contract->canonicalJson($private));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('MIKRO_PARITY_PROTECTED_SAMPLE_MANIFEST_INVALID');
        $this->authority->assertReusable($bundle['public_manifest'], $private, $this->context());
    }

    public function test_relabelled_old_manifest_is_rejected(): void
    {
        $bundle = $this->bundle();
        $public = $bundle['public_manifest'];
        $public['normalization_version'] = 'mikro-shadow-parity-normalization.v1';
        unset($public['public_manifest_sha256'], $public['public_manifest_hmac']);
        $public['public_manifest_sha256'] = hash('sha256', $this->contract->canonicalJson($public));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('MIKRO_PARITY_SAMPLE_CONTRACT_MISMATCH');
        $this->authority->assertReusable($public, $bundle['protected_manifest'], $this->context());
    }

    public function test_changing_normalization_operation_or_sample_policy_version_invalidates_reuse(): void
    {
        foreach (['normalization_version', 'operation_contract_version', 'sample_policy_version'] as $field) {
            $bundle = $this->bundle();
            $bundle['protected_manifest'][$field] = 'changed';
            try {
                $this->authority->assertReusable($bundle['public_manifest'], $bundle['protected_manifest'], $this->context());
                $this->fail($field.' must invalidate reuse.');
            } catch (DomainException $exception) {
                $this->assertSame('MIKRO_PARITY_SAMPLE_CONTRACT_MISMATCH', $exception->getMessage());
            }
        }
    }

    public function test_source_context_company_year_branch_warehouse_dates_and_selection_are_bound(): void
    {
        $bundle = $this->bundle();
        $changed = $this->context();
        $changed['working_year'] = 2027;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('MIKRO_PARITY_SAMPLE_CONTEXT_MISMATCH');
        $this->authority->assertReusable($bundle['public_manifest'], $bundle['protected_manifest'], $changed);
    }

    public function test_every_stock_lookup_has_explicit_as_of_date_shared_with_source_context(): void
    {
        $protected = $this->bundle()['protected_manifest'];
        $samples = $protected['operations']['stock.availability']['samples'];

        $this->assertCount(100, $samples);
        foreach ($samples as $sample) {
            $this->assertSame('2026-07-31', $sample['lookup']['as_of_date']);
        }
    }

    public function test_stock_as_of_date_cannot_silently_default(): void
    {
        $context = $this->context();
        unset($context['as_of_date']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('MIKRO_PARITY_DATE_INVALID');
        $this->authority->build($this->discoveryResults(), $context, $this->key(), $this->salt(), $this->retention());
    }

    public function test_required_present_strata_are_covered_and_overlapping_strata_are_supported(): void
    {
        $operations = $this->bundle()['public_manifest']['operations'];
        $customer = $operations['customer.lookup'];
        $stock = $operations['stock.availability'];

        $this->assertSame('COVERED', $customer['strata_coverage']['active']['status']);
        $this->assertSame('COVERED', $customer['strata_coverage']['inactive']['status']);
        $this->assertSame(2, $customer['strata_coverage']['multiple_currencies']['represented_count']);
        $this->assertSame('COVERED', $stock['strata_coverage']['serial_tracked']['status']);
        $this->assertSame('COVERED', $stock['strata_coverage']['warehouse_1']['status']);
        $this->assertSame('COVERED', $stock['strata_coverage']['warehouse_5']['status']);
    }

    public function test_unavailable_stratum_is_reported_not_fabricated(): void
    {
        $customer = $this->bundle()['public_manifest']['operations']['customer.lookup'];

        $this->assertSame('STRATUM_UNAVAILABLE', $customer['status']);
        $this->assertContains('dealer', $customer['unavailable_strata']);
        $this->assertSame('STRATUM_UNAVAILABLE', $customer['strata_coverage']['dealer']['status']);
    }

    public function test_negative_serial_canary_is_marked_synthetic_and_not_business_data(): void
    {
        $bundle = $this->bundle();
        $private = $bundle['protected_manifest']['operations']['serial.lookup'];
        $public = $bundle['public_manifest']['operations']['serial.lookup'];

        $this->assertSame(1, $private['synthetic_selected_count']);
        $this->assertSame(51, $private['selected_count']);
        $synthetic = collect($private['samples'])->firstWhere('synthetic', true);
        $this->assertSame('NOT_BUSINESS_DATA', $synthetic['synthetic_classification']);
        $this->assertTrue(collect($public['samples'])->contains(fn (array $sample): bool => $sample['synthetic'] === true));
    }

    public function test_insufficient_sample_is_not_ready_for_promotion_and_does_not_hide_other_results(): void
    {
        $results = $this->discoveryResults();
        $results['serial.lookup']['envelope']['samples'] = array_slice($results['serial.lookup']['envelope']['samples'], 0, 12);
        $bundle = $this->authority->build($results, $this->context(), $this->key(), $this->salt(), $this->retention());

        $this->assertSame('INSUFFICIENT_SAMPLE', $bundle['public_manifest']['operations']['serial.lookup']['status']);
        $this->assertSame('STRATUM_UNAVAILABLE', $bundle['public_manifest']['operations']['customer.lookup']['status']);
    }

    public function test_duplicate_identity_is_zero_and_duplicate_input_is_contract_error(): void
    {
        $results = $this->discoveryResults();
        $results['customer.lookup']['envelope']['samples'][] = $results['customer.lookup']['envelope']['samples'][0];
        $bundle = $this->authority->build($results, $this->context(), $this->key(), $this->salt(), $this->retention());

        $customer = $bundle['public_manifest']['operations']['customer.lookup'];
        $this->assertSame('CONTRACT_ERROR', $customer['status']);
        $this->assertSame(1, $customer['duplicate_identity_count']);
    }

    public function test_final_sample_is_generated_from_final_contract_and_logs_do_not_disclose_inputs(): void
    {
        $bundle = $this->bundle();
        $this->assertSame($this->contract->fingerprint(), $bundle['public_manifest']['contract_fingerprint']);
        $this->assertSame(MikroParityContract::NORMALIZATION_VERSION, $bundle['protected_manifest']['normalization_version']);

        try {
            $changed = $this->context();
            $changed['company_code'] = 'SECRET-CUSTOMER-CODE';
            $this->authority->assertReusable($bundle['public_manifest'], $bundle['protected_manifest'], $changed);
            $this->fail('Context drift must fail.');
        } catch (DomainException $exception) {
            $this->assertStringNotContainsString('SECRET-CUSTOMER-CODE', $exception->getMessage());
            $this->assertSame('MIKRO_PARITY_SAMPLE_CONTEXT_MISMATCH', $exception->getMessage());
        }
    }

    /** @return array{public_manifest:array<string,mixed>,protected_manifest:array<string,mixed>} */
    private function bundle(): array
    {
        return $this->authority->build(
            $this->discoveryResults(),
            $this->context(),
            $this->key(),
            $this->salt(),
            $this->retention(),
        );
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'company_code' => 'EMAKS_PRIME',
            'working_year' => 2026,
            'branch_code' => 0,
            'warehouse_codes' => [5, 1],
            'as_of_date' => '2026-07-31',
            'date_range' => ['from' => '2025-08-01', 'to' => '2026-07-31'],
            'source_context' => ['mikro' => 'V17', 'n8n' => 'local-v2'],
        ];
    }

    /** @return array<string, mixed> */
    private function retention(): array
    {
        return [
            'manifest_id' => 'test-manifest-v2',
            'purpose' => 'RUN1_RUN2_REUSE',
            'generated_at_utc' => '2098-01-01T00:00:00Z',
            'expires_at_utc' => '2099-01-01T00:00:00Z',
            'retention_days' => 365,
        ];
    }

    private function key(): string
    {
        return base64_encode(str_repeat('K', 32));
    }

    private function salt(): string
    {
        return base64_encode(str_repeat('S', 16));
    }

    /** @return array<string, array<string, mixed>> */
    private function discoveryResults(): array
    {
        return [
            'customer.lookup' => $this->discoveryResult('customer.lookup', 50, fn (int $index): array => [
                'identity' => sprintf('C%03d', $index),
                'lookup' => ['customer_code' => sprintf('C%03d', $index)],
                'strata' => [$index % 2 === 0 ? 'active' : 'inactive'],
                'strata_dimensions' => ['currency' => (string) ($index % 2)],
            ]),
            'stock.availability' => $this->discoveryResult('stock.availability', 100, fn (int $index): array => [
                'identity' => sprintf('S%03d|%d', intdiv($index, 2), $index % 2 === 0 ? 1 : 5),
                'lookup' => ['item_code' => sprintf('S%03d', intdiv($index, 2)), 'warehouse_code' => $index % 2 === 0 ? 1 : 5],
                'strata' => [
                    $index % 3 === 0 ? 'out_of_stock' : 'in_stock',
                    $index % 2 === 0 ? 'serial_tracked' : 'non_serial',
                    $index % 2 === 0 ? 'warehouse_1' : 'warehouse_5',
                ],
                'strata_dimensions' => [],
            ]),
            'serial.lookup' => $this->discoveryResult('serial.lookup', 50, fn (int $index): array => [
                'identity' => sprintf('SER%03d|S%03d', $index, $index),
                'lookup' => ['serial_number' => sprintf('SER%03d', $index), 'item_code' => sprintf('S%03d', $index)],
                'strata' => [],
                'strata_dimensions' => [],
            ]),
            'order.detail' => $this->discoveryResult('order.detail', 50, fn (int $index): array => [
                'identity' => sprintf('0|0|S|%d', $index),
                'lookup' => ['order_anchor_line_guid' => sprintf('00000000-0000-4000-8000-%012d', $index)],
                'strata' => [],
                'strata_dimensions' => [
                    'customer_context' => 'C'.($index % 2),
                    'warehouse_context' => (string) ($index % 2 === 0 ? 1 : 5),
                ],
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

        return ['status' => 'READY', 'operation_key' => $operationKey, 'envelope' => ['samples' => $samples]];
    }
}
