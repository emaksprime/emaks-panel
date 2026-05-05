<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table) {
            $table->decimal('travel_round_trip_km', 8, 2)->nullable()->after('source_channel');
            $table->decimal('travel_billable_km', 8, 2)->nullable()->after('travel_round_trip_km');
            $table->decimal('travel_fee_amount', 10, 2)->nullable()->after('travel_billable_km');
            $table->string('travel_calculation_source')->nullable()->default('manual')->after('travel_fee_amount');
            $table->timestamp('travel_calculated_at')->nullable()->after('travel_calculation_source');
        });
    }

    public function down(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table) {
            $table->dropColumn([
                'travel_round_trip_km',
                'travel_billable_km',
                'travel_fee_amount',
                'travel_calculation_source',
                'travel_calculated_at',
            ]);
        });
    }
};
