<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\User;
use App\Models\UserCariGroupPermission;
use App\Services\SalesMainPageService;
use Database\Seeders\PanelDataSourcesSeeder;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesCariGroupPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->seed(PanelMetadataSeeder::class);
        $this->seed(PanelDataSourcesSeeder::class);
        $this->seed(PanelKnownWorkflowDataSourcesSeeder::class);
    }

    public function test_admin_save_user_normalizes_cari_group_permissions_and_deny_wins(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $target = User::factory()->create([
            'role_code' => 'sales',
            'temsilci_kodu' => '0024',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/users', [
                'id' => $target->id,
                'username' => $target->username,
                'full_name' => $target->full_name,
                'role_code' => 'sales',
                'temsilci_kodu' => '0024',
                'aktif' => true,
                'force_password_change' => false,
                'access' => ['sales_main'],
                'denied_access' => [],
                'allowed_cari_groups' => '120.11, 120.12,120.12',
                'denied_cari_groups' => '120.11',
            ])
            ->assertOk();

        $this->assertDatabaseHas('panel.user_cari_group_permissions', [
            'user_id' => $target->id,
            'cari_group_code' => '120.12',
            'mode' => UserCariGroupPermission::MODE_ALLOW,
        ]);
        $this->assertDatabaseHas('panel.user_cari_group_permissions', [
            'user_id' => $target->id,
            'cari_group_code' => '120.11',
            'mode' => UserCariGroupPermission::MODE_DENY,
        ]);
        $this->assertDatabaseMissing('panel.user_cari_group_permissions', [
            'user_id' => $target->id,
            'cari_group_code' => '120.11',
            'mode' => UserCariGroupPermission::MODE_ALLOW,
        ]);
    }

    public function test_sales_dashboard_payload_sends_allow_and_deny_cari_group_codes(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $this->grantCariGroupPermission($user, '120.11', UserCariGroupPermission::MODE_ALLOW);
        $this->grantCariGroupPermission($user, '120.12', UserCariGroupPermission::MODE_ALLOW);
        $this->grantCariGroupPermission($user, '120.11', UserCariGroupPermission::MODE_DENY);

        $this->fakeSalesGateway();

        app(SalesMainPageService::class)->dataset($user, [
            'scope_key' => 'all',
            'detail_type' => 'cari',
            'grain' => 'week',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-28',
            'bypass_cache' => true,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['source_code'] ?? null) === 'sales_main_dashboard'
                && ($payload['allowed_cari_group_codes'] ?? null) === '120.12'
                && ($payload['denied_cari_group_codes'] ?? null) === '120.11'
                && ($payload['params']['allowed_cari_group_codes'] ?? null) === '120.12'
                && ($payload['params']['denied_cari_group_codes'] ?? null) === '120.11';
        });
    }

    public function test_sales_detail_datasources_inherit_cari_group_payload_filters(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $this->grantCariGroupPermission($user, '120.11', UserCariGroupPermission::MODE_DENY);

        $this->fakeSalesGateway();

        $service = app(SalesMainPageService::class);
        foreach (['online_perakende', 'bayi_proje'] as $scopeKey) {
            $service->dataset($user, [
                'scope_key' => $scopeKey,
                'detail_type' => 'cari',
                'grain' => 'week',
                'date_from' => '2026-04-01',
                'date_to' => '2026-04-28',
                'bypass_cache' => true,
            ]);
        }

        $payloads = collect(Http::recorded())
            ->map(fn (array $record): array => json_decode($record[0]->body(), true) ?: []);

        $this->assertTrue($payloads->contains(fn (array $payload): bool => ($payload['source_code'] ?? null) === 'sales_online_perakende_detail'
            && ($payload['denied_cari_group_codes'] ?? null) === '120.11'
            && ($payload['params']['denied_cari_group_codes'] ?? null) === '120.11'));
        $this->assertTrue($payloads->contains(fn (array $payload): bool => ($payload['source_code'] ?? null) === 'sales_bayi_proje_detail'
            && ($payload['denied_cari_group_codes'] ?? null) === '120.11'
            && ($payload['params']['denied_cari_group_codes'] ?? null) === '120.11'));
    }

    public function test_sales_customer_search_payload_sends_cari_group_filters(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $this->grantCariGroupPermission($user, '120.11', UserCariGroupPermission::MODE_ALLOW);

        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'rows' => [
                    ['cari_kodu' => 'C-1', 'cari_unvani' => 'Test Cari', 'display_text' => 'Test Cari | C-1'],
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->postJson('/api/data/sales_customer_search', [
                'search' => 'test',
                'scope_key' => 'all',
                'date_from' => '2026-04-01',
                'date_to' => '2026-04-28',
                'bypass_cache' => true,
            ])
            ->assertOk();

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['source_code'] ?? null) === 'sales_customer_search'
                && ($payload['allowed_cari_group_codes'] ?? null) === '120.11'
                && ($payload['params']['allowed_cari_group_codes'] ?? null) === '120.11';
        });
    }

    public function test_sales_sql_templates_include_cari_group_allow_and_deny_filters_only_for_sales(): void
    {
        foreach (['sales_main_dashboard', 'sales_online_perakende_detail', 'sales_bayi_proje_detail', 'sales_customer_search'] as $sourceCode) {
            $source = DataSource::query()->where('code', $sourceCode)->firstOrFail();

            $this->assertContains('allowed_cari_group_codes', $source->allowed_params);
            $this->assertContains('denied_cari_group_codes', $source->allowed_params);
            $this->assertStringContainsString('allowed_cari_group_codes', (string) $source->query_template);
            $this->assertStringContainsString('denied_cari_group_codes', (string) $source->query_template);
            $this->assertStringContainsString('STRING_SPLIT(@allowed_cari_group_codes', (string) $source->query_template);
            $this->assertStringContainsString('STRING_SPLIT(@denied_cari_group_codes', (string) $source->query_template);
            $this->assertStringContainsString('cari_grup_kodu', (string) $source->query_template);
        }

        foreach (['stock_dashboard', 'orders_alinan', 'orders_verilen'] as $sourceCode) {
            $source = DataSource::query()->where('code', $sourceCode)->firstOrFail();

            $this->assertNotContains('allowed_cari_group_codes', $source->allowed_params ?? []);
            $this->assertNotContains('denied_cari_group_codes', $source->allowed_params ?? []);
            $this->assertStringNotContainsString('allowed_cari_group_codes', (string) $source->query_template);
            $this->assertStringNotContainsString('denied_cari_group_codes', (string) $source->query_template);
        }
    }

    public function test_admin_users_ui_exposes_cari_group_permission_inputs(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/admin/AdminUsers.jsx')) ?: '';

        $this->assertStringContainsString('Cari Grup Kodu Yetkileri', $component);
        $this->assertStringContainsString('allowed_cari_groups', $component);
        $this->assertStringContainsString('denied_cari_groups', $component);
    }

    private function grantCariGroupPermission(User $user, string $code, string $mode): void
    {
        UserCariGroupPermission::query()->create([
            'user_id' => $user->id,
            'cari_group_code' => $code,
            'mode' => $mode,
        ]);
    }

    private function fakeSalesGateway(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'rows' => [
                    [
                        'period_label' => '2026-04-01 / 2026-04-28',
                        'satir_tipi' => 'GRUP',
                        'cari_grup_adi' => 'Grup A',
                        'satir_adi' => 'Grup A',
                        'adet' => 1,
                        'ciro' => 100,
                        'siralama_1' => 1,
                        'siralama_2' => 0,
                        'excluded_from_total' => 0,
                    ],
                ],
            ]),
        ]);
    }
}
