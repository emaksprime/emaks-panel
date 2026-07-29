<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\Role;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use Database\Seeders\B2BPartnerPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class B2BLocksmithSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_cari_control_locksmith_apply_creates_partner_technician_and_link(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
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
    }

    public function test_cari_control_apply_is_idempotent_by_cari_code(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
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
    }

    public function test_apply_auto_geocode_writes_lat_lng_when_quality_ok(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
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
