<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Mikro\MikroApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function test_contract_readiness_blocks_missing_live_configuration_without_network(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/technical-service/messaging-settings')
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.contract_ready', true)
            ->assertJsonPath('messaging_settings.mikro_api.live_configuration_ready', false)
            ->assertJsonPath('messaging_settings.mikro_api.readiness_status', 'CONTRACT_READY')
            ->assertJsonPath('messaging_settings.mikro_api.read_operation_count', 32)
            ->assertJsonPath('messaging_settings.mikro_api.implemented_read_operation_count', 30)
            ->assertJsonPath('messaging_settings.mikro_api.write_operation_count', 11)
            ->assertJsonPath('messaging_settings.mikro_api.enabled_write_operation_count', 0)
            ->assertJsonPath('messaging_settings.mikro_api.write_enabled', false)
            ->assertJsonPath('messaging_settings.mikro_api.write_ready', false);

        $response = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/connection-test')
            ->assertStatus(409)
            ->assertJsonPath('mikro_connection.error_code', 'MIKRO_LIVE_CONFIGURATION_MISSING')
            ->assertJsonPath('mikro_connection.success', false)
            ->json();

        foreach (['MIKRO_BASE_URL_MISSING', 'MIKRO_API_KEY_MISSING', 'MIKRO_USER_CODE_MISSING', 'MIKRO_PASSWORD_MISSING', 'MIKRO_FIRM_CODE_MISSING', 'MIKRO_WORKING_YEAR_MISSING'] as $blocker) {
            $this->assertContains($blocker, $response['blocker_codes']);
        }
        Http::assertNothingSent();
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
        foreach ($secrets as $secret) {
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

    public function test_customer_and_stock_reads_use_daily_signature_and_exact_post_contracts(): void
    {
        $secrets = $this->configureLiveContract();
        Http::fake([
            'https://mikro-api.example.test/Api/APIMethods/CariListesiV3' => Http::response(['Result' => [['cari_kod' => 'TEST-CARI']]]),
            'https://mikro-api.example.test/Api/APIMethods/StokListesiV2' => Http::response(['Result' => [['sto_kod' => 'TEST-STOK']]]),
        ]);

        $client = app(MikroApiClient::class);
        $customers = $client->listCustomers('TEST-CARI', '', 2, '2026-01-01', '2026-01-31', '-cari_kod', 5, 0);
        $stocks = $client->listStocks('TEST-STOK', 2, '2026-01-01', '2026-01-31', '-sto_kod', 5, 0);
        $signature = md5('2026-07-29 '.$secrets['password']);

        $this->assertTrue($customers['success']);
        $this->assertSame(1, $customers['result_count']);
        $this->assertTrue($stocks['success']);
        $this->assertSame(1, $stocks['result_count']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://mikro-api.example.test/Api/APIMethods/CariListesiV3'
            && $request['Mikro'] === [
                'ApiKey' => $secrets['api_key'],
                'CalismaYili' => '2026',
                'FirmaKodu' => 'TEST-FIRM',
                'KullaniciKodu' => $secrets['user_code'],
                'Sifre' => $signature,
            ]
            && $request['CariKod'] === 'TEST-CARI'
            && $request['Size'] === '5'
            && $request['Index'] === 0);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mikro-api.example.test/Api/APIMethods/StokListesiV2'
            && $request['Mikro']['Sifre'] === $signature
            && $request['StokKod'] === 'TEST-STOK');
    }

    public function test_all_verified_direct_endpoints_use_exact_paths_and_nested_payload_contracts(): void
    {
        $this->configureLiveContract();
        Http::fake([
            'https://mikro-api.example.test/Api/APIMethods/KullaniciParametreleriV2' => Http::response(['Result' => [['FirmaKodu' => 'TEST-FIRM']]]),
            'https://mikro-api.example.test/Api/APIMethods/KullaniciListesiV2' => Http::response(['Result' => [['KullaniciKodu' => 'TEST']]]),
            'https://mikro-api.example.test/Api/APIMethods/FaturaPdfV2' => Http::response(['Result' => [['ContentType' => 'application/pdf']]]),
            'https://mikro-api.example.test/Api/APIMethods/EIrsaliyePdfV2' => Http::response(['Result' => [['ContentType' => 'application/pdf']]]),
            'https://mikro-api.example.test/Api/APIMethods/EBelgeDurumSorgulamaV2' => Http::response(['Result' => [['Durum' => 'OK']]]),
            'https://mikro-api.example.test/Api/APIMethods/EMukellefSorgulamaV2' => Http::response(['Result' => [['Mukellef' => true]]]),
        ]);
        $guid = '123e4567-e89b-42d3-a456-426614174000';
        $client = app(MikroApiClient::class);

        $this->assertTrue($client->userParameters()['success']);
        $this->assertTrue($client->listUsers()['success']);
        $this->assertTrue($client->invoicePdf($guid)['success']);
        $this->assertTrue($client->dispatchPdf(1, $guid)['success']);
        $this->assertTrue($client->eDocumentStatus(1, 2, $guid)['success']);
        $this->assertTrue($client->eTaxpayerCheck('1234567890')['success']);

        Http::assertSentCount(6);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mikro-api.example.test/Api/APIMethods/FaturaPdfV2'
            && $request['Mikro']['Fatura_Guid'] === $guid);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mikro-api.example.test/Api/APIMethods/EIrsaliyePdfV2'
            && $request['Mikro']['EFaturaTipi'] === 1
            && $request['Mikro']['Id'] === $guid);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mikro-api.example.test/Api/APIMethods/EBelgeDurumSorgulamaV2'
            && $request['Mikro']['EBelge'] === ['EFaturaTipi' => 1, 'EBelgeTipi' => 2, 'UUID' => $guid]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mikro-api.example.test/Api/APIMethods/EMukellefSorgulamaV2'
            && $request['Mikro']['EMukellef'] === ['VKN_TCKN' => '1234567890']);
    }

    public function test_fixed_query_reads_use_only_server_rendered_sql_contract(): void
    {
        $this->configureLiveContract();
        Http::fake([
            'https://mikro-api.example.test/Api/APIMethods/SqlVeriOkuV2' => Http::response(['Result' => [['ok' => true]]]),
        ]);
        $guid = '123e4567-e89b-42d3-a456-426614174000';
        $client = app(MikroApiClient::class);

        $this->assertTrue($client->orderLines($guid, 10)['success']);
        $this->assertTrue($client->serialHistory('SERIAL-001', 10)['success']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mikro-api.example.test/Api/APIMethods/SqlVeriOkuV2'
            && str_contains((string) $request['SQLSorgu'], 'sth.sth_sip_uid = sip.sip_Guid'));
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['SQLSorgu'], 'sth.sth_Guid = ch.ChHar_master_uid'));
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
        $first = $client->listCustomers('TEST-CARI', '', 0, '', '', '-cari_kod', 5, 0);
        $fallback = $client->listCustomers('TEST-CARI', '', 0, '', '', '-cari_kod', 5, 0);

        $this->assertTrue($first['success']);
        $this->assertTrue($fallback['success']);
        $this->assertTrue($fallback['stale']);
        $this->assertTrue($fallback['fallback_used']);
        $this->assertSame('MIKRO_SERVER_ERROR', $fallback['error_code']);
        $this->assertSame(2, $fallback['attempt_count']);
        $this->assertSame($first['data'], $fallback['data']);
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

    public function test_auth_tls_and_http_500_fail_without_blind_retry_or_stale_fallback(): void
    {
        $this->configureLiveContract();
        $responses = [
            Http::response(['error' => 'auth'], 401),
            Http::response(['error' => 'server'], 500),
            Http::failedConnection('TLS certificate verification failed'),
        ];
        Http::fake(function () use (&$responses) {
            return array_shift($responses);
        });
        $client = app(MikroApiClient::class);

        $auth = $client->listCustomers('TEST');
        $server = $client->listStocks('TEST');
        $tls = $client->healthCheck();

        $this->assertSame('MIKRO_AUTH_FAILED', $auth['error_code']);
        $this->assertSame('MIKRO_SERVER_ERROR', $server['error_code']);
        $this->assertSame('MIKRO_TLS_FAILED', $tls['error_code']);
        foreach ([$auth, $server, $tls] as $result) {
            $this->assertSame(1, $result['attempt_count']);
            $this->assertFalse($result['fallback_used']);
        }
        Http::assertSentCount(3);
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
            ->postJson('/api/technical-service/messaging-settings/mikro-api/credentials', $secrets)
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
