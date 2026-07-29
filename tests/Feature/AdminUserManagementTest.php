<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerUserAccess;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\Resource;
use App\Models\Role;
use App\Models\RoleResourcePermission;
use App\Models\User;
use App\Models\UserAccess;
use App\Services\PanelAccessService;
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

    public function test_delegated_admin_cannot_create_user_with_flagged_super_role(): void
    {
        $superRole = $this->flaggedSuperRole();
        $actor = $this->delegatedUserAdmin();
        $before = $this->adminUserMutationFootprint();

        $response = $this->actingAs($actor)->postJson('/api/admin/users', [
            'username' => 'blocked.super.create',
            'full_name' => 'Blocked Super Create',
            'password' => 'new-password-123',
            'role_code' => $superRole->code,
            'temsilci_kodu' => 'S001',
            'aktif' => true,
            'force_password_change' => true,
            'access' => ['admin_panel', 'user_admin'],
            'denied_access' => [],
            'strict_access' => false,
        ]);

        $response->assertForbidden();
        $this->assertStringNotContainsString($superRole->code, $response->getContent());
        $this->assertDatabaseMissing('panel.users', ['username' => 'blocked.super.create']);
        $this->assertSame($before, $this->adminUserMutationFootprint());
    }

    public function test_delegated_admin_cannot_promote_normal_user_to_flagged_super_role(): void
    {
        $superRole = $this->flaggedSuperRole();
        $actor = $this->delegatedUserAdmin();
        $target = User::factory()->create([
            'role_code' => 'sales',
            'password_hash' => Hash::make('original-password'),
            'temsilci_kodu' => 'N001',
            'aktif' => true,
            'force_password_change' => false,
        ]);
        UserAccess::query()->create([
            'user_id' => $target->id,
            'resource_code' => 'sales_main',
            'can_view' => true,
        ]);

        $this->assertDeniedUserSaveLeavesNoMutation($actor, $target, [
            'role_code' => $superRole->code,
            'full_name' => 'Mutated Promotion Name',
            'password' => 'mutated-password-123',
            'temsilci_kodu' => 'M999',
            'aktif' => false,
            'force_password_change' => true,
            'access' => ['admin_panel'],
            'denied_access' => ['sales_main'],
        ], $superRole->code);
    }

    public function test_delegated_admin_cannot_promote_itself_to_flagged_super_role(): void
    {
        $superRole = $this->flaggedSuperRole();
        $actor = $this->delegatedUserAdmin();

        $this->assertDeniedUserSaveLeavesNoMutation($actor, $actor, [
            'role_code' => $superRole->code,
            'full_name' => 'Mutated Self Promotion',
            'password' => 'mutated-password-123',
            'aktif' => false,
            'access' => [],
            'denied_access' => ['admin_panel', 'user_admin'],
        ], $superRole->code);
    }

    public function test_delegated_admin_cannot_edit_existing_flagged_superadmin(): void
    {
        $superRole = $this->flaggedSuperRole();
        $actor = $this->delegatedUserAdmin();
        $target = User::factory()->create([
            'role_code' => $superRole->code,
            'password_hash' => Hash::make('original-password'),
            'aktif' => true,
        ]);
        UserAccess::query()->create([
            'user_id' => $target->id,
            'resource_code' => 'sales_main',
            'can_view' => true,
        ]);

        $this->assertDeniedUserSaveLeavesNoMutation($actor, $target, [
            'role_code' => $superRole->code,
            'full_name' => 'Mutated Existing Super',
            'password' => 'mutated-password-123',
            'aktif' => false,
            'force_password_change' => true,
            'access' => ['user_admin'],
            'denied_access' => ['sales_main'],
        ], $superRole->code);
    }

    public function test_delegated_admin_cannot_demote_existing_flagged_superadmin(): void
    {
        $superRole = $this->flaggedSuperRole();
        $actor = $this->delegatedUserAdmin();
        $target = User::factory()->create([
            'role_code' => $superRole->code,
            'password_hash' => Hash::make('original-password'),
            'temsilci_kodu' => 'S002',
            'aktif' => true,
        ]);
        UserAccess::query()->create([
            'user_id' => $target->id,
            'resource_code' => 'stock',
            'can_view' => false,
        ]);

        $this->assertDeniedUserSaveLeavesNoMutation($actor, $target, [
            'role_code' => 'viewer',
            'full_name' => 'Mutated Super Demotion',
            'password' => 'mutated-password-123',
            'temsilci_kodu' => 'D999',
            'aktif' => false,
            'access' => ['sales_main'],
            'denied_access' => [],
        ], $superRole->code);
    }

    public function test_delegated_admin_cannot_clone_flagged_superadmin(): void
    {
        $superRole = $this->flaggedSuperRole();
        $actor = $this->delegatedUserAdmin();
        $source = User::factory()->create(['role_code' => $superRole->code]);
        UserAccess::query()->create([
            'user_id' => $source->id,
            'resource_code' => 'sales_main',
            'can_view' => true,
        ]);
        $sourceBefore = $this->adminManagedUserSnapshot($source);
        $footprintBefore = $this->adminUserMutationFootprint();

        $response = $this->actingAs($actor)
            ->postJson("/api/admin/users/{$source->id}/clone", [
                'username' => 'blocked.super.clone',
                'full_name' => 'Blocked Super Clone',
                'password' => 'new-password-123',
                'aktif' => true,
                'force_password_change' => true,
                'strict_access' => true,
            ]);

        $response->assertForbidden();
        $this->assertStringNotContainsString($superRole->code, $response->getContent());
        $this->assertDatabaseMissing('panel.users', ['username' => 'blocked.super.clone']);
        $this->assertSame($sourceBefore, $this->adminManagedUserSnapshot($source));
        $this->assertSame($footprintBefore, $this->adminUserMutationFootprint());
    }

    public function test_delegated_admin_can_create_update_and_clone_normal_users(): void
    {
        $actor = $this->delegatedUserAdmin();

        $this->actingAs($actor)->postJson('/api/admin/users', [
            'username' => 'delegated.normal',
            'full_name' => 'Delegated Normal User',
            'password' => 'new-password-123',
            'role_code' => 'sales',
            'aktif' => true,
            'force_password_change' => false,
            'access' => ['sales_main'],
            'denied_access' => ['stock'],
            'strict_access' => false,
        ])->assertOk();

        $target = User::query()->where('username', 'delegated.normal')->firstOrFail();

        $this->actingAs($actor)->postJson('/api/admin/users', [
            ...$this->validUserSavePayload($target),
            'full_name' => 'Delegated Normal Updated',
            'password' => 'updated-password-123',
            'role_code' => 'viewer',
            'aktif' => false,
            'force_password_change' => true,
            'access' => ['dashboard'],
            'denied_access' => ['stock'],
        ])->assertOk();

        $target->refresh();
        $this->assertSame('viewer', $target->role_code);
        $this->assertSame('Delegated Normal Updated', $target->full_name);
        $this->assertTrue(Hash::check('updated-password-123', $target->password_hash));

        $this->actingAs($actor)
            ->postJson("/api/admin/users/{$target->id}/clone", [
                'username' => 'delegated.normal.clone',
                'full_name' => 'Delegated Normal Clone',
                'password' => 'clone-password-123',
                'aktif' => true,
                'force_password_change' => true,
                'strict_access' => false,
            ])
            ->assertOk();

        $clone = User::query()->where('username', 'delegated.normal.clone')->firstOrFail();
        $this->assertSame('viewer', $clone->role_code);
        $this->assertDatabaseHas('panel.logs', [
            'user_id' => $actor->id,
            'action' => 'admin.user.clone',
        ]);
    }

    public function test_flagged_superadmin_can_create_update_and_clone_flagged_superadmins(): void
    {
        $superRole = $this->flaggedSuperRole();
        $actor = User::factory()->create([
            'role_code' => $superRole->code,
            'aktif' => true,
        ]);

        $this->actingAs($actor)->postJson('/api/admin/users', [
            'username' => 'allowed.super.target',
            'full_name' => 'Allowed Super Target',
            'password' => 'new-password-123',
            'role_code' => $superRole->code,
            'aktif' => true,
            'force_password_change' => false,
            'access' => [],
            'denied_access' => [],
            'strict_access' => false,
        ])->assertOk();

        $target = User::query()->where('username', 'allowed.super.target')->firstOrFail();

        $this->actingAs($actor)->postJson('/api/admin/users', [
            ...$this->validUserSavePayload($target),
            'full_name' => 'Allowed Super Updated',
            'password' => 'updated-password-123',
            'role_code' => $superRole->code,
        ])->assertOk();

        $this->actingAs($actor)
            ->postJson("/api/admin/users/{$target->id}/clone", [
                'username' => 'allowed.super.clone',
                'full_name' => 'Allowed Super Clone',
                'password' => 'clone-password-123',
                'aktif' => true,
                'force_password_change' => true,
                'strict_access' => true,
            ])
            ->assertOk();

        $this->assertSame('Allowed Super Updated', $target->fresh()->full_name);
        $this->assertSame(
            $superRole->code,
            User::query()->where('username', 'allowed.super.clone')->value('role_code'),
        );
    }

    public function test_superadmin_boundary_uses_fresh_actor_role_state(): void
    {
        $superRole = $this->flaggedSuperRole();
        $actor = User::factory()->create([
            'role_code' => $superRole->code,
            'aktif' => true,
        ])->load('role');
        $this->actingAs($actor);

        User::query()->whereKey($actor->id)->update(['role_code' => 'viewer']);
        $before = $this->adminUserMutationFootprint();

        $response = $this->postJson('/api/admin/users', [
            'username' => 'stale.actor.blocked',
            'full_name' => 'Stale Actor Blocked',
            'password' => 'new-password-123',
            'role_code' => $superRole->code,
            'aktif' => true,
            'access' => [],
            'denied_access' => [],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('panel.users', ['username' => 'stale.actor.blocked']);
        $this->assertSame($before, $this->adminUserMutationFootprint());
    }

    public function test_missing_actor_role_is_treated_as_non_superadmin(): void
    {
        $superRole = $this->flaggedSuperRole();
        $actor = $this->delegatedUserAdmin(null);
        $before = $this->adminUserMutationFootprint();

        $response = $this->actingAs($actor)->postJson('/api/admin/users', [
            'username' => 'missing.role.blocked',
            'full_name' => 'Missing Role Blocked',
            'password' => 'new-password-123',
            'role_code' => $superRole->code,
            'aktif' => true,
            'access' => [],
            'denied_access' => [],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('panel.users', ['username' => 'missing.role.blocked']);
        $this->assertSame($before, $this->adminUserMutationFootprint());
    }

    public function test_admin_users_endpoint_forbids_authenticated_user_without_admin_permissions(): void
    {
        $blocked = User::factory()->create([
            'role_code' => 'viewer',
            'aktif' => true,
        ]);
        $access = app(PanelAccessService::class);

        $this->assertFalse($access->isPrivileged($blocked));
        $this->assertFalse($access->userCanAccess($blocked, 'admin_panel'));
        $this->assertFalse($access->userCanAccess($blocked, 'user_admin'));

        $this->actingAs($blocked)
            ->getJson('/api/admin/users')
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

    public function test_sales_representative_scopes_use_current_codes_and_remove_salih_scope(): void
    {
        $service = file_get_contents(app_path('Services/SalesMainPageService.php')) ?: '';
        $seeder = file_get_contents(database_path('seeders/PanelMetadataSeeder.php')) ?: '';

        $this->assertStringContainsString("'0003' => 'sales_rep_umit_yildiz'", $service);
        $this->assertStringContainsString("'0035' => 'sales_rep_bulent_saglam'", $service);
        $this->assertStringContainsString("'0039' => 'sales_rep_mehmet_can'", $service);
        $this->assertStringContainsString("'0040' => 'sales_rep_orkun_genc'", $service);
        $this->assertStringNotContainsString("'0024' => 'sales_rep_salih_cakir'", $service);
        $this->assertStringNotContainsString("'salih' => 'sales_rep_salih_cakir'", $service);
        $this->assertMatchesRegularExpression("/'key'\\s*=>\\s*'bulent_saglam'[\\s\\S]{0,300}'repCode'\\s*=>\\s*'0035'/", $service);
        $this->assertMatchesRegularExpression("/'key'\\s*=>\\s*'mehmet_can'[\\s\\S]{0,300}'repCode'\\s*=>\\s*'0039'/", $service);
        $this->assertMatchesRegularExpression("/'key'\\s*=>\\s*'orkun_genc'[\\s\\S]{0,300}'repCode'\\s*=>\\s*'0040'/", $service);
        $this->assertDoesNotMatchRegularExpression("/'key'\\s*=>\\s*'bulent_saglam'[\\s\\S]{0,300}'repCode'\\s*=>\\s*'0024'/", $service);

        $this->assertDoesNotMatchRegularExpression("/'key'\\s*=>\\s*'salih'[\\s\\S]{0,220}'repCode'\\s*=>\\s*'0024'/", $seeder);
        $this->assertMatchesRegularExpression("/'key'\\s*=>\\s*'bulent_saglam'[\\s\\S]{0,260}'repCode'\\s*=>\\s*'0035'/", $seeder);
        $this->assertMatchesRegularExpression("/'key'\\s*=>\\s*'mehmet_can'[\\s\\S]{0,260}'repCode'\\s*=>\\s*'0039'/", $seeder);
        $this->assertMatchesRegularExpression("/'key'\\s*=>\\s*'orkun_genc'[\\s\\S]{0,260}'repCode'\\s*=>\\s*'0040'/", $seeder);
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

    public function test_capability_filters_ignore_inactive_capability_rows_for_include_all_and_exclude_modes(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $inactiveCapabilityPartner = $this->partner(
            'CAP-ROW-INACTIVE',
            'Capability Row Matrix Inactive Partner',
            ['dealer'],
        );
        $activeCapabilityPartner = $this->partner(
            'CAP-ROW-ACTIVE',
            'Capability Row Matrix Active Partner',
            ['dealer', 'locksmith'],
        );
        $inactiveCapability = B2BPartnerCapability::query()->create([
            'partner_id' => $inactiveCapabilityPartner->id,
            'capability' => 'locksmith',
            'active' => false,
        ]);
        $inactiveCapabilityUser = User::factory()->create([
            'full_name' => 'Capability Row Matrix Inactive User',
            'aktif' => true,
        ]);
        $activeCapabilityUser = User::factory()->create([
            'full_name' => 'Capability Row Matrix Active User',
            'aktif' => true,
        ]);
        $inactiveCapabilityProfile = $this->profile($inactiveCapabilityUser, $inactiveCapabilityPartner, true);
        $activeCapabilityProfile = $this->profile($activeCapabilityUser, $activeCapabilityPartner, true);

        $this->assertFalse((bool) $inactiveCapability->fresh()->active);
        $this->assertTrue((bool) $inactiveCapabilityPartner->fresh()->active);
        $this->assertTrue((bool) $activeCapabilityPartner->fresh()->active);
        $this->assertTrue((bool) $inactiveCapabilityProfile->fresh()->active);
        $this->assertTrue((bool) $activeCapabilityProfile->fresh()->active);
        $this->assertTrue((bool) $inactiveCapabilityUser->fresh()->aktif);
        $this->assertTrue((bool) $activeCapabilityUser->fresh()->aktif);

        $includedPayload = $this->filteredUserPayload($admin, [
            'search' => 'Capability Row Matrix',
            'capabilities' => ['locksmith'],
            'capability_match' => 'any',
        ]);
        $allPayload = $this->filteredUserPayload($admin, [
            'search' => 'Capability Row Matrix',
            'capabilities' => ['dealer', 'locksmith'],
            'capability_match' => 'all',
        ]);
        $excludedPayload = $this->filteredUserPayload($admin, [
            'search' => 'Capability Row Matrix',
            'capabilities' => ['locksmith'],
            'capability_match' => 'exclude',
        ]);

        $includedIds = collect($includedPayload['users'])->pluck('id');
        $allIds = collect($allPayload['users'])->pluck('id');
        $excludedIds = collect($excludedPayload['users'])->pluck('id');

        $this->assertFalse($includedIds->contains($inactiveCapabilityUser->id));
        $this->assertTrue($includedIds->contains($activeCapabilityUser->id));
        $this->assertSame(1, $includedPayload['meta']['filtered_total']);

        $this->assertFalse($allIds->contains($inactiveCapabilityUser->id));
        $this->assertTrue($allIds->contains($activeCapabilityUser->id));
        $this->assertSame(1, $allPayload['meta']['filtered_total']);

        $this->assertTrue($excludedIds->contains($inactiveCapabilityUser->id));
        $this->assertFalse($excludedIds->contains($activeCapabilityUser->id));
        $this->assertSame(1, $excludedPayload['meta']['filtered_total']);
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

    private function flaggedSuperRole(string $code = 'opaque_boundary_role'): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => 'Opaque Boundary Role',
            'description' => 'Flag-based authorization fixture',
            'is_super_admin' => true,
        ]);
    }

    private function delegatedUserAdmin(?string $roleCode = 'viewer'): User
    {
        $actor = User::factory()->create([
            'role_code' => $roleCode,
            'aktif' => true,
        ]);

        foreach (['admin_panel', 'user_admin'] as $resourceCode) {
            UserAccess::query()->create([
                'user_id' => $actor->id,
                'resource_code' => $resourceCode,
                'can_view' => true,
            ]);
        }

        return $actor;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validUserSavePayload(User $user, array $overrides = []): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'role_code' => $user->role_code,
            'temsilci_kodu' => $user->temsilci_kodu,
            'aktif' => (bool) $user->aktif,
            'force_password_change' => (bool) $user->force_password_change,
            'access' => [],
            'denied_access' => [],
            'strict_access' => false,
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminManagedUserSnapshot(User $user): array
    {
        $fresh = User::query()->findOrFail($user->id);

        return [
            'user' => $fresh->only([
                'username',
                'full_name',
                'password_hash',
                'role_code',
                'temsilci_kodu',
                'aktif',
                'force_password_change',
            ]),
            'access' => UserAccess::query()
                ->where('user_id', $fresh->id)
                ->orderBy('resource_code')
                ->get(['resource_code', 'can_view'])
                ->map(fn (UserAccess $access): array => [
                    'resource_code' => $access->resource_code,
                    'can_view' => (bool) $access->can_view,
                ])
                ->all(),
        ];
    }

    /**
     * @return array{users: int, user_access: int, success_audits: int}
     */
    private function adminUserMutationFootprint(): array
    {
        return [
            'users' => User::query()->count(),
            'user_access' => UserAccess::query()->count(),
            'success_audits' => AuditLog::query()
                ->whereIn('action', ['admin.user.save', 'admin.user.clone'])
                ->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertDeniedUserSaveLeavesNoMutation(
        User $actor,
        User $target,
        array $overrides,
        string $protectedRoleCode,
    ): void {
        $targetBefore = $this->adminManagedUserSnapshot($target);
        $footprintBefore = $this->adminUserMutationFootprint();

        $response = $this->actingAs($actor)->postJson(
            '/api/admin/users',
            $this->validUserSavePayload($target, $overrides),
        );

        $response->assertForbidden();
        $this->assertStringNotContainsString($protectedRoleCode, $response->getContent());
        $this->assertSame($targetBefore, $this->adminManagedUserSnapshot($target));
        $this->assertSame($footprintBefore, $this->adminUserMutationFootprint());
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
