<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel.warehouse_rack_operation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->index();
            $table->integer('line_no');
            $table->string('item_type');
            $table->integer('warehouse_no');
            $table->string('source_rack_code');
            $table->string('target_rack_code');
            $table->string('serial_no')->nullable();
            $table->string('stock_code');
            $table->string('stock_name')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->string('status')->default('completed');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('serial_no', 'warehouse_rack_operation_items_serial_index');
            $table->index('stock_code', 'warehouse_rack_operation_items_stock_index');
            $table->index(['warehouse_no', 'source_rack_code'], 'warehouse_rack_operation_items_source_index');
            $table->index(['warehouse_no', 'target_rack_code'], 'warehouse_rack_operation_items_target_index');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('panel.warehouse_rack_operation_items', function (Blueprint $table) {
                $table->foreign('operation_id', 'warehouse_rack_operation_items_operation_fk')
                    ->references('id')
                    ->on('panel.warehouse_rack_operations')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('panel.warehouse_rack_operation_items');
    }
};
