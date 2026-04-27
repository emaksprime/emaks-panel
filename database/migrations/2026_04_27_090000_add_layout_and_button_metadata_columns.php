<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel.pages', function (Blueprint $table) {
            if (! Schema::hasColumn('panel.pages', 'layout_type')) {
                $table->string('layout_type', 32)->default('module')->after('component');
            }
        });

        Schema::table('panel.buttons', function (Blueprint $table) {
            if (! Schema::hasColumn('panel.buttons', 'position')) {
                $table->string('position', 64)->nullable()->default('page_top')->after('action_target');
            }

            if (! Schema::hasColumn('panel.buttons', 'config_json')) {
                $table->json('config_json')->nullable()->after('position');
            }

            if (! Schema::hasColumn('panel.buttons', 'confirmation_required')) {
                $table->boolean('confirmation_required')->default(false)->after('config_json');
            }

            if (! Schema::hasColumn('panel.buttons', 'confirmation_text')) {
                $table->string('confirmation_text')->nullable()->after('confirmation_required');
            }
        });
    }

    public function down(): void
    {
        Schema::table('panel.buttons', function (Blueprint $table) {
            foreach (['confirmation_text', 'confirmation_required', 'config_json', 'position'] as $column) {
                if (Schema::hasColumn('panel.buttons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('panel.pages', function (Blueprint $table) {
            if (Schema::hasColumn('panel.pages', 'layout_type')) {
                $table->dropColumn('layout_type');
            }
        });
    }
};
