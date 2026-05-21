<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel.warehouse_rack_operations', function (Blueprint $table) {
            $table->id();
            $table->string('operation_no')->unique();
            $table->string('operation_type')->default('rack_transfer');
            $table->integer('source_warehouse_no')->nullable();
            $table->string('source_rack_code')->nullable();
            $table->integer('target_warehouse_no')->nullable();
            $table->string('target_rack_code')->nullable();
            $table->string('serial_no')->nullable();
            $table->string('stock_code')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->string('status')->default('draft');
            $table->string('validation_status')->nullable();
            $table->text('validation_message')->nullable();
            $table->foreignId('created_by')->nullable()->index();
            $table->foreignId('completed_by')->nullable()->index();
            $table->foreignId('cancelled_by')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('operation_type');
            $table->index('status');
            $table->index('serial_no');
            $table->index('stock_code');
            $table->index(['source_warehouse_no', 'source_rack_code']);
            $table->index(['target_warehouse_no', 'target_rack_code']);
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('panel.warehouse_rack_operations', function (Blueprint $table) {
                $table->foreign('created_by')->references('id')->on('panel.users')->nullOnDelete();
                $table->foreign('completed_by')->references('id')->on('panel.users')->nullOnDelete();
                $table->foreign('cancelled_by')->references('id')->on('panel.users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('panel.warehouse_rack_operations');
    }
};
