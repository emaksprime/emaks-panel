<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('panel.resources')->updateOrInsert(
            ['code' => 'activation_code_search'],
            [
                'name' => 'Aktivasyon Kodu Bul',
                'type' => 'page',
                'description' => 'Seri numarasından aktivasyon kodu arama ekranı',
                'active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        DB::table('panel.pages')->updateOrInsert(
            ['code' => 'activation_code_search'],
            [
                'resource_code' => 'activation_code_search',
                'name' => 'Aktivasyon Kodu Bul',
                'route' => '/activation-code-search',
                'component' => 'panel/activation-code-search',
                'layout_type' => 'module',
                'icon' => 'key-round',
                'description' => 'Seri numarası, kısa kod veya aktivasyon kodu ile ürün kaydı arayın.',
                'page_order' => 78,
                'active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $technicalGroupId = DB::table('panel.menu_groups')->where('code', 'technical_service')->value('id');
        $pageId = DB::table('panel.pages')->where('code', 'activation_code_search')->value('id');

        if ($technicalGroupId !== null && $pageId !== null) {
            DB::table('panel.page_menu')->updateOrInsert(
                [
                    'menu_group_id' => $technicalGroupId,
                    'page_id' => $pageId,
                ],
                [
                    'label' => 'Aktivasyon Kodu Bul',
                    'icon' => 'key-round',
                    'sort_order' => 78,
                    'is_visible' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        DB::table('panel.page_configs')->updateOrInsert(
            ['page_code' => 'activation_code_search'],
            [
                'layout_json' => json_encode([
                    'heroEyebrow' => 'Teknik Servis',
                ], JSON_UNESCAPED_UNICODE),
                'filters_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                'datasource_id' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        foreach ([
            'admin' => true,
            'manager' => true,
            'technical' => true,
            'sales' => false,
            'stock' => false,
            'orders' => false,
            'customer' => false,
            'proforma' => false,
            'viewer' => false,
        ] as $roleCode => $canView) {
            if (! DB::table('panel.roles')->where('code', $roleCode)->exists()) {
                continue;
            }

            DB::table('panel.role_resource_permissions')->updateOrInsert(
                [
                    'role_code' => $roleCode,
                    'resource_code' => 'activation_code_search',
                ],
                [
                    'can_view' => $canView,
                    'can_execute' => $roleCode === 'admin',
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('panel.page_configs')->where('page_code', 'activation_code_search')->delete();
        DB::table('panel.page_menu')->whereIn('page_id', function ($query) {
            $query->select('id')->from('panel.pages')->where('code', 'activation_code_search');
        })->delete();
        DB::table('panel.pages')->where('code', 'activation_code_search')->delete();
        DB::table('panel.role_resource_permissions')->where('resource_code', 'activation_code_search')->delete();
        DB::table('panel.resources')->where('code', 'activation_code_search')->delete();
    }
};
