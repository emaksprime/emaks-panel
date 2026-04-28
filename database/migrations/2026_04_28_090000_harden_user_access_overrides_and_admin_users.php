<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            if (! Schema::hasColumn('panel.user_access', 'can_view')) {
                Schema::table('panel.user_access', function ($table) {
                    $table->boolean('can_view')->default(true)->after('resource_code');
                });
            }

            if (! Schema::hasColumn('panel.users', 'force_password_change')) {
                Schema::table('panel.users', function ($table) {
                    $table->boolean('force_password_change')->default(false)->after('aktif');
                });
            }

            return;
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS panel');
        DB::statement('ALTER TABLE panel.user_access ADD COLUMN IF NOT EXISTS can_view boolean DEFAULT true NOT NULL');
        DB::statement('ALTER TABLE panel.users ADD COLUMN IF NOT EXISTS force_password_change boolean DEFAULT false NOT NULL');
    }

    public function down(): void
    {
        // Production-safe migration: keep access semantics and user flags intact.
    }
};
