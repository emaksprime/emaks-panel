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

        $this->assertStringContainsString('DashboardHome', $panelPage);
        $this->assertStringContainsString("matchesPage('dashboard', '/dashboard')", $panelPage);
        $this->assertStringContainsString('panelNavigation?.groups', $dashboard);
        $this->assertStringContainsString('visibleHrefs', $dashboard);
        $this->assertStringContainsString('firstVisibleHref(card.candidates, visibleHrefs)', $dashboard);
        $this->assertStringContainsString('.filter((card) => card.href !== null)', $dashboard);
        $this->assertStringContainsString('Satış Yönetimi', $dashboard);
        $this->assertStringContainsString('Stok Yönetimi', $dashboard);
        $this->assertStringContainsString('Sipariş Yönetimi', $dashboard);
        $this->assertStringContainsString('Teknik Servis', $dashboard);
        $this->assertStringContainsString('Müşteri Yönetimi', $dashboard);
        $this->assertStringContainsString('Proforma', $dashboard);
        $this->assertStringContainsString('/assets/primecrm/emaks-prime.png', $dashboard);
    }
}
