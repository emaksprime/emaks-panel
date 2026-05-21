<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel.warehouse_serial_locations', function (Blueprint $table) {
            $table->id();
            $table->string('serial_no')->unique();
            $table->string('stock_code')->nullable();
            $table->string('stock_name')->nullable();
            $table->integer('warehouse_no');
            $table->string('rack_code')->nullable();
            $table->string('status')->default('in_stock');
            $table->string('source')->default('manual');
            $table->string('last_operation_no')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['warehouse_no', 'rack_code']);
            $table->index('status');
            $table->index('stock_code');
            $table->index('last_operation_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel.warehouse_serial_locations');
    }
};
