<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerAuditLog;
use App\Models\B2B\B2BPartnerUserAccess;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\MenuGroup;
use App\Models\Page;
use App\Models\PageMenu;
use App\Models\Resource;
use App\Models\Role;
use App\Models\RoleResourcePermission;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\B2B\B2BPartnerAccessService;
use Database\Seeders\B2BPartnerPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class B2BPartnerPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_see_all_partners(): void
    {
        $admin = $this->userWithRole('admin', true);
        $dealer = $this->partner(['partner_type' => B2BPartner::TYPE_DEALER, 'display_name' => 'Ankara Bayi']);
        $locksmith = $this->partner(['partner_type' => B2BPartner::TYPE_LOCKSMITH, 'display_name' => 'Ankara Çilingir']);

        $this->actingAs($admin)
            ->getJson('/api/b2b/partners')
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.id', $dealer->id)
            ->assertJsonPath('items.1.id', $locksmith->id);
    }

    public function test_dealer_scoped_user_only_sees_assigned_dealer(): void
    {
        $user = $this->partnerUser();
        $visibleDealer = $this->partner(['partner_type' => B2BPartner::TYPE_DEALER, 'display_name' => 'Görünen Bayi']);
        $this->partner(['partner_type' => B2BPartner::TYPE_DEALER, 'display_name' => 'Gizli Bayi']);
        $this->partner(['partner_type' => B2BPartner::TYPE_LOCKSMITH, 'display_name' => 'Gizli Çilingir']);
        $this->grantPartnerAccess($user, $visibleDealer, 'view');

        $visibleIds = app(B2BPartnerAccessService::class)
            ->visiblePartnerQuery($user)
            ->pluck('id')
            ->all();

        $this->assertSame([$visibleDealer->id], $visibleIds);

        $this->actingAs($user)
            ->getJson('/api/b2b/partners?partner_type=dealer')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $visibleDealer->id);

        $this->actingAs($user)
            ->getJson('/api/b2b/partners?partner_type=locksmith')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_locksmith_scoped_user_only_sees_assigned_locksmith(): void
    {
        $user = $this->partnerUser();
        $this->partner(['partner_type' => B2BPartner::TYPE_DEALER, 'display_name' => 'Gizli Bayi']);
        $visibleLocksmith = $this->partner(['partner_type' => B2BPartner::TYPE_LOCKSMITH, 'display_name' => 'Görünen Çilingir']);
        $hiddenLocksmith = $this->partner(['partner_type' => B2BPartner::TYPE_LOCKSMITH, 'display_name' => 'Gizli Çilingir']);
        $this->grantPartnerAccess($user, $visibleLocksmith, 'technical_service');

        $this->actingAs($user)
            ->getJson('/api/b2b/partners')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $visibleLocksmith->id);

        $this->actingAs($user)
            ->getJson("/api/b2b/partners/{$hiddenLocksmith->id}")
            ->assertForbidden();
    }

    public function test_dealer_only_user_cannot_view_locksmith_detail_by_url_manipulation(): void
    {
        $user = $this->partnerUser();
        $dealer = $this->partner(['partner_type' => B2BPartner::TYPE_DEALER]);
        $locksmith = $this->partner(['partner_type' => B2BPartner::TYPE_LOCKSMITH]);
        $this->grantPartnerAccess($user, $dealer, 'view');

        $this->actingAs($user)
            ->getJson("/api/b2b/partners/{$locksmith->id}")
            ->assertForbidden();
    }

    public function test_panel_access_middleware_is_still_required_before_entity_scope(): void
    {
        $user = $this->userWithRole('partner_without_panel_access');
        $partner = $this->partner(['partner_type' => B2BPartner::TYPE_DEALER]);
        $this->grantPartnerAccess($user, $partner, 'view');

        $this->actingAs($user)
            ->getJson('/api/b2b/partners')
            ->assertForbidden();
    }

    public function test_dealer_resource_permission_can_enter_api_but_entity_scope_still_filters(): void
    {
        $user = $this->userWithRole('dealer_resource_user');
        (new B2BPartnerPermissionSeeder)->run();
        RoleResourcePermission::query()->create([
            'role_code' => 'dealer_resource_user',
            'resource_code' => 'b2b.dealers.view',
            'can_view' => true,
            'can_execute' => false,
        ]);
        $visibleDealer = $this->partner(['partner_type' => B2BPartner::TYPE_DEALER, 'display_name' => 'Açık Bayi']);
        $this->partner(['partner_type' => B2BPartner::TYPE_DEALER, 'display_name' => 'Kapalı Bayi']);
        $this->grantPartnerAccess($user, $visibleDealer, 'view');

        $this->actingAs($user)
            ->getJson('/api/b2b/partners?partner_type=dealer')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $visibleDealer->id);
    }

    public function test_locksmith_partner_can_link_to_technical_service_technician(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Çilingir Usta',
            'technician_type' => 'locksmith',
            'phone' => '+905551111111',
            'city' => 'İstanbul',
            'active' => true,
        ]);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'technical_service_technician_id' => $technician->id,
        ]);

        $this->assertTrue($partner->fresh()->technician->is($technician));

        $this->actingAs($admin)
            ->getJson("/api/b2b/partners/{$partner->id}")
            ->assertOk()
            ->assertJsonPath('partner.technical_service_technician_id', $technician->id)
            ->assertJsonPath('partner.linked_technician_name', 'Çilingir Usta');
    }

    public function test_partner_access_scope_abilities_are_enforced(): void
    {
        $user = $this->partnerUser();
        $partner = $this->partner();
        $this->grantPartnerAccess($user, $partner, 'manage', [
            'can_view' => true,
            'can_update' => true,
            'can_approve' => false,
        ]);
        $this->grantPartnerAccess($user, $partner, 'technical_service', [
            'can_view' => true,
            'can_approve' => true,
        ]);

        $service = app(B2BPartnerAccessService::class);

        $this->assertTrue($service->canViewPartner($user, $partner));
        $this->assertTrue($service->canManagePartner($user, $partner));
        $this->assertTrue($service->canAccessScope($user, $partner, 'manage', 'update'));
        $this->assertFalse($service->canAccessScope($user, $partner, 'manage', 'approve'));
        $this->assertTrue($service->canAccessScope($user, $partner, 'technical_service', 'approve'));
    }

    public function test_b2b_partner_user_relations_resolve_without_sqlite_foreign_keys(): void
    {
        $user = $this->partnerUser();
        $creator = $this->userWithRole('partner_creator');
        $partner = $this->partner(['partner_type' => B2BPartner::TYPE_LOCKSMITH]);

        $access = $this->grantPartnerAccess($user, $partner, 'technical_service', [
            'created_by' => $creator->id,
        ]);
        $profile = B2BPartnerUserProfile::query()->create([
            'user_id' => $user->id,
            'partner_id' => $partner->id,
            'title' => 'Servis Yetkilisi',
            'phone' => '+905551111112',
            'active' => true,
        ]);
        $auditLog = B2BPartnerAuditLog::query()->create([
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'action' => 'b2b.partner.test_relation',
            'subject_type' => B2BPartner::class,
            'subject_id' => $partner->id,
        ]);

        $this->assertInstanceOf(User::class, $access->user);
        $this->assertTrue($access->user->is($user));
        $this->assertInstanceOf(B2BPartner::class, $access->partner);
        $this->assertTrue($access->partner->is($partner));
        $this->assertInstanceOf(User::class, $access->creator);
        $this->assertTrue($access->creator->is($creator));

        $this->assertInstanceOf(User::class, $profile->user);
        $this->assertTrue($profile->user->is($user));
        $this->assertInstanceOf(B2BPartner::class, $profile->partner);
        $this->assertTrue($profile->partner->is($partner));

        $this->assertInstanceOf(User::class, $auditLog->user);
        $this->assertTrue($auditLog->user->is($user));
        $this->assertInstanceOf(B2BPartner::class, $auditLog->partner);
        $this->assertTrue($auditLog->partner->is($partner));
    }

    public function test_b2b_permission_seeder_is_idempotent(): void
    {
        $seeder = new B2BPartnerPermissionSeeder;

        $seeder->run();
        $seeder->run();

        $codes = [
            'b2b.view',
            'b2b.manage',
            'b2b.dealers.view',
            'b2b.dealers.manage',
            'b2b.locksmiths.view',
            'b2b.locksmiths.manage',
            'b2b.orders.view',
            'b2b.orders.manage',
            'b2b.stock.view',
            'b2b.finance.view',
            'b2b.technical_service.view',
            'b2b.partner_users.manage',
        ];

        $this->assertSame(count($codes), Resource::query()->whereIn('code', $codes)->count());
        $this->assertSame(1, Resource::query()->where('code', 'b2b.view')->count());
        $this->assertSame(1, Page::query()->where('code', 'b2b_partners')->count());
        $this->assertSame(1, MenuGroup::query()->where('code', 'b2b')->count());
        $this->assertSame(1, PageMenu::query()
            ->where('page_id', Page::query()->where('code', 'b2b_partners')->value('id'))
            ->count());
    }

    public function test_create_dealer_partner_works(): void
    {
        $admin = $this->userWithRole('admin', true);

        $this->actingAs($admin)
            ->postJson('/api/b2b/partners', $this->partnerPayload([
                'partner_type' => B2BPartner::TYPE_DEALER,
                'partner_code' => 'DEALER-CREATE-001',
                'display_name' => 'Yeni Bayi',
            ]))
            ->assertCreated()
            ->assertJsonPath('partner.partner_code', 'DEALER-CREATE-001')
            ->assertJsonPath('partner.partner_type', B2BPartner::TYPE_DEALER)
            ->assertJsonPath('partner.technical_service_technician_id', null);

        $this->assertDatabaseHas('b2b_partners', [
            'partner_code' => 'DEALER-CREATE-001',
            'partner_type' => B2BPartner::TYPE_DEALER,
        ]);
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'action' => 'b2b.partner.created',
        ]);
    }

    public function test_create_locksmith_partner_with_locksmith_technician_works(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician([
            'name' => 'Locksmith Partner Usta',
            'technician_type' => 'locksmith',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/b2b/partners', $this->partnerPayload([
                'partner_type' => B2BPartner::TYPE_LOCKSMITH,
                'partner_code' => 'LOCKSMITH-CREATE-001',
                'display_name' => 'Yeni Çilingir',
                'technical_service_technician_id' => $technician->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('partner.partner_type', B2BPartner::TYPE_LOCKSMITH)
            ->assertJsonPath('partner.technical_service_technician_id', $technician->id)
            ->assertJsonPath('partner.linked_technician_name', 'Locksmith Partner Usta');
    }

    public function test_create_dealer_with_technician_id_fails(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['technician_type' => 'locksmith']);

        $this->actingAs($admin)
            ->postJson('/api/b2b/partners', $this->partnerPayload([
                'partner_type' => B2BPartner::TYPE_DEALER,
                'partner_code' => 'DEALER-WITH-TECH',
                'technical_service_technician_id' => $technician->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('technical_service_technician_id');
    }

    public function test_create_locksmith_with_non_locksmith_technician_fails(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['technician_type' => 'technician']);

        $this->actingAs($admin)
            ->postJson('/api/b2b/partners', $this->partnerPayload([
                'partner_type' => B2BPartner::TYPE_LOCKSMITH,
                'partner_code' => 'LOCKSMITH-WITH-NON-LOCKSMITH',
                'technical_service_technician_id' => $technician->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('technical_service_technician_id');
    }

    public function test_update_partner_writes_audit_log(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner(['display_name' => 'Eski Ünvan']);

        $this->actingAs($admin)
            ->patchJson("/api/b2b/partners/{$partner->id}", $this->partnerPayload([
                'partner_type' => B2BPartner::TYPE_DEALER,
                'partner_code' => $partner->partner_code,
                'display_name' => 'Yeni Ünvan',
            ]))
            ->assertOk()
            ->assertJsonPath('partner.display_name', 'Yeni Ünvan');

        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'action' => 'b2b.partner.updated',
        ]);
    }

    public function test_active_toggle_writes_audit_log(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner(['active' => true]);

        $this->actingAs($admin)
            ->patchJson("/api/b2b/partners/{$partner->id}/active", ['active' => false])
            ->assertOk()
            ->assertJsonPath('partner.active', false);

        $this->assertDatabaseHas('b2b_partners', [
            'id' => $partner->id,
            'active' => false,
        ]);
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'action' => 'b2b.partner.active_changed',
        ]);
    }

    public function test_unauthorized_user_cannot_create_or_update_partner(): void
    {
        $user = $this->partnerUser();
        $partner = $this->partner();
        $this->grantPartnerAccess($user, $partner, 'manage', ['can_update' => true]);

        $this->actingAs($user)
            ->postJson('/api/b2b/partners', $this->partnerPayload(['partner_code' => 'DENIED-CREATE']))
            ->assertForbidden();

        $this->actingAs($user)
            ->patchJson("/api/b2b/partners/{$partner->id}", $this->partnerPayload(['partner_code' => $partner->partner_code]))
            ->assertForbidden();
    }

    public function test_scoped_user_cannot_update_unscoped_partner(): void
    {
        $user = $this->userWithRole('b2b_manager');
        $this->grantPanelResource('b2b_manager', 'b2b.manage');
        $visiblePartner = $this->partner(['display_name' => 'Visible']);
        $hiddenPartner = $this->partner(['display_name' => 'Hidden']);
        $this->grantPartnerAccess($user, $visiblePartner, 'manage', [
            'can_view' => true,
            'can_update' => true,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/b2b/partners/{$hiddenPartner->id}", $this->partnerPayload([
                'partner_code' => $hiddenPartner->partner_code,
            ]))
            ->assertForbidden();
    }

    public function test_locksmith_technician_lookup_only_returns_active_locksmiths(): void
    {
        $admin = $this->userWithRole('admin', true);
        $activeLocksmith = $this->technician([
            'name' => 'Aktif Çilingir',
            'technician_type' => 'locksmith',
            'active' => true,
        ]);
        $this->technician([
            'name' => 'Pasif Çilingir',
            'technician_type' => 'locksmith',
            'active' => false,
        ]);
        $this->technician([
            'name' => 'Normal Teknisyen',
            'technician_type' => 'technician',
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/locksmith-technicians?search=Çilingir')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $activeLocksmith->id)
            ->assertJsonPath('items.0.source_key', 'technical_service_technician:'.$activeLocksmith->id);
    }

    public function test_partner_list_filters_search_type_active_and_city(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'display_name' => 'Ankara Filtre Bayi',
            'partner_code' => 'FILTER-DEALER',
            'city' => 'Ankara',
            'active' => true,
        ]);
        $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'display_name' => 'İstanbul Filtre Çilingir',
            'partner_code' => 'FILTER-LOCKSMITH',
            'city' => 'İstanbul',
            'active' => true,
        ]);
        $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'display_name' => 'Pasif Bayi',
            'partner_code' => 'FILTER-PASSIVE',
            'city' => 'Ankara',
            'active' => false,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/partners?partner_type=dealer&active=1&city=Ankara&search=Filtre')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.partner_code', 'FILTER-DEALER');
    }

    private function partnerUser(): User
    {
        $user = $this->userWithRole('partner_user');
        (new B2BPartnerPermissionSeeder)->run();

        RoleResourcePermission::query()->create([
            'role_code' => 'partner_user',
            'resource_code' => 'b2b.view',
            'can_view' => true,
            'can_execute' => false,
        ]);

        return $user;
    }

    private function userWithRole(string $roleCode, bool $superAdmin = false): User
    {
        Role::query()->updateOrCreate(
            ['code' => $roleCode],
            [
                'name' => str_replace('_', ' ', $roleCode),
                'description' => null,
                'is_super_admin' => $superAdmin,
            ],
        );

        return User::factory()->create(['role_code' => $roleCode]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function partner(array $attributes = []): B2BPartner
    {
        $sequence = B2BPartner::query()->count() + 1;
        $type = $attributes['partner_type'] ?? B2BPartner::TYPE_DEALER;

        return B2BPartner::query()->create(array_merge([
            'partner_type' => $type,
            'partner_code' => sprintf('%s-%03d', strtoupper((string) $type), $sequence),
            'display_name' => 'Test Partner '.$sequence,
            'mikro_cari_kodu' => 'CR-'.$sequence,
            'mikro_cari_unvan' => 'Test Cari '.$sequence,
            'city' => 'İstanbul',
            'district' => 'Kadıköy',
            'active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function grantPartnerAccess(User $user, B2BPartner $partner, string $scope, array $attributes = []): B2BPartnerUserAccess
    {
        return B2BPartnerUserAccess::query()->create(array_merge([
            'user_id' => $user->id,
            'partner_id' => $partner->id,
            'access_scope' => $scope,
            'can_view' => true,
            'can_create' => false,
            'can_update' => false,
            'can_approve' => false,
        ], $attributes));
    }

    private function grantPanelResource(string $roleCode, string $resourceCode): void
    {
        (new B2BPartnerPermissionSeeder)->run();

        RoleResourcePermission::query()->updateOrCreate(
            [
                'role_code' => $roleCode,
                'resource_code' => $resourceCode,
            ],
            [
                'can_view' => true,
                'can_execute' => false,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function technician(array $attributes = []): TechnicalServiceTechnician
    {
        $sequence = TechnicalServiceTechnician::query()->count() + 1;

        return TechnicalServiceTechnician::query()->create(array_merge([
            'name' => 'Test Usta '.$sequence,
            'technician_type' => 'locksmith',
            'phone' => '+90555111000'.$sequence,
            'city' => 'İstanbul',
            'district' => 'Kadıköy',
            'mikro_cari_kodu' => '320.CLG.'.$sequence,
            'mikro_cari_adi' => 'Test Usta Cari '.$sequence,
            'active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function partnerPayload(array $overrides = []): array
    {
        return array_merge([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'partner_code' => 'PARTNER-PAYLOAD',
            'display_name' => 'Partner Payload',
            'mikro_cari_kodu' => 'CR-PAYLOAD',
            'mikro_cari_unvan' => 'Partner Payload Cari',
            'cari_grup_kodu' => null,
            'responsibility_code' => null,
            'phone' => '+905551119999',
            'email' => 'partner@example.test',
            'city' => 'İstanbul',
            'district' => 'Kadıköy',
            'active' => true,
            'technical_service_technician_id' => null,
        ], $overrides);
    }
}
