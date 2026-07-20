<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerUserAccess;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\Resource;
use App\Models\RoleResourcePermission;
use App\Models\User;
use App\Models\UserAccess;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PanelMetadataSeeder::class);
    }

    public function test_admin_logs_response_contains_readable_user_names(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $logUser = User::factory()->create([
            'full_name' => 'Log Owner',
            'username' => 'log.owner',
        ]);

        AuditLog::query()->create([
            'user_id' => $logUser->id,
            'action' => 'admin.test',
            'payload' => ['ok' => true],
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs')
            ->assertOk()
            ->assertJsonPath('logs.0.user_id', $logUser->id)
            ->assertJsonPath('logs.0.user_name', 'Log Owner')
            ->assertJsonPath('logs.0.username', 'log.owner')
            ->assertJsonPath('logs.0.action', 'admin.test');
    }

    public function test_admin_logs_use_safe_fallback_for_deleted_or_system_users(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        AuditLog::query()->create([
            'user_id' => 999999,
            'action' => 'admin.deleted-user',
            'payload' => [],
            'created_at' => now()->addSecond(),
        ]);

        AuditLog::query()->create([
            'user_id' => null,
            'action' => 'system.job',
            'payload' => [],
            'created_at' => now(),
        ]);

        $payload = $this->actingAs($admin)
            ->getJson('/api/admin/logs')
            ->assertOk()
            ->json('logs');

        $this->assertContains('Kullanıcı #999999', collect($payload)->pluck('user_name')->all());
        $this->assertContains('Sistem', collect($payload)->pluck('user_name')->all());
    }

    public function test_admin_can_clone_user_role_access_denies_and_new_password(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $source = User::factory()->create([
            'role_code' => 'sales',
            'password_hash' => Hash::make('source-password'),
            'temsilci_kodu' => '0024',
        ]);

        Resource::query()->updateOrCreate(
            ['code' => 'role_only_clone_area'],
            ['name' => 'Role Only Clone Area', 'type' => 'page', 'active' => true],
        );
        Resource::query()->updateOrCreate(
            ['code' => 'blocked_clone_area'],
            ['name' => 'Blocked Clone Area', 'type' => 'page', 'active' => true],
        );
        RoleResourcePermission::query()->updateOrCreate(
            ['role_code' => 'sales', 'resource_code' => 'role_only_clone_area'],
            ['can_view' => true, 'can_execute' => false],
        );

        UserAccess::query()->create([
            'user_id' => $source->id,
            'resource_code' => 'sales_main',
            'can_view' => true,
        ]);
        UserAccess::query()->create([
            'user_id' => $source->id,
            'resource_code' => 'stock',
            'can_view' => false,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$source->id}/clone", [
                'username' => 'clone.user',
                'full_name' => 'Clone User',
                'password' => 'new-password-123',
                'temsilci_kodu' => '0035',
                'aktif' => true,
                'force_password_change' => true,
            ])
            ->assertOk();

        $cloned = User::query()->where('username', 'clone.user')->firstOrFail();

        $this->assertSame('Clone User', $cloned->full_name);
        $this->assertSame($source->role_code, $cloned->role_code);
        $this->assertSame('0035', $cloned->temsilci_kodu);
        $this->assertTrue((bool) $cloned->aktif);
        $this->assertTrue((bool) $cloned->force_password_change);
        $this->assertNotSame($source->password_hash, $cloned->password_hash);
        $this->assertTrue(Hash::check('new-password-123', $cloned->password_hash));

        $this->assertDatabaseHas('panel.user_access', [
            'user_id' => $cloned->id,
            'resource_code' => 'sales_main',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('panel.user_access', [
            'user_id' => $cloned->id,
            'resource_code' => 'stock',
            'can_view' => false,
        ]);
        $this->assertDatabaseHas('panel.user_access', [
            'user_id' => $cloned->id,
            'resource_code' => 'role_only_clone_area',
            'can_view' => true,
        ]);
        $this->assertDatabaseHas('panel.user_access', [
            'user_id' => $cloned->id,
            'resource_code' => 'blocked_clone_area',
            'can_view' => false,
        ]);
        $this->assertDatabaseHas('panel.logs', [
            'user_id' => $admin->id,
            'action' => 'admin.user.clone',
        ]);
    }

    public function test_user_clone_requires_user_admin_access(): void
    {
        $source = User::factory()->create(['role_code' => 'sales']);
        $blocked = User::factory()->create(['role_code' => 'viewer']);

        UserAccess::query()->create([
            'user_id' => $blocked->id,
            'resource_code' => 'admin_panel',
            'can_view' => true,
        ]);

        $this->actingAs($blocked)
            ->postJson("/api/admin/users/{$source->id}/clone", [
                'username' => 'blocked.clone',
                'full_name' => 'Blocked Clone',
                'password' => 'new-password-123',
            ])
            ->assertForbidden();
    }

    public function test_bulent_saglam_rep_code_migration_updates_only_bulent(): void
    {
        $bulent = User::factory()->create([
            'full_name' => 'Bülent Sağlam',
            'username' => 'bulent.saglam',
            'temsilci_kodu' => '0024',
        ]);
        $salih = User::factory()->create([
            'full_name' => 'Salih İmal',
            'username' => 'salih.imal',
            'temsilci_kodu' => '0024',
        ]);

        $migration = require database_path('migrations/2026_05_11_120000_update_bulent_saglam_rep_code.php');
        $migration->up();

        $this->assertSame('0035', $bulent->refresh()->temsilci_kodu);
        $this->assertSame('0024', $salih->refresh()->temsilci_kodu);
    }

    public function test_bulent_sales_scope_hardcodes_use_0035_and_salih_stays_0024(): void
    {
        $service = file_get_contents(app_path('Services/SalesMainPageService.php')) ?: '';
        $seeder = file_get_contents(database_path('seeders/PanelMetadataSeeder.php')) ?: '';

        $this->assertStringContainsString("'0035' => 'sales_rep_bulent_saglam'", $service);
        $this->assertStringContainsString("'0024' => 'sales_rep_salih_cakir'", $service);
        $this->assertMatchesRegularExpression("/'key'\\s*=>\\s*'bulent_saglam'[\\s\\S]{0,300}'repCode'\\s*=>\\s*'0035'/", $service);
        $this->assertDoesNotMatchRegularExpression("/'key'\\s*=>\\s*'bulent_saglam'[\\s\\S]{0,300}'repCode'\\s*=>\\s*'0024'/", $service);

        $this->assertMatchesRegularExpression("/'key'\\s*=>\\s*'salih'[\\s\\S]{0,220}'repCode'\\s*=>\\s*'0024'/", $seeder);
        $this->assertMatchesRegularExpression("/'key'\\s*=>\\s*'bulent_saglam'[\\s\\S]{0,260}'repCode'\\s*=>\\s*'0035'/", $seeder);
        $this->assertDoesNotMatchRegularExpression("/'key'\\s*=>\\s*'bulent_saglam'[\\s\\S]{0,260}'repCode'\\s*=>\\s*'0024'/", $seeder);
    }

    public function test_admin_user_management_clone_ui_contract_exists(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/admin/AdminUsers.jsx')) ?: '';

        $this->assertStringContainsString('Kopyala', $component);
        $this->assertStringContainsString('/api/admin/users/${cloneSource.id}/clone', $component);
        $this->assertStringContainsString('Rol ve izinler kaynak kullanıcıdan kopyalanır', $component);
        $this->assertStringContainsString('Temsilci kodu yeni kullanıcı için ayrı girilir', $component);
        $this->assertStringContainsString('force_password_change: true', $component);
        $this->assertStringContainsString('strict_access: true', $component);
        $this->assertStringContainsString('Dar yetkiyi sabitle', $component);
        $this->assertStringContainsString('rol fallback fazladan alan açamaz', $component);
    }

    public function test_user_editor_uses_compact_inputs_and_only_multiline_fields_render_textarea(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/admin/AdminUsers.jsx')) ?: '';

        $this->assertStringContainsString('data-testid="admin-user-editor"', $component);
        $this->assertStringContainsString('className="h-10 rounded-lg border border-slate-200', $component);
        $this->assertStringContainsString('Temel Bilgiler', $component);
        $this->assertStringContainsString('Rol ve Durum', $component);
        $this->assertStringContainsString('Partner Atamaları', $component);
        $this->assertStringContainsString('Güvenlik', $component);
        $this->assertStringContainsString('İzinler', $component);
        $this->assertStringNotContainsString('<textarea', $component);
    }

    public function test_user_editor_has_internal_scroll_sticky_actions_and_responsive_sheet(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/admin/AdminUsers.jsx')) ?: '';

        $this->assertStringContainsString('data-testid="admin-users-workspace"', $component);
        $this->assertStringContainsString('data-testid="admin-users-list-panel"', $component);
        $this->assertStringContainsString('data-testid="admin-user-editor-scroll"', $component);
        $this->assertStringContainsString('flex-1 space-y-5 overflow-y-auto', $component);
        $this->assertStringContainsString('data-testid="admin-user-editor-actions"', $component);
        $this->assertStringContainsString('sticky bottom-0', $component);
        $this->assertStringContainsString('fixed inset-0 z-50', $component);
        $this->assertStringContainsString('sm:w-[460px]', $component);
        $this->assertStringContainsString('overflow-x-auto', $component);
    }

    public function test_user_editor_save_payload_permissions_and_unsaved_warning_contract_remain_intact(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/admin/AdminUsers.jsx')) ?: '';

        $this->assertStringContainsString("apiRequest('/api/admin/users'", $component);
        $this->assertStringContainsString('body: JSON.stringify(form)', $component);
        $this->assertStringContainsString("window.confirm('Kaydedilmemiş değişiklikler silinsin mi?')", $component);
        $this->assertStringContainsString('const state = accessState(resource.code);', $component);
        $this->assertStringContainsString('value={state}', $component);
        $this->assertStringContainsString('onChange={(event) => setAccessState(resource.code, event.target.value)}', $component);
        $this->assertStringContainsString('setFormBaseline(savedForm)', $component);
        $this->assertStringContainsString('Kullanıcı kaydedildi ve yetkileri güncellendi.', $component);
    }

    public function test_global_status_is_explicit_and_does_not_delete_partner_memberships(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $user = User::factory()->create(['role_code' => 'sales', 'aktif' => true]);
        $partner = $this->partner('STATUS-PARTNER', 'Durum Test Partneri', ['dealer']);
        B2BPartnerUserProfile::query()->create([
            'user_id' => $user->id,
            'partner_id' => $partner->id,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/users', [
                'id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'role_code' => $user->role_code,
                'aktif' => false,
                'force_password_change' => false,
                'access' => [],
                'denied_access' => [],
            ])
            ->assertOk();

        $this->assertFalse((bool) $user->fresh()->aktif);
        $this->assertDatabaseHas('b2b_partner_user_profiles', [
            'user_id' => $user->id,
            'partner_id' => $partner->id,
            'active' => true,
        ]);

        $component = file_get_contents(resource_path('js/pages/panel/admin/AdminUsers.jsx')) ?: '';
        $this->assertStringContainsString('Hesap: {form.aktif ?', $component);
        $this->assertStringContainsString('Hesap durumu', $component);
        $this->assertStringContainsString('partner üyelikleri audit için korunur', $component);
    }

    public function test_admin_users_filter_by_active_inactive_and_role(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $activeSales = User::factory()->create(['role_code' => 'sales', 'aktif' => true]);
        $inactiveViewer = User::factory()->create(['role_code' => 'viewer', 'aktif' => false]);

        $activeIds = collect($this->actingAs($admin)
            ->getJson('/api/admin/users?active=active')
            ->assertOk()
            ->json('users'))
            ->pluck('id');
        $inactiveIds = collect($this->actingAs($admin)
            ->getJson('/api/admin/users?active=inactive')
            ->assertOk()
            ->json('users'))
            ->pluck('id');
        $salesIds = collect($this->actingAs($admin)
            ->getJson('/api/admin/users?role_code=sales')
            ->assertOk()
            ->json('users'))
            ->pluck('id');

        $this->assertTrue($activeIds->contains($activeSales->id));
        $this->assertFalse($activeIds->contains($inactiveViewer->id));
        $this->assertTrue($inactiveIds->contains($inactiveViewer->id));
        $this->assertFalse($inactiveIds->contains($activeSales->id));
        $this->assertTrue($salesIds->contains($activeSales->id));
        $this->assertFalse($salesIds->contains($inactiveViewer->id));
    }

    public function test_admin_users_filter_by_partner_assignment_capabilities_and_partner_search(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $dealer = $this->partner('DEALER-A', 'Filtre Bayi A', ['dealer']);
        $locksmith = $this->partner('LOCK-B', 'Filtre Çilingir B', ['locksmith']);
        $multiCapabilityPartner = $this->partner('MULTI-C', 'Filtre Çoklu Partner C', ['dealer', 'locksmith']);
        $dealerOnly = User::factory()->create(['full_name' => 'Yalnız Bayi Kullanıcısı']);
        $multi = User::factory()->create(['full_name' => 'Bayi ve Çilingir Kullanıcısı']);
        $activeLocksmith = User::factory()->create(['full_name' => 'Aktif Çilingir Kullanıcısı']);
        $multiCapability = User::factory()->create(['full_name' => 'Çoklu Kabiliyet Kullanıcısı']);
        $unassigned = User::factory()->create(['full_name' => 'Atamasız Kullanıcı']);

        $this->profile($dealerOnly, $dealer, true);
        $this->profile($multi, $dealer, true);
        $this->profile($multi, $locksmith, false);
        $this->profile($activeLocksmith, $locksmith, true);
        $this->profile($multiCapability, $multiCapabilityPartner, true);

        $dealerIds = $this->filteredUserIds($admin, 'capabilities%5B%5D=dealer');
        $locksmithIds = $this->filteredUserIds($admin, 'capabilities%5B%5D=locksmith');
        $allIds = $this->filteredUserIds($admin, 'capabilities%5B%5D=dealer&capabilities%5B%5D=locksmith&capability_match=all');
        $excludedLocksmithIds = $this->filteredUserIds($admin, 'capabilities%5B%5D=locksmith&capability_match=exclude');
        $excludedDealerIds = $this->filteredUserIds($admin, 'capabilities%5B%5D=dealer&capability_match=exclude');
        $multipleIds = $this->filteredUserIds($admin, 'partner_assignment=multiple');
        $inactiveIds = $this->filteredUserIds($admin, 'partner_assignment=inactive');
        $unassignedIds = $this->filteredUserIds($admin, 'partner_assignment=unassigned');
        $specificIds = $this->filteredUserIds($admin, 'partner_id='.$dealer->id);
        $searchIds = $this->filteredUserIds($admin, 'search=Filtre%20%C3%87ilingir');

        $this->assertTrue($dealerIds->contains($dealerOnly->id), 'Dealer capability must include dealer-only user.');
        $this->assertTrue($dealerIds->contains($multi->id), 'Dealer capability must include multi-partner user.');
        $this->assertFalse($locksmithIds->contains($dealerOnly->id), 'Locksmith capability must exclude dealer-only user.');
        $this->assertFalse($locksmithIds->contains($multi->id), 'Inactive locksmith membership must not satisfy the capability filter.');
        $this->assertTrue($locksmithIds->contains($activeLocksmith->id), 'Active locksmith membership must satisfy the capability filter.');
        $this->assertFalse($allIds->contains($multi->id), 'All mode must ignore inactive memberships.');
        $this->assertTrue($allIds->contains($multiCapability->id), 'All mode must accept an active multi-capability partner.');
        $this->assertTrue($excludedLocksmithIds->contains($dealerOnly->id), 'Exclude mode must retain dealer-only user.');
        $this->assertTrue($excludedLocksmithIds->contains($multi->id), 'Inactive locksmith membership must not exclude the user.');
        $this->assertFalse($excludedLocksmithIds->contains($activeLocksmith->id), 'Active locksmith membership must exclude the user.');
        $this->assertFalse($excludedDealerIds->contains($dealerOnly->id), 'Active dealer membership must exclude the user.');
        $this->assertTrue($multipleIds->contains($multi->id), 'Multiple assignment filter must include multi-partner user.');
        $this->assertTrue($inactiveIds->contains($multi->id), 'Inactive membership filter must include matching user.');
        $this->assertTrue($unassignedIds->contains($unassigned->id), 'Unassigned filter must include unassigned user.');
        $this->assertFalse($unassignedIds->contains($dealerOnly->id), 'Unassigned filter must exclude assigned user.');
        $this->assertTrue($specificIds->contains($dealerOnly->id), 'Specific partner filter must include dealer-only user.');
        $this->assertTrue($specificIds->contains($multi->id), 'Specific partner filter must include multi-partner user.');
        $this->assertTrue($searchIds->contains($multi->id), 'Partner name search must include linked user.');
    }

    public function test_capability_filter_any_uses_only_active_memberships(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $dealer = $this->partner('CAP-ANY-D', 'Capability Any Dealer', ['dealer']);
        $locksmith = $this->partner('CAP-ANY-L', 'Capability Any Locksmith', ['locksmith']);
        $user = User::factory()->create(['full_name' => 'Capability Any Active Dealer Inactive Locksmith']);

        $this->profile($user, $dealer, true);
        $this->profile($user, $locksmith, false);

        $dealerPayload = $this->filteredUserPayload($admin, [
            'search' => $user->full_name,
            'capabilities' => ['dealer'],
            'capability_match' => 'any',
        ]);
        $locksmithPayload = $this->filteredUserPayload($admin, [
            'search' => $user->full_name,
            'capabilities' => ['locksmith'],
            'capability_match' => 'any',
        ]);

        $this->assertSame([$user->id], collect($dealerPayload['users'])->pluck('id')->all());
        $this->assertSame(1, $dealerPayload['meta']['filtered_total']);
        $this->assertSame([], collect($locksmithPayload['users'])->pluck('id')->all());
        $this->assertSame(0, $locksmithPayload['meta']['filtered_total']);
    }

    public function test_capability_filter_all_ignores_inactive_memberships(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $dealer = $this->partner('CAP-ALL-D', 'Capability All Dealer', ['dealer']);
        $locksmith = $this->partner('CAP-ALL-L', 'Capability All Locksmith', ['locksmith']);
        $user = User::factory()->create(['full_name' => 'Capability All Inactive Locksmith']);

        $this->profile($user, $dealer, true);
        $this->profile($user, $locksmith, false);

        $payload = $this->filteredUserPayload($admin, [
            'search' => $user->full_name,
            'capabilities' => ['dealer', 'locksmith'],
            'capability_match' => 'all',
        ]);

        $this->assertSame([], collect($payload['users'])->pluck('id')->all());
        $this->assertSame(0, $payload['meta']['filtered_total']);
    }

    public function test_capability_filter_exclude_ignores_inactive_memberships(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $dealer = $this->partner('CAP-EX-D', 'Capability Exclude Dealer', ['dealer']);
        $locksmith = $this->partner('CAP-EX-L', 'Capability Exclude Locksmith', ['locksmith']);
        $user = User::factory()->create(['full_name' => 'Capability Exclude Inactive Locksmith']);

        $this->profile($user, $dealer, true);
        $this->profile($user, $locksmith, false);

        $locksmithPayload = $this->filteredUserPayload($admin, [
            'search' => $user->full_name,
            'capabilities' => ['locksmith'],
            'capability_match' => 'exclude',
        ]);
        $dealerPayload = $this->filteredUserPayload($admin, [
            'search' => $user->full_name,
            'capabilities' => ['dealer'],
            'capability_match' => 'exclude',
        ]);

        $this->assertSame([$user->id], collect($locksmithPayload['users'])->pluck('id')->all());
        $this->assertSame(1, $locksmithPayload['meta']['filtered_total']);
        $this->assertSame([], collect($dealerPayload['users'])->pluck('id')->all());
        $this->assertSame(0, $dealerPayload['meta']['filtered_total']);
    }

    public function test_inactive_membership_filter_remains_independent_from_capability_filter(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $locksmith = $this->partner('INACTIVE-MEMBER-L', 'Inactive Membership Locksmith', ['locksmith']);
        $user = User::factory()->create(['full_name' => 'Inactive Membership Filter User']);

        $this->profile($user, $locksmith, false);

        $inactivePayload = $this->filteredUserPayload($admin, [
            'search' => $user->full_name,
            'partner_assignment' => 'inactive',
        ]);
        $combinedPayload = $this->filteredUserPayload($admin, [
            'search' => $user->full_name,
            'partner_assignment' => 'inactive',
            'capabilities' => ['locksmith'],
        ]);

        $this->assertSame([$user->id], collect($inactivePayload['users'])->pluck('id')->all());
        $this->assertSame(1, $inactivePayload['meta']['filtered_total']);
        $this->assertSame([], collect($combinedPayload['users'])->pluck('id')->all());
        $this->assertSame(0, $combinedPayload['meta']['filtered_total']);
    }

    public function test_capability_filter_all_accepts_one_or_multiple_active_memberships(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $dealer = $this->partner('CAP-VALID-D', 'Capability Valid Dealer', ['dealer']);
        $locksmith = $this->partner('CAP-VALID-L', 'Capability Valid Locksmith', ['locksmith']);
        $multiCapability = $this->partner('CAP-VALID-M', 'Capability Valid Multi', ['dealer', 'locksmith']);
        $acrossPartners = User::factory()->create(['full_name' => 'Capability Valid Across Partners']);
        $singlePartner = User::factory()->create(['full_name' => 'Capability Valid Single Partner']);

        $this->profile($acrossPartners, $dealer, true);
        $this->profile($acrossPartners, $locksmith, true);
        $this->profile($singlePartner, $multiCapability, true);

        $payload = $this->filteredUserPayload($admin, [
            'search' => 'Capability Valid',
            'capabilities' => ['dealer', 'locksmith'],
            'capability_match' => 'all',
        ]);

        $this->assertEqualsCanonicalizing(
            [$acrossPartners->id, $singlePartner->id],
            collect($payload['users'])->pluck('id')->all(),
        );
        $this->assertSame(2, $payload['meta']['filtered_total']);
    }

    public function test_capability_filter_ignores_memberships_to_inactive_partners(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $locksmith = $this->partner('CAP-INACTIVE-P', 'Capability Inactive Partner', ['locksmith']);
        $locksmith->update(['active' => false]);
        $user = User::factory()->create(['full_name' => 'Capability Inactive Partner User']);

        $this->profile($user, $locksmith, true);

        $includedPayload = $this->filteredUserPayload($admin, [
            'search' => $user->full_name,
            'capabilities' => ['locksmith'],
        ]);
        $excludedPayload = $this->filteredUserPayload($admin, [
            'search' => $user->full_name,
            'capabilities' => ['locksmith'],
            'capability_match' => 'exclude',
        ]);

        $this->assertSame([], collect($includedPayload['users'])->pluck('id')->all());
        $this->assertSame(0, $includedPayload['meta']['filtered_total']);
        $this->assertSame([$user->id], collect($excludedPayload['users'])->pluck('id')->all());
        $this->assertSame(1, $excludedPayload['meta']['filtered_total']);
    }

    public function test_capability_filter_query_count_is_bounded_as_results_grow(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $dealer = $this->partner('CAP-PERF-D', 'Capability Performance Dealer', ['dealer']);
        $locksmith = $this->partner('CAP-PERF-L', 'Capability Performance Locksmith', ['locksmith']);

        foreach (range(1, 5) as $index) {
            $user = User::factory()->create(['full_name' => sprintf('Capability Performance User %02d', $index)]);
            $this->profile($user, $dealer, true);
            $this->profile($user, $locksmith, $index % 2 === 0);
        }

        $query = [
            'search' => 'Capability Performance User',
            'capabilities' => ['dealer'],
            'per_page' => 100,
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fivePayload = $this->filteredUserPayload($admin, $query);
        $fiveQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(6, 20) as $index) {
            $user = User::factory()->create(['full_name' => sprintf('Capability Performance User %02d', $index)]);
            $this->profile($user, $dealer, true);
            $this->profile($user, $locksmith, $index % 2 === 0);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $twentyPayload = $this->filteredUserPayload($admin, $query);
        $twentyQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(5, $fivePayload['meta']['filtered_total']);
        $this->assertSame(20, $twentyPayload['meta']['filtered_total']);
        $this->assertLessThanOrEqual($fiveQueryCount + 1, $twentyQueryCount);
    }

    public function test_admin_user_filter_does_not_expose_hidden_partner_memberships(): void
    {
        $scopedAdmin = User::factory()->create(['role_code' => 'viewer']);
        foreach (['admin_panel', 'user_admin'] as $resourceCode) {
            UserAccess::query()->create([
                'user_id' => $scopedAdmin->id,
                'resource_code' => $resourceCode,
                'can_view' => true,
            ]);
        }

        $visible = $this->partner('VISIBLE', 'Görünür Partner', ['dealer']);
        $hidden = $this->partner('HIDDEN', 'Gizli Partner', ['locksmith']);
        B2BPartnerUserAccess::query()->create([
            'user_id' => $scopedAdmin->id,
            'partner_id' => $visible->id,
            'access_scope' => 'view',
            'can_view' => true,
            'can_create' => false,
            'can_update' => false,
            'can_approve' => false,
        ]);
        $target = User::factory()->create();
        $this->profile($target, $visible, true);
        $this->profile($target, $hidden, true);

        $payload = $this->actingAs($scopedAdmin)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->json();
        $targetPayload = collect($payload['users'])->firstWhere('id', $target->id);

        $this->assertSame([$visible->id], collect($payload['partners'])->pluck('id')->all());
        $this->assertSame([$visible->id], collect($targetPayload['partner_memberships'])->pluck('partner_id')->all());
        $this->assertStringNotContainsString('Gizli Partner', json_encode($payload, JSON_UNESCAPED_UNICODE));

        $filteredPayload = $this->filteredUserPayload($scopedAdmin, [
            'search' => $target->full_name,
            'capabilities' => ['locksmith'],
        ]);

        $this->assertSame([], collect($filteredPayload['users'])->pluck('id')->all());
        $this->assertSame(0, $filteredPayload['meta']['filtered_total']);
        $this->assertStringNotContainsString('Gizli Partner', json_encode($filteredPayload, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('HIDDEN', json_encode($filteredPayload, JSON_UNESCAPED_UNICODE));

        $this->actingAs($scopedAdmin)
            ->getJson('/api/admin/users?partner_id='.$hidden->id)
            ->assertForbidden();
    }

    public function test_effective_permission_labels_show_result_and_source_without_payload_changes(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/admin/AdminUsers.jsx')) ?: '';

        $this->assertStringContainsString("Rol kararını kullan — {roleAllowedResources.has(resource.code) ? 'Açık' : 'Kapalı'}", $component);
        $this->assertStringContainsString("Efektif: {effective ? 'Açık' : 'Kapalı'}", $component);
        $this->assertStringContainsString('Kaynak: {source}', $component);
        $this->assertStringContainsString("? 'Rol'", $component);
        $this->assertStringContainsString("? 'Kullanıcı izni'", $component);
        $this->assertStringContainsString(": 'Kullanıcı engeli'", $component);
        $this->assertStringContainsString('body: JSON.stringify(form)', $component);
    }

    public function test_admin_user_filter_toolbar_contract_is_complete(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/admin/AdminUsers.jsx')) ?: '';

        foreach ([
            'Ad, kullanıcı, temsilci veya partner ara',
            'Hesap durumu',
            'Tüm roller',
            'Partner atanmış',
            'Partner atanmamış',
            'Birden fazla partner',
            'Pasif üyeliği bulunan',
            'Partner kabiliyeti filtresi',
            'Dahil',
            'Tümü',
            'Hariç',
            'Belirli partner',
            'Filtreleri temizle',
            'Partner Atamaları',
        ] as $expected) {
            $this->assertStringContainsString($expected, $component);
        }
    }

    public function test_accounting_finance_resource_is_grouped_in_admin_users_response(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $resources = collect($this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->json('resources'));

        $accountingResource = $resources->firstWhere('code', 'accounting_finance_resmi_stok_kontrol');

        $this->assertNotNull($accountingResource);
        $this->assertSame('Muhasebe / Finans', $accountingResource['group']);
    }

    public function test_admin_users_group_order_and_select_all_include_accounting_finance(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/admin/AdminUsers.jsx')) ?: '';

        $this->assertMatchesRegularExpression(
            "/'Proforma',\\s*'Muhasebe \\/ Finans',\\s*'Destek',\\s*'Sistem Yönetimi'/",
            $component,
        );
        $this->assertStringContainsString('access: data.resources.map((resource) => resource.code)', $component);

        $admin = User::factory()->create(['role_code' => 'admin']);
        $selectAllAccess = collect($this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->json('resources'))
            ->pluck('code')
            ->values()
            ->all();

        $this->assertContains('accounting_finance_resmi_stok_kontrol', $selectAllAccess);
    }

    public function test_accounting_finance_number_parser_prevents_42_dot_00_becoming_4200(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/accounting-finance/resmi-stok-kontrol.tsx')) ?: '';

        $this->assertStringContainsString('function parseNumericValue(value: unknown): number', $component);
        $this->assertStringContainsString("const hasComma = cleaned.includes(',');", $component);
        $this->assertStringContainsString("const hasDot = cleaned.includes('.');", $component);
        $this->assertStringContainsString('const decimalSeparator = lastComma > lastDot', $component);
        $this->assertMatchesRegularExpression(
            '/function numberValue\(row: ApiRow, key: string\): number\s*{\s*return parseNumericValue\(row\[key\]\);\s*}/',
            $component,
        );

        preg_match(
            '/function numberValue\(row: ApiRow, key: string\): number\s*{(?P<body>[\s\S]*?)\n}/',
            $component,
            $matches,
        );

        $this->assertNotEmpty($matches['body'] ?? null);
        $this->assertStringNotContainsString("replace(/\\./g, '')", $matches['body']);
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function partner(string $code, string $name, array $capabilities): B2BPartner
    {
        $partner = B2BPartner::query()->create([
            'partner_type' => $capabilities[0] ?? B2BPartner::TYPE_DEALER,
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

    private function profile(User $user, B2BPartner $partner, bool $active): B2BPartnerUserProfile
    {
        return B2BPartnerUserProfile::query()->create([
            'user_id' => $user->id,
            'partner_id' => $partner->id,
            'active' => $active,
        ]);
    }

    private function filteredUserIds(User $admin, string $query): Collection
    {
        return collect($this->actingAs($admin)
            ->getJson('/api/admin/users?'.$query)
            ->assertOk()
            ->json('users'))
            ->pluck('id');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function filteredUserPayload(User $admin, array $query): array
    {
        return $this->actingAs($admin)
            ->getJson('/api/admin/users?'.http_build_query($query))
            ->assertOk()
            ->json();
    }
}
