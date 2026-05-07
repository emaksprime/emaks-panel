<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activation_code_records', function (Blueprint $table) {
            $table->id();
            $table->string('stock_code')->nullable();
            $table->string('stock_name')->nullable();
            $table->string('serial_no')->unique();
            $table->string('serial_prefix')->nullable();
            $table->string('activation_code')->nullable();
            $table->string('serial_no_clean');
            $table->string('serial_tail_6', 6)->nullable();
            $table->string('serial_tail_10', 10)->nullable();
            $table->string('search_code')->nullable();
            $table->string('source_file_name')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('serial_no_clean');
            $table->index('serial_tail_6');
            $table->index('serial_tail_10');
            $table->index('search_code');
            $table->index('activation_code');
            $table->index('stock_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_code_records');
    }
};
