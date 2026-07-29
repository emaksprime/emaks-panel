<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement('CREATE SCHEMA IF NOT EXISTS panel');
        }

        if (Schema::hasTable('panel.user_cari_group_permissions')) {
            return;
        }

        Schema::create('panel.user_cari_group_permissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('cari_group_code', 50);
            $table->string('mode', 10);
            $table->timestamps();
            $table->unique(['user_id', 'cari_group_code', 'mode'], 'user_cari_group_permissions_unique');
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE panel.user_cari_group_permissions ADD CONSTRAINT user_cari_group_permissions_user_fk FOREIGN KEY (user_id) REFERENCES panel.users(id) ON DELETE CASCADE'
            );
            DB::statement(
                "ALTER TABLE panel.user_cari_group_permissions ADD CONSTRAINT user_cari_group_permissions_mode_check CHECK (mode IN ('allow', 'deny'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('panel.user_cari_group_permissions');
    }
};
