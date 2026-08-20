<?php

namespace Database\Seeders;

use App\Models\MenuGroup;
use App\Models\Page;
use App\Models\PageMenu;
use App\Models\Resource;
use App\Models\Role;
use App\Models\RoleResourcePermission;
use Illuminate\Database\Seeder;

class B2BPartnerPermissionSeeder extends Seeder
{
    public function run(): void
    {
        collect($this->resources())->each(function (array $resource): void {
            Resource::query()->updateOrCreate(
                ['code' => $resource['code']],
                $resource,
            );
        });

        $this->upsertB2BDashboardPage();
        $this->upsertPartnerDirectoryPage();
        $this->upsertPartnerUsersPage();
        $this->upsertB2BRoles();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resources(): array
    {
        return [
            ['code' => 'b2b.partners.view', 'name' => 'B2B Partner Yönetimi Görünümü', 'type' => 'page', 'description' => 'B2B partner yönetim ekranı giriş yetkisi.', 'active' => true],
            ['code' => 'b2b.manufacturers.view', 'name' => 'B2B Üretici Görünümü', 'type' => 'scope', 'description' => 'Üretici partner kayıtlarını görüntüleme yetkisi.', 'active' => true],
            ['code' => 'b2b.manufacturers.manage', 'name' => 'B2B Üretici Yönetimi', 'type' => 'action', 'description' => 'Üretici partner kayıtlarını yönetme yetkisi.', 'active' => true],
            ['code' => 'b2b.sellers.view', 'name' => 'B2B Satıcı Görünümü', 'type' => 'scope', 'description' => 'Satıcı partner kayıtlarını görüntüleme yetkisi.', 'active' => true],
            ['code' => 'b2b.sellers.manage', 'name' => 'B2B Satıcı Yönetimi', 'type' => 'action', 'description' => 'Satıcı partner kayıtlarını yönetme yetkisi.', 'active' => true],
            ['code' => 'b2b.view', 'name' => 'B2B Partner Görünümü', 'type' => 'page', 'description' => 'B2B partner modülü giriş yetkisi.', 'active' => true],
            ['code' => 'b2b.dashboard.view', 'name' => 'B2B Kokpit Görünümü', 'type' => 'page', 'description' => 'Operasyon B2B kokpit ekranı.', 'active' => true],
            ['code' => 'b2b.portal_preview.view', 'name' => 'B2B Portal Önizleme', 'type' => 'action', 'description' => 'İç operasyonun partner portalını read-only önizleme yetkisi.', 'active' => true],
            ['code' => 'b2b.manage', 'name' => 'B2B Partner Yönetimi', 'type' => 'action', 'description' => 'B2B partner yönetim aksiyonları.', 'active' => true],
            ['code' => 'b2b.dealers.view', 'name' => 'B2B Bayi Görünümü', 'type' => 'scope', 'description' => 'Bayi partner kayıtlarını görüntüleme yetkisi.', 'active' => true],
            ['code' => 'b2b.dealers.manage', 'name' => 'B2B Bayi Yönetimi', 'type' => 'action', 'description' => 'Bayi partner kayıtlarını yönetme yetkisi.', 'active' => true],
            ['code' => 'b2b.locksmiths.view', 'name' => 'B2B Çilingir Görünümü', 'type' => 'scope', 'description' => 'Çilingir partner kayıtlarını görüntüleme yetkisi.', 'active' => true],
            ['code' => 'b2b.locksmiths.manage', 'name' => 'B2B Çilingir Yönetimi', 'type' => 'action', 'description' => 'Çilingir partner kayıtlarını yönetme yetkisi.', 'active' => true],
            ['code' => 'b2b.orders.view', 'name' => 'B2B Sipariş Görünümü', 'type' => 'scope', 'description' => 'Partner sipariş özetlerini görüntüleme yetkisi.', 'active' => true],
            ['code' => 'b2b.orders.manage', 'name' => 'B2B Sipariş Yönetimi', 'type' => 'action', 'description' => 'Partner sipariş aksiyonlarını yönetme yetkisi.', 'active' => true],
            ['code' => 'b2b.stock.view', 'name' => 'B2B Stok Görünümü', 'type' => 'scope', 'description' => 'Partner stok görünümü yetkisi.', 'active' => true],
            ['code' => 'b2b.finance.view', 'name' => 'B2B Finans Görünümü', 'type' => 'scope', 'description' => 'Partner cari/risk finans görünümü yetkisi.', 'active' => true],
            ['code' => 'b2b.technical_service.view', 'name' => 'B2B Teknik Servis Görünümü', 'type' => 'scope', 'description' => 'Partner teknik servis işleri görünümü yetkisi.', 'active' => true],
            ['code' => 'b2b.partner_users.manage', 'name' => 'B2B Partner Kullanıcı Yönetimi', 'type' => 'action', 'description' => 'Partner kullanıcılarını yönetme yetkisi.', 'active' => true],
            ['code' => 'partner.portal.view', 'name' => 'Partner Portal', 'type' => 'page', 'description' => 'B2B partner portal access.', 'active' => true],
            ['code' => 'partner.dashboard.view', 'name' => 'Partner Dashboard', 'type' => 'page', 'description' => 'Partner portal dashboard.', 'active' => true],
            ['code' => 'partner.profile.view', 'name' => 'Partner Profile', 'type' => 'page', 'description' => 'Partner profile and contact details.', 'active' => true],
            ['code' => 'partner.settings.view', 'name' => 'Partner Settings', 'type' => 'page', 'description' => 'Partner portal settings and safe profile details.', 'active' => true],
            ['code' => 'partner.orders.view', 'name' => 'Partner Orders', 'type' => 'page', 'description' => 'Partner order placeholder.', 'active' => true],
            ['code' => 'partner.stock.view', 'name' => 'Partner Stock', 'type' => 'page', 'description' => 'Partner stock placeholder.', 'active' => true],
            ['code' => 'partner.service_jobs.view', 'name' => 'Partner Service Jobs', 'type' => 'page', 'description' => 'Partner technical service jobs.', 'active' => true],
            ['code' => 'partner.earnings.view', 'name' => 'Partner Earnings', 'type' => 'page', 'description' => 'Partner locksmith earnings view.', 'active' => true],
        ];
    }

    private function upsertB2BDashboardPage(): void
    {
        $group = MenuGroup::query()->updateOrCreate(
            ['code' => 'b2b'],
            [
                'name' => 'B2B',
                'icon' => 'building-2',
                'menu_order' => 84,
                'active' => true,
            ],
        );

        $page = Page::query()->updateOrCreate(
            ['code' => 'b2b_dashboard'],
            [
                'resource_code' => 'b2b.dashboard.view',
                'name' => 'Bayi & Çilingir Kokpiti',
                'route' => '/panel/b2b',
                'component' => 'panel/b2b/dashboard',
                'layout_type' => 'module',
                'icon' => 'layout-dashboard',
                'description' => 'Operasyon ve yönetim için B2B partner durum ekranı',
                'page_order' => 83,
                'active' => true,
            ],
        );

        PageMenu::query()->updateOrCreate(
            [
                'menu_group_id' => $group->id,
                'page_id' => $page->id,
            ],
            [
                'label' => 'Bayi & Çilingir Kokpiti',
                'icon' => 'layout-dashboard',
                'sort_order' => 83,
                'is_visible' => true,
            ],
        );
    }

    private function upsertPartnerDirectoryPage(): void
    {
        $group = MenuGroup::query()->updateOrCreate(
            ['code' => 'b2b'],
            [
                'name' => 'B2B',
                'icon' => 'building-2',
                'menu_order' => 84,
                'active' => true,
            ],
        );

        $page = Page::query()->updateOrCreate(
            ['code' => 'b2b_partners'],
            [
                'resource_code' => 'b2b.partners.view',
                'name' => 'B2B Partner Yönetimi',
                'route' => '/panel/b2b/partners',
                'component' => 'panel/b2b/partners',
                'layout_type' => 'module',
                'icon' => 'building-2',
                'description' => 'Bayi ve çilingir partner kayıt yönetimi',
                'page_order' => 84,
                'active' => true,
            ],
        );

        PageMenu::query()->updateOrCreate(
            [
                'menu_group_id' => $group->id,
                'page_id' => $page->id,
            ],
            [
                'label' => 'Partner Yönetimi',
                'icon' => 'building-2',
                'sort_order' => 84,
                'is_visible' => true,
            ],
        );
    }

    private function upsertPartnerUsersPage(): void
    {
        $group = MenuGroup::query()->updateOrCreate(
            ['code' => 'b2b'],
            [
                'name' => 'B2B',
                'icon' => 'building-2',
                'menu_order' => 84,
                'active' => true,
            ],
        );

        $page = Page::query()->updateOrCreate(
            ['code' => 'b2b_partner_users'],
            [
                'resource_code' => 'b2b.partner_users.manage',
                'name' => 'B2B Partner Kullanıcıları',
                'route' => '/panel/b2b/users',
                'component' => 'panel/b2b/users',
                'layout_type' => 'module',
                'icon' => 'users',
                'description' => 'Partner kullanıcı atama ve yetki matrisi',
                'page_order' => 85,
                'active' => true,
            ],
        );

        PageMenu::query()->updateOrCreate(
            [
                'menu_group_id' => $group->id,
                'page_id' => $page->id,
            ],
            [
                'label' => 'Partner Kullanıcıları',
                'icon' => 'users',
                'sort_order' => 85,
                'is_visible' => true,
            ],
        );
    }

    private function upsertB2BRoles(): void
    {
        foreach ($this->roles() as $role) {
            Role::query()->updateOrCreate(
                ['code' => $role['code']],
                [
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'is_super_admin' => false,
                ],
            );
        }

        $availableResources = Resource::query()
            ->whereIn('code', collect($this->roleDefaults())->flatten()->unique()->all())
            ->pluck('code')
            ->all();

        foreach ($this->roleDefaults() as $roleCode => $resourceCodes) {
            foreach (array_intersect($resourceCodes, $availableResources) as $resourceCode) {
                RoleResourcePermission::query()->updateOrCreate(
                    [
                        'role_code' => $roleCode,
                        'resource_code' => $resourceCode,
                    ],
                    [
                        'can_view' => true,
                        'can_execute' => false,
                    ],
                );
            }
        }

        foreach ($this->roleDefaults() as $roleCode => $resourceCodes) {
            RoleResourcePermission::query()
                ->where('role_code', $roleCode)
                ->whereNotIn('resource_code', $resourceCodes)
                ->delete();
        }
    }

    /**
     * @return array<int, array{code: string, name: string, description: string}>
     */
    private function roles(): array
    {
        return [
            [
                'code' => 'b2b_manager',
                'name' => 'B2B Yönetici',
                'description' => 'B2B partner ve partner kullanıcı yönetimi.',
            ],
            [
                'code' => 'b2b_dealer',
                'name' => 'Bayi Kullanıcısı',
                'description' => 'Bayi partner portal kullanıcısı. Partner bazlı erişim ayrıca atanır.',
            ],
            [
                'code' => 'b2b_locksmith',
                'name' => 'Çilingir Kullanıcısı',
                'description' => 'Çilingir partner portal kullanıcısı. Partner bazlı erişim ayrıca atanır.',
            ],
            [
                'code' => 'b2b_manufacturer',
                'name' => 'Üretici Kullanıcısı',
                'description' => 'Üretici partner portal kullanıcısı. Partner bazlı erişim ayrıca atanır.',
            ],
            [
                'code' => 'b2b_seller',
                'name' => 'Satıcı Kullanıcısı',
                'description' => 'Satıcı partner portal kullanıcısı. Partner bazlı erişim ayrıca atanır.',
            ],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function roleDefaults(): array
    {
        return [
            'b2b_manager' => [
                'b2b.view',
                'b2b.partners.view',
                'b2b.manage',
                'b2b.dealers.view',
                'b2b.dealers.manage',
                'b2b.manufacturers.view',
                'b2b.manufacturers.manage',
                'b2b.sellers.view',
                'b2b.sellers.manage',
                'b2b.orders.view',
                'b2b.orders.manage',
                'b2b.stock.view',
                'b2b.finance.view',
                'b2b.technical_service.view',
                'b2b.partner_users.manage',
                'partner.portal.view',
                'partner.dashboard.view',
                'partner.profile.view',
                'partner.settings.view',
                'partner.orders.view',
                'partner.stock.view',
                'partner.service_jobs.view',
                'partner.earnings.view',
            ],
            'b2b_dealer' => [
                'partner.portal.view',
                'partner.dashboard.view',
                'partner.profile.view',
                'partner.settings.view',
                'partner.orders.view',
                'partner.stock.view',
                'partner.service_jobs.view',
                'partner.earnings.view',
            ],
            'b2b_locksmith' => [
                'partner.portal.view',
                'partner.dashboard.view',
                'partner.profile.view',
                'partner.settings.view',
                'partner.service_jobs.view',
                'partner.earnings.view',
            ],
            'b2b_manufacturer' => [
                'partner.portal.view',
                'partner.dashboard.view',
                'partner.profile.view',
                'partner.settings.view',
                'partner.orders.view',
                'partner.stock.view',
            ],
            'b2b_seller' => [
                'partner.portal.view',
                'partner.dashboard.view',
                'partner.profile.view',
                'partner.settings.view',
                'partner.orders.view',
                'partner.stock.view',
            ],
        ];
    }
}
