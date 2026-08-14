<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceRequestSerial;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Mikro\MikroApiClient;
use App\Services\Mikro\MikroContractEvidenceCatalog;
use App\Services\Mikro\MikroFixedQueryCatalog;
use App\Services\Mikro\MikroOperationRegistry;
use App\Services\Mikro\MikroParitySource;
use App\Services\Mikro\MikroResponseSchemaCatalog;
use App\Services\Mikro\MikroRuntimeState;
use Carbon\CarbonImmutable;
use DomainException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

class MikroApiConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-29 14:00:00', 'Europe/Istanbul'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_health_check_blocks_missing_safe_base_url_without_network(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/technical-service/messaging-settings')
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.contract_ready', true)
            ->assertJsonPath('messaging_settings.mikro_api.health_configuration_ready', false)
            ->assertJsonPath('messaging_settings.mikro_api.live_configuration_ready', false)
            ->assertJsonPath('messaging_settings.mikro_api.readiness_status', 'CONTRACT_READY')
            ->assertJsonPath('messaging_settings.mikro_api.read_operation_count', 33)
            ->assertJsonPath('messaging_settings.mikro_api.implemented_read_operation_count', 31)
            ->assertJsonPath('messaging_settings.mikro_api.enabled_read_operation_count', 1)
            ->assertJsonPath('messaging_settings.mikro_api.server_verified_read_operation_count', 3)
            ->assertJsonPath('messaging_settings.mikro_api.server_unverified_operation_count', 28)
            ->assertJsonPath('messaging_settings.mikro_api.write_operation_count', 11)
            ->assertJsonPath('messaging_settings.mikro_api.enabled_write_operation_count', 0)
            ->assertJsonPath('messaging_settings.mikro_api.write_enabled', false)
            ->assertJsonPath('messaging_settings.mikro_api.write_ready', false);

        $response = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/connection-test')
            ->assertStatus(409)
            ->assertJsonPath('mikro_connection.error_code', 'MIKRO_HEALTH_CONFIGURATION_MISSING')
            ->assertJsonPath('mikro_connection.success', false)
            ->json();

        $this->assertSame(['MIKRO_BASE_URL_MISSING'], $response['blocker_codes']);
        Http::assertNothingSent();
    }

    public function test_secretless_health_check_uses_only_safe_origin_and_keeps_execution_toggles_off(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => [
                    'enabled' => false,
                    'read_sync_enabled' => false,
                    'write_enabled' => false,
                    'base_url' => 'https://mikro-health.example.test',
                    'api_version' => 'V17',
                    'timeout_seconds' => 5,
                    'user_code' => 'PRIMEAPI',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.health_configuration_ready', true)
            ->assertJsonPath('messaging_settings.mikro_api.live_credentials_ready', false)
            ->assertJsonPath('messaging_settings.mikro_api.user_code', 'PRIMEAPI');

        Http::fake([
            'https://mikro-health.example.test/Api/APIMethods/HealthCheck' => Http::response(['result' => ['200']], 200),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/connection-test')
            ->assertOk()
            ->assertJsonPath('mikro_connection.operation_key', 'health.check')
            ->assertJsonPath('mikro_connection.success', true)
            ->assertJsonPath('messaging_settings.mikro_api.private_network_ready', true)
            ->assertJsonPath('messaging_settings.mikro_api.health_ready', true)
            ->assertJsonPath('messaging_settings.mikro_api.live_credentials_ready', false)
            ->assertJsonPath('messaging_settings.mikro_api.authenticated_read_ready', false)
            ->assertJsonPath('messaging_settings.mikro_api.enabled', false)
            ->assertJsonPath('messaging_settings.mikro_api.read_sync_enabled', false)
            ->assertJsonPath('messaging_settings.mikro_api.write_enabled', false);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://mikro-health.example.test/Api/APIMethods/HealthCheck'
            && $request->data() === []
            && ! $request->hasHeader('Authorization')
            && ! $request->hasHeader('ApiKey'));
    }

    public function test_secretless_health_server_failure_is_safely_mapped_without_enabling_reads(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => [
                    'base_url' => 'https://mikro-health.example.test',
                    'api_version' => 'V17',
                    'timeout_seconds' => 5,
                ],
            ])
            ->assertOk();
        Http::fake([
            'https://mikro-health.example.test/Api/APIMethods/HealthCheck' => Http::response(['error' => 'unavailable'], 500),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/connection-test')
            ->assertStatus(503)
            ->assertJsonPath('mikro_connection.error_code', 'MIKRO_SERVER_ERROR')
            ->assertJsonPath('mikro_connection.attempt_count', 1)
            ->assertJsonPath('messaging_settings.mikro_api.last_health_check_status', 'failed')
            ->assertJsonPath('messaging_settings.mikro_api.last_error_redacted', 'MIKRO_SERVER_ERROR')
            ->assertJsonPath('messaging_settings.mikro_api.enabled', false)
            ->assertJsonPath('messaging_settings.mikro_api.read_sync_enabled', false)
            ->assertJsonPath('messaging_settings.mikro_api.write_enabled', false);

        Http::assertSentCount(1);
    }

    public function test_authenticated_canary_requires_secrets_before_operation_or_network_evaluation(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => [
                    'enabled' => true,
                    'read_sync_enabled' => true,
                    'base_url' => 'https://mikro-auth.example.test',
                    'api_version' => 'V17',
                    'application_code' => 'EMAKS-PANEL-TEST',
                    'company_code' => 'TEST-FIRM',
                    'fiscal_year' => '2026',
                    'user_code' => 'PRIMEAPI',
                    'timeout_seconds' => 5,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.health_configuration_ready', true)
            ->assertJsonPath('messaging_settings.mikro_api.live_configuration_ready', false)
            ->assertJsonPath('messaging_settings.mikro_api.authenticated_canary_allowed', false);

        $result = app(MikroApiClient::class)->userParameters();

        $this->assertSame('MIKRO_LIVE_CONFIGURATION_MISSING', $result['error_code']);
        $this->assertSame(0, $result['attempt_count']);
        Http::assertNothingSent();
    }

    public function test_canary_can_run_four_allowlisted_reads_with_global_switches_off(): void
    {
        $secrets = $this->configureCanaryContract();
        $orderGuid = '123e4567-e89b-42d3-a456-426614174000';
        TechnicalServiceRequestSerial::query()->create([
            'serial_number' => 'CANARY-SERIAL-001',
            'stock_code' => 'CANARY-STOCK-001',
            'is_primary' => true,
            'is_returned' => false,
            'is_current_latest_sale' => true,
        ]);
        $rowCountBefore = TechnicalServiceRequestSerial::query()->count();
        $registry = app(MikroOperationRegistry::class);
        $serialBefore = $registry->read('serial.lookup');
        $origin = 'https://mikro-api.example.test';
        $runtimeState = app(MikroRuntimeState::class);
        $circuitsBefore = collect(['customer.detail', 'stock.availability', 'serial.lookup', 'order.detail'])
            ->mapWithKeys(fn (string $key): array => [$key => $runtimeState->circuit($origin, $key)])
            ->all();

        Http::fake(function (Request $request) use ($orderGuid) {
            $sql = (string) data_get($request->data(), 'SQLSorgu', '');
            $extra = [
                'api_key' => 'UNKNOWN_RESPONSE_SECRET',
                'password' => 'UNKNOWN_RESPONSE_PASSWORD',
                'token' => 'UNKNOWN_RESPONSE_TOKEN',
                'unexpected_nested' => ['secret' => 'UNKNOWN_NESTED_SECRET'],
            ];

            return match (true) {
                str_contains($sql, 'CIHAZ_HAREKETLERI') => $this->fixedQueryResponse([[
                    'serial_number' => 'CANARY-SERIAL-001',
                    'movement_guid' => '223e4567-e89b-42d3-a456-426614174000',
                    'movement_date' => '2026-07-29',
                    'stock_code' => 'CANARY-STOCK-001',
                    'customer_code' => 'CANARY-CUSTOMER-001',
                    'order_guid' => '{'.$orderGuid.'}',
                    'invoice_guid' => null,
                    ...$extra,
                ]]),
                str_contains($sql, 'CARI_HESAPLAR') => $this->fixedQueryResponse([[
                    'customer_code' => 'CANARY-CUSTOMER-001',
                    'title' => 'Canary Customer',
                    'title_2' => null,
                    'group_code' => 'TEST',
                    'representative_code' => 'REP',
                    ...$extra,
                ]]),
                str_contains($sql, 'fn_DepodakiMiktar') => $this->fixedQueryResponse([[
                    'stock_code' => 'CANARY-STOCK-001',
                    'depot_1_quantity' => 2,
                    'depot_5_quantity' => 3,
                    'available_quantity' => 5,
                    ...$extra,
                ]]),
                str_contains($sql, 'SIPARISLER') => $this->fixedQueryResponse([[
                    'order_guid' => $orderGuid,
                    'order_date' => '2026-07-29',
                    'document_series' => 'TEST',
                    'document_number' => 1,
                    'customer_code' => 'CANARY-CUSTOMER-001',
                    'representative_code' => 'REP',
                    'description' => 'Canary order',
                    ...$extra,
                ]]),
                default => Http::response(['error' => 'unexpected query'], 500),
            };
        });

        $payload = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/authenticated-read-canary')
            ->assertJsonPath('mikro_canaries.blocker_codes', [])
            ->assertJsonPath('mikro_canaries.success', true)
            ->assertOk()
            ->assertJsonPath('mikro_canaries.mikro_read_count', 4)
            ->assertJsonPath('mikro_canaries.mikro_write_count', 0)
            ->assertJsonPath('mikro_canaries.business_db_write_count', 0)
            ->assertJsonPath('mikro_canaries.source_mode_delta', 0)
            ->assertJsonPath('mikro_canaries.runtime_enabled_delta', 0)
            ->assertJsonPath('mikro_canaries.global_switches.enabled', false)
            ->assertJsonPath('mikro_canaries.global_switches.read_sync_enabled', false)
            ->assertJsonPath('mikro_canaries.global_switches.write_enabled', false)
            ->json();

        $this->assertSame(
            ['customer.lookup', 'stock.availability', 'serial.lookup', 'order.detail'],
            array_keys($payload['mikro_canaries']['operations']),
        );
        foreach ($payload['mikro_canaries']['operations'] as $operation) {
            $this->assertTrue($operation['success']);
            $this->assertSame('PASS', $operation['schema_validation']);
            $this->assertFalse($operation['runtime_state_mutated']);
            $this->assertFalse($operation['source_mode_mutated']);
            $this->assertArrayNotHasKey('data', $operation);
            $this->assertArrayNotHasKey('normalized_data', $operation);
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([...array_values($secrets), 'UNKNOWN_RESPONSE_SECRET', 'UNKNOWN_RESPONSE_PASSWORD', 'UNKNOWN_RESPONSE_TOKEN', 'UNKNOWN_NESTED_SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertSame($rowCountBefore, TechnicalServiceRequestSerial::query()->count());
        $this->assertSame($serialBefore, $registry->read('serial.lookup'));
        $this->assertSame($circuitsBefore, collect(array_keys($circuitsBefore))
            ->mapWithKeys(fn (string $key): array => [$key => $runtimeState->circuit($origin, $key)])
            ->all());
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === $origin.'/Api/apiMethods/SqlVeriOkuV2'
            && is_string(data_get($request->data(), 'SQLSorgu'))
            && filled(data_get($request->data(), 'Mikro.ApiKey'))
            && filled(data_get($request->data(), 'Mikro.Sifre')));
        Http::assertSent(fn (Request $request): bool => str_contains(
            (string) data_get($request->data(), 'SQLSorgu', ''),
            "CONVERT(uniqueidentifier, '{$orderGuid}')",
        ));
    }

    public function test_canary_allows_only_explicit_reads_and_rejects_invalid_order_guid_before_network(): void
    {
        $this->configureCanaryContract();
        $client = app(MikroApiClient::class);

        foreach (['invented.operation', 'customer.save', 'order.list'] as $operationKey) {
            $result = $client->authenticatedReadCanary($operationKey, []);
            $this->assertFalse($result['success']);
            $this->assertSame(MikroOperationRegistry::BLOCKED_CANARY_OPERATION, $result['error_code']);
            $this->assertSame(0, $result['attempt_count']);
        }

        $invalidGuid = $client->authenticatedReadCanary('order.detail', ['order_guid' => 'ORDER-NUMBER-IS-NOT-A-GUID']);
        $this->assertFalse($invalidGuid['success']);
        $this->assertSame('MIKRO_QUERY_PARAMETER_INVALID', $invalidGuid['error_code']);
        $this->assertSame(0, $invalidGuid['attempt_count']);
        Http::assertNothingSent();
    }

    public function test_canary_route_uses_the_same_health_gate_as_the_settings_payload(): void
    {
        $this->configureLiveContract();
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => [
                    'enabled' => false,
                    'read_sync_enabled' => false,
                    'write_enabled' => false,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.authenticated_canary_allowed', false)
            ->assertJsonPath('messaging_settings.mikro_api.authenticated_canary_blocker_codes.0', 'MIKRO_PRIVATE_HEALTH_NOT_READY');

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/authenticated-read-canary')
            ->assertStatus(409)
            ->assertJsonPath('blocker_codes.0', 'MIKRO_PRIVATE_HEALTH_NOT_READY');

        Http::assertNothingSent();
    }

    public function test_sample_bootstrap_requires_a_real_order_guid_without_business_writes(): void
    {
        $this->configureCanaryContract();
        TechnicalServiceRequestSerial::query()->create([
            'serial_number' => 'CANARY-SERIAL-WITHOUT-ORDER',
            'stock_code' => 'CANARY-STOCK-001',
            'is_primary' => true,
            'is_returned' => false,
            'is_current_latest_sale' => true,
        ]);
        $rowCountBefore = TechnicalServiceRequestSerial::query()->count();

        Http::fake([
            '*' => $this->fixedQueryResponse([[
                'serial_number' => 'CANARY-SERIAL-WITHOUT-ORDER',
                'stock_code' => 'CANARY-STOCK-001',
                'customer_code' => 'CANARY-CUSTOMER-001',
                'order_guid' => 'ORDER-NUMBER',
            ]]),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/authenticated-read-canary')
            ->assertStatus(503)
            ->assertJsonPath('mikro_canaries.success', false)
            ->assertJsonPath('mikro_canaries.blocker_codes.0', 'MIKRO_CANARY_SAMPLE_WITH_ORDER_GUID_NOT_FOUND')
            ->assertJsonPath('mikro_canaries.business_db_write_count', 0)
            ->assertJsonPath('mikro_canaries.mikro_write_count', 0);

        $this->assertSame($rowCountBefore, TechnicalServiceRequestSerial::query()->count());
        Http::assertSentCount(1);
    }

    public function test_health_check_uses_exact_get_contract_and_redacts_credentials(): void
    {
        $secrets = $this->configureLiveContract();
        Log::spy();
        Http::fake([
            'https://mikro-api.example.test/Api/APIMethods/HealthCheck' => Http::response(['result' => ['200']], 200),
        ]);

        $payload = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/connection-test')
            ->assertOk()
            ->assertJsonPath('mikro_connection.operation_key', 'health.check')
            ->assertJsonPath('mikro_connection.success', true)
            ->assertJsonPath('mikro_connection.status', 200)
            ->assertJsonPath('mikro_connection.result_count', 1)
            ->assertJsonPath('mikro_connection.normalized_data.service_status', 'UP')
            ->json();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([$secrets['api_key'], $secrets['password']] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://mikro-api.example.test/Api/APIMethods/HealthCheck'
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('X-Correlation-ID'));
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    public function test_customer_and_stock_request_contracts_are_typed_without_network_execution(): void
    {
        $secrets = ['api_key' => 'MIKRO_TEST_API_KEY_ONLY', 'user_code' => 'MIKRO_TEST_USER_ONLY', 'password' => 'MIKRO_TEST_PASSWORD_ONLY'];
        $client = app(MikroApiClient::class);
        $registry = app(MikroOperationRegistry::class);
        $method = new ReflectionMethod($client, 'requestPayload');
        $context = [
            'api_key' => $secrets['api_key'],
            'working_year' => '2026',
            'firm_code' => 'TEST-FIRM',
            'user_code' => $secrets['user_code'],
            'password' => $secrets['password'],
            'server_timezone' => 'Europe/Istanbul',
        ];
        $customers = $method->invoke($client, $registry->read('customer.list'), ['CariKod' => 'TEST-CARI', 'Size' => '5', 'Index' => 0], $context);
        $stocks = $method->invoke($client, $registry->read('stock.list'), ['StokKod' => 'TEST-STOK'], $context);
        $signature = md5('2026-07-29 '.$secrets['password']);

        $this->assertSame(['ApiKey' => $secrets['api_key'], 'CalismaYili' => '2026', 'FirmaKodu' => 'TEST-FIRM', 'KullaniciKodu' => $secrets['user_code'], 'Sifre' => $signature], $customers['Mikro']);
        $this->assertSame('TEST-CARI', $customers['CariKod']);
        $this->assertSame('5', $customers['Size']);
        $this->assertSame('TEST-STOK', $stocks['StokKod']);
        $this->assertSame($signature, $stocks['Mikro']['Sifre']);
        Http::assertNothingSent();
    }

    public function test_documented_direct_endpoints_keep_exact_authority_and_nested_payload_contracts(): void
    {
        $registry = app(MikroOperationRegistry::class);
        foreach ([
            'user.parameters' => '/Api/APIMethods/KullaniciParametreleriV2',
            'user.list' => '/Api/APIMethods/KullaniciListesiV2',
            'invoice.pdf' => '/API/APIMethods/FaturaPdfV2',
            'dispatch.pdf' => '/API/APIMethods/EIrsaliyePdfV2',
            'edocument.status' => '/API/APIMethods/EBelgeDurumSorgulamaV2',
            'etaxpayer.check' => '/API/APIMethods/EMukellefSorgulamaV2',
        ] as $operationKey => $path) {
            $operation = $registry->read($operationKey);
            $this->assertSame($path, $operation['endpoint']);
            $this->assertSame('DOCUMENTED_SERVER_UNVERIFIED', $operation['evidence_status']);
            $this->assertFalse($operation['runtime_eligible']);
            $this->assertFalse($operation['runtime_enabled']);
        }

        $guid = '123e4567-e89b-42d3-a456-426614174000';
        $client = app(MikroApiClient::class);
        $method = new ReflectionMethod($client, 'requestPayload');
        $context = ['api_key' => 'KEY', 'working_year' => '2026', 'firm_code' => 'FIRM', 'user_code' => 'USER', 'password' => 'PASS', 'server_timezone' => 'Europe/Istanbul'];
        $dispatch = $method->invoke($client, $registry->read('dispatch.pdf'), ['EFaturaTipi' => 1, 'Id' => $guid], $context);
        $status = $method->invoke($client, $registry->read('edocument.status'), ['EFaturaTipi' => 1, 'EBelgeTipi' => 2, 'UUID' => $guid], $context);

        $this->assertSame('KEY', $dispatch['Mikro']['Apikey']);
        $this->assertArrayNotHasKey('ApiKey', $dispatch['Mikro']);
        $this->assertSame(1, $dispatch['Mikro']['EFaturaTipi']);
        $this->assertSame($guid, $dispatch['Mikro']['Id']);
        $this->assertSame(['EFaturaTipi' => 1, 'EBelgeTipi' => 2, 'UUID' => $guid], $status['Mikro']['EBelge']);
        Http::assertNothingSent();
    }

    public function test_fixed_query_contracts_are_immutable_documented_and_server_unverified(): void
    {
        $guid = '123e4567-e89b-42d3-a456-426614174000';
        $queries = app(MikroFixedQueryCatalog::class);
        $registry = app(MikroOperationRegistry::class);
        $orderSql = $queries->render('order.lines', ['order_guid' => $guid, 'limit' => 10]);
        $serialSql = $queries->render('serial.history', ['serial_number' => 'SERIAL-001', 'limit' => 10]);
        $operation = $registry->read('order.lines');

        $this->assertStringContainsString('sth.sth_sip_uid = sip.sip_Guid', $orderSql);
        $this->assertStringContainsString('sth.sth_Guid = ch.ChHar_master_uid', $serialSql);
        $this->assertSame('/Api/apiMethods/SqlVeriOkuV2', $operation['endpoint']);
        $this->assertStringContainsString('SERVER_CANARY_PENDING', $operation['exact_path_casing']);
        $this->assertFalse($operation['runtime_enabled']);
        $this->assertNotEmpty($operation['v17_table_evidence']);
        Http::assertNothingSent();
    }

    public function test_authenticated_parity_probe_uses_isolated_query_without_changing_runtime_state_or_source_mode(): void
    {
        $this->configureCanaryContract();
        Http::fake([
            'https://mikro-api.example.test/Api/apiMethods/SqlVeriOkuV2' => $this->fixedQueryResponse([[
                'record_id' => '123e4567-e89b-42d3-a456-426614174000',
                'item_code' => 'STOK-001',
                'warehouse_code' => 5,
                'unit_name' => 'ADET',
                'on_hand_quantity' => '12.500000',
                'serial_tracking_code' => 3,
                'item_active_flag' => 1,
                'source_updated_at' => '2026-07-31T10:00:00',
            ]]),
        ]);

        $result = app(MikroApiClient::class)->authenticatedParityRead(
            MikroParitySource::STOCK_DETAIL,
            ['item_code' => 'STOK-001', 'warehouse_code' => 5, 'as_of_date' => '2026-07-31'],
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['canary']);
        $this->assertFalse($result['runtime_state_mutated']);
        $this->assertFalse($result['source_mode_mutated']);
        $this->assertSame('CONTRACT_FIELD_UNAVAILABLE', $result['data'][0]['status']);
        $this->assertSame('12.5', $result['data'][0]['envelope']['on_hand_quantity']);
        $this->assertArrayNotHasKey('available_quantity', $result['data'][0]['envelope']);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $sql = (string) data_get($request->data(), 'SQLSorgu', '');

            return $request->url() === 'https://mikro-api.example.test/Api/apiMethods/SqlVeriOkuV2'
                && str_contains($sql, 'AS on_hand_quantity')
                && str_contains($sql, ' 5 AS warehouse_code')
                && ! str_contains($sql, 'AS available_quantity');
        });
    }

    public function test_transient_read_retries_are_bounded_and_last_good_is_explicit(): void
    {
        $this->configureLiveContract();
        $client = app(MikroApiClient::class);
        $attempt = 0;
        $correlation = null;
        Http::fake(function (Request $request) use (&$attempt, &$correlation) {
            $attempt++;
            if ($attempt === 1) {
                return Http::response(['Result' => [['cari_kod' => 'TEST-CARI']]]);
            }
            $current = $request->header('X-Correlation-ID')[0] ?? null;
            $correlation ??= $current;
            $this->assertSame($correlation, $current);

            return Http::response(['error' => 'temporary'], 503);
        });
        $first = $client->healthCheck();
        $fallback = $client->healthCheck();

        $this->assertTrue($first['success']);
        $this->assertTrue($fallback['success']);
        $this->assertTrue($fallback['stale']);
        $this->assertTrue($fallback['fallback_used']);
        $this->assertSame('MIKRO_SERVER_ERROR', $fallback['error_code']);
        $this->assertSame(2, $fallback['attempt_count']);
        $this->assertSame($first['data'], $fallback['data']);
        $readiness = app(TechnicalServiceMessagingSettingsService::class)
            ->recordMikroHealthCheckResult($fallback)['mikro_api'];
        $this->assertFalse($readiness['health_ready']);
        $this->assertSame('failed', $readiness['last_health_check_status']);
        $this->assertSame('MIKRO_SERVER_ERROR', $readiness['last_error_redacted']);
        Http::assertSentCount(3);
    }

    public function test_timeout_and_connection_failure_are_controlled_and_bounded(): void
    {
        $this->configureLiveContract();
        $messages = ['Operation timed out', 'Operation timed out', 'Connection refused', 'Connection refused'];
        Http::fake(function () use (&$messages) {
            return Http::failedConnection(array_shift($messages) ?? 'Connection refused');
        });

        $client = app(MikroApiClient::class);
        $timeout = $client->healthCheck();
        $connection = $client->healthCheck();

        $this->assertSame('MIKRO_REQUEST_TIMEOUT', $timeout['error_code']);
        $this->assertSame(2, $timeout['attempt_count']);
        $this->assertSame('MIKRO_CONNECTION_FAILED', $connection['error_code']);
        $this->assertSame(2, $connection['attempt_count']);
        Http::assertSentCount(4);
    }

    public function test_unverified_business_operations_fail_before_network_even_with_live_configuration(): void
    {
        $this->configureLiveContract();
        $client = app(MikroApiClient::class);

        $auth = $client->listCustomers('TEST');
        $server = $client->listStocks('TEST');
        $fixed = $client->orderLines('123e4567-e89b-42d3-a456-426614174000', 10);

        foreach ([$auth] as $result) {
            $this->assertSame(MikroOperationRegistry::BLOCKED_RESPONSE_SCHEMA, $result['error_code']);
            $this->assertSame(0, $result['attempt_count']);
            $this->assertFalse($result['fallback_used']);
        }
        $this->assertSame(MikroOperationRegistry::BLOCKED_DISABLED, $server['error_code']);
        $this->assertSame(0, $server['attempt_count']);
        $this->assertFalse($server['fallback_used']);
        $this->assertSame(MikroOperationRegistry::BLOCKED_SERVER_CANARY, $fixed['error_code']);
        $this->assertSame(0, $fixed['attempt_count']);
        $this->assertFalse($fixed['fallback_used']);
        Http::assertNothingSent();
    }

    public function test_stock_list_contract_discovery_never_persists_raw_payload_and_redacts_credentials(): void
    {
        $evidence = MikroContractEvidenceCatalog::for('stock.list', 'READ', 'DIRECT_ENDPOINT');
        $encoded = json_encode($evidence, JSON_THROW_ON_ERROR);

        $this->assertSame('OFFICIAL_AND_SERVER_VERIFIED', $evidence['evidence_status']);
        $this->assertSame('PASS_3_BOUNDED_HTTP_200_STABLE_WRAPPER_2026-08-14', $evidence['installed_server_canary']);
        $this->assertSame('df832a6ade1c421c7decd0aa69ede26ba0abcfd1f5c0cc3f9178d3e24c0fdf6c', $evidence['evidence_hash']);
        $this->assertStringNotContainsString('raw_response_body', $encoded);
        $this->assertStringNotContainsString('raw_payload', $encoded);
        $this->assertStringNotContainsString('password_value', $encoded);
        $this->assertStringNotContainsString('api_key_value', $encoded);
        Http::assertNothingSent();
    }

    public function test_stock_list_schema_matches_installed_server_fingerprint(): void
    {
        $schema = app(MikroResponseSchemaCatalog::class)->descriptor('stock.list');

        $this->assertSame(MikroResponseSchemaCatalog::VERIFIED, $schema['schema_status']);
        $this->assertSame(MikroResponseSchemaCatalog::STOCK_LIST_CONTRACT_VERSION, $schema['contract_version']);
        $this->assertSame('$.result[].Data.StokListesi[]', $schema['collection_path']);
        $this->assertSame(MikroResponseSchemaCatalog::STOCK_LIST_RESPONSE_SCHEMA_FINGERPRINT, $schema['response_schema_fingerprint']);
        $this->assertSame(MikroResponseSchemaCatalog::STOCK_LIST_NOT_FOUND_FINGERPRINT, $schema['not_found_schema_fingerprint']);
        $this->assertSame(['sto_kod', 'sto_isim'], $schema['required_record_fields']);
        $this->assertSame(['item_code', 'item_name', 'unit_code'], $schema['normalized_fields']);
        Http::assertNothingSent();
    }

    public function test_stock_list_normalizer_drops_unknown_fields_and_preserves_null_without_inventing_values(): void
    {
        $schemas = app(MikroResponseSchemaCatalog::class);
        $normalized = $schemas->normalize('stock.list', [[
            'StatusCode' => 200,
            'Data' => ['StokListesi' => [[
                'sto_kod' => 'STOK-001',
                'sto_isim' => 'Gercek Parca',
                'sto_birim1_ad' => null,
                'sto_min_stok' => 25,
                'sto_toptan_vergi' => 20,
                'warehouse_code' => 'SHOULD-DROP',
                'available' => 999,
                'api_key' => 'SHOULD-DROP',
            ]]],
            'ErrorMessage' => '',
            'IsError' => false,
        ]]);

        $this->assertSame([[
            'item_code' => 'STOK-001',
            'item_name' => 'Gercek Parca',
            'unit_code' => null,
        ]], $normalized);
        $this->assertArrayNotHasKey('available', $normalized[0]);
        $this->assertArrayNotHasKey('vat_rate', $normalized[0]);
        $this->assertSame([], $schemas->normalize('stock.list', [[
            'StatusCode' => 200,
            'Data' => null,
            'ErrorMessage' => '',
            'IsError' => false,
        ]]));
        Http::assertNothingSent();
    }

    public function test_stock_list_requires_item_identity(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('MIKRO_INVALID_RESPONSE');

        app(MikroResponseSchemaCatalog::class)->normalize('stock.list', [[
            'StatusCode' => 200,
            'Data' => ['StokListesi' => [[
                'sto_isim' => 'Kodsuz Parca',
                'sto_birim1_ad' => 'ADET',
            ]]],
            'ErrorMessage' => '',
            'IsError' => false,
        ]]);
    }

    public function test_stock_list_runtime_enablement_requires_verified_schema_and_performs_zero_write(): void
    {
        $secrets = $this->configureLiveContract();
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => [
                    'enabled' => true,
                    'read_sync_enabled' => false,
                    'write_enabled' => false,
                    'operation_controls' => [
                        'stock.list' => ['runtime_enabled' => true, 'source_mode' => 'mikro'],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.read_sync_enabled', false)
            ->assertJsonPath('messaging_settings.mikro_api.write_enabled', false);

        Http::fake([
            'https://mikro-api.example.test/Api/APIMethods/StokListesiV2' => Http::response([
                'result' => [[
                    'StatusCode' => 200,
                    'Data' => ['StokListesi' => [[
                        'sto_kod' => 'STOK-002',
                        'sto_isim' => 'Typed Mikro Parca',
                        'sto_birim1_ad' => 'ADET',
                        'sto_pasif_fl' => false,
                        'sto_detay_takip' => 0,
                        'unexpected_secret' => 'DROP-ME',
                    ]]],
                    'ErrorMessage' => '',
                    'IsError' => false,
                ]],
            ], 200),
        ]);

        $result = app(MikroApiClient::class)->listStocks('STOK-002', size: 1);

        $this->assertTrue($result['success']);
        $this->assertSame([[
            'item_code' => 'STOK-002',
            'item_name' => 'Typed Mikro Parca',
            'unit_code' => 'ADET',
        ]], $result['data']);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secrets['api_key'], $encoded);
        $this->assertStringNotContainsString($secrets['password'], $encoded);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mikro-api.example.test/Api/APIMethods/StokListesiV2'
            && $request->method() === 'POST'
            && $request['StokKod'] === 'STOK-002'
            && $request['Size'] === '1');
        $this->assertFalse(app(MikroOperationRegistry::class)->read('stock.availability')['runtime_enabled']);
        $this->assertFalse(app(MikroOperationRegistry::class)->read('serial.lookup')['runtime_enabled']);
    }

    public function test_stock_search_schema_is_typed_and_drops_unknown_fields(): void
    {
        $schemas = app(MikroResponseSchemaCatalog::class);
        $schema = $schemas->descriptor('stock.search');
        $normalized = $schemas->normalize('stock.search', [[
            'item_code' => 'TKN000009',
            'item_name' => 'DDL 720 DIŞ DOKUMATİK',
            'item_short_name' => null,
            'unit_code' => 'ADET',
            'stock_type' => 8,
            'detail_tracking_type' => 0,
            'cancelled' => 0,
            'hidden' => 0,
            'available' => 999,
            'vat_rate' => 20,
            'api_key' => 'DROP-ME',
        ]]);

        $this->assertSame(MikroResponseSchemaCatalog::VERIFIED, $schema['schema_status']);
        $this->assertSame(MikroResponseSchemaCatalog::STOCK_SEARCH_CONTRACT_VERSION, $schema['contract_version']);
        $this->assertSame(MikroResponseSchemaCatalog::STOCK_SEARCH_RESPONSE_SCHEMA_FINGERPRINT, $schema['response_schema_fingerprint']);
        $this->assertSame([[
            'item_code' => 'TKN000009',
            'item_name' => 'DDL 720 DIŞ DOKUMATİK',
            'item_short_name' => null,
            'unit_code' => 'ADET',
            'stock_type' => 8,
            'detail_tracking_type' => 0,
            'cancelled' => false,
            'hidden' => false,
        ]], $normalized);
        $this->assertArrayNotHasKey('available', $normalized[0]);
        $this->assertArrayNotHasKey('vat_rate', $normalized[0]);
        $this->assertSame($normalized, $schemas->sanitizeSnapshot('stock.search', $normalized));
        Http::assertNothingSent();
    }

    public function test_stock_search_uses_only_verified_fixed_query_and_performs_zero_write(): void
    {
        $secrets = $this->configureLiveContract();
        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => [
                    'enabled' => true,
                    'read_sync_enabled' => true,
                    'write_enabled' => false,
                    'operation_controls' => [
                        'stock.search' => ['runtime_enabled' => true, 'source_mode' => 'mikro'],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.write_enabled', false)
            ->assertJsonPath('messaging_settings.mikro_api.enabled_write_operation_count', 0);

        Http::fake([
            'https://mikro-api.example.test/Api/apiMethods/SqlVeriOkuV2' => $this->fixedQueryResponse([[
                'item_code' => 'TKN000009',
                'item_name' => 'DDL 720 DIŞ DOKUMATİK',
                'item_short_name' => 'DDL720',
                'unit_code' => 'ADET',
                'stock_type' => 8,
                'detail_tracking_type' => 0,
                'cancelled' => 0,
                'hidden' => 0,
                'unexpected_secret' => 'DROP-ME',
            ]]),
        ]);

        $result = app(MikroApiClient::class)->searchStocks('DIŞ DOKUMATİK');

        $this->assertTrue($result['success']);
        $this->assertSame('TKN000009', $result['data'][0]['item_code']);
        $this->assertSame(8, $result['data'][0]['stock_type']);
        $this->assertArrayNotHasKey('unexpected_secret', $result['data'][0]);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secrets['api_key'], $encoded);
        $this->assertStringNotContainsString($secrets['password'], $encoded);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $sql = (string) $request['SQLSorgu'];

            return $request->url() === 'https://mikro-api.example.test/Api/apiMethods/SqlVeriOkuV2'
                && $request->method() === 'POST'
                && str_contains($sql, 'SELECT TOP (20)')
                && str_contains($sql, "N'DIS DOKUMATIK'")
                && str_contains($sql, 'FROM dbo.STOKLAR')
                && ! str_contains($sql, '[[')
                && ! preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|EXEC)\b/i', $sql);
        });
        $this->assertFalse(app(MikroOperationRegistry::class)->read('stock.availability')['runtime_enabled']);
        $this->assertFalse(app(MikroOperationRegistry::class)->read('serial.lookup')['runtime_enabled']);
    }

    public function test_operation_schemas_drop_unknown_fields_and_re_sanitize_last_good_snapshots(): void
    {
        $schemas = app(MikroResponseSchemaCatalog::class);
        $runtime = app(MikroRuntimeState::class);
        $raw = [[
            'invoice_guid' => '123e4567-e89b-42d3-a456-426614174000',
            'amount' => 125.50,
            'customer_phone' => '+905551112233',
            'tax_number' => '1111111111',
            'email' => 'secret@example.test',
            'address' => 'secret-address',
            'api_key' => 'SECRET-API-KEY',
            'password' => 'SECRET-PASSWORD',
            'token' => 'SECRET-TOKEN',
            'internal_note' => 'secret-note',
            'unexpected_nested' => ['secret' => 'nested-secret'],
        ]];

        $normalized = $schemas->normalize('invoice.list', $raw);
        $this->assertSame([[
            'invoice_guid' => '123e4567-e89b-42d3-a456-426614174000',
            'amount' => 125.50,
        ]], $normalized);

        $runtime->storeLastGood(
            'invoice.list',
            ['date_from' => '2026-07-01', 'date_to' => '2026-07-31'],
            $raw,
            'mikro',
            '2026-07-29T14:00:00+03:00',
            '123e4567-e89b-42d3-a456-426614174000',
        );
        $snapshot = $runtime->lastGood('invoice.list', ['date_to' => '2026-07-31', 'date_from' => '2026-07-01']);
        $this->assertSame($normalized, $snapshot['data']);

        $encoded = json_encode([$normalized, $snapshot], JSON_THROW_ON_ERROR);
        foreach (['customer_phone', 'tax_number', 'email', 'address', 'api_key', 'password', 'token', 'internal_note', 'unexpected_nested', 'nested-secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }

        $this->assertSame(
            [['service_status' => 'UP']],
            $schemas->normalize('health.check', [['service_status' => 'UP', 'api_key' => 'SECRET']]),
        );

        try {
            $schemas->normalize('customer.list', [['cari_kod' => 'TEST', 'unexpected' => 'SECRET']]);
            $this->fail('An unverified direct response schema must not normalize data.');
        } catch (DomainException $exception) {
            $this->assertSame(MikroOperationRegistry::BLOCKED_RESPONSE_SCHEMA, $exception->getMessage());
        }
        try {
            $schemas->normalize('invoice.list', [['api_key' => 'SECRET', 'unexpected_nested' => ['secret' => 'VALUE']]]);
            $this->fail('A response with no allowlisted fields must be invalid.');
        } catch (DomainException $exception) {
            $this->assertSame('MIKRO_INVALID_RESPONSE', $exception->getMessage());
        }

        $response = new ClientResponse(new Psr7Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['Result' => ['not-a-record']], JSON_THROW_ON_ERROR),
        ));
        $successResult = new ReflectionMethod(app(MikroApiClient::class), 'successResult');
        $result = $successResult->invoke(
            app(MikroApiClient::class),
            'invoice.list',
            $response,
            '123e4567-e89b-42d3-a456-426614174000',
            microtime(true),
            1,
            'CLOSED',
        );
        $this->assertFalse($result['success']);
        $this->assertSame('MIKRO_INVALID_RESPONSE', $result['error_code']);
        Http::assertNothingSent();
    }

    public function test_unsafe_base_urls_fail_validation_before_network(): void
    {
        foreach (['https://api.example.com', 'https://user:secret@mikro-api.example.test', 'https://mikro-api.example.test/Api/APIMethods', 'https://mikro-api.example.test?operation=read'] as $baseUrl) {
            $this->actingAs($this->admin())
                ->patchJson('/api/technical-service/messaging-settings', ['mikro_api' => ['base_url' => $baseUrl]])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['mikro_api.base_url']);
        }

        Http::assertNothingSent();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function fixedQueryResponse(array $rows): mixed
    {
        return Http::response([
            'result' => [[
                'StatusCode' => 200,
                'Data' => [['SQLResult1' => $rows]],
                'ErrorMessage' => '',
                'IsError' => false,
            ]],
        ], 200);
    }

    /** @return array{api_key:string,user_code:string,password:string} */
    private function configureCanaryContract(): array
    {
        $secrets = $this->configureLiveContract();

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => [
                    'enabled' => false,
                    'read_sync_enabled' => false,
                    'write_enabled' => false,
                ],
            ])
            ->assertOk();

        app(TechnicalServiceMessagingSettingsService::class)->recordMikroHealthCheckResult([
            'success' => true,
            'stale' => false,
            'fallback_used' => false,
            'error_code' => null,
        ]);

        $this->actingAs($this->admin())
            ->getJson('/api/technical-service/messaging-settings')
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.authenticated_canary_allowed', true)
            ->assertJsonPath('messaging_settings.mikro_api.authenticated_canary_blocker_codes', [])
            ->assertJsonPath('messaging_settings.mikro_api.enabled', false)
            ->assertJsonPath('messaging_settings.mikro_api.read_sync_enabled', false)
            ->assertJsonPath('messaging_settings.mikro_api.write_enabled', false);

        return $secrets;
    }

    /** @return array{api_key:string,user_code:string,password:string} */
    private function configureLiveContract(): array
    {
        $secrets = ['api_key' => 'MIKRO_TEST_API_KEY_ONLY', 'user_code' => 'MIKRO_TEST_USER_ONLY', 'password' => 'MIKRO_TEST_PASSWORD_ONLY'];

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => [
                    'enabled' => true,
                    'read_sync_enabled' => true,
                    'write_enabled' => false,
                    'write_approval_required' => true,
                    'base_url' => 'https://mikro-api.example.test',
                    'api_version' => 'V17',
                    'application_code' => 'EMAKS-PANEL-TEST',
                    'company_code' => 'TEST-FIRM',
                    'fiscal_year' => '2026',
                    'user_code' => $secrets['user_code'],
                    'server_timezone' => 'Europe/Istanbul',
                    'timeout_seconds' => 5,
                    'operation_controls' => [
                        'order.lines' => ['source_mode' => 'shadow_compare'],
                        'serial.history' => ['source_mode' => 'shadow_compare'],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.write_enabled', false)
            ->assertJsonPath('messaging_settings.mikro_api.write_operation_count', 11)
            ->assertJsonPath('messaging_settings.mikro_api.enabled_write_operation_count', 0);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/credentials', [
                'api_key' => $secrets['api_key'],
                'password' => $secrets['password'],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.credentials_ready', true)
            ->assertJsonPath('messaging_settings.mikro_api.live_configuration_ready', true)
            ->assertJsonPath('messaging_settings.mikro_api.readiness_status', 'LIVE_CONNECTIVITY_PENDING');

        return $secrets;
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }
}
