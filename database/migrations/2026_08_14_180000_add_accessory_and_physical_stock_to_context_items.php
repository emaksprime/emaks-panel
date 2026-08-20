<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_payment_order_context_items', function (Blueprint $table): void {
            $table->decimal('physical_stock_total_snapshot', 18, 6)
                ->nullable()
                ->after('availability_verified');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE technical_service_payment_order_context_items DROP CONSTRAINT IF EXISTS ts_poci_part_only');
            DB::statement("ALTER TABLE technical_service_payment_order_context_items ADD CONSTRAINT ts_poci_part_or_accessory CHECK (item_kind IN ('part', 'accessory'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            if (DB::table('technical_service_payment_order_context_items')->where('item_kind', 'accessory')->exists()) {
                throw new RuntimeException('ACCESSORY_CONTEXT_ROWS_REQUIRE_MANUAL_REVIEW');
            }
            DB::statement('ALTER TABLE technical_service_payment_order_context_items DROP CONSTRAINT IF EXISTS ts_poci_part_or_accessory');
            DB::statement("ALTER TABLE technical_service_payment_order_context_items ADD CONSTRAINT ts_poci_part_only CHECK (item_kind = 'part')");
        }

        Schema::table('technical_service_payment_order_context_items', function (Blueprint $table): void {
            $table->dropColumn('physical_stock_total_snapshot');
        });
    }
};
