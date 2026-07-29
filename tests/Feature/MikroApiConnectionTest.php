<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Mikro\MikroApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MikroApiConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_contract_readiness_blocks_missing_live_configuration_without_network(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/technical-service/messaging-settings')
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.contract_ready', true)
            ->assertJsonPath('messaging_settings.mikro_api.live_configuration_ready', false)
            ->assertJsonPath('messaging_settings.mikro_api.readiness_status', 'CONTRACT_READY')
            ->assertJsonPath('messaging_settings.mikro_api.read_operation_count', 3)
            ->assertJsonPath('messaging_settings.mikro_api.write_operation_count', 0)
            ->assertJsonPath('messaging_settings.mikro_api.write_enabled', false)
            ->assertJsonPath('messaging_settings.mikro_api.write_ready', false);

        $response = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/connection-test')
            ->assertStatus(409)
            ->assertJsonPath('mikro_connection.error_code', 'MIKRO_LIVE_CONFIGURATION_MISSING')
            ->assertJsonPath('mikro_connection.success', false)
            ->json();

        $this->assertContains('MIKRO_BASE_URL_MISSING', $response['blocker_codes']);
        $this->assertContains('MIKRO_API_KEY_MISSING', $response['blocker_codes']);
        $this->assertContains('MIKRO_USER_CODE_MISSING', $response['blocker_codes']);
        $this->assertContains('MIKRO_PASSWORD_MISSING', $response['blocker_codes']);
        $this->assertContains('MIKRO_FIRM_CODE_MISSING', $response['blocker_codes']);
        $this->assertContains('MIKRO_WORKING_YEAR_MISSING', $response['blocker_codes']);
        Http::assertNothingSent();
    }

    public function test_health_check_uses_exact_get_contract_and_redacts_credentials(): void
    {
        $secrets = $this->configureLiveContract();
        Log::spy();
        Http::fake([
            'https://mikro-api.example.test/Api/APIMethods/HealthCheck' => Http::response(['status' => 'UP'], 200),
        ]);

        $payload = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/connection-test')
            ->assertOk()
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

    public function test_health_check_timeout_and_connection_failure_are_controlled(): void
    {
        $this->configureLiveContract();

        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            $message = $attempt++ === 0 ? 'Operation timed out' : 'Connection refused';

            return Http::failedConnection($message);
        });

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/connection-test')
            ->assertStatus(503)
            ->assertJsonPath('mikro_connection.error_code', 'MIKRO_TIMEOUT')
            ->assertJsonPath('mikro_connection.success', false);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/connection-test')
            ->assertStatus(503)
            ->assertJsonPath('mikro_connection.error_code', 'MIKRO_CONNECTION_FAILED')
            ->assertJsonPath('mikro_connection.success', false);
    }

    public function test_customer_and_stock_reads_use_exact_post_contracts(): void
    {
        $secrets = $this->configureLiveContract();
        Http::fake([
            'https://mikro-api.example.test/Api/APIMethods/CariListesiV3' => Http::response([
                'Result' => [['cari_kod' => 'TEST-CARI']],
            ]),
            'https://mikro-api.example.test/Api/APIMethods/StokListesiV2' => Http::response([
                'Result' => [['sto_kod' => 'TEST-STOK']],
            ]),
        ]);

        $client = app(MikroApiClient::class);
        $customers = $client->listCustomers('TEST-CARI', '', 2, '2026-01-01', '2026-01-31', '-cari_kod', 5, 0);
        $stocks = $client->listStocks('TEST-STOK', 2, '2026-01-01', '2026-01-31', '-sto_kod', 5, 0);

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
                'Sifre' => $secrets['password'],
            ]
            && $request['CariKod'] === 'TEST-CARI'
            && $request['Size'] === '5'
            && $request['Index'] === 0);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://mikro-api.example.test/Api/APIMethods/StokListesiV2'
            && $request['Mikro']['ApiKey'] === $secrets['api_key']
            && $request['StokKod'] === 'TEST-STOK'
            && $request['Size'] === '5'
            && $request['Index'] === 0);
    }

    public function test_unsafe_base_urls_fail_validation_before_network(): void
    {
        foreach ([
            'https://api.example.com',
            'https://user:secret@mikro-api.example.test',
            'https://mikro-api.example.test/Api/APIMethods',
            'https://mikro-api.example.test?operation=read',
        ] as $baseUrl) {
            $this->actingAs($this->admin())
                ->patchJson('/api/technical-service/messaging-settings', [
                    'mikro_api' => ['base_url' => $baseUrl],
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['mikro_api.base_url']);
        }

        Http::assertNothingSent();
    }

    /**
     * @return array{api_key:string,user_code:string,password:string}
     */
    private function configureLiveContract(): array
    {
        $secrets = [
            'api_key' => 'MIKRO_TEST_API_KEY_ONLY',
            'user_code' => 'MIKRO_TEST_USER_ONLY',
            'password' => 'MIKRO_TEST_PASSWORD_ONLY',
        ];

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => [
                    'enabled' => true,
                    'read_sync_enabled' => true,
                    'write_enabled' => true,
                    'write_approval_required' => true,
                    'base_url' => 'https://mikro-api.example.test',
                    'api_version' => 'V17',
                    'application_code' => 'EMAKS-PANEL-TEST',
                    'company_code' => 'TEST-FIRM',
                    'fiscal_year' => '2026',
                    'timeout_seconds' => 5,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.write_enabled', false)
            ->assertJsonPath('messaging_settings.mikro_api.write_operation_count', 0);

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
