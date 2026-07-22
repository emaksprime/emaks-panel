<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BPartnerUserAccess;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\Role;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningItem;
use App\Models\TechnicalServiceEarningsPeriod;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\B2B\B2BPartnerPortalDataService;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use App\Services\PanelNavigationService;
use Database\Seeders\B2BPartnerPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TechnicalServiceAuthorizationTenantIsolationCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new B2BPartnerPermissionSeeder)->run();
        config()->set('services.partner_portal.public_url', 'https://portal.example.test');
    }

    public function test_intended_redirect_technician_login_preserves_intended_job_card_url(): void
    {
        $fixture = $this->tenantFixture();
        $path = $this->jobCardPath($fixture['jobA']);

        $this->get($path)->assertRedirect('/login');
        $intendedPath = $this->relativeUrl((string) session('url.intended'));
        $this->post('/login', $this->loginPayload($fixture['userA']))->assertRedirect($intendedPath);
    }

    public function test_technician_login_returns_to_exact_authorized_job(): void
    {
        $fixture = $this->tenantFixture();
        $path = $this->jobCardPath($fixture['jobA']);

        $this->get($path)->assertRedirect('/login');
        $intendedPath = $this->relativeUrl((string) session('url.intended'));
        $login = $this->post('/login', $this->loginPayload($fixture['userA']))->assertRedirect($intendedPath);

        $this->get((string) $login->headers->get('Location'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('partnerPortal.selectedPartner.id', $fixture['partnerA']->id)
                ->where('partnerPortal.serviceJobs.0.id', $fixture['jobA']->id));
    }

    public function test_login_rejects_external_intended_url(): void
    {
        $fixture = $this->tenantFixture();
        $fallback = app(PanelNavigationService::class)->homePathFor($fixture['userA']);

        $this->withSession(['url.intended' => 'https://attacker.example.test/steal'])
            ->post('/login', $this->loginPayload($fixture['userA']))
            ->assertRedirect($fallback);
    }

    public function test_login_rejects_protocol_relative_intended_url(): void
    {
        $fixture = $this->tenantFixture();
        $fallback = app(PanelNavigationService::class)->homePathFor($fixture['userA']);

        $this->withSession(['url.intended' => '//attacker.example.test/steal'])
            ->post('/login', $this->loginPayload($fixture['userA']))
            ->assertRedirect($fallback);
    }

    public function test_login_rejects_encoded_control_characters_in_intended_url(): void
    {
        $fixture = $this->tenantFixture();
        $fallback = app(PanelNavigationService::class)->homePathFor($fixture['userA']);

        $this->withSession(['url.intended' => '/partner/service-jobs%0d%0aLocation:%20https://attacker.example.test'])
            ->post('/login', $this->loginPayload($fixture['userA']))
            ->assertRedirect($fallback);
    }

    public function test_unauthorized_intended_job_does_not_open(): void
    {
        $fixture = $this->tenantFixture();
        $path = $this->jobCardPath($fixture['jobA']);

        $login = $this->withSession(['url.intended' => $path])
            ->post('/login', $this->loginPayload($fixture['userB']))
            ->assertRedirect($path);

        $response = $this->get((string) $login->headers->get('Location'));
        $response->assertForbidden();
        $this->assertStringNotContainsString('Tenant Secret Customer A', $response->getContent());
    }

    public function test_missing_intended_url_uses_role_home(): void
    {
        $fixture = $this->tenantFixture();
        $fallback = app(PanelNavigationService::class)->homePathFor($fixture['userA']);

        $this->post('/login', $this->loginPayload($fixture['userA']))->assertRedirect($fallback);
    }

    public function test_intended_url_does_not_bypass_partner_scope(): void
    {
        $fixture = $this->tenantFixture();
        $tampered = '/partner/service-jobs?'.http_build_query([
            'partner_id' => $fixture['partnerB']->id,
            'job_id' => $fixture['jobA']->id,
        ]);

        $login = $this->withSession(['url.intended' => $tampered])
            ->post('/login', $this->loginPayload($fixture['userA']))
            ->assertRedirect($tampered);

        $response = $this->get((string) $login->headers->get('Location'));
        $response->assertForbidden();
        $this->assertStringNotContainsString('Tenant Secret Customer A', $response->getContent());
    }

    public function test_invalid_profile_technician_mapping_fails_closed_fail_closed(): void
    {
        $fixture = $this->tenantFixture();
        $this->setProfileTechnician($fixture['userA'], $fixture['partnerA'], 999999);

        $this->assertNull(app(B2BPartnerServiceJobScopeService::class)
            ->portalTechnicianId($fixture['userA'], $fixture['partnerA']));
        $this->actingAs($fixture['userA'])->get($this->jobCardPath($fixture['jobA']))->assertForbidden();
    }

    public function test_missing_profile_technician_mapping_fails_closed(): void
    {
        $fixture = $this->tenantFixture();
        $this->setProfileMetadata($fixture['userA'], $fixture['partnerA'], []);

        $this->assertNull(app(B2BPartnerServiceJobScopeService::class)
            ->portalTechnicianId($fixture['userA'], $fixture['partnerA']));
    }

    public function test_single_partner_technician_does_not_authorize_unmapped_user(): void
    {
        $fixture = $this->tenantFixture();
        $this->setProfileMetadata($fixture['userA'], $fixture['partnerA'], []);

        $this->actingAs($fixture['userA'])
            ->get('/api/partner/service-jobs/'.$fixture['jobA']->id)
            ->assertForbidden();
    }

    public function test_primary_technician_is_not_auth_fallback(): void
    {
        $fixture = $this->tenantFixture();
        $fixture['partnerA']->forceFill([
            'technical_service_technician_id' => $fixture['technicianA']->id,
        ])->save();
        $this->setProfileMetadata($fixture['userA'], $fixture['partnerA'], []);

        $this->assertNull(app(B2BPartnerServiceJobScopeService::class)
            ->portalTechnicianId($fixture['userA'], $fixture['partnerA']->fresh()));
    }

    public function test_inactive_technician_mapping_denied(): void
    {
        $fixture = $this->tenantFixture();
        $path = $this->jobCardPath($fixture['jobA']);
        $fixture['technicianA']->forceFill(['active' => false])->save();

        $this->actingAs($fixture['userA'])->get($path)->assertForbidden();
    }

    public function test_soft_deleted_technician_mapping_denied(): void
    {
        $fixture = $this->tenantFixture();
        $path = $this->jobCardPath($fixture['jobA']);
        $fixture['technicianA']->delete();

        $this->actingAs($fixture['userA'])->get($path)->assertForbidden();
    }

    public function test_technician_from_wrong_partner_denied(): void
    {
        $fixture = $this->tenantFixture();
        $this->setProfileTechnician($fixture['userA'], $fixture['partnerA'], $fixture['technicianB']->id);

        $this->actingAs($fixture['userA'])->get($this->jobCardPath($fixture['jobA']))->assertForbidden();
    }

    public function test_technician_with_multiple_partner_links_requires_exact_scope_multi_partner_technician(): void
    {
        $fixture = $this->tenantFixture();
        $linkB = $this->link($fixture['partnerB'], $fixture['technicianA']);
        $jobB = $this->job($fixture['technicianA'], 'MRN-MULTI-PARTNER-B', 'Tenant Secret Customer B2');
        $this->offer($jobB, $linkB);
        $this->profile($fixture['userA'], $fixture['partnerB'], $fixture['technicianA']);
        $this->grantPortalAccess($fixture['userA'], $fixture['partnerB']);

        $this->actingAs($fixture['userA'])->get($this->jobCardPath($fixture['jobA']))->assertOk();
        $this->actingAs($fixture['userA'])->get($this->jobCardPath($jobB))->assertOk();

        $tampered = '/partner/service-jobs?'.http_build_query([
            'partner_id' => $fixture['partnerB']->id,
            'job_id' => $fixture['jobA']->id,
        ]);
        $this->actingAs($fixture['userA'])->get($tampered)->assertForbidden();
    }

    public function test_ops_support_mode_does_not_use_technician_auth_fallback(): void
    {
        $fixture = $this->tenantFixture();
        $this->setProfileMetadata($fixture['userA'], $fixture['partnerA'], []);

        $this->actingAs($fixture['userA'])->get($this->jobCardPath($fixture['jobA']))->assertForbidden();
        $this->actingAs($fixture['admin'])
            ->get($this->opsSupportPath($fixture['jobA'], $fixture['linkA']))
            ->assertOk();
    }

    public function test_denial_response_contains_no_customer_pii_pii_redaction(): void
    {
        $fixture = $this->tenantFixture();
        $this->setProfileMetadata($fixture['userA'], $fixture['partnerA'], []);

        $response = $this->actingAs($fixture['userA'])->get($this->jobCardPath($fixture['jobA']));
        $response->assertForbidden();
        $this->assertStringNotContainsString('Tenant Secret Customer A', $response->getContent());
        $this->assertStringNotContainsString('Tenant secret address A', $response->getContent());
    }

    public function test_receive_part_requires_exact_authorized_part_scope_receive_part_authorization(): void
    {
        $fixture = $this->tenantFixture();
        $partB = $this->partRequest($fixture['jobB']);

        $this->actingAs($fixture['userA'])
            ->postJson($this->receivePartPath($fixture['jobA'], $partB))
            ->assertNotFound();
        $this->assertSame(TechnicalServicePartRequest::STATUS_SENT, $partB->fresh()->status);
    }

    public function test_receive_part_parent_denied_child_cannot_bypass(): void
    {
        $fixture = $this->crossPartnerPartFixture();

        $this->actingAs($fixture['userB'])
            ->postJson($this->receivePartPath($fixture['jobA'], $fixture['part']))
            ->assertForbidden();
        $this->assertSame(TechnicalServicePartRequest::STATUS_SENT, $fixture['part']->fresh()->status);
    }

    public function test_receive_part_child_srv_wrong_partner_denied(): void
    {
        $fixture = $this->crossPartnerPartFixture();

        $this->assertAuthorizationDenied(fn () => app(B2BPartnerServiceJobScopeService::class)
            ->assertCanReceivePart($fixture['userB'], $fixture['jobA'], $fixture['part']));
    }

    public function test_receive_part_cross_tenant_denied(): void
    {
        $fixture = $this->tenantFixture();
        $part = $this->partRequest($fixture['jobA']);

        $response = $this->actingAs($fixture['userB'])
            ->postJson($this->receivePartPath($fixture['jobA'], $part));
        $response->assertForbidden();
        $this->assertStringNotContainsString('Tenant Secret Customer A', $response->getContent());
    }

    public function test_receive_part_query_tamper_denied(): void
    {
        $fixture = $this->tenantFixture();
        $part = $this->partRequest($fixture['jobA']);
        $path = $this->receivePartPath($fixture['jobA'], $part).'?partner_id='.$fixture['partnerB']->id;

        $this->actingAs($fixture['userA'])->postJson($path)->assertForbidden();
    }

    public function test_receive_part_old_technician_after_reassignment_denied(): void
    {
        $fixture = $this->tenantFixture();
        $part = $this->partRequest($fixture['jobA']);
        $this->reassign($fixture['jobA'], $fixture['technicianB'], $fixture['linkB']);

        $this->actingAs($fixture['userA'])
            ->postJson($this->receivePartPath($fixture['jobA'], $part))
            ->assertForbidden();
        $this->assertSame(TechnicalServicePartRequest::STATUS_SENT, $part->fresh()->status);
    }

    public function test_receive_part_new_assigned_technician_allowed(): void
    {
        $fixture = $this->tenantFixture();
        $part = $this->partRequest($fixture['jobA']);
        $this->reassign($fixture['jobA'], $fixture['technicianB'], $fixture['linkB']);

        $partner = app(B2BPartnerServiceJobScopeService::class)
            ->assertCanReceivePart($fixture['userB'], $fixture['jobA']->fresh(), $part);

        $this->assertTrue($partner->is($fixture['partnerB']));
    }

    public function test_receive_part_same_root_same_partner_allowed(): void
    {
        $fixture = $this->tenantFixture();
        $part = $this->partRequest($fixture['jobA']);
        $child = $this->childServiceVisit($fixture['jobA'], $part, $fixture['technicianA'], $fixture['linkA']);

        $partner = app(B2BPartnerServiceJobScopeService::class)
            ->assertCanReceivePart($fixture['userA'], $fixture['jobA'], $part->fresh());

        $this->assertTrue($partner->is($fixture['partnerA']));
        $this->assertSame($part->id, (int) $child->source_part_request_id);
    }

    public function test_denied_receive_part_does_not_mutate_part(): void
    {
        $fixture = $this->crossPartnerPartFixture();
        $before = $fixture['part']->only(['status', 'received_at', 'received_by_user_id']);

        $this->actingAs($fixture['userB'])
            ->postJson($this->receivePartPath($fixture['jobA'], $fixture['part']))
            ->assertForbidden();

        $this->assertSame($before, $fixture['part']->fresh()->only(['status', 'received_at', 'received_by_user_id']));
    }

    public function test_denied_receive_part_does_not_write_operation_history(): void
    {
        $fixture = $this->crossPartnerPartFixture();
        $eventCount = $fixture['jobA']->events()->count();

        $this->actingAs($fixture['userB'])
            ->postJson($this->receivePartPath($fixture['jobA'], $fixture['part']))
            ->assertForbidden();

        $this->assertSame($eventCount, $fixture['jobA']->events()->count());
    }

    public function test_denied_receive_part_response_has_no_customer_pii(): void
    {
        $fixture = $this->crossPartnerPartFixture();

        $response = $this->actingAs($fixture['userB'])
            ->postJson($this->receivePartPath($fixture['jobA'], $fixture['part']));
        $response->assertForbidden();
        $this->assertStringNotContainsString('Tenant Secret Customer A', $response->getContent());
    }

    public function test_earnings_require_partner_scope_earnings_partner_scope(): void
    {
        $fixture = $this->multiPartnerEarningFixture();

        $this->assertSame(['MRN-EARNING-A'], $this->earningMrns($this->earnings($fixture['partnerA'])));
        $this->assertSame(['MRN-EARNING-B'], $this->earningMrns($this->earnings($fixture['partnerB'])));
    }

    public function test_same_technician_two_partners_results_are_isolated(): void
    {
        $fixture = $this->multiPartnerEarningFixture();

        $payloadA = json_encode($this->earnings($fixture['partnerA']), JSON_THROW_ON_ERROR);
        $payloadB = json_encode($this->earnings($fixture['partnerB']), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('MRN-EARNING-A', $payloadA);
        $this->assertStringNotContainsString('MRN-EARNING-B', $payloadA);
        $this->assertStringContainsString('MRN-EARNING-B', $payloadB);
        $this->assertStringNotContainsString('MRN-EARNING-A', $payloadB);
    }

    public function test_partner_a_cannot_view_partner_b_earnings(): void
    {
        $fixture = $this->multiPartnerEarningFixture();

        $this->actingAs($fixture['userA'])
            ->getJson('/api/partner/earnings?partner_id='.$fixture['partnerB']->id)
            ->assertForbidden();
    }

    public function test_partner_query_tamper_does_not_change_scope(): void
    {
        $fixture = $this->multiPartnerEarningFixture();

        $response = $this->actingAs($fixture['userA'])
            ->getJson('/api/partner/earnings?partner_id='.$fixture['partnerB']->id);
        $response->assertForbidden();
        $this->assertStringNotContainsString('MRN-EARNING-B', $response->getContent());
    }

    public function test_reassignment_revokes_old_partner_earning_access(): void
    {
        $fixture = $this->multiPartnerEarningFixture();
        $this->offer($fixture['jobA'], $fixture['linkB']);

        $this->assertNotContains('MRN-EARNING-A', $this->earningMrns($this->earnings($fixture['partnerA'])));
    }

    public function test_reassignment_new_partner_sees_correct_earning(): void
    {
        $fixture = $this->multiPartnerEarningFixture();
        $this->offer($fixture['jobA'], $fixture['linkB']);

        $this->assertContains('MRN-EARNING-A', $this->earningMrns($this->earnings($fixture['partnerB'])));
    }

    public function test_wrong_tenant_earning_absent(): void
    {
        $fixture = $this->multiPartnerEarningFixture();

        $this->assertNotContains('MRN-EARNING-B', $this->earningMrns($this->earnings($fixture['partnerA'])));
    }

    public function test_earning_total_uses_only_scoped_partner_rows(): void
    {
        $fixture = $this->multiPartnerEarningFixture();

        $this->assertSame(1100.0, (float) data_get($this->earnings($fixture['partnerA']), 'completed.summary.grand_total'));
        $this->assertSame(2200.0, (float) data_get($this->earnings($fixture['partnerB']), 'completed.summary.grand_total'));
    }

    public function test_canonical_job_link_and_earning_use_same_partner_context(): void
    {
        $fixture = $this->multiPartnerEarningFixture();
        $context = app(B2BPartnerServiceJobScopeService::class)->technicianJobCardContext($fixture['jobA']);

        $this->assertSame($fixture['partnerA']->id, $context['partner_id']);
        $this->assertContains($fixture['jobA']->mrn, $this->earningMrns($this->earnings($fixture['partnerA'])));
        $this->assertNotContains($fixture['jobA']->mrn, $this->earningMrns($this->earnings($fixture['partnerB'])));
    }

    public function test_unauthorized_earning_response_has_no_customer_pii(): void
    {
        $fixture = $this->multiPartnerEarningFixture();

        $response = $this->actingAs($fixture['userA'])
            ->getJson('/api/partner/earnings?partner_id='.$fixture['partnerB']->id);
        $response->assertForbidden();
        $this->assertStringNotContainsString('Tenant Earning Customer B', $response->getContent());
    }

    public function test_job_card_part_and_earning_share_same_partner_scope(): void
    {
        $fixture = $this->multiPartnerEarningFixture();
        $part = $this->partRequest($fixture['jobA']);
        $scope = app(B2BPartnerServiceJobScopeService::class);

        $this->assertSame($fixture['partnerA']->id, $scope->technicianJobCardContext($fixture['jobA'])['partner_id']);
        $this->assertTrue($scope->assertCanReceivePart($fixture['userA'], $fixture['jobA'], $part)->is($fixture['partnerA']));
        $this->assertContains($fixture['jobA']->mrn, $this->earningMrns($this->earnings($fixture['partnerA'])));
    }

    public function test_scope_service_fails_closed_for_partial_context(): void
    {
        $fixture = $this->tenantFixture();
        $this->setProfileMetadata($fixture['userA'], $fixture['partnerA'], []);

        $this->assertAuthorizationDenied(fn () => app(B2BPartnerServiceJobScopeService::class)
            ->resolveAuthenticatedTechnicianOrFail($fixture['userA'], $fixture['partnerA']));
    }

    public function test_scope_service_rejects_cross_partner_parent_child_mix_parent_child_scope(): void
    {
        $fixture = $this->crossPartnerPartFixture();

        $this->assertAuthorizationDenied(fn () => app(B2BPartnerServiceJobScopeService::class)
            ->assertCanReceivePart($fixture['userB'], $fixture['jobA'], $fixture['part']));
    }

    public function test_ops_support_scope_is_explicit_not_implicit(): void
    {
        $fixture = $this->tenantFixture();
        $this->setProfileMetadata($fixture['userA'], $fixture['partnerA'], []);
        $scope = app(B2BPartnerServiceJobScopeService::class);

        $this->assertAuthorizationDenied(fn () => $scope->assertCanViewServiceJob($fixture['userA'], $fixture['jobA']));
        $selection = $scope->assertOpsSupportSelection(
            $fixture['partnerA']->id,
            $fixture['technicianA']->id,
            $fixture['jobA'],
        );
        $this->assertSame($fixture['partnerA']->id, $selection['partner']->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantFixture(): array
    {
        $admin = $this->userWithRole('admin', true);
        $partnerA = $this->partner('Tenant Partner A');
        $partnerB = $this->partner('Tenant Partner B');
        $technicianA = $this->technician('Tenant Usta A');
        $technicianB = $this->technician('Tenant Usta B');
        $linkA = $this->link($partnerA, $technicianA, true);
        $linkB = $this->link($partnerB, $technicianB, true);
        $userA = $this->portalUser($partnerA, $technicianA, 'tenant_a');
        $userB = $this->portalUser($partnerB, $technicianB, 'tenant_b');
        $jobA = $this->job($technicianA, 'MRN-TENANT-A', 'Tenant Secret Customer A');
        $jobB = $this->job($technicianB, 'MRN-TENANT-B', 'Tenant Secret Customer B');
        $this->offer($jobA, $linkA);
        $this->offer($jobB, $linkB);

        return compact(
            'admin',
            'partnerA',
            'partnerB',
            'technicianA',
            'technicianB',
            'linkA',
            'linkB',
            'userA',
            'userB',
            'jobA',
            'jobB',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function crossPartnerPartFixture(): array
    {
        $fixture = $this->tenantFixture();
        $part = $this->partRequest($fixture['jobA']);
        $child = $this->childServiceVisit(
            $fixture['jobA'],
            $part,
            $fixture['technicianB'],
            $fixture['linkB'],
        );

        return [...$fixture, 'part' => $part->fresh(), 'child' => $child];
    }

    /**
     * @return array<string, mixed>
     */
    private function multiPartnerEarningFixture(): array
    {
        $partnerA = $this->partner('Earning Partner A');
        $partnerB = $this->partner('Earning Partner B');
        $technician = $this->technician('Multi Partner Earning Usta');
        $linkA = $this->link($partnerA, $technician, true);
        $linkB = $this->link($partnerB, $technician, true);
        $userA = $this->portalUser($partnerA, $technician, 'earning_a');
        $userB = $this->portalUser($partnerB, $technician, 'earning_b');
        $jobA = $this->job($technician, 'MRN-EARNING-A', 'Tenant Earning Customer A');
        $jobB = $this->job($technician, 'MRN-EARNING-B', 'Tenant Earning Customer B');
        $this->offer($jobA, $linkA);
        $offerB = $this->offer($jobB, $linkB);
        $offerB->forceFill([
            'labor_amount' => 2000,
            'route_fee_amount' => 200,
            'total_amount' => 2200,
        ])->save();
        $period = TechnicalServiceEarningsPeriod::query()->create([
            'year' => 2026,
            'month' => 7,
            'status' => 'Hazır',
            'calculated_at' => now(),
        ]);
        $earning = TechnicalServiceEarning::query()->create([
            'period_id' => $period->id,
            'technical_service_technician_id' => $technician->id,
            'technician_name_snapshot' => $technician->name,
            'city_snapshot' => 'Denizli',
            'job_count' => 2,
            'installation_count' => 2,
            'service_count' => 0,
            'labor_total' => 3000,
            'travel_fee_total' => 300,
            'travel_round_trip_km_total' => 0,
            'travel_billable_km_total' => 0,
            'grand_total' => 3300,
            'status' => 'Hazır',
        ]);
        $this->earningItem($earning, $jobA, 1000, 100);
        $this->earningItem($earning, $jobB, 2000, 200);

        return compact(
            'partnerA',
            'partnerB',
            'technician',
            'linkA',
            'linkB',
            'userA',
            'userB',
            'jobA',
            'jobB',
            'earning',
        );
    }

    private function childServiceVisit(
        TechnicalServiceRequest $parent,
        TechnicalServicePartRequest $part,
        TechnicalServiceTechnician $technician,
        B2BPartnerTechnician $link,
    ): TechnicalServiceRequest {
        $child = $this->job($technician, 'SRV-'.$parent->id.'-'.$technician->id, 'Child Tenant Customer', [
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->root_mrn ?: $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-'.$parent->id.'-'.$technician->id,
            'service_visit_reason' => 'spare_part',
            'service_type' => 'Servis',
            'source_part_request_id' => $part->id,
        ]);
        $this->offer($child, $link);
        $part->forceFill([
            'service_visit_request_id' => $child->id,
            'requires_service_visit' => true,
        ])->save();

        return $child;
    }

    private function partRequest(TechnicalServiceRequest $job): TechnicalServicePartRequest
    {
        return TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $job->id,
            'root_request_id' => $job->parent_request_id ?: $job->id,
            'requested_by_technician_id' => $job->technical_service_technician_id,
            'status' => TechnicalServicePartRequest::STATUS_SENT,
            'part_name' => 'Security Test Part',
            'quantity' => 1,
        ]);
    }

    private function earningItem(
        TechnicalServiceEarning $earning,
        TechnicalServiceRequest $job,
        float $labor,
        float $travel,
    ): TechnicalServiceEarningItem {
        return TechnicalServiceEarningItem::query()->create([
            'earning_id' => $earning->id,
            'technical_service_request_id' => $job->id,
            'mrn' => $job->mrn,
            'job_date' => now(),
            'customer_city' => 'Denizli',
            'customer_district' => 'Pamukkale',
            'service_type' => 'Montaj',
            'product_name' => 'Test Kilit',
            'labor_amount' => $labor,
            'travel_round_trip_km' => 0,
            'travel_billable_km' => 0,
            'travel_fee_amount' => $travel,
            'line_total' => $labor + $travel,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function earnings(B2BPartner $partner): array
    {
        return app(B2BPartnerPortalDataService::class)->earningsFor($partner);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function earningMrns(array $payload): array
    {
        return collect(['pending', 'completed', 'excluded'])
            ->flatMap(function (string $bucket) use ($payload): array {
                return collect(data_get($payload, $bucket.'.rows', []))
                    ->flatMap(fn (array $row): array => isset($row['items'])
                        ? collect($row['items'])->pluck('mrn')->all()
                        : [$row['mrn'] ?? null])
                    ->filter()
                    ->all();
            })
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function reassign(
        TechnicalServiceRequest $job,
        TechnicalServiceTechnician $technician,
        B2BPartnerTechnician $link,
    ): void {
        $job->forceFill(['technical_service_technician_id' => $technician->id])->save();
        $this->offer($job, $link);
    }

    private function jobCardPath(TechnicalServiceRequest $job): string
    {
        $url = (string) app(B2BPartnerServiceJobScopeService::class)
            ->technicianJobCardContext($job)['canonical_url'];

        return (string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY);
    }

    private function receivePartPath(TechnicalServiceRequest $job, TechnicalServicePartRequest $part): string
    {
        return "/api/partner/service-jobs/{$job->id}/part-requests/{$part->id}/received";
    }

    private function relativeUrl(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH)
            .(parse_url($url, PHP_URL_QUERY) !== null ? '?'.parse_url($url, PHP_URL_QUERY) : '');
    }

    private function opsSupportPath(TechnicalServiceRequest $job, B2BPartnerTechnician $link): string
    {
        return '/technical-service/ops-support/service-jobs?'.http_build_query([
            'partner_id' => $link->partner_id,
            'technician_id' => $link->technical_service_technician_id,
            'job_id' => $job->id,
        ]);
    }

    /**
     * @return array{username: string, password: string}
     */
    private function loginPayload(User $user): array
    {
        return ['username' => (string) $user->username, 'password' => 'password'];
    }

    private function userWithRole(string $roleCode, bool $superAdmin = false): User
    {
        Role::query()->updateOrCreate(
            ['code' => $roleCode],
            ['name' => $roleCode, 'is_super_admin' => $superAdmin],
        );

        return User::factory()->create(['role_code' => $roleCode]);
    }

    private function partner(string $name): B2BPartner
    {
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'SEC-'.strtoupper(substr(md5($name), 0, 8)),
            'display_name' => $name,
            'mikro_cari_kodu' => 'TEST-'.substr(md5($name), 0, 8),
            'mikro_cari_unvan' => $name,
            'city' => 'Denizli',
            'district' => 'Pamukkale',
            'active' => true,
        ]);
        B2BPartnerCapability::query()->create([
            'partner_id' => $partner->id,
            'capability' => B2BPartner::TYPE_LOCKSMITH,
            'active' => true,
        ]);

        return $partner->load('capabilities');
    }

    private function technician(string $name): TechnicalServiceTechnician
    {
        return TechnicalServiceTechnician::query()->create([
            'name' => $name,
            'technician_type' => 'locksmith',
            'phone' => '+90555'.str_pad((string) TechnicalServiceTechnician::query()->count(), 7, '0', STR_PAD_LEFT),
            'city' => 'Denizli',
            'district' => 'Pamukkale',
            'active' => true,
        ]);
    }

    private function link(
        B2BPartner $partner,
        TechnicalServiceTechnician $technician,
        bool $primary = false,
    ): B2BPartnerTechnician {
        return B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'owner',
            'is_primary' => $primary,
            'active' => true,
        ]);
    }

    private function portalUser(
        B2BPartner $partner,
        TechnicalServiceTechnician $technician,
        string $suffix,
    ): User {
        $user = $this->userWithRole('b2b_locksmith');
        $user->forceFill(['username' => 'security_'.$suffix])->save();
        $this->profile($user, $partner, $technician);
        $this->grantPortalAccess($user, $partner);

        return $user;
    }

    private function profile(
        User $user,
        B2BPartner $partner,
        TechnicalServiceTechnician $technician,
    ): B2BPartnerUserProfile {
        return B2BPartnerUserProfile::query()->updateOrCreate(
            ['user_id' => $user->id, 'partner_id' => $partner->id],
            [
                'active' => true,
                'metadata' => [
                    'technical_service_technician_id' => $technician->id,
                    'source' => 'rel4e16a_security_test',
                ],
            ],
        );
    }

    private function grantPortalAccess(User $user, B2BPartner $partner): void
    {
        foreach (['view', 'technical_service', 'finance'] as $scope) {
            B2BPartnerUserAccess::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'partner_id' => $partner->id,
                    'access_scope' => $scope,
                ],
                ['can_view' => true],
            );
        }
    }

    private function setProfileTechnician(User $user, B2BPartner $partner, int $technicianId): void
    {
        $this->setProfileMetadata($user, $partner, [
            'technical_service_technician_id' => $technicianId,
            'source' => 'rel4e16a_security_test',
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function setProfileMetadata(User $user, B2BPartner $partner, array $metadata): void
    {
        B2BPartnerUserProfile::query()
            ->where('user_id', $user->id)
            ->where('partner_id', $partner->id)
            ->update(['metadata' => $metadata]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function job(
        TechnicalServiceTechnician $technician,
        string $mrn,
        string $customerName,
        array $overrides = [],
    ): TechnicalServiceRequest {
        return TechnicalServiceRequest::query()->create([
            'mrn' => $mrn,
            'customer_name' => $customerName,
            'customer_phone' => '+905550000001',
            'customer_city' => 'Denizli',
            'customer_district' => 'Pamukkale',
            'service_address' => str_replace('Customer', 'address', $customerName),
            'product_name' => 'Test Kilit',
            'service_type' => 'Montaj',
            'technical_service_technician_id' => $technician->id,
            'status' => 'Atandı',
            'workflow_status' => 'Usta Onayı Bekleyen',
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            ...$overrides,
        ]);
    }

    private function offer(
        TechnicalServiceRequest $request,
        B2BPartnerTechnician $link,
    ): TechnicalServiceAssignmentOffer {
        return TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $link->technical_service_technician_id,
            'labor_amount' => 1000,
            'route_fee_amount' => 100,
            'total_amount' => 1100,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
            'metadata' => [
                'assignment_partner_id' => $link->partner_id,
                'assignment_partner_technician_link_id' => $link->id,
            ],
        ]);
    }

    private function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('AuthorizationException bekleniyordu.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
