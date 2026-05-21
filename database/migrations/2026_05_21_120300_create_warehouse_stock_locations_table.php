<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel.warehouse_stock_locations', function (Blueprint $table) {
            $table->id();
            $table->integer('warehouse_no');
            $table->string('rack_code');
            $table->string('stock_code');
            $table->string('stock_name')->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->string('source')->default('manual');
            $table->string('last_operation_no')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_no', 'rack_code', 'stock_code'], 'warehouse_stock_locations_unique_position');
            $table->index(['warehouse_no', 'rack_code'], 'warehouse_stock_locations_rack_index');
            $table->index('stock_code', 'warehouse_stock_locations_stock_code_index');
            $table->index('last_operation_no', 'warehouse_stock_locations_operation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel.warehouse_stock_locations');
    }
};
