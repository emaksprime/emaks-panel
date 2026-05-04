<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_service_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('technical_service_earnings_periods')->cascadeOnDelete();
            $table->foreignId('technical_service_technician_id')->nullable()->constrained('technical_service_technicians')->nullOnDelete();
            $table->string('technician_name_snapshot');
            $table->string('city_snapshot')->nullable();
            $table->integer('job_count')->default(0);
            $table->integer('installation_count')->default(0);
            $table->integer('service_count')->default(0);
            $table->decimal('labor_total', 12, 2)->default(0);
            $table->decimal('travel_fee_total', 12, 2)->default(0);
            $table->decimal('travel_round_trip_km_total', 12, 2)->default(0);
            $table->decimal('travel_billable_km_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->string('status')->default('Kontrol Bekliyor');
            $table->text('dispute_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('technical_service_technician_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_earnings');
    }
};
