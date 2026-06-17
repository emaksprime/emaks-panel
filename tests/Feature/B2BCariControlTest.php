<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\Role;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use Database\Seeders\B2BPartnerPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class B2BCariControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_apply_does_not_write_when_dry_run(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->admin();
        $partnerCount = B2BPartner::query()->count();
        $technicianCount = TechnicalServiceTechnician::query()->count();
        $linkCount = B2BPartnerTechnician::query()->count();

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => true,
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                'sync_technician' => true,
                'geocode_mode' => 'auto',
                'candidates' => [[
                    'mikro_cari_kodu' => '320.CLG.FAZ1B.DRY-CLASS',
                    'display_name' => 'Dry Run Çilingir',
                    'phone' => '+905551235001',
                    'city' => 'İstanbul',
                    'address' => 'Dry Run Mahallesi No:1',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('items.0.partner_action', 'create_partner')
            ->assertJsonPath('items.0.technician_action', 'create_technician')
            ->assertJsonPath('items.0.link_action', 'ensure_partner_technician_link')
            ->assertJsonPath('items.0.geocode_plan.status', 'available');

        $this->assertSame($partnerCount, B2BPartner::query()->count());
        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
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
