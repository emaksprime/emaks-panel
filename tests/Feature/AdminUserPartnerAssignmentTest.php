<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerAuditLog;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BPartnerUserAccess;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Models\UserAccess;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserPartnerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PanelMetadataSeeder::class);
    }

    public function test_user_can_have_multiple_partner_memberships_without_role_explosion(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $user = User::factory()->create(['role_code' => 'sales', 'aktif' => true]);
        $dealer = $this->partner('A4-DEALER', 'A4 Test Bayi', ['dealer']);
        $locksmith = $this->partner('A4-LOCK', 'A4 Test Çilingir', ['locksmith']);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$dealer->id}/users", $this->membershipPayload($user, 'Bayi Sorumlusu'))
            ->assertCreated();
        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$locksmith->id}/users", $this->membershipPayload($user, 'Çilingir Sorumlusu'))
            ->assertCreated();

        $this->assertSame(2, B2BPartnerUserProfile::query()->where('user_id', $user->id)->count());
        $this->assertSame('sales', $user->fresh()->role_code);

        $this->actingAs($admin)
            ->postJson('/api/admin/users', [
                'id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'role_code' => 'viewer',
                'aktif' => true,
                'force_password_change' => false,
                'access' => [],
                'denied_access' => [],
            ])
            ->assertOk();

        $this->assertSame(2, B2BPartnerUserProfile::query()->where('user_id', $user->id)->count());

        $this->actingAs($admin)
            ->deleteJson("/api/b2b/partners/{$locksmith->id}/users/{$user->id}")
            ->assertOk();

        $this->assertDatabaseHas('b2b_partner_user_profiles', [
            'user_id' => $user->id,
            'partner_id' => $dealer->id,
            'active' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_profiles', [
            'user_id' => $user->id,
            'partner_id' => $locksmith->id,
            'active' => false,
        ]);

        $memberships = collect($this->actingAs($admin)
            ->getJson('/api/admin/users?search='.urlencode($user->username))
            ->assertOk()
            ->json('users.0.partner_memberships'));
        $this->assertSame([$dealer->id, $locksmith->id], $memberships->pluck('partner_id')->sort()->values()->all());

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$dealer->id}/users", $this->membershipPayload($user, 'Tekrar'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('partner_id');

        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $locksmith->id,
            'user_id' => $admin->id,
            'action' => 'b2b.partner_user.revoked',
            'subject_id' => $user->id,
        ]);
    }

    public function test_technician_mapping_requires_an_active_same_partner_link_and_is_audited(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $user = User::factory()->create(['role_code' => 'b2b_locksmith']);
        $partnerA = $this->partner('A4-PA', 'A4 Partner A', ['dealer', 'locksmith']);
        $partnerB = $this->partner('A4-PB', 'A4 Partner B', ['locksmith']);
        $technicianA = $this->technician('A4 Usta A');
        $technicianB = $this->technician('A4 Usta B');
        $linkA = $this->technicianLink($partnerA, $technicianA, false);
        $this->technicianLink($partnerB, $technicianB, true);

        $wrongPartnerPayload = $this->membershipPayload($user, 'Usta Kullanıcısı');
        $wrongPartnerPayload['technical_service_technician_id'] = $technicianB->id;
        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partnerA->id}/users", $wrongPartnerPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('technical_service_technician_id');

        $inactivePayload = $this->membershipPayload($user, 'Usta Kullanıcısı');
        $inactivePayload['technical_service_technician_id'] = $technicianA->id;
        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partnerA->id}/users", $inactivePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('technical_service_technician_id');

        $linkA->forceFill(['active' => true, 'is_primary' => true])->save();
        $response = $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partnerA->id}/users", $inactivePayload)
            ->assertCreated()
            ->assertJsonPath('items.0.technical_service_technician_id', $technicianA->id)
            ->assertJsonPath('items.0.linked_technician.id', $technicianA->id)
            ->assertJsonPath('items.0.technician_mapping_valid', true);

        $profile = B2BPartnerUserProfile::query()
            ->where('partner_id', $partnerA->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        $this->assertSame($technicianA->id, $profile->metadata['technical_service_technician_id']);

        $payload = $this->membershipPayload($user, 'Usta Kullanıcısı');
        $payload['technical_service_technician_id'] = null;
        $this->actingAs($admin)
            ->patchJson("/api/b2b/partners/{$partnerA->id}/users/{$user->id}", $payload)
            ->assertOk()
            ->assertJsonPath('items.0.technical_service_technician_id', null);

        $this->assertArrayNotHasKey(
            'technical_service_technician_id',
            B2BPartnerUserProfile::query()->findOrFail($profile->id)->metadata ?? [],
        );
        $this->assertTrue(B2BPartnerAuditLog::query()
            ->where('partner_id', $partnerA->id)
            ->where('user_id', $admin->id)
            ->where('action', 'b2b.partner_user.profile_updated')
            ->exists());
        $this->assertNotEmpty($response->json('items'));
    }

    public function test_dealer_technician_links_enforce_duplicates_primary_and_region_updates(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $dealer = $this->partner('A4-DLINK', 'A4 Usta Bağlantı Bayisi', ['dealer']);
        $first = $this->technician('A4 Birinci Usta');
        $second = $this->technician('A4 İkinci Usta');

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$dealer->id}/technicians", [
                'technical_service_technician_id' => $first->id,
                'relationship_type' => 'contracted_technician',
                'priority' => 2,
                'service_city' => 'İstanbul',
                'service_district' => 'Kadıköy',
                'service_region_note' => 'A4 test bölgesi',
            ])
            ->assertCreated();
        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$dealer->id}/technicians", [
                'technical_service_technician_id' => $second->id,
                'relationship_type' => 'field_technician',
                'is_primary' => true,
                'priority' => 1,
            ])
            ->assertCreated();

        $firstLink = B2BPartnerTechnician::query()->where('partner_id', $dealer->id)->where('technical_service_technician_id', $first->id)->firstOrFail();
        $secondLink = B2BPartnerTechnician::query()->where('partner_id', $dealer->id)->where('technical_service_technician_id', $second->id)->firstOrFail();
        $this->assertFalse((bool) $firstLink->fresh()->is_primary);
        $this->assertTrue((bool) $secondLink->fresh()->is_primary);
        $this->assertSame(1, B2BPartnerTechnician::query()->where('partner_id', $dealer->id)->where('active', true)->where('is_primary', true)->count());

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$dealer->id}/technicians", [
                'technical_service_technician_id' => $first->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('technical_service_technician_id');

        $this->actingAs($admin)
            ->patchJson("/api/b2b/partners/{$dealer->id}/technicians/{$firstLink->id}", [
                'active' => true,
                'is_primary' => true,
                'relationship_type' => 'branch_technician',
                'priority' => 7,
                'service_city' => 'Ankara',
                'service_district' => 'Çankaya',
                'service_region_note' => 'İç Anadolu test bölgesi',
            ])
            ->assertOk();

        $firstLink->refresh();
        $this->assertTrue((bool) $firstLink->is_primary);
        $this->assertSame(7, $firstLink->priority);
        $this->assertSame('Ankara', $firstLink->service_city);
        $this->assertSame('Çankaya', $firstLink->service_district);
        $this->assertSame('İç Anadolu test bölgesi', $firstLink->service_region_note);
        $this->assertFalse((bool) $secondLink->fresh()->is_primary);

        $this->actingAs($admin)
            ->deleteJson("/api/b2b/partners/{$dealer->id}/technicians/{$firstLink->id}")
            ->assertOk();
        $this->assertFalse((bool) $firstLink->fresh()->active);
        $this->assertTrue((bool) $secondLink->fresh()->is_primary);
    }

    public function test_scoped_admin_cannot_assign_a_hidden_partner_or_see_its_membership(): void
    {
        $actor = User::factory()->create(['role_code' => 'viewer']);
        foreach (['admin_panel', 'user_admin', 'b2b.partner_users.manage'] as $resourceCode) {
            UserAccess::query()->create(['user_id' => $actor->id, 'resource_code' => $resourceCode, 'can_view' => true]);
        }
        $visible = $this->partner('A4-VISIBLE', 'A4 Görünür Partner', ['dealer']);
        $hidden = $this->partner('A4-HIDDEN', 'A4 Gizli Partner', ['locksmith']);
        foreach (['view', 'users'] as $scope) {
            B2BPartnerUserAccess::query()->create([
                'user_id' => $actor->id,
                'partner_id' => $visible->id,
                'access_scope' => $scope,
                'can_view' => true,
                'can_create' => false,
                'can_update' => $scope === 'users',
                'can_approve' => false,
            ]);
        }
        $target = User::factory()->create();
        B2BPartnerUserProfile::query()->create(['user_id' => $target->id, 'partner_id' => $hidden->id, 'active' => true]);

        $this->actingAs($actor)
            ->postJson("/api/b2b/partners/{$hidden->id}/users", $this->membershipPayload($target, 'Gizli'))
            ->assertForbidden();

        $payload = $this->actingAs($actor)->getJson('/api/admin/users')->assertOk()->json();
        $this->assertSame([$visible->id], collect($payload['partners'])->pluck('id')->all());
        $this->assertStringNotContainsString('A4 Gizli Partner', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function test_admin_users_partner_and_technician_ui_contract_is_compact_and_explicit(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/admin/AdminUsers.jsx')) ?: '';

        foreach ([
            'Partner Atamaları',
            'Partner ata',
            'Partner atamasını düzenle',
            'Bağlı Ustalar / Çilingirler',
            'Bayinin ustalarını bağla',
            'Kullanıcıyı usta profiline bağla',
            'Birincil usta yap',
            'Gelişmiş kapsamı göster',
            'Üyelik: {membership.active ?',
            'sistem otomatik yetki vermedi',
            'max-h-[calc(100vh-1rem)]',
            'overflow-y-auto',
        ] as $expected) {
            $this->assertStringContainsString($expected, $component);
        }

        $this->assertStringContainsString('technical_service_technician_id', $component);
        $this->assertStringContainsString('membershipScopesPayload', $component);
        $this->assertStringNotContainsString('dealer_locksmith_user', $component);
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function partner(string $code, string $name, array $capabilities): B2BPartner
    {
        $partner = B2BPartner::query()->create([
            'partner_type' => $capabilities[0],
            'partner_code' => $code,
            'display_name' => $name,
            'active' => true,
        ]);
        foreach ($capabilities as $capability) {
            B2BPartnerCapability::query()->create([
                'partner_id' => $partner->id,
                'capability' => $capability,
                'active' => true,
            ]);
        }

        return $partner;
    }

    private function technician(string $name): TechnicalServiceTechnician
    {
        return TechnicalServiceTechnician::query()->create([
            'name' => $name,
            'display_name' => $name,
            'active' => true,
        ]);
    }

    private function technicianLink(B2BPartner $partner, TechnicalServiceTechnician $technician, bool $active): B2BPartnerTechnician
    {
        return B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'contracted_technician',
            'is_primary' => $active,
            'active' => $active,
            'priority' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipPayload(User $user, string $title): array
    {
        return [
            'user_id' => $user->id,
            'title' => $title,
            'phone' => '905550000000',
            'active' => true,
            'scopes' => [[
                'access_scope' => 'view',
                'can_view' => true,
                'can_create' => false,
                'can_update' => false,
                'can_approve' => false,
            ]],
        ];
    }
}
