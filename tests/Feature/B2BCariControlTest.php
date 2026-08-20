<?php

namespace Tests\Feature;

use App\Models\B2B\B2BCariSnapshot;
use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\DataSource;
use App\Models\Role;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\B2B\B2BCariControlService;
use Database\Seeders\B2BPartnerPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class B2BCariControlTest extends TestCase
{
    use RefreshDatabase;

    private const N8N_GATEWAY_TEST_URLS = [
        'customers_list' => 'https://n8n-gateway.example.test/webhook/cari-control',
        'customer_detail' => 'https://n8n-gateway.example.test/webhook/customer-detail',
        'cari_bilgi_dashboard' => 'https://n8n-gateway.example.test/webhook/cari-bilgi-dashboard',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_sync_apply_does_not_write_when_dry_run(): void
    {
        $this->seedB2BPartnerPermissions();
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
            ->assertJsonPath('items.0.partner_geocode_plan.status', 'ready')
            ->assertJsonPath('items.0.technician_geocode_plan.status', 'ready')
            ->assertJsonPath('summary.selected_count', 1)
            ->assertJsonPath('summary.partner_geocode_ready', 1)
            ->assertJsonPath('summary.technician_geocode_ready', 1)
            ->assertJsonPath('writes_performed', false);

        $this->assertSame($partnerCount, B2BPartner::query()->count());
        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
        $this->assertCariControlGatewaySourceOrder(['customer_detail', 'cari_bilgi_dashboard']);
    }

    public function test_cari_control_existing_partner_locksmith_checkbox_generates_role_update_preview(): void
    {
        $this->seedB2BPartnerPermissions();
        $admin = $this->admin();
        $partner = $this->partner([
            'display_name' => 'Bahattin Özbek',
            'mikro_cari_kodu' => '320.BAYI.BAHATTIN',
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);
        $technicianCount = TechnicalServiceTechnician::query()->count();
        $linkCount = B2BPartnerTechnician::query()->count();

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => true,
                'sync_technician' => false,
                'geocode_mode' => 'auto',
                'candidates' => [[
                    'existing_partner_id' => $partner->id,
                    'mikro_cari_kodu' => '320.BAYI.BAHATTIN',
                    'display_name' => 'Bahattin Özbek',
                    'city' => 'Ankara',
                    'address' => 'Partner adresi',
                    'selected_capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('items.0.partner_action', 'update_partner')
            ->assertJsonPath('items.0.role_changes.0', 'locksmith_added')
            ->assertJsonPath('items.0.technician_action', 'not_requested')
            ->assertJsonPath('items.0.link_action', 'not_requested')
            ->assertJsonPath('items.0.technician_geocode_plan.status', 'not_applicable');

        $this->assertEqualsCanonicalizing([B2BPartner::TYPE_DEALER], $partner->fresh()->capabilityCodes());
        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
    }

    public function test_cari_control_existing_partner_apply_adds_locksmith_role_idempotently_without_default_technician_sync(): void
    {
        $this->seedB2BPartnerPermissions();
        $admin = $this->admin();
        $partner = $this->partner([
            'display_name' => 'Bahattin Özbek',
            'mikro_cari_kodu' => '320.BAYI.BAHATTIN.IDEMPOTENT',
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);
        $partnerCount = B2BPartner::query()->count();
        $technicianCount = TechnicalServiceTechnician::query()->count();
        $linkCount = B2BPartnerTechnician::query()->count();
        $payload = [
            'action' => 'import',
            'dry_run' => false,
            'geocode_mode' => 'none',
            'candidates' => [[
                'existing_partner_id' => $partner->id,
                'mikro_cari_kodu' => '320.BAYI.BAHATTIN.IDEMPOTENT',
                'display_name' => 'Bahattin Özbek',
                'selected_capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
            ]],
        ];

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', $payload)
            ->assertOk()
            ->assertJsonPath('items.0.status', 'updated')
            ->assertJsonPath('items.0.role_changes.0', 'locksmith_added')
            ->assertJsonPath('items.0.technician_sync.status', 'not_requested');

        $this->assertSame($partnerCount, B2BPartner::query()->count());
        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
        $this->assertEqualsCanonicalizing([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH], $partner->fresh()->capabilityCodes());
        $this->assertDatabaseHas('b2b_partner_audit_logs', [
            'partner_id' => $partner->id,
            'action' => 'b2b.partner.capability_added',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', $payload)
            ->assertOk()
            ->assertJsonPath('items.0.role_changes', []);

        $this->assertSame($partnerCount, B2BPartner::query()->count());
        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
    }

    public function test_existing_partner_can_add_locksmith_role(): void
    {
        $this->seedB2BPartnerPermissions();
        $partner = $this->partner([
            'mikro_cari_kodu' => '320.BAYI.ADD-LOCKSMITH',
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => false,
                'sync_technician' => false,
                'geocode_mode' => 'none',
                'candidates' => [[
                    'existing_partner_id' => $partner->id,
                    'mikro_cari_kodu' => '320.BAYI.ADD-LOCKSMITH',
                    'display_name' => 'Role Add Partner',
                    'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.role_changes.0', 'locksmith_added');

        $this->assertEqualsCanonicalizing([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH], $partner->fresh()->capabilityCodes());
    }

    public function test_existing_dealer_partner_can_become_dealer_and_locksmith(): void
    {
        $this->seedB2BPartnerPermissions();
        $partner = $this->partner([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'mikro_cari_kodu' => '320.BAYI.MULTI-ROLE',
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => false,
                'geocode_mode' => 'none',
                'candidates' => [[
                    'existing_partner_id' => $partner->id,
                    'mikro_cari_kodu' => '320.BAYI.MULTI-ROLE',
                    'display_name' => 'Dealer Multi Role',
                    'selected_capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
                ]],
            ])
            ->assertOk();

        $partner->refresh();
        $this->assertSame(B2BPartner::TYPE_DEALER, $partner->partner_type);
        $this->assertEqualsCanonicalizing([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH], $partner->capabilityCodes());
    }

    public function test_adding_locksmith_role_does_not_duplicate_partner(): void
    {
        $this->seedB2BPartnerPermissions();
        $partner = $this->partner([
            'mikro_cari_kodu' => '320.BAYI.NO-DUP',
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => false,
                'geocode_mode' => 'none',
                'candidates' => [[
                    'mikro_cari_kodu' => '320.BAYI.NO-DUP',
                    'display_name' => 'No Duplicate Partner',
                    'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.partner_id', $partner->id);

        $this->assertSame(1, B2BPartner::query()->where('mikro_cari_kodu', '320.BAYI.NO-DUP')->count());
    }

    public function test_adding_locksmith_role_does_not_create_technician_unless_requested(): void
    {
        $this->seedB2BPartnerPermissions();
        $partner = $this->partner([
            'mikro_cari_kodu' => '320.BAYI.NO-TECH',
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);
        $technicianCount = TechnicalServiceTechnician::query()->count();
        $linkCount = B2BPartnerTechnician::query()->count();

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => false,
                'geocode_mode' => 'none',
                'candidates' => [[
                    'existing_partner_id' => $partner->id,
                    'mikro_cari_kodu' => '320.BAYI.NO-TECH',
                    'display_name' => 'No Technician Partner',
                    'city' => 'Ankara',
                    'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.technician_sync.status', 'not_requested');

        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
    }

    public function test_bahattin_add_locksmith_role_preserves_berkay_link_and_izmir_location(): void
    {
        $this->seedB2BPartnerPermissions();
        $admin = $this->admin();
        $partner = $this->partner([
            'display_name' => 'Bahattin Özbek',
            'mikro_cari_kodu' => '320.BAYI.BAHATTIN.BERKAY',
            'city' => 'Ankara',
            'address' => 'Bahattin partner adresi',
            'capabilities' => [B2BPartner::TYPE_DEALER],
        ]);
        $berkay = TechnicalServiceTechnician::query()->create([
            'name' => 'Berkay Atlas',
            'display_name' => 'Berkay Atlas',
            'technician_type' => 'locksmith',
            'phone' => '+905551230909',
            'city' => 'İzmir',
            'district' => 'Konak',
            'address' => 'İzmir usta adresi',
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $berkay->id,
            'relationship_type' => 'field_technician',
            'is_primary' => true,
            'active' => true,
            'source' => 'test',
            'service_city' => 'İzmir',
            'service_district' => 'Konak',
        ]);
        $technicianCount = TechnicalServiceTechnician::query()->count();
        $linkCount = B2BPartnerTechnician::query()->count();

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => false,
                'sync_technician' => false,
                'geocode_mode' => 'none',
                'candidates' => [[
                    'existing_partner_id' => $partner->id,
                    'mikro_cari_kodu' => '320.BAYI.BAHATTIN.BERKAY',
                    'display_name' => 'Bahattin Özbek',
                    'city' => 'Ankara',
                    'address' => 'Bahattin partner adresi',
                    'selected_capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.role_changes.0', 'locksmith_added')
            ->assertJsonPath('items.0.technician_sync.status', 'not_requested');

        $this->assertEqualsCanonicalizing([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH], $partner->fresh()->capabilityCodes());
        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
        $this->assertSame('İzmir', $berkay->fresh()->city);
        $this->assertSame('Konak', $berkay->fresh()->district);
    }

    public function test_explicit_technician_sync_creates_separate_bahattin_technician_without_moving_berkay(): void
    {
        $this->seedB2BPartnerPermissions();
        $admin = $this->admin();
        $partner = $this->partner([
            'display_name' => 'Bahattin Özbek',
            'mikro_cari_kodu' => '320.BAYI.BAHATTIN.SYNC',
            'city' => 'Ankara',
            'address' => 'Bahattin partner adresi',
            'capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
        ]);
        $berkay = TechnicalServiceTechnician::query()->create([
            'name' => 'Berkay Atlas',
            'display_name' => 'Berkay Atlas',
            'technician_type' => 'locksmith',
            'phone' => '+905551230910',
            'city' => 'İzmir',
            'district' => 'Konak',
            'address' => 'İzmir usta adresi',
            'active' => true,
        ]);
        $link = B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $berkay->id,
            'relationship_type' => 'field_technician',
            'is_primary' => true,
            'active' => true,
            'source' => 'test',
            'service_city' => 'İzmir',
            'service_district' => 'Konak',
        ]);
        $technicianCount = TechnicalServiceTechnician::query()->count();
        $linkCount = B2BPartnerTechnician::query()->count();

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => false,
                'sync_technician' => true,
                'geocode_mode' => 'none',
                'candidates' => [[
                    'existing_partner_id' => $partner->id,
                    'mikro_cari_kodu' => '320.BAYI.BAHATTIN.SYNC',
                    'display_name' => 'Bahattin Özbek',
                    'city' => 'Ankara',
                    'address' => 'Bahattin partner adresi',
                    'selected_capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.technician_sync.status', 'technician_created')
            ->assertJsonPath('items.0.technician_sync.ignored_technician_id', $berkay->id)
            ->assertJsonPath('items.0.technician_sync.ignored_technician_reason', 'different_linked_person')
            ->assertJsonPath('items.0.technician_geocode.status', 'skipped');

        $this->assertSame($technicianCount + 1, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount + 1, B2BPartnerTechnician::query()->count());
        $this->assertSame('İzmir', $berkay->fresh()->city);
        $this->assertSame('Konak', $berkay->fresh()->district);
        $this->assertDatabaseHas('technical_service_technicians', [
            'name' => 'Bahattin Özbek',
            'city' => 'Ankara',
        ]);
        $this->assertDatabaseHas('b2b_partner_technicians', [
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $link->technical_service_technician_id,
            'service_city' => 'İzmir',
        ]);
    }

    public function test_dry_run_locksmith_candidate_with_address_has_geocode_ready_plan(): void
    {
        $this->seedB2BPartnerPermissions();

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', $this->dryRunPayload([[
                'mikro_cari_kodu' => '320.CLG.GEO.READY',
                'display_name' => 'Adresli Çilingir',
                'phone' => '+905551235100',
                'city' => 'İstanbul',
                'district' => 'Kadıköy',
                'address' => 'Bağdat Caddesi No:1',
                'address_source' => 'Mikro cari adresi',
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            ]], 'auto'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('summary.partner_geocode_ready', 1)
            ->assertJsonPath('summary.technician_geocode_ready', 1)
            ->assertJsonPath('summary.technician_geocode_not_applicable', 0)
            ->assertJsonPath('items.0.partner_geocode_plan.status', 'ready')
            ->assertJsonPath('items.0.technician_geocode_plan.status', 'ready')
            ->assertJsonPath('items.0.technician_geocode_plan.query', 'Bağdat Caddesi No:1, Kadıköy, İstanbul, Türkiye')
            ->assertJsonPath('candidates.0.partner_geocode_plan.status', 'ready')
            ->assertJsonPath('candidates.0.technician_geocode_plan.status', 'ready');
    }

    public function test_dry_run_locksmith_candidate_with_plus_code_has_geocode_ready_plan(): void
    {
        $this->seedB2BPartnerPermissions();

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', $this->dryRunPayload([[
                'mikro_cari_kodu' => '320.CLG.GEO.PLUS',
                'display_name' => 'Plus Code Çilingir',
                'phone' => '+905551235101',
                'plus_code' => '8G9F+5W İstanbul',
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            ]], 'auto'))
            ->assertOk()
            ->assertJsonPath('summary.partner_geocode_ready', 1)
            ->assertJsonPath('summary.technician_geocode_ready', 1)
            ->assertJsonPath('items.0.partner_geocode_plan.status', 'ready')
            ->assertJsonPath('items.0.technician_geocode_plan.status', 'ready')
            ->assertJsonPath('items.0.technician_geocode_plan.source', 'plus_code')
            ->assertJsonPath('items.0.technician_geocode_plan.query', '8G9F+5W İstanbul');
    }

    public function test_dry_run_locksmith_candidate_without_address_has_geocode_warning(): void
    {
        $this->seedB2BPartnerPermissions();

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', $this->dryRunPayload([[
                'mikro_cari_kodu' => '320.CLG.GEO.WARNING',
                'display_name' => 'Adres Eksik Çilingir',
                'phone' => '+905551235102',
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            ]], 'auto'))
            ->assertOk()
            ->assertJsonPath('summary.partner_geocode_ready', 0)
            ->assertJsonPath('summary.partner_geocode_warning', 1)
            ->assertJsonPath('summary.technician_geocode_warning', 1)
            ->assertJsonPath('items.0.partner_geocode_plan.status', 'warning')
            ->assertJsonPath('items.0.technician_geocode_plan.status', 'warning')
            ->assertJsonPath('items.0.technician_geocode_plan.reason', 'Adres/konum eksik');
    }

    public function test_dry_run_dealer_only_candidate_with_address_is_geocode_not_applicable(): void
    {
        $this->seedB2BPartnerPermissions();

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', $this->dryRunPayload([[
                'mikro_cari_kodu' => '320.BAYI.GEO.NA',
                'display_name' => 'Adresli Bayi',
                'phone' => '+905551235103',
                'city' => 'Ankara',
                'address' => 'Tunali Hilmi Caddesi No:1',
                'selected_capabilities' => [B2BPartner::TYPE_DEALER],
            ]], 'auto'))
            ->assertOk()
            ->assertJsonPath('summary.partner_geocode_ready', 1)
            ->assertJsonPath('summary.technician_geocode_not_applicable', 1)
            ->assertJsonPath('items.0.partner_geocode_plan.status', 'ready')
            ->assertJsonPath('items.0.technician_geocode_plan.status', 'not_applicable')
            ->assertJsonPath('items.0.technician_geocode_plan.reason', 'Teknisyen oluşmayacağı için geocode uygulanmaz');
    }

    public function test_dry_run_dealer_candidate_without_address_has_partner_geocode_warning(): void
    {
        $this->seedB2BPartnerPermissions();

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', $this->dryRunPayload([[
                'mikro_cari_kodu' => '320.BAYI.GEO.WARNING',
                'display_name' => 'Adres Eksik Bayi',
                'phone' => '+905551235203',
                'selected_capabilities' => [B2BPartner::TYPE_DEALER],
            ]], 'auto'))
            ->assertOk()
            ->assertJsonPath('summary.partner_geocode_warning', 1)
            ->assertJsonPath('summary.technician_geocode_not_applicable', 1)
            ->assertJsonPath('items.0.partner_geocode_plan.status', 'warning')
            ->assertJsonPath('items.0.technician_geocode_plan.status', 'not_applicable');
    }

    public function test_dry_run_bayi_and_locksmith_candidate_with_address_has_both_partner_and_technician_geocode_ready(): void
    {
        $this->seedB2BPartnerPermissions();

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', $this->dryRunPayload([[
                'mikro_cari_kodu' => '320.BAYI.CLG.GEO.READY',
                'display_name' => 'Bayi Çilingir Hazır',
                'phone' => '+905551235204',
                'city' => 'İzmir',
                'district' => 'Konak',
                'address' => 'Kıbrıs Şehitleri Caddesi No:1',
                'selected_capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
            ]], 'auto'))
            ->assertOk()
            ->assertJsonPath('summary.partner_geocode_ready', 1)
            ->assertJsonPath('summary.technician_geocode_ready', 1)
            ->assertJsonPath('items.0.partner_geocode_plan.status', 'ready')
            ->assertJsonPath('items.0.technician_geocode_plan.status', 'ready');
    }

    public function test_dry_run_geocode_mode_none_marks_plan_skipped(): void
    {
        $this->seedB2BPartnerPermissions();

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', $this->dryRunPayload([[
                'mikro_cari_kodu' => '320.CLG.GEO.SKIP',
                'display_name' => 'Geocode Kapalı Çilingir',
                'phone' => '+905551235104',
                'city' => 'İzmir',
                'address' => 'Alsancak Mahallesi No:1',
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            ]], 'none'))
            ->assertOk()
            ->assertJsonPath('summary.partner_geocode_skipped', 1)
            ->assertJsonPath('summary.technician_geocode_skipped', 1)
            ->assertJsonPath('items.0.partner_geocode_plan.status', 'skipped')
            ->assertJsonPath('items.0.technician_geocode_plan.status', 'skipped')
            ->assertJsonPath('items.0.technician_geocode_plan.reason', 'Geocode modu kapalı');
    }

    public function test_dry_run_uses_selected_role_checkbox_not_only_classifier(): void
    {
        $this->seedB2BPartnerPermissions();

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', $this->dryRunPayload([[
                'mikro_cari_kodu' => '320.BAYI.SELECTED.CLG',
                'display_name' => 'Bayi Sınıflı Çilingir Seçimi',
                'phone' => '+905551235105',
                'city' => 'Bursa',
                'address' => 'Nilüfer Caddesi No:1',
                'suggested_capabilities' => [B2BPartner::TYPE_DEALER],
                'selected_capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
            ]], 'auto'))
            ->assertOk()
            ->assertJsonPath('items.0.roles.locksmith', true)
            ->assertJsonPath('items.0.technician_action', 'create_technician')
            ->assertJsonPath('summary.partner_geocode_ready', 1)
            ->assertJsonPath('summary.technician_geocode_ready', 1);
    }

    public function test_address_source_first_address_card_is_geocodeable(): void
    {
        $this->seedB2BPartnerPermissions();

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', $this->dryRunPayload([[
                'mikro_cari_kodu' => '320.CLG.GEO.FIRSTADDR',
                'display_name' => 'İlk Adres Kartı',
                'phone' => '+905551235106',
                'city' => 'Eskişehir',
                'full_address' => 'Yeni Mahalle No:46',
                'address_source' => 'ilk adres kartı',
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            ]], 'auto'))
            ->assertOk()
            ->assertJsonPath('summary.partner_geocode_ready', 1)
            ->assertJsonPath('summary.technician_geocode_ready', 1)
            ->assertJsonPath('items.0.address_source', 'ilk adres kartı')
            ->assertJsonPath('items.0.partner_geocode_plan.status', 'ready')
            ->assertJsonPath('items.0.technician_geocode_plan.status', 'ready');
    }

    public function test_dry_run_does_not_call_real_geocoder(): void
    {
        $this->seedB2BPartnerPermissions();
        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', $this->dryRunPayload([[
                'mikro_cari_kodu' => '320.CLG.GEO.NOHTTP',
                'display_name' => 'HTTP Yok Çilingir',
                'phone' => '+905551235107',
                'email' => 'no-http@example.test',
                'city' => 'İstanbul',
                'district' => 'Kadıköy',
                'address' => 'Test Sokak No:1',
                'tax_no' => '1234567890',
                'tax_office' => 'Kadıköy',
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            ]], 'auto'))
            ->assertOk()
            ->assertJsonPath('summary.partner_geocode_ready', 1)
            ->assertJsonPath('summary.technician_geocode_ready', 1);

        Http::assertNothingSent();
    }

    public function test_customers_list_tax_fields_are_normalized_from_mikro_cari_card_columns(): void
    {
        $candidate = app(B2BCariControlService::class)->normalizeCandidateInput([
            'musteri_kodu' => '320.CLG.TAX.001',
            'cari_unvan1' => 'Vergili Cari',
            'cari_VergiKimlikNo' => '6470242953',
            'cari_vdaire_no' => '1111111111',
            'cari_vdaire_adi' => 'M.KARAGÜZEL VERGİ DAİRESİ MÜD.',
            'cari_vergidairekodu' => '034567',
        ]);

        $this->assertSame('6470242953', $candidate['tax_no']);
        $this->assertSame('6470242953', $candidate['tax_number']);
        $this->assertSame('M.KARAGÜZEL VERGİ DAİRESİ MÜD.', $candidate['tax_office']);
        $this->assertSame('034567', $candidate['tax_office_code']);
        $this->assertSame('vkn', $candidate['tax_identity_type']);
    }

    public function test_customers_list_tax_number_falls_back_to_vdaire_no_only_when_valid(): void
    {
        $service = app(B2BCariControlService::class);

        $fallback = $service->normalizeCandidateInput([
            'musteri_kodu' => '320.CLG.TAX.002',
            'cari_unvan1' => 'Fallback Cari',
            'cari_vdaire_no' => '12345678901',
            'cari_vdaire_adi' => 'Kadıköy VD',
        ]);
        $invalid = $service->normalizeCandidateInput([
            'musteri_kodu' => '320.CLG.TAX.003',
            'cari_unvan1' => 'Eksik Cari',
            'cari_vdaire_no' => 'ABC-123',
        ]);

        $this->assertSame('12345678901', $fallback['tax_number']);
        $this->assertSame('tckn', $fallback['tax_identity_type']);
        $this->assertNull($invalid['tax_number']);
        $this->assertNull($invalid['tax_identity_type']);
    }

    public function test_dry_run_does_not_write_business_data(): void
    {
        $this->seedB2BPartnerPermissions();
        $admin = $this->admin();
        $partnerCount = B2BPartner::query()->count();
        $technicianCount = TechnicalServiceTechnician::query()->count();
        $linkCount = B2BPartnerTechnician::query()->count();

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', $this->dryRunPayload([[
                'mikro_cari_kodu' => '320.CLG.GEO.NOWRITE',
                'display_name' => 'Write Yok Çilingir',
                'phone' => '+905551235108',
                'city' => 'İstanbul',
                'address' => 'No Write Sokak No:1',
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            ]], 'auto'))
            ->assertOk()
            ->assertJsonPath('writes_performed', false);

        $this->assertSame($partnerCount, B2BPartner::query()->count());
        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
    }

    public function test_apply_partner_geocode_writes_partner_lat_lng_with_fake_geocoder(): void
    {
        $this->seedB2BPartnerPermissions();
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Bağdat Caddesi, Kadıköy/İstanbul, Türkiye',
                    'geometry' => [
                        'location_type' => 'ROOFTOP',
                        'location' => ['lat' => 40.982253, 'lng' => 29.057621],
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => false,
                'sync_technician' => true,
                'geocode_mode' => 'auto',
                'candidates' => [[
                    'mikro_cari_kodu' => '320.BAYI.GEO.APPLY',
                    'display_name' => 'Apply Bayi',
                    'phone' => '+905551235300',
                    'email' => 'apply-bayi@example.test',
                    'city' => 'İstanbul',
                    'district' => 'Kadıköy',
                    'address' => 'Bağdat Caddesi No:1',
                    'tax_no' => '1111111111',
                    'tax_office' => 'Kadıköy',
                    'selected_capabilities' => [B2BPartner::TYPE_DEALER],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('writes_performed', true)
            ->assertJsonPath('items.0.partner_geocode.status', 'ok')
            ->assertJsonPath('items.0.technician_sync.status', 'not_applicable');

        $partner = B2BPartner::query()->where('mikro_cari_kodu', '320.BAYI.GEO.APPLY')->firstOrFail();
        $this->assertSame('1111111111', $partner->tax_number);
        $this->assertSame('Kadıköy', $partner->tax_office);
        $this->assertSame('vkn', $partner->tax_identity_type);
        $this->assertSame('ok', $partner->geocode_status);
        $this->assertSame('mikro_address', $partner->location_source);
        $this->assertEqualsWithDelta(40.982253, (float) $partner->latitude, 0.000001);
        $this->assertEqualsWithDelta(29.057621, (float) $partner->longitude, 0.000001);
        $this->assertSame('ok', data_get($partner->metadata, 'geocode.status'));
        $this->assertSame(40.982253, data_get($partner->metadata, 'geocode.latitude'));
        $this->assertSame(29.057621, data_get($partner->metadata, 'geocode.longitude'));
    }

    public function test_apply_both_partner_and_technician_reuses_same_address_geocode_result(): void
    {
        $this->seedB2BPartnerPermissions();
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        $geocodeCalls = 0;
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => function () use (&$geocodeCalls) {
                $geocodeCalls++;

                return Http::response([
                    'status' => 'OK',
                    'results' => [[
                        'formatted_address' => 'Alsancak, Konak/İzmir, Türkiye',
                        'geometry' => [
                            'location_type' => 'ROOFTOP',
                            'location' => ['lat' => 38.439987, 'lng' => 27.143457],
                        ],
                    ]],
                ], 200);
            },
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => false,
                'sync_technician' => true,
                'geocode_mode' => 'auto',
                'candidates' => [[
                    'mikro_cari_kodu' => '320.BAYI.CLG.GEO.REUSE',
                    'display_name' => 'Reuse Bayi Çilingir',
                    'phone' => '+905551235301',
                    'email' => 'reuse@example.test',
                    'city' => 'İzmir',
                    'district' => 'Konak',
                    'address' => 'Alsancak Mahallesi No:1',
                    'tax_no' => '2222222222',
                    'tax_office' => 'Konak',
                    'selected_capabilities' => [B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.partner_geocode.status', 'ok')
            ->assertJsonPath('items.0.technician_geocode.status', 'ok');

        $this->assertSame(1, $geocodeCalls);
        $this->assertSame(1, B2BPartner::query()->where('mikro_cari_kodu', '320.BAYI.CLG.GEO.REUSE')->count());
        $this->assertSame(1, TechnicalServiceTechnician::query()->where('mikro_cari_kodu', '320.BAYI.CLG.GEO.REUSE')->count());
    }

    public function test_low_quality_partner_geocode_keeps_review_required(): void
    {
        $this->seedB2BPartnerPermissions();
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Türkiye',
                    'geometry' => [
                        'location_type' => 'APPROXIMATE',
                        'location' => ['lat' => 39.0, 'lng' => 35.0],
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => false,
                'sync_technician' => true,
                'geocode_mode' => 'auto',
                'candidates' => [[
                    'mikro_cari_kodu' => '320.BAYI.GEO.LOW',
                    'display_name' => 'Low Quality Bayi',
                    'phone' => '+905551235302',
                    'email' => 'low@example.test',
                    'city' => 'İstanbul',
                    'district' => 'Kadıköy',
                    'address' => 'Belirsiz Adres',
                    'tax_no' => '3333333333',
                    'tax_office' => 'Kadıköy',
                    'selected_capabilities' => [B2BPartner::TYPE_DEALER],
                ]],
            ])
            ->assertOk();

        $partner = B2BPartner::query()->where('mikro_cari_kodu', '320.BAYI.GEO.LOW')->firstOrFail();
        $this->assertSame('3333333333', $partner->tax_number);
        $this->assertSame('Kadıköy', $partner->tax_office);
        $this->assertSame('review_required', $partner->geocode_status);
        $this->assertTrue((bool) $partner->needs_review);
        $this->assertSame('review_required', data_get($partner->metadata, 'geocode.status'));
        $this->assertTrue((bool) data_get($partner->metadata, 'geocode.needs_review'));
    }

    public function test_existing_partner_tax_and_coordinates_are_not_overwritten_without_override(): void
    {
        $this->seedB2BPartnerPermissions();
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'partner_code' => 'EXISTING-TAX-GEO',
            'display_name' => 'Mevcut Vergi Partner',
            'mikro_cari_kodu' => '320.BAYI.GEO.EXISTING',
            'tax_number' => '9999999999',
            'tax_office' => 'Manuel VD',
            'latitude' => 41.1,
            'longitude' => 29.2,
            'location_source' => 'manual',
            'active' => true,
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => false,
                'sync_technician' => true,
                'geocode_mode' => 'auto',
                'candidates' => [[
                    'existing_partner_id' => $partner->id,
                    'mikro_cari_kodu' => '320.BAYI.GEO.EXISTING',
                    'display_name' => 'Mevcut Vergi Partner',
                    'city' => 'İstanbul',
                    'address' => 'Yeni Adres No:1',
                    'tax_no' => '1111111111',
                    'tax_office' => 'Yeni VD',
                    'selected_capabilities' => [B2BPartner::TYPE_DEALER],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.partner_geocode.status', 'skipped_existing_coordinates');

        $partner->refresh();
        $this->assertSame('9999999999', $partner->tax_number);
        $this->assertSame('Manuel VD', $partner->tax_office);
        $this->assertEqualsWithDelta(41.1, (float) $partner->latitude, 0.000001);
        $this->assertEqualsWithDelta(29.2, (float) $partner->longitude, 0.000001);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'maps.googleapis.com/maps/api/geocode'));
    }

    public function test_bulk_select_geocode_summary_counts_all_selected_candidates(): void
    {
        $this->seedB2BPartnerPermissions();

        $this->actingAs($this->admin())
            ->postJson('/api/b2b/cari-control/apply', $this->dryRunPayload([
                [
                    'mikro_cari_kodu' => '320.CLG.GEO.BULKREADY',
                    'display_name' => 'Bulk Hazır',
                    'city' => 'İstanbul',
                    'address' => 'Hazır Sokak No:1',
                    'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                ],
                [
                    'mikro_cari_kodu' => '320.CLG.GEO.BULKWARN',
                    'display_name' => 'Bulk Uyarı',
                    'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                ],
                [
                    'mikro_cari_kodu' => '320.BAYI.GEO.BULKNA',
                    'display_name' => 'Bulk Bayi',
                    'city' => 'Ankara',
                    'address' => 'Bayi Sokak No:1',
                    'selected_capabilities' => [B2BPartner::TYPE_DEALER],
                ],
            ], 'auto'))
            ->assertOk()
            ->assertJsonPath('summary.selected_count', 3)
            ->assertJsonPath('summary.partner_geocode_ready', 2)
            ->assertJsonPath('summary.partner_geocode_warning', 1)
            ->assertJsonPath('summary.technician_geocode_ready', 1)
            ->assertJsonPath('summary.technician_geocode_warning', 1)
            ->assertJsonPath('summary.technician_geocode_not_applicable', 1)
            ->assertJsonPath('summary.technician_geocode_skipped', 0);
    }

    public function test_dry_run_handles_current_filtered_locksmith_batch_under_limit(): void
    {
        $this->seedB2BPartnerPermissions();
        $admin = $this->admin();
        $partnerCount = B2BPartner::query()->count();
        $technicianCount = TechnicalServiceTechnician::query()->count();
        $linkCount = B2BPartnerTechnician::query()->count();
        $candidates = collect(range(1, 67))
            ->map(fn (int $index): array => [
                'mikro_cari_kodu' => sprintf('320.CLG.FILTERED.%03d', $index),
                'display_name' => 'Filtreli Çilingir '.$index,
                'phone' => '+90555125'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'city' => 'İstanbul',
                'district' => 'Kadıköy',
                'address' => 'Filtreli Mahallesi No:'.$index,
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            ])
            ->all();

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => true,
                'sync_technician' => true,
                'geocode_mode' => 'auto',
                'candidates' => $candidates,
            ])
            ->assertOk()
            ->assertJsonPath('summary.selected_count', 67)
            ->assertJsonPath('summary.partner_geocode_ready', 67)
            ->assertJsonPath('summary.technician_geocode_ready', 67)
            ->assertJsonPath('summary.technician_geocode_warning', 0)
            ->assertJsonPath('summary.technician_geocode_not_applicable', 0)
            ->assertJsonPath('writes_performed', false);

        $this->assertSame($partnerCount, B2BPartner::query()->count());
        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
    }

    public function test_dry_run_uses_snapshot_for_code_only_select_all_payload(): void
    {
        $this->seedB2BPartnerPermissions();
        $admin = $this->admin();
        $partnerCount = B2BPartner::query()->count();
        $technicianCount = TechnicalServiceTechnician::query()->count();
        $linkCount = B2BPartnerTechnician::query()->count();

        collect(range(1, 67))->each(function (int $index): void {
            B2BCariSnapshot::query()->create([
                'source_code' => 'customers_list',
                'base_mikro_cari_kodu' => sprintf('320.CLG.SNAPSHOT.%03d', $index),
                'mikro_cari_kodu' => sprintf('320.CLG.SNAPSHOT.%03d', $index),
                'mikro_cari_unvan' => 'Snapshot Çilingir '.$index,
                'normalized_unvan' => 'SNAPSHOT CILINGIR '.$index,
                'phone' => '+90555126'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'city' => 'İstanbul',
                'district' => 'Kadıköy',
                'address' => 'Snapshot Mahallesi No:'.$index,
                'suggested_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                'candidate_status' => 'new',
                'last_seen_at' => now(),
                'payload_hash' => hash('sha256', 'snapshot-'.$index),
                'raw_payload' => [
                    'address_source' => 'ilk adres kartı',
                ],
            ]);
        });

        $candidates = collect(range(1, 67))
            ->map(fn (int $index): array => [
                'mikro_cari_kodu' => sprintf('320.CLG.SNAPSHOT.%03d', $index),
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
            ])
            ->all();

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => true,
                'sync_technician' => true,
                'geocode_mode' => 'auto',
                'candidates' => $candidates,
            ])
            ->assertOk()
            ->assertJsonPath('summary.selected_count', 67)
            ->assertJsonPath('summary.partner_geocode_ready', 67)
            ->assertJsonPath('summary.technician_geocode_ready', 67)
            ->assertJsonPath('writes_performed', false);

        $this->assertSame($partnerCount, B2BPartner::query()->count());
        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
    }

    public function test_apply_blocks_over_safe_batch_limit(): void
    {
        $this->seedB2BPartnerPermissions();
        $admin = $this->admin();
        $candidates = collect(range(1, 51))
            ->map(fn (int $index): array => [
                'mikro_cari_kodu' => sprintf('320.CLG.BULK.%03d', $index),
                'display_name' => 'Toplu Çilingir '.$index,
                'phone' => '+90555124'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'city' => 'İstanbul',
                'address' => 'Toplu Mahallesi No:'.$index,
            ])
            ->all();

        $this->actingAs($admin)
            ->postJson('/api/b2b/cari-control/apply', [
                'action' => 'import',
                'dry_run' => false,
                'selected_capabilities' => [B2BPartner::TYPE_LOCKSMITH],
                'sync_technician' => true,
                'geocode_mode' => 'none',
                'candidates' => $candidates,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.candidates.0', 'Tek seferde en fazla 50 aday işlenebilir. Filtreyi daraltın veya parça parça ilerleyin.');

        $this->assertSame(0, B2BPartner::query()->where('mikro_cari_kodu', 'like', '320.CLG.BULK.%')->count());
        $this->assertSame(0, TechnicalServiceTechnician::query()->where('mikro_cari_kodu', 'like', '320.CLG.BULK.%')->count());
    }

    public function test_select_all_selects_current_filtered_candidates(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('const CARI_CONTROL_DRY_RUN_LIMIT = 250', $source);
        $this->assertStringContainsString('const CARI_CONTROL_APPLY_LIMIT = 50', $source);
        $this->assertStringContainsString('selectAllCurrentCariCandidates', $source);
        $this->assertStringContainsString('currentSelectableCariCandidates', $source);
        $this->assertStringContainsString('const nextCandidates = currentSelectableCariCandidates', $source);
        $this->assertStringContainsString('setSelectedCariCodes(nextCandidates.map', $source);
        $this->assertStringContainsString('Tümünü seç', $source);
    }

    public function test_select_all_does_not_select_ineligible_candidates(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('const candidateIsSelectable = (candidate: CariControlCandidate): boolean', $source);
        $this->assertStringContainsString('cariCandidates.filter(candidateIsSelectable)', $source);
        $this->assertStringContainsString('currentIneligibleCariCount', $source);
        $this->assertStringContainsString('disabled={!actionsEnabled || !candidateIsSelectable(candidate)}', $source);
        $this->assertStringContainsString('Uygun olmayan: {currentIneligibleCariCount}', $source);
    }

    public function test_clear_selection_resets_selected_candidates_and_dry_run(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('const clearCariSelection = () => {', $source);
        $this->assertStringContainsString('setSelectedCariCodes([])', $source);
        $this->assertStringContainsString('setSelectedCariCandidates({})', $source);
        $this->assertStringContainsString('setCariDryRunResult(null)', $source);
        $this->assertStringContainsString('Tümünü kaldır', $source);
    }

    public function test_changing_filter_invalidates_selection_or_reconciles_safely(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('setSelectedCariCodes((current) => current.filter', $source);
        $this->assertStringContainsString('setSelectedCariCandidates((current) => Object.fromEntries', $source);
        $this->assertStringContainsString('setCariSearch(event.target.value)', $source);
        $this->assertStringContainsString('setCariCapabilityFilter(event.target.value as', $source);
        $this->assertStringContainsString('setCariStatusFilter(event.target.value as CariControlStatusFilter)', $source);
        $this->assertStringContainsString('clearCariSelection()', $source);
    }

    public function test_dry_run_after_select_all_sends_all_selected_candidate_keys(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('const candidates = selectedCariItems', $source);
        $this->assertStringContainsString('const cariCandidateApplyPayload = (candidate: CariControlCandidate, selectedCapabilities: PartnerType[]) => ({', $source);
        $this->assertStringContainsString('candidates: candidates.map((candidate) => cariCandidateApplyPayload(', $source);
        $this->assertStringContainsString('selected_capabilities: selectedCapabilities', $source);
        $this->assertStringNotContainsString('...candidate', $source);
        $this->assertStringContainsString('signature: selectedCariSignature', $source);
    }

    public function test_apply_stays_disabled_until_dry_run_after_select_all(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('!dryRunIsCurrent', $source);
        $this->assertStringContainsString('Önce seçili adaylar için dry-run önizlemesi çalıştırın.', $source);
        $this->assertStringContainsString('disabled={saving || !actionsEnabled || selectedCariCodes.length === 0 || !dryRunIsCurrent}', $source);
        $this->assertStringContainsString('Apply için güncel dry-run gerekli.', $source);
    }

    public function test_apply_requires_confirmation_for_bulk_selection(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('window.confirm', $source);
        $this->assertStringContainsString('const operationScope = cariSyncTechnician', $source);
        $this->assertStringContainsString('Partner/teknisyen/link değişiklikleri yapılacak.', $source);
        $this->assertStringContainsString('Partner rol ve cari bilgisi değişiklikleri yapılacak; teknisyen oluşturulmayacak.', $source);
        $this->assertStringContainsString('Toplu işlem yapıyorsun. Önce dry-run sonucunu kontrol et.', $source);
    }

    public function test_cari_control_ui_separates_role_update_from_technician_sync(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('const [cariSyncTechnician, setCariSyncTechnician] = useState(false)', $source);
        $this->assertStringContainsString('sync_technician: cariSyncTechnician', $source);
        $this->assertStringContainsString('Teknisyen oluştur/eşleştir', $source);
        $this->assertStringContainsString('sync_technician: cariSyncTechnician', $source);
        $this->assertStringContainsString('Rol değişimi: {(item.role_changes ?? []).join', $source);
        $this->assertStringContainsString('syncPreviewActionLabel(cariSyncTechnician ? candidate.sync_preview.technician_action : \'not_requested\')', $source);
        $this->assertStringContainsString("cariSyncTechnician\n                                    ? (candidate.sync_preview.technician_geocode_plan?.message", $source);
        $this->assertStringNotContainsString('sync_technician: true,', $source);
    }

    public function test_selected_chip_area_collapses_when_many_selected(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('const SELECTED_CARI_CHIP_LIMIT = 10', $source);
        $this->assertStringContainsString('Seçili: {selectedCariCodes.length}', $source);
        $this->assertStringContainsString('selectedCariChipItems', $source);
        $this->assertStringContainsString('selectedCariItems.slice(0, SELECTED_CARI_CHIP_LIMIT)', $source);
        $this->assertStringContainsString('+{selectedCariOverflowCount} aday daha', $source);
    }

    public function test_select_all_no_db_write(): void
    {
        $source = $this->b2bPartnersPageSource();
        preg_match('/const selectAllCurrentCariCandidates = \(\) => \{(?<body>.*?)\n  \}/s', $source, $matches);

        $this->assertNotEmpty($matches['body'] ?? null);
        $this->assertStringNotContainsString('apiRequest', $matches['body']);
        $this->assertStringNotContainsString('fetch(', $matches['body']);
        $this->assertStringContainsString('setSelectedCariCodes(nextCandidates.map', $matches['body']);
        $this->assertStringContainsString('setCariDryRunResult(null)', $source);
    }

    public function test_dry_run_summary_counts_are_visible_after_select_all_preview(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('Dry-run sonucu', $source);
        $this->assertStringContainsString('Partner:', $source);
        $this->assertStringContainsString('Teknisyen:', $source);
        $this->assertStringContainsString('Bağ:', $source);
        $this->assertStringContainsString('Partner geocode: Hazır {dryRunSummary.partnerGeocodeReady}', $source);
        $this->assertStringContainsString('Teknisyen geocode: Hazır {dryRunSummary.technicianGeocodeReady}', $source);
        $this->assertStringContainsString('Uygulanmaz {dryRunSummary.technicianGeocodeNotApplicable}', $source);
        $this->assertStringContainsString('Atlandı {dryRunSummary.technicianGeocodeSkipped}', $source);
        $this->assertStringContainsString('Geocode yapılmayacak.', $source);
    }

    public function test_old_single_geocode_uygulanmaz_not_shown_when_partner_ready(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringNotContainsString('Geocode plan: Hazır', $source);
        $this->assertStringNotContainsString('Geocode: {candidate.sync_preview.geocode_plan', $source);
        $this->assertStringContainsString('Partner geocode: {geocodePlanStatusLabel(partnerPlan?.status)}', $source);
        $this->assertStringContainsString('Teknisyen geocode: {geocodePlanStatusLabel(technicianPlan?.status)}', $source);
    }

    public function test_select_all_dry_run_button_uses_resolved_selected_candidates(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('selectedCariCandidates[code] ?? cariCandidateByCode[code]', $source);
        $this->assertStringContainsString('const canRunCariDryRun = actionsEnabled', $source);
        $this->assertStringContainsString('disabled={saving || !canRunCariDryRun}', $source);
        $this->assertStringNotContainsString('&& selectedCariItems.length <= CARI_CONTROL_DRY_RUN_LIMIT', $source);
        $this->assertStringContainsString('Tek seferde en fazla ${CARI_CONTROL_DRY_RUN_LIMIT} aday için dry-run yapılabilir.', $source);
        $this->assertStringContainsString('geocode_mode: cariGeocodeMode', $source);
        $this->assertStringNotContainsString('geocode_mode: dryRun && cariGeocodeMode === \'auto\' ? \'dry_run\' : cariGeocodeMode', $source);
    }

    public function test_changing_role_invalidates_previous_dry_run(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('const toggleCandidateCapability = (mikroCariKodu: string, capability: PartnerType) => {', $source);
        $this->assertStringContainsString('setCariDryRunResult(null)', $source);
    }

    public function test_cari_control_open_uses_snapshot_without_forced_refresh(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('void runCariControl({ search: nextSearch, resetSelection: true })', $source);
        $this->assertStringContainsString('if (options.refresh === true)', $source);
        $this->assertStringNotContainsString('options.refresh ?? options.resetSelection', $source);
        $this->assertStringContainsString('Yeniden yükle', $source);
        $this->assertStringContainsString('void runCariControl({ search, refresh: true })', $source);
    }

    public function test_partner_tax_and_geocode_fields_are_visible_in_partner_ui(): void
    {
        $source = $this->b2bPartnersPageSource();

        $this->assertStringContainsString('Vergi no', $source);
        $this->assertStringContainsString('Vergi dairesi', $source);
        $this->assertStringContainsString('Konum / geocode', $source);
        $this->assertStringContainsString('Google ile koordinatı güncelle', $source);
        $this->assertStringContainsString('Kontrol edildi', $source);
        $this->assertStringContainsString('coordinateLabel(partner.latitude, partner.longitude)', $source);
        $this->assertStringContainsString('candidateTaxLabel(candidate)', $source);
    }

    private function seedB2BPartnerPermissions(): void
    {
        (new B2BPartnerPermissionSeeder)->run();

        foreach (array_keys(self::N8N_GATEWAY_TEST_URLS) as $sourceCode) {
            $this->dataSource($sourceCode);
        }

        $emptyResponse = Http::response([
            'ok' => true,
            'rows' => [],
        ]);

        Http::fake([
            self::N8N_GATEWAY_TEST_URLS['customers_list'] => $this->validatedN8nGatewayResponse('customers_list', $emptyResponse),
            self::N8N_GATEWAY_TEST_URLS['customer_detail'] => $this->validatedN8nGatewayResponse('customer_detail', $emptyResponse),
            self::N8N_GATEWAY_TEST_URLS['cari_bilgi_dashboard'] => $this->validatedN8nGatewayResponse('cari_bilgi_dashboard', $emptyResponse),
        ]);
    }

    private function dataSource(string $code): DataSource
    {
        $statementKey = implode('_', ['query', 'template']);
        $allowedKey = implode('_', ['allowed', 'params']);
        $metaKey = implode('_', ['connection', 'meta']);
        $endpointUrl = self::N8N_GATEWAY_TEST_URLS[$code]
            ?? throw new \InvalidArgumentException('Unexpected test data source ['.$code.'].');

        return DataSource::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => 'Test '.$code,
                'db_type' => 'n8n_json',
                $statementKey => 'SELECT 1',
                $allowedKey => ['search', 'scope_key', 'customer_scope_key', 'page', 'limit', 'bypass_cache'],
                $metaKey => [
                    'endpoint_url' => $endpointUrl,
                    'response_rows_key' => 'rows',
                    'timeout_seconds' => 10,
                ],
                'preview_payload' => [],
                'active' => true,
            ],
        );
    }

    private function validatedN8nGatewayResponse(string $sourceCode, mixed $response): \Closure
    {
        return function (Request $request) use ($sourceCode, $response): mixed {
            $this->assertSame(self::N8N_GATEWAY_TEST_URLS[$sourceCode], $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));
            $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));
            $this->assertSame($sourceCode, $request['source_code'] ?? null);
            $this->assertSame('all', $request['scope_key'] ?? null);
            $this->assertSame('bayi_proje', $request['params']['customer_scope_key'] ?? null);
            $this->assertSame($sourceCode === 'customers_list' ? 1000 : 10, $request['limit'] ?? null);
            $this->assertSame(1, $request['params']['page'] ?? null);
            $this->assertTrue((bool) ($request['bypass_cache'] ?? false));

            return $response;
        };
    }

    /**
     * @param  array<int, string>  $expectedSourceCodes
     */
    private function assertCariControlGatewaySourceOrder(array $expectedSourceCodes): void
    {
        $requests = Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_starts_with($request->url(), 'https://n8n-gateway.example.test/'))
            ->values();

        $this->assertCount(count($expectedSourceCodes), $requests);
        $this->assertSame(
            $expectedSourceCodes,
            $requests->map(fn (Request $request): mixed => $request['source_code'] ?? null)->all(),
        );
    }

    private function b2bPartnersPageSource(): string
    {
        return file_get_contents(resource_path('js/pages/panel/b2b/partners.tsx')) ?: '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function dryRunPayload(array $candidates, string $geocodeMode): array
    {
        return [
            'action' => 'import',
            'dry_run' => true,
            'sync_technician' => true,
            'geocode_mode' => $geocodeMode,
            'candidates' => $candidates,
        ];
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
            'partner_code' => sprintf('B2B-CARI-%03d', $sequence),
            'display_name' => 'Cari Test Partner '.$sequence,
            'mikro_cari_kodu' => 'CR-CARI-'.$sequence,
            'city' => 'İstanbul',
            'district' => 'Kadıköy',
            'active' => true,
        ], $attributes));

        foreach (array_unique($capabilities) as $capability) {
            $partner->capabilities()->create([
                'capability' => $capability,
                'active' => true,
            ]);
        }

        return $partner->load('capabilities');
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
