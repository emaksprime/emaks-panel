<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PanelMetadataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PanelMetadataSeeder::class);
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('panel/page')
                ->where('page.slug', 'dashboard')
                ->where('page.routePath', '/dashboard')
            );
    }

    public function test_dashboard_home_component_uses_authorized_navigation_modules(): void
    {
        $dashboard = file_get_contents(resource_path('js/pages/panel/DashboardHome.jsx')) ?: '';
        $panelPage = file_get_contents(resource_path('js/pages/panel/page.tsx')) ?: '';
        $header = file_get_contents(resource_path('js/components/app-sidebar-header.tsx')) ?: '';
        $appLogo = file_get_contents(resource_path('js/components/app-logo.tsx')) ?: '';
        $dashboard = preg_replace('/\s+/', ' ', $dashboard) ?: '';

        $this->assertStringContainsString('DashboardHome', $panelPage);
        $this->assertStringContainsString("matchesPage('dashboard', '/dashboard')", $panelPage);
        $this->assertStringContainsString('panelNavigation?.groups', $dashboard);
        $this->assertStringContainsString('visibleHrefs', $dashboard);
        $this->assertMatchesRegularExpression('/firstVisibleHref\(\s*card\.candidates,\s*visibleHrefs,?\s*\)/', $dashboard);
        $this->assertStringContainsString('.filter((card) => card.href !== null)', $dashboard);
        $this->assertStringContainsString('Hoş geldiniz', $dashboard);
        $this->assertStringContainsString('Operasyon ekranlarına buradan hızlıca ulaşabilirsiniz.', $dashboard);
        $this->assertStringNotContainsString('Yetkiniz olan', $dashboard);
        $this->assertStringContainsString('Web Sitesine Git', $dashboard);
        $this->assertStringContainsString('https://www.emaksprime.com', $dashboard);
        $this->assertStringContainsString('target="_blank"', $dashboard);
        $this->assertStringContainsString('rel="noopener noreferrer"', $dashboard);
        $this->assertStringContainsString('Satış Yönetimi', $dashboard);
        $this->assertStringContainsString('Stok Yönetimi', $dashboard);
        $this->assertStringContainsString('Sipariş Yönetimi', $dashboard);
        $this->assertStringContainsString('Teknik Servis', $dashboard);
        $this->assertStringContainsString('Müşteri Yönetimi', $dashboard);
        $this->assertStringContainsString('Proforma', $dashboard);
        $this->assertStringContainsString('/assets/primecrm/emaks-prime.png', $dashboard);
        $this->assertStringContainsString('Emaks Prime logo', $dashboard);
        $this->assertStringContainsString('h-9 w-auto object-contain', $dashboard);
        $this->assertStringNotContainsString('Sparkles', $dashboard);
        $this->assertStringNotContainsString('relative mt-6 h-2', $dashboard);
        $this->assertStringNotContainsString('w-2/3 rounded-full', $dashboard);
        $this->assertStringContainsString('Ana Giriş', $header);
        $this->assertStringContainsString('Operasyon Paneli', $header);
        $this->assertStringContainsString('const showContextBadges = !isDashboard', $header);
        $this->assertStringContainsString('{showContextBadges &&', $header);
        $this->assertStringContainsString('href="/dashboard"', file_get_contents(resource_path('js/layouts/module-layout.tsx')) ?: '');
        $this->assertStringContainsString('Operasyon Paneli', $appLogo);
        $this->assertStringContainsString('Emaks Prime logo', $appLogo);
        $this->assertStringContainsString('flex-col', $appLogo);
        $this->assertStringContainsString('whitespace-nowrap', $appLogo);
        $this->assertStringNotContainsString('>Emaks Prime<', $appLogo);
        $this->assertStringNotContainsString('truncate', $appLogo);
        $this->assertStringNotContainsString('Güvenli yetki görünümü', $dashboard);
        $this->assertStringNotContainsString('erişilebilir modül', $dashboard);
        $this->assertStringNotContainsString('Operasyon Merkezi', $dashboard);
        $this->assertStringNotContainsString('backend yetki payload', $dashboard);
    }
}
