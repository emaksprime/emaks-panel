<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel.warehouse_racks', function (Blueprint $table) {
            $table->id();
            $table->integer('warehouse_no');
            $table->string('rack_code');
            $table->string('rack_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_no', 'rack_code']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel.warehouse_racks');
    }
};
