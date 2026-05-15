<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('technical_service_route_quotes')) {
            return;
        }

        Schema::create('technical_service_route_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_service_request_id')
                ->constrained('technical_service_requests')
                ->cascadeOnDelete();
            $table->foreignId('technician_id')
                ->constrained('technical_service_technicians')
                ->cascadeOnDelete();
            $table->decimal('origin_latitude', 10, 7)->nullable();
            $table->decimal('origin_longitude', 10, 7)->nullable();
            $table->decimal('destination_latitude', 10, 7)->nullable();
            $table->decimal('destination_longitude', 10, 7)->nullable();
            $table->unsignedInteger('distance_meters')->nullable();
            $table->decimal('distance_km', 10, 2)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->decimal('threshold_km', 8, 2)->default(30);
            $table->decimal('extra_km', 10, 2)->default(0);
            $table->decimal('fee_per_km', 10, 2)->nullable();
            $table->decimal('fee_amount', 12, 2)->nullable();
            $table->boolean('travel_fee_required')->default(false);
            $table->string('provider', 64)->default('google_routes');
            $table->string('status', 32)->default('calculated');
            $table->text('error_message')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->index('technical_service_request_id', 'ts_route_quotes_request_idx');
            $table->index('technician_id', 'ts_route_quotes_technician_idx');
            $table->index('calculated_at', 'ts_route_quotes_calculated_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_route_quotes');
    }
};
