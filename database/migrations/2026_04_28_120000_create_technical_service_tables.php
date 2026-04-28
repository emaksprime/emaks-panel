<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('mrn', 64)->unique();
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('customer_city');
            $table->string('customer_district');
            $table->text('service_address');
            $table->string('product_name');
            $table->string('product_model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('service_type');
            $table->string('status')->default('Yeni');
            $table->string('priority')->default('Orta');
            $table->string('risk_level')->default('Orta');
            $table->string('technician_name')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('description')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('source_channel')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('service_type');
            $table->index('priority');
            $table->index('risk_level');
            $table->index('scheduled_at');
            $table->index('sla_due_at');
            $table->index('serial_number');
            $table->index('customer_phone');
        });

        Schema::create('technical_service_request_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('technical_service_request_id');
            $table->string('event_type');
            $table->string('title');
            $table->text('note')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('technical_service_request_id')
                ->references('id')
                ->on('technical_service_requests')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_request_events');
        Schema::dropIfExists('technical_service_requests');
    }
};
