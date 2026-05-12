<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ($this->resources() as $resource) {
            DB::table('panel.resources')->updateOrInsert(
                ['code' => $resource['code']],
                [
                    'name' => $resource['name'],
                    'type' => 'page',
                    'description' => $resource['description'],
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        DB::table('panel.menu_groups')->updateOrInsert(
            ['code' => 'support'],
            [
                'name' => 'Destek',
                'icon' => 'life-buoy',
                'menu_order' => 65,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        foreach ($this->pages() as $page) {
            DB::table('panel.pages')->updateOrInsert(
                ['code' => $page['code']],
                [
                    'resource_code' => $page['resource_code'],
                    'name' => $page['name'],
                    'route' => $page['route'],
                    'component' => 'panel/support',
                    'layout_type' => 'module',
                    'icon' => 'life-buoy',
                    'parent_id' => null,
                    'description' => $page['description'],
                    'page_order' => $page['page_order'],
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $groupId = DB::table('panel.menu_groups')->where('code', 'support')->value('id');
        $pageId = DB::table('panel.pages')->where('code', 'support')->value('id');

        if ($groupId && $pageId) {
            DB::table('panel.page_menu')->updateOrInsert(
                [
                    'menu_group_id' => $groupId,
                    'page_id' => $pageId,
                ],
                [
                    'label' => 'Destek Merkezi',
                    'icon' => 'life-buoy',
                    'sort_order' => 95,
                    'is_visible' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        DB::table('panel.page_configs')->updateOrInsert(
            ['page_code' => 'support'],
            [
                'layout_json' => json_encode([
                    'heroEyebrow' => 'Destek',
                    'moduleTabs' => [
                        ['label' => 'Tuşlama ve Kurulum Rehberi', 'href' => '/support/keypad-guide'],
                        ['label' => 'Aktivasyon Sorgu', 'href' => '/support/activation'],
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'filters_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                'datasource_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        foreach (['support_keypad_guide', 'support_activation_query'] as $pageCode) {
            DB::table('panel.page_configs')->updateOrInsert(
                ['page_code' => $pageCode],
                [
                    'layout_json' => json_encode(['heroEyebrow' => 'Destek'], JSON_UNESCAPED_UNICODE),
                    'filters_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                    'datasource_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        DB::table('panel.role_resource_permissions')
            ->whereIn('resource_code', array_column($this->resources(), 'code'))
            ->delete();
    }

    public function down(): void
    {
        $pageId = DB::table('panel.pages')->where('code', 'support')->value('id');

        if ($pageId) {
            DB::table('panel.page_menu')->where('page_id', $pageId)->delete();
        }

        DB::table('panel.page_configs')->whereIn('page_code', array_column($this->pages(), 'code'))->delete();
        DB::table('panel.role_resource_permissions')->whereIn('resource_code', array_column($this->resources(), 'code'))->delete();
        DB::table('panel.pages')->whereIn('code', array_column($this->pages(), 'code'))->delete();
        DB::table('panel.menu_groups')->where('code', 'support')->delete();
        DB::table('panel.resources')->whereIn('code', array_column($this->resources(), 'code'))->delete();
    }

    /**
     * @return list<array{code: string, name: string, description: string}>
     */
    private function resources(): array
    {
        return [
            [
                'code' => 'support',
                'name' => 'Destek Merkezi',
                'description' => 'Philips / Emaks Prime cihaz kurulum, tuşlama ve aktivasyon destek ekranı',
            ],
            [
                'code' => 'support_keypad_guide',
                'name' => 'Destek - Tuşlama ve Kurulum Rehberi',
                'description' => 'Cihaz kurulum ve tuşlama rehberi içeriği',
            ],
            [
                'code' => 'support_activation_query',
                'name' => 'Destek - Aktivasyon Sorgu',
                'description' => 'Aktivasyon sorgu ekranı erişimi',
            ],
        ];
    }

    /**
     * @return list<array{code: string, resource_code: string, name: string, route: string, description: string, page_order: int}>
     */
    private function pages(): array
    {
        return [
            [
                'code' => 'support',
                'resource_code' => 'support',
                'name' => 'Destek Merkezi',
                'route' => '/support',
                'description' => 'Philips / Emaks Prime cihaz kurulum, tuşlama ve aktivasyon destek ekranı',
                'page_order' => 95,
            ],
            [
                'code' => 'support_keypad_guide',
                'resource_code' => 'support_keypad_guide',
                'name' => 'Tuşlama ve Kurulum Rehberi',
                'route' => '/support/keypad-guide',
                'description' => 'Cihaz kurulum ve tuşlama rehberi',
                'page_order' => 96,
            ],
            [
                'code' => 'support_activation_query',
                'resource_code' => 'support_activation_query',
                'name' => 'Aktivasyon Sorgu',
                'route' => '/support/activation',
                'description' => 'Aktivasyon sorgu ekranı',
                'page_order' => 97,
            ],
        ];
    }
};
