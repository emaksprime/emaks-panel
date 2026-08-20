<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_service_admin_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')
                ->constrained('technical_service_requests')
                ->restrictOnDelete();
            $table->foreignId('root_request_id')
                ->nullable()
                ->constrained('technical_service_requests')
                ->nullOnDelete();
            $table->text('request_code')->nullable();
            $table->text('root_mrn')->nullable();
            $table->string('field_key', 96)->index();
            $table->string('field_label', 160)->nullable();
            $table->string('field_group', 64)->nullable()->index();
            $table->string('status', 32)->default('pending')->index();
            $table->string('source', 32)->default('ops_admin_direct')->index();
            $table->json('old_value')->nullable();
            $table->json('requested_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->unsignedBigInteger('applied_by')->nullable()->index();
            $table->unsignedBigInteger('rejected_by')->nullable()->index();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('recompute_flags')->default(json_encode((object) []));
            $table->json('metadata')->default(json_encode((object) []));
            $table->timestamps();

            $table->index('root_request_id', 'technical_service_admin_overrides_root_request_id_index');
            $table->index('created_at');
            $table->index(['request_id', 'status'], 'ts_admin_overrides_request_status_idx');
            $table->index(['request_id', 'field_key'], 'ts_admin_overrides_request_field_idx');
        });

        $this->addPanelUserForeignKeys();
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_admin_overrides');
    }

    private function addPanelUserForeignKeys(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('technical_service_admin_overrides', function (Blueprint $table): void {
            $table->foreign('requested_by')->references('id')->on('panel.users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('panel.users')->nullOnDelete();
            $table->foreign('applied_by')->references('id')->on('panel.users')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('panel.users')->nullOnDelete();
        });
    }
};
