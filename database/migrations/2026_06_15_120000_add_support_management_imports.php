<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('panel.support_activation_codes')) {
            Schema::table('panel.support_activation_codes', function (Blueprint $table): void {
                if (! Schema::hasColumn('panel.support_activation_codes', 'source')) {
                    $table->text('source')->nullable()->index();
                }

                if (! Schema::hasColumn('panel.support_activation_codes', 'imported_at')) {
                    $table->timestampTz('imported_at')->nullable()->index();
                }

                if (! Schema::hasColumn('panel.support_activation_codes', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable()->index();
                }

                if (! Schema::hasColumn('panel.support_activation_codes', 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable()->index();
                }

                if (! Schema::hasColumn('panel.support_activation_codes', 'import_batch_id')) {
                    $table->unsignedBigInteger('import_batch_id')->nullable()->index();
                }
            });
        }

        if (! Schema::hasTable('panel.support_activation_import_batches')) {
            Schema::create('panel.support_activation_import_batches', function (Blueprint $table): void {
                $table->id();
                $table->text('filename')->nullable();
                $table->text('source')->default('csv');
                $table->text('status')->default('committed');
                $table->integer('total_rows')->default(0);
                $table->integer('created_count')->default(0);
                $table->integer('updated_count')->default(0);
                $table->integer('skipped_count')->default(0);
                $table->integer('failed_count')->default(0);
                $table->jsonb('preview_payload')->nullable();
                $table->jsonb('result_payload')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestampTz('created_at');
                $table->timestampTz('updated_at');

                $table->index('source');
                $table->index('status');
            });
        }

        if (Schema::hasTable('panel.support_guide_entries')) {
            Schema::table('panel.support_guide_entries', function (Blueprint $table): void {
                if (! Schema::hasColumn('panel.support_guide_entries', 'title')) {
                    $table->text('title')->nullable();
                }

                if (! Schema::hasColumn('panel.support_guide_entries', 'stok_kodu')) {
                    $table->text('stok_kodu')->nullable()->index();
                }

                if (! Schema::hasColumn('panel.support_guide_entries', 'product_keyword')) {
                    $table->text('product_keyword')->nullable()->index();
                }

                if (! Schema::hasColumn('panel.support_guide_entries', 'guide_content')) {
                    $table->text('guide_content')->nullable();
                }

                if (! Schema::hasColumn('panel.support_guide_entries', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable()->index();
                }

                if (! Schema::hasColumn('panel.support_guide_entries', 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable()->index();
                }
            });
        }

        $this->upsertMetadata($now);
    }

    public function down(): void
    {
        $pageId = DB::table('panel.pages')->where('code', 'support_management')->value('id');

        if ($pageId) {
            DB::table('panel.page_menu')->where('page_id', $pageId)->delete();
        }

        DB::table('panel.page_configs')->where('page_code', 'support_management')->delete();
        DB::table('panel.role_resource_permissions')->where('resource_code', 'support_management')->delete();
        DB::table('panel.pages')->where('code', 'support_management')->delete();
        DB::table('panel.resources')->where('code', 'support_management')->delete();

        Schema::dropIfExists('panel.support_activation_import_batches');
    }

    private function upsertMetadata(mixed $now): void
    {
        DB::table('panel.resources')->updateOrInsert(
            ['code' => 'support_management'],
            [
                'name' => 'Destek Yönetimi',
                'type' => 'page',
                'description' => 'Aktivasyon kodu importu ve tuşlama rehberi yönetimi',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

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

        DB::table('panel.pages')->updateOrInsert(
            ['code' => 'support_management'],
            [
                'resource_code' => 'support_management',
                'name' => 'Destek Yönetimi',
                'route' => '/support/management',
                'component' => 'panel/support-management',
                'layout_type' => 'module',
                'icon' => 'settings',
                'parent_id' => null,
                'description' => 'Aktivasyon kodu ve tuşlama rehberi yönetimi',
                'page_order' => 98,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $groupId = DB::table('panel.menu_groups')->where('code', 'support')->value('id');
        $pageId = DB::table('panel.pages')->where('code', 'support_management')->value('id');

        if ($groupId && $pageId) {
            DB::table('panel.page_menu')->updateOrInsert(
                [
                    'menu_group_id' => $groupId,
                    'page_id' => $pageId,
                ],
                [
                    'label' => 'Destek Yönetimi',
                    'icon' => 'settings',
                    'sort_order' => 98,
                    'is_visible' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        DB::table('panel.page_configs')->updateOrInsert(
            ['page_code' => 'support_management'],
            [
                'layout_json' => json_encode(['heroEyebrow' => 'Destek'], JSON_UNESCAPED_UNICODE),
                'filters_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                'datasource_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $roles = DB::table('panel.roles')->pluck('code');

        foreach ($roles as $roleCode) {
            foreach (['support', 'support_keypad_guide', 'support_activation_query'] as $resourceCode) {
                DB::table('panel.role_resource_permissions')->updateOrInsert(
                    ['role_code' => $roleCode, 'resource_code' => $resourceCode],
                    [
                        'can_view' => true,
                        'can_execute' => $roleCode === 'admin',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }

            DB::table('panel.role_resource_permissions')->updateOrInsert(
                ['role_code' => $roleCode, 'resource_code' => 'support_management'],
                [
                    'can_view' => $roleCode === 'admin',
                    'can_execute' => $roleCode === 'admin',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
};
