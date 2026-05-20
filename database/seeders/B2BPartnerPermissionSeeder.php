<?php

namespace Database\Seeders;

use App\Models\MenuGroup;
use App\Models\Page;
use App\Models\PageMenu;
use App\Models\Resource;
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

        $this->upsertPartnerDirectoryPage();
        $this->upsertPartnerUsersPage();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resources(): array
    {
        return [
            ['code' => 'b2b.view', 'name' => 'B2B Partner Görünümü', 'type' => 'page', 'description' => 'B2B partner modülü giriş yetkisi.', 'active' => true],
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
        ];
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
                'resource_code' => 'b2b.view',
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
}
