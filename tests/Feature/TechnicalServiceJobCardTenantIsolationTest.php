<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BPartnerUserAccess;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\Role;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use App\Services\Messaging\TechnicalServiceManualE2ERunContext;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Messaging\TechnicalServiceTechnicianPortalLinkResolver;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\B2BPartnerPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TechnicalServiceJobCardTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new B2BPartnerPermissionSeeder)->run();
        config()->set('services.partner_portal.public_url', 'https://portal.example.test');
    }

    public function test_assigned_technician_can_open_own_job_card_link_tenant_isolation(): void
    {
        $fixture = $this->tenantFixture();
        $path = $this->jobCardPath($fixture['job']);

        $this->actingAs($fixture['userA'])
            ->get($path)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/service-jobs')
                ->where('partnerPortal.allowed', true)
                ->where('partnerPortal.selectedPartner.id', $fixture['partnerA']->id)
                ->where('partnerPortal.serviceJobs.0.id', $fixture['job']->id)
            );
    }

    public function test_other_technician_cannot_open_job_card_link(): void
    {
        $fixture = $this->tenantFixture();
        $response = $this->actingAs($fixture['otherUserA'])->get($this->jobCardPath($fixture['job']));

        $response->assertForbidden();
        $this->assertStringNotContainsString('Tenant Secret Customer', $response->getContent());
    }

    public function test_other_partner_cannot_open_job_card_link(): void
    {
        $fixture = $this->tenantFixture();
        $response = $this->actingAs($fixture['userB'])->get($this->jobCardPath($fixture['job']));

        $response->assertForbidden();
        $this->assertStringNotContainsString('Tenant Secret Customer', $response->getContent());
    }

    public function test_unauthenticated_job_card_redirects_to_login_and_returns_to_intended_job(): void
    {
        $fixture = $this->tenantFixture();
        $path = $this->jobCardPath($fixture['job']);

        $this->get($path)->assertRedirect('/login');

        $intended = (string) session('url.intended');
        $this->assertStringContainsString('/partner/service-jobs?', $intended);
        $this->assertStringContainsString('job_id='.$fixture['job']->id, $intended);
    }

    public function test_reassigned_old_technician_loses_access(): void
    {
        $fixture = $this->tenantFixture();
        $oldPath = $this->jobCardPath($fixture['job']);

        $this->reassign($fixture['job'], $fixture['otherTechnicianA'], $fixture['otherLinkA']);

        $response = $this->actingAs($fixture['userA'])->get($oldPath);
        $response->assertForbidden();
        $this->assertStringNotContainsString('Tenant Secret Customer', $response->getContent());
    }

    public function test_reassigned_new_technician_gains_access(): void
    {
        $fixture = $this->tenantFixture();
        $this->reassign($fixture['job'], $fixture['otherTechnicianA'], $fixture['otherLinkA']);

        $this->actingAs($fixture['otherUserA'])
            ->get($this->jobCardPath($fixture['job']->fresh()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('partnerPortal.allowed', true)
                ->where('partnerPortal.serviceJobs.0.id', $fixture['job']->id)
            );
    }

    public function test_job_card_link_uses_active_assignment_partner(): void
    {
        [$request, $firstLink, $activeLink] = $this->ambiguousAssignmentFixture();

        $context = app(B2BPartnerServiceJobScopeService::class)->technicianJobCardContext($request);

        $this->assertTrue($context['ready']);
        $this->assertSame($activeLink->partner_id, $context['partner_id']);
        $this->assertStringContainsString('partner_id='.$activeLink->partner_id, $context['canonical_url']);
        $this->assertStringNotContainsString('partner_id='.$firstLink->partner_id.'&', $context['canonical_url']);
    }

    public function test_job_card_link_does_not_use_first_unrelated_partner(): void
    {
        [$request, $firstLink, $activeLink] = $this->ambiguousAssignmentFixture();

        $context = app(B2BPartnerServiceJobScopeService::class)->technicianJobCardContext($request);

        $this->assertNotSame($firstLink->partner_id, $context['partner_id']);
        $this->assertSame($activeLink->id, $context['partner_technician_link_id']);
    }

    public function test_technician_message_never_contains_ops_preview_url(): void
    {
        $fixture = $this->tenantFixture();
        $payload = app(TechnicalServiceWorkflowService::class)->serialize($fixture['job'], true);
        $jobCard = $payload['technician_job_card'];

        $this->assertTrue($jobCard['ready']);
        $this->assertStringContainsString('/partner/service-jobs?', $jobCard['canonical_url']);
        $this->assertStringNotContainsString('/technical-service/ops-support/', $jobCard['canonical_url']);
        $this->assertStringNotContainsString('/portal-preview', $jobCard['canonical_url']);
        $this->assertStringContainsString('/technical-service/ops-support/', $jobCard['ops_support_url']);
        $this->assertStringContainsString('/portal-preview', $jobCard['preview_url']);
    }

    public function test_ops_support_mode_requires_admin_or_ops_permission(): void
    {
        $fixture = $this->tenantFixture();
        $url = $this->opsSupportPath($fixture['job'], $fixture['linkA']);

        $this->actingAs($fixture['userA'])->get($url)->assertForbidden();
        $this->actingAs($fixture['admin'])->get($url)->assertOk();
    }

    public function test_ops_support_mode_opens_selected_request_and_technician(): void
    {
        $fixture = $this->tenantFixture();

        $this->actingAs($fixture['admin'])
            ->get($this->opsSupportPath($fixture['job'], $fixture['linkA']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/service-jobs')
                ->where('partnerPortal.opsSupport.enabled', true)
                ->where('partnerPortal.opsSupport.partner_id', $fixture['partnerA']->id)
                ->where('partnerPortal.opsSupport.technician_id', $fixture['technicianA']->id)
                ->where('partnerPortal.serviceJobs.0.id', $fixture['job']->id)
            );
    }

    public function test_ops_support_mode_allows_permitted_mutations(): void
    {
        $fixture = $this->tenantFixture();

        $this->actingAs($fixture['admin'])
            ->postJson($this->opsSupportApiPath($fixture['job'], $fixture['linkA'], '/note'), [
                'note' => 'Kontrollü OPS destek notu',
                'visibility' => 'ops',
            ])
            ->assertOk()
            ->assertJsonPath('action', TechnicalServicePartnerJobAction::ACTION_NOTE_ADDED);

        $this->assertDatabaseHas('technical_service_partner_job_actions', [
            'technical_service_request_id' => $fixture['job']->id,
            'partner_id' => $fixture['partnerA']->id,
            'user_id' => $fixture['admin']->id,
            'action' => TechnicalServicePartnerJobAction::ACTION_NOTE_ADDED,
        ]);
    }

    public function test_ops_support_action_records_real_ops_actor_and_acting_as_metadata(): void
    {
        $fixture = $this->tenantFixture();

        $this->actingAs($fixture['admin'])
            ->postJson($this->opsSupportApiPath($fixture['job'], $fixture['linkA'], '/note'), [
                'note' => 'OPS aktör kanıtı',
                'visibility' => 'ops',
            ])
            ->assertOk();

        $event = $fixture['job']->events()->latest('id')->firstOrFail();
        $this->assertSame($fixture['admin']->id, $event->author_user_id);
        $this->assertSame('ops_support_mode', data_get($event->metadata, 'source'));
        $this->assertSame($fixture['technicianA']->id, data_get($event->metadata, 'acting_as_technician_id'));
        $this->assertSame($fixture['partnerA']->id, data_get($event->metadata, 'acting_as_partner_id'));
        $this->assertNotSame($fixture['technicianA']->name, $fixture['admin']->name);
    }

    public function test_normal_technician_cannot_use_ops_support_mode(): void
    {
        $fixture = $this->tenantFixture();

        $this->actingAs($fixture['userA'])
            ->postJson($this->opsSupportApiPath($fixture['job'], $fixture['linkA'], '/note'), [
                'note' => 'Yetkisiz destek aksiyonu',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('technical_service_partner_job_actions', [
            'technical_service_request_id' => $fixture['job']->id,
            'note' => 'Yetkisiz destek aksiyonu',
        ]);
    }

    public function test_normal_technician_cannot_switch_to_other_technician(): void
    {
        $fixture = $this->tenantFixture();
        $url = '/api/partner/service-jobs/'.$fixture['job']->id.'?'.http_build_query([
            'partner_id' => $fixture['partnerA']->id,
            'technician_id' => $fixture['otherTechnicianA']->id,
        ]);

        $response = $this->actingAs($fixture['userA'])->getJson($url);
        $response->assertForbidden();
        $this->assertStringNotContainsString('Tenant Secret Customer', $response->getContent());

        $source = file_get_contents(resource_path('js/pages/partner/portal-shell.tsx'));
        $this->assertStringContainsString('{isOpsSupport && (', $source);
        $this->assertStringContainsString('Destek verilecek ustayı seç', $source);
    }

    public function test_hakedis_job_card_button_opens_same_request_active_technician_support_mode(): void
    {
        $fixture = $this->tenantFixture();
        $context = app(B2BPartnerServiceJobScopeService::class)->technicianJobCardContext($fixture['job']);

        $this->assertStringContainsString('job_id='.$fixture['job']->id, $context['ops_support_url']);
        $this->assertStringContainsString('partner_id='.$fixture['partnerA']->id, $context['ops_support_url']);
        $this->assertStringContainsString('technician_id='.$fixture['technicianA']->id, $context['ops_support_url']);

        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));
        $this->assertStringContainsString('Usta İş Kartını OPS Olarak Yönet', $source);
        $this->assertStringContainsString('technicianJobCard.ops_support_url', $source);
        $this->assertStringContainsString('Usta Portalını Önizle', $source);
    }

    public function test_authorized_job_deep_link_endpoint_returns_exact_scoped_job(): void
    {
        $fixture = $this->tenantFixture();
        $url = '/api/partner/service-jobs/'.$fixture['job']->id.'?'.http_build_query([
            'partner_id' => $fixture['partnerA']->id,
        ]);

        $this->actingAs($fixture['userA'])
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('partner_id', $fixture['partnerA']->id)
            ->assertJsonPath('job.id', $fixture['job']->id)
            ->assertJsonPath('job.mrn', $fixture['job']->mrn);
    }

    public function test_technician_short_job_link_redirects_authorized_user(): void
    {
        $fixture = $this->tenantFixture();

        $this->actingAs($fixture['userA'])
            ->get('/pj/'.$fixture['job']->id)
            ->assertRedirectToRoute('partner.service-jobs', [
                'partner_id' => $fixture['partnerA']->id,
                'job_id' => $fixture['job']->id,
            ]);
    }

    public function test_technician_short_job_link_preserves_intended_login(): void
    {
        $fixture = $this->tenantFixture();

        $this->get('/pj/'.$fixture['job']->id)->assertRedirect('/login');
        $this->assertStringEndsWith('/pj/'.$fixture['job']->id, (string) session('url.intended'));
    }

    public function test_technician_short_job_link_denies_wrong_technician_without_pii(): void
    {
        $fixture = $this->tenantFixture();
        $response = $this->actingAs($fixture['userB'])->get('/pj/'.$fixture['job']->id);

        $response->assertForbidden();
        $this->assertStringNotContainsString('Tenant Secret Customer', $response->getContent());
    }

    public function test_technician_short_job_link_denies_after_reassignment(): void
    {
        $fixture = $this->tenantFixture();
        $this->reassign($fixture['job'], $fixture['otherTechnicianA'], $fixture['otherLinkA']);

        $this->actingAs($fixture['userA'])
            ->get('/pj/'.$fixture['job']->id)
            ->assertForbidden();
        $this->actingAs($fixture['otherUserA'])
            ->get('/pj/'.$fixture['job']->id)
            ->assertRedirectToRoute('partner.service-jobs', [
                'partner_id' => $fixture['partnerA']->id,
                'job_id' => $fixture['job']->id,
            ]);
    }

    public function test_technician_short_job_link_uses_manual_e2e_origin_only_when_guarded(): void
    {
        $fixture = $this->tenantFixture();
        [$settings, $metadata] = $this->manualE2ELinkContext('905467647428');
        config(['services.partner_portal.public_url' => 'http://10.0.28.64:8000']);

        $resolved = app(TechnicalServiceTechnicianPortalLinkResolver::class)->resolveForDispatch(
            $fixture['job'],
            $settings,
            'technician',
            '905467647428',
            $metadata,
        );

        $this->assertTrue($resolved['ready']);
        $this->assertSame('manual_e2e_local', $resolved['mode']);
        $this->assertSame('services.partner_portal.public_url', $resolved['source']);
        $this->assertSame('http://10.0.28.64:8000/pj/'.$fixture['job']->id, $resolved['short_url']);
        $this->assertStringContainsString('/partner/service-jobs?', $resolved['canonical_url']);
        $this->assertStringNotContainsString('ops-support', $resolved['short_url']);
        $this->assertStringNotContainsString('portal-preview', $resolved['short_url']);
    }

    public function test_non_manual_non_allowlisted_and_customer_contexts_ignore_lan_origin(): void
    {
        $fixture = $this->tenantFixture();
        [$settings, $metadata] = $this->manualE2ELinkContext('905467647428');
        $resolver = app(TechnicalServiceTechnicianPortalLinkResolver::class);

        $nonManual = $settings;
        $nonManual['global']['manual_e2e_enabled'] = false;
        $this->assertSame('public_live', $resolver->resolveForDispatch(
            $fixture['job'],
            $nonManual,
            'technician',
            '905467647428',
            $metadata,
        )['mode']);

        $this->assertSame('public_live', $resolver->resolveForDispatch(
            $fixture['job'],
            $settings,
            'technician',
            '905500000000',
            $metadata,
        )['mode']);

        $this->assertSame('public_live', $resolver->resolveForDispatch(
            $fixture['job'],
            $settings,
            'customer',
            '905467647428',
            $metadata,
        )['mode']);
    }

    public function test_production_ignores_manual_e2e_lan_origin(): void
    {
        $fixture = $this->tenantFixture();
        [$settings, $metadata] = $this->manualE2ELinkContext('905467647428');
        $previousEnvironment = app()->environment();

        try {
            app()->detectEnvironment(fn (): string => 'production');
            $resolved = app(TechnicalServiceTechnicianPortalLinkResolver::class)->resolveForDispatch(
                $fixture['job'],
                $settings,
                'technician',
                '905467647428',
                $metadata,
            );
            $this->assertSame('public_live', $resolved['mode']);
            $this->assertStringStartsWith('https://portal.example.test/', $resolved['short_url']);
        } finally {
            app()->detectEnvironment(fn (): string => $previousEnvironment);
        }
    }

    public function test_non_local_environment_ignores_manual_e2e_lan_origin(): void
    {
        $fixture = $this->tenantFixture();
        [$settings, $metadata] = $this->manualE2ELinkContext('905467647428');
        $previousEnvironment = app()->environment();

        try {
            app()->detectEnvironment(fn (): string => 'staging');
            $resolved = app(TechnicalServiceTechnicianPortalLinkResolver::class)->resolveForDispatch(
                $fixture['job'],
                $settings,
                'technician',
                '905467647428',
                $metadata,
            );
            $this->assertSame('public_live', $resolved['mode']);
            $this->assertStringStartsWith('https://portal.example.test/', $resolved['short_url']);
        } finally {
            app()->detectEnvironment(fn (): string => $previousEnvironment);
        }
    }

    public function test_local_short_job_link_accepts_private_lan_profile_without_enabling_provider_effects(): void
    {
        $fixture = $this->tenantFixture();
        config([
            'services.partner_portal.public_url' => 'http://10.0.28.64:8000',
            'app.url' => 'http://10.0.28.64:8000',
        ]);

        $resolved = app(TechnicalServiceTechnicianPortalLinkResolver::class)->resolveForDispatch(
            $fixture['job'],
            ['global' => ['test_mode_enabled' => false, 'manual_e2e_enabled' => false]],
            'technician',
            '905467647428',
        );

        $this->assertTrue($resolved['ready']);
        $this->assertSame('local_preview', $resolved['mode']);
        $this->assertSame('services.partner_portal.public_url', $resolved['source']);
        $this->assertSame('http://10.0.28.64:8000/pj/'.$fixture['job']->id, $resolved['short_url']);
    }

    public function test_job_deep_link_page_exposes_only_authorized_requested_job_id(): void
    {
        $fixture = $this->tenantFixture();

        $this->actingAs($fixture['userA'])
            ->get($this->jobCardPath($fixture['job']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('requestedJobId', $fixture['job']->id)
                ->where('partnerPortal.selectedPartner.id', $fixture['partnerA']->id)
                ->where('partnerPortal.serviceJobs.0.id', $fixture['job']->id));

        $response = $this->actingAs($fixture['userB'])
            ->get($this->jobCardPath($fixture['job']));
        $response->assertForbidden();
        $this->assertStringNotContainsString('Tenant Secret Customer', $response->getContent());
    }

    public function test_reassigned_job_deep_link_denies_old_and_allows_new_technician(): void
    {
        $fixture = $this->tenantFixture();
        $path = $this->jobCardPath($fixture['job']);
        $this->reassign($fixture['job'], $fixture['otherTechnicianA'], $fixture['otherLinkA']);

        $oldResponse = $this->actingAs($fixture['userA'])->get($path);
        $oldResponse->assertForbidden();
        $this->assertStringNotContainsString('Tenant Secret Customer', $oldResponse->getContent());

        $this->actingAs($fixture['otherUserA'])
            ->get($this->jobCardPath($fixture['job']->fresh()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('requestedJobId', $fixture['job']->id)
                ->where('partnerPortal.selectedPartner.id', $fixture['partnerA']->id));
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
        $otherTechnicianA = $this->technician('Tenant Usta A2');
        $technicianB = $this->technician('Tenant Usta B');
        $linkA = $this->link($partnerA, $technicianA, true);
        $otherLinkA = $this->link($partnerA, $otherTechnicianA);
        $linkB = $this->link($partnerB, $technicianB, true);
        $userA = $this->portalUser($partnerA, $technicianA, 'tenant_a');
        $otherUserA = $this->portalUser($partnerA, $otherTechnicianA, 'tenant_a2');
        $userB = $this->portalUser($partnerB, $technicianB, 'tenant_b');
        $job = $this->job($technicianA, 'MRN-TENANT-A');
        $this->offer($job, $linkA);

        return compact(
            'admin',
            'partnerA',
            'partnerB',
            'technicianA',
            'otherTechnicianA',
            'technicianB',
            'linkA',
            'otherLinkA',
            'linkB',
            'userA',
            'otherUserA',
            'userB',
            'job',
        );
    }

    /**
     * @return array{0:array<string, mixed>,1:array<string, mixed>}
     */
    private function manualE2ELinkContext(string $targetPhone): array
    {
        $now = CarbonImmutable::now();
        $global = [
            'test_mode_enabled' => false,
            'manual_e2e_enabled' => true,
            'manual_e2e_phase' => TechnicalServiceMessagingSettingsService::MANUAL_E2E_PHASE_PREPARED,
            'manual_e2e_active_run_id' => 'MANUAL-E2E-REL4E17A1-TEST',
            'manual_e2e_started_at' => $now->subMinute()->toIso8601String(),
            'manual_e2e_created_after' => $now->subMinute()->toIso8601String(),
            'manual_e2e_expires_at' => $now->addHour()->toIso8601String(),
            'manual_e2e_allowlisted_phones' => [$targetPhone],
            'manual_e2e_partner_portal_origin_enabled' => true,
            'manual_e2e_partner_portal_origin' => 'http://10.0.28.64:8000',
        ];
        $metadata = TechnicalServiceManualE2ERunContext::fromSettings($global)
            ->dispatchMetadata('MRN-TENANT-A', $targetPhone, 'technician');

        return [['global' => $global], $metadata];
    }

    /**
     * @return array{0: TechnicalServiceRequest, 1: B2BPartnerTechnician, 2: B2BPartnerTechnician}
     */
    private function ambiguousAssignmentFixture(): array
    {
        $technician = $this->technician('Multi Partner Usta');
        $firstPartner = $this->partner('İlk İlgisiz Partner');
        $activePartner = $this->partner('Aktif Atama Partneri');
        $firstLink = $this->link($firstPartner, $technician, true);
        $activeLink = $this->link($activePartner, $technician);
        $request = $this->job($technician, 'MRN-MULTI-PARTNER');
        $this->offer($request, $activeLink);

        return [$request, $firstLink, $activeLink];
    }

    private function reassign(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        B2BPartnerTechnician $link,
    ): void {
        $request->forceFill(['technical_service_technician_id' => $technician->id])->save();
        $this->offer($request, $link);
    }

    private function jobCardPath(TechnicalServiceRequest $request): string
    {
        $url = (string) app(B2BPartnerServiceJobScopeService::class)
            ->technicianJobCardContext($request)['canonical_url'];

        return (string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY);
    }

    private function opsSupportPath(TechnicalServiceRequest $request, B2BPartnerTechnician $link): string
    {
        return '/technical-service/ops-support/service-jobs?'.http_build_query([
            'partner_id' => $link->partner_id,
            'technician_id' => $link->technical_service_technician_id,
            'job_id' => $request->id,
        ]);
    }

    private function opsSupportApiPath(
        TechnicalServiceRequest $request,
        B2BPartnerTechnician $link,
        string $action = '',
    ): string {
        return '/api/technical-service/ops-support/service-jobs/'.$request->id.$action.'?'.http_build_query([
            'partner_id' => $link->partner_id,
            'technician_id' => $link->technical_service_technician_id,
        ]);
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
            'partner_code' => 'LOCK-'.strtoupper(substr(md5($name), 0, 8)),
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
        $user->forceFill(['username' => 'portal_'.$suffix])->save();
        B2BPartnerUserProfile::query()->create([
            'user_id' => $user->id,
            'partner_id' => $partner->id,
            'active' => true,
            'metadata' => [
                'technical_service_technician_id' => $technician->id,
                'source' => 'tenant_isolation_test',
            ],
        ]);
        foreach (['view', 'technical_service'] as $scope) {
            B2BPartnerUserAccess::query()->create([
                'user_id' => $user->id,
                'partner_id' => $partner->id,
                'access_scope' => $scope,
                'can_view' => true,
            ]);
        }

        return $user;
    }

    private function job(TechnicalServiceTechnician $technician, string $mrn): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create([
            'mrn' => $mrn,
            'customer_name' => 'Tenant Secret Customer',
            'customer_phone' => '+905550000001',
            'customer_city' => 'Denizli',
            'customer_district' => 'Pamukkale',
            'service_address' => 'Tenant secret address',
            'product_name' => 'Test Kilit',
            'service_type' => 'Montaj',
            'technical_service_technician_id' => $technician->id,
            'status' => 'Atandı',
            'workflow_status' => 'Usta Onayı Bekleyen',
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
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
}
