<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerAuditLog;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerOrder;
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
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningItem;
use App\Models\TechnicalServiceEarningsPeriod;
use App\Models\TechnicalServiceCustomerConfirmation;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\B2B\B2BPartnerAccessService;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use App\Services\PanelAccessService;
use App\Services\PanelNavigationService;
use Database\Seeders\B2BPartnerPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
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
        RoleResourcePermission::query()->updateOrCreate(
            [
                'role_code' => 'b2b_dealer',
                'resource_code' => 'b2b.view',
            ],
            [
                'can_view' => true,
                'can_execute' => false,
            ],
        );
        $seeder->run();

        $codes = [
            'b2b.view',
            'b2b.dashboard.view',
            'b2b.portal_preview.view',
            'b2b.partners.view',
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
        $this->assertSame(1, Resource::query()->where('code', 'b2b.dashboard.view')->count());
        $this->assertSame(1, Resource::query()->where('code', 'b2b.portal_preview.view')->count());
        $this->assertSame(1, Resource::query()->where('code', 'b2b.partners.view')->count());
        $this->assertSame(1, Page::query()->where('code', 'b2b_partners')->count());
        $this->assertSame(1, Page::query()->where('code', 'b2b_dashboard')->count());
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
            'name' => 'Bayi Kullanıcısı',
        ]);
        $this->assertDatabaseHas('panel.roles', [
            'code' => 'b2b_locksmith',
            'name' => 'Çilingir Kullanıcısı',
        ]);
        $this->assertDatabaseHas('panel.roles', [
            'code' => 'b2b_manufacturer',
            'name' => 'Üretici Kullanıcısı',
        ]);
        $this->assertDatabaseHas('panel.roles', [
            'code' => 'b2b_seller',
            'name' => 'Satıcı Kullanıcısı',
        ]);
        $this->assertDatabaseHas('panel.role_resource_permissions', [
            'role_code' => 'b2b_manager',
            'resource_code' => 'b2b.partner_users.manage',
            'can_view' => true,
        ]);
        $this->assertDatabaseMissing('panel.role_resource_permissions', [
            'role_code' => 'b2b_manager',
            'resource_code' => 'b2b.dashboard.view',
        ]);
        $this->assertDatabaseMissing('panel.role_resource_permissions', [
            'role_code' => 'b2b_manager',
            'resource_code' => 'b2b.locksmiths.view',
        ]);
        $this->assertDatabaseMissing('panel.role_resource_permissions', [
            'role_code' => 'b2b_manager',
            'resource_code' => 'b2b.portal_preview.view',
        ]);
        $this->assertDatabaseHas('panel.role_resource_permissions', [
            'role_code' => 'b2b_dealer',
            'resource_code' => 'partner.portal.view',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('panel.role_resource_permissions', [
            'role_code' => 'b2b_locksmith',
            'resource_code' => 'partner.service_jobs.view',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('panel.role_resource_permissions', [
            'role_code' => 'b2b_manufacturer',
            'resource_code' => 'partner.dashboard.view',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('panel.role_resource_permissions', [
            'role_code' => 'b2b_seller',
            'resource_code' => 'partner.orders.view',
            'can_view' => true,
        ]);

        foreach (['b2b_dealer', 'b2b_locksmith', 'b2b_manufacturer', 'b2b_seller'] as $roleCode) {
            $resourceCodes = RoleResourcePermission::query()
                ->where('role_code', $roleCode)
                ->pluck('resource_code')
                ->all();

            $this->assertNotContains('dashboard', $resourceCodes);
            $this->assertNotContains('b2b.view', $resourceCodes);
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
        $this->assertStringContainsString('Admin kullanıcı oluştur', $source);
        $this->assertStringContainsString('Kullanıcısı olmayanlara admin aç', $source);
        $this->assertStringContainsString('Portal Önizle', $source);
        $this->assertStringNotContainsString('overflow-x-auto', $source);
        $this->assertStringNotContainsString('Bayi için kullanılmaz', $source);
        $this->assertStringNotContainsString('overflow-x-auto', $usersSource);
        $this->assertStringContainsString('Portal admin', $usersSource);
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

    public function test_dealer_manage_role_can_link_cari_mismatch_technician(): void
    {
        $manager = $this->userWithRole('dealer_tech_manager');
        $this->grantPanelResource('dealer_tech_manager', 'b2b.dealers.manage');
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
            'mikro_cari_kodu' => '120.DEALER.001',
        ]);
        $this->grantPartnerAccess($manager, $partner, 'manage', ['can_update' => true]);
        $technician = $this->technician([
            'name' => 'Cari Mismatch Usta',
            'mikro_cari_kodu' => '320.CLG.DIFFERENT',
            'cari_code' => '320.CLG.DIFFERENT',
        ]);

        $this->actingAs($manager)
            ->postJson("/api/b2b/partners/{$partner->id}/technicians", [
                'technical_service_technician_id' => $technician->id,
            ])
            ->assertCreated()
            ->assertJsonPath('link.relationship_type', 'contracted_technician');

        $this->assertDatabaseHas('b2b_partner_technicians', [
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'active' => true,
        ]);
    }

    public function test_locksmith_sync_includes_technician_type_technician_and_legacy_candidates(): void
    {
        $admin = $this->userWithRole('admin', true);
        $typedTechnician = $this->technician([
            'name' => 'Saha Teknisyeni',
            'technician_type' => 'technician',
            'mikro_cari_kodu' => '320.TECH.001',
            'cari_code' => '320.TECH.001',
        ]);
        $legacyTechnician = $this->technician([
            'name' => 'Legacy Aktif Usta',
            'technician_type' => 'legacy',
            'mikro_cari_kodu' => null,
            'cari_code' => '320.LEGACY.001',
            'phone' => '+905551112222',
            'city' => 'Ankara',
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/api/b2b/locksmith-technicians/sync')
            ->assertOk()
            ->assertJsonPath('created_partners', 2)
            ->assertJsonPath('linked_technicians', 2)
            ->assertJsonPath('review_required', 1);

        $this->assertDatabaseHas('b2b_partner_technicians', [
            'technical_service_technician_id' => $typedTechnician->id,
            'active' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_technicians', [
            'technical_service_technician_id' => $legacyTechnician->id,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/b2b/locksmith-technicians/sync')
            ->assertOk()
            ->assertJsonPath('already_linked', 2);
    }

    public function test_b2b_role_defaults_are_partner_portal_only_for_partner_users(): void
    {
        (new B2BPartnerPermissionSeeder)->run();

        $dealerResources = RoleResourcePermission::query()
            ->where('role_code', 'b2b_dealer')
            ->where('can_view', true)
            ->pluck('resource_code')
            ->all();

        $this->assertContains('partner.portal.view', $dealerResources);
        $this->assertContains('partner.dashboard.view', $dealerResources);
        $this->assertContains('partner.orders.view', $dealerResources);
        $this->assertContains('partner.stock.view', $dealerResources);
        $this->assertNotContains('b2b.view', $dealerResources);
        $this->assertNotContains('sales_main', $dealerResources);
        $this->assertNotContains('stock', $dealerResources);
        $this->assertFalse(app(PanelAccessService::class)->userCanAccess($this->userWithRole('b2b_dealer'), 'sales_main'));
    }

    public function test_partner_user_without_entity_profile_cannot_open_partner_portal(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $user = $this->userWithRole('b2b_dealer');
        $this->partner(['partner_type' => B2BPartner::TYPE_DEALER]);

        $this->actingAs($user)
            ->get('/partner/dashboard')
            ->assertForbidden();
    }

    public function test_b2b_dealer_user_sees_own_partner_portal_and_not_internal_panel(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $user = $this->userWithRole('b2b_dealer');
        $partner = $this->partner(['partner_type' => B2BPartner::TYPE_DEALER]);
        B2BPartnerUserProfile::query()->create([
            'user_id' => $user->id,
            'partner_id' => $partner->id,
            'active' => true,
        ]);
        $this->grantPartnerAccess($user, $partner, 'view');
        $this->grantPartnerAccess($user, $partner, 'orders', ['can_create' => true]);
        $this->grantPartnerAccess($user, $partner, 'stock');

        $this->assertSame('/partner/dashboard', app(PanelNavigationService::class)->homePathFor($user));
        $this->assertFalse(app(PanelAccessService::class)->userCanAccess($user, 'dashboard'));
        $this->assertFalse(app(PanelAccessService::class)->userCanAccess($user, 'sales_main'));
        $this->assertFalse(app(PanelAccessService::class)->userCanAccess($user, 'stock'));

        $this->actingAs($user)
            ->get('/partner/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/dashboard')
                ->where('partnerPortal.allowed', true)
                ->where('partnerPortal.selectedPartner.id', $partner->id));

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_b2b_locksmith_user_sees_only_owner_field_service_jobs(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $user = $this->userWithRole('b2b_locksmith');
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
        ]);
        B2BPartnerUserProfile::query()->create([
            'user_id' => $user->id,
            'partner_id' => $partner->id,
            'active' => true,
        ]);
        $this->grantPartnerAccess($user, $partner, 'view');
        $this->grantPartnerAccess($user, $partner, 'technical_service');
        $fieldTechnician = $this->technician(['name' => 'Portal Field Usta']);
        $contractedTechnician = $this->technician(['name' => 'Portal Contracted Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $fieldTechnician->id,
            'relationship_type' => 'field_technician',
            'active' => true,
            'is_primary' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $contractedTechnician->id,
            'relationship_type' => 'contracted_technician',
            'active' => true,
            'is_primary' => false,
        ]);
        $visibleRequest = $this->serviceRequestForTechnician($fieldTechnician, 'MRN-PORTAL-FIELD');
        $this->serviceRequestForTechnician($contractedTechnician, 'MRN-PORTAL-CONTRACTED');

        $this->actingAs($user)
            ->get('/partner/service-jobs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/service-jobs')
                ->where('partnerPortal.allowed', true)
                ->where('partnerPortal.serviceJobs.0.mrn', $visibleRequest->mrn)
                ->missing('partnerPortal.serviceJobs.1'));
    }

    public function test_internal_user_can_open_b2b_operations_dashboard(): void
    {
        $user = $this->userWithRole('b2b_ops_user');
        $this->grantPanelResource('b2b_ops_user', 'b2b.dashboard.view');

        $this->actingAs($user)
            ->get('/panel/b2b')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('panel/b2b/dashboard')
                ->where('page.routePath', '/panel/b2b'));
    }

    public function test_partner_user_cannot_access_b2b_operations_dashboard(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $user = $this->userWithRole('b2b_dealer');

        $this->actingAs($user)
            ->get('/panel/b2b')
            ->assertForbidden();

        $this->actingAs($user)
            ->getJson('/api/b2b/dashboard/summary')
            ->assertForbidden();
    }

    public function test_b2b_dashboard_summary_counts_partners_and_placeholders(): void
    {
        $user = $this->userWithRole('b2b_ops_summary');
        $this->grantPanelResource('b2b_ops_summary', 'b2b.dashboard.view');
        $dealer = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
            'address' => 'Bayi adresi',
        ]);
        $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
        ]);
        $technician = $this->technician(['name' => 'Kokpit Usta']);
        $hybrid = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
            'technical_service_technician_id' => $technician->id,
            'address' => 'Karma adres',
        ]);
        B2BPartnerUserProfile::query()->create([
            'user_id' => $user->id,
            'partner_id' => $dealer->id,
            'active' => true,
        ]);
        $this->serviceRequestForTechnician($technician, 'MRN-B2B-DASHBOARD');

        $this->actingAs($user)
            ->getJson('/api/b2b/dashboard/summary?capability=dealer')
            ->assertOk()
            ->assertJsonPath('partner_counts.total', 2)
            ->assertJsonPath('partner_counts.active_dealers', 2)
            ->assertJsonPath('partner_counts.active_locksmiths', 1)
            ->assertJsonPath('partner_counts.active_dealer_locksmith', 1)
            ->assertJsonPath('missing_data_counts.partners_without_users', 1)
            ->assertJsonPath('missing_data_counts.locksmiths_without_technicians', 0)
            ->assertJsonPath('visibility.can_include_locksmiths', false)
            ->assertJsonPath('visibility.include_locksmiths', false)
            ->assertJsonPath('visibility.pure_locksmiths_hidden', true)
            ->assertJsonPath('service_counts.open_service_jobs', 1)
            ->assertJsonPath('stock_order_placeholders.orders.status', 'local_order_requests')
            ->assertJsonCount(2, 'partner_status');

        $this->actingAs($user)
            ->getJson('/api/b2b/dashboard/summary?capability=locksmith')
            ->assertOk()
            ->assertJsonCount(1, 'partner_status')
            ->assertJsonPath('partner_status.0.id', $hybrid->id);

        $this->grantPanelResource('b2b_ops_summary', 'b2b.locksmiths.view');

        $this->actingAs($user)
            ->getJson('/api/b2b/dashboard/summary?include_locksmiths=1')
            ->assertOk()
            ->assertJsonPath('partner_counts.total', 3)
            ->assertJsonPath('partner_counts.active_locksmiths', 2)
            ->assertJsonPath('partner_counts.pure_locksmiths_visible', 1)
            ->assertJsonPath('missing_data_counts.partners_without_users', 2)
            ->assertJsonPath('missing_data_counts.locksmiths_without_technicians', 1)
            ->assertJsonPath('visibility.can_include_locksmiths', true)
            ->assertJsonPath('visibility.include_locksmiths', true)
            ->assertJsonCount(3, 'partner_status');

        $this->actingAs($user)
            ->getJson('/api/b2b/dashboard/orders')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('summary.pending', 0);

        $this->actingAs($user)
            ->getJson('/api/b2b/dashboard/stock')
            ->assertOk()
            ->assertJsonPath('status', 'not_configured');

        $this->assertTrue($hybrid->fresh()->activePartnerTechnicians()->exists());
    }

    public function test_b2b_dashboard_resource_is_required_separately_from_partner_management(): void
    {
        $user = $this->userWithRole('b2b_partner_manager');
        $this->grantPanelResource('b2b_partner_manager', 'b2b.view');
        $this->grantPanelResource('b2b_partner_manager', 'b2b.manage');

        $this->actingAs($user)->get('/panel/b2b')->assertForbidden();
        $this->actingAs($user)->getJson('/api/b2b/dashboard/summary')->assertForbidden();

        $this->grantPanelResource('b2b_partner_manager', 'b2b.dashboard.view');

        $this->actingAs($user)->get('/panel/b2b')->assertOk();
        $this->actingAs($user)->getJson('/api/b2b/dashboard/summary')->assertOk();
    }

    public function test_partner_admin_provisioning_creates_scoped_dealer_user(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
            'display_name' => 'BAHATTIN OZBEK',
            'mikro_cari_kodu' => '320.CLG.06.002',
        ]);
        $otherPartner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
            'mikro_cari_kodu' => '320.CLG.06.999',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user", [
                'show_default_password' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('username', 'bahat320')
            ->assertJsonPath('role_code', 'b2b_dealer')
            ->assertJsonPath('default_password', '12345678');

        $user = User::query()->where('username', 'bahat320')->firstOrFail();
        $this->assertSame('b2b_dealer', $user->role_code);
        $this->assertTrue(Hash::check('12345678', $user->password_hash));
        $this->assertNotSame('12345678', $user->password_hash);
        $this->assertTrue((bool) $user->force_password_change);

        $this->assertDatabaseHas('b2b_partner_user_profiles', [
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'active' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'access_scope' => 'orders',
            'can_view' => true,
            'can_create' => true,
            'can_update' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'access_scope' => 'stock',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'access_scope' => 'users',
            'can_view' => true,
            'can_create' => true,
            'can_update' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'access_scope' => 'finance',
            'can_view' => false,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'access_scope' => 'technical_service',
            'can_view' => false,
        ]);

        $this->assertTrue(app(B2BPartnerAccessService::class)->canViewPartner($user, $partner));
        $this->assertFalse(app(B2BPartnerAccessService::class)->canViewPartner($user, $otherPartner));
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'subject_id' => $user->id,
            'action' => 'b2b.partner.admin_user_created',
        ]);
        $auditPayload = B2BPartnerAuditLog::query()
            ->where('partner_id', $partner->id)
            ->get()
            ->map(fn (B2BPartnerAuditLog $log): string => json_encode([$log->old_values, $log->new_values]) ?: '')
            ->implode("\n");
        $this->assertStringNotContainsString('12345678', $auditPayload);
    }

    public function test_partner_admin_provisioning_is_idempotent_and_username_suffixes_are_unique(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        User::factory()->create(['username' => 'bahat320']);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
            'display_name' => 'BAHATTIN OZBEK',
            'mikro_cari_kodu' => '320.CLG.06.002',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated()
            ->assertJsonPath('username', 'bahat3202');

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertOk()
            ->assertJsonPath('status', 'already_linked')
            ->assertJsonPath('username', 'bahat3202')
            ->assertJsonPath('default_password', null);

        $this->assertSame(1, B2BPartnerUserProfile::query()
            ->where('partner_id', $partner->id)
            ->whereHas('user', fn ($query) => $query->where('role_code', 'b2b_dealer'))
            ->count());
    }

    public function test_locksmith_and_hybrid_partner_admin_scopes_are_partner_limited(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $locksmith = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'LOCKSMITH ONLY',
            'mikro_cari_kodu' => '320.CLG.01.001',
        ]);
        $hybrid = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'HYBRID PARTNER',
            'mikro_cari_kodu' => '120.00.33.00005',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$locksmith->id}/provision-admin-user")
            ->assertCreated()
            ->assertJsonPath('role_code', 'b2b_locksmith');
        $locksmithUser = User::query()->where('role_code', 'b2b_locksmith')->firstOrFail();
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $locksmith->id,
            'user_id' => $locksmithUser->id,
            'access_scope' => 'technical_service',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $locksmith->id,
            'user_id' => $locksmithUser->id,
            'access_scope' => 'orders',
            'can_view' => false,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$hybrid->id}/provision-admin-user")
            ->assertCreated()
            ->assertJsonPath('role_code', 'b2b_dealer');
        $hybridUser = User::query()
            ->where('role_code', 'b2b_dealer')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $hybrid->id))
            ->firstOrFail();
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $hybrid->id,
            'user_id' => $hybridUser->id,
            'access_scope' => 'orders',
            'can_view' => true,
            'can_create' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $hybrid->id,
            'user_id' => $hybridUser->id,
            'access_scope' => 'technical_service',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('b2b_partner_user_access', [
            'partner_id' => $hybrid->id,
            'user_id' => $hybridUser->id,
            'access_scope' => 'finance',
            'can_view' => false,
        ]);
    }

    public function test_bulk_partner_admin_provisioning_skips_existing_active_admin_users(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $existing = $this->partner(['display_name' => 'Existing Dealer', 'mikro_cari_kodu' => '120.00.01']);
        $missing = $this->partner(['display_name' => 'Missing Dealer', 'mikro_cari_kodu' => '120.00.02']);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$existing->id}/provision-admin-user")
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/b2b/partners/provision-admin-users', [
                'partner_ids' => [$existing->id, $missing->id],
                'only_without_users' => true,
                'show_default_password' => true,
            ])
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('created', 1)
            ->assertJsonPath('skipped_existing', 1);
    }

    public function test_partner_users_cannot_provision_admins_or_open_internal_preview(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner(['display_name' => 'Scoped Dealer', 'mikro_cari_kodu' => '120.00.33']);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_dealer')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertForbidden();
        $this->actingAs($portalUser)
            ->get("/panel/b2b/partners/{$partner->id}/portal-preview")
            ->assertForbidden();
        $this->actingAs($portalUser)
            ->get('/panel/b2b')
            ->assertForbidden();
        $this->actingAs($portalUser)
            ->get('/partner/dashboard')
            ->assertOk();
    }

    public function test_portal_preview_requires_permission_and_loads_selected_partner_only(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $operationUser = $this->userWithRole('b2b_preview_operator');
        $this->grantPanelResource('b2b_preview_operator', 'b2b.portal_preview.view');
        $partner = $this->partner(['display_name' => 'Preview Partner', 'mikro_cari_kodu' => '120.00.55']);
        $otherPartner = $this->partner(['display_name' => 'Other Partner', 'mikro_cari_kodu' => '120.00.56']);

        $this->actingAs($operationUser)
            ->get("/panel/b2b/partners/{$partner->id}/portal-preview")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('panel/b2b/portal-preview')
                ->where('preview.read_only', true)
                ->where('partnerPortal.selectedPartner.id', $partner->id)
                ->where('partnerPortal.partners.0.id', $partner->id)
                ->missing('partnerPortal.partners.1')
            );

        $this->actingAs($operationUser)
            ->get("/panel/b2b/partners/{$otherPartner->id}/portal-preview")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('panel/b2b/portal-preview')
                ->where('partnerPortal.selectedPartner.id', $otherPartner->id)
            );
    }

    public function test_dealer_partner_portal_creates_local_order_request_with_safe_product_fields(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
            'display_name' => 'Portal Dealer',
            'mikro_cari_kodu' => '120.00.33.00005',
            'partner_code' => 'INTERNAL-PARTNER-CODE',
        ]);
        $otherPartner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
            'display_name' => 'Other Dealer',
            'mikro_cari_kodu' => '120.00.33.99999',
        ]);
        B2BPartnerOrder::query()->create([
            'partner_id' => $otherPartner->id,
            'user_id' => $admin->id,
            'order_no' => 'B2B-OTHER',
            'status' => B2BPartnerOrder::STATUS_OPS_REVIEW,
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_dealer')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->get('/partner/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/dashboard')
                ->missing('partnerPortal.selectedPartner.partner_code')
                ->missing('partnerPortal.selectedPartner.mikro_cari_kodu')
            );

        $this->actingAs($portalUser)
            ->get('/partner/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/settings')
                ->missing('partnerPortal.selectedPartner.partner_code')
                ->missing('partnerPortal.selectedPartner.mikro_cari_kodu')
            );

        $this->actingAs($portalUser)
            ->getJson('/api/partner/products')
            ->assertOk()
            ->assertJsonPath('source', 'local_safe_catalog')
            ->assertJsonMissingPath('products.0.product_code')
            ->assertJsonMissingPath('products.0.cost')
            ->assertJsonMissingPath('products.0.mikro_cari_kodu');

        $this->actingAs($portalUser)
            ->postJson('/api/partner/orders', [
                'partner_id' => $partner->id,
                'note' => 'Portal order note',
                'items' => [
                    ['catalog_id' => 'smart_lock_prime', 'requested_quantity' => 2],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('order.items.0.product_name', 'Akıllı kapı kilidi')
            ->assertJsonMissingPath('order.items.0.product_code');

        $this->assertDatabaseHas('b2b_partner_orders', [
            'partner_id' => $partner->id,
            'user_id' => $portalUser->id,
            'status' => B2BPartnerOrder::STATUS_OPS_REVIEW,
            'note' => 'Portal order note',
        ]);
        $this->assertDatabaseHas('b2b_partner_order_items', [
            'product_code' => 'smart_lock_prime',
            'product_name' => 'Akıllı kapı kilidi',
            'requested_quantity' => 2,
        ]);

        $this->actingAs($portalUser)
            ->get('/partner/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/orders')
                ->has('partnerPortal.orders', 1)
                ->where('partnerPortal.orders.0.note', 'Portal order note')
            );

        $this->actingAs($portalUser)
            ->getJson("/api/partner/orders?partner_id={$otherPartner->id}")
            ->assertForbidden();
    }

    public function test_locksmith_partner_portal_scopes_jobs_and_earnings_to_owner_field_technicians(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Portal Locksmith',
            'mikro_cari_kodu' => '320.CLG.77.001',
        ]);
        $linkedTechnician = $this->technician(['name' => 'Portal Linked Usta']);
        $otherTechnician = $this->technician(['name' => 'Portal Other Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $linkedTechnician->id,
            'relationship_type' => 'owner',
            'is_primary' => true,
            'active' => true,
        ]);
        $linkedRequest = $this->serviceRequestForTechnician($linkedTechnician, 'MRN-PORTAL-LINKED');
        $this->serviceRequestForTechnician($otherTechnician, 'MRN-PORTAL-OTHER');
        $period = TechnicalServiceEarningsPeriod::query()->create([
            'year' => 2026,
            'month' => 5,
            'status' => 'Hazır',
            'calculated_at' => now(),
        ]);
        $earning = TechnicalServiceEarning::query()->create([
            'period_id' => $period->id,
            'technical_service_technician_id' => $linkedTechnician->id,
            'technician_name_snapshot' => $linkedTechnician->name,
            'city_snapshot' => 'İstanbul',
            'job_count' => 1,
            'installation_count' => 1,
            'service_count' => 0,
            'labor_total' => 1000,
            'travel_fee_total' => 100,
            'travel_round_trip_km_total' => 10,
            'travel_billable_km_total' => 0,
            'grand_total' => 1100,
            'status' => 'Hazır',
        ]);
        TechnicalServiceEarningItem::query()->create([
            'earning_id' => $earning->id,
            'technical_service_request_id' => $linkedRequest->id,
            'mrn' => 'MRN-PORTAL-LINKED',
            'job_date' => now(),
            'customer_city' => 'İstanbul',
            'customer_district' => 'Kadıköy',
            'service_type' => 'Montaj',
            'product_name' => 'Test Kilit',
            'labor_amount' => 1000,
            'travel_round_trip_km' => 10,
            'travel_billable_km' => 0,
            'travel_fee_amount' => 100,
            'line_total' => 1100,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->get('/partner/service-jobs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/service-jobs')
                ->has('partnerPortal.serviceJobs', 1)
                ->where('partnerPortal.serviceJobs.0.mrn', 'MRN-PORTAL-LINKED')
            );

        $this->actingAs($portalUser)
            ->get('/partner/earnings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/earnings')
                ->has('partnerPortal.earnings.rows', 1)
                ->where('partnerPortal.earnings.rows.0.items.0.mrn', 'MRN-PORTAL-LINKED')
            );
    }

    public function test_locksmith_partner_service_jobs_api_returns_scoped_kanban_columns(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Kanban Locksmith',
        ]);
        $owner = $this->technician(['name' => 'Owner Portal Usta']);
        $field = $this->technician(['name' => 'Field Portal Usta']);
        $contracted = $this->technician(['name' => 'Contracted Dealer Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $owner->id,
            'relationship_type' => 'owner',
            'is_primary' => true,
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $field->id,
            'relationship_type' => 'field_technician',
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $contracted->id,
            'relationship_type' => 'contracted_technician',
            'active' => true,
        ]);

        $newJob = $this->serviceRequestForTechnician($owner, 'MRN-KANBAN-NEW', [
            'workflow_status' => 'Usta Onayı Bekleyen',
            'status' => 'Atandı',
        ]);
        $this->serviceRequestForTechnician($field, 'MRN-KANBAN-PLANLI', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);
        $this->serviceRequestForTechnician($field, 'MRN-KANBAN-REVISIT', [
            'workflow_status' => 'Beklemede',
            'status' => 'Devam Ediyor',
            'requires_second_visit' => true,
        ]);
        $this->serviceRequestForTechnician($field, 'MRN-KANBAN-DONE', [
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now(),
        ]);
        $this->serviceRequestForTechnician($contracted, 'MRN-KANBAN-CONTRACTED', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->getJson('/api/partner/service-jobs')
            ->assertOk()
            ->assertJsonPath('columns.0.key', 'new_jobs')
            ->assertJsonPath('columns.0.count', 1)
            ->assertJsonPath('columns.1.key', 'appointment_confirmed')
            ->assertJsonPath('columns.1.count', 1)
            ->assertJsonPath('columns.2.key', 'revisit')
            ->assertJsonPath('columns.2.count', 1)
            ->assertJsonPath('columns.3.key', 'final_check')
            ->assertJsonPath('columns.3.count', 0)
            ->assertJsonPath('columns.4.key', 'completed')
            ->assertJsonPath('columns.4.count', 1)
            ->assertJsonPath('appointment_slot_options.0.value', '10:00-11:00')
            ->assertJsonPath('appointment_slot_options.6.value', '16:00-17:00')
            ->assertJsonMissing(['mrn' => 'MRN-KANBAN-CONTRACTED']);

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$newJob->id}")
            ->assertOk()
            ->assertJsonPath('job.can_accept', false)
            ->assertJsonPath('job.can_propose_appointment', true)
            ->assertJsonPath('job.checklist_payload', []);
    }

    public function test_locksmith_partner_service_job_actions_are_scoped_and_audited(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Action Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Action Portal Usta']);
        $otherTechnician = $this->technician(['name' => 'Other Portal Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $acceptJob = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-ACCEPT', [
            'workflow_status' => 'Usta Onayı Bekleyen',
            'status' => 'Atandı',
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '12:00',
        ]);
        $unplannedAcceptJob = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-ACCEPT-NO-DATE', [
            'workflow_status' => 'Usta Onayı Bekleyen',
            'status' => 'Atandı',
        ]);
        $revisitJob = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-REVISIT', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);
        $completionJob = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-COMPLETE', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'before_photo_count' => 3,
            'after_photo_count' => 3,
            'general_photo_count' => 3,
            'customer_closure_approval_status' => 'onaylandi',
        ]);
        $otherJob = $this->serviceRequestForTechnician($otherTechnician, 'MRN-ACTION-FORBIDDEN', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$unplannedAcceptJob->id}/accept-appointment", ['note' => 'Randevu yok'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('appointment');

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$acceptJob->id}/accept-appointment", ['note' => 'Randevu uygundur'])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_APPLIED)
            ->assertJsonPath('job.kanban_column', 'appointment_confirmed');
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $acceptJob->id,
            'partner_id' => $partner->id,
            'user_id' => $portalUser->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_ACCEPTED_BY_TECHNICIAN,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $acceptJob->id,
            'event_type' => 'partner_portal_appointment_accepted',
        ]);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$revisitJob->id}/request-revisit", ['reason' => 'Müşteri yeni tarih istedi'])
            ->assertOk()
            ->assertJsonPath('job.kanban_column', 'revisit');
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $revisitJob->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED,
        ]);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$revisitJob->id}/support-request", [
                'type' => 'spare_part',
                'description' => 'Yedek kilit parcasi gerekiyor.',
                'product_name' => 'Kilit parcasi',
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW);
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $revisitJob->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
        ]);

        $this->createPortalFieldDocument($completionJob, 'before_photo');
        $this->createPortalFieldDocument($completionJob, 'after_photo');
        $this->createPortalFieldDocument($completionJob, 'warranty_document_photo');

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$completionJob->id}/submit-completion", [
                'result' => 'completed',
                'note' => 'İşlem tamamlandı, operasyon onayı bekleniyor.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.kanban_column', 'final_check');
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $completionJob->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
        ]);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$completionJob->id}/note", ['note' => 'Ek operasyon notu', 'visibility' => 'ops'])
            ->assertOk();
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $completionJob->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_NOTE_ADDED,
        ]);

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$otherJob->id}")
            ->assertForbidden();
        $this->actingAs($portalUser)
            ->getJson('/api/technical-service/requests')
            ->assertForbidden();
    }

    public function test_customer_approval_link_is_required_for_partner_completion(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Approval Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Approval Portal Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-CUSTOMER-APPROVAL', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);
        $beforeDocument = $this->createPortalFieldDocument($job, 'before_photo');
        $afterDocument = $this->createPortalFieldDocument($job, 'after_photo');
        $warrantyDocument = $this->createPortalFieldDocument($job, 'warranty_document_photo');

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/submit-completion", [
                'result' => 'completed',
                'note' => 'Müşteri onayı olmadan kapanmamalı.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_confirmation');

        config([
            'services.partner_portal.public_url' => 'https://portal.test',
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.real_send_enabled' => true,
        ]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);
        TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $job->id,
            'partner_id' => $partner->id,
            'user_id' => $portalUser->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_SUBMITTED,
            'payload' => [
                'message_payload' => [
                    'dispatch_status' => 'failed',
                    'error_message' => 'WhatsApp webhook ayarı eksik.',
                ],
            ],
            'note' => 'Eski başarısız mesaj kaydı.',
        ]);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/customer-otp-request", [
                'note' => 'Müşteri bağlantıdan onaylayacak.',
            ])
            ->assertOk()
            ->assertJsonPath('action', TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED)
            ->assertJsonPath('dispatch.dispatch_status', 'sent')
            ->assertJsonPath('dispatch.target_phone', '905467647428')
            ->assertJsonPath('job.customer_otp_request.payload.message_payload.dispatch_status', 'sent')
            ->assertJsonPath('message', 'WhatsApp onay mesajı gönderildi.');

        $confirmation = TechnicalServiceCustomerConfirmation::query()
            ->where('technical_service_request_id', $job->id)
            ->firstOrFail();
        $this->assertSame(TechnicalServiceCustomerConfirmation::STATUS_PENDING, $confirmation->status);
        $this->assertStringContainsString('Emaks Prime Teknik Servis', (string) ($confirmation->payload['message_payload']['message_text'] ?? ''));
        $this->assertStringContainsString("\nhttps://portal.test/service-job-confirmation/{$confirmation->token}\n", (string) ($confirmation->payload['message_payload']['message_text'] ?? ''));
        $this->assertStringNotContainsString('127.0.0.1', (string) ($confirmation->payload['message_payload']['message_text'] ?? ''));
        $this->assertArrayHasKey('approval_url', $confirmation->payload['message_payload'] ?? []);
        $this->assertSame("https://portal.test/service-job-confirmation/{$confirmation->token}", $confirmation->payload['message_payload']['confirmation_url'] ?? null);
        $this->assertSame('sent', $confirmation->payload['message_payload']['dispatch_status'] ?? null);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $job->id,
            'event' => 'customer_approval_request',
            'target_type' => 'customer',
            'target_phone' => '905467647428',
            'test_mode' => true,
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://n8n.test/webhook/emaks/evo/send-message'
            && $request['event'] === 'customer_approval_request'
            && $request['target_phone'] === '905467647428'
            && $request['confirmation_url'] === "https://portal.test/service-job-confirmation/{$confirmation->token}");

        $this->get("/service-job-confirmation/{$confirmation->token}")
            ->assertOk()
            ->assertSee("/service-job-confirmation/{$confirmation->token}/approve", false)
            ->assertSee('Onaylıyorum', false)
            ->assertSee('Onaylamıyorum', false)
            ->assertSee('reject-dialog', false);

        $this->from("/service-job-confirmation/{$confirmation->token}")
            ->post("/service-job-confirmation/{$confirmation->token}/reject", [])
            ->assertSessionHasErrors('customer_note');

        $this->post("/service-job-confirmation/{$confirmation->token}/approve", [
            'customer_note' => 'Montajı onaylıyorum.',
        ])
            ->assertOk()
            ->assertSee('Teşekkür ederiz', false)
            ->assertSee('Operasyon ekibi süreci kontrol edecektir', false);

        $this->assertDatabaseHas('technical_service_customer_confirmations', [
            'id' => $confirmation->id,
            'status' => TechnicalServiceCustomerConfirmation::STATUS_APPROVED,
        ]);
        $job->refresh();
        $this->assertNull($job->completed_at);
        $this->assertNotSame('Tamamlandı', $job->workflow_status);
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $job->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_CONFIRMED,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $job->id,
            'event_type' => 'customer_installation_approved',
        ]);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/submit-completion", [
                'result' => 'completed',
                'note' => 'Müşteri onayı sonrası son kontrole gönderildi.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.kanban_column', 'final_check')
            ->assertJsonPath('job.checklist_status', 'tamamlandı');

        $job->refresh();
        $this->assertSame('tamamlandı', $job->checklist_status);
    }

    public function test_ops_completion_accepts_server_checked_portal_checklist_without_visible_backend_steps(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Server Checked Completion Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Server Checked Usta']);
        $portalUser = $this->userWithRole('b2b_locksmith');
        $job = $this->serviceRequestForTechnician($technician, 'MRN-SERVER-CHECKED-COMPLETE', [
            'workflow_status' => 'Planlı',
            'status' => 'Son Kontrol',
            'checklist_status' => null,
            'checklist_payload' => [
                'Ürün seri numarası kontrol edildi' => false,
                'Kapı / montaj yeri kontrol edildi' => false,
                'Montaj uygunluğu kontrol edildi' => false,
                'Ürün çalışır durumda test edildi' => false,
                'Müşteriye kullanım bilgisi verildi' => false,
                'Garanti / servis formu bilgisi kontrol edildi' => false,
            ],
            'customer_closure_approval_status' => 'onaylandı',
        ]);
        $beforeDocument = $this->createPortalFieldDocument($job, 'before_photo');
        $afterDocument = $this->createPortalFieldDocument($job, 'after_photo');
        $warrantyDocument = $this->createPortalFieldDocument($job, 'warranty_document_photo');
        $beforeDocument->forceFill(['review_status' => 'accepted'])->save();
        $afterDocument->forceFill(['review_status' => 'accepted'])->save();
        $warrantyDocument->forceFill(['review_status' => 'accepted'])->save();

        $action = TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $job->id,
            'partner_id' => $partner->id,
            'user_id' => $portalUser->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => [
                'checklist_gate' => 'server_checked',
                'checklist' => [
                    'customer_contacted' => true,
                    'address_confirmed' => true,
                    'appointment_confirmed' => true,
                    'door_product_checked' => true,
                    'job_completed' => true,
                    'customer_informed' => true,
                ],
            ],
            'note' => 'Usta tamamlamaya gönderdi.',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/partner-completions/{$action->id}/approve", [
                'note' => 'Server checklist kontrolüyle son kontrol tamamlandı.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_APPLIED)
            ->assertJsonPath('request.workflow_status', 'Tamamlandı')
            ->assertJsonPath('request.checklist_status', 'tamamlandı');

        $job->refresh();
        $this->assertSame('tamamlandı', $job->checklist_status);
    }

    public function test_customer_approval_after_pending_completion_stays_in_final_check(): void
    {
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Final Check Approval Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Pending Approval Usta']);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-CUSTOMER-FINAL-CHECK', [
            'workflow_status' => 'Sahada',
            'status' => 'Devam Ediyor',
            'completed_at' => now(),
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $portalUser = $this->userWithRole('b2b_locksmith');
        TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $job->id,
            'partner_id' => $partner->id,
            'user_id' => $portalUser->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => ['note' => 'Müşteri onayı bekleniyor.'],
            'note' => 'Müşteri onayı bekleniyor.',
        ]);
        $otpAction = TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $job->id,
            'partner_id' => $partner->id,
            'user_id' => $portalUser->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_SUBMITTED,
            'payload' => ['note' => 'Müşteri bağlantıdan onaylayacak.'],
            'note' => 'Müşteri bağlantıdan onaylayacak.',
        ]);
        $confirmation = TechnicalServiceCustomerConfirmation::query()->create([
            'technical_service_request_id' => $job->id,
            'token' => 'customer-final-check-token',
            'status' => TechnicalServiceCustomerConfirmation::STATUS_PENDING,
            'payload' => ['partner_action_id' => $otpAction->id],
        ]);

        $this->post("/service-job-confirmation/{$confirmation->token}/approve", [
            'customer_note' => 'Montajı onaylıyorum.',
        ])->assertOk();

        $this->assertDatabaseHas('technical_service_requests', [
            'id' => $job->id,
            'status' => 'Son Kontrol',
            'workflow_status' => 'Müşteri Kapanış Onayı Bekleyen',
            'completed_at' => null,
        ]);
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $job->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_CONFIRMED,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
        ]);
    }

    public function test_public_customer_door_photos_do_not_count_as_partner_field_documents(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Document Separation Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Document Separation Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'field_technician',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-DOC-SEPARATION', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'customer_closure_approval_status' => 'onaylandi',
        ]);
        foreach (['door_front_photo', 'door_side_photo', 'door_back_photo'] as $fieldCode) {
            TechnicalServiceRequestUpload::query()->create([
                'technical_service_request_id' => $job->id,
                'field_code' => $fieldCode,
                'category' => TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO,
                'original_name' => $fieldCode.'.jpg',
                'path' => 'technical-service/customer/'.$fieldCode.'.jpg',
                'mime' => 'image/jpeg',
                'size' => 128,
            ]);
        }

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 0)
            ->assertJsonPath('job.completion_requirements.photos_ready', false)
            ->assertJsonPath('job.photos', []);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/submit-completion", [
                'result' => 'completed',
                'note' => 'Public fotoğraf saha belgesi sayılmamalı.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photos');
    }

    public function test_locksmith_partner_can_propose_appointment_and_ops_can_approve_with_message_payload(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Appointment Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Appointment Portal Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-APPOINTMENT-PROPOSE', [
            'workflow_status' => 'Usta Onayı Bekleyen',
            'status' => 'Atandı',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/appointment-proposal", [
                'slots' => [
                    ['date' => '2026-05-25', 'slot' => '10:00-11:00'],
                    ['date' => '2026-05-25', 'slot' => '10:00-11:00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slots');

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/appointment-proposal", [
                'slots' => [
                    ['date' => '2026-05-25', 'slot' => '10:00-11:00'],
                    ['date' => '2026-05-25', 'slot' => '15:00-16:00'],
                ],
                'note' => 'Sabah uygunum.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW);

        $action = TechnicalServicePartnerJobAction::query()
            ->where('technical_service_request_id', $job->id)
            ->where('action', TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED)
            ->firstOrFail();
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $job->id,
            'event_type' => 'partner_portal_appointment_proposed',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/partner-appointment-proposals/{$action->id}/approve", [
                'note' => 'Operasyon onayladı.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('request.workflow_status', 'Planlı')
            ->assertJsonPath('request.technician_approval_status', 'onayladı')
            ->assertJsonPath('message_payloads.customer.slot_text', 'öğleden önce')
            ->assertJsonPath('message_payloads.technician.mrn', 'MRN-APPOINTMENT-PROPOSE');

        $portalJobResponse = $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.kanban_column', 'appointment_confirmed');
        $this->assertNotContains('Randevu önerildi', $portalJobResponse->json('job.badges'));

        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'id' => $action->id,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $job->id,
            'event_type' => 'partner_appointment_approved',
        ]);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $job->id,
            'event' => 'appointment_approved_customer',
            'target_type' => 'customer',
            'target_phone' => '905467647428',
            'test_mode' => true,
        ]);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $job->id,
            'event' => 'appointment_approved_technician',
            'target_type' => 'technician',
            'target_phone' => '905467647428',
            'test_mode' => true,
        ]);
    }

    public function test_locksmith_partner_can_reject_job_without_removing_assignment(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Reject Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Reject Portal Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'field_technician',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-REJECT-PORTAL', [
            'workflow_status' => 'Usta Onayı Bekleyen',
            'status' => 'Atandı',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/reject", [
                'reason' => 'time_not_suitable',
                'note' => 'Bu saat uygun değil.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW);

        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $job->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
        ]);
        $this->assertDatabaseHas('technical_service_requests', [
            'id' => $job->id,
            'technical_service_technician_id' => $technician->id,
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $job->id,
            'event_type' => 'partner_portal_job_rejected',
        ]);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $job->id,
            'event' => 'job_rejected_ops',
            'target_type' => 'ops',
            'target_phone' => '905467647428',
            'test_mode' => true,
        ]);

        $newTechnician = $this->technician(['name' => 'Reject Replacement Usta']);
        $job->forceFill([
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ])->save();
        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/assign", [
                'technical_service_technician_id' => $newTechnician->id,
                'travel_round_trip_km' => 20,
                'assignment_offer' => [
                    'labor_amount' => 500,
                    'route_fee_amount' => 50,
                    'total_amount' => 550,
                    'note' => 'Red sonrasi yeni usta atandi.',
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('technical_service_requests', [
            'id' => $job->id,
            'technical_service_technician_id' => $newTechnician->id,
        ]);
        $this->assertDatabaseHas('technical_service_assignment_archives', [
            'technical_service_request_id' => $job->id,
            'old_technician_id' => $technician->id,
            'new_technician_id' => $newTechnician->id,
        ]);
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $job->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
        ]);
        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertForbidden();
    }

    public function test_technical_service_assignment_creates_assignment_offer_for_portal(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Offer Usta']);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Offer Locksmith',
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-OFFER-ASSIGN',
            'customer_name' => 'Offer Musteri',
            'customer_phone' => '+905550000222',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Offer test adresi',
            'product_name' => 'Offer Kilit',
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'workflow_status' => 'Usta Ataması Bekleyen',
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
            'technician_payment_amount' => 900,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'travel_round_trip_km' => 44,
                'assignment_offer' => [
                    'labor_amount' => 900,
                    'route_fee_amount' => 180,
                    'total_amount' => 1080,
                    'note' => 'Atama hakedişi.',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('request.assignment_offer.labor_amount', 900)
            ->assertJsonPath('request.assignment_offer.route_fee_amount', 180)
            ->assertJsonPath('request.assignment_offer.total_amount', 1080);

        $offer = TechnicalServiceAssignmentOffer::query()
            ->where('technical_service_request_id', $job->id)
            ->firstOrFail();
        $this->assertSame(TechnicalServiceAssignmentOffer::STATUS_SENT, $offer->status);
        $this->assertSame(1080.0, (float) $offer->total_amount);
        $this->assertIsArray($offer->metadata['message_payload'] ?? null);
        $this->assertStringContainsString('/partner/service-jobs?job_id='.$job->id, (string) ($offer->metadata['message_payload']['job_link'] ?? ''));
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $job->id,
            'technical_service_assignment_offer_id' => $offer->id,
            'event' => 'assignment_offer_technician',
            'target_type' => 'technician',
            'target_phone' => '905467647428',
            'test_mode' => true,
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $job->id,
            'event_type' => 'assignment_offer_sent',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.assignment_offer.labor_amount', 900)
            ->assertJsonPath('job.assignment_offer.route_fee_amount', 180)
            ->assertJsonPath('job.assignment_offer.total_amount', 1080)
            ->assertJsonPath('job.earning_summary.total_amount', 1080);
    }

    public function test_partner_portal_earning_summary_corrects_stale_earning_message_total(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Stale Total Usta']);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Stale Total Locksmith',
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-STALE-EARNING',
            'customer_name' => 'Stale Musteri',
            'customer_phone' => '+905550000333',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Stale test adresi',
            'product_name' => 'Stale Kilit',
            'serial_number' => 'STALE-SN-1',
            'service_type' => 'Montaj',
            'status' => 'Atandi',
            'workflow_status' => 'Usta Onayi Bekleyen',
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'travel_fee_amount' => 5.4,
            'operation_control_payload' => [
                'technician_earning_message' => [
                    'status' => 'sent',
                    'labor_amount' => 3000,
                    'route_fee_amount' => 5.4,
                    'total_amount' => 30500,
                    'message_text' => 'Toplam hakediş: 30.500,00 TL',
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.earning_summary.labor_amount', 3000)
            ->assertJsonPath('job.earning_summary.route_fee_amount', 5.4)
            ->assertJsonPath('job.earning_summary.total_amount', 3005.4)
            ->assertJsonPath('job.earning_summary.status', 'sent');
    }

    public function test_locksmith_partner_completion_can_use_existing_safe_workflow_when_requirements_are_met(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Direct Complete Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Direct Complete Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'field_technician',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-DIRECT-COMPLETE', [
            'workflow_status' => 'Sahada',
            'status' => 'Devam Ediyor',
            'checklist_status' => 'tamamlandı',
            'checklist_payload' => [
                'Ürün seri numarası kontrol edildi' => true,
                'Kapı / montaj yeri kontrol edildi' => true,
                'Montaj uygunluğu kontrol edildi' => true,
                'Ürün çalışır durumda test edildi' => true,
                'Müşteriye kullanım bilgisi verildi' => true,
                'Garanti / servis formu bilgisi kontrol edildi' => true,
            ],
            'before_photo_count' => 3,
            'after_photo_count' => 3,
            'general_photo_count' => 1,
            'document_status' => 'tamam',
            'customer_closure_approval_status' => 'onaylandı',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $beforeDocument = $this->createPortalFieldDocument($job, 'before_photo');
        $afterDocument = $this->createPortalFieldDocument($job, 'after_photo');
        $warrantyDocument = $this->createPortalFieldDocument($job, 'warranty_document_photo');

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/submit-completion", [
                'result' => 'completed',
                'note' => 'Doğrudan tamamlanabilir şartlar sağlandı.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.kanban_column', 'final_check');

        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $job->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
        ]);

        $action = TechnicalServicePartnerJobAction::query()
            ->where('technical_service_request_id', $job->id)
            ->where('action', TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED)
            ->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/partner-completions/{$action->id}/approve", [
                'note' => 'Operasyon son kontrolu tamamlanmadan evrak uygunlugu kontrol edilir.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('completion');

        $beforeDocument->forceFill(['review_status' => 'accepted'])->save();
        $afterDocument->forceFill(['review_status' => 'accepted'])->save();
        $warrantyDocument->forceFill(['review_status' => 'accepted'])->save();

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/partner-completions/{$action->id}/approve", [
                'note' => 'Operasyon son kontrolü tamamladı.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('request.workflow_status', 'Tamamlandı');
    }

    public function test_partner_portal_users_stay_out_of_internal_panel_routes(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
            'display_name' => 'Portal Isolation Dealer',
            'mikro_cari_kodu' => '120.00.33.12345',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_dealer')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)->get('/partner/dashboard')->assertOk();
        $this->actingAs($portalUser)->get('/panel/b2b')->assertForbidden();
        $this->actingAs($portalUser)->get('/panel/b2b/users')->assertForbidden();
        $this->actingAs($portalUser)->get('/sales/main')->assertForbidden();
        $this->actingAs($portalUser)->get('/stock')->assertForbidden();
        $this->actingAs($portalUser)->get('/orders')->assertForbidden();
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
     * @param  array<string, mixed>  $attributes
     */
    private function serviceRequestForTechnician(TechnicalServiceTechnician $technician, string $mrn, array $attributes = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => $mrn,
            'customer_name' => 'Portal Musteri',
            'customer_phone' => '+905550000001',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Portal test adresi',
            'product_name' => 'Test Kilit',
            'service_type' => 'Montaj',
            'technical_service_technician_id' => $technician->id,
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
        ], $attributes));
    }

    private function createPortalFieldDocument(TechnicalServiceRequest $request, string $fieldCode): TechnicalServiceRequestUpload
    {
        return TechnicalServiceRequestUpload::query()->create([
            'technical_service_request_id' => $request->id,
            'field_code' => $fieldCode,
            'category' => TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT,
            'original_name' => $fieldCode.'.jpg',
            'path' => 'technical-service/test/'.$fieldCode.'.jpg',
            'mime' => 'image/jpeg',
            'size' => 128,
        ]);
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
