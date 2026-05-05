<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table) {
            $table->decimal('technician_payment_amount', 12, 2)->nullable()->after('travel_fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table) {
            $table->dropColumn('technician_payment_amount');
        });
    }
};
