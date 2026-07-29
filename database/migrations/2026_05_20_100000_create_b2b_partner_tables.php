<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_partners', function (Blueprint $table) {
            $table->id();
            $table->string('partner_type', 32)->index();
            $table->string('partner_code', 128)->unique();
            $table->string('display_name');
            $table->string('mikro_cari_kodu', 128)->nullable()->index();
            $table->string('mikro_cari_unvan')->nullable();
            $table->string('cari_grup_kodu', 128)->nullable();
            $table->string('responsibility_code', 128)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('city', 128)->nullable()->index();
            $table->string('district', 128)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->foreignId('technical_service_technician_id')
                ->nullable()
                ->constrained('technical_service_technicians')
                ->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['partner_type', 'active']);
        });

        Schema::create('b2b_partner_user_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->foreignId('partner_id')->constrained('b2b_partners')->cascadeOnDelete();
            $table->string('access_scope', 64)->index();
            $table->boolean('can_view')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_update')->default(false);
            $table->boolean('can_approve')->default(false);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
            $table->unique(['user_id', 'partner_id', 'access_scope'], 'b2b_access_user_partner_scope_unique');
        });

        Schema::create('b2b_partner_user_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->foreignId('partner_id')->constrained('b2b_partners')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('phone', 64)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'partner_id'], 'b2b_profile_user_partner_unique');
        });

        Schema::create('b2b_partner_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained('b2b_partners')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });

        $this->addPanelUserForeignKeys();
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_partner_audit_logs');
        Schema::dropIfExists('b2b_partner_user_profiles');
        Schema::dropIfExists('b2b_partner_user_access');
        Schema::dropIfExists('b2b_partners');
    }

    private function addPanelUserForeignKeys(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('b2b_partner_user_access', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('panel.users')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('panel.users')->nullOnDelete();
        });

        Schema::table('b2b_partner_user_profiles', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('panel.users')->cascadeOnDelete();
        });

        Schema::table('b2b_partner_audit_logs', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('panel.users')->nullOnDelete();
        });
    }
};
