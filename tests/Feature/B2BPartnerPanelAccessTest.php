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
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\TechnicalServiceRouteQuote;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
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

    public function test_locksmith_partner_completed_job_appears_in_completed_earnings_before_period_calculation(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Portal Earnings Flow Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Portal Earnings Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $completedJob = $this->serviceRequestForTechnician($technician, 'MRN-EARNING-COMPLETE', [
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now(),
        ]);
        $pendingJob = $this->serviceRequestForTechnician($technician, 'MRN-EARNING-PENDING', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);
        foreach ([[$completedJob, 900, 120], [$pendingJob, 700, 80]] as [$request, $labor, $route]) {
            TechnicalServiceAssignmentOffer::query()->create([
                'technical_service_request_id' => $request->id,
                'technical_service_technician_id' => $technician->id,
                'labor_amount' => $labor,
                'route_fee_amount' => $route,
                'total_amount' => $labor + $route,
                'currency' => 'TRY',
                'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
                'sent_at' => now(),
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
            ->get('/partner/earnings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/earnings')
                ->where('partnerPortal.earnings.pending.summary.job_count', 1)
                ->where('partnerPortal.earnings.pending.rows.0.mrn', 'MRN-EARNING-PENDING')
                ->where('partnerPortal.earnings.completed.summary.job_count', 1)
                ->where('partnerPortal.earnings.completed.rows.0.items.0.mrn', 'MRN-EARNING-COMPLETE')
                ->where('partnerPortal.earnings.completed.rows.0.grand_total', 1020)
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
        $plannedJob = $this->serviceRequestForTechnician($field, 'MRN-KANBAN-PLANLI', [
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
        $this->serviceRequestForTechnician($field, 'MRN-KANBAN-REOPENED', [
            'workflow_status' => 'Yeni Talep',
            'status' => 'Yeni',
            'completed_at' => now()->subDay(),
            'installation_completed_at' => now()->subDay(),
            'reopened_at' => now(),
            'reopen_count' => 1,
            'reopen_reason' => 'Operasyon dÃ¼zeltmesi',
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
            ->assertJsonPath('columns.0.count', 2)
            ->assertJsonPath('columns.1.key', 'appointment_confirmed')
            ->assertJsonPath('columns.1.count', 1)
            ->assertJsonPath('columns.2.key', 'ops_review')
            ->assertJsonPath('columns.2.count', 0)
            ->assertJsonPath('columns.3.key', 'revisit')
            ->assertJsonPath('columns.3.count', 1)
            ->assertJsonPath('columns.4.key', 'final_check')
            ->assertJsonPath('columns.4.count', 0)
            ->assertJsonPath('columns.5.key', 'completed')
            ->assertJsonPath('columns.5.count', 1)
            ->assertJsonPath('appointment_slot_options.0.value', '10:00-11:00')
            ->assertJsonPath('appointment_slot_options.6.value', '16:00-17:00')
            ->assertJsonFragment(['mrn' => 'MRN-KANBAN-REOPENED', 'kanban_column' => 'new_jobs'])
            ->assertJsonMissing(['mrn' => 'MRN-KANBAN-CONTRACTED']);

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$newJob->id}")
            ->assertOk()
            ->assertJsonPath('job.can_accept', false)
            ->assertJsonPath('job.can_propose_appointment', true)
            ->assertJsonPath('job.checklist_payload', []);

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$plannedJob->id}")
            ->assertOk()
            ->assertJsonPath('job.kanban_column', 'appointment_confirmed')
            ->assertJsonPath('job.next_action', 'Fotoğraf bekliyor');

        $plannedJobResponse = $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$plannedJob->id}")
            ->assertOk();
        $this->assertNotContains('Fotoğraf bekliyor', $plannedJobResponse->json('job.badges'));
    }

    public function test_locksmith_partner_service_job_api_rejects_cross_partner_query_and_polling_scope(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $partnerA = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Scope Locksmith A',
        ]);
        $partnerB = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Scope Locksmith B',
        ]);
        $technicianA = $this->technician(['name' => 'Scope Usta A']);
        $technicianB = $this->technician(['name' => 'Scope Usta B']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partnerA->id,
            'technical_service_technician_id' => $technicianA->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partnerB->id,
            'technical_service_technician_id' => $technicianB->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);

        $jobA = $this->serviceRequestForTechnician($technicianA, 'MRN-SCOPE-A');
        $jobB = $this->serviceRequestForTechnician($technicianB, 'MRN-SCOPE-B');
        $completedJobB = $this->serviceRequestForTechnician($technicianB, 'MRN-SCOPE-B-COMPLETED', [
            'workflow_status' => 'TamamlandÄ±',
            'status' => 'TamamlandÄ±',
            'completed_at' => now(),
        ]);
        foreach ([[$jobA, $technicianA], [$jobB, $technicianB], [$completedJobB, $technicianB]] as [$request, $technician]) {
            TechnicalServiceAssignmentOffer::query()->create([
                'technical_service_request_id' => $request->id,
                'technical_service_technician_id' => $technician->id,
                'labor_amount' => 1000,
                'route_fee_amount' => 100,
                'total_amount' => 1100,
                'currency' => 'TRY',
                'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
                'sent_at' => now(),
            ]);
        }

        $userA = $this->userWithRole('b2b_locksmith');
        $userB = $this->userWithRole('b2b_locksmith');
        foreach ([[$userA, $partnerA], [$userB, $partnerB]] as [$user, $partner]) {
            B2BPartnerUserProfile::query()->create([
                'user_id' => $user->id,
                'partner_id' => $partner->id,
                'active' => true,
            ]);
            $this->grantPartnerAccess($user, $partner, 'view');
            $this->grantPartnerAccess($user, $partner, 'technical_service');
        }

        $this->actingAs($userA)
            ->get('/partner/service-jobs?partner_id='.$partnerB->id)
            ->assertForbidden();
        $this->actingAs($userB)
            ->get('/partner/service-jobs?partner_id='.$partnerA->id)
            ->assertForbidden();

        $this->actingAs($userA)
            ->getJson('/api/partner/service-jobs?partner_id='.$partnerB->id)
            ->assertForbidden();
        $this->actingAs($userB)
            ->getJson('/api/partner/service-jobs?partner_id='.$partnerA->id)
            ->assertForbidden();

        $this->actingAs($userA)
            ->getJson('/api/partner/service-jobs?partner_id='.$partnerA->id)
            ->assertOk()
            ->assertJsonPath('partner_id', $partnerA->id)
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-A'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B-COMPLETED']);
        $this->actingAs($userB)
            ->getJson('/api/partner/service-jobs?partner_id='.$partnerB->id)
            ->assertOk()
            ->assertJsonPath('partner_id', $partnerB->id)
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-B'])
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-B-COMPLETED'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-A']);

        $this->actingAs($userA)
            ->getJson('/api/partner/service-jobs')
            ->assertOk()
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-A'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B']);
        $this->actingAs($userA)
            ->getJson('/api/partner/service-jobs/'.$jobB->id)
            ->assertForbidden();

        foreach ([
            'appointment-proposal' => ['slots' => [['date' => now()->addDay()->toDateString(), 'slot' => '10:00-11:00']]],
            'reject' => ['reason' => 'other', 'note' => 'Cross partner forbidden'],
            'photos' => [],
            'customer-otp-request' => ['note' => 'Cross partner forbidden'],
            'submit-completion' => ['result' => 'completed'],
            'note' => ['note' => 'Cross partner forbidden'],
        ] as $action => $payload) {
            $this->actingAs($userA)
                ->postJson("/api/partner/service-jobs/{$jobB->id}/{$action}", $payload)
                ->assertForbidden();
        }

        $this->actingAs($userA)
            ->postJson("/api/partner/service-jobs/{$jobA->id}/appointment-proposal", [
                'slots' => [['date' => now()->addDay()->toDateString(), 'slot' => '10:00-11:00']],
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW);
        $this->actingAs($userA)
            ->getJson('/api/partner/service-jobs?partner_id='.$partnerA->id)
            ->assertOk()
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-A'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B']);

        $this->actingAs($userA)
            ->getJson('/api/partner/earnings?partner_id='.$partnerB->id)
            ->assertForbidden();
        $this->actingAs($userA)
            ->getJson('/api/partner/earnings?partner_id='.$partnerA->id)
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.partner_id', $partnerA->id)
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-A'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B']);

        $jobA->forceFill(['technical_service_technician_id' => $technicianB->id])->save();
        $this->actingAs($userA)
            ->getJson('/api/partner/service-jobs/'.$jobA->id)
            ->assertForbidden();
        $this->actingAs($userA)
            ->getJson('/api/partner/service-jobs?partner_id='.$partnerA->id)
            ->assertOk()
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-A']);
        $this->actingAs($userB)
            ->getJson('/api/partner/service-jobs?partner_id='.$partnerB->id)
            ->assertOk()
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-A'])
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-B']);
    }

    public function test_partner_user_cannot_list_other_partner_jobs_even_with_partner_id_query(): void
    {
        $scope = $this->partnerPortalScopeFixture();

        $this->actingAs($scope['userA'])
            ->get('/partner/service-jobs?partner_id='.$scope['partnerB']->id)
            ->assertForbidden();
        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerB']->id)
            ->assertForbidden();
        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonPath('partner_id', $scope['partnerA']->id)
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-A'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B']);
    }

    public function test_partner_user_cannot_see_completed_jobs_from_other_technician(): void
    {
        $scope = $this->partnerPortalScopeFixture();

        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-A'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B-COMPLETED']);
        $this->actingAs($scope['userB'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerB']->id)
            ->assertOk()
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-B-COMPLETED'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-A']);
    }

    public function test_cancelled_service_job_is_hidden_from_partner_active_portal(): void
    {
        $scope = $this->partnerPortalScopeFixture();
        $scope['jobA']->forceFill([
            'cancelled_at' => now(),
            'status' => 'İptal',
            'workflow_status' => 'İptal',
        ])->save();

        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-A']);
        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs/'.$scope['jobA']->id)
            ->assertForbidden();
    }

    public function test_partner_user_cannot_open_other_partner_job_detail(): void
    {
        $scope = $this->partnerPortalScopeFixture();

        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs/'.$scope['jobB']->id)
            ->assertForbidden();
    }

    public function test_partner_user_cannot_act_on_other_partner_job(): void
    {
        $scope = $this->partnerPortalScopeFixture();

        foreach ($this->partnerPortalCrossPartnerActionPayloads() as $action => $payload) {
            $this->actingAs($scope['userA'])
                ->postJson("/api/partner/service-jobs/{$scope['jobB']->id}/{$action}", $payload)
                ->assertForbidden();
        }
    }

    public function test_appointment_proposal_does_not_expand_partner_scope(): void
    {
        $scope = $this->partnerPortalScopeFixture();

        $this->actingAs($scope['userA'])
            ->postJson("/api/partner/service-jobs/{$scope['jobA']->id}/appointment-proposal", [
                'slots' => [['date' => now()->addDay()->toDateString(), 'slot' => '10:00-11:00']],
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW);
        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-A'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B-COMPLETED']);
    }

    public function test_polling_refresh_keeps_partner_scope(): void
    {
        $scope = $this->partnerPortalScopeFixture();

        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-A'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B']);
        $this->actingAs($scope['userA'])
            ->postJson("/api/partner/service-jobs/{$scope['jobA']->id}/appointment-proposal", [
                'slots' => [['date' => now()->addDay()->toDateString(), 'slot' => '10:00-11:00']],
            ])
            ->assertOk();
        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-A'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B']);
    }

    public function test_reassigned_job_disappears_from_old_technician_portal(): void
    {
        $scope = $this->partnerPortalScopeFixture();

        $scope['jobA']->forceFill(['technical_service_technician_id' => $scope['technicianB']->id])->save();

        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs/'.$scope['jobA']->id)
            ->assertForbidden();
        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-A']);
        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/earnings?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-A']);
        $this->actingAs($scope['userB'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerB']->id)
            ->assertOk()
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-A']);
    }

    public function test_earnings_are_scoped_to_current_partner_technicians(): void
    {
        $scope = $this->partnerPortalScopeFixture();

        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/earnings?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonPath('items.0.partner_id', $scope['partnerA']->id)
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-A'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B-COMPLETED']);
        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/earnings?partner_id='.$scope['partnerB']->id)
            ->assertForbidden();
    }

    public function test_partner_srv_job_earning_summary_sums_parent_and_srv_offers_for_same_technician(): void
    {
        $scope = $this->partnerPortalScopeFixture();
        $parent = $scope['jobA'];
        $child = $this->serviceRequestForTechnician($scope['technicianA'], 'SRV-SCOPE-A-001', [
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-SCOPE-A-001',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Montaj',
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => now(),
        ]);

        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $parent->id,
            'technical_service_technician_id' => $scope['technicianA']->id,
            'labor_amount' => 3000,
            'route_fee_amount' => 0,
            'total_amount' => 3000,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $child->id,
            'technical_service_technician_id' => $scope['technicianA']->id,
            'labor_amount' => 2500,
            'route_fee_amount' => 500,
            'total_amount' => 3000,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_REVISED,
            'sent_at' => now(),
        ]);
        $period = TechnicalServiceEarningsPeriod::query()->create([
            'year' => 2026,
            'month' => 6,
            'status' => 'Hazır',
            'calculated_at' => now(),
        ]);
        $earning = TechnicalServiceEarning::query()->create([
            'period_id' => $period->id,
            'technical_service_technician_id' => $scope['technicianA']->id,
            'technician_name_snapshot' => $scope['technicianA']->name,
            'city_snapshot' => 'İstanbul',
            'job_count' => 1,
            'installation_count' => 0,
            'service_count' => 1,
            'labor_total' => 2500,
            'travel_fee_total' => 500,
            'travel_round_trip_km_total' => 0,
            'travel_billable_km_total' => 0,
            'grand_total' => 3000,
            'status' => 'Hazır',
        ]);
        TechnicalServiceEarningItem::query()->create([
            'earning_id' => $earning->id,
            'technical_service_request_id' => $child->id,
            'mrn' => $child->mrn,
            'job_date' => now(),
            'customer_city' => 'İstanbul',
            'customer_district' => 'Kadıköy',
            'service_type' => 'Servis',
            'product_name' => 'Test Kilit',
            'labor_amount' => 2500,
            'travel_round_trip_km' => 0,
            'travel_billable_km' => 0,
            'travel_fee_amount' => 500,
            'line_total' => 3000,
        ]);

        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs/'.$child->id.'?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonPath('job.service_type', 'Servis')
            ->assertJsonPath('job.earning_summary.labor_amount', 5500)
            ->assertJsonPath('job.earning_summary.route_fee_amount', 500)
            ->assertJsonPath('job.earning_summary.total_amount', 6000)
            ->assertJsonPath('job.earning_summary.job_count', 2);

        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/earnings?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonPath('items.0.earnings.completed.summary.grand_total', 6000)
            ->assertJsonPath('items.0.earnings.completed.rows.0.job_count', 2)
            ->assertJsonFragment(['mrn' => 'SRV-SCOPE-A-001']);
    }

    public function test_partner_srv_grouped_earning_drops_cancelled_related_request(): void
    {
        $scope = $this->partnerPortalScopeFixture();
        $parent = $scope['jobA'];
        $child = $this->serviceRequestForTechnician($scope['technicianA'], 'SRV-SCOPE-A-CANCELLED-001', [
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_code' => 'SRV-SCOPE-A-CANCELLED-001',
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => now(),
        ]);
        $parent->forceFill([
            'status' => 'İptal',
            'workflow_status' => 'İptal',
            'cancelled_at' => now(),
        ])->save();

        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $parent->id,
            'technical_service_technician_id' => $scope['technicianA']->id,
            'labor_amount' => 3000,
            'route_fee_amount' => 0,
            'total_amount' => 3000,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $child->id,
            'technical_service_technician_id' => $scope['technicianA']->id,
            'labor_amount' => 2500,
            'route_fee_amount' => 500,
            'total_amount' => 3000,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs/'.$child->id.'?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonPath('job.earning_summary.labor_amount', 2500)
            ->assertJsonPath('job.earning_summary.route_fee_amount', 500)
            ->assertJsonPath('job.earning_summary.total_amount', 3000)
            ->assertJsonPath('job.earning_summary.job_count', 1);
    }

    public function test_dealer_contracted_technician_does_not_open_field_technician_mrns(): void
    {
        $dealer = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);
        $contracted = $this->technician(['name' => 'Dealer Contracted Scope Usta']);
        $fieldTechnician = $this->technician(['name' => 'Locksmith Field Scope Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $dealer->id,
            'technical_service_technician_id' => $contracted->id,
            'relationship_type' => 'contracted_technician',
            'active' => true,
            'is_primary' => true,
        ]);
        $this->serviceRequestForTechnician($contracted, 'MRN-DEALER-CONTRACTED-SCOPE');
        $this->serviceRequestForTechnician($fieldTechnician, 'MRN-FIELD-SCOPE');

        $this->assertSame([], app(B2BPartnerServiceJobScopeService::class)
            ->serviceJobsQuery($dealer)
            ->pluck('mrn')
            ->all());
    }

    public function test_admin_preview_route_can_preview_but_partner_route_cannot_bypass_scope(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $operationUser = $this->userWithRole('b2b_preview_operator');
        $this->grantPanelResource('b2b_preview_operator', 'b2b.portal_preview.view');
        $scope = $this->partnerPortalScopeFixture(seedPermissions: false);

        $this->actingAs($operationUser)
            ->get("/panel/b2b/partners/{$scope['partnerB']->id}/portal-preview")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('panel/b2b/portal-preview')
                ->where('preview.read_only', true)
                ->where('partnerPortal.selectedPartner.id', $scope['partnerB']->id)
            );
        $this->actingAs($scope['userA'])
            ->get('/partner/service-jobs?partner_id='.$scope['partnerB']->id)
            ->assertForbidden();
        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerB']->id)
            ->assertForbidden();
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
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_APPLIED)
            ->assertJsonPath('job.kanban_column', 'new_jobs')
            ->assertJsonPath('job.can_propose_appointment', true);
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $unplannedAcceptJob->id,
            'partner_id' => $partner->id,
            'user_id' => $portalUser->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_ACCEPTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
        ]);

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
            ->postJson("/api/partner/service-jobs/{$acceptJob->id}/appointment-proposal", [
                'slots' => [['date' => now()->addDays(2)->toDateString(), 'slot' => '14:00-15:00']],
                'note' => 'Müşteri randevu saatini değiştirmek istiyor.',
            ])
            ->assertOk()
            ->assertJsonPath('action', TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED)
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.kanban_column', 'ops_review')
            ->assertJsonPath('job.action_state', 'appointment_change_requested')
            ->assertJsonPath('job.can_request_appointment_change', false);
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $acceptJob->id,
            'partner_id' => $partner->id,
            'user_id' => $portalUser->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
        ]);
        $appointmentChangeState = app(\App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter::class)
            ->present($acceptJob->fresh());
        $this->assertSame('assignment_pending', $appointmentChangeState['ops_column']);
        $this->assertSame('Usta randevu değişikliği istiyor', $appointmentChangeState['display_action_label']);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$revisitJob->id}/request-revisit", ['reason' => 'Müşteri yeni tarih istedi'])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.kanban_column', 'ops_review')
            ->assertJsonPath('job.action_state', 'revisit_requested');
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $revisitJob->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED,
        ]);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$revisitJob->id}/support-request", [
                'type' => 'technical_support',
                'description' => 'Teknik destek gerekiyor.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.kanban_column', 'ops_review')
            ->assertJsonPath('job.action_state', 'support_requested');
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

    public function test_spare_part_request_lifecycle_blocks_completion_and_creates_srv_child(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Part Lifecycle Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Part Lifecycle Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-PART', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => now(),
            'scheduled_at' => now()->addHour(),
            'scheduled_date' => now()->addHour()->toDateString(),
            'scheduled_time' => now()->addHour()->format('H:i'),
            'customer_closure_approval_status' => 'onaylandi',
            'product_model' => 'F3',
            'brand' => 'Emaks Prime',
            'serial_number' => 'SN-PART-001',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
            'operation_control_checked_at' => now(),
            'operation_control_checked_by_user_id' => $admin->id,
        ]);
        $job->requestSerials()->create([
            'mrn' => $job->mrn,
            'serial_number' => 'SN-PART-001',
            'product_name' => 'Test Kilit',
            'product_model' => 'F3',
            'brand' => 'Emaks Prime',
            'customer_selected' => true,
            'customer_visible' => true,
            'customer_selectable' => true,
            'is_primary' => true,
        ]);
        $this->createPortalFieldDocument($job, 'before_photo');
        $this->createPortalFieldDocument($job, 'after_photo');
        $this->createPortalFieldDocument($job, 'warranty_document_photo');

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/support-request", [
                'type' => 'spare_part',
                'description' => 'Kilit karşılığı değişmeli.',
                'product_name' => 'Karşılık sacı',
                'quantity' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('job.kanban_column', 'ops_review')
            ->assertJsonPath('job.active_part_request.status', TechnicalServicePartRequest::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.can_submit_completion', false);

        $partRequest = TechnicalServicePartRequest::query()
            ->where('technical_service_request_id', $job->id)
            ->firstOrFail();
        $this->assertSame('Karşılık sacı', $partRequest->part_name);
        $this->assertSame(2, $partRequest->quantity);
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $job->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
        ]);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/submit-completion", [
                'result' => 'completed',
                'note' => 'Parça açıkken tamamlanamaz.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('part_request');

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$job->id}/part-requests/{$partRequest->id}", [
                'status' => TechnicalServicePartRequest::STATUS_APPROVED,
                'note' => 'Parça onaylandı.',
                'partner_message' => 'Parça talebiniz onaylandı.',
            ])
            ->assertOk()
            ->assertJsonPath('part_request.status', TechnicalServicePartRequest::STATUS_APPROVED)
            ->assertJsonPath('request.active_part_request.status', TechnicalServicePartRequest::STATUS_APPROVED);

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$job->id}/part-requests/{$partRequest->id}", [
                'status' => TechnicalServicePartRequest::STATUS_ORDERED,
                'note' => 'Tedarikte.',
            ])
            ->assertOk()
            ->assertJsonPath('part_request.status', TechnicalServicePartRequest::STATUS_ORDERED);

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$job->id}/part-requests/{$partRequest->id}", [
                'status' => TechnicalServicePartRequest::STATUS_SENT,
                'shipment_provider' => 'Test Kargo',
                'tracking_no' => 'TRK-123',
                'partner_message' => 'Parça kargoya verildi.',
            ])
            ->assertOk()
            ->assertJsonPath('part_request.status', TechnicalServicePartRequest::STATUS_SENT)
            ->assertJsonPath('request.active_part_request.tracking_no', 'TRK-123');

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}?partner_id={$partner->id}")
            ->assertOk()
            ->assertJsonPath('job.can_receive_part', true);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/part-requests/{$partRequest->id}/received")
            ->assertOk()
            ->assertJsonPath('part_request.status', TechnicalServicePartRequest::STATUS_RECEIVED)
            ->assertJsonPath('job.can_receive_part', false);

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$job->id}/part-requests/{$partRequest->id}", [
                'status' => TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED,
                'note' => 'Parça sonrası servis gerekiyor.',
            ])
            ->assertOk()
            ->assertJsonPath('part_request.status', TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED);

        $createSrvResponse = $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/part-requests/{$partRequest->id}/service-visit", [
                'reason' => 'spare_part',
            ])
            ->assertCreated()
            ->assertJsonPath('request.active_part_request.status', null);

        $childId = $createSrvResponse->json('child_request.id');
        $child = TechnicalServiceRequest::query()->findOrFail($childId);
        $this->assertSame($job->id, $child->parent_request_id);
        $this->assertSame($job->mrn, $child->root_mrn);
        $this->assertSame('SRV-ACTION-PART-001', $child->service_code);
        $this->assertSame('SRV-ACTION-PART-001', $child->mrn);
        $this->assertSame('Servis', $child->service_type);
        $this->assertSame('SN-PART-001', $child->serial_number);
        $this->assertDatabaseHas('technical_service_request_serials', [
            'technical_service_request_id' => $child->id,
            'serial_number' => 'SN-PART-001',
            'linked_mrn' => $job->mrn,
        ]);
        $this->assertDatabaseHas('technical_service_part_requests', [
            'id' => $partRequest->id,
            'status' => TechnicalServicePartRequest::STATUS_SERVICE_VISIT_CREATED,
            'service_visit_request_id' => $child->id,
        ]);

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$child->id}?partner_id={$partner->id}")
            ->assertForbidden();

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$child->id}/technician", [
                'technical_service_technician_id' => $technician->id,
                'note' => 'SRV aynı ustaya atandı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.kanban_column', 'assignment_pending');

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$child->id}?partner_id={$partner->id}")
            ->assertOk()
            ->assertJsonPath('job.mrn', $child->mrn);
    }

    public function test_revisit_request_creates_isolated_srv_child_without_parent_completion_state(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Revisit SRV Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Revisit SRV Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-REVISIT-SRV', [
            'workflow_status' => 'PlanlÄ±',
            'status' => 'Randevulu',
            'technician_approval_status' => 'onayladÄ±',
            'technician_approved_at' => now(),
            'scheduled_at' => now()->addDay(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '14:00',
            'customer_closure_approval_status' => 'onaylandÄ±',
            'customer_closure_approved_at' => now(),
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
            'operation_control_checked_at' => now(),
            'operation_control_checked_by_user_id' => $admin->id,
            'serial_number' => 'SN-REVISIT-SRV-001',
        ]);
        $job->requestSerials()->create([
            'mrn' => $job->mrn,
            'serial_number' => 'SN-REVISIT-SRV-001',
            'product_name' => 'Test Kilit',
            'customer_selected' => true,
            'customer_visible' => true,
            'customer_selectable' => true,
            'is_primary' => true,
        ]);
        foreach (['before_photo', 'after_photo', 'warranty_document_photo'] as $fieldCode) {
            $this->createPortalFieldDocument($job, $fieldCode);
        }
        $job->customerConfirmations()->create([
            'token' => 'revisit-parent-approved-token',
            'status' => TechnicalServiceCustomerConfirmation::STATUS_APPROVED,
            'approved_at' => now(),
            'payload' => [],
        ]);
        TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $job->id,
            'partner_id' => $partner->id,
            'user_id' => $admin->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
            'note' => 'Eski ziyaret tamamlaması',
            'payload' => [
                'ops_final_check_required' => true,
                'resolved_by_reassignment' => true,
            ],
        ]);

        $parentState = app(\App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter::class)->present($job->fresh());
        $this->assertFalse($parentState['is_pending_final_check']);
        $this->assertSame('assigned', $parentState['ops_column']);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/request-revisit", ['reason' => 'MÃ¼ÅŸteri tekrar kontrol istedi'])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.kanban_column', 'ops_review')
            ->assertJsonPath('job.can_submit_completion', false);

        $job->refresh();
        $this->assertSame('PlanlÄ±', $job->workflow_status);
        $this->assertFalse((bool) $job->requires_second_visit);

        $revisitAction = TechnicalServicePartnerJobAction::query()
            ->where('technical_service_request_id', $job->id)
            ->where('action', TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED)
            ->firstOrFail();

        $createSrvResponse = $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/partner-revisits/{$revisitAction->id}/service-visit", [
                'note' => 'Tekrar ziyaret iÃ§in SRV aÃ§Ä±ldÄ±.',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'created');

        $childId = $createSrvResponse->json('child_request.id');
        $child = TechnicalServiceRequest::query()->findOrFail($childId);
        $this->assertSame($job->id, $child->parent_request_id);
        $this->assertSame($job->mrn, $child->root_mrn);
        $this->assertSame('SRV-ACTION-REVISIT-SRV-001', $child->service_code);
        $this->assertSame('SRV-ACTION-REVISIT-SRV-001', $child->mrn);
        $this->assertSame('Servis', $child->service_type);
        $this->assertSame('revisit', $child->service_visit_reason);
        $this->assertSame($revisitAction->id, $child->source_partner_action_id);
        $this->assertSame('Yeni Talep', $child->workflow_status);
        $this->assertNull($child->technical_service_technician_id);
        $this->assertNull($child->scheduled_at);
        $this->assertNull($child->technician_approved_at);
        $this->assertNull($child->customer_closure_approval_status);
        $this->assertSame(0, $child->uploads()->count());
        $this->assertSame(0, $child->customerConfirmations()->count());
        $this->assertFalse($child->partnerJobActions()->where('action', TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED)->exists());
        $this->assertDatabaseHas('technical_service_request_serials', [
            'technical_service_request_id' => $child->id,
            'serial_number' => 'SN-REVISIT-SRV-001',
            'linked_mrn' => $job->mrn,
        ]);

        $childState = app(\App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter::class)->present($child->fresh());
        $this->assertSame('new', $childState['ops_column']);
        $this->assertFalse($childState['is_pending_final_check']);

        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'id' => $revisitAction->id,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
        ]);
        $this->assertDatabaseMissing('technical_service_partner_job_actions', [
            'technical_service_request_id' => $job->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
        ]);
        $revisitAction->refresh();
        $this->assertSame($child->id, $revisitAction->payload['service_visit_created']['request_id'] ?? null);

        $job->refresh();
        $this->assertSame('srv_delegated', $job->field_status);
        $this->assertSame('SRV ile takip ediliyor', $job->next_action);
        $this->assertFalse((bool) $job->requires_second_visit);
        $delegatedParentState = app(\App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter::class)->present($job->fresh());
        $this->assertNotSame('review', $delegatedParentState['ops_column']);
        $this->assertNotSame('revisit', $delegatedParentState['partner_column']);
        $this->assertFalse($delegatedParentState['requires_ops_action']);

        $opsList = $this->actingAs($admin)
            ->getJson('/api/technical-service/requests?limit=200')
            ->assertOk();
        $opsMrns = collect($opsList->json('items'))->pluck('mrn')->all();
        $this->assertNotContains($job->mrn, $opsMrns);
        $this->assertContains($child->mrn, $opsMrns);

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$child->id}?partner_id={$partner->id}")
            ->assertForbidden();

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$child->id}/technician", [
                'technical_service_technician_id' => $technician->id,
                'note' => 'SRV tekrar ziyaret atamasÄ±.',
            ])
            ->assertOk()
            ->assertJsonPath('request.kanban_column', 'assignment_pending');

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$child->id}?partner_id={$partner->id}")
            ->assertOk()
            ->assertJsonPath('job.kanban_column', 'new_jobs')
            ->assertJsonPath('job.can_propose_appointment', true)
            ->assertJsonPath('job.can_submit_completion', false);
    }

    public function test_partner_active_jobs_hides_parent_when_srv_child_is_active(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'SRV Duplicate Scope Locksmith',
        ]);
        $technician = $this->technician(['name' => 'SRV Duplicate Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);

        $parent = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-SRV-DUP-PARENT', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'technician_approval_status' => 'onaylandi',
            'technician_approved_at' => now(),
            'scheduled_at' => now()->addDay(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '14:00',
            'requires_second_visit' => true,
            'field_status' => 'beklemede',
            'next_action' => 'Tekrar ziyaret planlanmalı',
        ]);
        TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $parent->id,
            'partner_id' => $partner->id,
            'user_id' => $admin->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'note' => 'Legacy aktif tekrar ziyaret talebi',
            'payload' => ['reason' => 'Legacy parent state'],
        ]);
        $child = $this->serviceRequestForTechnician($technician, 'SRV-ACTION-SRV-DUP-001', [
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-ACTION-SRV-DUP-001',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Montaj',
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'technician_approval_status' => 'onaylandi',
            'technician_approved_at' => now(),
            'scheduled_at' => now()->addDays(2),
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'scheduled_time' => '15:00',
        ]);
        $parentState = app(\App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter::class)->present($parent->fresh());
        $this->assertNotSame('review', $parentState['ops_column']);
        $this->assertNotSame('revisit', $parentState['partner_column']);
        $this->assertFalse($parentState['requires_ops_action']);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $response = $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs?partner_id={$partner->id}")
            ->assertOk();
        $mrns = collect($response->json('jobs'))->pluck('mrn')->all();

        $this->assertNotContains($parent->mrn, $mrns);
        $this->assertContains($child->mrn, $mrns);
        $this->assertSame('Servis', collect($response->json('jobs'))->firstWhere('mrn', $child->mrn)['service_type']);

        $opsList = $this->actingAs($admin)
            ->getJson('/api/technical-service/requests?limit=200')
            ->assertOk();
        $opsItems = collect($opsList->json('items'));
        $this->assertFalse($opsItems->contains(fn (array $item): bool => $item['mrn'] === $parent->mrn));
        $this->assertTrue($opsItems->contains(fn (array $item): bool => $item['mrn'] === $child->mrn && $item['service_type'] === 'Servis'));

        $operationsDashboard = $this->actingAs($admin)
            ->getJson('/api/technical-service/operations-dashboard')
            ->assertOk();
        $dashboardMrns = collect($operationsDashboard->json('today_appointments') ?? [])
            ->concat($operationsDashboard->json('overdue_requests') ?? [])
            ->concat($operationsDashboard->json('past_scheduled_not_completed') ?? [])
            ->pluck('mrn')
            ->all();
        $this->assertNotContains($parent->mrn, $dashboardMrns);

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$parent->id}?partner_id={$partner->id}")
            ->assertForbidden();

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$child->id}?partner_id={$partner->id}")
            ->assertOk()
            ->assertJsonPath('job.service_visit_context.root_mrn', $parent->mrn);

        $parent->forceFill([
            'workflow_status' => 'TamamlandÄ±',
            'status' => 'TamamlandÄ±',
            'completed_at' => now(),
        ])->save();
        $child->forceFill([
            'workflow_status' => 'TamamlandÄ±',
            'status' => 'TamamlandÄ±',
            'completed_at' => now(),
        ])->save();

        $completedResponse = $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs?partner_id={$partner->id}")
            ->assertOk();
        $completedMrns = collect($completedResponse->json('jobs'))->pluck('mrn')->all();
        $this->assertNotContains($parent->mrn, $completedMrns);
        $this->assertContains($child->mrn, $completedMrns);
    }

    public function test_completing_srv_child_closes_parent_when_parent_not_cancelled(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'SRV Parent Close Usta']);
        $parent = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-SRV-CLOSE-PARENT', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);
        $child = $this->serviceRequestForTechnician($technician, 'SRV-ACTION-SRV-CLOSE-001', [
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-ACTION-SRV-CLOSE-001',
            'service_visit_reason' => 'revisit',
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now(),
        ]);

        $closedParent = app(\App\Services\TechnicalService\TechnicalServiceServiceVisitService::class)
            ->closeParentIfChildCompleted($child, $admin);

        $this->assertNotNull($closedParent);
        $parent->refresh();
        $this->assertSame('Tamamlandı', $parent->workflow_status);
        $this->assertSame('Tamamlandı', $parent->status);
        $this->assertNotNull($parent->completed_at);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $parent->id,
            'event_type' => 'srv_child_completed_parent_closed',
        ]);
    }

    public function test_completing_srv_child_does_not_close_cancelled_parent(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'SRV Cancelled Parent Usta']);
        $parent = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-SRV-CANCELLED-PARENT', [
            'workflow_status' => 'İptal',
            'status' => 'İptal',
            'cancelled_at' => now(),
        ]);
        $child = $this->serviceRequestForTechnician($technician, 'SRV-ACTION-SRV-CANCELLED-001', [
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-ACTION-SRV-CANCELLED-001',
            'service_visit_reason' => 'revisit',
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now(),
        ]);

        $closedParent = app(\App\Services\TechnicalService\TechnicalServiceServiceVisitService::class)
            ->closeParentIfChildCompleted($child, $admin);

        $this->assertNull($closedParent);
        $parent->refresh();
        $this->assertSame('İptal', $parent->workflow_status);
        $this->assertNull($parent->completed_at);
        $this->assertDatabaseMissing('technical_service_request_events', [
            'technical_service_request_id' => $parent->id,
            'event_type' => 'srv_child_completed_parent_closed',
        ]);
    }

    public function test_partner_part_request_review_label_is_partner_friendly(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Partner Friendly Part Label Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Partner Friendly Part Label Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-PART-LABEL-PARTNER', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => now(),
            'scheduled_at' => now()->addHour(),
            'scheduled_date' => now()->addHour()->toDateString(),
            'scheduled_time' => now()->addHour()->format('H:i'),
        ]);

        $this->actingAs($this->userWithRole('admin', true))
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $response = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/support-request", [
                'type' => 'spare_part',
                'description' => 'Parça operasyon incelemesine düşmeli.',
                'product_name' => 'Karşılık sacı',
            ])
            ->assertOk()
            ->assertJsonPath('job.active_part_request.status', TechnicalServicePartRequest::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.next_action', 'Parça talebi operasyon incelemesinde')
            ->assertJsonPath('job.active_part_request.status_label', 'Parça talebi operasyon incelemesinde')
            ->assertJsonPath('job.completion_requirements.part_request_status_label', 'Parça talebi operasyon incelemesinde');

        $this->assertNotContains('Aksiyon: Parça talebi incelenmeli', $response->json('job.badges', []));
    }

    public function test_ops_part_request_review_label_stays_action_oriented(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Ops Part Label Usta']);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-PART-LABEL-OPS', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'technician_approval_status' => 'onayladı',
        ]);
        $partRequest = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $job->id,
            'root_request_id' => $job->id,
            'requested_by_user_id' => $admin->id,
            'requested_by_technician_id' => $technician->id,
            'status' => TechnicalServicePartRequest::STATUS_OPS_REVIEW,
            'part_name' => 'Karşılık sacı',
            'quantity' => 1,
            'technician_note' => 'Parça ops incelemesinde.',
        ]);

        $this->assertSame('Parça talebi incelenmeli', $partRequest->statusLabel());
        $this->assertSame('Parça talebi operasyon incelemesinde', $partRequest->partnerStatusLabel());

        $this->actingAs($admin)
            ->getJson("/api/technical-service/requests/{$job->id}")
            ->assertOk()
            ->assertJsonPath('request.active_part_request.status', TechnicalServicePartRequest::STATUS_OPS_REVIEW)
            ->assertJsonPath('request.active_part_request.status_label', 'Parça talebi incelenmeli');
    }

    public function test_srv_detail_shows_root_mrn_history(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'SRV History Usta']);
        $parent = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-SRV-HISTORY', [
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now()->subHour(),
            'serial_number' => 'SN-SRV-HISTORY-001',
        ]);
        $parent->events()->create([
            'event_type' => 'completion_submitted',
            'title' => 'Tamamlamaya gönderildi',
            'from_status' => 'Planlı',
            'to_status' => 'Son Kontrol',
            'author_user_id' => $admin->id,
            'metadata' => [],
        ]);
        $partRequest = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $parent->id,
            'root_request_id' => $parent->id,
            'requested_by_user_id' => $admin->id,
            'requested_by_technician_id' => $technician->id,
            'status' => TechnicalServicePartRequest::STATUS_SERVICE_VISIT_CREATED,
            'part_name' => 'Karşılık sacı',
            'quantity' => 1,
        ]);
        $child = TechnicalServiceRequest::query()->create([
            'mrn' => 'SRV-ACTION-SRV-HISTORY-001',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-ACTION-SRV-HISTORY-001',
            'service_visit_reason' => 'spare_part',
            'source_part_request_id' => $partRequest->id,
            'customer_name' => $parent->customer_name,
            'customer_phone' => $parent->customer_phone,
            'customer_city' => $parent->customer_city,
            'customer_district' => $parent->customer_district,
            'service_address' => $parent->service_address,
            'product_name' => $parent->product_name,
            'product_model' => $parent->product_model,
            'serial_number' => $parent->serial_number,
            'service_type' => $parent->service_type,
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'priority' => TechnicalServiceRequest::PRIORITY_MEDIUM,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/technical-service/requests/{$child->id}")
            ->assertOk()
            ->assertJsonPath('request.service_visit_history.root_mrn', $parent->mrn)
            ->assertJsonPath('request.service_visit_history.service_code', 'SRV-ACTION-SRV-HISTORY-001')
            ->assertJsonPath('request.service_visit_history.reason_label', 'Parça sonrası servis')
            ->assertJsonPath('request.service_visit_history.parent_request.mrn', $parent->mrn)
            ->assertJsonPath('request.service_visit_history.parent_part_requests.0.part_name', 'Karşılık sacı')
            ->assertJsonPath('request.service_visit_history.parent_events.0.event_type_label', 'Tamamlamaya gönderildi');
    }

    public function test_srv_child_does_not_inherit_parent_completion_gate(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'SRV Gate Locksmith',
        ]);
        $technician = $this->technician(['name' => 'SRV Gate Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $parent = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-SRV-GATE', [
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'customer_closure_approval_status' => 'onaylandi',
            'technician_approval_status' => 'onayladı',
            'serial_number' => 'SN-SRV-GATE-001',
        ]);
        foreach (['before_photo', 'after_photo', 'warranty_document_photo'] as $fieldCode) {
            $this->createPortalFieldDocument($parent, $fieldCode);
        }
        $child = TechnicalServiceRequest::query()->create([
            'mrn' => 'SRV-ACTION-SRV-GATE-001',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-ACTION-SRV-GATE-001',
            'service_visit_reason' => 'spare_part',
            'technical_service_technician_id' => $technician->id,
            'customer_name' => $parent->customer_name,
            'customer_phone' => $parent->customer_phone,
            'customer_city' => $parent->customer_city,
            'customer_district' => $parent->customer_district,
            'service_address' => $parent->service_address,
            'product_name' => $parent->product_name,
            'product_model' => $parent->product_model,
            'serial_number' => $parent->serial_number,
            'service_type' => $parent->service_type,
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => now(),
            'scheduled_at' => now()->addHour(),
            'scheduled_date' => now()->addHour()->toDateString(),
            'scheduled_time' => now()->addHour()->format('H:i'),
            'priority' => TechnicalServiceRequest::PRIORITY_MEDIUM,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$child->id}?partner_id={$partner->id}")
            ->assertOk()
            ->assertJsonPath('job.service_visit_context.root_mrn', $parent->mrn)
            ->assertJsonPath('job.service_visit_context.service_code', 'SRV-ACTION-SRV-GATE-001')
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 0)
            ->assertJsonPath('job.completion_requirements.customer_confirmation_ready', false);
    }

    public function test_ops_can_reject_spare_part_request_with_note_and_partner_cannot_act_on_other_partner_part_request(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Reject Part Locksmith',
        ]);
        $otherPartner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Other Part Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Reject Part Usta']);
        $otherTechnician = $this->technician(['name' => 'Other Part Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $otherPartner->id,
            'technical_service_technician_id' => $otherTechnician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-PART-REJECT', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'technician_approval_status' => 'onayladı',
        ]);
        $otherJob = $this->serviceRequestForTechnician($otherTechnician, 'MRN-ACTION-PART-OTHER', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'technician_approval_status' => 'onayladı',
        ]);
        $otherPartRequest = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $otherJob->id,
            'root_request_id' => $otherJob->id,
            'requested_by_user_id' => null,
            'requested_by_technician_id' => $otherTechnician->id,
            'status' => TechnicalServicePartRequest::STATUS_SENT,
            'part_name' => 'Başka partner parçası',
            'quantity' => 1,
        ]);

        $this->actingAs($admin)->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")->assertCreated();
        $this->actingAs($admin)->postJson("/api/b2b/partners/{$otherPartner->id}/provision-admin-user")->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/support-request", [
                'type' => 'spare_part',
                'description' => 'Parça uyumsuz.',
                'product_name' => 'Menteşe',
            ])
            ->assertOk();
        $partRequest = TechnicalServicePartRequest::query()
            ->where('technical_service_request_id', $job->id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$job->id}/part-requests/{$partRequest->id}", [
                'status' => TechnicalServicePartRequest::STATUS_REJECTED,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$job->id}/part-requests/{$partRequest->id}", [
                'status' => TechnicalServicePartRequest::STATUS_REJECTED,
                'note' => 'Parça gerekmiyor.',
                'partner_message' => 'Parça talebi reddedildi.',
            ])
            ->assertOk()
            ->assertJsonPath('part_request.status', TechnicalServicePartRequest::STATUS_REJECTED)
            ->assertJsonPath('request.active_part_request', null);

        $this->assertDatabaseHas('technical_service_part_requests', [
            'id' => $partRequest->id,
            'status' => TechnicalServicePartRequest::STATUS_REJECTED,
            'ops_note' => 'Parça gerekmiyor.',
        ]);

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$otherJob->id}?partner_id={$otherPartner->id}")
            ->assertForbidden();
        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$otherJob->id}/part-requests/{$otherPartRequest->id}/received")
            ->assertForbidden();
    }

    public function test_locksmith_partner_can_upload_heic_and_submit_completion_without_note(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        Storage::fake('public');
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Heic Completion Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Heic Portal Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-HEIC-COMPLETE', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'customer_closure_approval_status' => 'onaylandi',
        ]);
        $this->createPortalFieldDocument($job, 'after_photo');
        $this->createPortalFieldDocument($job, 'warranty_document_photo');

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before.HEIC', 256, 'image/heic'),
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.photos_ready', true);

        $upload = TechnicalServiceRequestUpload::query()
            ->where('technical_service_request_id', $job->id)
            ->where('field_code', 'before_photo')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('image/heic', $upload->mime);
        $this->assertStringEndsWith('.heic', $upload->path);
        Storage::disk('public')->assertExists($upload->path);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/submit-completion", [
                'result' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.kanban_column', 'final_check');

        $completionAction = TechnicalServicePartnerJobAction::query()
            ->where('technical_service_request_id', $job->id)
            ->where('action', TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED)
            ->firstOrFail();
        $this->assertNull($completionAction->note);

        $job->refresh();
        $this->assertSame('Son Kontrol', $job->status);
        $this->assertSame('Son Kontrol', $job->workflow_status);
        $this->assertSame('son_kontrol', $job->field_status);
        $this->assertNull($job->completed_at);
        $this->assertNull($job->technician_completed_at);
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
            'next_action' => 'Saha süreci bekleniyor',
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
        $oldConfirmation = TechnicalServiceCustomerConfirmation::query()->create([
            'technical_service_request_id' => $job->id,
            'token' => 'old-partner-approval-token',
            'status' => TechnicalServiceCustomerConfirmation::STATUS_PENDING,
            'payload' => ['partner_id' => $partner->id],
        ]);

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
            'services.evolution.allow_unit_test_http_fake' => true,
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
                'message_text' => "Özel onay mesajı\nTalep: {$job->mrn}",
            ])
            ->assertOk()
            ->assertJsonPath('action', TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED)
            ->assertJsonPath('dispatch.dispatch_status', 'sent')
            ->assertJsonPath('dispatch.target_phone', '905467647428')
            ->assertJsonPath('job.completion_requirements.customer_confirmation_ready', false)
            ->assertJsonPath('job.customer_otp_request.payload.message_payload.dispatch_status', 'sent')
            ->assertJsonPath('message', 'WhatsApp onay mesajı gönderildi.');

        $this->assertDatabaseHas('technical_service_customer_confirmations', [
            'id' => $oldConfirmation->id,
            'status' => TechnicalServiceCustomerConfirmation::STATUS_CANCELLED,
        ]);
        $this->post("/service-job-confirmation/{$oldConfirmation->token}/approve")
            ->assertStatus(410);
        $this->assertDatabaseMissing('technical_service_partner_job_actions', [
            'technical_service_request_id' => $job->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_CONFIRMED,
        ]);

        $confirmation = TechnicalServiceCustomerConfirmation::query()
            ->where('technical_service_request_id', $job->id)
            ->where('status', TechnicalServiceCustomerConfirmation::STATUS_PENDING)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(TechnicalServiceCustomerConfirmation::STATUS_PENDING, $confirmation->status);
        $messageText = (string) ($confirmation->payload['message_payload']['message_text'] ?? '');
        $this->assertStringContainsString('Özel onay mesajı', $messageText);
        $this->assertStringContainsString("Talep: {$job->mrn}", $messageText);
        $this->assertStringContainsString("\nhttps://portal.test/service-job-confirmation/{$confirmation->token}", $messageText);
        $this->assertStringNotContainsString('127.0.0.1', $messageText);
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
            && str_contains((string) $request['message_text'], 'Özel onay mesajı')
            && str_contains((string) $request['message_text'], "https://portal.test/service-job-confirmation/{$confirmation->token}")
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
        $this->assertSame('Son Kontrol', $job->status);
        $this->assertSame('Son Kontrol', $job->workflow_status);
        $this->assertSame('son_kontrol', $job->field_status);
        $this->assertNull($job->completed_at);
    }

    public function test_partner_customer_approval_is_blocked_until_three_field_docs_uploaded(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Photo Gate Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Photo Gate Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-PHOTO-GATE-OTP', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => now(),
            'scheduled_at' => now()->addDay(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '14:00',
            'customer_phone' => '05551112233',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/customer-otp-request", [
                'note' => 'Fotoğraf olmadan müşteri onayı istenmemeli.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photos');

        $this->assertDatabaseMissing('technical_service_customer_confirmations', [
            'technical_service_request_id' => $job->id,
        ]);
        $this->assertDatabaseMissing('technical_service_partner_job_actions', [
            'technical_service_request_id' => $job->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED,
        ]);
    }

    public function test_mrn_like_sebrsovsl3_flow_docs_approval_completion_submit_reaches_final_review(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        config([
            'services.partner_portal.public_url' => 'https://portal.test',
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.real_send_enabled' => true,
            'services.evolution.allow_unit_test_http_fake' => true,
        ]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'SEBRSOVSL3 Regression Locksmith',
        ]);
        $technician = $this->technician(['name' => 'SEBRSOVSL3 Regression Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-SEBRSOVSL3-REGRESSION', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'field_status' => 'planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => now(),
            'scheduled_at' => now()->addDay(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '14:00',
            'customer_phone' => '05551112233',
        ]);
        $this->createPortalFieldDocument($job, 'before_photo');
        $this->createPortalFieldDocument($job, 'after_photo');
        $this->createPortalFieldDocument($job, 'warranty_document_photo');

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/customer-otp-request", [
                'note' => 'Müşteri onayı alındıktan sonra tamamlama gönderilecek.',
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.customer_confirmation_ready', false);

        $confirmation = TechnicalServiceCustomerConfirmation::query()
            ->where('technical_service_request_id', $job->id)
            ->where('status', TechnicalServiceCustomerConfirmation::STATUS_PENDING)
            ->latest('id')
            ->firstOrFail();

        $this->post("/service-job-confirmation/{$confirmation->token}/approve", [
            'customer_note' => 'Montaj onaylandı.',
        ])->assertOk();

        $readyResponse = $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.next_action', 'Tamamlamaya gönderilebilir')
            ->assertJsonPath('job.action_state', 'completion_ready')
            ->assertJsonPath('job.can_submit_completion', true)
            ->assertJsonPath('job.completion_requirements.photos_ready', true)
            ->assertJsonPath('job.completion_requirements.customer_confirmation_ready', true);
        $this->assertContains('Tamamlamaya gönderilebilir', $readyResponse->json('job.badges'));
        $this->assertNotContains('Aksiyon: Randevu onaylandı', $readyResponse->json('job.badges'));

        $job->refresh();
        $this->assertSame('onaylandı', $job->customer_closure_approval_status);
        $this->assertSame('Randevulu', $job->status);
        $this->assertSame('Planlı', $job->workflow_status);
        $this->assertNull($job->completed_at);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/submit-completion", [
                'result' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.kanban_column', 'final_check')
            ->assertJsonPath('job.action_state', 'final_check_waiting');

        $job->refresh();
        $this->assertSame('Son Kontrol', $job->status);
        $this->assertSame('Son Kontrol', $job->workflow_status);
        $this->assertSame('son_kontrol', $job->field_status);
        $this->assertNull($job->completed_at);
        $this->assertNull($job->technician_completed_at);
        $state = app(\App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter::class)->present($job->fresh());
        $this->assertTrue($state['is_pending_final_check']);
        $this->assertSame('final_check', $state['ops_column']);
        $this->assertSame('final_check', $state['partner_column']);
    }

    public function test_partner_completion_ready_cta_is_primary_on_mobile(): void
    {
        $source = file_get_contents(resource_path('js/pages/partner/portal-shell.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('jobReadyForCompletionSubmit', $source);
        $this->assertStringContainsString('Saha belgeleri ve müşteri onayı tamam. İşi operasyon son kontrolüne gönderebilirsiniz.', $source);
        $this->assertStringContainsString('Ana aksiyon', $source);
        $this->assertStringContainsString('col-span-2 min-h-12 rounded-xl border border-emerald-700 bg-emerald-600', $source);
        $this->assertStringContainsString('Tamamlamaya gönderilebilir', $source);
    }

    public function test_customer_approval_reject_blocks_completion_and_raises_ops_action(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.real_send_enabled' => true,
            'services.evolution.allow_unit_test_http_fake' => true,
        ]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Reject Approval Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Reject Approval Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-CUSTOMER-REJECT-BLOCKS-COMPLETE', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);
        $this->createPortalFieldDocument($job, 'before_photo');
        $this->createPortalFieldDocument($job, 'after_photo');
        $this->createPortalFieldDocument($job, 'warranty_document_photo');

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();
        $otpAction = TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $job->id,
            'partner_id' => $partner->id,
            'user_id' => $portalUser->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_SUBMITTED,
            'payload' => ['note' => 'Müşteri onay bağlantısı hazırlandı.'],
            'note' => 'Müşteri onay bağlantısı hazırlandı.',
        ]);
        $confirmation = TechnicalServiceCustomerConfirmation::query()->create([
            'technical_service_request_id' => $job->id,
            'token' => 'customer-reject-blocks-complete-token',
            'status' => TechnicalServiceCustomerConfirmation::STATUS_PENDING,
            'payload' => ['partner_action_id' => $otpAction->id],
        ]);

        $this->post("/service-job-confirmation/{$confirmation->token}/reject", [
            'customer_note' => 'Montajı kabul etmiyorum.',
        ])->assertOk();

        $this->assertDatabaseHas('technical_service_customer_confirmations', [
            'id' => $confirmation->id,
            'status' => TechnicalServiceCustomerConfirmation::STATUS_REJECTED,
        ]);
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $job->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
        ]);
        $job->refresh();
        $this->assertSame('reddedildi', $job->customer_closure_approval_status);
        $this->assertSame('Müşteri montaj onayını reddetti.', $job->completion_block_reason);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/submit-completion", [
                'result' => 'completed',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_confirmation');
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

    public function test_partner_customer_approval_sheet_has_mobile_editable_message_contract(): void
    {
        $source = file_get_contents(resource_path('js/pages/partner/portal-shell.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('WhatsApp mesaj metni', $source);
        $this->assertStringContainsString('message_text: messageText', $source);
        $this->assertStringContainsString('w-[min(100%,calc(100dvw-1rem))]', $source);
        $this->assertStringContainsString('overflow-x-hidden', $source);
        $this->assertStringContainsString('Linki kopyala', $source);
    }

    public function test_ops_customer_approval_modal_exposes_copyable_link(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('customerApprovalModalOpen', $source);
        $this->assertStringContainsString('role="dialog"', $source);
        $this->assertStringContainsString('Müşteri onayı / OTP', $source);
        $this->assertStringContainsString('Onay linkini kopyala', $source);
        $this->assertStringContainsString('Mesaj metnini kopyala', $source);
        $this->assertStringContainsString('WhatsApp mesajını aç', $source);
        $this->assertStringContainsString('Kopyalama başarısız', $source);
    }

    public function test_ops_customer_approval_inline_is_compact(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('setCustomerApprovalModalOpen(true)', $source);
        $this->assertStringContainsString('{customerApprovalModalOpen && latestCustomerApprovalUrl ? (', $source);
        $this->assertStringContainsString('{customerApprovalModalOpen && latestCustomerApprovalMessageText ? (', $source);
        $this->assertStringContainsString('Onay mesajını tekrar gönder', $source);
    }

    public function test_customer_approval_link_copy_available_when_whatsapp_suppressed(): void
    {
        config()->set('services.evolution.real_send_enabled', false);
        config()->set('services.evolution.test_mode', true);
        config()->set('services.evolution.test_phone', '905467647428');
        config()->set('app.url', 'https://panel.test');
        config()->set('services.partner_portal.public_url', 'https://panel.test');

        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Suppressed Approval Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Suppressed Approval Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-APPROVAL-COPY', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/customer-approval-requests", [
                'note' => 'Ops linki tekrar istedi.',
            ])
            ->assertOk()
            ->assertJsonPath('dispatch.dispatch_status', 'suppressed_testing_environment')
            ->assertJsonPath('request.partner_portal_actions.0.action', TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED);

        $messagePayload = $response->json('request.partner_portal_actions.0.payload.message_payload');
        $this->assertIsArray($messagePayload);
        $this->assertStringStartsWith('https://panel.test/service-job-confirmation/', $messagePayload['approval_url'] ?? '');
        $this->assertStringStartsWith('https://panel.test/service-job-confirmation/', $messagePayload['confirmation_url'] ?? '');
        $this->assertNotEmpty($messagePayload['message_text'] ?? null);
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
            'workflow_status' => 'Son Kontrol',
            'field_status' => 'son_kontrol',
            'completed_at' => null,
        ]);
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $job->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_CONFIRMED,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
        ]);
    }

    public function test_ops_can_resend_customer_approval_with_new_token_and_force_whatsapp_dispatch(): void
    {
        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.real_send_enabled' => true,
            'services.evolution.allow_unit_test_http_fake' => true,
            'services.partner_portal.public_url' => 'https://panel.test',
        ]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['message' => 'Workflow was started'], 200),
        ]);

        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Approval Resend Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Approval Resend Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-APPROVAL-RESEND', [
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'customer_name' => 'Onay Test Müşteri',
            'customer_phone' => '05551112233',
        ]);
        $oldConfirmation = TechnicalServiceCustomerConfirmation::query()->create([
            'technical_service_request_id' => $job->id,
            'token' => 'old-approval-token',
            'status' => TechnicalServiceCustomerConfirmation::STATUS_PENDING,
            'payload' => ['partner_id' => $partner->id],
        ]);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/customer-approval-requests", [
                'note' => 'Müşteriye tekrar gönder.',
            ])
            ->assertOk()
            ->assertJsonPath('dispatch.dispatch_status', 'sent')
            ->assertJsonPath('dispatch.target_phone', '905467647428');

        $this->assertDatabaseHas('technical_service_customer_confirmations', [
            'id' => $oldConfirmation->id,
            'status' => TechnicalServiceCustomerConfirmation::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $job->id,
            'partner_id' => $partner->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_SUBMITTED,
        ]);
        $this->assertSame(1, TechnicalServiceCustomerConfirmation::query()
            ->where('technical_service_request_id', $job->id)
            ->where('status', TechnicalServiceCustomerConfirmation::STATUS_PENDING)
            ->count());
        Http::assertSent(fn ($request): bool => $request['event'] === 'customer_approval_request'
            && $request['target_phone'] === '905467647428'
            && str_starts_with((string) $request['confirmation_url'], 'https://panel.test/service-job-confirmation/'));
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

    public function test_appointment_proposal_changes_partner_card_to_operation_approval_waiting(): void
    {
        $scope = $this->partnerPortalScopeFixture();

        $response = $this->actingAs($scope['userA'])
            ->postJson("/api/partner/service-jobs/{$scope['jobA']->id}/appointment-proposal", [
                'slots' => [['date' => now()->addDay()->toDateString(), 'slot' => '10:00-11:00']],
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.next_action', 'Randevu önerildi')
            ->assertJsonPath('job.appointment_label', 'Randevu önerildi')
            ->assertJsonPath('job.can_propose_appointment', false);

        $this->assertSame(['Operasyon onayı bekleniyor'], $response->json('job.badges'));
        $this->assertSame('appointment_proposed_waiting', $response->json('job.action_state'));
        $this->assertNotContains('Randevu bekleniyor', [
            $response->json('job.next_action'),
            $response->json('job.appointment_label'),
            ...$response->json('job.badges'),
        ]);
    }

    public function test_ops_card_label_says_technician_proposed_appointment_and_approve_needed(): void
    {
        $scope = $this->partnerPortalScopeFixture();

        $this->actingAs($scope['userA'])
            ->postJson("/api/partner/service-jobs/{$scope['jobA']->id}/appointment-proposal", [
                'slots' => [['date' => now()->addDay()->toDateString(), 'slot' => '10:00-11:00']],
            ])
            ->assertOk();

        $opsState = app(\App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter::class)
            ->present($scope['jobA']->refresh());
        $labels = collect($opsState['display_tags'])->pluck('label')->all();

        $this->assertSame('Usta randevu önerdi', $opsState['display_action_label']);
        $this->assertSame(6, $opsState['sort_priority']);
        $this->assertSame(['OPS aksiyonu: Usta randevu önerdi', 'Randevuyu onaylayın'], $labels);
        $this->assertNotContains('Randevu önerisi bekliyor', $labels);
    }

    public function test_action_after_transition_sorts_card_near_top(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Sorting Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Sorting Portal Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $normalJob = $this->serviceRequestForTechnician($technician, 'MRN-SORT-NORMAL', [
            'updated_at' => now()->addMinute(),
        ]);
        $proposedJob = $this->serviceRequestForTechnician($technician, 'MRN-SORT-PROPOSED', [
            'updated_at' => now()->subMinute(),
        ]);
        $user = $this->userWithRole('b2b_locksmith');
        TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $proposedJob->id,
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => ['slots' => [['date' => now()->addDay()->toDateString(), 'slot' => '10:00-11:00']]],
        ]);

        $board = app(\App\Services\B2B\B2BPartnerPortalDataService::class)->serviceJobBoardFor($partner);
        $newJobs = collect($board['columns'])->firstWhere('key', 'new_jobs')['jobs'];

        $this->assertSame('MRN-SORT-PROPOSED', $newJobs[0]['mrn']);
        $this->assertSame(6, $newJobs[0]['card_priority']);
        $this->assertSame('MRN-SORT-NORMAL', $newJobs[1]['mrn']);
        $this->assertSame(12, $newJobs[1]['card_priority']);
        $this->assertNotSame($normalJob->id, $proposedJob->id);
    }

    public function test_polling_refresh_updates_appointment_approved_state(): void
    {
        $scope = $this->partnerPortalScopeFixture();
        $scope['jobA']->forceFill([
            'workflow_status' => 'Usta Onayı Bekleyen',
            'status' => 'Atandı',
        ])->save();
        $admin = $this->userWithRole('admin', true);

        $this->actingAs($scope['userA'])
            ->postJson("/api/partner/service-jobs/{$scope['jobA']->id}/appointment-proposal", [
                'slots' => [['date' => now()->addDay()->toDateString(), 'slot' => '10:00-11:00']],
            ])
            ->assertOk();
        $action = TechnicalServicePartnerJobAction::query()
            ->where('technical_service_request_id', $scope['jobA']->id)
            ->where('action', TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED)
            ->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$scope['jobA']->id}/partner-appointment-proposals/{$action->id}/approve", [
                'note' => 'Operasyon onayladı.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('request.workflow_status', 'Planlı');

        $refreshResponse = $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonFragment(['mrn' => 'MRN-SCOPE-A'])
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-B']);

        $job = collect($refreshResponse->json('jobs'))->firstWhere('mrn', 'MRN-SCOPE-A');
        $this->assertSame('appointment_confirmed', $job['kanban_column']);
        $this->assertTrue($job['operational_state']['is_appointment_confirmed']);
        $this->assertFalse($job['operational_state']['is_completed']);
        $this->assertFalse($job['operational_state']['is_pending_final_check']);
        $this->assertSame('assigned', $job['operational_state']['ops_column']);
        $this->assertSame([
            'Randevu onaylandı',
            'Fotoğraf bekleniyor',
            'Müşteri onayı bekleniyor',
        ], $job['badges']);
        $this->assertSame('İş sonrası 3 fotoğrafı yükleyin, ardından müşteri onayı alın.', $job['field_action_hint']);
        $this->assertSame(TechnicalServicePartnerJobAction::STATUS_APPLIED, $job['appointment_proposal']['status']);
        $this->assertNotContains('Operasyon onayı bekleniyor', $job['badges']);
        $this->assertNotContains('Randevu önerildi', $job['badges']);
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
        $proposalDate = now()->addDay()->toDateString();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/appointment-proposal", [
                'slots' => [
                    ['date' => $proposalDate, 'slot' => '10:00-11:00'],
                    ['date' => $proposalDate, 'slot' => '10:00-11:00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slots');

        $proposalResponse = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/appointment-proposal", [
                'slots' => [
                    ['date' => $proposalDate, 'slot' => '10:00-11:00'],
                    ['date' => $proposalDate, 'slot' => '15:00-16:00'],
                ],
                'note' => 'Sabah uygunum.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.next_action', 'Randevu önerildi')
            ->assertJsonPath('job.appointment_label', 'Randevu önerildi')
            ->assertJsonPath('job.can_propose_appointment', false);
        $this->assertContains('Operasyon onayı bekleniyor', $proposalResponse->json('job.badges'));
        $opsState = app(\App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter::class)->present($job->refresh());
        $this->assertSame('Usta randevu önerdi', $opsState['display_action_label']);
        $this->assertContains('Randevuyu onaylayın', collect($opsState['display_tags'])->pluck('label')->all());

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
        $newPartner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Reject Replacement Locksmith',
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $newPartner->id,
            'technical_service_technician_id' => $newTechnician->id,
            'relationship_type' => 'field_technician',
            'active' => true,
        ]);
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

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$newPartner->id}/provision-admin-user")
            ->assertCreated();
        $newPortalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $newPartner->id))
            ->firstOrFail();

        $newPortalJobResponse = $this->actingAs($newPortalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.kanban_column', 'new_jobs')
            ->assertJsonPath('job.action_state', 'new')
            ->assertJsonPath('job.rejection', null)
            ->assertJsonPath('job.latest_partner_action', null);

        $this->assertNotContains('Reddedildi', $newPortalJobResponse->json('job.badges'));
        $this->assertFalse(collect($newPortalJobResponse->json('job.portal_actions'))
            ->contains(fn (array $action): bool => $action['action'] === TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED));
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
        $routeQuote = TechnicalServiceRouteQuote::query()->create([
            'technical_service_request_id' => $job->id,
            'technician_id' => $technician->id,
            'distance_meters' => 61000,
            'distance_km' => 61,
            'threshold_km' => 30,
            'extra_km' => 31,
            'fee_per_km' => 94.9,
            'fee_amount' => 2942,
            'travel_fee_required' => true,
            'provider' => TechnicalServiceRouteQuote::PROVIDER_GOOGLE_ROUTES,
            'status' => TechnicalServiceRouteQuote::STATUS_CALCULATED,
            'raw_payload' => [
                'one_way_distance_meters' => 30500,
                'round_trip_distance_meters' => 61000,
            ],
            'calculated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'route_quote_id' => $routeQuote->id,
                'labor_amount' => 2000,
                'travel_amount' => 1000,
                'earning_note' => 'Final hakediş onayı.',
                'confirm_assignment' => true,
                'assignment_offer' => [
                    'labor_amount' => 900,
                    'route_fee_amount' => 180,
                    'total_amount' => 99999,
                    'note' => 'Atama hakedişi.',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('request.assignment_offer.labor_amount', 2000)
            ->assertJsonPath('request.assignment_offer.route_fee_amount', 1000)
            ->assertJsonPath('request.assignment_offer.total_amount', 3000)
            ->assertJsonPath('request.assignment_offer.route_quote_id', $routeQuote->id)
            ->assertJsonPath('request.route_quote.fee_amount', 2942);

        $offer = TechnicalServiceAssignmentOffer::query()
            ->where('technical_service_request_id', $job->id)
            ->firstOrFail();
        $this->assertSame(TechnicalServiceAssignmentOffer::STATUS_SENT, $offer->status);
        $this->assertSame(2000.0, (float) $offer->labor_amount);
        $this->assertSame(1000.0, (float) $offer->route_fee_amount);
        $this->assertSame(3000.0, (float) $offer->total_amount);
        $this->assertIsArray($offer->metadata['message_payload'] ?? null);
        $this->assertTrue((bool) ($offer->metadata['confirmed_by_ops'] ?? false));
        $this->assertStringContainsString('/partner/service-jobs?', (string) ($offer->metadata['message_payload']['job_link'] ?? ''));
        $this->assertStringContainsString('partner_id='.$partner->id, (string) ($offer->metadata['message_payload']['job_link'] ?? ''));
        $this->assertStringContainsString('job_id='.$job->id, (string) ($offer->metadata['message_payload']['job_link'] ?? ''));
        $this->assertStringContainsString('2.000 TL', (string) ($offer->metadata['message_payload']['message_text'] ?? ''));
        $this->assertStringContainsString('Yol: 1.000 TL', (string) ($offer->metadata['message_payload']['message_text'] ?? ''));
        $this->assertStringContainsString('Toplam: 3.000 TL', (string) ($offer->metadata['message_payload']['message_text'] ?? ''));
        $this->assertStringContainsString('İş kartı:', (string) ($offer->metadata['message_payload']['message_text'] ?? ''));
        $this->assertStringNotContainsString('TRY', (string) ($offer->metadata['message_payload']['message_text'] ?? ''));
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
            ->getJson("/api/technical-service/requests/{$job->id}")
            ->assertOk()
            ->assertJsonPath('request.assignment_offer.id', $offer->id)
            ->assertJsonPath('request.assignment_offer.labor_amount', 2000)
            ->assertJsonPath('request.assignment_offer.route_fee_amount', 1000)
            ->assertJsonPath('request.assignment_offer.total_amount', 3000)
            ->assertJsonPath('request.assignment_offer.message_payload.route_fee_amount', 1000)
            ->assertJsonPath('request.assignment_offer.message_payload.total_amount', 3000)
            ->assertJsonPath('request.assignment_offer.job_link', $offer->metadata['message_payload']['job_link'])
            ->assertJsonPath('request.travel_fee_amount', '1000.00')
            ->assertJsonPath('request.technician_payment_amount', '2000.00');

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
            ->assertJsonPath('job.assignment_offer.labor_amount', 2000)
            ->assertJsonPath('job.assignment_offer.route_fee_amount', 1000)
            ->assertJsonPath('job.assignment_offer.total_amount', 3000)
            ->assertJsonPath('job.earning_summary.labor_amount', 2000)
            ->assertJsonPath('job.earning_summary.route_fee_amount', 1000)
            ->assertJsonPath('job.earning_summary.total_amount', 3000)
            ->assertJsonPath('job.earning_breakdown.current_visit.kind_label', 'Montaj')
            ->assertJsonPath('job.earning_breakdown.current_visit.total_amount', 3000)
            ->assertJsonPath('job.earning_breakdown.root_total.total_amount', 3000);

        $this->actingAs($portalUser)
            ->getJson('/api/partner/earnings')
            ->assertOk()
            ->assertJsonPath('items.0.earnings.pending.rows.0.labor_amount', 2000)
            ->assertJsonPath('items.0.earnings.pending.rows.0.travel_fee_amount', 1000)
            ->assertJsonPath('items.0.earnings.pending.rows.0.line_total', 3000);

        $otherPartner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Other Locksmith',
        ]);
        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$otherPartner->id}/provision-admin-user")
            ->assertCreated();
        $otherPortalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $otherPartner->id))
            ->firstOrFail();

        $this->actingAs($otherPortalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}?partner_id={$otherPartner->id}&job_id={$job->id}")
            ->assertForbidden();

        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $job->id,
            'technical_service_technician_id' => $technician->id,
            'route_quote_id' => $routeQuote->id,
            'labor_amount' => 1,
            'route_fee_amount' => 2,
            'total_amount' => 3,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_CANCELLED,
            'sent_at' => now(),
            'metadata' => ['source' => 'cancelled_history'],
        ]);

        $this->actingAs($admin)
            ->getJson("/api/technical-service/requests/{$job->id}")
            ->assertOk()
            ->assertJsonPath('request.assignment_offer.id', $offer->id)
            ->assertJsonPath('request.assignment_offer.route_fee_amount', 1000)
            ->assertJsonPath('request.assignment_offer.total_amount', 3000);
    }

    public function test_technical_service_assignment_requires_final_earning_confirmation_when_final_amounts_are_sent(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Final Onay Usta']);
        $job = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-FINAL-CONFIRM',
            'customer_name' => 'Final Onay Musteri',
            'customer_phone' => '+905550000333',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Final onay adresi',
            'product_name' => 'Final Onay Kilit',
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'workflow_status' => 'Usta AtamasÄ± Bekleyen',
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
            'technician_payment_amount' => 700,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'travel_round_trip_km' => 10,
                'labor_amount' => 700,
                'travel_amount' => 80,
                'confirm_assignment' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_assignment');
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

    /**
     * @return array{
     *     partnerA: B2BPartner,
     *     partnerB: B2BPartner,
     *     technicianA: TechnicalServiceTechnician,
     *     technicianB: TechnicalServiceTechnician,
     *     jobA: TechnicalServiceRequest,
     *     jobB: TechnicalServiceRequest,
     *     completedJobB: TechnicalServiceRequest,
     *     userA: User,
     *     userB: User
     * }
     */
    private function partnerPortalScopeFixture(bool $seedPermissions = true): array
    {
        if ($seedPermissions) {
            (new B2BPartnerPermissionSeeder)->run();
        }

        $partnerA = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Scope Locksmith A',
        ]);
        $partnerB = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Scope Locksmith B',
        ]);
        $technicianA = $this->technician(['name' => 'Scope Usta A']);
        $technicianB = $this->technician(['name' => 'Scope Usta B']);

        foreach ([[$partnerA, $technicianA], [$partnerB, $technicianB]] as [$partner, $technician]) {
            B2BPartnerTechnician::query()->create([
                'partner_id' => $partner->id,
                'technical_service_technician_id' => $technician->id,
                'relationship_type' => 'owner',
                'active' => true,
            ]);
        }

        $jobA = $this->serviceRequestForTechnician($technicianA, 'MRN-SCOPE-A');
        $jobB = $this->serviceRequestForTechnician($technicianB, 'MRN-SCOPE-B');
        $completedJobB = $this->serviceRequestForTechnician($technicianB, 'MRN-SCOPE-B-COMPLETED', [
            'workflow_status' => 'Tamamland'."\u{0131}",
            'status' => 'Tamamland'."\u{0131}",
            'completed_at' => now(),
        ]);

        foreach ([[$jobA, $technicianA], [$jobB, $technicianB], [$completedJobB, $technicianB]] as [$request, $technician]) {
            $this->partnerPortalAssignmentOffer($request, $technician);
        }

        $userA = $this->userWithRole('b2b_locksmith');
        $userB = $this->userWithRole('b2b_locksmith');
        foreach ([[$userA, $partnerA], [$userB, $partnerB]] as [$user, $partner]) {
            B2BPartnerUserProfile::query()->create([
                'user_id' => $user->id,
                'partner_id' => $partner->id,
                'active' => true,
            ]);
            foreach (['view', 'technical_service', 'finance'] as $scope) {
                $this->grantPartnerAccess($user, $partner, $scope);
            }
        }

        return [
            'partnerA' => $partnerA,
            'partnerB' => $partnerB,
            'technicianA' => $technicianA,
            'technicianB' => $technicianB,
            'jobA' => $jobA,
            'jobB' => $jobB,
            'completedJobB' => $completedJobB,
            'userA' => $userA,
            'userB' => $userB,
        ];
    }

    private function partnerPortalAssignmentOffer(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
    ): TechnicalServiceAssignmentOffer {
        return TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 1000,
            'route_fee_amount' => 100,
            'total_amount' => 1100,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function partnerPortalCrossPartnerActionPayloads(): array
    {
        return [
            'accept' => ['note' => 'Cross partner forbidden'],
            'accept-appointment' => ['note' => 'Cross partner forbidden'],
            'appointment-proposal' => [
                'slots' => [['date' => now()->addDay()->toDateString(), 'slot' => '10:00-11:00']],
            ],
            'reject' => ['reason' => 'other', 'note' => 'Cross partner forbidden'],
            'photos' => [],
            'customer-otp-request' => ['note' => 'Cross partner forbidden'],
            'support-request' => ['type' => 'other', 'description' => 'Cross partner forbidden'],
            'price-revision-request' => ['labor_amount' => 1200, 'note' => 'Cross partner forbidden'],
            'request-revisit' => ['reason' => 'Cross partner forbidden'],
            'submit-completion' => ['result' => 'completed'],
            'note' => ['note' => 'Cross partner forbidden'],
        ];
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
        $statementKey = implode('_', ['query', 'template']);
        $allowedKey = implode('_', ['allowed', 'params']);
        $metaKey = implode('_', ['connection', 'meta']);

        return DataSource::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => 'Test '.$code,
                'db_type' => 'n8n_json',
                $statementKey => 'SELECT 1',
                $allowedKey => ['search', 'scope_key', 'customer_scope_key', 'page', 'limit', 'bypass_cache'],
                $metaKey => [
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
