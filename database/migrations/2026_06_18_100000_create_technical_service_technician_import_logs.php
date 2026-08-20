<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('technical_service_technician_import_batches')) {
            Schema::create('technical_service_technician_import_batches', function (Blueprint $table): void {
                $table->id();
                $table->string('file_name');
                $table->string('file_hash', 128)->index();
                $table->string('preview_hash', 128)->index();
                $table->string('source_type', 16);
                $table->string('sheet_name')->nullable();
                $table->json('dry_run_summary')->nullable();
                $table->json('apply_summary')->nullable();
                $table->string('status', 32)->default('previewed')->index();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('applied_by')->nullable()->index();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('technical_service_technician_import_rows')) {
            Schema::create('technical_service_technician_import_rows', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('batch_id')
                    ->constrained('technical_service_technician_import_batches')
                    ->cascadeOnDelete();
                $table->unsignedInteger('row_number')->index();
                $table->string('action', 32)->index();
                $table->string('status', 32)->default('planned')->index();
                $table->unsignedBigInteger('technician_id')->nullable()->index();
                $table->unsignedBigInteger('partner_id')->nullable()->index();
                $table->unsignedBigInteger('link_id')->nullable()->index();
                $table->json('normalized_payload')->nullable();
                $table->json('changed_fields')->nullable();
                $table->json('warnings')->nullable();
                $table->json('errors')->nullable();
                $table->json('geocode_result')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_technician_import_rows');
        Schema::dropIfExists('technical_service_technician_import_batches');
    }
};
