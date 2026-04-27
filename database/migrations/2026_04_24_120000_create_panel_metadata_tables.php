<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            try {
                DB::statement("ATTACH DATABASE ':memory:' AS panel");
            } catch (Throwable) {
                //
            }
        } else {
            DB::statement('CREATE SCHEMA IF NOT EXISTS panel');
        }

        Schema::create('panel.roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_super_admin')->default(false);
            $table->timestamps();
        });

        Schema::create('panel.resources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 128)->unique();
            $table->string('name');
            $table->string('type')->default('page');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('panel.menu_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->unsignedInteger('menu_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('panel.pages', function (Blueprint $table) {
            $table->id();
            $table->string('resource_code', 128)->nullable()->index();
            $table->string('code', 128)->unique();
            $table->string('name');
            $table->string('route')->unique();
            $table->string('component')->default('panel/page');
            $table->string('icon')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->unsignedInteger('page_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('panel.page_menu', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('menu_group_id');
            $table->unsignedBigInteger('page_id');
            $table->string('label')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->unique(['menu_group_id', 'page_id']);
        });

        Schema::create('panel.buttons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('page_id');
            $table->string('resource_code', 128)->nullable()->index();
            $table->string('label');
            $table->string('code', 128)->unique();
            $table->string('variant')->default('secondary');
            $table->string('action_type')->default('navigate');
            $table->string('action_target')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });

        Schema::create('panel.role_resource_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role_code', 64)->index();
            $table->string('resource_code', 128)->index();
            $table->boolean('can_view')->default(false);
            $table->boolean('can_execute')->default(false);
            $table->timestamps();
            $table->unique(['role_code', 'resource_code']);
        });

        Schema::create('panel.user_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('resource_code', 128)->index();
            $table->timestamps();
            $table->unique(['user_id', 'resource_code']);
        });

        Schema::create('panel.data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 128)->unique();
            $table->string('name');
            $table->string('db_type', 32)->default('mssql');
            $table->longText('query_template');
            $table->json('allowed_params')->nullable();
            $table->json('connection_meta')->nullable();
            $table->json('preview_payload')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('panel.page_configs', function (Blueprint $table) {
            $table->id();
            $table->string('page_code', 128)->unique();
            $table->json('layout_json')->nullable();
            $table->json('filters_json')->nullable();
            $table->unsignedBigInteger('datasource_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('panel.logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('action');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel.logs');
        Schema::dropIfExists('panel.page_configs');
        Schema::dropIfExists('panel.data_sources');
        Schema::dropIfExists('panel.user_access');
        Schema::dropIfExists('panel.role_resource_permissions');
        Schema::dropIfExists('panel.buttons');
        Schema::dropIfExists('panel.page_menu');
        Schema::dropIfExists('panel.pages');
        Schema::dropIfExists('panel.menu_groups');
        Schema::dropIfExists('panel.resources');
        Schema::dropIfExists('panel.roles');
    }
};
