<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\DataSource;
use App\Models\Role;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use Database\Seeders\B2BPartnerPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class B2BLocksmithSyncTest extends TestCase
{
    use RefreshDatabase;

    private const N8N_GATEWAY_TEST_URLS = [
        'customer_detail' => 'https://n8n-gateway.example.test/webhook/customer-detail',
        'cari_bilgi_dashboard' => 'https://n8n-gateway.example.test/webhook/cari-bilgi-dashboard',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_cari_control_locksmith_apply_creates_partner_technician_and_link(): void
    {
        $this->seedB2BPartnerPermissions();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'selected_capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
                'sync_technician' => true,
                'geocode_mode' => 'none',
                'candidates' => [[
                    'mikro_cari_kodu' => '320.CLG.FAZ1B.CLASS',
                    'display_name' => 'Faz 1B Sınıf Çilingir',
                    'phone' => '+905551235002',
                    'city' => 'Manisa',
                    'district' => 'Yunusemre',
                    'address' => 'Test Mahallesi No:1',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.status', 'created')
            ->assertJsonPath('items.0.technician_sync.status', 'technician_created');

        $partner = B2BPartner::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.CLASS')->firstOrFail();
        $technician = TechnicalServiceTechnician::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.CLASS')->firstOrFail();
        $link = B2BPartnerTechnician::query()
            ->where('partner_id', $partner->id)
            ->where('technical_service_technician_id', $technician->id)
            ->firstOrFail();

        $this->assertEqualsCanonicalizing([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH], $partner->capabilityCodes());
        $this->assertSame('locksmith', $technician->technician_type);
        $this->assertSame('Manisa', $link->service_city);
        $this->assertTrue((bool) $link->needs_review);
        $this->assertCariControlGatewaySourceOrder(
            ['customer_detail', 'cari_bilgi_dashboard'],
            '320.CLG.FAZ1B.CLASS',
        );
        Http::assertSentCount(2);
    }

    public function test_cari_control_apply_is_idempotent_by_cari_code(): void
    {
        $this->seedB2BPartnerPermissions();
        $admin = $this->admin();
        $payload = [
            'action' => 'import',
            'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'sync_technician' => true,
            'geocode_mode' => 'none',
            'candidates' => [[
                'mikro_cari_kodu' => '320.CLG.FAZ1B.CLASS-IDEMP',
                'display_name' => 'Sınıf İdempotent Çilingir',
                'phone' => '+905551235003',
                'city' => 'Ankara',
                'address' => 'İdempotent Sokak No:3',
            ]],
        ];

        $this->actingAs($admin)->postJson('/api/b2b/cari-control/apply', $payload)->assertOk();
        $this->actingAs($admin)->postJson('/api/b2b/cari-control/apply', $payload)->assertOk();

        $this->assertSame(1, B2BPartner::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.CLASS-IDEMP')->count());
        $this->assertSame(1, TechnicalServiceTechnician::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.CLASS-IDEMP')->count());

        $partner = B2BPartner::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.CLASS-IDEMP')->firstOrFail();
        $technician = TechnicalServiceTechnician::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.CLASS-IDEMP')->firstOrFail();

        $this->assertSame(1, B2BPartnerTechnician::query()
            ->where('partner_id', $partner->id)
            ->where('technical_service_technician_id', $technician->id)
            ->count());
        $this->assertCariControlGatewaySourceOrder(
            ['customer_detail', 'cari_bilgi_dashboard', 'customer_detail', 'cari_bilgi_dashboard'],
            '320.CLG.FAZ1B.CLASS-IDEMP',
        );
        Http::assertSentCount(4);
    }

    public function test_apply_auto_geocode_writes_lat_lng_when_quality_ok(): void
    {
        $this->seedB2BPartnerPermissions();
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Manisa Organize Sanayi Bölgesi, Yunusemre/Manisa, Türkiye',
                    'geometry' => [
                        'location_type' => 'ROOFTOP',
                        'location' => ['lat' => 38.619099, 'lng' => 27.428921],
                    ],
                ]],
            ], 200),
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                'sync_technician' => true,
                'geocode_mode' => 'auto',
                'candidates' => [[
                    'mikro_cari_kodu' => '320.CLG.FAZ1B.CLASS-GEO',
                    'display_name' => 'Sınıf Geocode Çilingir',
                    'phone' => '+905551235004',
                    'city' => 'Manisa',
                    'district' => 'Yunusemre',
                    'address' => 'Organize Sanayi Bölgesi',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.technician_sync.geocode.status', 'ok')
            ->assertJsonPath('items.0.technician_sync.needs_review', false);

        $technician = TechnicalServiceTechnician::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.CLASS-GEO')->firstOrFail();
        $this->assertEquals('38.6190990', $technician->latitude);
        $this->assertEquals('27.4289210', $technician->longitude);
        $this->assertFalse((bool) $technician->needs_review);
        $this->assertCariControlGatewaySourceOrder(
            ['customer_detail', 'cari_bilgi_dashboard'],
            '320.CLG.FAZ1B.CLASS-GEO',
        );
        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://maps.googleapis.com/maps/api/geocode/json?',
        ) && $request->method() === 'GET');
        Http::assertSentCount(3);
    }

    private function seedB2BPartnerPermissions(): void
    {
        (new B2BPartnerPermissionSeeder)->run();

        foreach (array_keys(self::N8N_GATEWAY_TEST_URLS) as $sourceCode) {
            $this->dataSource($sourceCode);
        }

        $emptyResponse = Http::response([
            'ok' => true,
            'rows' => [],
        ]);

        Http::fake([
            self::N8N_GATEWAY_TEST_URLS['customer_detail'] => $this->validatedN8nGatewayResponse('customer_detail', $emptyResponse),
            self::N8N_GATEWAY_TEST_URLS['cari_bilgi_dashboard'] => $this->validatedN8nGatewayResponse('cari_bilgi_dashboard', $emptyResponse),
        ]);
    }

    private function dataSource(string $code): DataSource
    {
        $endpointUrl = self::N8N_GATEWAY_TEST_URLS[$code]
            ?? throw new \InvalidArgumentException('Unexpected test data source ['.$code.'].');

        return DataSource::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => 'Test '.$code,
                'db_type' => 'n8n_json',
                'query_template' => 'SELECT 1',
                'allowed_params' => ['search', 'scope_key', 'customer_scope_key', 'page', 'limit', 'bypass_cache'],
                'connection_meta' => [
                    'endpoint_url' => $endpointUrl,
                    'response_rows_key' => 'rows',
                    'timeout_seconds' => 10,
                ],
                'preview_payload' => [],
                'active' => true,
            ],
        );
    }

    private function validatedN8nGatewayResponse(string $sourceCode, mixed $response): \Closure
    {
        return function (Request $request) use ($sourceCode, $response): mixed {
            $this->assertSame(self::N8N_GATEWAY_TEST_URLS[$sourceCode], $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));
            $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));
            $this->assertSame($sourceCode, $request['source_code'] ?? null);
            $this->assertSame('all', $request['scope_key'] ?? null);
            $this->assertSame('bayi_proje', $request['params']['customer_scope_key'] ?? null);
            $this->assertSame(10, $request['limit'] ?? null);
            $this->assertSame(1, $request['params']['page'] ?? null);
            $this->assertTrue((bool) ($request['bypass_cache'] ?? false));

            return $response;
        };
    }

    /**
     * @param  array<int, string>  $expectedSourceCodes
     */
    private function assertCariControlGatewaySourceOrder(array $expectedSourceCodes, string $expectedCariCode): void
    {
        $requests = Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_starts_with($request->url(), 'https://n8n-gateway.example.test/'))
            ->values();

        $this->assertCount(count($expectedSourceCodes), $requests);
        $this->assertSame(
            $expectedSourceCodes,
            $requests->map(fn (Request $request): mixed => $request['source_code'] ?? null)->all(),
        );

        foreach ($requests as $request) {
            $this->assertSame($expectedCariCode, $request['search'] ?? null);
            $this->assertSame($expectedCariCode, $request['customer_filter'] ?? null);
            $this->assertSame($expectedCariCode, $request['cari_filter'] ?? null);
            $this->assertSame($expectedCariCode, $request['params']['search'] ?? null);
            $this->assertSame('all', $request['params']['scope_key'] ?? null);
            $this->assertTrue((bool) ($request['params']['bypass_cache'] ?? false));
        }
    }

    private function admin(): User
    {
        Role::query()->updateOrCreate(
            ['code' => 'admin'],
            ['name' => 'Admin', 'is_super_admin' => true],
        );

        return User::factory()->create(['role_code' => 'admin']);
    }
}
