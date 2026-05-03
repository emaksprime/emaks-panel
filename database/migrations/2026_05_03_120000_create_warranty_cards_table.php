<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_cards', function (Blueprint $table) {
            $table->id();
            $table->string('serial_no');
            $table->string('stock_code')->nullable();
            $table->string('stock_name')->nullable();
            $table->date('last_sale_date')->nullable();
            $table->string('last_sale_customer_code')->nullable();
            $table->string('last_sale_customer_name')->nullable();
            $table->string('last_sale_document_type')->nullable();
            $table->string('last_sale_document_no')->nullable();
            $table->string('last_sale_mikro_fingerprint')->nullable();
            $table->date('installation_completed_at')->nullable();
            $table->date('warranty_started_at')->nullable();
            $table->date('warranty_ends_at')->nullable();
            $table->unsignedSmallInteger('warranty_period_months')->default(24);
            $table->string('status');
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('serial_no');
            $table->index('last_sale_mikro_fingerprint');
            $table->index('status');
            $table->index('last_sale_date');
            $table->index('warranty_started_at');
            $table->index('warranty_ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_cards');
    }
};
