<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Mikro\MikroApiClient;
use App\Services\Mikro\MikroRuntimeState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MikroOperationControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_full_sanitized_catalog_is_visible_without_sql_text_or_secret_material(): void
    {
        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/messaging-settings')
            ->assertOk()
            ->assertJsonCount(43, 'messaging_settings.mikro_api.operation_catalog.operations')
            ->assertJsonPath('messaging_settings.mikro_api.read_operation_count', 32)
            ->assertJsonPath('messaging_settings.mikro_api.write_operation_count', 11)
            ->assertJsonPath('messaging_settings.mikro_api.enabled_read_operation_count', 1)
            ->assertJsonPath('messaging_settings.mikro_api.enabled_write_operation_count', 0)
            ->json('messaging_settings.mikro_api');

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SELECT TOP', $encoded);
        $this->assertStringContainsString('SQLSorgu', $encoded);
        $this->assertStringNotContainsString('password_encrypted', $encoded);
        $this->assertStringNotContainsString('api_key_encrypted', $encoded);
    }

    public function test_operation_controls_persist_valid_source_mode_and_reject_unknown_modes(): void
    {
        $payload = $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => [
                    'operation_controls' => [
                        'customer.list' => ['runtime_enabled' => true, 'source_mode' => 'n8n'],
                        'customer.save' => ['runtime_enabled' => true],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.mikro_api.enabled_write_operation_count', 0)
            ->json();

        $this->assertFalse($payload['messaging_settings']['mikro_api']['operation_controls']['customer.list']['runtime_enabled']);
        $this->assertFalse($payload['messaging_settings']['mikro_api']['operation_controls']['customer.save']['runtime_enabled']);
        $this->assertSame('n8n', $payload['messaging_settings']['mikro_api']['operation_controls']['customer.list']['source_mode']);

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => ['operation_controls' => ['customer.list' => ['source_mode' => 'raw_proxy']]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mikro_api.operation_controls.customer.list.source_mode']);

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => ['operation_controls' => ['invented.operation' => ['runtime_enabled' => true]]],
            ])
            ->assertStatus(422);
    }

    public function test_master_and_per_operation_disabled_states_block_before_network(): void
    {
        $client = app(MikroApiClient::class);
        $masterBlocked = $client->listCustomers('TEST');
        $this->assertSame('MIKRO_DISABLED', $masterBlocked['error_code']);
        Http::assertNothingSent();

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'mikro_api' => [
                    'enabled' => true,
                    'read_sync_enabled' => true,
                    'operation_controls' => ['customer.list' => ['runtime_enabled' => false]],
                ],
            ])
            ->assertOk();

        $operationBlocked = $client->listCustomers('TEST');
        $this->assertSame('MIKRO_OPERATION_SERVER_CANARY_REQUIRED', $operationBlocked['error_code']);
        Http::assertNothingSent();
    }

    public function test_admin_can_reset_only_a_known_read_circuit(): void
    {
        $origin = 'https://mikro-api.example.test';
        $state = app(MikroRuntimeState::class);
        foreach (range(1, 3) as $_) {
            $state->recordTransientFailure($origin, 'customer.list');
        }
        $this->assertSame('OPEN', $state->circuit($origin, 'customer.list')['circuit_state']);

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', ['mikro_api' => ['base_url' => $origin]])
            ->assertOk();

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/operations/customer.list/circuit/reset')
            ->assertOk()
            ->assertJsonPath('operation_key', 'customer.list')
            ->assertJsonPath('circuit_state', 'CLOSED');

        $this->assertSame('CLOSED', $state->circuit($origin, 'customer.list')['circuit_state']);
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/mikro-api/operations/customer.save/circuit/reset')
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }
}
