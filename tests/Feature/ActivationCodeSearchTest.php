<?php

namespace Tests\Feature;

use App\Models\SupportActivationCode;
use App\Models\User;
use App\Services\SupportActivationCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ActivationCodeSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_activation_table_and_snapshot_exist(): void
    {
        $snapshot = $this->snapshot();

        $this->assertGreaterThan(0, $snapshot['source']['recordCount']);
        $this->assertGreaterThan(0, $snapshot['source']['suffixActivationCodeCount']);
        $this->assertGreaterThan(0, count($snapshot['records']));
        $this->assertSame($snapshot['source']['recordCount'], DB::table('panel.support_activation_codes')->count());
    }

    public function test_snapshot_uses_serial_suffix_as_activation_code(): void
    {
        $record = collect($this->snapshot()['records'])
            ->firstWhere('serial_number', 'W720FWS03E250621A01809-275023');

        $this->assertIsArray($record);
        $this->assertSame('W720FWS03E250621A01809', $record['serial_number_clean']);
        $this->assertSame('A01809', $record['search_code']);
        $this->assertSame('275023', $record['activation_code']);
        $this->assertNotSame($record['search_code'], $record['activation_code']);
    }

    public function test_search_matches_serial_clean_search_code_stock_name_and_activation_code(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $this->createRecord([
            'stock_code' => 'TEST-STK-001',
            'stock_name' => 'AAA Test Kilit',
            'serial_number' => 'W720FWS03E250621A01783-123456',
            'serial_number_clean' => 'W720FWS03E250621A01783',
            'search_code' => 'A01783',
            'activation_code' => '999999',
            'activation_link' => 'https://example.test/activate/123456',
        ]);

        foreach (['W720FWS03E250621A01783', 'A01783', 'AAA Test', '123456'] as $query) {
            $this->actingAs($user)
                ->getJson('/api/support/activation/search?query='.urlencode($query))
                ->assertOk()
                ->assertJsonPath('items.0.activation_code', '123456');
        }
    }

    public function test_snapshot_records_are_searchable_by_sheet_fields(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $record = collect($this->snapshot()['records'])
            ->first(fn (array $entry): bool => filled($entry['serial_number_clean'] ?? null)
                && filled($entry['search_code'] ?? null)
                && filled($entry['stock_name'] ?? null));

        $this->assertIsArray($record);

        foreach ([
            $record['serial_number_clean'],
            $record['search_code'],
            $record['activation_code'],
            explode(' ', (string) $record['stock_name'])[0],
        ] as $query) {
            $response = $this->actingAs($user)
                ->getJson('/api/support/activation/search?query='.urlencode((string) $query))
                ->assertOk()
                ->json();

            $this->assertGreaterThan(0, $response['count']);
        }
    }

    public function test_known_activation_code_from_serial_suffix_is_searchable(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);

        $response = $this->actingAs($user)
            ->getJson('/api/support/activation/search?query=275023')
            ->assertOk()
            ->json();

        $this->assertGreaterThan(0, $response['count']);
        $this->assertTrue(
            collect($response['items'])->contains(fn (array $item): bool => $item['activation_code'] === '275023'
                && $item['serial_number_clean'] === 'W720FWS03E250621A01809'
                && $item['search_code'] === 'A01809'),
        );
    }

    public function test_search_rejects_short_query(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->getJson('/api/support/activation/search?query=A')
            ->assertStatus(422)
            ->assertJsonValidationErrors('query')
            ->assertJsonPath('errors.query.0', 'En az 2 karakter girin.');

        $this->actingAs($user)
            ->getJson('/api/support/activation/search?query=')
            ->assertStatus(422);
    }

    public function test_support_activation_page_renders(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->get('/support/activation')
            ->assertOk();
    }

    public function test_support_activation_frontend_uses_support_api_not_old_technical_service_route(): void
    {
        $page = file_get_contents(resource_path('js/pages/panel/support.tsx'));
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertIsString($page);
        $this->assertIsString($routes);
        $this->assertStringContainsString('/api/support/activation/search', $page);
        $this->assertStringContainsString("Route::get('activation/search'", $routes);
        $this->assertStringNotContainsString('/activation-code-search', $page.$routes);
    }

    private function createRecord(array $record): SupportActivationCode
    {
        return SupportActivationCode::query()->create(
            app(SupportActivationCodeService::class)->buildRecordPayload($record),
        );
    }

    /**
     * @return array{source: array<string, mixed>, records: list<array<string, mixed>>}
     */
    private function snapshot(): array
    {
        $snapshot = json_decode(
            file_get_contents(database_path('data/support-activation-codes.json')) ?: '{}',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($snapshot);

        return $snapshot;
    }
}
