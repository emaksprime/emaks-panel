<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Database\Seeders\PanelDataSourcesSeeder;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminLogsAuditArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PanelMetadataSeeder::class);
        $this->seed(PanelDataSourcesSeeder::class);
        $this->seed(PanelKnownWorkflowDataSourcesSeeder::class);
        Cache::flush();
    }

    public function test_audit_logger_sanitizes_request_context_and_user_agent(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $request = Request::create(
            'https://panel.test/admin/users?token=top-secret',
            'POST',
            ['password' => 'plain-secret', 'search' => 'burhan'],
            server: [
                'REMOTE_ADDR' => '10.20.30.40',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ],
        );

        app(AuditLogger::class)->log($user, 'admin.user.save', [
            'password' => 'plain-secret',
            'nested' => ['api_key' => 'api-secret'],
            'search' => 'burhan',
        ], $request);

        $payload = AuditLog::query()->firstOrFail()->payload;

        $this->assertSame('***', $payload['password']);
        $this->assertSame('***', $payload['nested']['api_key']);
        $this->assertSame('10.20.30.40', $payload['ip_address']);
        $this->assertSame('Chrome', $payload['browser']);
        $this->assertSame('Windows', $payload['platform']);
        $this->assertSame('Masaüstü', $payload['device_type']);
        $this->assertSame('admin/users', $payload['path']);
        $this->assertSame('POST', $payload['method']);
        $this->assertSame('Kullanıcı kaydetti', $payload['action_label']);
        $this->assertStringNotContainsString('top-secret', $payload['safe_url']);
    }

    public function test_audit_logger_prefers_real_ip_headers_over_proxy_ip(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);

        $cloudflareRequest = Request::create(
            'https://panel.test/admin/logs',
            'GET',
            server: [
                'REMOTE_ADDR' => '10.0.1.1',
                'HTTP_CF_CONNECTING_IP' => '8.8.8.8',
            ],
        );
        app(AuditLogger::class)->log($user, 'panel.page.view', ['page' => 'admin_logs'], $cloudflareRequest);

        $forwardedRequest = Request::create(
            'https://panel.test/admin/logs',
            'GET',
            server: [
                'REMOTE_ADDR' => '10.0.1.1',
                'HTTP_X_FORWARDED_FOR' => '8.8.4.4, 10.0.1.1',
            ],
        );
        app(AuditLogger::class)->log($user, 'admin.datasource.test', [], $forwardedRequest);

        $fallbackRequest = Request::create(
            'https://panel.test/admin/logs',
            'GET',
            server: ['REMOTE_ADDR' => '10.0.1.1'],
        );
        app(AuditLogger::class)->log($user, 'admin.page.save', [], $fallbackRequest);

        $this->assertSame('8.8.8.8', AuditLog::query()->where('action', 'panel.page.view')->firstOrFail()->payload['ip_address']);
        $this->assertSame('8.8.4.4', AuditLog::query()->where('action', 'admin.datasource.test')->firstOrFail()->payload['ip_address']);
        $this->assertSame('10.0.1.1', AuditLog::query()->where('action', 'admin.page.save')->firstOrFail()->payload['ip_address']);
    }

    public function test_admin_logs_endpoint_returns_istanbul_time_and_readable_summary(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $logUser = User::factory()->create([
            'full_name' => 'Log Owner',
            'username' => 'log.owner',
        ]);

        AuditLog::query()->create([
            'user_id' => $logUser->id,
            'action' => 'sales.customer.search',
            'payload' => [
                'page' => 'sales_main',
                'search' => 'mehmet',
                'scope_key' => 'online_perakende',
                'result_count' => 3,
                'ip_address' => '10.0.0.5',
                'device_type' => 'Masaüstü',
                'browser' => 'Chrome',
                'browser_version' => '120',
            ],
            'created_at' => '2026-05-11 09:15:30',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs?q=mehmet&action=sales.customer.search&page=sales_main&date_from=2026-05-11&date_to=2026-05-11&limit=10')
            ->assertOk()
            ->assertJsonPath('summary.total_recent', 1)
            ->assertJsonPath('summary.archived_available', true)
            ->assertJsonPath('logs.0.user_name', 'Log Owner')
            ->assertJsonPath('logs.0.username', 'log.owner')
            ->assertJsonPath('logs.0.created_at_utc', '2026-05-11T09:15:30.000000Z')
            ->assertJsonPath('logs.0.created_at_human', '11.05.2026 12:15:30')
            ->assertJsonPath('logs.0.action_label', 'Müşteri aradı')
            ->assertJsonPath('logs.0.page_label', 'Genel Satış')
            ->assertJsonPath('logs.0.search_term', 'mehmet')
            ->assertJsonPath('logs.0.ip_address', '10.0.0.5')
            ->assertJsonPath('logs.0.device_label', 'Masaüstü')
            ->assertJsonPath('logs.0.browser_label', 'Chrome 120');
    }

    public function test_admin_logs_endpoint_parses_legacy_user_agent_device_fallback(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $iphoneSafari = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

        AuditLog::query()->create([
            'user_id' => null,
            'action' => 'panel.page.view',
            'payload' => [
                'page' => 'admin_logs',
                'user_agent' => $iphoneSafari,
                'ip_address' => '8.8.8.8',
            ],
            'created_at' => now('UTC'),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs')
            ->assertOk()
            ->assertJsonPath('logs.0.device_label', 'Mobil / iOS')
            ->assertJsonPath('logs.0.browser_label', 'Safari 17.5');
    }

    public function test_admin_logs_ui_contract_is_readable_not_raw_payload_table(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/admin/AdminLogs.jsx')) ?: '';

        $this->assertStringContainsString('Bugünkü log', $component);
        $this->assertStringContainsString('Bugünkü kullanıcı', $component);
        $this->assertStringContainsString('Tarih/Saat', $component);
        $this->assertStringContainsString('Cihaz/Tarayıcı', $component);
        $this->assertStringContainsString('Arama/Filtre', $component);
        $this->assertStringContainsString('Detay göster', $component);
        $this->assertStringContainsString('raw_payload', $component);
        $this->assertStringContainsString('<form onSubmit={handleSubmit}', $component);
        $this->assertStringContainsString('type="submit"', $component);
        $this->assertStringContainsString('const resetFilters = () =>', $component);
        $this->assertStringContainsString('loadLogs(defaultFilters)', $component);
        $this->assertStringNotContainsString('JSON.stringify(log.payload', $component);
    }

    public function test_sales_customer_search_writes_readable_audit_log(): void
    {
        DB::table('panel.data_source_cache')->delete();

        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'rows' => [
                    ['cari_kodu' => '120.00.001', 'cari_unvani' => 'Mehmet Test'],
                ],
                'request' => [
                    'params' => [
                        'search' => 'mehmet',
                        'scope_key' => 'online_perakende',
                        'rep_code' => null,
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create(['role_code' => 'admin', 'aktif' => true]);

        $this->actingAs($user)
            ->postJson('/api/data/sales_customer_search', [
                'search' => 'mehmet',
                'scope_key' => 'online_perakende',
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-11',
                'bypass_cache' => true,
            ])
            ->assertOk();

        $log = AuditLog::query()->where('action', 'sales.customer.search')->firstOrFail();
        $payload = $log->payload;

        $this->assertSame('sales_main', $payload['page']);
        $this->assertSame('mehmet', $payload['search']);
        $this->assertSame('online_perakende', $payload['scope_key']);
        $this->assertSame(1, $payload['result_count']);
        $this->assertSame('Müşteri aradı', $payload['action_label']);
    }

    public function test_sales_main_data_request_writes_filter_audit_log(): void
    {
        DB::table('panel.data_source_cache')->delete();

        Http::fake([
            '*' => Http::response(['ok' => true, 'rows' => []]),
        ]);

        $user = User::factory()->create(['role_code' => 'admin', 'aktif' => true]);

        $this->actingAs($user)
            ->postJson('/api/data/sales-main', [
                'scope_key' => 'all',
                'detail_type' => 'urun',
                'brand_filter' => 'philips',
                'category_filter' => 'A1',
                'product_filter' => '720',
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-11',
                'bypass_cache' => true,
            ])
            ->assertOk();

        $payload = AuditLog::query()->where('action', 'sales.data.filter')->firstOrFail()->payload;

        $this->assertSame('sales_main', $payload['page']);
        $this->assertSame('all', $payload['scope_key']);
        $this->assertSame('urun', $payload['detail_type']);
        $this->assertSame('philips', $payload['brand_filter']);
        $this->assertSame('A1', $payload['category_filter']);
        $this->assertSame('720', $payload['product_filter']);
        $this->assertSame('Satış verisi filtreledi', $payload['action_label']);
    }

    public function test_archive_logs_command_is_dry_run_safe_and_idempotent(): void
    {
        $oldLog = AuditLog::query()->create([
            'user_id' => null,
            'action' => 'panel.page.view',
            'payload' => ['page' => 'dashboard'],
            'created_at' => CarbonImmutable::now('Europe/Istanbul')->subMonthNoOverflow()->startOfMonth()->addDay()->timezone('UTC'),
        ]);
        $currentLog = AuditLog::query()->create([
            'user_id' => null,
            'action' => 'panel.page.view',
            'payload' => ['page' => 'dashboard'],
            'created_at' => CarbonImmutable::now('Europe/Istanbul')->startOfMonth()->addDay()->timezone('UTC'),
        ]);

        $this->artisan('panel:archive-logs', ['--dry-run' => true])
            ->assertExitCode(0);
        $this->assertSame(0, DB::table('panel.log_archives')->count());
        $this->assertDatabaseHas('panel.logs', ['id' => $oldLog->id]);

        $this->artisan('panel:archive-logs')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('panel.logs', ['id' => $oldLog->id]);
        $this->assertDatabaseHas('panel.logs', ['id' => $currentLog->id]);
        $this->assertDatabaseHas('panel.log_archives', ['original_log_id' => $oldLog->id]);

        $this->artisan('panel:archive-logs')
            ->assertExitCode(0);
        $this->assertSame(1, DB::table('panel.log_archives')->count());
    }
}
