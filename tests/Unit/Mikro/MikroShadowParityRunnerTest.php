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
            ->willReturn(['enabled' => false, 'read_sync_enabled' => false, 'write_enabled' => false]);
        $runner = $this->runner();

        $preflight = $runner->preflight();

        $this->assertSame(['active' => false, 'read_sync' => false, 'write' => false], $preflight['mikro_switches']);
        $this->assertSame('NOT_RUN', $preflight['formal_run_1']);
        $this->assertSame('READY', $preflight['operations']['customer.lookup']['source_contract']);
    }

    public function test_schema_probe_uses_one_typed_read_per_source_without_formal_parity_result(): void
    {
        $lookup = ['customer_code' => 'C001'];
        $n8nResult = ['status' => 'CONTRACT_FIELD_UNAVAILABLE', 'provider' => 'n8n'];
        $mikroResult = ['success' => true, 'data' => [['status' => 'CONTRACT_FIELD_UNAVAILABLE']]];
        $this->n8n->expects($this->once())
            ->method('readForParity')
            ->with(MikroParitySource::CUSTOMER_DETAIL, $lookup)
            ->willReturn($n8nResult);
        $this->mikro->expects($this->once())
            ->method('authenticatedParityRead')
            ->with(MikroParitySource::CUSTOMER_DETAIL, $lookup)
            ->willReturn($mikroResult);

        $probe = $this->runner()->schemaProbe('customer.lookup', $lookup);

        $this->assertSame('NOT_RUN', $probe['formal_parity_result']);
        $this->assertSame($n8nResult, $probe['n8n']);
        $this->assertSame($mikroResult, $probe['mikro']);
        $this->assertFalse($probe['source_mode_mutated']);
        $this->assertFalse($probe['mikro_switches_mutated']);
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

    private function runner(): MikroShadowParityRunner
    {
        return new MikroShadowParityRunner(
            $this->contract,
            $this->samples,
            $this->n8n,
            $this->mikro,
            $this->settings,
        );
    }
}
