<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_service_technicians', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->text('address')->nullable();
            $table->text('default_start_address')->nullable();
            $table->boolean('active')->default(true);
            $table->text('note')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('active');
            $table->index(['city', 'district']);
        });

        Schema::table('technical_service_requests', function (Blueprint $table) {
            $table->foreignId('technical_service_technician_id')
                ->nullable()
                ->after('technician_name')
                ->constrained('technical_service_technicians')
                ->nullOnDelete();
        });

        $this->upsertNavigation();
    }

    public function down(): void
    {
        DB::table('panel.page_menu')
            ->whereIn('page_id', DB::table('panel.pages')->where('code', 'technical_service_technicians')->pluck('id'))
            ->delete();

        DB::table('panel.pages')->where('code', 'technical_service_technicians')->delete();

        Schema::table('technical_service_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technical_service_technician_id');
        });

        Schema::dropIfExists('technical_service_technicians');
    }

    private function upsertNavigation(): void
    {
        DB::table('panel.pages')->updateOrInsert(
            ['code' => 'technical_service_technicians'],
            [
                'resource_code' => 'technical_service',
                'name' => 'Ustalar / Çilingirler',
                'route' => '/technical-service/technicians',
                'component' => 'panel/technical-service-technicians',
                'layout_type' => 'module',
                'icon' => 'users',
                'description' => 'Teknik servis usta ve çilingir kayıtları',
                'page_order' => 76,
                'active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $pageId = DB::table('panel.pages')->where('code', 'technical_service_technicians')->value('id');
        $groupId = DB::table('panel.menu_groups')->where('code', 'technical_service')->value('id');

        if ($pageId && $groupId) {
            DB::table('panel.page_menu')->updateOrInsert(
                [
                    'menu_group_id' => $groupId,
                    'page_id' => $pageId,
                ],
                [
                    'label' => 'Ustalar / Çilingirler',
                    'icon' => 'users',
                    'sort_order' => 76,
                    'is_visible' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
};
