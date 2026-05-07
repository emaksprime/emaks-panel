<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_critical_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('stock_code')->unique();
            $table->string('product_name')->nullable();
            $table->string('category')->nullable();
            $table->decimal('threshold_quantity', 18, 2);
            $table->boolean('active')->default(true);
            $table->text('note')->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->foreignId('updated_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_critical_settings');
    }
};
