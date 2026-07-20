<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Resource;
use App\Models\RoleResourcePermission;
use App\Models\User;
use App\Models\UserAccess;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertStringContainsString('Partner / Usta Bağlantısı', $component);
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
        $this->assertStringContainsString('value={accessState(resource.code)}', $component);
        $this->assertStringContainsString('onChange={(event) => setAccessState(resource.code, event.target.value)}', $component);
        $this->assertStringContainsString('setFormBaseline(savedForm)', $component);
        $this->assertStringContainsString('Kullanıcı kaydedildi ve yetkileri güncellendi.', $component);
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
}
