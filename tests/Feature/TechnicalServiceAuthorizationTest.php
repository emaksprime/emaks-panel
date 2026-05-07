<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningsPeriod;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Models\UserAccess;
use App\Services\TechnicalService\MikroSerialNumberService;
use App\Services\TechnicalService\WarrantyService;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TechnicalServiceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PanelMetadataSeeder::class);
    }

    public function test_basic_technical_service_user_only_sees_base_screen(): void
    {
        $user = $this->userWithAccess(['technical_service']);

        $this->actingAs($user)->get('/technical-service')->assertOk();
        $this->actingAs($user)->get('/technical-service/dashboard')->assertForbidden();
        $this->actingAs($user)->get('/technical-service/serial-query')->assertForbidden();
        $this->actingAs($user)->get('/technical-service/technicians')->assertForbidden();
        $this->actingAs($user)->get('/technical-service/earnings')->assertForbidden();
        $this->actingAs($user)->get('/technical-service/admin')->assertForbidden();

        $this->actingAs($user)->getJson('/api/technical-service/requests')->assertOk();
        $this->actingAs($user)->getJson('/api/technical-service/summary')->assertOk();
        $this->actingAs($user)->postJson('/api/technical-service/requests', [])->assertForbidden();
    }

    public function test_technical_role_has_operational_technical_service_access_without_earnings_or_admin(): void
    {
        $user = User::factory()->create(['role_code' => 'technical']);

        $this->actingAs($user)->get('/technical-service')->assertOk();
        $this->actingAs($user)->get('/technical-service/dashboard')->assertOk();
        $this->actingAs($user)->get('/technical-service/serial-query')->assertOk();
        $this->actingAs($user)->get('/technical-service/technicians')->assertOk();

        $this->actingAs($user)->get('/technical-service/earnings')->assertForbidden();
        $this->actingAs($user)->get('/technical-service/admin')->assertForbidden();
    }

    public function test_dashboard_permission_only_allows_dashboard_screen_and_api(): void
    {
        $user = $this->userWithAccess(['technical_service_dashboard']);

        $this->actingAs($user)->get('/technical-service/dashboard')->assertOk();
        $this->actingAs($user)->getJson('/api/technical-service/operations-dashboard')->assertOk();

        $this->actingAs($user)->get('/technical-service')->assertForbidden();
        $this->actingAs($user)->get('/technical-service/technicians')->assertForbidden();
        $this->actingAs($user)->get('/technical-service/earnings')->assertForbidden();
        $this->actingAs($user)->get('/technical-service/admin')->assertForbidden();
    }

    public function test_technician_permission_allows_technician_screen_and_crud_but_not_earnings_payment(): void
    {
        $user = $this->userWithAccess(['technical_service_technicians']);
        $earning = $this->earning();

        $this->actingAs($user)->get('/technical-service/technicians')->assertOk();
        $this->actingAs($user)->getJson('/api/technical-service/technicians')->assertOk();

        $createResponse = $this->actingAs($user)->postJson('/api/technical-service/technicians', [
            'first_name' => 'Ada',
            'last_name' => 'Usta',
            'phone' => '5551112233',
            'city' => 'Adana',
            'district' => 'Seyhan',
        ])->assertCreated();
        $technicianId = $createResponse->json('technician.id');

        $this->actingAs($user)->patchJson("/api/technical-service/technicians/{$technicianId}", [
            'first_name' => 'Ada',
            'last_name' => 'Teknik',
            'active' => true,
        ])->assertOk();

        $csv = UploadedFile::fake()->createWithContent(
            'technicians.csv',
            'first_name,last_name,phone,city,district,address,google_plus_code,google_formatted_address,default_start_address,default_start_plus_code,mikro_cari_kodu,mikro_cari_adi,note,active'."\n",
        );
        $this->actingAs($user)->post('/api/technical-service/technicians/import', ['file' => $csv])->assertOk();

        $this->actingAs($user)->deleteJson("/api/technical-service/technicians/{$technicianId}")->assertOk();
        $this->actingAs($user)->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid")->assertForbidden();
    }

    public function test_earnings_permission_allows_earnings_api_but_not_mark_paid(): void
    {
        $user = $this->userWithAccess(['technical_service_earnings']);

        $this->actingAs($user)->get('/technical-service/earnings')->assertOk();
        $this->actingAs($user)->postJson('/api/technical-service/earnings/periods/calculate', [
            'year' => 2026,
            'month' => 5,
        ])->assertOk();
        $earning = $this->earning();

        $this->actingAs($user)->getJson('/api/technical-service/earnings?year=2026&month=5')->assertOk();
        $this->actingAs($user)->getJson("/api/technical-service/earnings/{$earning->id}")->assertOk();
        $this->actingAs($user)->patchJson("/api/technical-service/earnings/{$earning->id}", [
            'status' => 'İtirazlı',
            'dispute_note' => 'Kontrol',
        ])->assertOk();
        $this->actingAs($user)->getJson("/api/technical-service/earnings/{$earning->id}/whatsapp-text")->assertOk();
        $this->actingAs($user)->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid")->assertForbidden();
    }

    public function test_earnings_payment_permission_allows_mark_paid_without_showing_earnings_screen(): void
    {
        $user = $this->userWithAccess(['technical_service_earnings_pay']);
        $earning = $this->earning();

        $this->actingAs($user)->get('/technical-service/earnings')->assertForbidden();
        $this->actingAs($user)->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid")->assertOk();
    }

    public function test_serial_query_permission_controls_mikro_and_warranty_apis(): void
    {
        $user = $this->userWithAccess(['technical_service_serial_query']);
        $this->mock(MikroSerialNumberService::class, function ($mock): void {
            $mock->shouldReceive('history')->andReturn([
                'decision' => ['found' => false],
                'events' => [],
            ]);
        });
        $this->mock(WarrantyService::class, function ($mock): void {
            $mock->shouldReceive('statusForSerial')->andReturn([
                'serial_no' => 'SN-1',
                'status' => 'Garanti Başlamadı',
            ]);
        });

        $this->actingAs($user)->get('/technical-service/serial-query')->assertOk();
        $this->actingAs($user)->getJson('/api/technical-service/mikro/serial-history?serial_no=SN-1')->assertOk();
        $this->actingAs($user)->getJson('/api/technical-service/warranty/serial?serial_no=SN-1')->assertOk();

        $blocked = $this->userWithAccess(['technical_service']);
        $this->actingAs($blocked)->getJson('/api/technical-service/mikro/serial-history?serial_no=SN-1')->assertForbidden();
    }

    public function test_admin_permission_exposes_technical_service_admin_page_and_navigation(): void
    {
        $user = $this->userWithAccess(['technical_service_admin']);

        $this->actingAs($user)->get('/technical-service/admin')->assertOk();

        $items = collect($this->actingAs($user)->getJson('/api/navigation')->assertOk()->json('groups'))
            ->flatMap(fn (array $group) => $group['items']);

        $this->assertContains('/technical-service/admin', $items->pluck('href'));
    }

    public function test_super_admin_passes_all_technical_service_routes(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $earning = $this->earning();

        $this->actingAs($admin)->get('/technical-service')->assertOk();
        $this->actingAs($admin)->get('/technical-service/dashboard')->assertOk();
        $this->actingAs($admin)->get('/technical-service/serial-query')->assertOk();
        $this->actingAs($admin)->get('/technical-service/technicians')->assertOk();
        $this->actingAs($admin)->get('/technical-service/earnings')->assertOk();
        $this->actingAs($admin)->get('/technical-service/admin')->assertOk();
        $this->actingAs($admin)->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid")->assertOk();
    }

    public function test_admin_users_resource_payload_groups_technical_service_resources(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $payload = $this->actingAs($admin)->getJson('/api/admin/users')->assertOk()->json();

        $resources = collect($payload['resources']);

        $this->assertSame('Teknik Servis', $resources->firstWhere('code', 'technical_service_admin')['group'] ?? null);
        $this->assertTrue($resources->pluck('code')->contains('technical_service_earnings_pay'));
        $this->assertSame('Teknik Servis', $resources->firstWhere('code', 'technical_service_serial_check')['group'] ?? null);
        $this->assertSame('Teknik Servis', $resources->firstWhere('code', 'technical_service_serial_history')['group'] ?? null);
        $this->assertSame('Teknik Servis', $resources->firstWhere('code', 'technical_service_warranty_serial')['group'] ?? null);
    }

    public function test_strict_access_writes_allowlist_and_denylist_for_technical_service_resources(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $user = User::factory()->create(['role_code' => 'viewer']);

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'id' => $user->id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'password' => '',
            'role_code' => 'viewer',
            'temsilci_kodu' => null,
            'aktif' => true,
            'force_password_change' => false,
            'access' => ['technical_service', 'technical_service_dashboard'],
            'denied_access' => [],
            'strict_access' => true,
        ])->assertOk();

        $this->assertDatabaseHas('panel.user_access', [
            'user_id' => $user->id,
            'resource_code' => 'technical_service_dashboard',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('panel.user_access', [
            'user_id' => $user->id,
            'resource_code' => 'technical_service_admin',
            'can_view' => false,
        ]);
        $this->assertDatabaseHas('panel.user_access', [
            'user_id' => $user->id,
            'resource_code' => 'technical_service_earnings_pay',
            'can_view' => false,
        ]);
    }

    /**
     * @param list<string> $resources
     */
    private function userWithAccess(array $resources): User
    {
        $user = User::factory()->create(['role_code' => 'viewer']);

        foreach ($resources as $resourceCode) {
            UserAccess::query()->create([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
                'can_view' => true,
            ]);
        }

        return $user;
    }

    private function earning(): TechnicalServiceEarning
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Ada Usta',
            'first_name' => 'Ada',
            'last_name' => 'Usta',
            'active' => true,
        ]);
        $period = TechnicalServiceEarningsPeriod::query()->firstOrCreate([
            'year' => 2026,
            'month' => 5,
        ], [
            'status' => 'draft',
        ]);

        return TechnicalServiceEarning::query()->create([
            'period_id' => $period->id,
            'technical_service_technician_id' => $technician->id,
            'technician_name_snapshot' => 'Ada Usta',
            'city_snapshot' => 'Adana',
            'job_count' => 1,
            'installation_count' => 1,
            'service_count' => 0,
            'labor_total' => 1000,
            'travel_fee_total' => 100,
            'travel_round_trip_km_total' => 10,
            'travel_billable_km_total' => 0,
            'grand_total' => 1100,
            'status' => 'Ödenecek',
        ]);
    }
}
