<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SupportActivationCode;
use App\Models\SupportGuideEntry;
use App\Models\User;
use App\Services\SupportActivationCodeService;
use App\Services\SupportManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SupportManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_home_card_is_visible_on_main_dashboard(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/panel/DashboardHome.jsx')) ?: '';

        $this->assertStringContainsString("title: 'Destek'", $dashboard);
        $this->assertStringContainsString('Kurulum, tuşlama ve aktivasyon bilgilerine hızlı erişin.', $dashboard);
        $this->assertStringContainsString("candidates: ['/support', '/support/keypad-guide', '/support/activation']", $dashboard);
    }

    public function test_support_center_route_still_works(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);

        $this->actingAs($user)
            ->get('/support')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('panel/support')
                ->has('supportPermissions'));
    }

    public function test_admin_can_access_support_management(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($admin)
            ->get('/support/management')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('panel/support-management'));
    }

    public function test_super_admin_can_access_support_management(): void
    {
        Role::query()->create([
            'code' => 'super_admin',
            'name' => 'Super Admin',
            'description' => 'Test super admin',
            'is_super_admin' => true,
        ]);
        $admin = User::factory()->create(['role_code' => 'super_admin']);

        $this->actingAs($admin)->get('/support/management')->assertOk();
    }

    public function test_non_admin_cannot_access_support_management(): void
    {
        $user = User::factory()->create(['role_code' => 'manager']);

        $this->actingAs($user)->get('/support/management')->assertForbidden();
        $this->actingAs($user)->getJson('/api/support/management/activation-codes')->assertForbidden();
    }

    public function test_partner_cannot_access_support_management(): void
    {
        Role::query()->create([
            'code' => 'partner',
            'name' => 'Partner',
            'description' => 'Partner panel user',
            'is_super_admin' => false,
        ]);
        $partner = User::factory()->create(['role_code' => 'partner']);

        $this->actingAs($partner)->get('/support/management')->assertForbidden();
    }

    public function test_activation_code_is_extracted_from_serial_after_clean_serial_dash(): void
    {
        $preview = app(SupportManagementService::class)->previewActivationImport($this->sampleCsv(), 'paste');

        $this->assertSame(1, $preview['created_count']);
        $this->assertSame('W610LBS02E251213A03101', $preview['rows'][0]['seri_no_temiz']);
        $this->assertSame('306572', $preview['rows'][0]['aktivasyon_kodu']);
    }

    public function test_activation_import_preview_does_not_write_to_db(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $before = DB::table('panel.support_activation_codes')->count();

        $this->actingAs($admin)
            ->postJson('/api/support/management/imports/preview', [
                'paste_text' => $this->sampleCsv(),
                'source' => 'paste',
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 1);

        $this->assertSame($before, DB::table('panel.support_activation_codes')->count());
    }

    public function test_activation_import_commit_creates_rows(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $preview = $this->previewAs($admin, $this->sampleCsv());

        $this->actingAs($admin)
            ->postJson('/api/support/management/imports/commit', [
                'rows' => $preview['rows'],
                'source' => 'paste',
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('updated_count', 0);

        $this->assertDatabaseHas('panel.support_activation_codes', [
            'serial_number_clean' => 'W610LBS02E251213A03101',
            'activation_code' => '306572',
            'search_code' => 'A03101',
        ]);
    }

    public function test_activation_import_commit_updates_existing_clean_serial(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $this->createActivation([
            'stock_code' => 'OLD',
            'stock_name' => 'Old Name',
            'serial_number' => 'W610LBS02E251213A03101-111111',
            'serial_number_clean' => 'W610LBS02E251213A03101',
            'search_code' => 'OLD',
            'activation_code' => '111111',
        ]);
        $preview = $this->previewAs($admin, $this->sampleCsv());

        $this->actingAs($admin)
            ->postJson('/api/support/management/imports/commit', [
                'rows' => $preview['rows'],
                'source' => 'paste',
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('updated_count', 1);

        $this->assertDatabaseHas('panel.support_activation_codes', [
            'serial_number_clean' => 'W610LBS02E251213A03101',
            'stock_code' => 'EP.EKK.002.14.70.R002',
            'activation_code' => '306572',
        ]);
    }

    public function test_activation_import_reports_failed_rows(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($admin)
            ->postJson('/api/support/management/imports/preview', [
                'paste_text' => "STOK_KODU,STOK_ADI,SERI_NO,SERI_NO_TEMIZ,ARAMA_KODU\nSTK,Ürün,,,\n",
                'source' => 'paste',
            ])
            ->assertOk()
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('errors.0.reason', 'Seri no temiz değeri çıkarılamadı.');
    }

    public function test_activation_import_is_idempotent(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $firstPreview = $this->previewAs($admin, $this->sampleCsv());

        $this->actingAs($admin)
            ->postJson('/api/support/management/imports/commit', [
                'rows' => $firstPreview['rows'],
                'source' => 'paste',
            ])
            ->assertOk();

        $secondPreview = $this->previewAs($admin, $this->sampleCsv());

        $this->assertSame(0, $secondPreview['created_count']);
        $this->assertSame(1, $secondPreview['updated_count']);

        $this->actingAs($admin)
            ->postJson('/api/support/management/imports/commit', [
                'rows' => $secondPreview['rows'],
                'source' => 'paste',
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('updated_count', 1);

        $this->assertSame(
            1,
            SupportActivationCode::query()
                ->where('serial_number_clean', 'W610LBS02E251213A03101')
                ->count(),
        );
    }

    public function test_activation_search_finds_by_clean_serial_activation_code_and_arama_kodu(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        $this->createActivation([
            'stock_code' => 'TEST-STK',
            'stock_name' => 'Test Kilit',
            'serial_number' => 'UNITCLEANXYZ-ZXCVBN',
            'serial_number_clean' => 'UNITCLEANXYZ',
            'search_code' => 'MNL615',
            'activation_code' => 'ZXCVBN',
        ]);

        foreach (range(1, 55) as $index) {
            $this->createActivation([
                'stock_code' => 'NOISE-STK-'.$index,
                'stock_name' => sprintf('AAA Noise %02d', $index),
                'serial_number' => sprintf('NOISESERIAL%02d-615%03d', $index, $index),
                'serial_number_clean' => sprintf('NOISESERIAL%02d', $index),
                'search_code' => 'NOISE'.$index,
                'activation_code' => sprintf('615%03d', $index),
            ]);
        }

        foreach (['UNITCLEANXYZ', 'ZXCVBN', 'MNL615'] as $query) {
            $response = $this->actingAs($user)
                ->getJson('/api/support/activation/search?query='.urlencode($query))
                ->assertOk()
                ->json();

            $this->assertTrue(
                collect($response['items'])->contains(fn (array $item): bool => $item['serial_number_clean'] === 'UNITCLEANXYZ'),
            );
        }

        $response = $this->actingAs($user)
            ->getJson('/api/support/activation/search?query=MNL615')
            ->assertOk()
            ->json();

        $this->assertSame('UNITCLEANXYZ', $response['items'][0]['serial_number_clean']);
    }

    public function test_keying_guide_can_be_created_and_updated_by_admin(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $created = $this->actingAs($admin)
            ->postJson('/api/support/management/guides', [
                'title' => 'DDL610 Tuşlama',
                'stok_kodu' => 'EP.EKK.002',
                'product_keyword' => 'DDL610',
                'method' => 'Tuş Takımı',
                'guide_content' => "1. Kapıyı aç\n2. Yönetici kodunu gir",
                'is_active' => true,
                'sort_order' => 5,
            ])
            ->assertOk()
            ->json('item');

        $this->actingAs($admin)
            ->patchJson("/api/support/management/guides/{$created['id']}", [
                'title' => 'DDL610 Güncel Tuşlama',
                'stok_kodu' => 'EP.EKK.002',
                'product_keyword' => 'DDL610',
                'method' => 'Tuş Takımı',
                'guide_content' => 'Güncel adım',
                'is_active' => false,
                'sort_order' => 6,
            ])
            ->assertOk()
            ->assertJsonPath('item.title', 'DDL610 Güncel Tuşlama')
            ->assertJsonPath('item.is_active', false);
    }

    public function test_support_page_shows_matching_keying_guide(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        SupportGuideEntry::query()->create([
            'code' => 'support_guide_test_match',
            'title' => 'Galaxy 20 Tuşlama',
            'source_sheet' => 'Destek Yönetimi',
            'stok_kodu' => 'GALAXY-STK',
            'product_keyword' => 'Galaxy 20',
            'guide_content' => 'Pin ekleme adımı',
            'devices' => ['Galaxy 20'],
            'device_aliases' => ['GALAXY-STK'],
            'method' => 'Tuş Takımı',
            'guide_type' => 'Galaxy 20 Tuşlama',
            'sections' => [['title' => 'Galaxy 20 Tuşlama', 'steps' => ['Pin ekleme adımı']]],
            'warnings' => [],
            'extra_notes' => [],
            'search_text' => 'galaxy 20 pin ekleme',
            'is_active' => true,
            'sort_order' => -100,
        ]);

        $this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('panel/support')
                ->where('supportGuideData.entries.0.guideType', 'Galaxy 20 Tuşlama'));
    }

    /**
     * @return array<string, mixed>
     */
    private function previewAs(User $user, string $csv): array
    {
        return $this->actingAs($user)
            ->postJson('/api/support/management/imports/preview', [
                'paste_text' => $csv,
                'source' => 'paste',
            ])
            ->assertOk()
            ->json();
    }

    private function createActivation(array $record): SupportActivationCode
    {
        return SupportActivationCode::query()->create(
            app(SupportActivationCodeService::class)->buildRecordPayload($record),
        );
    }

    private function sampleCsv(): string
    {
        return <<<'CSV'
STOK_KODU,STOK_ADI,SERI_NO,SERI_NO_TEMIZ,ARAMA_KODU
EP.EKK.002.14.70.R002,DDL610-5HBS - SİYAH / BLACK (70LİK KİLİT),W610LBS02E251213A03101-306572,W610LBS02E251213A03101,A03101
CSV;
    }
}
