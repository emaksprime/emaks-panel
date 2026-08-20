<?php

namespace Tests\Unit\Mikro;

use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Mikro\MikroApiClient;
use App\Services\Mikro\MikroFixedQueryCatalog;
use App\Services\Mikro\MikroParityContract;
use App\Services\Mikro\MikroParitySampleAuthority;
use App\Services\Mikro\MikroParitySource;
use App\Services\Mikro\MikroShadowParityRunner;
use App\Services\N8nPanelDataGateway;
use DomainException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MikroShadowParityRunnerTest extends TestCase
{
    private MikroParityContract $contract;

    private MikroParitySampleAuthority $samples;

    private N8nPanelDataGateway&MockObject $n8n;

    private MikroApiClient&MockObject $mikro;

    private TechnicalServiceMessagingSettingsService&MockObject $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contract = new MikroParityContract(new MikroFixedQueryCatalog);
        $this->samples = new MikroParitySampleAuthority($this->contract);
        $this->n8n = $this->createMock(N8nPanelDataGateway::class);
        $this->mikro = $this->createMock(MikroApiClient::class);
        $this->settings = $this->createMock(TechnicalServiceMessagingSettingsService::class);
    }

    public function test_parity_runner_never_changes_source_mode_or_mikro_switches(): void
    {
        $this->settings->expects($this->once())
            ->method('mikroApiConnectionContext')
            ->willReturn($this->runtimeContext());

        $preflight = $this->runner()->preflight();

        $this->assertSame(['active' => false, 'read_sync' => false, 'write' => false], $preflight['mikro_switches']);
        $this->assertSame(MikroParityContract::SAMPLE_POLICY_VERSION, $preflight['sample_policy_version']);
        $this->assertSame('NOT_RUN', $preflight['formal_run_1']);
        $this->assertSame('TYPED_SCHEMA_READY', $preflight['operations']['customer.lookup']['source_contract']);
    }

    public function test_schema_probe_measures_runtime_controls_and_uses_one_typed_read_per_source(): void
    {
        $lookup = ['customer_code' => 'C001'];
        $n8nResult = ['status' => 'CONTRACT_FIELD_UNAVAILABLE', 'provider' => 'n8n'];
        $mikroResult = ['success' => true, 'data' => [['status' => 'CONTRACT_FIELD_UNAVAILABLE']]];
        $this->settings->expects($this->exactly(2))->method('mikroApiConnectionContext')->willReturn($this->runtimeContext());
        $this->n8n->expects($this->once())->method('readForParity')->with(MikroParitySource::CUSTOMER_DETAIL, $lookup)->willReturn($n8nResult);
        $this->mikro->expects($this->once())->method('authenticatedParityRead')->with(MikroParitySource::CUSTOMER_DETAIL, $lookup)->willReturn($mikroResult);

        $probe = $this->runner()->schemaProbe('customer.lookup', $lookup);

        $this->assertSame('NOT_RUN', $probe['formal_parity_result']);
        $this->assertSame($n8nResult, $probe['n8n']);
        $this->assertSame($mikroResult, $probe['mikro']);
        $this->assertFalse($probe['source_mode_mutated']);
        $this->assertFalse($probe['mikro_switches_mutated']);
        $this->assertSame($probe['runtime_control_fingerprint_before'], $probe['runtime_control_fingerprint_after']);
    }

    public function test_discovery_binds_explicit_stock_as_of_date_to_every_resolved_lookup(): void
    {
        $this->n8n->expects($this->exactly(4))
            ->method('readForParity')
            ->willReturnCallback(function (MikroParitySource $source, array $parameters): array {
                if ($source === MikroParitySource::STOCK_DISCOVERY) {
                    $this->assertSame('2026-07-31', $parameters['as_of_date']);

                    return ['status' => 'READY', 'envelope' => ['samples' => [[
                        'identity' => 'STOK-001|1',
                        'lookup' => ['item_code' => 'STOK-001', 'warehouse_code' => 1],
                        'strata' => ['in_stock', 'serial_tracked', 'warehouse_1'],
                        'strata_dimensions' => [],
                    ]]]];
                }

                return ['status' => 'READY', 'envelope' => ['samples' => []]];
            });

        $bundle = $this->runner()->discoverSamples(
            [
                'customer.lookup' => ['limit' => 50],
                'stock.availability' => ['limit' => 100],
                'serial.lookup' => ['limit' => 50],
                'order.detail' => ['date_from' => '2025-08-01', 'date_to' => '2026-07-31', 'limit' => 50],
            ],
            $this->sampleContext(),
            ['key_base64' => base64_encode(str_repeat('K', 32)), 'salt_base64' => base64_encode(str_repeat('S', 16))],
            $this->retention(),
        );

        $lookup = $bundle['protected_manifest']['operations']['stock.availability']['samples'][0]['lookup'];
        $this->assertSame('2026-07-31', $lookup['as_of_date']);
    }

    public function test_stock_as_of_date_missing_or_mismatch_fails_before_network(): void
    {
        $this->n8n->expects($this->never())->method('readForParity');
        $context = $this->sampleContext();
        unset($context['as_of_date']);

        $this->expectException(DomainException::class);
        $this->runner()->discoverSamples([], $context, [], []);
    }

    public function test_unknown_or_write_operation_fails_before_network(): void
    {
        $this->n8n->expects($this->never())->method('readForParity');
        $this->mikro->expects($this->never())->method('authenticatedParityRead');

        try {
            $this->runner()->schemaProbe('invoice.write', []);
            $this->fail('Write operation must fail before a network call.');
        } catch (DomainException $exception) {
            $this->assertSame('MIKRO_PARITY_OPERATION_NOT_ALLOWED', $exception->getMessage());
        }
    }

    public function test_formal_shadow_parity_run_is_not_authorized(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('MIKRO_FORMAL_SHADOW_PARITY_NOT_AUTHORIZED');

        $this->runner()->runFormalParity('customer.lookup');
    }

    /** @return array<string, mixed> */
    private function runtimeContext(): array
    {
        return [
            'enabled' => false,
            'read_sync_enabled' => false,
            'write_enabled' => false,
            'operation_controls' => ['customer.lookup' => ['source_mode' => 'shadow_compare']],
        ];
    }

    /** @return array<string, mixed> */
    private function sampleContext(): array
    {
        return [
            'company_code' => 'EMAKS_PRIME',
            'working_year' => 2026,
            'branch_code' => 0,
            'warehouse_codes' => [1, 5],
            'as_of_date' => '2026-07-31',
            'date_range' => ['from' => '2025-08-01', 'to' => '2026-07-31'],
            'source_context' => ['mikro' => 'V17', 'n8n' => 'local-v2'],
        ];
    }

    /** @return array<string, mixed> */
    private function retention(): array
    {
        return [
            'manifest_id' => 'runner-test',
            'purpose' => 'RUN1_RUN2_REUSE',
            'generated_at_utc' => '2098-01-01T00:00:00Z',
            'expires_at_utc' => '2099-01-01T00:00:00Z',
            'retention_days' => 365,
        ];
    }

    private function runner(): MikroShadowParityRunner
    {
        return new MikroShadowParityRunner($this->contract, $this->samples, $this->n8n, $this->mikro, $this->settings);
    }
}
