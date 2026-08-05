<?php

namespace Tests\Feature;

use App\Services\Mikro\DirectMikroApiClient;
use App\Services\Mikro\MikroApiClientInterface;
use App\Services\Mikro\MikroApiResult;
use App\Services\Mikro\MikroBusinessWriteMonitor;
use App\Services\Mikro\MikroCanaryPolicy;
use App\Services\Mikro\MikroConnectionProfile;
use App\Services\Mikro\MikroCredentialEnvelope;
use App\Services\Mikro\MikroErrorClass;
use App\Services\Mikro\MikroOperationCatalog;
use App\Services\Mikro\MikroOperationCatalogInterface;
use App\Services\Mikro\MikroOperationDefinition;
use App\Services\Mikro\MikroPrivateBaseUrl;
use App\Services\Mikro\MikroReadCanaryService;
use App\Services\Mikro\MikroRequestContext;
use App\Services\Mikro\MikroStockListRequest;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MikroDirectApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    #[Test]
    public function mikro_health_probe_is_side_effect_free(): void
    {
        Http::fake([
            $this->healthUrl() => Http::response(['status' => 'fixture-only'], 200),
        ]);
        $monitor = new MikroBusinessWriteMonitor(DB::connection());
        $defaultResult = (new DirectMikroApiClient(
            $this->app->make(Factory::class),
            $this->app,
        ))->probeHealth(
            $this->profile(),
            $this->context(MikroOperationCatalog::HEALTH_CHECK),
        );

        $this->assertFalse($defaultResult->success);
        $this->assertSame(MikroErrorClass::FEATURE_DISABLED, $defaultResult->errorClass);
        $this->assertSame(0, $defaultResult->httpRequestCount);
        Http::assertNothingSent();

        $productionResult = (new DirectMikroApiClient(
            $this->app->make(Factory::class),
            $this->nonLocalApplication(),
            healthProbeEnabled: true,
        ))->probeHealth(
            $this->profile(),
            $this->context(MikroOperationCatalog::HEALTH_CHECK, 'testing'),
        );

        $this->assertFalse($productionResult->success);
        $this->assertSame(MikroErrorClass::NON_LOCAL_EXECUTION, $productionResult->errorClass);
        $this->assertSame(0, $productionResult->httpRequestCount);
        Http::assertNothingSent();

        $measurement = $monitor->measure(fn (): MikroApiResult => $this->directClient()->probeHealth(
            $this->profile(),
            $this->context(MikroOperationCatalog::HEALTH_CHECK),
        ));

        $this->assertSame(0, $measurement->writeCount);
        $this->assertInstanceOf(MikroApiResult::class, $measurement->value);
        $this->assertFalse($measurement->value->success);
        $this->assertSame(MikroErrorClass::BODY_CONTRACT_UNVERIFIED, $measurement->value->errorClass);
        Http::assertSentCount(1);
    }

    #[Test]
    public function mikro_health_probe_uses_at_most_one_request(): void
    {
        Http::fake([
            $this->healthUrl() => Http::response(['status' => 'fixture-only'], 200),
        ]);

        $result = $this->directClient()->probeHealth(
            $this->profile(),
            $this->context(MikroOperationCatalog::HEALTH_CHECK),
        );

        $this->assertSame(1, $result->httpRequestCount);
        $this->assertSame(1, $result->readHttpRequestCount);
        $this->assertSame(0, $result->writeHttpRequestCount);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === $this->healthUrl()
            && count($request->header('X-Correlation-ID')) === 1);
    }

    #[Test]
    public function mikro_health_probe_does_not_retry(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return $attempts === 1
                ? Http::response(['error' => 'fixture-unavailable'], 503)
                : Http::response(['status' => 'fixture-second-response'], 200);
        });

        $result = $this->directClient()->probeHealth(
            $this->profile(),
            $this->context(MikroOperationCatalog::HEALTH_CHECK),
        );

        $this->assertSame(1, $attempts);
        $this->assertSame(1, $result->httpRequestCount);
        $this->assertFalse($result->success);
        Http::assertSentCount(1);
    }

    #[Test]
    public function mikro_health_probe_does_not_treat_every_2xx_as_success(): void
    {
        Http::fake([
            $this->healthUrl() => Http::response(['unexpected' => 'fixture-shape'], 200),
        ]);

        $result = $this->directClient()->probeHealth(
            $this->profile(),
            $this->context(MikroOperationCatalog::HEALTH_CHECK),
        );

        $this->assertFalse($result->success);
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame(MikroErrorClass::BODY_CONTRACT_UNVERIFIED, $result->errorClass);
        Http::assertSentCount(1);
    }

    #[Test]
    public function mikro_canary_rejects_unverified_operation(): void
    {
        $definition = new MikroOperationDefinition(
            code: MikroOperationCatalog::STOCK_LIST,
            title: 'Unverified stock read fixture',
            classification: MikroOperationDefinition::CLASSIFICATION_READ,
            verification: MikroOperationDefinition::VERIFICATION_BLOCKED,
            method: MikroOperationCatalog::STOCK_LIST_METHOD,
            endpoint: MikroOperationCatalog::STOCK_LIST_ENDPOINT,
            maxRows: 5,
        );

        $decision = $this->enabledPolicy()->decide(
            $definition,
            $this->profile(),
            $this->credentials(),
            new MikroStockListRequest(size: 5),
            $this->context(MikroOperationCatalog::STOCK_LIST),
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame(MikroErrorClass::CONTRACT_UNVERIFIED, $decision->errorClass);

        $client = $this->createMock(MikroApiClientInterface::class);
        $client->expects($this->never())->method('readStockList');
        $context = $this->context(MikroOperationCatalog::STOCK_LIST);
        $catalogResult = (new MikroReadCanaryService(
            $client,
            $this->enabledPolicy(),
            new MikroOperationCatalog,
            new MikroBusinessWriteMonitor(DB::connection()),
        ))->run(
            $this->profile(),
            $this->credentials(),
            $context,
            new MikroStockListRequest(size: 5),
        );

        $this->assertFalse($catalogResult->executed);
        $this->assertSame(MikroErrorClass::CONTRACT_UNVERIFIED, $catalogResult->decision->errorClass);
        $this->assertSame(0, $catalogResult->apiResult->httpRequestCount);
        $this->assertSame(0, $context->httpRequestCount());
        Http::assertNothingSent();
    }

    #[Test]
    public function mikro_canary_rejects_write_operation(): void
    {
        $definition = new MikroOperationDefinition(
            code: 'stock.write.fixture',
            title: 'Write fixture',
            classification: MikroOperationDefinition::CLASSIFICATION_WRITE,
            verification: MikroOperationDefinition::VERIFICATION_CONTRACT_VERIFIED,
            method: 'POST',
            endpoint: MikroOperationCatalog::STOCK_LIST_ENDPOINT,
            maxRows: 5,
            safeForCanary: true,
            requiresWriteGate: true,
        );

        $decision = $this->enabledPolicy()->decide(
            $definition,
            $this->profile(),
            $this->credentials(),
            new MikroStockListRequest(size: 5),
            $this->context($definition->code),
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame(MikroErrorClass::WRITE_OPERATION_FORBIDDEN, $decision->errorClass);
        Http::assertNothingSent();
    }

    #[Test]
    public function mikro_canary_rejects_sql_veri_oku_v2(): void
    {
        $decision = $this->enabledPolicy()->decide(
            $this->syntheticVerifiedRead('SqlVeriOkuV2'),
            $this->profile(),
            $this->credentials(),
            new MikroStockListRequest(size: 5),
            $this->context('SqlVeriOkuV2'),
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame(MikroErrorClass::OPERATION_BLOCKED, $decision->errorClass);
        Http::assertNothingSent();
    }

    #[Test]
    public function mikro_canary_rejects_sql_sorgu(): void
    {
        $decision = $this->enabledPolicy()->decide(
            $this->syntheticVerifiedRead('SQLSorgu'),
            $this->profile(),
            $this->credentials(),
            new MikroStockListRequest(size: 5),
            $this->context('SQLSorgu'),
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame(MikroErrorClass::OPERATION_BLOCKED, $decision->errorClass);
        Http::assertNothingSent();
    }

    #[Test]
    public function mikro_canary_allows_only_dedicated_direct_read_contract(): void
    {
        $allowed = $this->enabledPolicy()->decide(
            $this->syntheticVerifiedRead(),
            $this->profile(),
            $this->credentials(),
            new MikroStockListRequest(size: 5),
            $this->context(MikroOperationCatalog::STOCK_LIST),
        );
        $wrongEndpoint = $this->enabledPolicy()->decide(
            $this->syntheticVerifiedRead(endpoint: '/Api/APIMethods/OtherRead'),
            $this->profile(),
            $this->credentials(),
            new MikroStockListRequest(size: 5),
            $this->context(MikroOperationCatalog::STOCK_LIST),
        );
        $productionPolicy = new MikroCanaryPolicy($this->nonLocalApplication(), [
            'real_canary_enabled' => true,
            'allowed_canary_environments' => ['production', 'testing'],
        ]);
        $productionDecision = $productionPolicy->decide(
            $this->syntheticVerifiedRead(),
            $this->profile(),
            $this->credentials(),
            new MikroStockListRequest(size: 5),
            $this->context(MikroOperationCatalog::STOCK_LIST, 'production'),
        );

        $this->assertTrue($allowed->allowed);
        $this->assertFalse($wrongEndpoint->allowed);
        $this->assertFalse($productionDecision->allowed);
        $this->assertSame(MikroErrorClass::NON_LOCAL_EXECUTION, $productionDecision->errorClass);
        Http::assertNothingSent();
    }

    #[Test]
    public function mikro_canary_limits_page_size_to_five(): void
    {
        $overLimitDefinition = $this->syntheticVerifiedRead(maxRows: 6);
        $decision = $this->enabledPolicy()->decide(
            $overLimitDefinition,
            $this->profile(),
            $this->credentials(),
            new MikroStockListRequest(size: 5),
            $this->context(MikroOperationCatalog::STOCK_LIST),
        );

        $this->assertFalse($decision->allowed);

        try {
            new MikroStockListRequest(size: 6);
            $this->fail('A canary request above five rows must be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function mikro_canary_uses_at_most_one_outbound_request(): void
    {
        $context = $this->context(MikroOperationCatalog::STOCK_LIST);
        $result = $this->canaryService(
            $this->fakeReadClient(),
            $this->syntheticVerifiedRead(),
        )->run(
            $this->profile(),
            $this->credentials(),
            $context,
            new MikroStockListRequest(size: 5),
        );

        $this->assertTrue($result->executed);
        $this->assertSame(1, $context->httpRequestCount());
        $this->assertSame(1, $result->apiResult->httpRequestCount);
        $this->assertTrue($result->passed());

        $zeroCounterContext = $this->context(MikroOperationCatalog::STOCK_LIST);
        $zeroCounterResult = $this->canaryService(
            $this->fakeReadClient(classification: null),
            $this->syntheticVerifiedRead(),
        )->run(
            $this->profile(),
            $this->credentials(),
            $zeroCounterContext,
            new MikroStockListRequest(size: 5),
        );

        $this->assertTrue($zeroCounterResult->executed);
        $this->assertFalse($zeroCounterResult->apiResult->success);
        $this->assertSame(MikroErrorClass::INVALID_RESPONSE, $zeroCounterResult->apiResult->errorClass);
        $this->assertFalse($zeroCounterResult->passed());

        $mismatchedCounterContext = $this->context(MikroOperationCatalog::STOCK_LIST);
        $mismatchedCounterResult = $this->canaryService(
            $this->fakeReadClient(misreportReadCounters: true),
            $this->syntheticVerifiedRead(),
        )->run(
            $this->profile(),
            $this->credentials(),
            $mismatchedCounterContext,
            new MikroStockListRequest(size: 5),
        );

        $this->assertSame(1, $mismatchedCounterContext->readHttpRequestCount());
        $this->assertFalse($mismatchedCounterResult->apiResult->success);
        $this->assertSame(MikroErrorClass::INVALID_RESPONSE, $mismatchedCounterResult->apiResult->errorClass);
        $this->assertFalse($mismatchedCounterResult->passed());
        Http::assertNothingSent();
    }

    #[Test]
    public function mikro_canary_measures_zero_write_requests(): void
    {
        $context = $this->context(MikroOperationCatalog::STOCK_LIST);
        $result = $this->canaryService(
            $this->fakeReadClient(),
            $this->syntheticVerifiedRead(),
        )->run(
            $this->profile(),
            $this->credentials(),
            $context,
            new MikroStockListRequest(size: 5),
        );

        $this->assertSame(0, $context->writeHttpRequestCount());
        $this->assertSame(0, $result->apiResult->writeHttpRequestCount);
        $this->assertTrue($result->passed());

        $writeContext = $this->context(MikroOperationCatalog::STOCK_LIST);
        $writeResult = $this->canaryService(
            $this->fakeReadClient(
                classification: MikroOperationDefinition::CLASSIFICATION_WRITE,
                misreportWriteCount: true,
            ),
            $this->syntheticVerifiedRead(),
        )->run(
            $this->profile(),
            $this->credentials(),
            $writeContext,
            new MikroStockListRequest(size: 5),
        );

        $this->assertSame(1, $writeContext->writeHttpRequestCount());
        $this->assertSame(1, $writeResult->apiResult->writeHttpRequestCount);
        $this->assertFalse($writeResult->passed());
        Http::assertNothingSent();
    }

    #[Test]
    public function mikro_canary_performs_zero_database_business_writes(): void
    {
        $result = $this->canaryService(
            $this->fakeReadClient(),
            $this->syntheticVerifiedRead(),
        )->run(
            $this->profile(),
            $this->credentials(),
            $this->context(MikroOperationCatalog::STOCK_LIST),
            new MikroStockListRequest(size: 5),
        );

        $this->assertSame(0, $result->businessWriteCount);
        $this->assertTrue($result->passed());

        $markedResult = $this->canaryService(
            $this->fakeReadClient(markBusinessWrite: true),
            $this->syntheticVerifiedRead(),
        )->run(
            $this->profile(),
            $this->credentials(),
            $this->context(MikroOperationCatalog::STOCK_LIST),
            new MikroStockListRequest(size: 5),
        );

        $this->assertSame(1, $markedResult->businessWriteCount);
        $this->assertSame(MikroErrorClass::BUSINESS_WRITE_DETECTED, $markedResult->apiResult->errorClass);
        $this->assertFalse($markedResult->passed());
        Http::assertNothingSent();
    }

    private function directClient(): DirectMikroApiClient
    {
        return new DirectMikroApiClient(
            $this->app->make(Factory::class),
            $this->app,
            healthProbeEnabled: true,
        );
    }

    private function canaryService(
        MikroApiClientInterface $client,
        MikroOperationDefinition $operation,
    ): MikroReadCanaryService {
        return new MikroReadCanaryService(
            $client,
            $this->enabledPolicy(),
            $this->catalogProviding($operation),
            new MikroBusinessWriteMonitor(DB::connection()),
        );
    }

    private function catalogProviding(MikroOperationDefinition $operation): MikroOperationCatalogInterface
    {
        $catalog = $this->createMock(MikroOperationCatalogInterface::class);
        $catalog->expects($this->once())
            ->method('find')
            ->with(MikroOperationCatalog::STOCK_LIST)
            ->willReturn($operation);

        return $catalog;
    }

    private function enabledPolicy(): MikroCanaryPolicy
    {
        return new MikroCanaryPolicy($this->app, [
            'real_canary_enabled' => true,
            'allowed_canary_environments' => ['testing'],
            'stock_list' => [
                'method' => MikroOperationCatalog::STOCK_LIST_METHOD,
                'endpoint' => MikroOperationCatalog::STOCK_LIST_ENDPOINT,
                'maximum_canary_rows' => 5,
            ],
        ]);
    }

    private function syntheticVerifiedRead(
        string $code = MikroOperationCatalog::STOCK_LIST,
        string $endpoint = MikroOperationCatalog::STOCK_LIST_ENDPOINT,
        int $maxRows = 5,
    ): MikroOperationDefinition {
        return new MikroOperationDefinition(
            code: $code,
            title: 'Synthetic verified dedicated read fixture',
            classification: MikroOperationDefinition::CLASSIFICATION_READ,
            verification: MikroOperationDefinition::VERIFICATION_CONTRACT_VERIFIED,
            method: MikroOperationCatalog::STOCK_LIST_METHOD,
            endpoint: $endpoint,
            maxRows: $maxRows,
            safeForCanary: true,
        );
    }

    private function fakeReadClient(
        ?string $classification = MikroOperationDefinition::CLASSIFICATION_READ,
        bool $misreportWriteCount = false,
        bool $markBusinessWrite = false,
        bool $misreportReadCounters = false,
    ): MikroApiClientInterface {
        $client = $this->createMock(MikroApiClientInterface::class);
        $client->expects($this->once())
            ->method('readStockList')
            ->willReturnCallback(function (
                MikroConnectionProfile $profile,
                MikroCredentialEnvelope $credentials,
                MikroRequestContext $context,
                MikroStockListRequest $request,
            ) use ($classification, $markBusinessWrite, $misreportReadCounters, $misreportWriteCount): MikroApiResult {
                if ($classification !== null) {
                    $context->recordHttpRequest($classification);
                }
                if ($markBusinessWrite) {
                    DB::connection()->recordsHaveBeenModified();
                }

                return new MikroApiResult(
                    success: true,
                    httpStatus: 200,
                    providerStatus: 'FAKE_ONLY',
                    errorClass: MikroErrorClass::NONE,
                    errorCode: null,
                    message: 'FAKE_CONTRACT_RESPONSE',
                    rowCount: min($request->size, 5),
                    durationMs: $context->durationMs(),
                    httpRequestCount: $misreportReadCounters ? 0 : $context->httpRequestCount(),
                    readHttpRequestCount: $misreportReadCounters ? 0 : $context->readHttpRequestCount(),
                    writeHttpRequestCount: $misreportWriteCount ? 0 : $context->writeHttpRequestCount(),
                    correlationId: $context->correlationId,
                    metadata: ['transport' => 'fake'],
                );
            });

        return $client;
    }

    private function profile(): MikroConnectionProfile
    {
        return new MikroConnectionProfile(
            baseUrl: new MikroPrivateBaseUrl('http://127.0.0.1:8094'),
            apiVersion: 'V17',
            applicationCode: 'WP01_TEST',
            applicationName: 'WP-01 contract test',
            firmCode: '1',
            branchCode: '0',
            terminalCode: '0',
            fiscalYear: 2026,
            username: 'fixture-user',
            timeoutSeconds: 3,
        );
    }

    private function credentials(): MikroCredentialEnvelope
    {
        return new MikroCredentialEnvelope(password: 'fixture-password-not-real');
    }

    private function nonLocalApplication(): Application
    {
        $application = $this->createMock(Application::class);
        $application->method('environment')->willReturn(false);

        return $application;
    }

    private function context(string $operationCode, string $environment = 'testing'): MikroRequestContext
    {
        return new MikroRequestContext(
            correlationId: 'wp01-fixture-'.str_replace('.', '-', $operationCode).'-'.bin2hex(random_bytes(4)),
            operationCode: $operationCode,
            environment: $environment,
            requestBudget: 1,
        );
    }

    private function healthUrl(): string
    {
        return 'http://127.0.0.1:8094'.MikroOperationCatalog::HEALTH_ENDPOINT;
    }
}
