<?php

namespace Tests\Feature;

use App\Models\Resource;
use App\Models\Role;
use App\Models\SupportActivationCode;
use App\Models\SupportGuideEntry;
use App\Models\SupportKeyingGuideProduct;
use App\Models\SupportKeyingGuideStep;
use App\Models\User;
use App\Models\UserAccess;
use App\Services\SupportActivationCodeService;
use App\Services\SupportManagementService;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class SupportManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_support_card_links_existing_support_page(): void
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

    public function test_support_center_still_visible_for_allowed_users(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);

        $hrefs = collect($this->actingAs($user)->getJson('/api/navigation')->assertOk()->json('groups'))
            ->flatMap(fn (array $group) => $group['items'] ?? [])
            ->pluck('href')
            ->all();

        $this->assertContains('/support', $hrefs);
    }

    public function test_support_page_keeps_device_method_format_filters(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        $component = file_get_contents(resource_path('js/pages/panel/support.tsx')) ?: '';

        $this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('panel/support')
                ->has('supportGuideData.entries'));

        $this->assertStringContainsString('label="Cihaz"', $component);
        $this->assertStringContainsString('label="Giriş yöntemi"', $component);
        $this->assertStringContainsString('label="Giriş biçimi"', $component);
        $this->assertStringContainsString('methodOptionsForEntries', $component);
        $this->assertStringContainsString('formatOptionsForEntries', $component);
    }

    public function test_support_page_existing_guides_are_not_removed(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        SupportGuideEntry::query()->create([
            'code' => 'existing_static_guide_contract',
            'source_sheet' => 'Static Test',
            'source_row' => 10,
            'devices' => ['Static Model'],
            'device_aliases' => ['Static Alias'],
            'method' => 'Tuş Takımı',
            'guide_type' => 'Static Pin Ekleme',
            'sections' => [['title' => 'Static Pin Ekleme', 'steps' => ['Static adım']]],
            'warnings' => [],
            'extra_notes' => [],
            'search_text' => 'static model pin',
            'is_active' => true,
            'sort_order' => -100,
        ]);
        $product = $this->createGuideProduct(['product_name' => 'DB Model', 'sort_order' => 1]);
        $this->createGuideStep($product, [
            'entry_method' => 'TTLOCK',
            'entry_format' => 'DB Cihaz Eşleme',
            'title' => 'Cihaz Eşleme',
            'content' => 'DB adım',
        ]);

        $entries = $this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->inertiaProps('supportGuideData.entries');

        $guideTypes = collect($entries)->pluck('guideType')->all();
        $this->assertContains('Static Pin Ekleme', $guideTypes);
        $this->assertContains('DB Cihaz Eşleme', $guideTypes);
    }

    public function test_support_page_does_not_show_smoke_guides(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        $smokeNeedles = [
            'Browser Rehber',
            'Smoke Tuşlama',
            'SmokeModel',
            'SMOKE_REHBER',
            'Manual Browser Kilit',
            'Browser Test Kilit',
        ];

        $entries = $this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->inertiaProps('supportGuideData.entries');

        $payload = json_encode($entries, JSON_UNESCAPED_UNICODE) ?: '';
        $code = collect([
            base_path('database'),
            app_path(),
            resource_path('js/pages/panel'),
        ])->flatMap(fn (string $path) => collect(glob($path.'/**/*') ?: []))
            ->filter(fn (string $path): bool => is_file($path))
            ->map(fn (string $path): string => file_get_contents($path) ?: '')
            ->implode("\n");

        foreach ($smokeNeedles as $needle) {
            $this->assertStringNotContainsString($needle, $payload);
            $this->assertStringNotContainsString($needle, $code);
        }
    }

    public function test_support_page_shows_db_guides_in_existing_filter_structure(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        $product = $this->createGuideProduct([
            'product_name' => 'Retina 30',
            'search_keywords' => "RETINA30\nYüz tanıma",
            'sort_order' => 1,
        ]);
        $this->createGuideStep($product, [
            'entry_method' => 'TTLOCK',
            'entry_format' => 'Yüz Tanıma Ekleme',
            'title' => 'Diğer',
            'content' => "Menüye gir\nYüz tanıma adımını başlat",
            'sort_order' => 1,
        ]);

        $entries = $this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->inertiaProps('supportGuideData.entries');
        $entry = collect($entries)->firstWhere('guideType', 'Yüz Tanıma Ekleme');

        $this->assertNotNull($entry);
        $this->assertSame('TTLOCK', $entry['method']);
        $this->assertContains('Retina 30', $entry['devices']);
        $this->assertContains('RETINA30', $entry['deviceAliases']);
        $this->assertSame('Diğer', $entry['sections'][0]['title']);
        $this->assertSame('Menüye gir', $entry['sections'][0]['steps'][0]);
    }

    public function test_support_management_permission_is_visible_in_user_permission_panel(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $resources = collect($this->actingAs($admin)->getJson('/api/admin/users')->assertOk()->json('resources'));
        $resource = $resources->firstWhere('code', 'support_management');

        $this->assertNotNull($resource);
        $this->assertSame('Destek Yönetimi', $resource['name']);
        $this->assertSame('Destek', $resource['group']);
    }

    public function test_permission_seed_does_not_remove_existing_user_access(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        UserAccess::query()->create([
            'user_id' => $user->id,
            'resource_code' => 'support',
            'can_view' => true,
        ]);

        $this->seed(PanelMetadataSeeder::class);

        $this->assertDatabaseHas('panel.user_access', [
            'user_id' => $user->id,
            'resource_code' => 'support',
            'can_view' => true,
        ]);
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
        Role::query()->updateOrCreate(
            ['code' => 'super_admin'],
            [
                'name' => 'Super Admin',
                'description' => 'Test super admin',
                'is_super_admin' => true,
            ],
        );
        $admin = User::factory()->create(['role_code' => 'super_admin']);

        $this->actingAs($admin)->get('/support/management')->assertOk();
    }

    public function test_normal_user_cannot_access_support_management(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);

        $this->actingAs($user)->get('/support/management')->assertForbidden();
        $this->actingAs($user)->getJson('/api/support/management/activation-codes')->assertForbidden();
    }

    public function test_partner_cannot_access_support_management(): void
    {
        Role::query()->updateOrCreate(
            ['code' => 'partner'],
            [
                'name' => 'Partner',
                'description' => 'Partner panel user',
                'is_super_admin' => false,
            ],
        );
        $partner = User::factory()->create(['role_code' => 'partner']);

        $this->actingAs($partner)->get('/support/management')->assertForbidden();
    }

    public function test_support_management_has_no_hardcoded_localhost_urls(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/support-management.tsx')) ?: '';

        $this->assertStringNotContainsString('localhost', $component);
        $this->assertStringNotContainsString('127.0.0.1', $component);
    }

    public function test_support_management_uses_relative_api_endpoints(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/support-management.tsx')) ?: '';

        $this->assertStringContainsString("'/api/support/management/activation-codes", $component);
        $this->assertStringContainsString("'/api/support/management/imports/preview'", $component);
        $this->assertStringContainsString("'/api/support/management/imports/commit'", $component);
        $this->assertStringContainsString("'/api/support/management/guides", $component);
        $this->assertStringNotContainsString('apiRequest(\'http', $component);
        $this->assertStringNotContainsString('fetch(\'http', $component);
    }

    public function test_support_management_activation_list_has_no_horizontal_overflow(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/support-management.tsx')) ?: '';

        $this->assertStringNotContainsString('overflow-x-auto', $component);
        $this->assertStringNotContainsString('<table', $component);
        $this->assertStringNotContainsString('min-w-[', $component);
    }

    public function test_support_management_activation_list_uses_clean_serial_without_duplicate_raw_serial_fields(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/support-management.tsx')) ?: '';

        $this->assertStringContainsString('cleanSerialText(item)', $component);
        $this->assertStringContainsString('activationCodeText(item)', $component);
        $this->assertStringNotContainsString('Temiz Seri', $component);
        $this->assertStringNotContainsString("['Seri No', item.serial_number]", $component);
    }

    public function test_support_management_mobile_has_no_horizontal_overflow(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/support-management.tsx')) ?: '';

        $this->assertStringContainsString('min-w-0', $component);
        $this->assertStringContainsString('break-all', $component);
        $this->assertStringContainsString('break-words', $component);
        $this->assertStringNotContainsString('w-screen', $component);
    }

    public function test_guide_management_has_no_horizontal_overflow(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/support-management.tsx')) ?: '';

        $this->assertStringContainsString('xl:grid-cols-[24rem_minmax(0,1fr)]', $component);
        $this->assertStringContainsString('min-w-0', $component);
        $this->assertStringNotContainsString('overflow-x-auto', $component);
        $this->assertStringNotContainsString('<table', $component);
    }

    public function test_guide_product_form_does_not_show_sort_order_input(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/support-management.tsx')) ?: '';

        $this->assertStringNotContainsString('label="Sıralama"', $component);
        $this->assertStringNotContainsString('<Field label="Sıralama">', $component);
    }

    public function test_guide_step_form_does_not_show_sort_order_input(): void
    {
        $component = file_get_contents(resource_path('js/pages/panel/support-management.tsx')) ?: '';

        $this->assertStringNotContainsString('Sıra {step.sort_order}', $component);
        $this->assertStringNotContainsString('stepForm.sort_order', $component);
    }

    public function test_guide_sort_order_is_assigned_server_side(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $product = $this->actingAs($admin)
            ->postJson('/api/support/management/guides', [
                'product_name' => 'Server Sıra Cihazı',
                'search_keywords' => null,
                'is_active' => true,
            ])
            ->assertOk()
            ->json('item');

        $this->assertGreaterThan(0, $product['sort_order']);

        $step = $this->actingAs($admin)
            ->postJson('/api/support/management/guides/'.$product['id'].'/steps', [
                'section_type' => 'pin',
                'entry_method' => 'TTLOCK',
                'entry_format' => 'Pin Ekleme',
                'content' => 'Server sıra adımı.',
                'is_active' => true,
            ])
            ->assertOk()
            ->json('item');

        $this->assertGreaterThan(0, $step['sort_order']);
    }

    public function test_support_management_lists_existing_keying_guides(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $response = $this->actingAs($admin)
            ->getJson('/api/support/management/guides')
            ->assertOk()
            ->json();

        $this->assertGreaterThan(0, count($response['items']));
        $this->assertSame('legacy', $response['items'][0]['source']);
        $this->assertNotEmpty($response['items'][0]['product_name']);
        $this->assertNotEmpty($response['items'][0]['steps']);
    }

    public function test_support_management_lists_existing_legacy_guides(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $items = $this->actingAs($admin)
            ->getJson('/api/support/management/guides')
            ->assertOk()
            ->json('items');

        $this->assertTrue(collect($items)->contains(fn (array $item): bool => ($item['source'] ?? null) === 'legacy'
            && str_starts_with((string) $item['id'], 'legacy-product-')
            && count($item['steps'] ?? []) > 0));
    }

    public function test_support_management_does_not_show_empty_when_legacy_guides_exist(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $items = $this->actingAs($admin)
            ->getJson('/api/support/management/guides')
            ->assertOk()
            ->json('items');

        $this->assertGreaterThan(0, count($items));
        $this->assertTrue(collect($items)->contains(fn (array $item): bool => ($item['source'] ?? null) === 'legacy'));
    }

    public function test_support_management_can_update_guide_product(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->actingAs($admin)->getJson('/api/support/management/guides')->assertOk()->json('items.0');

        $this->actingAs($admin)
            ->patchJson('/api/support/management/guides/'.$product['id'], [
                'product_name' => 'Güncel Legacy Cihaz',
                'search_keywords' => "GUNCELLEGACY\nLegacy Alias",
                'is_active' => true,
                'sort_order' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('item.product_name', 'Güncel Legacy Cihaz')
            ->assertJsonPath('item.search_keywords', "GUNCELLEGACY\nLegacy Alias");

        $this->assertTrue(SupportGuideEntry::query()
            ->whereJsonContains('devices', 'Güncel Legacy Cihaz')
            ->exists());
    }

    public function test_support_management_can_update_guide_step(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->actingAs($admin)->getJson('/api/support/management/guides')->assertOk()->json('items.0');
        $step = $product['steps'][0];

        $this->actingAs($admin)
            ->patchJson('/api/support/management/guides/'.$product['id'].'/steps/'.$step['id'], [
                'section_type' => 'pin',
                'entry_method' => 'TTLOCK',
                'entry_format' => 'Pin Ekleme',
                'content' => "Güncel legacy adım\nİkinci satır",
                'is_active' => true,
                'sort_order' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('item.entry_format', 'Pin Ekleme')
            ->assertJsonPath('item.content', "Güncel legacy adım\nİkinci satır");

        $this->assertTrue(SupportGuideEntry::query()
            ->where('guide_type', 'Pin Ekleme')
            ->where('search_text', 'like', '%Güncel legacy adım%')
            ->exists());
    }

    public function test_support_management_update_persists_after_refresh(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->actingAs($admin)->getJson('/api/support/management/guides')->assertOk()->json('items.0');
        $step = $product['steps'][0];

        $this->actingAs($admin)
            ->patchJson('/api/support/management/guides/'.$product['id'].'/steps/'.$step['id'], [
                'section_type' => 'other',
                'custom_title' => 'Kalıcı Legacy Başlık',
                'entry_method' => 'GATEWAY',
                'entry_format' => 'Kalıcı Legacy Biçim',
                'content' => 'Refresh sonrası kalmalı.',
                'is_active' => true,
                'sort_order' => 6,
            ])
            ->assertOk();

        $refreshed = collect($this->actingAs($admin)
            ->getJson('/api/support/management/guides?search=Kalıcı Legacy')
            ->assertOk()
            ->json('items'))
            ->flatMap(fn (array $item): array => $item['steps'])
            ->firstWhere('entry_format', 'Kalıcı Legacy Biçim');

        $this->assertNotNull($refreshed);
        $this->assertSame('Refresh sonrası kalmalı.', $refreshed['content']);
    }

    public function test_support_management_product_edit_persists_after_refresh(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'Düzenlenecek Cihaz']);

        $this->actingAs($admin)
            ->patchJson('/api/support/management/guides/'.$product->id, [
                'product_name' => 'Düzenlenmiş Cihaz',
                'search_keywords' => 'DUZENLENMIS-CIHAZ',
                'is_active' => true,
            ])
            ->assertOk();

        $refreshed = collect($this->actingAs($admin)
            ->getJson('/api/support/management/guides?search=Düzenlenmiş')
            ->assertOk()
            ->json('items'))
            ->firstWhere('product_name', 'Düzenlenmiş Cihaz');

        $this->assertNotNull($refreshed);
        $this->assertSame('DUZENLENMIS-CIHAZ', $refreshed['search_keywords']);
    }

    public function test_support_page_reflects_updated_product_name(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $user = User::factory()->create(['role_code' => 'sales']);
        $product = $this->createGuideProduct(['product_name' => 'Eski Public Cihaz']);
        $this->createGuideStep($product, [
            'entry_method' => 'TTLOCK',
            'entry_format' => 'Public Cihaz Eşleme',
            'content' => 'Public adım.',
        ]);

        $this->actingAs($admin)
            ->patchJson('/api/support/management/guides/'.$product->id, [
                'product_name' => 'Yeni Public Cihaz',
                'search_keywords' => 'YENI-PUBLIC',
                'is_active' => true,
            ])
            ->assertOk();

        $entries = collect($this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->inertiaProps('supportGuideData.entries'));

        $this->assertTrue($entries->contains(fn (array $entry): bool => in_array('Yeni Public Cihaz', $entry['devices'], true)));
        $this->assertFalse($entries->contains(fn (array $entry): bool => in_array('Eski Public Cihaz', $entry['devices'], true)));
    }

    public function test_support_management_step_edit_persists_after_refresh(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'Step Edit Cihaz']);
        $step = $this->createGuideStep($product, ['content' => 'Eski içerik']);

        $this->actingAs($admin)
            ->patchJson('/api/support/management/guides/'.$product->id.'/steps/'.$step->id, [
                'section_type' => 'pin',
                'entry_method' => 'TTLOCK',
                'entry_format' => 'Pin Ekleme',
                'content' => 'Yeni kalıcı içerik',
                'is_active' => true,
            ])
            ->assertOk();

        $refreshed = collect($this->actingAs($admin)
            ->getJson('/api/support/management/guides?search=Step Edit')
            ->assertOk()
            ->json('items.0.steps'))
            ->firstWhere('id', $step->id);

        $this->assertSame('Yeni kalıcı içerik', $refreshed['content']);
    }

    public function test_support_management_custom_step_title_persists(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'Custom Step Cihaz']);
        $step = $this->createGuideStep($product);

        $this->actingAs($admin)
            ->patchJson('/api/support/management/guides/'.$product->id.'/steps/'.$step->id, [
                'section_type' => 'other',
                'custom_title' => 'Özel Kalıcı Başlık',
                'entry_method' => 'GATEWAY',
                'entry_format' => 'Özel Kalıcı Biçim',
                'content' => 'Özel içerik kalıcı.',
                'is_active' => true,
            ])
            ->assertOk();

        $refreshed = collect($this->actingAs($admin)
            ->getJson('/api/support/management/guides?search=Custom Step')
            ->assertOk()
            ->json('items.0.steps'))
            ->firstWhere('entry_format', 'Özel Kalıcı Biçim');

        $this->assertSame('Özel Kalıcı Başlık', $refreshed['custom_title']);
        $this->assertSame('Özel Kalıcı Başlık', $refreshed['title']);
    }

    public function test_support_page_reflects_updated_step_content(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $user = User::factory()->create(['role_code' => 'sales']);
        $product = $this->createGuideProduct(['product_name' => 'Public Step Cihaz']);
        $step = $this->createGuideStep($product, ['content' => 'Eski public içerik']);

        $this->actingAs($admin)
            ->patchJson('/api/support/management/guides/'.$product->id.'/steps/'.$step->id, [
                'section_type' => 'other',
                'custom_title' => 'Public Özel Başlık',
                'entry_method' => 'TTLOCK',
                'entry_format' => 'Public Özel Biçim',
                'content' => 'Yeni public içerik',
                'is_active' => true,
            ])
            ->assertOk();

        $entry = collect($this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->inertiaProps('supportGuideData.entries'))
            ->firstWhere('guideType', 'Public Özel Biçim');

        $this->assertNotNull($entry);
        $this->assertSame('Public Özel Başlık', $entry['sections'][0]['title']);
        $this->assertSame('Yeni public içerik', $entry['sections'][0]['steps'][0]);
    }

    public function test_admin_can_duplicate_keying_guide_product(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'Kopyalanacak Cihaz']);

        $this->actingAs($admin)
            ->postJson('/api/support/management/guides/'.$product->id.'/duplicate')
            ->assertOk()
            ->assertJsonPath('item.product_name', 'Kopyalanacak Cihaz - Kopya');
    }

    public function test_duplicated_product_copies_steps(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'Adımlı Cihaz']);
        $this->createGuideStep($product, ['entry_format' => 'Pin Ekleme', 'content' => 'Pin adımı']);
        $this->createGuideStep($product, ['entry_format' => 'Kart Ekleme', 'content' => 'Kart adımı']);

        $copy = $this->actingAs($admin)
            ->postJson('/api/support/management/guides/'.$product->id.'/duplicate')
            ->assertOk()
            ->json('item');

        $this->assertCount(2, $copy['steps']);
        $this->assertContains('Pin Ekleme', collect($copy['steps'])->pluck('entry_format')->all());
        $this->assertContains('Kart Ekleme', collect($copy['steps'])->pluck('entry_format')->all());
    }

    public function test_editing_duplicate_does_not_modify_original(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'Orijinal Cihaz']);
        $this->createGuideStep($product, ['content' => 'Orijinal içerik']);
        $copy = $this->actingAs($admin)
            ->postJson('/api/support/management/guides/'.$product->id.'/duplicate')
            ->assertOk()
            ->json('item');

        $this->actingAs($admin)
            ->patchJson('/api/support/management/guides/'.$copy['id'], [
                'product_name' => 'Kopya Değişti',
                'search_keywords' => 'KOPYA',
                'is_active' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('panel.support_keying_guide_products', [
            'id' => $product->id,
            'product_name' => 'Orijinal Cihaz',
        ]);
        $this->assertDatabaseHas('panel.support_keying_guide_products', [
            'id' => $copy['id'],
            'product_name' => 'Kopya Değişti',
        ]);
    }

    public function test_duplicated_product_can_be_renamed_and_saved(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'Rename Copy Cihaz']);
        $copy = $this->actingAs($admin)
            ->postJson('/api/support/management/guides/'.$product->id.'/duplicate')
            ->assertOk()
            ->json('item');

        $this->actingAs($admin)
            ->patchJson('/api/support/management/guides/'.$copy['id'], [
                'product_name' => 'Rename Copy Cihaz Yeni',
                'search_keywords' => 'RENAME-COPY-YENI',
                'is_active' => true,
            ])
            ->assertOk();

        $refreshed = collect($this->actingAs($admin)
            ->getJson('/api/support/management/guides?search=Rename Copy Cihaz Yeni')
            ->assertOk()
            ->json('items'))
            ->firstWhere('product_name', 'Rename Copy Cihaz Yeni');

        $this->assertNotNull($refreshed);
    }

    public function test_duplicated_product_appears_on_support_page_filters(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $user = User::factory()->create(['role_code' => 'sales']);
        $product = $this->createGuideProduct(['product_name' => 'Public Copy Kaynak']);
        $this->createGuideStep($product, [
            'entry_method' => 'TTLOCK',
            'entry_format' => 'Copy Public Biçim',
            'content' => 'Copy public adım',
        ]);
        $copy = $this->actingAs($admin)
            ->postJson('/api/support/management/guides/'.$product->id.'/duplicate')
            ->assertOk()
            ->json('item');

        $this->actingAs($admin)
            ->patchJson('/api/support/management/guides/'.$copy['id'], [
                'product_name' => 'Public Copy Yeni',
                'search_keywords' => 'PUBLIC-COPY-YENI',
                'is_active' => true,
            ])
            ->assertOk();

        $entries = collect($this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->inertiaProps('supportGuideData.entries'));

        $this->assertTrue($entries->contains(fn (array $entry): bool => in_array('Public Copy Yeni', $entry['devices'], true)
            && ($entry['guideType'] ?? null) === 'Copy Public Biçim'));
    }

    public function test_admin_can_deactivate_keying_guide_step(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'Pasif Cihaz']);
        $step = $this->createGuideStep($product, ['is_active' => true]);

        $this->actingAs($admin)
            ->patchJson('/api/support/management/guides/'.$product->id.'/steps/'.$step->id, [
                'section_type' => $step->section_type,
                'entry_method' => $step->entry_method,
                'entry_format' => $step->entry_format,
                'content' => $step->content,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('item.is_active', false);
    }

    public function test_inactive_step_is_hidden_from_support_page(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        $product = $this->createGuideProduct(['product_name' => 'Pasif Public Cihaz']);
        $this->createGuideStep($product, [
            'entry_format' => 'Pasif Public Biçim',
            'content' => 'Görünmemeli',
            'is_active' => false,
        ]);

        $entries = collect($this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->inertiaProps('supportGuideData.entries'));

        $this->assertFalse($entries->contains(fn (array $entry): bool => ($entry['guideType'] ?? null) === 'Pasif Public Biçim'));
    }

    public function test_inactive_step_can_be_seen_in_management_when_requested(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'Pasif Yönetim Cihaz']);
        $this->createGuideStep($product, [
            'entry_format' => 'Pasif Yönetim Biçim',
            'content' => 'Yönetimde görülebilir',
            'is_active' => false,
        ]);
        $component = file_get_contents(resource_path('js/pages/panel/support-management.tsx')) ?: '';

        $item = collect($this->actingAs($admin)
            ->getJson('/api/support/management/guides?search=Pasif Yönetim')
            ->assertOk()
            ->json('items.0.steps'))
            ->firstWhere('entry_format', 'Pasif Yönetim Biçim');

        $this->assertNotNull($item);
        $this->assertFalse($item['is_active']);
        $this->assertStringContainsString('Pasifleri göster', $component);
    }

    public function test_support_page_filters_include_db_and_existing_guides(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        $product = $this->createGuideProduct(['product_name' => 'DB Filtre Cihaz']);
        $this->createGuideStep($product, [
            'entry_method' => 'TTLOCK',
            'entry_format' => 'DB Filtre Biçim',
            'content' => 'DB filtre adımı.',
        ]);

        $entries = collect($this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->inertiaProps('supportGuideData.entries'));

        $this->assertTrue($entries->contains(fn (array $entry): bool => in_array('DB Filtre Cihaz', $entry['devices'], true)
            && ($entry['guideType'] ?? null) === 'DB Filtre Biçim'));
        $this->assertTrue($entries->contains(fn (array $entry): bool => in_array('E35', $entry['devices'], true)
            && ($entry['method'] ?? null) === 'TTLOCK'));
    }

    public function test_support_page_device_method_format_filters_not_empty(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        $entries = collect($this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->inertiaProps('supportGuideData.entries'));

        $this->assertNotEmpty($entries->flatMap(fn (array $entry): array => $entry['devices'])->filter()->unique()->values()->all());
        $this->assertNotEmpty($entries->pluck('method')->filter()->unique()->values()->all());
        $this->assertNotEmpty($entries->pluck('guideType')->filter()->unique()->values()->all());
    }

    public function test_smoke_cleanup_does_not_delete_real_guides(): void
    {
        $before = SupportGuideEntry::query()->where('is_active', true)->count();

        foreach (['Browser Rehber', 'Smoke Tuşlama', 'SmokeModel', 'SMOKE_REHBER'] as $needle) {
            SupportKeyingGuideProduct::query()->where('product_name', 'like', '%'.$needle.'%')->delete();
        }

        $this->assertSame($before, SupportGuideEntry::query()->where('is_active', true)->count());
        $this->assertGreaterThan(0, $before);
    }

    public function test_support_management_does_not_show_smoke_guides(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $payload = json_encode($this->actingAs($admin)
            ->getJson('/api/support/management/guides')
            ->assertOk()
            ->json('items'), JSON_UNESCAPED_UNICODE) ?: '';

        foreach (['Browser Rehber', 'Smoke Tuşlama', 'SmokeModel', 'SMOKE_REHBER', 'Manual Browser Kilit', 'Browser Test Kilit'] as $needle) {
            $this->assertStringNotContainsString($needle, $payload);
        }
    }

    public function test_support_management_activation_list_is_paginated(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        foreach (range(1, 31) as $index) {
            $this->createActivation([
                'stock_code' => 'PAGE-'.$index,
                'stock_name' => 'Sayfa Test '.$index,
                'serial_number' => 'PAGESERIAL'.$index.'-123456',
                'serial_number_clean' => 'PAGESERIAL'.$index,
                'activation_code' => '123456',
                'search_code' => 'PAGE'.$index,
            ]);
        }

        $response = $this->actingAs($admin)
            ->getJson('/api/support/management/activation-codes?per_page=25')
            ->assertOk()
            ->json();

        $this->assertCount(25, $response['items']);
        $this->assertGreaterThanOrEqual(31, $response['total']);
        $this->assertSame(25, $response['pagination']['per_page']);
        $this->assertGreaterThanOrEqual(2, $response['pagination']['last_page']);
    }

    public function test_support_management_activation_search_is_server_side(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $this->createActivation([
            'stock_code' => 'TARGET-STK',
            'stock_name' => 'Target Kilit',
            'serial_number' => 'TARGETSERIAL-123456',
            'serial_number_clean' => 'TARGETSERIAL',
            'activation_code' => '123456',
            'search_code' => 'TARGET-CODE',
        ]);
        $this->createActivation([
            'stock_code' => 'OTHER-STK',
            'stock_name' => 'Other Kilit',
            'serial_number' => 'OTHERSERIAL-654321',
            'serial_number_clean' => 'OTHERSERIAL',
            'activation_code' => '654321',
            'search_code' => 'OTHER-CODE',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/support/management/activation-codes?search=TARGET-CODE&per_page=25')
            ->assertOk()
            ->json();

        $this->assertGreaterThanOrEqual(2, $response['total']);
        $this->assertSame(1, $response['filtered_total']);
        $this->assertSame('TARGETSERIAL', $response['items'][0]['serial_number_clean']);
    }

    public function test_activation_import_accepts_csv(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($admin)
            ->post('/api/support/management/imports/preview', [
                'source' => 'csv',
                'file' => UploadedFile::fake()->createWithContent('codes.csv', $this->sampleCsv()),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('created_count', 1);
    }

    public function test_activation_import_accepts_xlsx(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $path = $this->makeXlsx([
            ['STOK_KODU', 'STOK_ADI', 'SERI_NO', 'SERI_NO_TEMIZ', 'ARAMA_KODU'],
            ['EP.TURK.001', 'Türkçe Ürün ŞİĞÜÖÇ', 'TRSERIAL-123456', 'TRSERIAL', 'ARA-TR'],
        ]);

        $file = new UploadedFile(
            $path,
            'activation.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $this->actingAs($admin)
            ->post('/api/support/management/imports/preview', [
                'source' => 'xlsx',
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('rows.0.stok_adi', 'Türkçe Ürün ŞİĞÜÖÇ')
            ->assertJsonPath('rows.0.aktivasyon_kodu', '123456');
    }

    public function test_xlsx_preview_does_not_write_to_db(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $before = SupportActivationCode::query()->count();
        $file = $this->uploadedXlsx([
            ['STOK_KODU', 'STOK_ADI', 'SERI_NO', 'SERI_NO_TEMIZ', 'ARAMA_KODU'],
            ['XLSX.001', 'XLSX Test Ürün', 'XLSXPREVIEW-445566', 'XLSXPREVIEW', 'XLSX-ARA'],
        ]);

        $this->actingAs($admin)
            ->post('/api/support/management/imports/preview', [
                'source' => 'xlsx',
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('created_count', 1);

        $this->assertSame($before, SupportActivationCode::query()->count());
    }

    public function test_xlsx_commit_upserts_by_clean_serial(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $this->createActivation([
            'stock_code' => 'OLD-XLSX',
            'stock_name' => 'Old XLSX',
            'serial_number' => 'XLSXCOMMIT-000000',
            'serial_number_clean' => 'XLSXCOMMIT',
            'activation_code' => '000000',
            'search_code' => 'OLD-X',
        ]);
        $file = $this->uploadedXlsx([
            ['STOK_KODU', 'STOK_ADI', 'SERI_NO', 'SERI_NO_TEMIZ', 'ARAMA_KODU'],
            ['XLSX.002', 'XLSX Commit Ürün', 'XLSXCOMMIT-998877', 'XLSXCOMMIT', 'XLSX-UP'],
        ]);
        $preview = $this->actingAs($admin)
            ->post('/api/support/management/imports/preview', [
                'source' => 'xlsx',
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('updated_count', 1)
            ->json();

        $this->actingAs($admin)
            ->postJson('/api/support/management/imports/commit', [
                'rows' => $preview['rows'],
                'source' => 'xlsx',
                'filename' => 'activation.xlsx',
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('updated_count', 1);

        $this->assertDatabaseHas('panel.support_activation_codes', [
            'serial_number_clean' => 'XLSXCOMMIT',
            'stock_code' => 'XLSX.002',
            'activation_code' => '998877',
            'search_code' => 'XLSX-UP',
        ]);
    }

    public function test_xlsx_import_extracts_activation_code(): void
    {
        $preview = app(SupportManagementService::class)->previewActivationImport(
            file_get_contents($this->makeXlsx([
                ['STOK_KODU', 'STOK_ADI', 'SERI_NO', 'SERI_NO_TEMIZ', 'ARAMA_KODU'],
                ['XLSX.003', 'XLSX Extract Ürün', 'XLSXEXTRACT-112233', 'XLSXEXTRACT', 'XLSX-EX'],
            ])) ?: '',
            'xlsx',
            'activation.xlsx',
        );

        $this->assertSame('XLSXEXTRACT', $preview['rows'][0]['seri_no_temiz']);
        $this->assertSame('112233', $preview['rows'][0]['aktivasyon_kodu']);
    }

    public function test_activation_import_accepts_paste(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($admin)
            ->postJson('/api/support/management/imports/preview', [
                'paste_text' => $this->sampleCsv(),
                'source' => 'paste',
            ])
            ->assertOk()
            ->assertJsonPath('source', 'paste')
            ->assertJsonPath('created_count', 1);
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

    public function test_activation_import_commit_upserts_by_clean_serial(): void
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

    public function test_activation_import_extracts_activation_code_from_serial_suffix(): void
    {
        $preview = app(SupportManagementService::class)->previewActivationImport($this->sampleCsv(), 'paste');

        $this->assertSame('W610LBS02E251213A03101', $preview['rows'][0]['seri_no_temiz']);
        $this->assertSame('306572', $preview['rows'][0]['aktivasyon_kodu']);
    }

    public function test_activation_import_handles_turkish_characters(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $csv = "STOK_KODU,STOK_ADI,SERI_NO,SERI_NO_TEMIZ,ARAMA_KODU\nTR.001,ŞİĞÜÖÇ Kilit,TRSERIAL-998877,TRSERIAL,İST-01\n";

        $preview = $this->previewAs($admin, $csv);

        $this->assertSame('ŞİĞÜÖÇ Kilit', $preview['rows'][0]['stok_adi']);
        $this->assertSame('İST-01', $preview['rows'][0]['arama_kodu']);
    }

    public function test_activation_import_reports_first_20_errors(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $rows = collect(range(1, 25))
            ->map(fn (int $index): string => "STK{$index},Ürün {$index},,,")
            ->prepend('STOK_KODU,STOK_ADI,SERI_NO,SERI_NO_TEMIZ,ARAMA_KODU')
            ->implode("\n");

        $response = $this->actingAs($admin)
            ->postJson('/api/support/management/imports/preview', [
                'paste_text' => $rows,
                'source' => 'paste',
            ])
            ->assertOk()
            ->assertJsonPath('failed_count', 25)
            ->json();

        $this->assertCount(20, $response['errors']);
    }

    public function test_admin_can_create_keying_guide_product(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($admin)
            ->postJson('/api/support/management/guides', [
                'product_name' => 'Galaxy 20',
                'search_keywords' => 'G20, Galaxy Smart',
                'is_active' => true,
                'sort_order' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('item.product_name', 'Galaxy 20')
            ->assertJsonPath('item.search_keywords', 'G20, Galaxy Smart');
    }

    public function test_admin_can_add_keying_guide_step_with_preset_title(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'Galaxy 20']);

        $this->actingAs($admin)
            ->postJson("/api/support/management/guides/{$product->id}/steps", [
                'section_type' => 'pin',
                'entry_method' => 'Tuş Takımı',
                'entry_format' => 'Pin Ekleme',
                'content' => "Menüye gir\nPIN ekle",
                'is_active' => true,
                'sort_order' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('item.title', 'Pin Ekleme')
            ->assertJsonPath('item.entry_method', 'Tuş Takımı')
            ->assertJsonPath('item.entry_format', 'Pin Ekleme');
    }

    public function test_admin_can_add_keying_guide_step_with_custom_title(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'DDL610']);

        $this->actingAs($admin)
            ->postJson("/api/support/management/guides/{$product->id}/steps", [
                'section_type' => 'other',
                'custom_title' => 'Servis Modu',
                'entry_method' => 'TTLOCK',
                'entry_format' => 'Servis Modu',
                'content' => 'Servis modunu aç.',
                'is_active' => true,
                'sort_order' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('item.title', 'Servis Modu')
            ->assertJsonPath('item.entry_method', 'TTLOCK')
            ->assertJsonPath('item.entry_format', 'Servis Modu')
            ->assertJsonPath('item.custom_title', 'Servis Modu');
    }

    public function test_admin_can_create_guide_product_with_device_name(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($admin)
            ->postJson('/api/support/management/guides', [
                'product_name' => 'Retina 30',
                'search_keywords' => 'RETINA30, yüz tanıma',
                'is_active' => true,
                'sort_order' => 15,
            ])
            ->assertOk()
            ->assertJsonPath('item.product_name', 'Retina 30')
            ->assertJsonPath('item.sort_order', 15);
    }

    public function test_admin_can_add_guide_step_with_method_and_format(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'Retina 30']);

        $this->actingAs($admin)
            ->postJson("/api/support/management/guides/{$product->id}/steps", [
                'section_type' => 'fingerprint',
                'entry_method' => 'TTLOCK',
                'entry_format' => 'Parmak İzi Ekleme',
                'content' => 'Parmak izi menüsünü aç.',
                'is_active' => true,
                'sort_order' => 7,
            ])
            ->assertOk()
            ->assertJsonPath('item.title', 'Parmak İzi Ekleme')
            ->assertJsonPath('item.entry_method', 'TTLOCK')
            ->assertJsonPath('item.entry_format', 'Parmak İzi Ekleme');
    }

    public function test_admin_can_add_custom_other_title(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'Retina 30']);

        $this->actingAs($admin)
            ->postJson("/api/support/management/guides/{$product->id}/steps", [
                'section_type' => 'other',
                'custom_title' => 'Servis Menüsü',
                'entry_method' => 'GATEWAY',
                'entry_format' => 'Servis Menüsü',
                'content' => 'Servis menüsüne gir.',
                'is_active' => true,
                'sort_order' => 8,
            ])
            ->assertOk()
            ->assertJsonPath('item.title', 'Servis Menüsü')
            ->assertJsonPath('item.custom_title', 'Servis Menüsü')
            ->assertJsonPath('item.entry_method', 'GATEWAY')
            ->assertJsonPath('item.entry_format', 'Servis Menüsü');
    }

    public function test_guide_management_does_not_require_stock_code(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $product = $this->createGuideProduct(['product_name' => 'Stok Kodsuz Cihaz']);

        $this->actingAs($admin)
            ->postJson("/api/support/management/guides/{$product->id}/steps", [
                'section_type' => 'pairing',
                'entry_method' => 'Uygulama ile Eşleme',
                'entry_format' => 'Cihaz Eşleme',
                'content' => 'Stok kodu olmadan eşleme adımı.',
                'is_active' => true,
                'sort_order' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('item.entry_format', 'Cihaz Eşleme');
    }

    public function test_support_page_filters_db_guide_by_device_method_format(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        $product = $this->createGuideProduct([
            'product_name' => 'Alpha-VP',
            'search_keywords' => 'ALPHA VP',
            'sort_order' => 1,
        ]);
        $this->createGuideStep($product, [
            'entry_method' => 'Uygulama ile Eşleme',
            'entry_format' => 'Cihaz Eşleme',
            'title' => 'Cihaz Eşleme',
            'content' => 'Alpha eşleme adımı.',
        ]);

        $entries = collect($this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->inertiaProps('supportGuideData.entries'));
        $filtered = $entries->filter(fn (array $entry): bool => in_array('Alpha-VP', $entry['devices'], true)
            && ($entry['method'] ?? null) === 'Uygulama ile Eşleme'
            && ($entry['guideType'] ?? null) === 'Cihaz Eşleme');

        $this->assertCount(1, $filtered);
    }

    public function test_keying_guide_search_matches_product_name(): void
    {
        $admin = User::factory()->create(['role_code' => 'admin']);
        $this->createGuideProduct(['product_name' => 'ManagedOnlyGalaxyCodex']);
        $this->createGuideProduct(['product_name' => 'ManagedOnlyDdlCodex']);

        $response = $this->actingAs($admin)
            ->getJson('/api/support/management/guides?search=ManagedOnlyGalaxyCodex')
            ->assertOk()
            ->json();

        $this->assertCount(1, $response['items']);
        $this->assertSame('ManagedOnlyGalaxyCodex', $response['items'][0]['product_name']);
    }

    public function test_support_page_shows_product_guide_steps(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        $product = $this->createGuideProduct(['product_name' => 'Galaxy 20', 'sort_order' => 1]);
        $this->createGuideStep($product, [
            'section_type' => 'pin',
            'entry_method' => 'Tuş Takımı',
            'entry_format' => 'Pin Ekleme',
            'title' => 'Pin Ekleme',
            'content' => "Menüye gir\nPIN ekle",
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('panel/support')
                ->where('supportGuideData.entries.0.method', 'Tuş Takımı')
                ->where('supportGuideData.entries.0.guideType', 'Pin Ekleme')
                ->where('supportGuideData.entries.0.sections.0.title', 'Pin Ekleme')
                ->where('supportGuideData.entries.0.sections.0.steps.0', 'Menüye gir'));
    }

    public function test_support_page_does_not_show_empty_guide_cards(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        $this->createGuideProduct(['product_name' => 'Boş Ürün', 'sort_order' => 1]);

        $this->actingAs($user)
            ->get('/support/keypad-guide')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('supportGuideData.entries', fn ($entries): bool => ! collect($entries)
                    ->contains(fn (array $entry): bool => ($entry['guideType'] ?? null) === 'Boş Ürün')));
    }

    public function test_support_management_seed_does_not_include_smoke_guides(): void
    {
        foreach (['Browser Rehber', 'Smoke Tuşlama', 'SmokeModel', 'SMOKE_REHBER'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                file_get_contents(database_path('migrations/2026_06_15_120000_add_support_management_imports.php')) ?: '',
            );
            $this->assertStringNotContainsString(
                $needle,
                file_get_contents(database_path('seeders/PanelMetadataSeeder.php')) ?: '',
            );
        }
    }

    public function test_browser_smoke_data_is_cleaned_after_smoke(): void
    {
        $smokeNeedles = ['Browser Rehber', 'Smoke Tuşlama', 'SmokeModel', 'SMOKE_REHBER', 'Manual Browser Kilit', 'Browser Test Kilit'];

        foreach ($smokeNeedles as $needle) {
            $this->assertFalse(
                SupportKeyingGuideProduct::query()->where('product_name', 'like', '%'.$needle.'%')->exists(),
                "Unexpected smoke guide product remains: {$needle}",
            );
            $this->assertFalse(
                SupportActivationCode::query()->where('stock_name', 'like', '%'.$needle.'%')->exists(),
                "Unexpected smoke activation remains: {$needle}",
            );
        }
    }

    public function test_non_admin_cannot_manage_keying_guides(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);

        $this->actingAs($user)
            ->postJson('/api/support/management/guides', [
                'product_name' => 'Galaxy 20',
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    public function test_support_activation_search_finds_by_serial(): void
    {
        $this->assertActivationSearchFinds('UNITCLEANXYZ-ZXCVBN');
    }

    public function test_support_activation_search_finds_by_clean_serial(): void
    {
        $this->assertActivationSearchFinds('UNITCLEANXYZ');
    }

    public function test_support_activation_search_finds_by_activation_code(): void
    {
        $this->assertActivationSearchFinds('ZXCVBN');
    }

    public function test_support_activation_search_finds_by_search_code(): void
    {
        $this->assertActivationSearchFinds('MNL615');
    }

    public function test_support_activation_result_shows_matching_guide_by_product_name(): void
    {
        $user = User::factory()->create(['role_code' => 'sales']);
        $this->createActivation([
            'stock_code' => 'GALAXY-STK',
            'stock_name' => 'Galaxy 20 Akıllı Kilit',
            'serial_number' => 'GALAXY20SERIAL-888999',
            'serial_number_clean' => 'GALAXY20SERIAL',
            'search_code' => 'GAL-20',
            'activation_code' => '888999',
        ]);
        $product = $this->createGuideProduct(['product_name' => 'Galaxy 20']);
        $this->createGuideStep($product, [
            'section_type' => 'pin',
            'entry_method' => 'Tuş Takımı',
            'entry_format' => 'Pin Ekleme',
            'title' => 'Pin Ekleme',
            'content' => 'PIN adımını uygula.',
        ]);

        $this->actingAs($user)
            ->getJson('/api/support/activation/search?query=888999')
            ->assertOk()
            ->assertJsonPath('items.0.matching_guide.guideType', 'Pin Ekleme')
            ->assertJsonPath('items.0.matching_guide.method', 'Tuş Takımı')
            ->assertJsonPath('items.0.matching_guide.sections.0.title', 'Pin Ekleme');
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

    private function assertActivationSearchFinds(string $query): void
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

        $response = $this->actingAs($user)
            ->getJson('/api/support/activation/search?query='.urlencode($query))
            ->assertOk()
            ->json();

        $this->assertTrue(
            collect($response['items'])->contains(fn (array $item): bool => $item['serial_number_clean'] === 'UNITCLEANXYZ'),
        );
    }

    private function createActivation(array $record): SupportActivationCode
    {
        return SupportActivationCode::query()->create(
            app(SupportActivationCodeService::class)->buildRecordPayload($record),
        );
    }

    private function createGuideProduct(array $overrides = []): SupportKeyingGuideProduct
    {
        return SupportKeyingGuideProduct::query()->create([
            'product_name' => $overrides['product_name'] ?? 'Galaxy 20',
            'search_keywords' => $overrides['search_keywords'] ?? null,
            'is_active' => $overrides['is_active'] ?? true,
            'sort_order' => $overrides['sort_order'] ?? 100,
        ]);
    }

    private function createGuideStep(SupportKeyingGuideProduct $product, array $overrides = []): SupportKeyingGuideStep
    {
        return SupportKeyingGuideStep::query()->create([
            'product_id' => $product->id,
            'section_type' => $overrides['section_type'] ?? 'pin',
            'custom_title' => $overrides['custom_title'] ?? null,
            'entry_method' => $overrides['entry_method'] ?? 'Tuş Takımı',
            'entry_format' => $overrides['entry_format'] ?? 'Pin Ekleme',
            'title' => $overrides['title'] ?? 'Pin Ekleme',
            'content' => $overrides['content'] ?? 'PIN adımı',
            'is_active' => $overrides['is_active'] ?? true,
            'sort_order' => $overrides['sort_order'] ?? 100,
        ]);
    }

    private function makeXlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'support-xlsx-');
        $xlsxPath = $path.'.xlsx';
        rename($path, $xlsxPath);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($xlsxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>
</workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($rows));
        $zip->close();

        return $xlsxPath;
    }

    private function uploadedXlsx(array $rows): UploadedFile
    {
        return new UploadedFile(
            $this->makeXlsx($rows),
            'activation.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    private function sheetXml(array $rows): string
    {
        $sheetRows = collect($rows)
            ->map(function (array $row, int $rowIndex): string {
                $cells = collect($row)
                    ->map(function (string $value, int $columnIndex) use ($rowIndex): string {
                        $cell = chr(65 + $columnIndex).($rowIndex + 1);
                        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

                        return "<c r=\"{$cell}\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>";
                    })
                    ->implode('');

                $number = $rowIndex + 1;

                return "<row r=\"{$number}\">{$cells}</row>";
            })
            ->implode('');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>{$sheetRows}</sheetData>
</worksheet>
XML;
    }

    private function sampleCsv(): string
    {
        return <<<'CSV'
STOK_KODU,STOK_ADI,SERI_NO,SERI_NO_TEMIZ,ARAMA_KODU
EP.EKK.002.14.70.R002,DDL610-5HBS - SİYAH / BLACK (70LİK KİLİT),W610LBS02E251213A03101-306572,W610LBS02E251213A03101,A03101
CSV;
    }
}
