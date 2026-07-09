<?php

namespace Tests\Feature;

use App\Models\B2B\B2BCariSnapshot;
use App\Models\B2B\B2BCariSnapshotRun;
use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerAuditLog;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerOrder;
use App\Models\B2B\B2BPartnerTechnician;
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
use App\Models\TechnicalServiceCustomerConfirmation;
use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningItem;
use App\Models\TechnicalServiceEarningsPeriod;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\TechnicalServiceRouteQuote;
use App\Models\TechnicalServiceSettlement;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\B2B\B2BPartnerAccessService;
use App\Services\B2B\B2BPartnerPortalDataService;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\PanelAccessService;
use App\Services\PanelNavigationService;
use App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter;
use App\Services\TechnicalService\TechnicalServicePaymentSettlementService;
use App\Services\TechnicalService\TechnicalServiceServiceVisitService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Database\Seeders\B2BPartnerPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_b2b_partner_search_finds_partner_by_linked_technician_name(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'BAHATTİN ÖZBEK',
            'mikro_cari_kodu' => '320.ÇLG.06.002',
            'city' => 'Ankara',
            'latitude' => '39.9111158',
            'longitude' => '32.8607935',
        ]);
        $technician = $this->technician([
            'name' => 'BERKAY ATLAS',
            'first_name' => 'BERKAY',
            'last_name' => 'ATLAS',
            'phone' => '+905071838038',
            'city' => 'İzmir',
            'district' => 'Bornova',
            'latitude' => null,
            'longitude' => null,
            'mikro_cari_kodu' => '320.ÇLG.06.002',
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'contracted_technician',
            'active' => true,
            'is_primary' => false,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/partners?partner_type=locksmith&search=ber')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $partner->id)
            ->assertJsonPath('items.0.display_name', 'BAHATTİN ÖZBEK')
            ->assertJsonPath('items.0.city', 'Ankara')
            ->assertJsonPath('items.0.linked_technicians.0.technician.name', 'BERKAY ATLAS')
            ->assertJsonPath('items.0.linked_technicians.0.technician.city', 'İzmir');

        $this->assertSame('İzmir', $technician->fresh()->city);
    }

    public function test_b2b_locksmith_filter_includes_partner_with_linked_active_technician(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
            'display_name' => 'Bağlı Ustalı Bayi',
        ]);
        $technician = $this->technician(['name' => 'Filtre Ustası', 'city' => 'İzmir']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'contracted_technician',
            'active' => true,
            'is_primary' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/partners?partner_type=locksmith')
            ->assertOk()
            ->assertJsonFragment(['id' => $partner->id])
            ->assertJsonPath('items.0.linked_technicians.0.technician.city', 'İzmir');
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

    public function test_partner_edit_roles_can_add_locksmith_without_creating_technician(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'partner_code' => 'BAHATTIN-EDIT',
            'display_name' => 'Bahattin Özbek',
            'mikro_cari_kodu' => '320.BAYI.BAHATTIN.EDIT',
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);
        $partnerCount = B2BPartner::query()->count();
        $technicianCount = TechnicalServiceTechnician::query()->count();
        $linkCount = B2BPartnerTechnician::query()->count();

        $this->actingAs($admin)
            ->patchJson("/api/b2b/partners/{$partner->id}", $this->partnerPayload([
                'partner_type' => B2BPartner::TYPE_DEALER,
                'partner_code' => 'BAHATTIN-EDIT',
                'display_name' => 'Bahattin Özbek',
                'mikro_cari_kodu' => '320.BAYI.BAHATTIN.EDIT',
                'capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
                'technical_service_technician_id' => null,
            ]))
            ->assertOk()
            ->assertJsonPath('partner.id', $partner->id)
            ->assertJsonPath('partner.capabilities.0', B2BPartner::TYPE_DEALER)
            ->assertJsonPath('partner.capabilities.1', B2BPartner::TYPE_LOCKSMITH);

        $this->assertSame($partnerCount, B2BPartner::query()->count());
        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
        $this->assertEqualsCanonicalizing([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH], $partner->fresh()->capabilityCodes());
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'action' => 'b2b.partner.capability_added',
        ]);
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'action' => 'b2b.partner.updated',
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

    public function test_cari_candidate_title_does_not_duplicate_same_name(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [[
                    'musteri_kodu' => '320.ÇLG.01.0001',
                    'firma_unvani' => 'CENGİZ ÇETİN',
                    'firma_unvani_2' => 'CENGİZ ÇETİN',
                    'grup' => 'ÇİLİNGİR',
                    'phone' => '5334491851',
                    'city' => 'ADANA',
                    'address' => 'GÜZELYALI MAH.',
                    'address_source' => 'ilk adres kartı',
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?refresh=1&include_review_required=1&limit=1000')
            ->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.display_name', 'CENGİZ ÇETİN')
            ->assertJsonPath('candidates.0.mikro_cari_unvan', 'CENGİZ ÇETİN')
            ->assertJsonPath('candidates.0.contact_or_service_name', null);
    }

    public function test_cari_candidate_identity_uses_cari_code_and_merges_same_code_rows(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'musteri_kodu' => '320.ÇLG.MERGE',
                        'firma_unvani' => 'Birleşen Çilingir',
                        'firma_unvani_2' => 'Birleşen Çilingir',
                        'grup' => 'ÇİLİNGİR',
                    ],
                    [
                        'musteri_kodu' => '320.ÇLG.MERGE',
                        'firma_unvani' => 'Birleşen Çilingir',
                        'grup' => 'ÇİLİNGİR',
                        'phone' => '+905551112233',
                        'city' => 'Ankara',
                        'address' => 'Adres kartı',
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?refresh=1&include_review_required=1&limit=1000')
            ->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.mikro_cari_kodu', '320.ÇLG.MERGE')
            ->assertJsonPath('candidates.0.display_name', 'Birleşen Çilingir')
            ->assertJsonPath('candidates.0.phone', '+905551112233')
            ->assertJsonPath('candidates.0.address', 'Adres kartı');
    }

    public function test_cari_candidate_keeps_distinct_cari_codes_with_same_name(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [
                    ['musteri_kodu' => '320.ÇLG.SAME.001', 'firma_unvani' => 'Aynı İsim', 'grup' => 'ÇİLİNGİR'],
                    ['musteri_kodu' => '320.ÇLG.SAME.002', 'firma_unvani' => 'Aynı İsim', 'grup' => 'ÇİLİNGİR'],
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?refresh=1&include_review_required=1&limit=1000')
            ->assertOk()
            ->assertJsonCount(2, 'candidates')
            ->assertJsonPath('candidates.0.mikro_cari_kodu', '320.ÇLG.SAME.001')
            ->assertJsonPath('candidates.1.mikro_cari_kodu', '320.ÇLG.SAME.002');
    }

    public function test_sync_preview_does_not_warn_phone_or_address_missing_when_source_has_them(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [[
                    'musteri_kodu' => '320.ÇLG.COMPLETE',
                    'firma_unvani' => 'Tam Kaynak Çilingir',
                    'grup' => 'ÇİLİNGİR',
                    'phone' => '+905551112233',
                    'city' => 'Adana',
                    'district' => 'Seyhan',
                    'address' => 'Mikro cari adresi',
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?refresh=1&include_review_required=1&limit=1000')
            ->assertOk()
            ->assertJsonPath('candidates.0.source_field_missing', ['email', 'tax_no', 'tax_office'])
            ->assertJsonPath('candidates.0.sync_preview.warnings.0', 'Çilingir adayı için teknisyen kaydı Faz 1B eşitlemesinde oluşturulacak veya eşleştirilecek.');
    }

    public function test_cari_control_default_snapshot_includes_existing_partner_candidates(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'display_name' => 'Mevcut Çilingir',
            'mikro_cari_kodu' => '320.ÇLG.EXISTING',
            'mikro_cari_unvan' => 'Mevcut Çilingir',
            'cari_grup_kodu' => 'ÇİLİNGİR',
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'phone' => '+905551112233',
            'city' => 'İstanbul',
            'address' => 'Mevcut adres',
        ]);

        B2BCariSnapshot::query()->create([
            'source_code' => 'customers_list',
            'base_mikro_cari_kodu' => '320.ÇLG.EXISTING',
            'mikro_cari_kodu' => '320.ÇLG.EXISTING',
            'mikro_cari_unvan' => 'Mevcut Çilingir Mevcut Çilingir',
            'normalized_unvan' => 'MEVCUT CILINGIR',
            'cari_grup_kodu' => 'ÇİLİNGİR',
            'phone' => '+905551112233',
            'city' => 'İstanbul',
            'address' => 'Mevcut adres',
            'suggested_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'raw_payload' => ['display_name' => 'Mevcut Çilingir Mevcut Çilingir'],
            'payload_hash' => hash('sha256', 'existing-candidate'),
            'existing_partner_id' => $partner->id,
            'candidate_status' => 'matched',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?include_review_required=1&limit=1000')
            ->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.existing_partner_id', $partner->id)
            ->assertJsonPath('candidates.0.display_name', 'Mevcut Çilingir')
            ->assertJsonPath('candidates.0.status', 'matched');
    }

    public function test_cari_control_requests_more_than_100_candidates_when_available(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');
        $sent = [];
        $rows = collect(range(1, 300))
            ->map(fn (int $index): array => [
                'musteri_kodu' => sprintf('320.ÇLG.%03d', $index),
                'firma_unvani' => 'Çilingir Aday '.$index,
                'grup' => 'ÇİLİNGİR',
                'city' => 'Ankara',
                'toplam_cari_sayisi' => 300,
            ])
            ->all();

        Http::fake(function ($request) use (&$sent, $rows) {
            $sent[] = $request->data();

            return Http::response([
                'ok' => true,
                'rows' => $rows,
                'meta' => ['total' => 300],
            ]);
        });

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?refresh=1&include_review_required=1&limit=1000')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('loaded_count', 300)
            ->assertJsonPath('source_total', 300)
            ->assertJsonPath('source_total_known', true)
            ->assertJsonPath('role_counts.locksmith', 300)
            ->assertJsonPath('actions_enabled', true)
            ->assertJsonCount(300, 'candidates');

        $this->assertSame(1000, data_get($sent[0] ?? [], 'limit'));
        $this->assertSame(1000, data_get($sent[0] ?? [], 'params.limit'));
    }

    public function test_cari_control_does_not_hard_cap_snapshot_at_100(): void
    {
        $admin = $this->userWithRole('admin', true);

        foreach (range(1, 180) as $index) {
            B2BCariSnapshot::query()->create([
                'source_code' => 'customers_list',
                'base_mikro_cari_kodu' => sprintf('320.ÇLG.SNAP.%03d', $index),
                'mikro_cari_kodu' => sprintf('320.ÇLG.SNAP.%03d', $index),
                'mikro_cari_unvan' => 'Snapshot Çilingir '.$index,
                'normalized_unvan' => 'SNAPSHOT CILINGIR '.$index,
                'cari_grup_kodu' => 'ÇİLİNGİR',
                'suggested_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                'raw_payload' => ['raw_source' => ['toplam_cari_sayisi' => 180]],
                'payload_hash' => hash('sha256', 'snapshot-'.$index),
                'candidate_status' => 'new',
                'last_seen_at' => now(),
            ]);
        }

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?include_review_required=1&limit=150')
            ->assertOk()
            ->assertJsonPath('snapshot_total', 180)
            ->assertJsonPath('loaded_count', 150)
            ->assertJsonPath('filtered_total', 180)
            ->assertJsonPath('role_counts.locksmith', 180)
            ->assertJsonCount(150, 'candidates');
    }

    public function test_cari_control_reports_loaded_count_and_role_counts(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [
                    ['musteri_kodu' => '120.BAYI.101', 'firma_unvani' => 'Bayi Aday', 'grup' => 'BAYİ', 'toplam_cari_sayisi' => 3],
                    ['musteri_kodu' => '320.CLG.101', 'firma_unvani' => 'Çilingir Aday', 'grup' => 'ÇİLİNGİR', 'toplam_cari_sayisi' => 3],
                    ['musteri_kodu' => '320.CLG.102', 'firma_unvani' => 'Cilingir Aday', 'grup' => 'CILINGIR', 'toplam_cari_sayisi' => 3],
                ],
                'meta' => ['total' => 3],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?refresh=1&include_review_required=1&limit=1000')
            ->assertOk()
            ->assertJsonPath('loaded_count', 3)
            ->assertJsonPath('source_total', 3)
            ->assertJsonPath('role_counts.dealer', 1)
            ->assertJsonPath('role_counts.locksmith', 2);
    }

    public function test_cari_control_normalizes_clg_and_turkish_cilingir_search(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [
                    ['musteri_kodu' => '320.ÇLG.201', 'firma_unvani' => 'Türkçe Çilingir', 'grup' => 'ÇİLİNGİR'],
                    ['musteri_kodu' => '120.BAYI.201', 'firma_unvani' => 'Normal Bayi', 'grup' => 'BAYİ'],
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?refresh=1&include_review_required=1&limit=1000')
            ->assertOk();

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?search=CLG&include_review_required=1&limit=1000')
            ->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.mikro_cari_kodu', '320.ÇLG.201')
            ->assertJsonPath('candidates.0.suggested_capabilities.0', B2BPartner::TYPE_LOCKSMITH);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?search=CILINGIR&include_review_required=1&limit=1000')
            ->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.mikro_cari_kodu', '320.ÇLG.201');
    }

    public function test_cari_control_locksmith_candidate_builds_partner_technician_sync_preview(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');
        $technician = $this->technician([
            'name' => 'Mevcut Çilingir',
            'mikro_cari_kodu' => '320.CLG.PREVIEW',
            'cari_code' => '320.CLG.PREVIEW',
            'phone' => '+905551110001',
        ]);

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [[
                    'musteri_kodu' => '320.CLG.PREVIEW',
                    'firma_unvani' => 'Preview Çilingir',
                    'grup' => 'ÇİLİNGİR',
                    'phone' => '+905551110001',
                    'city' => 'İstanbul',
                    'address' => 'Servis adresi',
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?refresh=1&include_review_required=1&limit=1000')
            ->assertOk()
            ->assertJsonPath('candidates.0.sync_preview.writes_enabled', false)
            ->assertJsonPath('candidates.0.sync_preview.partner_action', 'create_partner_preview')
            ->assertJsonPath('candidates.0.sync_preview.technician_action', 'match_existing_technician')
            ->assertJsonPath('candidates.0.sync_preview.link_action', 'ensure_partner_technician_link_preview')
            ->assertJsonPath('candidates.0.sync_preview.technician_phone_matches.0.id', $technician->id);
    }

    public function test_sync_preview_does_not_write_partner_technician_data(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');
        $partnerCount = B2BPartner::query()->count();
        $technicianCount = TechnicalServiceTechnician::query()->count();
        $linkCount = B2BPartnerTechnician::query()->count();

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [[
                    'musteri_kodu' => '320.CLG.NO-WRITE',
                    'firma_unvani' => 'Yazmasız Çilingir',
                    'grup' => 'ÇİLİNGİR',
                    'city' => 'İstanbul',
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?refresh=1&include_review_required=1&limit=1000')
            ->assertOk()
            ->assertJsonPath('actions_enabled', true)
            ->assertJsonPath('candidates.0.sync_preview.writes_enabled', false);

        $this->assertSame($partnerCount, B2BPartner::query()->count());
        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
    }

    public function test_bahattin_like_partner_can_have_dealer_and_locksmith_roles_in_preview(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [[
                    'musteri_kodu' => '320.CLG.BAHATTIN',
                    'firma_unvani' => 'Bahattin Bayi Çilingir',
                    'grup' => 'BAYİ ÇİLİNGİR',
                    'city' => 'Ankara',
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?refresh=1&include_review_required=1&limit=1000')
            ->assertOk()
            ->assertJsonPath('candidates.0.suggested_capabilities.0', B2BPartner::TYPE_LOCKSMITH)
            ->assertJsonPath('candidates.0.suggested_capabilities.1', B2BPartner::TYPE_DEALER)
            ->assertJsonPath('candidates.0.sync_preview.role_model', 'single_partner_multi_role');
    }

    public function test_duplicate_phone_candidate_is_flagged_in_preview(): void
    {
        $admin = $this->userWithRole('admin', true);
        $this->dataSource('customers_list');
        $partner = $this->partner([
            'display_name' => 'Telefon Eşleşen Partner',
            'phone' => '+905551234567',
        ]);

        Http::fake([
            'https://n8n.test/*' => Http::response([
                'ok' => true,
                'rows' => [[
                    'musteri_kodu' => '320.CLG.PHONE',
                    'firma_unvani' => 'Telefon Eşleşen Çilingir',
                    'grup' => 'ÇİLİNGİR',
                    'phone' => '+90 555 123 45 67',
                    'city' => 'Bursa',
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/b2b/cari-control?refresh=1&include_review_required=1&limit=1000')
            ->assertOk()
            ->assertJsonPath('candidates.0.sync_preview.partner_phone_matches.0.id', $partner->id)
            ->assertJsonPath('candidates.0.sync_preview.duplicate_flags.0', 'partner_phone_match');
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

    public function test_cari_control_locksmith_apply_creates_partner_technician_and_link(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'selected_capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
                'sync_technician' => true,
                'geocode_mode' => 'none',
                'candidates' => [[
                    'mikro_cari_kodu' => '320.CLG.FAZ1B.001',
                    'display_name' => 'Faz 1B Çilingir',
                    'mikro_cari_unvan' => 'Faz 1B Çilingir',
                    'phone' => '+905551234000',
                    'city' => 'Manisa',
                    'district' => 'Yunusemre',
                    'address' => 'Test Mahallesi No:1',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('items.0.status', 'created')
            ->assertJsonPath('items.0.technician_sync.status', 'technician_created')
            ->assertJsonPath('items.0.technician_sync.geocode.status', 'skipped');

        $partner = B2BPartner::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.001')->firstOrFail();
        $technician = TechnicalServiceTechnician::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.001')->firstOrFail();
        $link = B2BPartnerTechnician::query()
            ->where('partner_id', $partner->id)
            ->where('technical_service_technician_id', $technician->id)
            ->firstOrFail();

        $this->assertEqualsCanonicalizing([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH], $partner->capabilityCodes());
        $this->assertSame('locksmith', $technician->technician_type);
        $this->assertSame('Manisa', $technician->city);
        $this->assertTrue((bool) $technician->needs_review);
        $this->assertSame('review_required', $technician->review_status);
        $this->assertContains('Koordinat eksik.', $technician->review_reasons);
        $this->assertSame('Manisa', $link->service_city);
        $this->assertSame('Yunusemre', $link->service_district);
        $this->assertTrue((bool) $link->needs_review);
    }

    public function test_sync_apply_does_not_write_when_dry_run(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
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
                    'mikro_cari_kodu' => '320.CLG.FAZ1B.DRY',
                    'display_name' => 'Dry Run Çilingir',
                    'phone' => '+905551234001',
                    'city' => 'İstanbul',
                    'address' => 'Dry Run Mahallesi No:1',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('geocode_mode', 'auto')
            ->assertJsonPath('items.0.partner_action', 'create_partner')
            ->assertJsonPath('items.0.technician_action', 'create_technician')
            ->assertJsonPath('items.0.link_action', 'ensure_partner_technician_link')
            ->assertJsonPath('items.0.partner_geocode_plan.status', 'ready')
            ->assertJsonPath('items.0.technician_geocode_plan.status', 'ready');

        $this->assertSame($partnerCount, B2BPartner::query()->count());
        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
    }

    public function test_cari_control_apply_is_idempotent_by_cari_code_and_phone(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $payload = [
            'action' => 'import',
            'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'sync_technician' => true,
            'geocode_mode' => 'none',
            'candidates' => [[
                'mikro_cari_kodu' => '320.CLG.FAZ1B.IDEMPOTENT',
                'display_name' => 'Idempotent Çilingir',
                'phone' => '+905551234002',
                'city' => 'Ankara',
                'district' => 'Çankaya',
                'address' => 'İdempotent Sokak No:2',
            ]],
        ];

        $this->actingAs($admin)->postJson('/api/b2b/cari-control/apply', $payload)->assertOk();
        $this->actingAs($admin)->postJson('/api/b2b/cari-control/apply', $payload)->assertOk();

        $this->assertSame(1, B2BPartner::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.IDEMPOTENT')->count());
        $this->assertSame(1, TechnicalServiceTechnician::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.IDEMPOTENT')->count());

        $partner = B2BPartner::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.IDEMPOTENT')->firstOrFail();
        $technician = TechnicalServiceTechnician::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.IDEMPOTENT')->firstOrFail();

        $this->assertSame(1, B2BPartnerTechnician::query()
            ->where('partner_id', $partner->id)
            ->where('technical_service_technician_id', $technician->id)
            ->count());
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
        $admin = $this->userWithRole('admin', true);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                'sync_technician' => true,
                'geocode_mode' => 'auto',
                'candidates' => [[
                    'mikro_cari_kodu' => '320.CLG.FAZ1B.GEO',
                    'display_name' => 'Geocode Çilingir',
                    'phone' => '+905551234003',
                    'city' => 'Manisa',
                    'district' => 'Yunusemre',
                    'address' => 'Organize Sanayi Bölgesi',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.technician_sync.geocode.status', 'ok')
            ->assertJsonPath('items.0.technician_sync.needs_review', false);

        $technician = TechnicalServiceTechnician::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.GEO')->firstOrFail();
        $this->assertEquals('38.6190990', $technician->latitude);
        $this->assertEquals('27.4289210', $technician->longitude);
        $this->assertEquals('38.6190990', $technician->start_latitude);
        $this->assertEquals('27.4289210', $technician->start_longitude);
        $this->assertSame('google_geocode', $technician->location_source);
        $this->assertSame('ok', $technician->geocode_status);
        $this->assertFalse((bool) $technician->needs_review);
    }

    public function test_city_only_address_keeps_needs_review(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                'sync_technician' => true,
                'geocode_mode' => 'auto',
                'candidates' => [[
                    'mikro_cari_kodu' => '320.CLG.FAZ1B.CITY',
                    'display_name' => 'Şehir Çilingir',
                    'phone' => '+905551234004',
                    'city' => 'Bursa',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.technician_sync.needs_review', true);

        $technician = TechnicalServiceTechnician::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.CITY')->firstOrFail();
        $this->assertTrue((bool) $technician->needs_review);
        $this->assertContains('Adres/şehir eksik.', $technician->review_reasons);
        $this->assertContains('Koordinat eksik.', $technician->review_reasons);
        $this->assertNull($technician->latitude);
    }

    public function test_existing_manual_coordinates_not_overwritten_without_override(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Farklı Adres, Manisa, Türkiye',
                    'geometry' => [
                        'location_type' => 'ROOFTOP',
                        'location' => ['lat' => 38.619099, 'lng' => 27.428921],
                    ],
                ]],
            ], 200),
        ]);
        $admin = $this->userWithRole('admin', true);
        TechnicalServiceTechnician::query()->create([
            'name' => 'Manuel Koordinatlı',
            'technician_type' => 'locksmith',
            'phone' => '+905551234005',
            'city' => 'Manisa',
            'address' => 'Eski adres',
            'mikro_cari_kodu' => '320.CLG.FAZ1B.MANUAL',
            'cari_code' => '320.CLG.FAZ1B.MANUAL',
            'latitude' => '38.5000000',
            'longitude' => '27.1000000',
            'start_latitude' => '38.5000000',
            'start_longitude' => '27.1000000',
            'location_source' => 'manual',
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                'sync_technician' => true,
                'geocode_mode' => 'auto',
                'override_existing_coordinates' => false,
                'candidates' => [[
                    'mikro_cari_kodu' => '320.CLG.FAZ1B.MANUAL',
                    'display_name' => 'Manuel Koordinatlı',
                    'phone' => '+905551234005',
                    'city' => 'Manisa',
                    'address' => 'Yeni adres',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.technician_sync.geocode.status', 'skipped_existing_coordinates');

        $technician = TechnicalServiceTechnician::query()->where('mikro_cari_kodu', '320.CLG.FAZ1B.MANUAL')->firstOrFail();
        $this->assertEquals('38.5000000', $technician->latitude);
        $this->assertEquals('27.1000000', $technician->longitude);
        $this->assertSame('manual', $technician->location_source);
    }

    public function test_bahattin_technician_creation_does_not_reuse_or_move_berkay(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'BAHATTİN ÖZBEK',
            'mikro_cari_kodu' => '320.ÇLG.06.002',
            'mikro_cari_unvan' => 'BAHATTİN ÖZBEK',
            'city' => 'ANKARA',
            'district' => 'ÇANKAYA',
        ]);
        $berkay = $this->technician([
            'name' => 'BERKAY ATLAS',
            'display_name' => 'BERKAY ATLAS',
            'first_name' => 'BERKAY',
            'last_name' => 'ATLAS',
            'phone' => '+905071838038',
            'city' => 'İzmir',
            'district' => null,
            'address' => null,
            'mikro_cari_kodu' => '320.ÇLG.06.002',
            'cari_code' => '320.ÇLG.06.002',
            'latitude' => '38.4237340',
            'longitude' => '27.1428260',
            'start_latitude' => '38.4237340',
            'start_longitude' => '27.1428260',
            'location_source' => 'manual',
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $berkay->id,
            'relationship_type' => 'field_technician',
            'is_primary' => true,
            'active' => true,
            'source' => 'manual',
            'match_reason' => 'partner_form',
        ]);

        $partnerCount = B2BPartner::query()->count();

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'selected_capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
                'sync_technician' => true,
                'geocode_mode' => 'none',
                'candidates' => [[
                    'mikro_cari_kodu' => '320.ÇLG.06.002',
                    'display_name' => 'BAHATTİN ÖZBEK',
                    'mikro_cari_unvan' => 'BAHATTİN ÖZBEK',
                    'phone' => '+905551112233',
                    'city' => 'ANKARA',
                    'district' => 'ÇANKAYA',
                    'address' => 'KAVAKLIDERE MAH. ESAT CD NO 59',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.technician_sync.status', 'technician_created')
            ->assertJsonPath('items.0.technician_sync.ignored_technician_id', $berkay->id)
            ->assertJsonPath('items.0.technician_sync.ignored_technician_reason', 'different_person_same_cari_or_phone');

        $berkay->refresh();
        $this->assertSame('BERKAY ATLAS', $berkay->name);
        $this->assertSame('BERKAY ATLAS', $berkay->display_name);
        $this->assertSame('İzmir', $berkay->city);
        $this->assertNull($berkay->address);
        $this->assertEquals('38.4237340', $berkay->latitude);
        $this->assertEquals('27.1428260', $berkay->longitude);
        $this->assertSame($partnerCount, B2BPartner::query()->count());

        $bahattinTechnician = TechnicalServiceTechnician::query()
            ->where('name', 'BAHATTİN ÖZBEK')
            ->firstOrFail();

        $this->assertNotSame($berkay->id, $bahattinTechnician->id);
        $this->assertSame('ANKARA', $bahattinTechnician->city);
        $this->assertSame(2, B2BPartnerTechnician::query()
            ->where('partner_id', $partner->id)
            ->where('active', true)
            ->count());

        $links = $this->actingAs($admin)
            ->getJson("/api/b2b/partners/{$partner->id}/technicians")
            ->assertOk()
            ->json('items');
        $berkayPayload = collect($links)->firstWhere('technical_service_technician_id', $berkay->id);
        $this->assertSame('BERKAY ATLAS', data_get($berkayPayload, 'technician.name'));
        $this->assertSame('İzmir', data_get($berkayPayload, 'technician.city'));
        $this->assertEquals('38.4237340', data_get($berkayPayload, 'technician.latitude'));

        $searchItems = $this->actingAs($admin)
            ->getJson('/api/b2b/locksmith-technicians?search=Berkay')
            ->assertOk()
            ->json('items');

        $this->assertNotNull(collect($searchItems)->firstWhere('id', $berkay->id));
    }

    public function test_cari_control_existing_partner_preview_does_not_target_different_linked_technician(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'capabilities' => [B2BPartner::TYPE_DEALER],
            'display_name' => 'BAHATTİN ÖZBEK',
            'mikro_cari_kodu' => '320.ÇLG.06.002',
        ]);
        $berkay = $this->technician([
            'name' => 'BERKAY ATLAS',
            'display_name' => 'BERKAY ATLAS',
            'phone' => '+905071838038',
            'city' => 'İzmir',
            'mikro_cari_kodu' => '320.ÇLG.06.002',
            'cari_code' => '320.ÇLG.06.002',
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $berkay->id,
            'relationship_type' => 'field_technician',
            'is_primary' => true,
            'active' => true,
            'source' => 'manual',
            'match_reason' => 'partner_form',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => true,
                'selected_capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
                'sync_technician' => true,
                'geocode_mode' => 'none',
                'candidates' => [[
                    'mikro_cari_kodu' => '320.ÇLG.06.002',
                    'display_name' => 'BAHATTİN ÖZBEK',
                    'mikro_cari_unvan' => 'BAHATTİN ÖZBEK',
                    'city' => 'ANKARA',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('items.0.partner_action', 'update_partner')
            ->assertJsonPath('items.0.role_changes.0', B2BPartner::TYPE_LOCKSMITH.'_added')
            ->assertJsonPath('items.0.technician_action', 'create_technician')
            ->assertJsonPath('items.0.ignored_technician_id', $berkay->id)
            ->assertJsonPath('items.0.ignored_technician_reason', 'different_person_same_cari_or_phone');

        $this->assertSame('BERKAY ATLAS', $berkay->fresh()->name);
        $this->assertSame('İzmir', $berkay->fresh()->city);
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

    public function test_b2b_partner_management_is_accessible_as_separate_module(): void
    {
        $moduleLayout = file_get_contents(resource_path('js/layouts/module-layout.tsx')) ?: '';

        $this->assertStringContainsString("label: 'Bayi & Çilingir'", $moduleLayout);
        $this->assertStringContainsString("'/panel/b2b/partners'", $moduleLayout);
        $this->assertStringContainsString("'/panel/b2b/users'", $moduleLayout);
        $this->assertStringContainsString("tone: 'violet'", $moduleLayout);
    }

    public function test_technical_service_navigation_does_not_absorb_b2b_partner_management(): void
    {
        $moduleLayout = file_get_contents(resource_path('js/layouts/module-layout.tsx')) ?: '';
        $technicalServiceBlock = Str::between($moduleLayout, "label: 'Teknik Servis'", "label: 'Müşteri Yönetimi'");

        $this->assertStringNotContainsString('/panel/b2b', $technicalServiceBlock);
        $this->assertStringContainsString('/technical-service/technicians', $technicalServiceBlock);
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

    public function test_partner_job_card_and_earnings_tab_share_earning_summary_for_draft_and_sent_srv(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Portal Canonical Earnings Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Portal Canonical Earnings Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);

        $sentSrv = $this->serviceRequestForTechnician($technician, 'SRV-CANONICAL-EARNING-002', [
            'root_mrn' => 'MRN-CANONICAL-EARNING',
            'service_code' => 'SRV-CANONICAL-EARNING-002',
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now()->subDay(),
            'scheduled_at' => now()->subDays(2),
            'scheduled_date' => now()->subDays(2)->toDateString(),
            'customer_name' => 'Canonical Musteri',
            'customer_city' => 'Eskişehir',
            'customer_district' => 'Beylikova',
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $sentSrv->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 1000,
            'route_fee_amount' => 2500,
            'total_amount' => 3500,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now()->subDay(),
        ]);

        $draftSrv = $this->serviceRequestForTechnician($technician, 'SRV-CANONICAL-EARNING-003', [
            'parent_request_id' => $sentSrv->id,
            'root_mrn' => 'MRN-CANONICAL-EARNING',
            'service_code' => 'SRV-CANONICAL-EARNING-003',
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now(),
            'scheduled_at' => now()->addDays(2),
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'customer_name' => 'Canonical Musteri',
            'customer_city' => 'Eskişehir',
            'customer_district' => 'Beylikova',
            'technician_payment_amount' => 0,
            'travel_fee_amount' => 4850,
            'operation_control_payload' => [
                'completed_earning_snapshot' => [
                    'completed_request_id' => null,
                    'mrn' => 'SRV-CANONICAL-EARNING-003',
                    'root_mrn' => 'MRN-CANONICAL-EARNING',
                    'technical_service_technician_id' => $technician->id,
                    'technician_name' => $technician->name,
                    'labor_amount' => 0,
                    'route_fee_amount' => 4850,
                    'total_amount' => 4850,
                    'payout_status' => 'draft',
                    'source' => 'completion_snapshot',
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
            ->get('/partner/service-jobs?partner_id='.$partner->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/service-jobs')
                ->where('partnerPortal.serviceJobs.0.earning_breakdown.current_visit.total_amount', 4850)
                ->where('partnerPortal.serviceJobs.0.earning_breakdown.current_visit.status_label', 'Taslak')
                ->where('partnerPortal.serviceJobs.1.earning_breakdown.current_visit.total_amount', 3500)
                ->where('partnerPortal.serviceJobs.1.earning_breakdown.current_visit.status_label', 'Gönderildi')
            );

        $this->actingAs($portalUser)
            ->get('/partner/earnings?partner_id='.$partner->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/earnings')
                ->where('partnerPortal.earnings.pending.summary.job_count', 1)
                ->where('partnerPortal.earnings.pending.summary.grand_total', 4850)
                ->where('partnerPortal.earnings.pending.rows.0.mrn', $draftSrv->mrn)
                ->where('partnerPortal.earnings.pending.rows.0.job_status_label', 'İş tamamlandı')
                ->where('partnerPortal.earnings.pending.rows.0.earning_status', 'draft')
                ->where('partnerPortal.earnings.pending.rows.0.status_label', 'Taslak')
                ->where('partnerPortal.earnings.pending.rows.0.earning_bucket_label', 'Hakediş onayı bekliyor')
                ->where('partnerPortal.earnings.pending.rows.0.explanation', 'İş tamamlandı; hakediş operasyon onayı veya gönderimi sonrası kesinleşir.')
                ->where('partnerPortal.earnings.completed.summary.job_count', 1)
                ->where('partnerPortal.earnings.completed.summary.grand_total', 3500)
                ->where('partnerPortal.earnings.completed.rows.0.items.0.mrn', $sentSrv->mrn)
                ->where('partnerPortal.earnings.completed.rows.0.items.0.job_status_label', 'İş tamamlandı')
                ->where('partnerPortal.earnings.completed.rows.0.items.0.earning_status', 'sent')
                ->where('partnerPortal.earnings.completed.rows.0.items.0.status', 'Gönderildi')
                ->where('partnerPortal.earnings.completed.rows.0.items.0.earning_bucket_label', 'Kesinleşen/gönderilen hakediş')
            );
    }

    public function test_finalized_earning_completed_payable_srv003_partner_earnings_tab_partner_job_card_not_pending_estimated_after_ops_final_approval(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Portal Finalized Earnings Locksmith',
        ]);
        $technician = $this->technician(['name' => 'EMRE YİĞİT']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);

        $sentSrv = $this->serviceRequestForTechnician($technician, 'SRV-CANONICAL-FINALIZED-002', [
            'root_mrn' => 'MRN-CANONICAL-FINALIZED',
            'service_code' => 'SRV-CANONICAL-FINALIZED-002',
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now()->subDay(),
            'scheduled_at' => now()->subDays(2),
            'scheduled_date' => now()->subDays(2)->toDateString(),
            'customer_name' => 'Canonical Final Musteri',
            'customer_city' => 'Eskişehir',
            'customer_district' => 'Beylikova',
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $sentSrv->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 1000,
            'route_fee_amount' => 2500,
            'total_amount' => 3500,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now()->subDay(),
        ]);

        $finalizedSrv = $this->serviceRequestForTechnician($technician, 'SRV-CANONICAL-FINALIZED-003', [
            'parent_request_id' => $sentSrv->id,
            'root_mrn' => 'MRN-CANONICAL-FINALIZED',
            'service_code' => 'SRV-CANONICAL-FINALIZED-003',
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now(),
            'scheduled_at' => now()->addDays(2),
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'customer_name' => 'Canonical Final Musteri',
            'customer_city' => 'Eskişehir',
            'customer_district' => 'Beylikova',
            'technician_payment_amount' => 0,
            'travel_fee_amount' => 4850,
            'operation_control_payload' => [
                'completed_earning_snapshot' => [
                    'completed_request_id' => null,
                    'mrn' => 'SRV-CANONICAL-FINALIZED-003',
                    'root_mrn' => 'MRN-CANONICAL-FINALIZED',
                    'technical_service_technician_id' => $technician->id,
                    'technician_name' => $technician->name,
                    'labor_amount' => 0,
                    'route_fee_amount' => 4850,
                    'total_amount' => 4850,
                    'payout_status' => 'draft',
                    'source' => 'completion_snapshot',
                ],
            ],
        ]);
        $operationControl = $finalizedSrv->operation_control_payload;
        $operationControl['ops_final_payout_approval'] = [
            'approved_request_ids' => [$finalizedSrv->id],
            'excluded_request_ids' => [$sentSrv->id],
            'approved_at' => now()->toISOString(),
            'approved_by_user_id' => $admin->id,
        ];
        $finalizedSrv->forceFill(['operation_control_payload' => $operationControl])->save();

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->get('/partner/service-jobs?partner_id='.$partner->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/service-jobs')
                ->where('partnerPortal.serviceJobs.0.mrn', $finalizedSrv->mrn)
                ->where('partnerPortal.serviceJobs.0.earning_breakdown.current_visit.total_amount', 4850)
                ->where('partnerPortal.serviceJobs.0.earning_breakdown.current_visit.status_label', 'Kesinleşti')
                ->where('partnerPortal.serviceJobs.1.mrn', $sentSrv->mrn)
                ->where('partnerPortal.serviceJobs.1.earning_breakdown.current_visit.total_amount', 3500)
                ->where('partnerPortal.serviceJobs.1.earning_breakdown.current_visit.status_label', 'Gönderildi')
            );

        $this->actingAs($portalUser)
            ->get('/partner/earnings?partner_id='.$partner->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/earnings')
                ->where('partnerPortal.earnings.pending.summary.job_count', 0)
                ->where('partnerPortal.earnings.pending.summary.grand_total', 0)
                ->where('partnerPortal.earnings.completed.summary.job_count', 2)
                ->where('partnerPortal.earnings.completed.summary.grand_total', 8350)
                ->where('partnerPortal.earnings.completed.rows.0.items.0.mrn', $finalizedSrv->mrn)
                ->where('partnerPortal.earnings.completed.rows.0.items.0.job_status_label', 'İş tamamlandı')
                ->where('partnerPortal.earnings.completed.rows.0.items.0.earning_status', 'finalized')
                ->where('partnerPortal.earnings.completed.rows.0.items.0.status', 'Kesinleşti')
                ->where('partnerPortal.earnings.completed.rows.0.items.0.earning_bucket_label', 'Kesinleşen/gönderilen hakediş')
                ->where('partnerPortal.earnings.completed.rows.1.items.0.mrn', $sentSrv->mrn)
                ->where('partnerPortal.earnings.completed.rows.1.items.0.job_status_label', 'İş tamamlandı')
                ->where('partnerPortal.earnings.completed.rows.1.items.0.earning_status', 'sent')
                ->where('partnerPortal.earnings.completed.rows.1.items.0.status', 'Gönderildi')
            );
    }

    public function test_partner_earnings_top_cards_use_earning_status_not_job_status_language(): void
    {
        $source = file_get_contents(resource_path('js/pages/partner/portal-shell.tsx'));

        $this->assertStringContainsString('Hakediş onayı bekleyen iş', $source);
        $this->assertStringContainsString('İş tamamlandı, hakediş kesinleşmedi', $source);
        $this->assertStringContainsString('Kesinleşen hakediş', $source);
        $this->assertStringContainsString('Gönderilen / onaylanan kayıt', $source);
        $this->assertStringContainsString('Hakediş onayı bekleyen tamamlanan işler', $source);
        $this->assertStringContainsString('İş durumu:', $source);
        $this->assertStringContainsString('Hakediş durumu:', $source);
        $this->assertStringNotContainsString('title="Bekleyen iş"', $source);
        $this->assertStringNotContainsString('title="Tamamlanan iş"', $source);
        $this->assertStringNotContainsString('Actual hakediş değildir', $source);
    }

    public function test_draft_earning_completed_job_appears_in_pending_earnings(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Portal Draft Earnings Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Portal Draft Earnings Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $draftJob = $this->serviceRequestForTechnician($technician, 'MRN-DRAFT-EARNING-PENDING', [
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now(),
            'technician_payment_amount' => 1500,
            'travel_fee_amount' => 350,
            'operation_control_payload' => [
                'completed_earning_snapshot' => [
                    'labor_amount' => 1500,
                    'route_fee_amount' => 350,
                    'total_amount' => 1850,
                    'payout_status' => 'draft',
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
            ->get('/partner/earnings?partner_id='.$partner->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('partnerPortal.earnings.pending.rows.0.mrn', $draftJob->mrn)
                ->where('partnerPortal.earnings.pending.rows.0.line_total', 1850)
                ->where('partnerPortal.earnings.pending.rows.0.job_status_label', 'İş tamamlandı')
                ->where('partnerPortal.earnings.pending.rows.0.earning_status', 'draft')
                ->where('partnerPortal.earnings.pending.rows.0.status_label', 'Taslak')
                ->where('partnerPortal.earnings.completed.summary.job_count', 0)
            );
    }

    public function test_sent_earning_completed_job_appears_in_completed_earnings(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Portal Sent Earnings Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Portal Sent Earnings Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $sentJob = $this->serviceRequestForTechnician($technician, 'MRN-SENT-EARNING-COMPLETED', [
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now(),
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $sentJob->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 1200,
            'route_fee_amount' => 300,
            'total_amount' => 1500,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->get('/partner/earnings?partner_id='.$partner->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('partnerPortal.earnings.completed.rows.0.items.0.mrn', $sentJob->mrn)
                ->where('partnerPortal.earnings.completed.rows.0.items.0.job_status_label', 'İş tamamlandı')
                ->where('partnerPortal.earnings.completed.rows.0.items.0.earning_status', 'sent')
                ->where('partnerPortal.earnings.completed.rows.0.items.0.status', 'Gönderildi')
                ->where('partnerPortal.earnings.completed.summary.grand_total', 1500)
                ->where('partnerPortal.earnings.pending.summary.job_count', 0)
            );
    }

    public function test_excluded_earning_cancelled_job_not_counted_in_active_partner_earnings(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Portal Excluded Earnings Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Portal Excluded Earnings Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $cancelledJob = $this->serviceRequestForTechnician($technician, 'MRN-EXCLUDED-EARNING-CANCELLED', [
            'workflow_status' => 'İptal',
            'status' => 'İptal',
            'cancelled_at' => now(),
            'technician_payment_amount' => 2500,
            'travel_fee_amount' => 500,
        ]);
        TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $cancelledJob->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 2500,
            'route_fee_amount' => 500,
            'total_amount' => 3000,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->get('/partner/earnings?partner_id='.$partner->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('partnerPortal.earnings.pending.summary.grand_total', 0)
                ->where('partnerPortal.earnings.completed.summary.grand_total', 0)
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
            'scheduled_at' => now()->addDay(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '14:00',
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

    public function test_partner_service_jobs_normalizes_legacy_turkish_address_for_display(): void
    {
        $scope = $this->partnerPortalScopeFixture();
        $scope['jobA']->forceFill([
            'customer_name' => 'M??teri Smoke',
            'customer_city' => '?stanbul',
            'customer_district' => 'Kad?k?y',
            'service_address' => '?stanbul · Kad?k?y',
            'status' => 'Planl?',
            'workflow_status' => 'Tamamland?',
        ])->save();

        $response = $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonPath('jobs.0.customer_name', 'Müşteri Smoke')
            ->assertJsonPath('jobs.0.city', 'İstanbul')
            ->assertJsonPath('jobs.0.district', 'Kadıköy')
            ->assertJsonPath('jobs.0.address_summary', 'İstanbul · Kadıköy')
            ->assertJsonPath('jobs.0.status_label', 'Planlı')
            ->assertJsonPath('jobs.0.service_stage_label', 'Tamamlandı');

        $encoded = json_encode($response->json('jobs.0'), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('?stanbul', $encoded);
        $this->assertStringNotContainsString('Kad?k?y', $encoded);
        $this->assertStringNotContainsString('M??teri', $encoded);
        $this->assertStringNotContainsString('Planl?', $encoded);
        $this->assertStringNotContainsString('Tamamland?', $encoded);
        $this->assertStringNotContainsString('�', $encoded);
    }

    public function test_partner_payload_keeps_display_labels_normalized(): void
    {
        $scope = $this->partnerPortalScopeFixture();
        $scope['jobA']->forceFill([
            'product_name' => 'Portal Ak?ll? Kap? Kilidi',
            'product_model' => 'Kap? Model',
        ])->save();

        $response = $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerA']->id)
            ->assertOk();

        $job = collect($response->json('jobs'))->firstWhere('mrn', $scope['jobA']->mrn);

        $this->assertIsArray($job);
        $this->assertSame('Portal Akıllı Kapı Kilidi', $job['product_name']);
        $this->assertSame('Kapı Model', $job['product_model']);
        $this->assertSame('Kapı Model', $job['model']);

        $encoded = json_encode($job, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Ak?ll?', $encoded);
        $this->assertStringNotContainsString('Kap?', $encoded);
    }

    public function test_ops_detail_does_not_render_mojibake_user_facing_labels(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician([
            'name' => 'SMOKE-SCOPE-20260606021857 Other Usta',
            'city' => '?stanbul',
            'district' => 'Kad?k?y',
        ]);
        $request = $this->serviceRequestForTechnician($technician, 'MRN-LEGACY-TR', [
            'customer_name' => 'M??teri Smoke',
            'customer_city' => '?stanbul',
            'customer_district' => 'Kad?k?y',
            'service_address' => '?stanbul · Kad?k?y',
            'status' => 'Planl?',
            'workflow_status' => 'Tamamland?',
            'technician_name' => $technician->name,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/technical-service/requests/'.$request->id)
            ->assertOk()
            ->assertJsonPath('request.customer_name', 'Müşteri Smoke')
            ->assertJsonPath('request.customer_city', 'İstanbul')
            ->assertJsonPath('request.customer_district', 'Kadıköy')
            ->assertJsonPath('request.service_address', 'İstanbul · Kadıköy')
            ->assertJsonPath('request.status', 'Tamamlandı')
            ->assertJsonPath('request.workflow_status', 'Tamamlandı')
            ->assertJsonPath('request.technical_service_technician.name', 'SMOKE-SCOPE-20260606021857 Diğer Usta');

        $encoded = json_encode($response->json('request'), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach (['?stanbul', 'Kad?k?y', 'M??teri', 'Planl?', 'Tamamland?', 'Other Usta', '�'] as $badFragment) {
            $this->assertStringNotContainsString($badFragment, $encoded);
        }
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

    public function test_partner_cancelled_service_job_is_hidden_from_partner_active_portal(): void
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
            ->assertOk()
            ->assertJsonPath('job.mrn', 'MRN-SCOPE-A')
            ->assertJsonPath('job.is_current_active_assignment', false)
            ->assertJsonPath('job.should_show_current_actions', false)
            ->assertJsonPath('job.next_action', 'İptal edildi')
            ->assertJsonPath('job.cancel_context.exists', true)
            ->assertJsonPath('job.cancel_context.summary', 'İş iptal edildi. Hakedişe dahil değil.')
            ->assertJsonPath('job.cancel_context.earning_excluded_label', 'İptal nedeniyle hakedişe dahil değil')
            ->assertJsonPath('job.earning_summary.total_amount', 0)
            ->assertJsonPath('job.earning_summary.excluded_from_payable', true)
            ->assertJsonPath('job.earning_summary.exclusion_label', 'İptal nedeniyle hakedişe dahil değil')
            ->assertJsonPath('job.can_upload_photos', false)
            ->assertJsonPath('job.can_request_customer_otp', false)
            ->assertJsonPath('job.can_submit_completion', false);
    }

    public function test_cancel_review_service_job_is_hidden_from_active_portal_but_detail_is_read_only(): void
    {
        $scope = $this->partnerPortalScopeFixture();
        $scope['jobA']->forceFill([
            'status' => 'Devam Ediyor',
            'workflow_status' => 'Beklemede',
            'pending_reason' => TechnicalServiceWorkflowService::CANCELLATION_REVIEW_PENDING_REASON,
            'operation_control_payload' => [
                TechnicalServiceWorkflowService::CANCELLATION_REVIEW_KEY => [
                    'status' => 'pending',
                    'reason' => 'Müşteri iptal istedi',
                ],
            ],
        ])->save();

        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonMissing(['mrn' => 'MRN-SCOPE-A']);

        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/service-jobs/'.$scope['jobA']->id)
            ->assertOk()
            ->assertJsonPath('job.mrn', 'MRN-SCOPE-A')
            ->assertJsonPath('job.is_current_active_assignment', false)
            ->assertJsonPath('job.should_show_current_actions', false)
            ->assertJsonPath('job.next_action', 'İptal incelemede')
            ->assertJsonPath('job.cancel_context.exists', true)
            ->assertJsonPath('job.cancel_context.is_cancel_review', true)
            ->assertJsonPath('job.cancel_context.current_stage_label', 'İptal incelemede')
            ->assertJsonPath('job.earning_summary.total_amount', 0)
            ->assertJsonPath('job.earning_summary.excluded_from_payable', true)
            ->assertJsonPath('job.can_receive_part', false)
            ->assertJsonPath('job.can_upload_photos', false)
            ->assertJsonPath('job.can_request_customer_otp', false)
            ->assertJsonPath('job.can_submit_completion', false);
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
            ->assertJsonPath('job.earning_summary.job_count', 2)
            ->assertJsonPath('job.earning_breakdown.current_visit.technician_name', 'Scope Usta A')
            ->assertJsonPath('job.earning_breakdown.rows.0.technician_name', 'Scope Usta A')
            ->assertJsonPath('job.earning_breakdown.rows.1.technician_name', 'Scope Usta A')
            ->assertJsonPath('job.earning_breakdown.root_total.is_multi_technician', false);

        $this->actingAs($scope['userA'])
            ->getJson('/api/partner/earnings?partner_id='.$scope['partnerA']->id)
            ->assertOk()
            ->assertJsonPath('items.0.earnings.completed.summary.grand_total', 3000)
            ->assertJsonPath('items.0.earnings.completed.summary.job_count', 1)
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
        $appointmentChangeState = app(TechnicalServiceOperationalStatePresenter::class)
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

        $receiveResponse = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/part-requests/{$partRequest->id}/received")
            ->assertOk()
            ->assertJsonPath('part_request.status', TechnicalServicePartRequest::STATUS_SERVICE_VISIT_CREATED)
            ->assertJsonPath('parent_job.can_receive_part', false)
            ->assertJsonPath('job.can_receive_part', false)
            ->assertJsonPath('job.can_propose_appointment', true)
            ->assertJsonPath('job.parent_request_id', $job->id)
            ->assertJsonPath('job.kanban_column', 'new_jobs');

        $childId = $receiveResponse->json('job.id');
        $child = TechnicalServiceRequest::query()->findOrFail($childId);
        $this->assertSame($job->id, $child->parent_request_id);
        $this->assertSame($job->mrn, $child->root_mrn);
        $this->assertSame('SRV-ACTION-PART-001', $child->service_code);
        $this->assertSame('SRV-ACTION-PART-001', $child->mrn);
        $this->assertSame('Servis', $child->service_type);
        $this->assertSame($technician->id, (int) $child->technical_service_technician_id);
        $this->assertNotNull($child->technician_approved_at);
        $this->assertNull($child->scheduled_at);
        $this->assertNull($child->scheduled_date);
        $this->assertNull($child->scheduled_time);
        $this->assertSame(0, $child->uploads()->count());
        $this->assertSame(0, $child->customerConfirmations()->count());
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
            ->getJson("/api/partner/service-jobs/{$job->id}?partner_id={$partner->id}")
            ->assertForbidden();

        $childResponse = $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$child->id}?partner_id={$partner->id}");
        $this->assertSame(200, $childResponse->status(), $childResponse->content());
        $childResponse
            ->assertJsonPath('job.mrn', $child->mrn)
            ->assertJsonPath('job.next_action', 'Usta randevu önerecek')
            ->assertJsonPath('job.can_propose_appointment', true)
            ->assertJsonPath('job.can_upload_photos', false)
            ->assertJsonPath('job.can_submit_completion', false);
        $this->assertNotContains('Fotoğraf bekliyor', $childResponse->json('job.badges'));
        $this->assertNotContains('Fotoğraf eksik', $childResponse->json('job.badges'));

        $duplicateReceiveResponse = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/part-requests/{$partRequest->id}/received")
            ->assertOk()
            ->assertJsonPath('job.id', $child->id);

        $this->assertSame($child->id, $duplicateReceiveResponse->json('job.id'));
        $this->assertSame(1, TechnicalServiceRequest::query()
            ->where('source_part_request_id', $partRequest->id)
            ->count());

        $opsPayload = $this->actingAs($admin)
            ->getJson('/api/technical-service/requests?limit=200')
            ->assertOk()
            ->json('items');
        $this->assertNotContains($job->id, collect($opsPayload)->pluck('id')->all());
        $this->assertContains($child->id, collect($opsPayload)->pluck('id')->all());

        $childState = app(TechnicalServiceOperationalStatePresenter::class)->present($child->fresh());
        $this->assertSame('assigned', $childState['ops_column']);
        $this->assertSame('Usta randevu önerecek', $childState['display_action_label']);
        $this->assertFalse($childState['is_field_docs_required']);
        $this->assertFalse($childState['is_customer_approval_required']);
        $this->assertTrue($childState['requires_technician_action']);

        $this->actingAs($admin)
            ->getJson("/api/technical-service/requests/{$child->id}")
            ->assertOk()
            ->assertJsonPath('request.display_action_label', 'Usta randevu önerecek')
            ->assertJsonPath('request.operational_state.action_label', 'Usta randevu önerecek')
            ->assertJsonPath('request.next_action_payload.title', 'Usta randevu önerecek');
    }

    public function test_technical_service_full_locksmith_part_return_journey(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        Storage::fake('public');
        config([
            'services.evolution.real_send_enabled' => false,
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
        ]);
        Http::fake();

        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'E2E Flow Locksmith',
        ]);
        $otherPartner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'E2E Flow Other Locksmith',
        ]);
        $technician = $this->technician(['name' => 'E2E Flow Usta']);
        $otherTechnician = $this->technician(['name' => 'E2E Other Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);

        $job = $this->serviceRequestForTechnician($technician, 'MRN-E2E-FLOW-PART-RETURN', [
            'workflow_status' => 'Usta Onayı Bekleyen',
            'status' => 'Atandı',
            'technician_approval_status' => null,
            'technician_approved_at' => null,
            'scheduled_at' => null,
            'scheduled_date' => null,
            'scheduled_time' => null,
            'customer_name' => 'E2E Flow Müşteri',
            'customer_phone' => '+905550010101',
            'service_address' => 'E2E Flow test adresi',
            'product_name' => 'E2E Test Kilit',
            'product_model' => 'E2E-LOCK',
            'brand' => 'Emaks Prime',
            'serial_number' => 'E2E-FLOW-SERIAL-001',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
            'operation_control_checked_at' => now(),
            'operation_control_checked_by_user_id' => $admin->id,
        ]);
        $job->requestSerials()->create([
            'mrn' => $job->mrn,
            'serial_number' => 'E2E-FLOW-SERIAL-001',
            'product_name' => 'E2E Test Kilit',
            'product_model' => 'E2E-LOCK',
            'brand' => 'Emaks Prime',
            'customer_selected' => true,
            'customer_visible' => true,
            'customer_selectable' => true,
            'is_primary' => true,
        ]);
        $session = $this->mountSessionForServiceRequest($job);
        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $job->id,
            'provider' => 'fake',
            'provider_reference' => 'E2E-FLOW-MOUNT',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3500,
            'currency' => 'TRY',
            'payment_url' => 'http://127.0.0.1:8000/mount-payment/e2e-flow-mount',
            'paid_at' => now(),
            'raw_payload' => ['source' => 'mount_payment'],
        ]);

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();
        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$otherPartner->id}/provision-admin-user")
            ->assertCreated();
        $otherPortalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $otherPartner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/accept-appointment", ['note' => 'İş kabul edildi.'])
            ->assertOk()
            ->assertJsonPath('job.next_action', 'Usta randevu önerecek')
            ->assertJsonPath('job.can_propose_appointment', true)
            ->assertJsonPath('job.can_upload_photos', false);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/appointment-proposal", [
                'slots' => [['date' => now()->addDay()->toDateString(), 'slot' => '10:00-11:00']],
                'note' => 'Müşteri yarın uygundur.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->assertJsonPath('job.next_action', 'Randevu önerildi');
        $firstAppointmentAction = TechnicalServicePartnerJobAction::query()
            ->where('technical_service_request_id', $job->id)
            ->where('action', TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED)
            ->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/partner-appointment-proposals/{$firstAppointmentAction->id}/approve", [
                'note' => 'İlk randevu onaylandı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Planlı')
            ->assertJsonPath('request.kanban_column', 'assigned');

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/support-request", [
                'type' => 'spare_part',
                'description' => 'Montaj sırasında panel parçası eksik çıktı.',
                'product_name' => 'Panel',
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('job.kanban_column', 'ops_review')
            ->assertJsonPath('job.next_action', 'Parça talebi operasyon incelemesinde');
        $partRequest = TechnicalServicePartRequest::query()
            ->where('technical_service_request_id', $job->id)
            ->firstOrFail();

        $chargeableResponse = $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$job->id}/part-requests/{$partRequest->id}", [
                'status' => TechnicalServicePartRequest::STATUS_APPROVED,
                'charge_decision' => 'chargeable',
                'service_amount' => 1000,
                'part_amount' => 750,
                'customer_message' => 'Parça ve servis bedeli için ödeme linki.',
                'partner_message' => 'Parça talebiniz onaylandı.',
            ])
            ->assertOk()
            ->assertJsonPath('part_request.charge_decision', 'chargeable')
            ->assertJsonPath('customer_charge.total_amount', 1750)
            ->assertJsonPath('payment_summary.mount.amount', 3500);
        $customerCharge = TechnicalServiceMountPayment::query()
            ->where('raw_payload->part_request_id', $partRequest->id)
            ->firstOrFail();
        $this->assertNotEmpty($chargeableResponse->json('customer_charge.payment_url'));
        app(TechnicalServicePaymentSettlementService::class)
            ->markPaid($customerCharge, ['fake_approved' => true]);

        $paidSummary = app(TechnicalServiceWorkflowService::class)
            ->serialize($job->refresh(), true)['sale_and_payment']['payment_summary'];
        $this->assertSame(3500.0, (float) $paidSummary['mount']['amount']);
        $this->assertSame(1000.0, (float) $paidSummary['service']['amount']);
        $this->assertSame(750.0, (float) $paidSummary['part']['amount']);
        $this->assertSame(5250.0, (float) $paidSummary['total_customer_collection']);

        foreach ([TechnicalServicePartRequest::STATUS_ORDERED, TechnicalServicePartRequest::STATUS_SENT] as $status) {
            $payload = ['status' => $status, 'note' => 'Parça akışı güncellendi.'];
            if ($status === TechnicalServicePartRequest::STATUS_SENT) {
                $payload['tracking_no'] = 'E2E-TRACK-001';
                $payload['shipment_provider'] = 'Test Kargo';
            }

            $this->actingAs($admin)
                ->patchJson("/api/technical-service/requests/{$job->id}/part-requests/{$partRequest->id}", $payload)
                ->assertOk()
                ->assertJsonPath('part_request.status', $status);
        }

        $receiveResponse = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/part-requests/{$partRequest->id}/received")
            ->assertOk()
            ->assertJsonPath('part_request.status', TechnicalServicePartRequest::STATUS_SERVICE_VISIT_CREATED)
            ->assertJsonPath('job.next_action', 'Usta randevu önerecek')
            ->assertJsonPath('job.can_propose_appointment', true)
            ->assertJsonPath('job.can_upload_photos', false)
            ->assertJsonPath('job.can_submit_completion', false);
        $childId = (int) $receiveResponse->json('job.id');
        $child = TechnicalServiceRequest::query()->findOrFail($childId);
        $this->assertSame($job->id, (int) $child->parent_request_id);
        $this->assertSame($technician->id, (int) $child->technical_service_technician_id);
        $this->assertNull($child->scheduled_at);
        $this->assertNull($child->customer_closure_approval_status);
        $this->assertSame(0, $child->uploads()->whereIn('field_code', ['before_photo', 'after_photo', 'warranty_document_photo'])->count());

        $childOpsState = app(TechnicalServiceOperationalStatePresenter::class)->present($child->fresh());
        $this->assertSame('assigned', $childOpsState['ops_column']);
        $this->assertSame('Usta randevu önerecek', $childOpsState['display_action_label']);
        $this->assertFalse($childOpsState['is_field_docs_required']);
        $this->assertFalse($childOpsState['is_customer_approval_required']);

        $board = app(B2BPartnerPortalDataService::class)->serviceJobBoardFor($partner);
        $activeMrns = collect($board['columns'])
            ->flatMap(fn (array $column): array => $column['jobs'] ?? [])
            ->pluck('mrn')
            ->all();
        $this->assertContains($child->mrn, $activeMrns);
        $this->assertNotContains($job->mrn, $activeMrns);
        $this->actingAs($otherPortalUser)
            ->getJson("/api/partner/service-jobs/{$child->id}")
            ->assertForbidden();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$child->id}/appointment-proposal", [
                'slots' => [['date' => now()->addDays(2)->toDateString(), 'slot' => '14:00-15:00']],
                'note' => 'Parça sonrası randevu önerildi.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW);
        $childAppointmentAction = TechnicalServicePartnerJobAction::query()
            ->where('technical_service_request_id', $child->id)
            ->where('action', TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED)
            ->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$child->id}/partner-appointment-proposals/{$childAppointmentAction->id}/approve", [
                'note' => 'Parça sonrası randevu onaylandı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Planlı')
            ->assertJsonPath('request.kanban_column', 'assigned');

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$child->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before.jpg', 256, 'image/jpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 1)
            ->assertJsonPath('job.completion_requirements.photos_ready', false);
        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$child->id}/photos", [
                'after_photo' => UploadedFile::fake()->create('after.png', 256, 'image/png'),
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 2)
            ->assertJsonPath('job.completion_requirements.photos_ready', false);
        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$child->id}/photos", [
                'warranty_document_photo' => UploadedFile::fake()->create('warranty.HEIC', 256, 'image/heic'),
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 3)
            ->assertJsonPath('job.completion_requirements.photos_ready', true);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$child->id}/customer-otp-request", [
                'note' => 'Müşteri onayı istendi.',
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.customer_confirmation_ready', false);
        $confirmation = TechnicalServiceCustomerConfirmation::query()
            ->where('technical_service_request_id', $child->id)
            ->where('status', TechnicalServiceCustomerConfirmation::STATUS_PENDING)
            ->latest('id')
            ->firstOrFail();
        $this->post("/service-job-confirmation/{$confirmation->token}/approve", [
            'customer_note' => 'Servis onaylandı.',
        ])->assertOk();

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$child->id}")
            ->assertOk()
            ->assertJsonPath('job.next_action', 'Tamamlamaya gönderilebilir')
            ->assertJsonPath('job.can_submit_completion', true)
            ->assertJsonPath('job.completion_requirements.customer_confirmation_ready', true);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$child->id}/submit-completion", [
                'result' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('job.kanban_column', 'final_check')
            ->assertJsonPath('job.next_action', 'Son kontrol bekliyor');
        $completionAction = TechnicalServicePartnerJobAction::query()
            ->where('technical_service_request_id', $child->id)
            ->where('action', TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED)
            ->firstOrFail();

        foreach ($child->uploads()->whereIn('field_code', ['before_photo', 'after_photo', 'warranty_document_photo'])->get() as $upload) {
            $this->actingAs($admin)
                ->patchJson("/api/technical-service/requests/{$child->id}/field-documents/{$upload->id}/review", [
                    'status' => 'accepted',
                    'note' => 'Belge uygun.',
                ])
                ->assertOk();
        }

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$child->id}/partner-completions/{$completionAction->id}/approve", [
                'note' => 'SRV son kontrol tamamlandı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Tamamlandı')
            ->assertJsonPath('request.kanban_column', 'completed');

        $child->refresh();
        $job->refresh();
        $this->assertNotNull($child->completed_at);
        $this->assertSame('Tamamlandı', $child->workflow_status);
        $this->assertSame('Tamamlandı', $job->workflow_status);
        $this->assertSame('SRV ile tamamlandı', $job->next_action);
        $this->assertSame(0, TechnicalServiceEarning::query()
            ->where('technical_service_request_id', $job->id)
            ->count());
        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$child->id}")
            ->assertOk()
            ->assertJsonPath('job.kanban_column', 'completed');
        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.kanban_column', 'completed');

        $this->actingAs($otherPortalUser)
            ->getJson("/api/partner/service-jobs/{$child->id}")
            ->assertForbidden();
    }

    public function test_spare_part_request_decision_supports_free_and_chargeable_amounts(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Part Decision Usta']);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-PART-DECISION', [
            'serial_number' => 'SN-PART-DECISION',
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'mount_payment_label' => 'Form payment paid',
        ]);
        $session = $this->mountSessionForServiceRequest($job);
        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $job->id,
            'provider' => 'fake',
            'provider_reference' => 'MOUNT-PART-DECISION',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3500,
            'currency' => 'TRY',
            'payment_url' => 'http://127.0.0.1:8000/mount-payment/mount-part-decision',
            'paid_at' => now(),
            'raw_payload' => [
                'source' => 'mount_payment',
                'technical_service_request_id' => $job->id,
            ],
        ]);

        $freeRequest = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $job->id,
            'root_request_id' => $job->id,
            'requested_by_user_id' => $admin->id,
            'requested_by_technician_id' => $technician->id,
            'status' => TechnicalServicePartRequest::STATUS_OPS_REVIEW,
            'part_name' => 'Warranty part',
            'quantity' => 1,
        ]);
        $chargeableRequest = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $job->id,
            'root_request_id' => $job->id,
            'requested_by_user_id' => $admin->id,
            'requested_by_technician_id' => $technician->id,
            'status' => TechnicalServicePartRequest::STATUS_OPS_REVIEW,
            'part_name' => 'Chargeable part',
            'quantity' => 1,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$job->id}/part-requests/{$freeRequest->id}", [
                'status' => TechnicalServicePartRequest::STATUS_APPROVED,
                'charge_decision' => 'free',
                'note' => 'Covered by warranty.',
                'partner_message' => 'Part will be covered free of charge.',
            ])
            ->assertOk()
            ->assertJsonPath('part_request.charge_decision', 'free')
            ->assertJsonPath('part_request.total_amount', 0)
            ->assertJsonPath('part_request.payment_url', null)
            ->assertJsonPath('customer_charge', null)
            ->assertJsonPath('payment_summary.mount.amount', 3500)
            ->assertJsonPath('payment_summary.total_customer_collection', 3500);

        $this->assertSame(0, TechnicalServiceMountPayment::query()
            ->where('raw_payload->source', 'operation_customer_charge')
            ->count());

        $chargeableResponse = $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$job->id}/part-requests/{$chargeableRequest->id}", [
                'status' => TechnicalServicePartRequest::STATUS_APPROVED,
                'charge_decision' => 'chargeable',
                'service_amount' => 1000,
                'part_amount' => 750,
                'customer_message' => 'Service and part fee will be collected from the customer.',
            ])
            ->assertOk()
            ->assertJsonPath('part_request.charge_decision', 'chargeable')
            ->assertJsonPath('part_request.service_amount', 1000)
            ->assertJsonPath('part_request.part_amount', 750)
            ->assertJsonPath('part_request.total_amount', 1750)
            ->assertJsonPath('part_request.customer_message', 'Service and part fee will be collected from the customer.')
            ->assertJsonPath('customer_charge.status', TechnicalServiceMountPayment::STATUS_PENDING)
            ->assertJsonPath('customer_charge.service_amount', 1000)
            ->assertJsonPath('customer_charge.part_amount', 750)
            ->assertJsonPath('customer_charge.total_amount', 1750);

        $paymentUrl = $chargeableResponse->json('customer_charge.payment_url');
        $this->assertNotEmpty($paymentUrl);
        $this->assertSame(1, TechnicalServiceMountPayment::query()
            ->where('raw_payload->source', 'operation_customer_charge')
            ->count());
        $this->assertSame(2, TechnicalServiceMountPayment::query()->count());

        $this->assertSame('free', $freeRequest->refresh()->metadata['charge_decision'] ?? null);
        $this->assertSame('chargeable', $chargeableRequest->refresh()->metadata['charge_decision'] ?? null);
        $customerCharge = TechnicalServiceMountPayment::query()
            ->where('raw_payload->source', 'operation_customer_charge')
            ->firstOrFail();
        $this->assertSame($chargeableRequest->id, (int) ($customerCharge->raw_payload['part_request_id'] ?? 0));
        $this->assertSame(1000.0, (float) ($customerCharge->raw_payload['service_amount'] ?? 0));
        $this->assertSame(750.0, (float) ($customerCharge->raw_payload['part_amount'] ?? 0));

        app(TechnicalServicePaymentSettlementService::class)
            ->markPaid($customerCharge, ['fake_approved' => true]);

        $summary = app(TechnicalServiceWorkflowService::class)
            ->serialize($job->refresh(), true)['sale_and_payment']['payment_summary'];

        $this->assertSame(3500.0, (float) $summary['mount']['amount']);
        $this->assertSame(1000.0, (float) $summary['service']['amount']);
        $this->assertSame(750.0, (float) $summary['part']['amount']);
        $this->assertSame(5250.0, (float) $summary['total_customer_collection']);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PAID, $job->refresh()->mount_payment_status);
    }

    public function test_chargeable_part_payment_records_amount_reference_and_paid_at_in_ops_payload_and_history(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Part Payment Usta']);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-PART-PAYMENT-REF', [
            'serial_number' => 'SN-PART-PAYMENT-REF',
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
        ]);
        $this->mountSessionForServiceRequest($job);
        $partRequest = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $job->id,
            'root_request_id' => $job->id,
            'requested_by_user_id' => $admin->id,
            'requested_by_technician_id' => $technician->id,
            'status' => TechnicalServicePartRequest::STATUS_OPS_REVIEW,
            'part_name' => 'Ücretli barel',
            'quantity' => 1,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$job->id}/part-requests/{$partRequest->id}", [
                'status' => TechnicalServicePartRequest::STATUS_APPROVED,
                'charge_decision' => 'chargeable',
                'service_amount' => 500,
                'part_amount' => 1250,
                'customer_message' => 'Servis/parça ödeme linki.',
            ])
            ->assertOk()
            ->assertJsonPath('customer_charge.total_amount', 1750);

        $payment = TechnicalServiceMountPayment::query()
            ->where('raw_payload->part_request_id', $partRequest->id)
            ->firstOrFail();
        app(TechnicalServicePaymentSettlementService::class)
            ->markPaid($payment, ['receipt_no' => 'DEKONT-PART-55']);
        $child = $this->serviceRequestForTechnician($technician, 'SRV-PART-PAYMENT-REF-001', [
            'parent_request_id' => $job->id,
            'root_mrn' => $job->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-PART-PAYMENT-REF-001',
            'service_visit_reason' => 'spare_part',
            'service_type' => 'Servis',
        ]);

        $partRequest->refresh();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $partRequest->metadata['charge_status'] ?? null);
        $this->assertSame('DEKONT-PART-55', $partRequest->metadata['payment_reference'] ?? null);
        $this->assertSame(1750.0, (float) ($partRequest->metadata['paid_amount'] ?? 0));
        $this->assertNotEmpty($partRequest->metadata['paid_at'] ?? null);

        $response = $this->actingAs($admin)
            ->getJson("/api/technical-service/requests/{$job->id}")
            ->assertOk();
        $partPayload = collect($response->json('request.part_requests'))->firstWhere('id', $partRequest->id);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $partPayload['charge_status'] ?? null);
        $this->assertSame('DEKONT-PART-55', $partPayload['payment_reference'] ?? null);
        $this->assertSame('DEKONT-PART-55', data_get($partPayload, 'customer_charge.payment_reference'));
        $this->assertNotEmpty($partPayload['paid_at'] ?? null);

        $historyResponse = $this->actingAs($admin)
            ->getJson("/api/technical-service/requests/{$child->id}")
            ->assertOk();
        $historyTitles = collect($historyResponse->json('request.service_visit_history.parent_events') ?? [])
            ->pluck('title_label')
            ->all();
        $this->assertContains('Parça ödemesi alındı', $historyTitles);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $job->id,
            'event_type' => 'part_request_payment_paid',
            'title' => 'Parça ödemesi alındı',
        ]);
    }

    public function test_ops_can_create_free_and_chargeable_part_request_from_srv_detail(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Ops Part Usta']);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-OPS-PART-CREATE', [
            'serial_number' => 'SN-OPS-PART-CREATE',
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
        ]);
        $session = $this->mountSessionForServiceRequest($job);
        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $job->id,
            'provider' => 'fake',
            'provider_reference' => 'MOUNT-OPS-PART-CREATE',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3000,
            'currency' => 'TRY',
            'payment_url' => 'http://127.0.0.1:8000/mount-payment/mount-ops-part-create',
            'paid_at' => now(),
            'raw_payload' => ['source' => 'mount_payment'],
        ]);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/part-requests", [
                'part_name' => 'Garanti karşılığı',
                'part_code' => 'FREE-PART-001',
                'quantity' => 2,
                'charge_decision' => 'free',
                'note' => 'Garanti kapsamında değişim.',
            ])
            ->assertCreated()
            ->assertJsonPath('part_request.status', TechnicalServicePartRequest::STATUS_APPROVED)
            ->assertJsonPath('part_request.charge_decision', 'free')
            ->assertJsonPath('part_request.part_code', 'FREE-PART-001')
            ->assertJsonPath('part_request.quantity', 2)
            ->assertJsonPath('part_request.payment_url', null)
            ->assertJsonPath('customer_charge', null);

        $chargeableResponse = $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/part-requests", [
                'part_name' => 'Ücretli karşılık',
                'part_code' => 'PAID-PART-001',
                'quantity' => 1,
                'charge_decision' => 'chargeable',
                'service_amount' => 500,
                'part_amount' => 1250,
                'note' => 'Müşteri ücretli parça istedi.',
                'customer_message' => 'Ücretli parça bedeli müşteri tarafından ödenecektir.',
            ])
            ->assertCreated()
            ->assertJsonPath('part_request.status', TechnicalServicePartRequest::STATUS_APPROVED)
            ->assertJsonPath('part_request.charge_decision', 'chargeable')
            ->assertJsonPath('part_request.service_amount', 500)
            ->assertJsonPath('part_request.part_amount', 1250)
            ->assertJsonPath('part_request.total_amount', 1750)
            ->assertJsonPath('customer_charge.status', TechnicalServiceMountPayment::STATUS_PENDING);

        $this->assertNotEmpty($chargeableResponse->json('customer_charge.payment_url'));
        $this->assertSame(2, TechnicalServicePartRequest::query()
            ->where('technical_service_request_id', $job->id)
            ->where('metadata->source', 'ops_part_request')
            ->count());
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $job->id,
            'event_type' => 'part_request_created',
            'title' => 'Parça talebi oluşturuldu',
        ]);
    }

    public function test_chargeable_part_request_requires_amount_and_customer_message(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Part Validation Usta']);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-PART-VALIDATION');
        $partRequest = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $job->id,
            'root_request_id' => $job->id,
            'requested_by_user_id' => $admin->id,
            'requested_by_technician_id' => $technician->id,
            'status' => TechnicalServicePartRequest::STATUS_OPS_REVIEW,
            'part_name' => 'Validation part',
            'quantity' => 1,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$job->id}/part-requests/{$partRequest->id}", [
                'status' => TechnicalServicePartRequest::STATUS_APPROVED,
                'charge_decision' => 'chargeable',
                'service_amount' => 1000,
                'part_amount' => 0,
                'customer_message' => 'Customer payment message',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('part_amount');

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$job->id}/part-requests/{$partRequest->id}", [
                'status' => TechnicalServicePartRequest::STATUS_APPROVED,
                'charge_decision' => 'chargeable',
                'service_amount' => 1000,
                'part_amount' => 750,
                'customer_message' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_message');
    }

    public function test_ops_extra_photo_and_ops_extra_completion_document_upload_appears_in_same_preview_payload_without_replacing_partner_documents(): void
    {
        Storage::fake('public');

        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Ops Extra Doc Usta']);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-OPS-EXTRA-DOC');

        foreach (['before_photo', 'after_photo', 'warranty_document_photo'] as $fieldCode) {
            $this->createPortalFieldDocument($job, $fieldCode);
        }

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/ops-extra-documents", [
                'ops_extra_documents' => [
                    UploadedFile::fake()->create('ops-extra.jpg', 256, 'image/jpeg'),
                ],
                'note' => 'Operasyon ek kanıt görseli.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $payload = $this->actingAs($admin)
            ->getJson("/api/technical-service/requests/{$job->id}")
            ->assertOk()
            ->json('request.field_completion_documents');

        $documents = collect($payload);
        $this->assertCount(4, $documents);
        $this->assertSame(
            ['after_photo', 'before_photo', 'ops_extra_photo', 'warranty_document_photo'],
            $documents->pluck('field_code')->sort()->values()->all()
        );
        $this->assertSame(3, $documents
            ->whereIn('field_code', ['before_photo', 'after_photo', 'warranty_document_photo'])
            ->count());
        $this->assertSame('ops_extra_document', $documents->firstWhere('field_code', 'ops_extra_photo')['category'] ?? null);
        $this->assertSame('OPS Ek Görsel', $documents->firstWhere('field_code', 'ops_extra_photo')['label'] ?? null);

        $this->assertDatabaseHas('technical_service_request_uploads', [
            'technical_service_request_id' => $job->id,
            'field_code' => 'ops_extra_photo',
            'category' => TechnicalServiceRequestUpload::CATEGORY_OPS_EXTRA_DOCUMENT,
            'review_status' => 'accepted',
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $job->id,
            'event_type' => 'ops_extra_document_uploaded',
            'title' => 'OPS ek görsel yüklendi',
        ]);
    }

    public function test_ops_can_upload_extra_door_photo_and_ops_uploaded_door_photo_appears_in_door_preview_payload(): void
    {
        Storage::fake('public');

        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Ops Door Doc Usta']);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-OPS-DOOR-DOC');

        $response = $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/ops-extra-documents", [
                'ops_extra_documents' => [
                    UploadedFile::fake()->create('ops-door-front.jpg', 256, 'image/jpeg'),
                ],
                'document_type' => 'ops_door_front_photo',
                'note' => 'Kapı görseli operasyon tarafından eklendi.',
            ])
            ->assertOk()
            ->assertJsonPath('uploads.0.field_code', 'ops_door_front_photo');

        $doorPhotos = collect($response->json('request.door_photos'));
        $this->assertCount(1, $doorPhotos);
        $this->assertSame('ops_door_front_photo', $doorPhotos->first()['field_code'] ?? null);
        $this->assertSame(TechnicalServiceRequestUpload::CATEGORY_OPS_EXTRA_DOCUMENT, $doorPhotos->first()['category'] ?? null);
        $this->assertSame('OPS Kapı Ön Yüzü', $doorPhotos->first()['label'] ?? null);
        $this->assertNotEmpty($doorPhotos->first()['preview_url'] ?? null);
        $this->assertStringStartsWith('/api/technical-service/requests/', (string) ($doorPhotos->first()['preview_url'] ?? ''));

        $fieldDocuments = collect($response->json('request.field_completion_documents'));
        $this->assertFalse($fieldDocuments->contains(fn (array $document): bool => ($document['field_code'] ?? null) === 'ops_door_front_photo'));

        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $job->id,
            'event_type' => 'ops_extra_document_uploaded',
            'title' => 'OPS kapı ön yüz görseli yüklendi',
        ]);
    }

    public function test_ops_door_photo_does_not_replace_partner_required_documents_and_keeps_document_source_as_ops(): void
    {
        Storage::fake('public');

        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Ops Door Scope Usta']);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-OPS-DOOR-SCOPE');

        foreach (['before_photo', 'after_photo', 'warranty_document_photo'] as $fieldCode) {
            $this->createPortalFieldDocument($job, $fieldCode);
        }

        $response = $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/ops-extra-documents", [
                'ops_extra_documents' => [
                    UploadedFile::fake()->create('ops-door-side.jpg', 256, 'image/jpeg'),
                ],
                'document_type' => 'ops_door_side_photo',
                'note' => 'Yan yüz kontrol görseli.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $fieldDocuments = collect($response->json('request.field_completion_documents'));
        $this->assertCount(3, $fieldDocuments);
        $this->assertSame(
            ['after_photo', 'before_photo', 'warranty_document_photo'],
            $fieldDocuments->pluck('field_code')->sort()->values()->all()
        );

        $doorPhotos = collect($response->json('request.door_photos'));
        $this->assertTrue($doorPhotos->contains(fn (array $document): bool => ($document['field_code'] ?? null) === 'ops_door_side_photo'));

        $upload = TechnicalServiceRequestUpload::query()
            ->where('technical_service_request_id', $job->id)
            ->where('field_code', 'ops_door_side_photo')
            ->first();

        $this->assertNotNull($upload);
        $this->assertSame(TechnicalServiceRequestUpload::CATEGORY_OPS_EXTRA_DOCUMENT, $upload->category);
        $this->assertSame('technical_service_ops', $upload->review_payload['source'] ?? null);
    }

    public function test_ops_door_photo_upload_requires_valid_image_and_returns_turkish_validation(): void
    {
        Storage::fake('public');

        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'Ops Door Validation Usta']);
        $job = $this->serviceRequestForTechnician($technician, 'MRN-OPS-DOOR-VALIDATION');

        $invalid = $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/ops-extra-documents", [
                'ops_extra_documents' => [
                    UploadedFile::fake()->create('not-image.txt', 1, 'text/plain'),
                ],
                'document_type' => 'ops_door_back_photo',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ops_extra_documents.0');
        $errorText = json_encode($invalid->json('errors'), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($errorText);
        $this->assertStringContainsString('OPS görselleri', $errorText);
        $this->assertStringNotContainsString('validation.mimes', $errorText);
        $this->assertStringNotContainsString('validation.max.file', $errorText);
        $this->assertStringNotContainsString('The file field', $errorText);
        $this->assertStringNotContainsString('kilobytes', $errorText);
    }

    public function test_final_control_multiple_srv_requires_per_visit_payout_selection_and_approve_selected_excluded_visit_is_removed_from_payout_total(): void
    {
        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Final Çoklu SRV Partner',
        ]);
        $technician = $this->technician(['name' => 'Final Çoklu SRV Usta']);
        $parent = $this->serviceRequestForTechnician($technician, 'MRN-FINAL-MULTI-SRV');
        $firstSrv = $this->serviceRequestForTechnician($technician, 'SRV-FINAL-MULTI-SRV-001', [
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-FINAL-MULTI-SRV-001',
            'service_visit_reason' => 'spare_part',
            'service_type' => 'Servis',
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'checklist_status' => 'tamamlandı',
            'customer_closure_approval_status' => 'onaylandı',
            'customer_closure_approved_at' => now(),
        ]);
        $secondSrv = $this->serviceRequestForTechnician($technician, 'SRV-FINAL-MULTI-SRV-002', [
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 2,
            'service_code' => 'SRV-FINAL-MULTI-SRV-002',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Servis',
        ]);

        foreach ([[$parent, 2000, 100], [$firstSrv, 1500, 300], [$secondSrv, 900, 200]] as [$request, $labor, $route]) {
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

        foreach (['before_photo', 'after_photo', 'warranty_document_photo'] as $fieldCode) {
            $this->createPortalFieldDocument($firstSrv, $fieldCode)
                ->forceFill(['review_status' => 'accepted', 'reviewed_at' => now(), 'reviewed_by' => $admin->id])
                ->save();
        }

        $completionAction = TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $firstSrv->id,
            'partner_id' => $partner->id,
            'user_id' => $admin->id,
            'technical_service_technician_id' => $technician->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => [
                'checklist_gate' => 'server_checked',
                'checklist' => ['job_completed' => true],
            ],
        ]);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$firstSrv->id}/partner-completions/{$completionAction->id}/approve", [
                'note' => 'Seçim yapılmadan çoklu SRV hakedişi kapanmamalı.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('approved_visit_ids');

        $response = $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$firstSrv->id}/partner-completions/{$completionAction->id}/approve", [
                'note' => 'İşaretli hakedişler onaylandı.',
                'approved_visit_ids' => [$parent->id, $firstSrv->id],
            ])
            ->assertOk()
            ->assertJsonPath('request.earning_breakdown.root_total.total_amount', 3900)
            ->assertJsonPath('request.earning_breakdown.root_total.excluded_job_count', 1);

        $rows = collect($response->json('request.earning_breakdown.rows'));
        $this->assertFalse($rows->firstWhere('id', $secondSrv->id)['payout_included']);
        $this->assertSame('Hakedişten çıkarıldı', $rows->firstWhere('id', $secondSrv->id)['payout_approval_status_label']);
        $this->assertSame([$parent->id, $firstSrv->id], $parent->refresh()->operation_control_payload['ops_final_payout_approval']['approved_request_ids'] ?? []);
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

        $parentState = app(TechnicalServiceOperationalStatePresenter::class)->present($job->fresh());
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

        $childState = app(TechnicalServiceOperationalStatePresenter::class)->present($child->fresh());
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
        $delegatedParentState = app(TechnicalServiceOperationalStatePresenter::class)->present($job->fresh());
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

    public function test_active_duplicate_filter_hides_parent_when_srv_child_is_active(): void
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
        $parentState = app(TechnicalServiceOperationalStatePresenter::class)->present($parent->fresh());
        $this->assertNull($parent->fresh()->completed_at);
        $this->assertNull($parent->fresh()->installation_completed_at);
        $this->assertNotSame('Tamamlandı', $parent->fresh()->workflow_status);
        $this->assertSame($parent->id, $child->parent_request_id);
        $this->assertSame(1, $parent->childRequests()->count());
        $this->assertTrue(app(B2BPartnerServiceJobScopeService::class)->shouldHideActiveParentWithChild($parent->fresh()));
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
        $activeMrns = collect($response->json('jobs'))
            ->reject(fn (array $job): bool => ($job['kanban_column'] ?? null) === 'completed')
            ->pluck('mrn')
            ->all();
        $allMrns = collect($response->json('jobs'))->pluck('mrn')->all();

        $this->assertNotContains($parent->mrn, $activeMrns);
        $this->assertContains($child->mrn, $allMrns);
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
        $this->assertContains($parent->mrn, $completedMrns);
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

        $closedParent = app(TechnicalServiceServiceVisitService::class)
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

        $closedParent = app(TechnicalServiceServiceVisitService::class)
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

    public function test_parent_documents_history_is_shown_for_srv_detail_context(): void
    {
        $admin = $this->userWithRole('admin', true);
        $technician = $this->technician(['name' => 'SRV History Usta']);
        $parent = $this->serviceRequestForTechnician($technician, 'MRN-ACTION-SRV-HISTORY', [
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
            'completed_at' => now()->subHour(),
            'serial_number' => 'SN-SRV-HISTORY-001',
        ]);
        TechnicalServiceRequestUpload::query()->create([
            'technical_service_request_id' => $parent->id,
            'field_code' => 'before_photo',
            'category' => TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT,
            'original_name' => 'before.jpg',
            'path' => 'technical-service/history/before.jpg',
            'mime' => 'image/jpeg',
            'size' => 2048,
            'review_status' => 'accepted',
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
            ->assertJsonPath('request.service_visit_history.history_records.0.mrn', $parent->mrn)
            ->assertJsonPath('request.service_visit_history.history_records.0.technician_name', 'SRV History Usta')
            ->assertJsonPath('request.service_visit_history.history_records.0.documents.0.field_code', 'before_photo')
            ->assertJsonPath('request.service_visit_history.parent_part_requests.0.part_name', 'Karşılık sacı')
            ->assertJsonPath('request.service_visit_history.parent_events.0.event_type_label', 'Tamamlamaya gönderildi');
    }

    public function test_srv_child_visual_gate_does_not_inherit_parent_completion_gate(): void
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

        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertStringContainsString("isServiceVisitDetail && chip.label === 'Görseller'", $source);
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
            'scheduled_at' => now()->addDay(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '14:00',
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
            ->assertJsonPath('dispatch.dispatch_status', TechnicalServiceMessageDispatch::STATUS_SUPPRESSED)
            ->assertJsonPath('dispatch.target_phone', '905467647428')
            ->assertJsonPath('job.completion_requirements.customer_confirmation_ready', false)
            ->assertJsonPath('job.customer_otp_request.payload.message_payload.dispatch_status', TechnicalServiceMessageDispatch::STATUS_SUPPRESSED)
            ->assertJsonPath('message', 'Müşteri onay mesajı sistem kaydı olarak tutuldu.');

        $this->assertDatabaseHas('technical_service_customer_confirmations', [
            'id' => $oldConfirmation->id,
            'status' => TechnicalServiceCustomerConfirmation::STATUS_CANCELLED,
        ]);
        $this->post("/service-job-confirmation/{$oldConfirmation->token}/approve")
            ->assertStatus(410)
            ->assertSee('Bu onay bağlantısı artık geçerli değil', false)
            ->assertDontSee('Laravel', false);
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
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED, $confirmation->payload['message_payload']['dispatch_status'] ?? null);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $job->id,
            'event' => 'customer_approval_request',
            'target_type' => 'customer',
            'target_phone' => '905467647428',
            'test_mode' => true,
            'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED,
            'provider_key' => 'null_local',
        ]);
        Http::assertNothingSent();

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
        $this->post("/service-job-confirmation/{$confirmation->token}/approve", [
            'customer_note' => 'Tekrar onay denemesi.',
        ])
            ->assertOk()
            ->assertSee('Teşekkür ederiz', false)
            ->assertDontSee('Laravel', false);
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

    public function test_partner_can_upload_required_documents_after_appointment_approval_with_reopened_stale_workflow(): void
    {
        (new B2BPartnerPermissionSeeder)->run();
        Storage::fake('public');

        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => 'Photo Upload Locksmith',
        ]);
        $technician = $this->technician(['name' => 'Photo Upload Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);

        $reopenedAt = now();
        $job = $this->serviceRequestForTechnician($technician, 'MRN-PHOTO-UPLOAD-REOPENED', [
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'field_status' => 'tamamlandı',
            'photo_status' => 'tamamlandı',
            'document_status' => 'tamamlandı',
            'checklist_status' => 'tamamlandı',
            'customer_closure_approval_status' => 'onaylandı',
            'completed_at' => now()->subHour(),
            'reopened_at' => $reopenedAt,
            'reopen_reason' => 'Operasyon düzeltmesi',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => now(),
            'scheduled_at' => now()->addDay(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '14:00',
        ]);

        foreach (['before_photo', 'after_photo', 'warranty_document_photo'] as $fieldCode) {
            $this->createPortalFieldDocument($job, $fieldCode)
                ->forceFill([
                    'created_at' => $reopenedAt->copy()->subMinute(),
                    'updated_at' => $reopenedAt->copy()->subMinute(),
                ])
                ->save();
        }

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}?partner_id={$partner->id}")
            ->assertOk()
            ->assertJsonPath('job.can_upload_photos', true)
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 0)
            ->assertJsonPath('job.completion_requirements.photos_ready', false);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before.jpg', 256, 'image/jpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 1)
            ->assertJsonPath('job.completion_requirements.photos_ready', false);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'after_photo' => UploadedFile::fake()->create('after.png', 256, 'image/png'),
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 2)
            ->assertJsonPath('job.completion_requirements.photos_ready', false);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'warranty_document_photo' => UploadedFile::fake()->create('warranty.HEIC', 256, 'image/heic'),
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 3)
            ->assertJsonPath('job.completion_requirements.photos_ready', true);

        $freshJob = $job->refresh();
        $this->assertSame(6, $freshJob->uploads()->count());
        $currentUploadIds = $freshJob->uploads()
            ->whereIn('field_code', ['before_photo', 'after_photo', 'warranty_document_photo'])
            ->where('created_at', '>=', $reopenedAt)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
        $previousUploadIds = $freshJob->uploads()
            ->whereIn('field_code', ['before_photo', 'after_photo', 'warranty_document_photo'])
            ->where('created_at', '<', $reopenedAt)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
        $this->assertCount(3, $currentUploadIds);
        $this->assertCount(3, $previousUploadIds);

        $opsDetail = $this->actingAs($admin)
            ->getJson("/api/technical-service/requests/{$job->id}")
            ->assertOk();
        $this->assertCount(3, $opsDetail->json('request.field_completion_documents'));
        $this->assertCount(3, $opsDetail->json('request.previous_field_completion_documents'));
        $this->assertSame(
            $currentUploadIds,
            collect($opsDetail->json('request.field_completion_documents'))->pluck('id')->sort()->values()->all()
        );
        $this->assertSame(
            $previousUploadIds,
            collect($opsDetail->json('request.previous_field_completion_documents'))->pluck('id')->sort()->values()->all()
        );
        $this->assertSame(
            ['after_photo', 'before_photo', 'warranty_document_photo'],
            collect($opsDetail->json('request.field_completion_documents'))->pluck('field_code')->sort()->values()->all()
        );
        $this->assertSame(
            ['after_photo', 'before_photo', 'warranty_document_photo'],
            collect($opsDetail->json('request.previous_field_completion_documents'))->pluck('field_code')->sort()->values()->all()
        );
    }

    public function test_partner_reupload_returns_latest_current_documents_without_duplicate_current_payload(): void
    {
        Storage::fake('public');
        $fixture = $this->locksmithPortalJobFixture('MRN-REUPLOAD-CURRENT-DOCS');
        $job = $fixture['job'];
        $portalUser = $fixture['portalUser'];

        $initial = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before.jpg', 256, 'image/jpeg'),
                'after_photo' => UploadedFile::fake()->create('after.jpg', 256, 'image/jpeg'),
                'warranty_document_photo' => UploadedFile::fake()->create('warranty.jpg', 256, 'image/jpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 3)
            ->assertJsonPath('job.completion_requirements.photos_ready', true);
        $oldBeforeId = $initial->json('job.current_field_documents.before_photo.id');
        $this->assertCount(3, $initial->json('job.photos'));

        $replacement = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before-new.jpg', 256, 'image/jpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 3)
            ->assertJsonPath('job.completion_requirements.photos_ready', true);

        $photos = collect($replacement->json('job.photos'));
        $newBeforeId = $replacement->json('job.current_field_documents.before_photo.id');
        $this->assertNotSame($oldBeforeId, $newBeforeId);
        $this->assertCount(3, $photos);
        $this->assertSame($newBeforeId, $photos->firstWhere('field_code', 'before_photo')['id'] ?? null);
        $this->assertNotContains($oldBeforeId, $photos->pluck('id')->all());
        $this->assertSame(
            collect($replacement->json('job.current_field_documents'))
                ->filter(fn ($document): bool => is_array($document))
                ->pluck('id')
                ->sort()
                ->values()
                ->all(),
            $photos->pluck('id')->sort()->values()->all()
        );
        $this->assertSame(4, $job->refresh()->uploads()
            ->whereIn('field_code', ['before_photo', 'after_photo', 'warranty_document_photo'])
            ->count());
    }

    public function test_current_field_documents_use_latest_id_when_created_at_matches(): void
    {
        $fixture = $this->locksmithPortalJobFixture('MRN-SAME-CREATED-LATEST-ID');
        $admin = $fixture['admin'];
        $job = $fixture['job'];
        $portalUser = $fixture['portalUser'];
        $sameCreatedAt = now()->setMicrosecond(0);

        $olderBeforeDocument = $this->createPortalFieldDocument($job, 'before_photo');
        $newerBeforeDocument = $this->createPortalFieldDocument($job, 'before_photo');
        foreach ([$olderBeforeDocument, $newerBeforeDocument] as $document) {
            $document->forceFill([
                'created_at' => $sameCreatedAt,
                'updated_at' => $sameCreatedAt,
            ])->save();
        }
        $this->createPortalFieldDocument($job, 'after_photo')
            ->forceFill(['created_at' => $sameCreatedAt, 'updated_at' => $sameCreatedAt])
            ->save();
        $this->createPortalFieldDocument($job, 'warranty_document_photo')
            ->forceFill(['created_at' => $sameCreatedAt, 'updated_at' => $sameCreatedAt])
            ->save();

        $portalResponse = $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.current_field_documents.before_photo.id', $newerBeforeDocument->id)
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 3)
            ->assertJsonPath('job.completion_requirements.photos_ready', true);
        $this->assertCount(3, $portalResponse->json('job.photos'));
        $this->assertNotContains($olderBeforeDocument->id, collect($portalResponse->json('job.photos'))->pluck('id')->all());

        $opsResponse = $this->actingAs($admin)
            ->getJson("/api/technical-service/requests/{$job->id}")
            ->assertOk();
        $opsDocuments = collect($opsResponse->json('request.field_completion_documents'));
        $this->assertCount(3, $opsDocuments);
        $this->assertSame($newerBeforeDocument->id, $opsDocuments->firstWhere('field_code', 'before_photo')['id'] ?? null);
    }

    public function test_reopened_boundary_documents_are_previous_not_current(): void
    {
        $reopenedAt = now()->setMicrosecond(0);
        $fixture = $this->locksmithPortalJobFixture('MRN-REOPENED-BOUNDARY-DOCS', [
            'reopened_at' => $reopenedAt,
            'reopen_reason' => 'Boundary regression',
        ]);
        $admin = $fixture['admin'];
        $job = $fixture['job'];
        $portalUser = $fixture['portalUser'];

        foreach (['before_photo', 'after_photo', 'warranty_document_photo'] as $fieldCode) {
            $this->createPortalFieldDocument($job, $fieldCode)
                ->forceFill([
                    'created_at' => $reopenedAt,
                    'updated_at' => $reopenedAt,
                ])
                ->save();
        }

        $portalResponse = $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.current_field_documents.before_photo', null)
            ->assertJsonPath('job.current_field_documents.after_photo', null)
            ->assertJsonPath('job.current_field_documents.warranty_document_photo', null)
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 0)
            ->assertJsonPath('job.completion_requirements.photos_ready', false);
        $this->assertCount(0, $portalResponse->json('job.photos'));
        $this->assertCount(3, $portalResponse->json('job.previous_photos'));

        $opsResponse = $this->actingAs($admin)
            ->getJson("/api/technical-service/requests/{$job->id}")
            ->assertOk()
            ->assertJsonPath('request.operational_state.is_field_docs_required', true);
        $this->assertCount(0, $opsResponse->json('request.field_completion_documents'));
        $this->assertCount(3, $opsResponse->json('request.previous_field_completion_documents'));
    }

    public function test_partner_photo_upload_requires_confirmed_appointment_schedule(): void
    {
        Storage::fake('public');
        $fixture = $this->locksmithPortalJobFixture('MRN-UPLOAD-NO-APPOINTMENT', [
            'scheduled_at' => null,
            'scheduled_date' => null,
            'scheduled_time' => null,
        ]);
        $job = $fixture['job'];
        $portalUser = $fixture['portalUser'];

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.can_upload_photos', false);

        $response = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before.jpg', 256, 'image/jpeg'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('workflow_status');

        $this->assertSame(
            'Fotoğraf yükleme sadece randevu onaylandıktan sonra yapılabilir.',
            $response->json('errors.workflow_status.0'),
        );
        $this->assertSame(0, $job->refresh()->uploads()
            ->whereIn('field_code', ['before_photo', 'after_photo', 'warranty_document_photo'])
            ->count());
    }

    public function test_failed_reupload_does_not_replace_current_documents_or_counts(): void
    {
        Storage::fake('public');
        $fixture = $this->locksmithPortalJobFixture('MRN-FAILED-REUPLOAD-STABLE');
        $job = $fixture['job'];
        $portalUser = $fixture['portalUser'];

        $initial = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before.jpg', 256, 'image/jpeg'),
                'after_photo' => UploadedFile::fake()->create('after.jpg', 256, 'image/jpeg'),
                'warranty_document_photo' => UploadedFile::fake()->create('warranty.jpg', 256, 'image/jpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 3)
            ->assertJsonPath('job.completion_requirements.photos_ready', true);
        $beforeId = $initial->json('job.current_field_documents.before_photo.id');
        $confirmation = $this->approveCustomerForJob($job, $fixture['partner']->id, 'failed-reupload-keeps-approval-token');

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.customer_confirmation_ready', true)
            ->assertJsonPath('job.can_submit_completion', true);

        $invalidTypeResponse = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before.txt', 1, 'text/plain'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('before_photo');
        $this->assertSame(
            'Fotoğraf JPG, PNG, WEBP, GIF, HEIC veya HEIF formatında olmalıdır.',
            $invalidTypeResponse->json('errors.before_photo.0'),
        );
        $this->assertStringNotContainsString('validation.', (string) json_encode($invalidTypeResponse->json(), JSON_UNESCAPED_UNICODE));

        $oversizedResponse = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before-large.jpg', 11264, 'image/jpeg'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('before_photo');
        $this->assertSame(
            'Öncesi fotoğrafı en fazla 10240 kilobayt olmalıdır.',
            $oversizedResponse->json('errors.before_photo.0'),
        );
        $this->assertStringNotContainsString('validation.', (string) json_encode($oversizedResponse->json(), JSON_UNESCAPED_UNICODE));

        $current = $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.current_field_documents.before_photo.id', $beforeId)
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 3)
            ->assertJsonPath('job.completion_requirements.photos_ready', true)
            ->assertJsonPath('job.completion_requirements.customer_confirmation_ready', true)
            ->assertJsonPath('job.can_submit_completion', true);

        $this->assertCount(3, $current->json('job.photos'));
        $this->assertDatabaseHas('technical_service_customer_confirmations', [
            'id' => $confirmation->id,
            'status' => TechnicalServiceCustomerConfirmation::STATUS_APPROVED,
        ]);
        $this->assertSame(3, $job->refresh()->uploads()
            ->whereIn('field_code', ['before_photo', 'after_photo', 'warranty_document_photo'])
            ->count());
    }

    public function test_partner_upload_validation_uses_turkish_max_file_message_for_before_photo(): void
    {
        Storage::fake('public');
        $fixture = $this->locksmithPortalJobFixture('MRN-UPLOAD-MAX-TR-BEFORE');
        $job = $fixture['job'];
        $portalUser = $fixture['portalUser'];

        $response = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before-large.jpg', 11264, 'image/jpeg'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('before_photo');

        $this->assertSame(
            'Öncesi fotoğrafı en fazla 10240 kilobayt olmalıdır.',
            $response->json('errors.before_photo.0'),
        );
    }

    public function test_partner_upload_validation_uses_turkish_attribute_names_for_all_required_fields(): void
    {
        Storage::fake('public');
        $fixture = $this->locksmithPortalJobFixture('MRN-UPLOAD-MAX-TR-ALL');
        $job = $fixture['job'];
        $portalUser = $fixture['portalUser'];

        $response = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before-large.jpg', 11264, 'image/jpeg'),
                'after_photo' => UploadedFile::fake()->create('after-large.jpg', 11264, 'image/jpeg'),
                'warranty_document_photo' => UploadedFile::fake()->create('warranty-large.jpg', 11264, 'image/jpeg'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['before_photo', 'after_photo', 'warranty_document_photo']);

        $this->assertSame(
            'Öncesi fotoğrafı en fazla 10240 kilobayt olmalıdır.',
            $response->json('errors.before_photo.0'),
        );
        $this->assertSame(
            'Sonrası fotoğrafı en fazla 10240 kilobayt olmalıdır.',
            $response->json('errors.after_photo.0'),
        );
        $this->assertSame(
            'Garanti belgesi fotoğrafı en fazla 10240 kilobayt olmalıdır.',
            $response->json('errors.warranty_document_photo.0'),
        );
    }

    public function test_partner_upload_validation_does_not_return_english_fallback_or_raw_key(): void
    {
        Storage::fake('public');
        $fixture = $this->locksmithPortalJobFixture('MRN-UPLOAD-MAX-NO-FALLBACK');
        $job = $fixture['job'];
        $portalUser = $fixture['portalUser'];

        $response = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before-large.jpg', 11264, 'image/jpeg'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('before_photo');

        $encoded = (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE);

        foreach ([
            'validation.max.file',
            'validation.mimes',
            'The before photo field',
            'before photo field',
            'kilobytes',
        ] as $badFragment) {
            $this->assertStringNotContainsString($badFragment, $encoded);
        }
    }

    public function test_reupload_after_customer_approval_resets_customer_confirmation_gate(): void
    {
        Storage::fake('public');
        $fixture = $this->locksmithPortalJobFixture('MRN-REUPLOAD-RESETS-CUSTOMER');
        $job = $fixture['job'];
        $portalUser = $fixture['portalUser'];

        $this->createPortalFieldDocument($job, 'before_photo');
        $this->createPortalFieldDocument($job, 'after_photo');
        $this->createPortalFieldDocument($job, 'warranty_document_photo');
        $confirmation = TechnicalServiceCustomerConfirmation::query()->create([
            'technical_service_request_id' => $job->id,
            'token' => 'reupload-resets-customer-token',
            'status' => TechnicalServiceCustomerConfirmation::STATUS_APPROVED,
            'approved_at' => now(),
            'payload' => ['partner_id' => $fixture['partner']->id],
        ]);
        $job->forceFill([
            'customer_closure_approval_status' => 'onaylandı',
            'customer_closure_approval_method' => 'customer_link',
            'customer_closure_approval_code' => 'approved-code',
            'customer_closure_approved_at' => now(),
        ])->save();

        $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.customer_confirmation_ready', true)
            ->assertJsonPath('job.can_submit_completion', true);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before-new.jpg', 256, 'image/jpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 3)
            ->assertJsonPath('job.completion_requirements.customer_confirmation_ready', false)
            ->assertJsonPath('job.can_submit_completion', false);

        $this->assertDatabaseHas('technical_service_customer_confirmations', [
            'id' => $confirmation->id,
            'status' => TechnicalServiceCustomerConfirmation::STATUS_CANCELLED,
        ]);
        $job->refresh();
        $this->assertNull($job->customer_closure_approval_status);
        $this->assertNull($job->customer_closure_approval_method);
        $this->assertNull($job->customer_closure_approval_code);
        $this->assertNull($job->customer_closure_approved_at);

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/submit-completion", [
                'result' => 'completed',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_confirmation');
    }

    public function test_reupload_after_ops_document_acceptance_requires_latest_document_review_again(): void
    {
        Storage::fake('public');
        $fixture = $this->locksmithPortalJobFixture('MRN-REUPLOAD-RESETS-OPS-REVIEW');
        $admin = $fixture['admin'];
        $job = $fixture['job'];
        $portalUser = $fixture['portalUser'];

        $beforeDocument = $this->createPortalFieldDocument($job, 'before_photo');
        $afterDocument = $this->createPortalFieldDocument($job, 'after_photo');
        $warrantyDocument = $this->createPortalFieldDocument($job, 'warranty_document_photo');
        $beforeDocument->forceFill(['review_status' => 'accepted'])->save();
        $afterDocument->forceFill(['review_status' => 'accepted'])->save();
        $warrantyDocument->forceFill(['review_status' => 'accepted'])->save();
        $this->approveCustomerForJob($job, $fixture['partner']->id, 'ops-review-before-reupload-token');

        $replacement = $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('before-new.jpg', 256, 'image/jpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 3)
            ->assertJsonPath('job.current_field_documents.before_photo.review_status', null);
        $newBeforeId = $replacement->json('job.current_field_documents.before_photo.id');

        $opsDetail = $this->actingAs($admin)
            ->getJson("/api/technical-service/requests/{$job->id}")
            ->assertOk();
        $currentOpsDocuments = collect($opsDetail->json('request.field_completion_documents'));
        $this->assertCount(3, $currentOpsDocuments);
        $this->assertSame($newBeforeId, $currentOpsDocuments->firstWhere('field_code', 'before_photo')['id'] ?? null);
        $this->assertNull($currentOpsDocuments->firstWhere('field_code', 'before_photo')['review_status'] ?? null);

        $this->approveCustomerForJob($job->refresh(), $fixture['partner']->id, 'ops-review-after-reupload-token');
        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/submit-completion", [
                'result' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('job.kanban_column', 'final_check');
        $completionAction = TechnicalServicePartnerJobAction::query()
            ->where('technical_service_request_id', $job->id)
            ->where('action', TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED)
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/partner-completions/{$completionAction->id}/approve", [
                'note' => 'Yeni belge incelenmeden son kontrol kapanmamalı.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('completion');

        TechnicalServiceRequestUpload::query()->findOrFail($newBeforeId)
            ->forceFill(['review_status' => 'accepted'])
            ->save();

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/partner-completions/{$completionAction->id}/approve", [
                'note' => 'Yeni belge uygun, son kontrol tamamlandı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.kanban_column', 'completed');
    }

    public function test_reupload_is_blocked_after_completion_submit_and_completed_job(): void
    {
        Storage::fake('public');
        $fixture = $this->locksmithPortalJobFixture('MRN-REUPLOAD-BLOCKED-FINAL-COMPLETED');
        $job = $fixture['job'];
        $portalUser = $fixture['portalUser'];

        $this->createPortalFieldDocument($job, 'before_photo')->forceFill(['review_status' => 'accepted'])->save();
        $this->createPortalFieldDocument($job, 'after_photo')->forceFill(['review_status' => 'accepted'])->save();
        $this->createPortalFieldDocument($job, 'warranty_document_photo')->forceFill(['review_status' => 'accepted'])->save();
        $this->approveCustomerForJob($job, $fixture['partner']->id, 'reupload-blocked-final-token');

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/submit-completion", [
                'result' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('job.kanban_column', 'final_check');

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('blocked-final.jpg', 256, 'image/jpeg'),
            ])
            ->assertUnprocessable();
        $this->assertSame(3, $job->refresh()->uploads()
            ->whereIn('field_code', ['before_photo', 'after_photo', 'warranty_document_photo'])
            ->count());

        $job->forceFill([
            'status' => 'Tamamlandı',
            'workflow_status' => 'Tamamlandı',
            'completed_at' => now(),
        ])->save();

        $this->actingAs($portalUser)
            ->postJson("/api/partner/service-jobs/{$job->id}/photos", [
                'before_photo' => UploadedFile::fake()->create('blocked-completed.jpg', 256, 'image/jpeg'),
            ])
            ->assertUnprocessable();
        $this->assertSame(3, $job->refresh()->uploads()
            ->whereIn('field_code', ['before_photo', 'after_photo', 'warranty_document_photo'])
            ->count());
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
            ->assertJsonPath('job.action_state', 'final_check_waiting')
            ->assertJsonPath('job.can_submit_completion', false)
            ->assertJsonPath('job.next_action', 'Son kontrol bekliyor');

        $finalCheckResponse = $this->actingAs($portalUser)
            ->getJson("/api/partner/service-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('job.kanban_column', 'final_check')
            ->assertJsonPath('job.action_state', 'final_check_waiting')
            ->assertJsonPath('job.can_submit_completion', false)
            ->assertJsonPath('job.next_action', 'Son kontrol bekliyor');
        $this->assertNotContains('Tamamlamaya gönderilebilir', $finalCheckResponse->json('job.badges'));

        $job->refresh();
        $this->assertSame('Son Kontrol', $job->status);
        $this->assertSame('Son Kontrol', $job->workflow_status);
        $this->assertSame('son_kontrol', $job->field_status);
        $this->assertNull($job->completed_at);
        $this->assertNull($job->technician_completed_at);
        $state = app(TechnicalServiceOperationalStatePresenter::class)->present($job->fresh());
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
        $this->assertStringContainsString('Otomatik kopyalanamadı;', $source);
    }

    public function test_ops_customer_approval_inline_is_compact(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));

        $this->assertIsString($source);
        $compactSource = preg_replace('/\s+/', ' ', $source) ?? $source;
        $this->assertStringContainsString('setCustomerApprovalModalOpen(true)', $source);
        $this->assertStringContainsString('{customerApprovalModalOpen && latestCustomerApprovalUrl ? (', $compactSource);
        $this->assertStringContainsString('{customerApprovalModalOpen && latestCustomerApprovalMessageText ? (', $compactSource);
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
            ->assertJsonPath('dispatch.dispatch_status', TechnicalServiceMessageDispatch::STATUS_SUPPRESSED)
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
            ->assertJsonPath('dispatch.dispatch_status', TechnicalServiceMessageDispatch::STATUS_SUPPRESSED)
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
        Http::assertNothingSent();
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

    public function test_appointment_proposed_ops_creates_whatsapp_without_ops_sms(): void
    {
        Http::fake();
        $scope = $this->partnerPortalScopeFixture();
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'messaging_enabled' => true,
            'test_mode_enabled' => false,
            'manual_e2e_enabled' => true,
            'manual_e2e_allowlisted_phones' => ['905372081633', '905467647428'],
            'ops_whatsapp_enabled' => true,
            'ops_whatsapp_phone' => '0546 764 74 28',
            'message_types' => [
                'appointment_proposed_ops' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
            ],
        ]);
        $scope['jobA']->forceFill([
            'mrn' => 'MRN-REL4E12-PROPOSE',
            'customer_name' => 'REL4E12 Müşteri',
            'customer_phone' => '05372081633',
        ])->save();
        $proposalDate = now()->addDay()->toDateString();

        $this->actingAs($scope['userA'])
            ->postJson("/api/partner/service-jobs/{$scope['jobA']->id}/appointment-proposal", [
                'slots' => [
                    ['date' => $proposalDate, 'slot' => '15:00-16:00'],
                ],
                'note' => '15:00 sonrası uygunum.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW);

        $dispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $scope['jobA']->id)
            ->where('message_type', 'appointment_proposed_ops')
            ->firstOrFail();

        $body = (string) ($dispatch->request_payload['body'] ?? '');
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_QUEUED, $dispatch->status);
        $this->assertSame('whatsapp', $dispatch->channel);
        $this->assertSame('evo_whatsapp', $dispatch->provider_key);
        $this->assertSame('ops', $dispatch->recipient_role);
        $this->assertSame('905467647428', $dispatch->target_phone);
        $this->assertTrue((bool) data_get($dispatch->metadata, 'manual_e2e'));
        $this->assertTrue((bool) data_get($dispatch->metadata, 'allowlisted_target'));
        $this->assertSame('MANUAL-E2E-LIVE-TEST', data_get($dispatch->metadata, 'manual_e2e_run_id'));
        $this->assertStringContainsString('Usta randevu önerdi', $body);
        $this->assertStringContainsString('MRN-REL4E12-PROPOSE', $body);
        $this->assertStringContainsString('REL4E12 Müşteri', $body);
        $this->assertStringContainsString(now()->addDay()->format('d.m.Y'), $body);
        $this->assertStringContainsString('15:00 - 16:00', $body);
        $this->assertStringContainsString('15:00 sonrası uygunum.', $body);
        $this->assertStringContainsString('Randevuyu onaylayın veya değişiklik isteyin.', $body);
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $scope['jobA']->id,
            'message_type' => 'appointment_proposed_ops',
            'channel' => 'sms',
        ]);
        Http::assertNothingSent();
    }

    public function test_ops_card_label_says_technician_proposed_appointment_and_approve_needed(): void
    {
        $scope = $this->partnerPortalScopeFixture();

        $this->actingAs($scope['userA'])
            ->postJson("/api/partner/service-jobs/{$scope['jobA']->id}/appointment-proposal", [
                'slots' => [['date' => now()->addDay()->toDateString(), 'slot' => '10:00-11:00']],
            ])
            ->assertOk();

        $opsState = app(TechnicalServiceOperationalStatePresenter::class)
            ->present($scope['jobA']->refresh());
        $labels = collect($opsState['display_tags'])->pluck('label')->all();

        $this->assertSame('Usta randevu önerdi', $opsState['display_action_label']);
        $this->assertSame(6, $opsState['sort_priority']);
        $this->assertSame(['OPS aksiyonu: Usta randevu önerdi', 'Randevuyu onaylayın'], $labels);
        $this->assertNotContains('Randevu önerisi bekliyor', $labels);
    }

    public function test_ops_can_approve_revision_card_slot_payload_without_start_time(): void
    {
        $scope = $this->partnerPortalScopeFixture();
        $scope['jobA']->forceFill([
            'workflow_status' => 'Usta Onayı Bekleyen',
            'status' => 'Atandı',
        ])->save();
        $admin = $this->userWithRole('admin', true);
        $proposalDate = now()->addDay()->toDateString();
        $action = TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $scope['jobA']->id,
            'partner_id' => $scope['partnerA']->id,
            'user_id' => $scope['userA']->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED,
            'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'payload' => [
                'slots' => [
                    ['date' => $proposalDate, 'slot' => '15:00-16:00'],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$scope['jobA']->id}/partner-appointment-proposals/{$action->id}/approve", [
                'note' => 'Revize randevu onaylandı.',
                'selected_slot_index' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServicePartnerJobAction::STATUS_APPLIED)
            ->assertJsonPath('request.workflow_status', 'Planlı')
            ->assertJsonPath('request.scheduled_time', '15:00');

        $this->assertSame($proposalDate, $scope['jobA']->fresh()->scheduled_date?->toDateString());
        $this->assertSame('15:00', $scope['jobA']->fresh()->scheduled_time);
        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'id' => $action->id,
            'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
        ]);
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

        $board = app(B2BPartnerPortalDataService::class)->serviceJobBoardFor($partner);
        $newJobs = collect($board['columns'])->firstWhere('key', 'new_jobs')['jobs'];

        $this->assertSame('MRN-SORT-PROPOSED', $newJobs[0]['mrn']);
        $this->assertSame(6, $newJobs[0]['card_priority']);
        $this->assertSame('MRN-SORT-NORMAL', $newJobs[1]['mrn']);
        $this->assertSame(12, $newJobs[1]['card_priority']);
        $this->assertNotSame($normalJob->id, $proposedJob->id);
    }

    public function test_polling_refresh_updates_appointment_approved_schedule_approved_state(): void
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

    public function test_appointment_message_context_is_prepared_when_ops_approves_partner_proposal(): void
    {
        Http::fake();
        (new B2BPartnerPermissionSeeder)->run();
        $admin = $this->userWithRole('admin', true);
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'messaging_enabled' => true,
            'test_mode_enabled' => true,
            'shared_test_phone' => '0546 764 74 28',
            'message_types' => [
                'appointment_approved_customer' => [
                    'enabled' => true,
                    'channel_policy' => 'whatsapp_only',
                    'whatsapp_provider' => 'evo_whatsapp',
                ],
                'appointment_approved_technician' => [
                    'enabled' => true,
                    'channel_policy' => 'whatsapp_only',
                    'whatsapp_provider' => 'evo_whatsapp',
                ],
            ],
        ]);
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
        $opsState = app(TechnicalServiceOperationalStatePresenter::class)->present($job->refresh());
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
        TechnicalServiceSettlement::query()->create([
            'technical_service_request_id' => $job->id,
            'root_request_id' => $job->parent_request_id ?: $job->id,
            'request_code' => $job->service_code,
            'root_mrn' => $job->root_mrn ?: $job->mrn,
            'technical_service_technician_id' => $technician->id,
            'currency' => 'TRY',
            'labor_earning_amount' => 600,
            'route_earning_amount' => 0,
            'technician_earning_total' => 600,
            'customer_collection_amount' => 0,
            'customer_direct_to_technician_amount' => 600,
            'customer_direct_assumed_paid_amount' => 0,
            'company_payable_amount' => 0,
            'company_paid_amount' => 0,
            'company_remaining_amount' => 0,
            'status' => TechnicalServiceSettlement::STATUS_FINALIZED,
            'settlement_source' => 'test',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$job->id}/partner-appointment-proposals/{$action->id}/approve", [
                'note' => 'Operasyon onayladı.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('request.workflow_status', 'Planlı')
            ->assertJsonPath('request.technician_approval_status', 'onayladı')
            ->assertJsonPath('message_payloads.event_type', 'appointment_approved')
            ->assertJsonPath('message_payloads.queued', 2)
            ->assertJsonPath('message_payloads.blocked', 0)
            ->assertJsonPath('message_payloads.dispatches.0.message_type', 'appointment_approved_customer')
            ->assertJsonPath('message_payloads.dispatches.0.status', TechnicalServiceMessageDispatch::STATUS_QUEUED)
            ->assertJsonPath('message_payloads.dispatches.0.test_redirect_applied', true)
            ->assertJsonPath('message_payloads.dispatches.1.message_type', 'appointment_approved_technician')
            ->assertJsonPath('message_payloads.dispatches.1.status', TechnicalServiceMessageDispatch::STATUS_QUEUED);

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
            'message_type' => 'appointment_approved_customer',
            'target_type' => 'customer',
            'test_mode' => true,
            'test_redirect_applied' => true,
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
        ]);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $job->id,
            'message_type' => 'appointment_approved_technician',
            'target_type' => 'technician',
            'test_mode' => true,
            'test_redirect_applied' => true,
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
        ]);
        $customerDispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $job->id)
            ->where('message_type', 'appointment_approved_customer')
            ->firstOrFail();
        $technicianDispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $job->id)
            ->where('message_type', 'appointment_approved_technician')
            ->firstOrFail();

        $this->assertSame('905467647428', $customerDispatch->target_phone);
        $this->assertNotEmpty($customerDispatch->original_phone);
        $this->assertSame('9054***428', $customerDispatch->effective_target_phone_mask);
        $this->assertStringContainsString('09:00 - 13:00 arası', (string) ($customerDispatch->request_payload['body'] ?? ''));
        $this->assertStringContainsString('Randevu sırasında ustaya ödenecek tutar: 600,00 TL.', (string) ($customerDispatch->request_payload['body'] ?? ''));
        $this->assertStringContainsString('10:00 - 11:00', (string) ($technicianDispatch->request_payload['body'] ?? ''));
        $this->assertStringContainsString('İş Kartı', (string) ($technicianDispatch->request_payload['body'] ?? ''));
        Http::assertNothingSent();
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

    public function test_technical_service_assignment_creates_assignment_offer_job_card_link_for_portal(): void
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
            'product_model' => 'Offer Model',
            'brand' => 'Emaks Prime',
            'stock_code' => 'STK-OFFER',
            'serial_number' => 'SN-OFFER-ACT-001',
            'activation_code' => 'ACT-OFFER',
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
        $this->assertStringContainsString('Seri: SN-OFFER-ACT-001', (string) ($offer->metadata['message_payload']['message_text'] ?? ''));
        $this->assertStringContainsString('Aktivasyon: ACT-OFFER', (string) ($offer->metadata['message_payload']['message_text'] ?? ''));
        $this->assertStringNotContainsString('STK-OFFER', (string) ($offer->metadata['message_payload']['message_text'] ?? ''));
        $this->assertStringNotContainsString('TRY', (string) ($offer->metadata['message_payload']['message_text'] ?? ''));
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $job->id,
            'event' => 'assignment_offer_technician',
            'target_type' => 'technician',
            'target_phone' => '905467647428',
            'test_mode' => true,
            'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED,
            'provider_key' => 'null_local',
            'channel' => 'system',
        ]);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $job->id,
            'event' => 'assignment_offer_technician',
            'metadata->assignment_offer_id' => $offer->id,
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $job->id,
            'event_type' => 'assignment_offer_sent',
            'title' => 'Hakediş bilgisi hazırlandı',
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $job->id,
            'event_type' => 'assignment_created',
            'title' => 'Usta atandı',
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
            ->assertJsonPath('request.assignment_offer.dispatch_status', TechnicalServiceMessageDispatch::STATUS_SUPPRESSED)
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
            ->assertJsonPath('job.earning_breakdown.root_total.total_amount', 3000)
            ->assertJsonPath('job.product_name', 'Offer Kilit')
            ->assertJsonPath('job.product_model', 'Offer Model')
            ->assertJsonPath('job.brand', 'Emaks Prime')
            ->assertJsonPath('job.stock_code', 'STK-OFFER')
            ->assertJsonPath('job.serial_no', 'SN-OFFER-ACT-001')
            ->assertJsonPath('job.activation_code', 'ACT-OFFER')
            ->assertJsonPath('job.serial_context.serial_number', 'SN-OFFER-ACT-001')
            ->assertJsonPath('job.serial_context.activation_code', 'ACT-OFFER');

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

    public function test_completed_reopen_creates_unassigned_srv_hidden_from_old_partner_until_assignment(): void
    {
        $scope = $this->partnerPortalScopeFixture();
        $admin = $this->userWithRole('admin', true);
        $parent = $scope['completedJobB'];
        $reason = 'Operasyon d'.hex2bin('c3bc').'zeltmesi';

        $response = $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$parent->id}/status", [
                'status' => 'Yeni',
                'reopen_reason' => $reason,
                'reopen_note' => 'Yeni SRV ile takip edilecek.',
            ])
            ->assertOk()
            ->assertJsonPath('reopened_as_service_visit', true)
            ->assertJsonPath('request.parent_request_id', $parent->id)
            ->assertJsonPath('request.technical_service_technician_id', null)
            ->assertJsonPath('request.scheduled_at', null);

        $child = TechnicalServiceRequest::query()->findOrFail($response->json('request.id'));
        $this->assertSame('Servis', $child->service_type);
        $this->assertSame('service_request', $child->service_visit_reason);
        $this->assertNull($child->technical_service_technician_id);

        $opsList = $this->actingAs($admin)
            ->getJson('/api/technical-service/requests?limit=200')
            ->assertOk();
        $opsMrns = collect($opsList->json('items'))->pluck('mrn')->all();
        $this->assertNotContains($parent->mrn, $opsMrns);
        $this->assertContains($child->mrn, $opsMrns);

        $this->actingAs($scope['userB'])
            ->getJson("/api/partner/service-jobs/{$child->id}?partner_id={$scope['partnerB']->id}")
            ->assertForbidden();
        $oldPartnerJobs = $this->actingAs($scope['userB'])
            ->getJson("/api/partner/service-jobs?partner_id={$scope['partnerB']->id}")
            ->assertOk();
        $this->assertNotContains($child->mrn, collect($oldPartnerJobs->json('jobs'))->pluck('mrn')->all());

        $child->forceFill([
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ])->save();

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$child->id}/technician", [
                'technical_service_technician_id' => $scope['technicianB']->id,
                'labor_amount' => 1500,
                'travel_amount' => 250,
                'confirm_assignment' => true,
                'note' => 'Yeni SRV ataması.',
            ])
            ->assertOk();

        $this->actingAs($scope['userB'])
            ->getJson("/api/partner/service-jobs/{$child->id}?partner_id={$scope['partnerB']->id}")
            ->assertOk()
            ->assertJsonPath('job.service_visit_context.root_mrn', $parent->mrn);
    }

    public function test_original_locksmith_sees_completed_parent_mrn_after_child_srv_assigned_elsewhere(): void
    {
        $scope = $this->partnerPortalScopeFixture();
        $admin = $this->userWithRole('admin', true);
        $parent = $scope['completedJobB'];

        $response = $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$parent->id}/status", [
                'status' => 'Yeni',
                'reopen_reason' => 'Operasyon d'.hex2bin('c3bc').'zeltmesi',
                'reopen_note' => 'Başka ustaya servis açılacak.',
            ])
            ->assertOk()
            ->assertJsonPath('reopened_as_service_visit', true);

        $child = TechnicalServiceRequest::query()->findOrFail($response->json('request.id'));
        $child->forceFill([
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ])->save();

        $this->actingAs($admin)
            ->patchJson("/api/technical-service/requests/{$child->id}/technician", [
                'technical_service_technician_id' => $scope['technicianA']->id,
                'labor_amount' => 1500,
                'travel_amount' => 250,
                'confirm_assignment' => true,
                'note' => 'Yeni SRV başka ustaya atandı.',
            ])
            ->assertOk();

        $oldPartnerJobs = $this->actingAs($scope['userB'])
            ->getJson("/api/partner/service-jobs?partner_id={$scope['partnerB']->id}")
            ->assertOk()
            ->json('jobs');
        $oldPartnerMrns = collect($oldPartnerJobs)->pluck('mrn')->all();

        $this->assertContains($parent->mrn, $oldPartnerMrns);
        $this->assertNotContains($child->mrn, $oldPartnerMrns);
        $oldPartnerCompletedJob = collect($oldPartnerJobs)->firstWhere('mrn', $parent->mrn);
        $this->assertSame('completed', $oldPartnerCompletedJob['kanban_column'] ?? null);
        $this->assertSame('completed_parent', $oldPartnerCompletedJob['view_context'] ?? null);
        $this->assertTrue((bool) ($oldPartnerCompletedJob['is_completed_history_view'] ?? false));
        $this->assertFalse((bool) ($oldPartnerCompletedJob['is_current_active_assignment'] ?? true));
        $this->assertFalse((bool) ($oldPartnerCompletedJob['should_show_completion_requirements'] ?? true));
        $this->assertFalse((bool) ($oldPartnerCompletedJob['should_show_current_actions'] ?? true));
        $this->assertFalse((bool) ($oldPartnerCompletedJob['can_upload_photos'] ?? true));
        $this->assertFalse((bool) ($oldPartnerCompletedJob['can_submit_completion'] ?? true));
        $this->assertSame(0, $oldPartnerCompletedJob['completion_requirements']['door_photos_required'] ?? null);
        $this->assertSame(0, $oldPartnerCompletedJob['completion_requirements']['door_photos_uploaded'] ?? null);
        $this->assertTrue((bool) ($oldPartnerCompletedJob['completion_requirements']['photos_ready'] ?? false));
        $this->assertSame([], $oldPartnerCompletedJob['completion_requirements']['missing_photo_labels'] ?? null);
        $this->assertSame([
            'before_photo' => null,
            'after_photo' => null,
            'warranty_document_photo' => null,
        ], $oldPartnerCompletedJob['current_field_documents'] ?? null);
        $this->assertEquals(1100.0, $oldPartnerCompletedJob['earning_summary']['total_amount'] ?? null);
        $this->assertSame([$parent->mrn], $oldPartnerCompletedJob['earning_summary']['related_mrns'] ?? null);
        $this->assertSame('Scope Usta B', $oldPartnerCompletedJob['earning_breakdown']['current_visit']['technician_name'] ?? null);
        $this->assertSame('Scope Usta B', $oldPartnerCompletedJob['earning_breakdown']['rows'][0]['technician_name'] ?? null);

        $oldPartnerEarnings = $this->actingAs($scope['userB'])
            ->getJson("/api/partner/earnings?partner_id={$scope['partnerB']->id}")
            ->assertOk()
            ->json('items.0.earnings.completed.rows');
        $oldPartnerEarningMrns = collect($oldPartnerEarnings)
            ->flatMap(fn (array $row): array => collect($row['items'] ?? [])->pluck('mrn')->all())
            ->all();

        $this->assertContains($parent->mrn, $oldPartnerEarningMrns);
        $this->assertNotContains($child->mrn, $oldPartnerEarningMrns);
        $oldPartnerParentEarningRow = collect($oldPartnerEarnings)
            ->first(fn (array $row): bool => collect($row['items'] ?? [])->pluck('mrn')->contains($parent->mrn));
        $this->assertNotNull($oldPartnerParentEarningRow);
        $this->assertSame('Gönderildi', $oldPartnerParentEarningRow['status'] ?? null);
        $this->assertSame('Hakediş ödeme kaydı yok', $oldPartnerParentEarningRow['payment_record_status_label'] ?? null);
        $this->assertNull($oldPartnerParentEarningRow['paid_at'] ?? null);
        $oldPartnerParentEarningItem = collect($oldPartnerParentEarningRow['items'] ?? [])
            ->firstWhere('mrn', $parent->mrn);
        $this->assertNotNull($oldPartnerParentEarningItem);
        $this->assertEquals(1100.0, $oldPartnerParentEarningItem['line_total'] ?? null);
        $this->assertSame('Scope Usta B', $oldPartnerParentEarningItem['technician_name'] ?? null);
        $this->assertSame('Gönderildi', $oldPartnerParentEarningItem['status'] ?? null);
        $this->assertSame('Hakediş ödeme kaydı yok', $oldPartnerParentEarningItem['payment_record_status_label'] ?? null);

        $newPartnerJobs = $this->actingAs($scope['userA'])
            ->getJson("/api/partner/service-jobs?partner_id={$scope['partnerA']->id}")
            ->assertOk()
            ->json('jobs');
        $newPartnerMrns = collect($newPartnerJobs)->pluck('mrn')->all();

        $this->assertContains($child->mrn, $newPartnerMrns);
        $this->assertNotContains($parent->mrn, $newPartnerMrns);
        $newPartnerChildJob = collect($newPartnerJobs)->firstWhere('mrn', $child->mrn);
        $this->assertSame('Scope Usta A', $newPartnerChildJob['earning_breakdown']['current_visit']['technician_name'] ?? null);
    }

    public function test_completed_parent_for_original_technician_hides_current_photo_requirements(): void
    {
        $scenario = $this->completedParentChildSrvPortalScenario();
        $upload = $this->createPortalFieldDocument($scenario['parent'], 'before_photo');
        $upload->forceFill([
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ])->save();
        $scenario['parent']->forceFill([
            'reopened_at' => now(),
            'workflow_status' => 'Tamamlandı',
            'status' => 'Tamamlandı',
        ])->save();

        $oldPartnerCompletedJob = collect($this->actingAs($scenario['userB'])
            ->getJson("/api/partner/service-jobs?partner_id={$scenario['partnerB']->id}")
            ->assertOk()
            ->json('jobs'))
            ->firstWhere('mrn', $scenario['parent']->mrn);

        $this->assertNotNull($oldPartnerCompletedJob);
        $this->assertSame('completed_parent', $oldPartnerCompletedJob['view_context'] ?? null);
        $this->assertTrue((bool) ($oldPartnerCompletedJob['is_completed_history_view'] ?? false));
        $this->assertFalse((bool) ($oldPartnerCompletedJob['should_show_completion_requirements'] ?? true));
        $this->assertSame(0, $oldPartnerCompletedJob['completion_requirements']['door_photos_required'] ?? null);
        $this->assertSame([], $oldPartnerCompletedJob['completion_requirements']['missing_photo_labels'] ?? null);
        $this->assertSame([
            'before_photo' => null,
            'after_photo' => null,
            'warranty_document_photo' => null,
        ], $oldPartnerCompletedJob['current_field_documents'] ?? null);
        $this->assertCount(0, $oldPartnerCompletedJob['photos'] ?? []);
        $this->assertCount(1, $oldPartnerCompletedJob['previous_photos'] ?? []);
        $this->assertSame('before_photo', $oldPartnerCompletedJob['previous_photos'][0]['field_code'] ?? null);
    }

    public function test_original_technician_completed_card_does_not_show_child_srv_completion_gate(): void
    {
        $scenario = $this->completedParentChildSrvPortalScenario();

        $response = $this->actingAs($scenario['userB'])
            ->getJson("/api/partner/service-jobs/{$scenario['parent']->id}?partner_id={$scenario['partnerB']->id}")
            ->assertOk();

        $response
            ->assertJsonPath('job.view_context', 'completed_parent')
            ->assertJsonPath('job.should_show_completion_requirements', false)
            ->assertJsonPath('job.should_show_current_actions', false)
            ->assertJsonPath('job.can_upload_photos', false)
            ->assertJsonPath('job.can_submit_completion', false)
            ->assertJsonPath('job.completion_requirements.door_photos_uploaded', 0)
            ->assertJsonPath('job.completion_requirements.photos_ready', true)
            ->assertJsonPath('job.completion_requirements.customer_confirmation_ready', true);
    }

    public function test_original_technician_does_not_see_child_srv_active_job_when_child_assigned_elsewhere(): void
    {
        $scenario = $this->completedParentChildSrvPortalScenario();

        $oldPartnerJobs = $this->actingAs($scenario['userB'])
            ->getJson("/api/partner/service-jobs?partner_id={$scenario['partnerB']->id}")
            ->assertOk()
            ->json('jobs');
        $oldPartnerMrns = collect($oldPartnerJobs)->pluck('mrn')->all();

        $this->assertContains($scenario['parent']->mrn, $oldPartnerMrns);
        $this->assertNotContains($scenario['child']->mrn, $oldPartnerMrns);

        $newPartnerJobs = $this->actingAs($scenario['userA'])
            ->getJson("/api/partner/service-jobs?partner_id={$scenario['partnerA']->id}")
            ->assertOk()
            ->json('jobs');

        $this->assertContains($scenario['child']->mrn, collect($newPartnerJobs)->pluck('mrn')->all());
    }

    public function test_child_srv_assigned_to_same_technician_shows_current_photo_requirements(): void
    {
        $scenario = $this->completedParentChildSrvPortalScenario(childAssignedToOriginal: true);

        $oldPartnerJobs = $this->actingAs($scenario['userB'])
            ->getJson("/api/partner/service-jobs?partner_id={$scenario['partnerB']->id}")
            ->assertOk()
            ->json('jobs');
        $childJob = collect($oldPartnerJobs)->firstWhere('mrn', $scenario['child']->mrn);
        $parentJob = collect($oldPartnerJobs)->firstWhere('mrn', $scenario['parent']->mrn);

        $this->assertNotNull($childJob);
        $this->assertSame('child_active', $childJob['view_context'] ?? null);
        $this->assertTrue((bool) ($childJob['is_current_active_assignment'] ?? false));
        $this->assertTrue((bool) ($childJob['should_show_completion_requirements'] ?? false));
        $this->assertSame(3, $childJob['completion_requirements']['door_photos_required'] ?? null);
        $this->assertSame(0, $childJob['completion_requirements']['door_photos_uploaded'] ?? null);
        $this->assertFalse((bool) ($childJob['completion_requirements']['photos_ready'] ?? true));
        $this->assertTrue((bool) ($childJob['can_upload_photos'] ?? false));

        $this->assertNotNull($parentJob);
        $this->assertSame('completed_parent', $parentJob['view_context'] ?? null);
        $this->assertFalse((bool) ($parentJob['should_show_completion_requirements'] ?? true));
    }

    public function test_completed_history_view_hides_current_actions(): void
    {
        $scenario = $this->completedParentChildSrvPortalScenario();

        $this->actingAs($scenario['userB'])
            ->getJson("/api/partner/service-jobs/{$scenario['parent']->id}?partner_id={$scenario['partnerB']->id}")
            ->assertOk()
            ->assertJsonPath('job.view_context', 'completed_parent')
            ->assertJsonPath('job.action_state', 'completed')
            ->assertJsonPath('job.should_show_current_actions', false)
            ->assertJsonPath('job.can_accept', false)
            ->assertJsonPath('job.can_propose_appointment', false)
            ->assertJsonPath('job.can_request_customer_otp', false)
            ->assertJsonPath('job.can_reject', false);
    }

    public function test_partner_completed_payload_has_no_customer_profit_margin_leak(): void
    {
        $scenario = $this->completedParentChildSrvPortalScenario();

        $payload = $this->actingAs($scenario['userB'])
            ->getJson("/api/partner/service-jobs/{$scenario['parent']->id}?partner_id={$scenario['partnerB']->id}")
            ->assertOk()
            ->json('job');
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

        foreach (['customer_collection', 'customer_payment_amount', 'profit', 'margin', 'net_operation_difference'] as $forbiddenFragment) {
            $this->assertStringNotContainsString($forbiddenFragment, (string) $encoded);
        }
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

    public function test_partner_serial_display_hides_stock_code_from_user_facing_label(): void
    {
        $source = file_get_contents(resource_path('js/pages/partner/portal-shell.tsx'));

        $this->assertStringContainsString('Aktivasyon:', $source);
        $this->assertStringContainsString('Seri:', $source);
        $this->assertStringNotContainsString('Stok:', $source);
    }

    public function test_partner_completed_history_ui_uses_previous_photos_read_only(): void
    {
        $source = file_get_contents(resource_path('js/pages/partner/portal-shell.tsx'));

        $this->assertStringContainsString('readOnlyHistoryPhotos', $source);
        $this->assertStringContainsString('...(job.previous_photos ?? [])', $source);
        $this->assertStringContainsString('job.is_completed_history_view && readOnlyHistoryPhotos.length > 0', $source);
        $this->assertStringContainsString('Aktif SRV eksik fotoğraf şartları bu karta yansımaz.', $source);
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
     * @return array{
     *     partnerA: B2BPartner,
     *     partnerB: B2BPartner,
     *     technicianA: TechnicalServiceTechnician,
     *     technicianB: TechnicalServiceTechnician,
     *     parent: TechnicalServiceRequest,
     *     child: TechnicalServiceRequest,
     *     userA: User,
     *     userB: User
     * }
     */
    private function completedParentChildSrvPortalScenario(bool $childAssignedToOriginal = false): array
    {
        $scope = $this->partnerPortalScopeFixture();
        $parent = $scope['completedJobB'];
        $childTechnician = $childAssignedToOriginal ? $scope['technicianB'] : $scope['technicianA'];
        $child = $this->serviceRequestForTechnician($childTechnician, 'SRV-SCOPE-COMPLETED-PARENT-001', [
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-SCOPE-COMPLETED-PARENT-001',
            'service_visit_reason' => 'part_service',
            'service_type' => 'Montaj',
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'technician_approval_status' => 'onaylandi',
            'technician_approved_at' => now(),
            'scheduled_at' => now()->addDay(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '10:00',
        ]);
        $this->partnerPortalAssignmentOffer($child, $childTechnician);

        return [
            'partnerA' => $scope['partnerA'],
            'partnerB' => $scope['partnerB'],
            'technicianA' => $scope['technicianA'],
            'technicianB' => $scope['technicianB'],
            'parent' => $parent,
            'child' => $child,
            'userA' => $scope['userA'],
            'userB' => $scope['userB'],
        ];
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
     * @param  array<string, mixed>  $jobAttributes
     * @return array{admin: User, partner: B2BPartner, technician: TechnicalServiceTechnician, job: TechnicalServiceRequest, portalUser: User}
     */
    private function locksmithPortalJobFixture(string $mrn, array $jobAttributes = []): array
    {
        (new B2BPartnerPermissionSeeder)->run();

        $admin = $this->userWithRole('admin', true);
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            'display_name' => $mrn.' Locksmith',
        ]);
        $technician = $this->technician(['name' => $mrn.' Usta']);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'active' => true,
        ]);
        $job = $this->serviceRequestForTechnician($technician, $mrn, array_merge([
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
            'field_status' => 'planlı',
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => now(),
            'scheduled_at' => now()->addDay(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '14:00',
        ], $jobAttributes));

        $this->actingAs($admin)
            ->postJson("/api/b2b/partners/{$partner->id}/provision-admin-user")
            ->assertCreated();
        $portalUser = User::query()
            ->where('role_code', 'b2b_locksmith')
            ->whereHas('b2bPartnerProfiles', fn ($query) => $query->where('partner_id', $partner->id))
            ->firstOrFail();

        return [
            'admin' => $admin,
            'partner' => $partner,
            'technician' => $technician,
            'job' => $job,
            'portalUser' => $portalUser,
        ];
    }

    private function approveCustomerForJob(TechnicalServiceRequest $job, int $partnerId, string $token): TechnicalServiceCustomerConfirmation
    {
        $confirmation = TechnicalServiceCustomerConfirmation::query()->create([
            'technical_service_request_id' => $job->id,
            'token' => $token,
            'status' => TechnicalServiceCustomerConfirmation::STATUS_APPROVED,
            'approved_at' => now(),
            'payload' => ['partner_id' => $partnerId],
        ]);

        $job->forceFill([
            'customer_closure_approval_status' => 'onaylandı',
            'customer_closure_approval_method' => 'customer_link',
            'customer_closure_approved_at' => now(),
        ])->save();

        return $confirmation;
    }

    private function mountSessionForServiceRequest(TechnicalServiceRequest $request): TechnicalServiceMountSession
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => $request->serial_number ?: 'SN-PART-'.uniqid(),
            'product_name' => $request->product_name ?: 'Test Kilit',
            'product_model' => $request->product_model,
            'brand' => $request->brand,
        ]);

        $session = TechnicalServiceMountSession::query()->create([
            'technical_service_qr_link_id' => $link->id,
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken('part-session-'.$request->id.'-'.uniqid()),
            'serial_number' => $request->serial_number ?: $link->serial_number,
            'sale_mount_status' => $request->sale_mount_status ?? TechnicalServiceMountSession::SALE_UNKNOWN,
            'mount_payment_status' => $request->mount_payment_status,
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
            'context_payload' => [],
        ]);

        $request->forceFill([
            'qr_link_id' => $link->id,
            'mount_session_id' => $session->id,
        ])->save();

        return $session;
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
