<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerAuditLog;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BCariSnapshot;
use App\Models\B2B\B2BCariSnapshotRun;
use App\Models\B2B\B2BPartnerUserAccess;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\DataSource;
use App\Models\MenuGroup;
use App\Models\Page;
use App\Models\PageMenu;
use App\Models\Resource;
use App\Models\Role;
use App\Models\RoleResourcePermission;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\B2B\B2BPartnerAccessService;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use Database\Seeders\B2BPartnerPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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
            'mikro_cari_kodu' => '320.CLG.001',
            'cari_code' => '320.CLG.001',
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

    public function test_partner_can_have_multiple_active_technicians_without_replacing_existing_link(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
        ]);
        $bahattin = $this->technician([
            'name' => 'Bahattin Ankara',
            'city' => 'Ankara',
            'mikro_cari_kodu' => '320.CLG.BAHAT',
            'cari_code' => '320.CLG.BAHAT',
        ]);
        $berkay = $this->technician([
            'name' => 'Berkay Izmir',
            'city' => 'Izmir',
            'mikro_cari_kodu' => '320.CLG.BAHAT',
            'cari_code' => '320.CLG.BAHAT',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/technicians", [
                'technical_service_technician_id' => $bahattin->id,
                'is_primary' => true,
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('partner.technical_service_technician_id', $bahattin->id);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/technicians", [
                'technical_service_technician_id' => $berkay->id,
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('partner.technical_service_technician_id', $bahattin->id);

        $this->assertDatabaseHas('b2b_partner_technicians', [
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $bahattin->id,
            'active' => true,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_technicians', [
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $berkay->id,
            'active' => true,
        ]);
    }

    public function test_same_active_technician_can_link_to_multiple_different_partners(): void
    {
        $admin = $this->userWithRole('admin', true);
        $firstPartner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
        ]);
        $secondPartner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
        ]);
        $technician = $this->technician(['name' => 'Tekil Usta']);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$firstPartner->id}/technicians", [
                'technical_service_technician_id' => $technician->id,
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$secondPartner->id}/technicians", [
                'technical_service_technician_id' => $technician->id,
            ])
            ->assertCreated();

        $this->assertSame(2, B2BPartnerTechnician::query()
            ->where('technical_service_technician_id', $technician->id)
            ->where('active', true)
            ->count());
    }

    public function test_same_technician_cannot_duplicate_link_inside_same_partner(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);
        $technician = $this->technician(['name' => 'Duplicate Guard Usta']);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/technicians", [
                'technical_service_technician_id' => $technician->id,
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/technicians", [
                'technical_service_technician_id' => $technician->id,
            ])
            ->assertJsonValidationErrors('technical_service_technician_id');
    }

    public function test_primary_technician_can_change_and_unlink_keeps_other_links(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
        ]);
        $first = $this->technician(['name' => 'Birinci Usta']);
        $second = $this->technician(['name' => 'Ikinci Usta']);

        $this->actingAs($admin)->postJson("/api/b2b/partners/{$partner->id}/technicians", [
            'technical_service_technician_id' => $first->id,
            'is_primary' => true,
        ])->assertCreated();
        $secondResponse = $this->actingAs($admin)->postJson("/api/b2b/partners/{$partner->id}/technicians", [
            'technical_service_technician_id' => $second->id,
        ])->assertCreated();
        $secondLinkId = $secondResponse->json('link.id');

        $this->actingAs($admin)
            ->patchJson("/api/b2b/partners/{$partner->id}/technicians/{$secondLinkId}", ['is_primary' => true])
            ->assertOk()
            ->assertJsonPath('partner.technical_service_technician_id', $second->id);

        $this->assertSame(1, B2BPartnerTechnician::query()->where('partner_id', $partner->id)->where('active', true)->where('is_primary', true)->count());

        $firstLink = B2BPartnerTechnician::query()
            ->where('partner_id', $partner->id)
            ->where('technical_service_technician_id', $first->id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson("/api/b2b/partners/{$partner->id}/technicians/{$firstLink->id}")
            ->assertOk()
            ->assertJsonCount(2, 'items');

        $this->assertDatabaseHas('b2b_partner_technicians', [
            'id' => $firstLink->id,
            'active' => false,
        ]);
        $this->assertDatabaseHas('b2b_partner_technicians', [
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $second->id,
            'active' => true,
            'is_primary' => true,
        ]);
    }

    public function test_dealer_only_partner_can_link_contracted_technicians(): void
    {
        $admin = $this->userWithRole('admin', true);
        $dealer = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);
        $technician = $this->technician();

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$dealer->id}/technicians", [
                'technical_service_technician_id' => $technician->id,
            ])
            ->assertCreated()
            ->assertJsonPath('link.relationship_type', 'contracted_technician')
            ->assertJsonPath('partner.technical_service_technician_id', $technician->id);
    }

    public function test_technician_lookup_marks_current_and_other_partner_links(): void
    {
        $admin = $this->userWithRole('admin', true);
        $currentPartner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
        ]);
        $otherPartner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
        ]);
        $currentTechnician = $this->technician([
            'name' => 'Bahattin Ankara',
            'mikro_cari_kodu' => '320.CLG.SAME',
            'cari_code' => '320.CLG.SAME',
        ]);
        $otherTechnician = $this->technician([
            'name' => 'Berkay Izmir',
            'mikro_cari_kodu' => '320.CLG.SAME',
            'cari_code' => '320.CLG.SAME',
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $currentPartner->id,
            'technical_service_technician_id' => $currentTechnician->id,
            'is_primary' => true,
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $otherPartner->id,
            'technical_service_technician_id' => $otherTechnician->id,
            'is_primary' => true,
            'active' => true,
        ]);

        $items = $this->actingAs($admin)
            ->getJson("/api/b2b/locksmith-technicians?search=320.CLG.SAME&partner_id={$currentPartner->id}")
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->json('items');

        $this->assertTrue(collect($items)->firstWhere('id', $currentTechnician->id)['linked_to_current_partner']);
        $this->assertTrue(collect($items)->firstWhere('id', $otherTechnician->id)['can_link']);
        $this->assertContains($otherPartner->display_name, collect($items)->firstWhere('id', $otherTechnician->id)['linked_partner_names']);
    }

    public function test_technician_lookup_empty_and_search_filters_active_technicians(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician([
            'name' => 'Lookup Alper Usta',
            'phone' => '+905551234567',
            'phone_e164' => '+905551234567',
            'city' => 'Istanbul',
            'district' => 'Besiktas',
            'mikro_cari_kodu' => '320.CLG.LOOKUP',
            'cari_code' => '320.CLG.LOOKUP',
        ]);
        $this->technician([
            'name' => 'Pasif Lookup Usta',
            'active' => false,
            'city' => 'Istanbul',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/locksmith-technicians')
            ->assertOk()
            ->assertJsonFragment(['id' => $technician->id]);

        foreach (['alper', '5551234567', 'istanbul', '320.CLG.LOOKUP'] as $search) {
            $this->actingAs($admin)
                ->getJson('/api/b2b/locksmith-technicians?search='.urlencode($search))
                ->assertOk()
                ->assertJsonFragment(['id' => $technician->id]);
        }
    }

    public function test_portal_service_job_scope_uses_all_active_linked_technicians(): void
    {
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
        ]);
        $first = $this->technician(['name' => 'Scope Usta 1']);
        $second = $this->technician(['name' => 'Scope Usta 2']);
        $other = $this->technician(['name' => 'Scope Diger']);
        foreach ([$first, $second] as $index => $technician) {
            B2BPartnerTechnician::query()->create([
                'partner_id' => $partner->id,
                'technical_service_technician_id' => $technician->id,
                'active' => true,
                'is_primary' => $index === 0,
            ]);
        }
        TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-SCOPE-1',
            'customer_name' => 'Scope Musteri',
            'customer_phone' => '+905550000001',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Scope adres 1',
            'product_name' => 'Test Kilit',
            'service_type' => 'Montaj',
            'technical_service_technician_id' => $first->id,
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
        ]);
        TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-SCOPE-2',
            'customer_name' => 'Scope Musteri',
            'customer_phone' => '+905550000002',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Scope adres 2',
            'product_name' => 'Test Kilit',
            'service_type' => 'Montaj',
            'technical_service_technician_id' => $second->id,
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
        ]);
        TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-SCOPE-3',
            'customer_name' => 'Scope Musteri',
            'customer_phone' => '+905550000003',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Scope adres 3',
            'product_name' => 'Test Kilit',
            'service_type' => 'Montaj',
            'technical_service_technician_id' => $other->id,
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
        ]);

        $mrns = app(B2BPartnerServiceJobScopeService::class)
            ->serviceJobsQuery($partner)
            ->pluck('mrn')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['MRN-SCOPE-1', 'MRN-SCOPE-2'], $mrns);
    }

    public function test_contracted_dealer_technician_does_not_expose_all_service_jobs(): void
    {
        $dealer = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);
        $technician = $this->technician(['name' => 'Contracted Dealer Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $dealer->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'contracted_technician',
            'active' => true,
            'is_primary' => true,
        ]);
        TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-DEALER-CONTRACTED',
            'customer_name' => 'Scope Musteri',
            'customer_phone' => '+905550000004',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Scope adres 4',
            'product_name' => 'Test Kilit',
            'service_type' => 'Montaj',
            'technical_service_technician_id' => $technician->id,
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
        ]);

        $this->assertSame([], app(B2BPartnerServiceJobScopeService::class)
            ->serviceJobsQuery($dealer)
            ->pluck('mrn')
            ->all());
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
            'b2b.manufacturers.view',
            'b2b.manufacturers.manage',
            'b2b.sellers.view',
            'b2b.sellers.manage',
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
        $this->assertSame(1, Page::query()->where('code', 'b2b_partner_users')->count());
        $this->assertSame(1, MenuGroup::query()->where('code', 'b2b')->count());
        $this->assertSame(1, PageMenu::query()
            ->where('page_id', Page::query()->where('code', 'b2b_partners')->value('id'))
            ->count());
        $this->assertSame(1, PageMenu::query()
            ->where('page_id', Page::query()->where('code', 'b2b_partner_users')->value('id'))
            ->count());
        $this->assertDatabaseHas('panel.roles', [
            'code' => 'b2b_manager',
            'name' => 'B2B Yönetici',
        ]);
        $this->assertDatabaseHas('panel.roles', [
            'code' => 'b2b_dealer',
            'name' => 'B2B Bayi',
        ]);
        $this->assertDatabaseHas('panel.roles', [
            'code' => 'b2b_locksmith',
            'name' => 'B2B Çilingir',
        ]);
        $this->assertDatabaseHas('panel.roles', [
            'code' => 'b2b_manufacturer',
        ]);
        $this->assertDatabaseHas('panel.roles', [
            'code' => 'b2b_seller',
        ]);
        $this->assertDatabaseHas('panel.role_resource_permissions', [
            'role_code' => 'b2b_manager',
            'resource_code' => 'b2b.partner_users.manage',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('panel.role_resource_permissions', [
            'role_code' => 'b2b_dealer',
            'resource_code' => 'b2b.dealers.view',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('panel.role_resource_permissions', [
            'role_code' => 'b2b_locksmith',
            'resource_code' => 'b2b.technical_service.view',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('panel.role_resource_permissions', [
            'role_code' => 'b2b_manufacturer',
            'resource_code' => 'b2b.manufacturers.view',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('panel.role_resource_permissions', [
            'role_code' => 'b2b_seller',
            'resource_code' => 'b2b.sellers.view',
            'can_view' => true,
        ]);

        foreach (['b2b_dealer', 'b2b_locksmith', 'b2b_manufacturer', 'b2b_seller'] as $roleCode) {
            $resourceCodes = RoleResourcePermission::query()
                ->where('role_code', $roleCode)
                ->pluck('resource_code')
                ->all();

            $this->assertNotContains('dashboard', $resourceCodes);
            $this->assertNotContains('sales_main', $resourceCodes);
            $this->assertNotContains('sales_main_all', $resourceCodes);
            $this->assertNotContains('sales_online', $resourceCodes);
            $this->assertNotContains('sales_bayi', $resourceCodes);
            $this->assertNotContains('stock', $resourceCodes);
            $this->assertNotContains('stock_all', $resourceCodes);
            $this->assertNotContains('orders', $resourceCodes);
            $this->assertNotContains('orders_alinan', $resourceCodes);
            $this->assertNotContains('orders_verilen', $resourceCodes);
        }
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

    public function test_partner_can_have_dealer_and_locksmith_capabilities_on_single_record(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Multi Role Usta']);

        $this->actingAs($admin)
            ->postJson('/api/b2b/partners', $this->partnerPayload([
                'partner_code' => 'MULTI-ROLE-001',
                'display_name' => 'Bayi ve Ã‡ilingir Partner',
                'capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
                'technical_service_technician_id' => $technician->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('partner.partner_code', 'MULTI-ROLE-001')
            ->assertJsonPath('partner.capabilities.0', B2BPartner::TYPE_DEALER)
            ->assertJsonPath('partner.capabilities.1', B2BPartner::TYPE_LOCKSMITH)
            ->assertJsonPath('partner.technical_service_technician_id', $technician->id);

        $partner = B2BPartner::query()->where('partner_code', 'MULTI-ROLE-001')->firstOrFail();
        $this->assertDatabaseHas('b2b_partner_capabilities', [
            'partner_id' => $partner->id,
            'capability' => B2BPartner::TYPE_DEALER,
            'active' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_capabilities', [
            'partner_id' => $partner->id,
            'capability' => B2BPartner::TYPE_LOCKSMITH,
            'active' => true,
        ]);
    }

    public function test_partner_requires_at_least_one_capability(): void
    {
        $admin = $this->userWithRole('admin', true);

        $this->actingAs($admin)
            ->postJson('/api/b2b/partners', $this->partnerPayload([
                'partner_type' => null,
                'capabilities' => [],
                'partner_code' => 'NO-CAPABILITY',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('capabilities');
    }

    public function test_same_active_technician_can_be_linked_to_two_active_locksmith_partners(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Tek Usta']);
        $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'technical_service_technician_id' => $technician->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/b2b/partners', $this->partnerPayload([
                'partner_type' => B2BPartner::TYPE_LOCKSMITH,
                'partner_code' => 'DUPLICATE-TECH',
                'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                'technical_service_technician_id' => $technician->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('partner.technical_service_technician_id', $technician->id);

        $this->assertSame(2, B2BPartnerTechnician::query()
            ->where('technical_service_technician_id', $technician->id)
            ->where('active', true)
            ->count());
    }

    public function test_same_mikro_cari_code_cannot_create_duplicate_active_partner(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->partner(['mikro_cari_kodu' => 'CR-DUPLICATE']);

        $this->actingAs($admin)
            ->postJson('/api/b2b/partners', $this->partnerPayload([
                'partner_code' => 'DUPLICATE-CARI',
                'mikro_cari_kodu' => 'CR-DUPLICATE',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mikro_cari_kodu');
    }

    public function test_create_dealer_with_technician_id_creates_contracted_link(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['technician_type' => 'locksmith']);

        $this->actingAs($admin)
            ->postJson('/api/b2b/partners', $this->partnerPayload([
                'partner_type' => B2BPartner::TYPE_DEALER,
                'partner_code' => 'DEALER-WITH-TECH',
                'technical_service_technician_id' => $technician->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('partner.technical_service_technician_id', $technician->id);

        $partner = B2BPartner::query()->where('partner_code', 'DEALER-WITH-TECH')->firstOrFail();
        $this->assertDatabaseHas('b2b_partner_technicians', [
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'contracted_technician',
            'active' => true,
        ]);
    }

    public function test_create_locksmith_with_non_locksmith_active_technician_works(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['technician_type' => 'technician']);

        $this->actingAs($admin)
            ->postJson('/api/b2b/partners', $this->partnerPayload([
                'partner_type' => B2BPartner::TYPE_LOCKSMITH,
                'partner_code' => 'LOCKSMITH-WITH-NON-LOCKSMITH',
                'technical_service_technician_id' => $technician->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('partner.technical_service_technician_id', $technician->id);
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
            'mikro_cari_kodu' => '320.CLG.001',
            'cari_code' => '320.CLG.001',
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

        $this->actingAs($admin)
            ->getJson('/api/b2b/locksmith-technicians?mikro_cari_kodu=320.CLG.001')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $activeLocksmith->id)
            ->assertJsonPath('items.0.match_reason', 'cari_match')
            ->assertJsonPath('items.0.requires_type_review', false);
    }

    public function test_locksmith_technician_lookup_searches_address_and_returns_full_payload(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician([
            'name' => 'Adresli Usta',
            'technician_type' => 'locksmith',
            'active' => true,
            'address' => 'Anahtar Sokak No 5',
            'city' => 'Bursa',
            'district' => 'Nilufer',
            'mikro_cari_kodu' => '320.CLG.ADDR',
            'mikro_cari_adi' => 'Adresli Usta Cari',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/locksmith-technicians?search=Anahtar')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $technician->id)
            ->assertJsonPath('items.0.address', 'Anahtar Sokak No 5')
            ->assertJsonPath('items.0.city', 'Bursa')
            ->assertJsonPath('items.0.district', 'Nilufer')
            ->assertJsonPath('items.0.mikro_cari_kodu', '320.CLG.ADDR')
            ->assertJsonPath('items.0.mikro_cari_adi', 'Adresli Usta Cari');
    }

    public function test_locksmith_technician_sync_creates_and_merges_b2b_partners_without_duplicates(): void
    {
        $admin = $this->userWithRole('admin', true);
        $existingDealer = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
            'mikro_cari_kodu' => '320.CLG.MERGE',
            'display_name' => 'Mevcut Bayi',
        ]);
        $mergeTechnician = $this->technician([
            'name' => 'Merge Usta',
            'technician_type' => 'locksmith',
            'mikro_cari_kodu' => '320.CLG.MERGE',
            'mikro_cari_adi' => 'Merge Usta Cari',
            'phone' => '+905551110111',
            'city' => 'Ankara',
            'district' => 'Cankaya',
            'address' => 'Merge Adres',
        ]);
        $newTechnician = $this->technician([
            'name' => 'Yeni Sync Usta',
            'technician_type' => 'locksmith',
            'mikro_cari_kodu' => '320.CLG.NEW',
            'mikro_cari_adi' => 'Yeni Sync Cari',
            'phone' => '+905551110222',
            'city' => 'Izmir',
            'district' => 'Bornova',
            'address' => 'Yeni Adres',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/b2b/locksmith-technicians/sync')
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('capability_added', 1);

        $existingDealer->refresh();
        $this->assertTrue($existingDealer->hasCapability(B2BPartner::TYPE_LOCKSMITH));
        $this->assertSame($mergeTechnician->id, (int) $existingDealer->technical_service_technician_id);
        $this->assertSame('+905551110111', $existingDealer->phone);
        $this->assertSame('Merge Adres', $existingDealer->metadata['address']);

        $newPartner = B2BPartner::query()
            ->where('technical_service_technician_id', $newTechnician->id)
            ->firstOrFail();
        $this->assertSame('Yeni Sync Usta', $newPartner->display_name);
        $this->assertSame('320.CLG.NEW', $newPartner->mikro_cari_kodu);
        $this->assertSame('Yeni Adres', $newPartner->metadata['address']);

        $this->actingAs($admin)
            ->postJson('/api/b2b/locksmith-technicians/sync')
            ->assertOk()
            ->assertJsonPath('created', 0);

        $this->assertSame(1, B2BPartner::query()->where('technical_service_technician_id', $newTechnician->id)->count());
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $existingDealer->id,
            'action' => 'b2b.partner.locksmith_synced',
        ]);
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

    public function test_partner_list_filters_by_dealer_and_locksmith_capabilities(): void
    {
        $admin = $this->userWithRole('admin', true);
        $dealerOnly = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'partner_code' => 'CAP-DEALER',
            'display_name' => 'Cap Dealer',
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);
        $locksmithOnly = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'CAP-LOCKSMITH',
            'display_name' => 'Cap Locksmith',
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
        ]);
        $multiRole = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'partner_code' => 'CAP-MULTI',
            'display_name' => 'Cap Multi',
            'capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
        ]);

        $dealerIds = collect($this->actingAs($admin)
            ->getJson('/api/b2b/partners?partner_type=dealer')
            ->assertOk()
            ->json('items'))
            ->pluck('id')
            ->all();

        $locksmithIds = collect($this->actingAs($admin)
            ->getJson('/api/b2b/partners?partner_type=locksmith')
            ->assertOk()
            ->json('items'))
            ->pluck('id')
            ->all();

        $this->assertContains($dealerOnly->id, $dealerIds);
        $this->assertContains($multiRole->id, $dealerIds);
        $this->assertNotContains($locksmithOnly->id, $dealerIds);

        $this->assertContains($locksmithOnly->id, $locksmithIds);
        $this->assertContains($multiRole->id, $locksmithIds);
        $this->assertNotContains($dealerOnly->id, $locksmithIds);
    }

    public function test_entity_scope_works_with_multi_capability_partner(): void
    {
        $user = $this->partnerUser();
        $multiRole = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'display_name' => 'Visible Multi',
            'capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
        ]);
        $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'display_name' => 'Hidden Locksmith',
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
        ]);
        $this->grantPartnerAccess($user, $multiRole, 'technical_service');

        $this->actingAs($user)
            ->getJson('/api/b2b/partners?partner_type=locksmith')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $multiRole->id)
            ->assertJsonPath('items.0.capabilities.0', B2BPartner::TYPE_DEALER)
            ->assertJsonPath('items.0.capabilities.1', B2BPartner::TYPE_LOCKSMITH);
    }

    public function test_partner_response_includes_user_counts(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner();
        B2BPartnerUserProfile::query()->create([
            'partner_id' => $partner->id,
            'user_id' => $this->userWithRole('active_partner_profile')->id,
            'active' => true,
        ]);
        B2BPartnerUserProfile::query()->create([
            'partner_id' => $partner->id,
            'user_id' => $this->userWithRole('inactive_partner_profile')->id,
            'active' => false,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/b2b/partners/{$partner->id}")
            ->assertOk()
            ->assertJsonPath('partner.users_count', 2)
            ->assertJsonPath('partner.active_users_count', 1);
    }

    public function test_cari_control_returns_error_when_gateway_source_is_unavailable_and_no_snapshot(): void
    {
        $admin = $this->userWithRole('admin', true);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control')
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('actions_enabled', false)
            ->assertJsonCount(0, 'items');
    }

    public function test_cari_control_uses_existing_customers_list_gateway_candidates(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');
        $this->technician(['mikro_cari_kodu' => '320.CLG.001']);

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'musteri_kodu' => '120.BAYI.001',
                        'firma_unvani' => 'Ankara Bayi A.S.',
                        'grup' => 'BAYI',
                        'temsilci_kodu' => 'SALES01',
                        'city' => 'Ankara',
                    ],
                    [
                        'musteri_kodu' => '320.CLG.001',
                        'firma_unvani' => 'Merkez Cilingir',
                        'grup' => 'Servis',
                    ],
                    [
                        'musteri_kodu' => '120.ONLINE.001',
                        'firma_unvani' => 'Online Perakende',
                        'grup' => 'ONLINE PERAKENDE',
                    ],
                    [
                        'musteri_kodu' => '320.FAB.001',
                        'firma_unvani' => 'Kapi Uretici Fabrika',
                        'grup' => 'URETICI',
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?include_review_required=1')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('source_used', 'customers_list')
            ->assertJsonPath('excluded_online_retail_count', 1)
            ->assertJsonCount(3, 'candidates')
            ->assertJsonPath('candidates.0.mikro_cari_kodu', '120.BAYI.001')
            ->assertJsonPath('candidates.0.suggested_capabilities.0', B2BPartner::TYPE_DEALER)
            ->assertJsonPath('candidates.0.suggested_capabilities.1', B2BPartner::TYPE_SELLER)
            ->assertJsonPath('candidates.1.suggested_capabilities.0', B2BPartner::TYPE_LOCKSMITH)
            ->assertJsonPath('candidates.2.suggested_capabilities.0', B2BPartner::TYPE_MANUFACTURER);

        Http::assertSentCount(4);
        $this->assertSame(1, B2BCariSnapshotRun::query()->where('source_code', 'customers_list')->where('status', 'success')->count());
        $this->assertSame(3, B2BCariSnapshot::query()->count());
        $this->assertDatabaseHas('b2b_cari_snapshots', [
            'base_mikro_cari_kodu' => '120.BAYI.001',
            'candidate_status' => 'new',
        ]);

        Http::fake(function (): void {
            throw new \RuntimeException('Gateway should not be called when snapshot is available.');
        });

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?include_review_required=1')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('snapshot_total', 3)
            ->assertJsonCount(3, 'candidates')
            ->assertJsonPath('candidates.0.mikro_cari_kodu', '120.BAYI.001');

        Http::assertNothingSent();
    }

    public function test_cari_control_search_groups_child_cari_accounts_under_parent_candidate(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'musteri_kodu' => '120.00.33.00005',
                        'firma_unvani' => 'KEYSWORLD GUVENLIK SISTEMLERI',
                        'grup' => 'BAYI',
                        'city' => 'Istanbul',
                    ],
                    [
                        'musteri_kodu' => '120.00.33.00005.KONSINYE',
                        'firma_unvani' => 'KEYSWORLD GUVENLIK SISTEMLERI KONSINYE',
                        'grup' => 'BAYI',
                    ],
                    [
                        'musteri_kodu' => '120.00.33.00005.TEŞHIR',
                        'firma_unvani' => 'KEYSWORLD GUVENLIK SISTEMLERI TESHIR',
                        'grup' => 'BAYI',
                    ],
                    [
                        'musteri_kodu' => '120.00.33.00005.PROJE',
                        'firma_unvani' => 'KEYSWORLD GUVENLIK SISTEMLERI PROJE',
                        'grup' => 'BAYI',
                    ],
                    [
                        'musteri_kodu' => '120.ONLINE.00001',
                        'firma_unvani' => 'Online Perakende Musteri',
                        'grup' => 'ONLINE PERAKENDE',
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?search=KONSINYE&include_review_required=1')
            ->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.mikro_cari_kodu', '120.00.33.00005')
            ->assertJsonPath('candidates.0.search_match', 'child')
            ->assertJsonPath('candidates.0.matched_child_cari_codes.0', '120.00.33.00005.KONSINYE')
            ->assertJsonPath('candidates.0.child_cari_accounts.0.mikro_cari_kodu', '120.00.33.00005.KONSINYE')
            ->assertJsonPath('candidates.0.child_cari_accounts.0.usage_type', 'consignment')
            ->assertJsonPath('candidates.0.child_cari_accounts.1.mikro_cari_kodu', '120.00.33.00005.TEŞHIR');

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?search=PROJE&include_review_required=1')
            ->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.mikro_cari_kodu', '120.00.33.00005')
            ->assertJsonPath('candidates.0.search_match', 'child')
            ->assertJsonPath('candidates.0.matched_child_cari_codes.0', '120.00.33.00005.PROJE');

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?search=KEYSWORLD&capability=dealer&include_review_required=1')
            ->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.search_match', 'parent')
            ->assertJsonPath('candidates.0.suggested_capabilities.0', B2BPartner::TYPE_DEALER);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?search=KEYSWORLD&capability=manufacturer&include_review_required=1')
            ->assertOk()
            ->assertJsonCount(0, 'candidates');

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?search=ONLINE&include_review_required=1')
            ->assertOk()
            ->assertJsonPath('excluded_online_retail_count', 1)
            ->assertJsonCount(0, 'candidates');
    }

    public function test_cari_control_child_only_candidate_creates_review_required_parent(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [[
                    'musteri_kodu' => '120.00.99.00001.KONSINYE',
                    'firma_unvani' => 'Sadece Alt Cari Konsinye',
                    'grup' => 'BAYI',
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?search=KONSINYE&include_review_required=1')
            ->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.mikro_cari_kodu', '120.00.99.00001')
            ->assertJsonPath('candidates.0.status', 'review_required')
            ->assertJsonPath('candidates.0.review_required', true)
            ->assertJsonPath('candidates.0.child_cari_accounts.0.usage_type', 'consignment');
    }

    public function test_cari_control_normalizes_contact_and_location_aliases(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [[
                    'cari_kod' => '120.ALIAS.001',
                    'cari_unvan1' => 'Alias Bayi',
                    'cari_grup_kodu' => 'BAYI',
                    'cari_tel_no' => '+905551112233',
                    'cari_email' => 'alias@example.com',
                    'sehir' => 'Ankara',
                    'ilçe' => 'Cankaya',
                    'cari_adres2' => 'Test Mahallesi',
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?search=ALIAS&include_review_required=1')
            ->assertOk()
            ->assertJsonPath('candidates.0.phone', '+905551112233')
            ->assertJsonPath('candidates.0.email', 'alias@example.com')
            ->assertJsonPath('candidates.0.city', 'Ankara')
            ->assertJsonPath('candidates.0.district', 'Cankaya')
            ->assertJsonPath('candidates.0.address', 'Test Mahallesi');
    }

    public function test_cari_apply_enriches_missing_contact_fields_from_detail_source_and_preserves_existing_values(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customer_detail');

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [[
                    'cari_kod' => '120.DETAIL.001',
                    'cari_unvan1' => 'Detail Bayi',
                    'cari_grup_kodu' => 'BAYI',
                    'cari_cep_tel' => '+905550001122',
                    'cari_EMail' => 'detail@example.test',
                    'cari_il' => 'Ankara',
                    'cari_ilce' => 'Cankaya',
                    'cari_adres1' => 'Detail Mahallesi',
                    'vergi_no' => '1234567890',
                    'vergi_dairesi' => 'Cankaya VD',
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'selected_capabilities' => [B2BPartner::TYPE_DEALER],
                'candidates' => [[
                    'mikro_cari_kodu' => '120.DETAIL.001',
                    'display_name' => 'Detail Bayi',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.status', 'created');

        $partner = B2BPartner::query()->where('mikro_cari_kodu', '120.DETAIL.001')->firstOrFail();
        $this->assertSame('+905550001122', $partner->phone);
        $this->assertSame('detail@example.test', $partner->email);
        $this->assertSame('Ankara', $partner->city);
        $this->assertSame('Cankaya', $partner->district);
        $this->assertSame('Detail Mahallesi', $partner->metadata['address']);
        $this->assertSame('1234567890', $partner->metadata['tax_no']);
        $this->assertSame('Cankaya VD', $partner->metadata['tax_office']);
        $this->assertSame('Detail Mahallesi', $partner->metadata['invoice_profile']['invoice_address']);
        $this->assertSame('detail@example.test', $partner->metadata['invoice_profile']['email']);
        $this->assertSame('Detail Mahallesi', $partner->metadata['shipping_profile']['address']);

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'update_partner',
                'existing_partner_id' => $partner->id,
                'selected_capabilities' => [B2BPartner::TYPE_DEALER],
                'candidates' => [[
                    'mikro_cari_kodu' => '120.DETAIL.001',
                    'display_name' => 'Detail Bayi Updated',
                    'phone' => '',
                    'email' => '',
                    'city' => '',
                    'district' => '',
                ]],
            ])
            ->assertOk();

        $partner->refresh();
        $this->assertSame('+905550001122', $partner->phone);
        $this->assertSame('detail@example.test', $partner->email);
        $this->assertSame('Ankara', $partner->city);
        $this->assertSame('Cankaya', $partner->district);
    }

    public function test_cari_control_query_contract_doc_contains_select_only_discovery_contract(): void
    {
        $contract = file_get_contents(base_path('docs/b2b-mikro-cari-control-query-contract.md')) ?: '';

        $this->assertStringContainsString('SELECT TABLE_SCHEMA, TABLE_NAME', $contract);
        $this->assertStringContainsString('INFORMATION_SCHEMA.TABLES', $contract);
        $this->assertStringContainsString('INFORMATION_SCHEMA.COLUMNS', $contract);
        $this->assertStringContainsString('Aday verisi gelmeden partner olusturma veya guncelleme yapilmaz.', $contract);
        $this->assertStringContainsString('MSSQL tarafinda INSERT/UPDATE/DELETE/DROP/TRUNCATE yoktur.', $contract);
    }

    public function test_partner_directory_ui_uses_multi_role_cards_without_horizontal_scroll(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/b2b/partners.tsx'));
        $usersSource = file_get_contents(resource_path('js/pages/panel/b2b/users.tsx'));

        $this->assertIsString($source);
        $this->assertIsString($usersSource);
        $this->assertStringContainsString('Bayi kanalı', $source);
        $this->assertStringContainsString('Çilingir / servis kanalı', $source);
        $this->assertStringContainsString("Mikro'da yeni cari oluşturmaz", $source);
        $this->assertStringContainsString('Çilingirleri eşitle', $source);
        $this->assertStringContainsString('Kullanıcı:', $source);
        $this->assertStringContainsString('Cari kodu,', $source);
        $this->assertStringContainsString('cariSearch', $source);
        $this->assertStringContainsString('selectedCariCandidates', $source);
        $this->assertStringContainsString('child_cari_accounts', $source);
        $this->assertStringContainsString('matched_child_cari_codes', $source);
        $this->assertStringContainsString('Eşleşen cari bulunamadı.', $source);
        $this->assertStringNotContainsString('overflow-x-auto', $source);
        $this->assertStringNotContainsString('Bayi için kullanılmaz', $source);
        $this->assertStringNotContainsString('overflow-x-auto', $usersSource);
        $this->assertStringContainsString('grid gap-3 rounded-xl', $usersSource);
        $this->assertStringContainsString('Partner kullanıcı yetkileri güncellendi', $usersSource);
    }

    public function test_cari_control_and_admin_role_preset_ui_contracts_exist(): void
    {
        $partnerSource = file_get_contents(resource_path('js/pages/panel/b2b/partners.tsx')) ?: '';
        $adminSource = file_get_contents(resource_path('js/pages/panel/admin/AdminUsers.jsx')) ?: '';

        $this->assertStringContainsString('Sorgu sözleşmesi', $partnerSource);
        $this->assertStringContainsString('Seçili adayları işle', $partnerSource);
        $this->assertStringNotContainsString('const cariControlAvailable = false', $partnerSource);
        $this->assertStringContainsString("'B2B'", $adminSource);
        $this->assertStringContainsString('applyRoleDefaults', $adminSource);
        $this->assertStringContainsString('Rol seçilince varsayılan izinler otomatik işaretlenir', $adminSource);
        $this->assertStringContainsString('şirket içi satış/stok/sipariş ekranlarını açmaz', $adminSource);
        $this->assertStringContainsString('Partner Kullanıcıları ekranından yönetilir', $adminSource);
    }

    public function test_import_selected_cari_creates_partner_with_selected_capabilities(): void
    {
        $admin = $this->userWithRole('admin', true);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/import', [
                'items' => [[
                    'mikro_cari_kodu' => 'CR-IMPORT-001',
                    'display_name' => 'Cari Import Partner',
                    'mikro_cari_unvan' => 'Cari Import Ãœnvan',
                    'city' => 'Ankara',
                    'capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.status', 'created');

        $partner = B2BPartner::query()->where('mikro_cari_kodu', 'CR-IMPORT-001')->firstOrFail();
        $this->assertEqualsCanonicalizing([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH], $partner->capabilityCodes());
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'action' => 'b2b.partner.imported_from_cari',
        ]);
    }

    public function test_dealer_cari_import_creates_default_scoped_dealer_user_and_child_metadata(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $otherPartner = $this->partner(['mikro_cari_kodu' => 'CR-OTHER-001']);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'selected_capabilities' => [B2BPartner::TYPE_DEALER],
                'candidates' => [[
                    'mikro_cari_kodu' => '320.ÇLG.06.002',
                    'display_name' => 'BAHATTİN ÖZBEK',
                    'mikro_cari_unvan' => 'BAHATTİN ÖZBEK',
                    'phone' => '+905551112233',
                    'email' => 'bahattin@example.test',
                    'city' => 'Ankara',
                    'district' => 'Cankaya',
                    'address' => 'Bayi Mahallesi No:1',
                    'tax_no' => '9988776655',
                    'tax_office' => 'Ankara VD',
                    'child_cari_accounts' => [[
                        'mikro_cari_kodu' => '320.ÇLG.06.002.KONSINYE',
                        'mikro_cari_unvan' => 'BAHATTİN ÖZBEK KONSINYE',
                        'usage_type' => 'consignment',
                        'invoice_usage_note' => 'Konsinye siparisi/faturasi icin bu alt cari hesabi kullanilacak.',
                    ], [
                        'mikro_cari_kodu' => '320.ÇLG.06.002.TESHIR',
                        'mikro_cari_unvan' => 'BAHATTİN ÖZBEK TESHIR',
                        'usage_type' => 'showroom',
                    ], [
                        'mikro_cari_kodu' => '320.ÇLG.06.002.PROJE',
                        'mikro_cari_unvan' => 'BAHATTİN ÖZBEK PROJE',
                        'usage_type' => 'project',
                    ]],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.status', 'created')
            ->assertJsonPath('items.0.default_user.username', 'bahat320')
            ->assertJsonPath('items.0.default_user.default_password', '12345678');

        $partner = B2BPartner::query()->where('mikro_cari_kodu', '320.ÇLG.06.002')->firstOrFail();
        $user = User::query()->where('username', 'bahat320')->firstOrFail();

        $this->assertTrue(Hash::check('12345678', $user->password_hash));
        $this->assertNotSame('12345678', $user->password_hash);
        $this->assertSame('b2b_dealer', $user->role_code);
        $this->assertTrue((bool) $user->force_password_change);
        $this->assertSame('consignment', $partner->metadata['child_cari_accounts'][0]['usage_type']);
        $this->assertSame('Bayi Mahallesi No:1', $partner->metadata['address']);
        $this->assertSame('9988776655', $partner->metadata['invoice_profile']['tax_no']);
        $this->assertSame('Ankara VD', $partner->metadata['invoice_profile']['tax_office']);
        $this->assertSame('320.ÇLG.06.002.KONSINYE', $partner->metadata['shipping_profile']['consignment_cari_kodu']);
        $this->assertSame('320.ÇLG.06.002.TESHIR', $partner->metadata['shipping_profile']['showroom_cari_kodu']);
        $this->assertSame('320.ÇLG.06.002.PROJE', $partner->metadata['shipping_profile']['project_cari_kodu']);

        $this->assertDatabaseHas('b2b_partner_user_profiles', [
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'active' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'access_scope' => 'view',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'access_scope' => 'orders',
            'can_view' => true,
            'can_create' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'access_scope' => 'stock',
            'can_view' => true,
        ]);
        $this->assertDatabaseMissing('b2b_partner_user_access', [
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'access_scope' => 'technical_service',
        ]);
        $this->assertFalse(app(B2BPartnerAccessService::class)->canViewPartner($user, $otherPartner));
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'action' => 'b2b.partner.default_user_created',
        ]);
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'subject_id' => $user->id,
            'action' => 'b2b.partner_user.assigned',
        ]);
    }

    public function test_default_dealer_username_gets_unique_suffix_and_locksmith_only_creates_no_dealer_user(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        User::factory()->create(['username' => 'bahat320']);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'selected_capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
                'candidates' => [[
                    'mikro_cari_kodu' => '320.CLG.06.002',
                    'display_name' => 'BAHATTIN OZBEK',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.default_user.username', 'bahat3202');

        $this->assertDatabaseMissing('b2b_partner_user_access', [
            'user_id' => User::query()->where('username', 'bahat3202')->value('id'),
            'access_scope' => 'technical_service',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                'candidates' => [[
                    'mikro_cari_kodu' => '320.CLG.06.003',
                    'display_name' => 'Sadece Cilingir',
                ]],
            ])
            ->assertOk()
            ->assertJsonMissingPath('items.0.default_user');
    }

    public function test_import_existing_mikro_cari_adds_capability_instead_of_duplicate_partner(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'mikro_cari_kodu' => 'CR-MERGE-001',
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/import', [
                'items' => [[
                    'mikro_cari_kodu' => 'CR-MERGE-001',
                    'display_name' => 'Cari Merge Partner',
                    'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.status', 'updated')
            ->assertJsonPath('items.0.partner_id', $partner->id);

        $this->assertSame(1, B2BPartner::query()->where('mikro_cari_kodu', 'CR-MERGE-001')->count());
        $this->assertEqualsCanonicalizing([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH], $partner->fresh()->capabilityCodes());
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'action' => 'b2b.partner.updated_from_cari',
        ]);
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'action' => 'b2b.partner.capability_added',
        ]);
    }

    public function test_cari_control_apply_create_partner_blocks_duplicate_active_mikro_cari(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'mikro_cari_kodu' => 'CR-DUP-001',
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'create_partner',
                'candidates' => [[
                    'mikro_cari_kodu' => 'CR-DUP-001',
                    'display_name' => 'Duplicate Cari',
                    'selected_capabilities' => [B2BPartner::TYPE_SELLER],
                ]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('existing_partner_id', $partner->id);

        $this->assertSame(1, B2BPartner::query()->where('mikro_cari_kodu', 'CR-DUP-001')->count());
    }

    public function test_cari_control_apply_updates_snapshot_adds_capability_and_marks_review(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'mikro_cari_kodu' => 'CR-APPLY-001',
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'update_partner',
                'existing_partner_id' => $partner->id,
                'selected_capabilities' => [B2BPartner::TYPE_DEALER],
                'candidates' => [[
                    'mikro_cari_kodu' => 'CR-APPLY-001',
                    'display_name' => 'Updated Snapshot',
                    'mikro_cari_unvan' => 'Updated Snapshot Unvan',
                    'city' => 'Bursa',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.status', 'updated');

        $partner->refresh();
        $this->assertSame('Updated Snapshot', $partner->display_name);
        $this->assertSame('Bursa', $partner->city);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'add_capability',
                'existing_partner_id' => $partner->id,
                'selected_capabilities' => [B2BPartner::TYPE_SELLER],
                'candidates' => [['mikro_cari_kodu' => 'CR-APPLY-001']],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.status', 'capability_added');

        $this->assertEqualsCanonicalizing([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_SELLER], $partner->fresh()->capabilityCodes());

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'mark_review',
                'existing_partner_id' => $partner->id,
                'selected_capabilities' => [B2BPartner::TYPE_DEALER],
                'candidates' => [['mikro_cari_kodu' => 'CR-APPLY-001', 'status' => 'review_required']],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.status', 'review_marked');

        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'action' => 'b2b.partner.updated_from_cari',
        ]);
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'action' => 'b2b.partner.capability_added',
        ]);
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'action' => 'b2b.partner.cari_review_marked',
        ]);
    }

    public function test_super_admin_assigns_existing_user_to_dealer_partner(): void
    {
        $admin = $this->userWithRole('admin', true);
        $targetUser = $this->userWithRole('dealer_staff');
        $partner = $this->partner(['partner_type' => B2BPartner::TYPE_DEALER]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/users", [
                'user_id' => $targetUser->id,
                'title' => 'Bayi Yetkilisi',
                'phone' => '+905551112233',
                'active' => true,
                'scopes' => $this->scopePayload('view', ['can_view' => true]),
            ])
            ->assertCreated()
            ->assertJsonPath('items.0.user_id', $targetUser->id)
            ->assertJsonPath('items.0.profile_title', 'Bayi Yetkilisi')
            ->assertJsonPath('items.0.scopes.view.can_view', true);

        $this->assertDatabaseHas('b2b_partner_user_profiles', [
            'partner_id' => $partner->id,
            'user_id' => $targetUser->id,
            'title' => 'Bayi Yetkilisi',
            'active' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $partner->id,
            'user_id' => $targetUser->id,
            'access_scope' => 'view',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'subject_id' => $targetUser->id,
            'action' => 'b2b.partner_user.assigned',
        ]);
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'subject_id' => $targetUser->id,
            'action' => 'b2b.partner_user.profile_updated',
        ]);
    }

    public function test_partner_user_scope_update_writes_old_new_audit_and_updates_access_service(): void
    {
        $admin = $this->userWithRole('admin', true);
        $targetUser = $this->userWithRole('partner_staff');
        $partner = $this->partner(['partner_type' => B2BPartner::TYPE_DEALER]);
        $this->grantPartnerAccess($targetUser, $partner, 'view', ['can_view' => true]);

        $this->actingAs($admin)
            ->patchJson("/api/b2b/partners/{$partner->id}/users/{$targetUser->id}", [
                'title' => 'Operasyon',
                'phone' => '+905550000001',
                'active' => true,
                'scopes' => [
                    ...$this->scopePayload('view', ['can_view' => true]),
                    ...$this->scopePayload('users', [
                        'can_view' => true,
                        'can_update' => true,
                        'can_approve' => true,
                    ]),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.scopes.users.can_update', true)
            ->assertJsonPath('items.0.scopes.users.can_approve', true);

        $service = app(B2BPartnerAccessService::class);

        $this->assertTrue($service->canViewPartner($targetUser, $partner));
        $this->assertTrue($service->canAccessScope($targetUser, $partner, 'users', 'update'));
        $this->assertTrue($service->canAccessScope($targetUser, $partner, 'users', 'approve'));

        $auditLog = B2BPartnerAuditLog::query()
            ->where('partner_id', $partner->id)
            ->where('subject_id', $targetUser->id)
            ->where('action', 'b2b.partner_user.access_updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($targetUser->id, $auditLog->new_values['user_id']);
        $this->assertArrayHasKey('view', $auditLog->old_values['scopes']);
        $this->assertArrayHasKey('users', $auditLog->new_values['scopes']);
    }

    public function test_revoke_partner_user_disables_access_without_hard_delete(): void
    {
        $admin = $this->userWithRole('admin', true);
        $targetUser = $this->userWithRole('revoked_partner_user');
        $partner = $this->partner();
        $this->grantPartnerAccess($targetUser, $partner, 'view', ['can_view' => true]);
        B2BPartnerUserProfile::query()->create([
            'partner_id' => $partner->id,
            'user_id' => $targetUser->id,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/b2b/partners/{$partner->id}/users/{$targetUser->id}")
            ->assertOk()
            ->assertJsonPath('items.0.profile_active', false)
            ->assertJsonPath('items.0.scopes.view.can_view', false);

        $this->assertFalse(app(B2BPartnerAccessService::class)->canViewPartner($targetUser, $partner));
        $this->assertDatabaseHas('b2b_partner_user_profiles', [
            'partner_id' => $partner->id,
            'user_id' => $targetUser->id,
            'active' => false,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $partner->id,
            'user_id' => $targetUser->id,
            'access_scope' => 'view',
            'can_view' => false,
        ]);
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'subject_id' => $targetUser->id,
            'action' => 'b2b.partner_user.revoked',
        ]);
    }

    public function test_unscoped_user_cannot_view_partner_users_by_url_manipulation(): void
    {
        $user = $this->partnerUser();
        $visiblePartner = $this->partner(['display_name' => 'Visible']);
        $hiddenPartner = $this->partner(['display_name' => 'Hidden']);
        $this->grantPartnerAccess($user, $visiblePartner, 'view');

        $this->actingAs($user)
            ->getJson("/api/b2b/partners/{$hiddenPartner->id}/users")
            ->assertForbidden();
    }

    public function test_scoped_partner_manager_only_manages_own_partner_users(): void
    {
        $manager = $this->partnerUser();
        $targetUser = $this->userWithRole('dealer_target_user');
        $ownDealer = $this->partner(['partner_type' => B2BPartner::TYPE_DEALER]);
        $otherDealer = $this->partner(['partner_type' => B2BPartner::TYPE_DEALER]);
        $this->grantPartnerAccess($manager, $ownDealer, 'users', [
            'can_view' => true,
            'can_update' => true,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/b2b/partners/{$ownDealer->id}/users", [
                'user_id' => $targetUser->id,
                'active' => true,
                'scopes' => $this->scopePayload('view', ['can_view' => true]),
            ])
            ->assertCreated();

        $this->actingAs($manager)
            ->postJson("/api/b2b/partners/{$otherDealer->id}/users", [
                'user_id' => $targetUser->id,
                'active' => true,
                'scopes' => $this->scopePayload('view', ['can_view' => true]),
            ])
            ->assertForbidden();
    }

    public function test_locksmith_scoped_manager_only_manages_own_locksmith_users(): void
    {
        $manager = $this->partnerUser();
        $targetUser = $this->userWithRole('locksmith_target_user');
        $ownLocksmith = $this->partner(['partner_type' => B2BPartner::TYPE_LOCKSMITH]);
        $otherLocksmith = $this->partner(['partner_type' => B2BPartner::TYPE_LOCKSMITH]);
        $this->grantPartnerAccess($manager, $ownLocksmith, 'users', [
            'can_view' => true,
            'can_update' => true,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/b2b/partners/{$ownLocksmith->id}/users", [
                'user_id' => $targetUser->id,
                'active' => true,
                'scopes' => $this->scopePayload('technical_service', ['can_view' => true]),
            ])
            ->assertCreated();

        $this->actingAs($manager)
            ->postJson("/api/b2b/partners/{$otherLocksmith->id}/users", [
                'user_id' => $targetUser->id,
                'active' => true,
                'scopes' => $this->scopePayload('technical_service', ['can_view' => true]),
            ])
            ->assertForbidden();
    }

    public function test_global_partner_user_permission_without_entity_scope_cannot_manage_partner_users(): void
    {
        $manager = $this->userWithRole('global_partner_user_manager');
        $targetUser = $this->userWithRole('unscoped_target_user');
        $partner = $this->partner();
        $this->grantPanelResource('global_partner_user_manager', 'b2b.partner_users.manage');

        $this->actingAs($manager)
            ->getJson("/api/b2b/partners/{$partner->id}/users")
            ->assertForbidden();

        $this->actingAs($manager)
            ->postJson("/api/b2b/partners/{$partner->id}/users", [
                'user_id' => $targetUser->id,
                'active' => true,
                'scopes' => $this->scopePayload('view', ['can_view' => true]),
            ])
            ->assertForbidden();
    }

    public function test_user_search_requires_manage_permission_and_does_not_return_secrets(): void
    {
        $manager = $this->userWithRole('user_search_manager');
        $this->grantPanelResource('user_search_manager', 'b2b.partner_users.manage');
        $targetUser = $this->userWithRole('searchable_user');

        $response = $this->actingAs($manager)
            ->getJson('/api/b2b/users/search?search=searchable')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.user_id', $targetUser->id);

        $response->assertJsonMissingPath('items.0.password_hash');
        $response->assertJsonMissingPath('items.0.password');
        $response->assertJsonMissingPath('items.0.remember_token');
        $response->assertJsonMissingPath('items.0.two_factor_secret');

        $this->actingAs($this->partnerUser())
            ->getJson('/api/b2b/users/search?search=searchable')
            ->assertForbidden();
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
        $capabilities = $attributes['capabilities'] ?? [$type];
        unset($attributes['capabilities']);

        $partner = B2BPartner::query()->create(array_merge([
            'partner_type' => $type,
            'partner_code' => sprintf('%s-%03d', strtoupper((string) $type), $sequence),
            'display_name' => 'Test Partner '.$sequence,
            'mikro_cari_kodu' => 'CR-'.$sequence,
            'mikro_cari_unvan' => 'Test Cari '.$sequence,
            'city' => 'İstanbul',
            'district' => 'Kadıköy',
            'active' => true,
        ], $attributes));

        foreach (array_unique($capabilities) as $capability) {
            B2BPartnerCapability::query()->create([
                'partner_id' => $partner->id,
                'capability' => $capability,
                'active' => true,
            ]);
        }

        if (! empty($partner->technical_service_technician_id)) {
            B2BPartnerTechnician::query()->updateOrCreate(
                [
                    'partner_id' => $partner->id,
                    'technical_service_technician_id' => $partner->technical_service_technician_id,
                ],
                [
                    'relationship_type' => 'field_technician',
                    'is_primary' => true,
                    'active' => true,
                    'source' => 'test_helper',
                ],
            );
        }

        return $partner->load('capabilities');
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

    private function dataSource(string $code): DataSource
    {
        return DataSource::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => 'Test '.$code,
                'db_type' => 'n8n_json',
                'query_template' => 'SELECT 1',
                'allowed_params' => ['search', 'scope_key', 'customer_scope_key', 'page', 'limit', 'bypass_cache'],
                'connection_meta' => [
                    'endpoint_url' => 'https://n8n.test/gateway',
                    'response_rows_key' => 'rows',
                    'timeout_seconds' => 10,
                ],
                'preview_payload' => [],
                'active' => true,
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

    /**
     * @param  array<string, bool>  $overrides
     * @return array<int, array<string, mixed>>
     */
    private function scopePayload(string $scope, array $overrides = []): array
    {
        return [[
            'access_scope' => $scope,
            'can_view' => false,
            'can_create' => false,
            'can_update' => false,
            'can_approve' => false,
            ...$overrides,
        ]];
    }
}
