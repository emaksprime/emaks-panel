<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_service_earning_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('earning_id')->constrained('technical_service_earnings')->cascadeOnDelete();
            $table->foreignId('technical_service_request_id')->constrained('technical_service_requests')->cascadeOnDelete();
            $table->string('mrn');
            $table->timestamp('job_date');
            $table->string('customer_city')->nullable();
            $table->string('customer_district')->nullable();
            $table->string('service_type')->nullable();
            $table->string('product_name')->nullable();
            $table->string('serial_number')->nullable();
            $table->decimal('labor_amount', 12, 2)->default(0);
            $table->decimal('travel_round_trip_km', 12, 2)->default(0);
            $table->decimal('travel_billable_km', 12, 2)->default(0);
            $table->decimal('travel_fee_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('job_date');
            $table->unique(['earning_id', 'technical_service_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_earning_items');
    }
};
