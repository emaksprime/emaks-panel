<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('old_warranty_card_id')->nullable()->constrained('warranty_cards')->nullOnDelete();
            $table->foreignId('new_warranty_card_id')->nullable()->constrained('warranty_cards')->nullOnDelete();
            $table->string('old_serial_no');
            $table->string('new_serial_no');
            $table->date('replacement_date');
            $table->unsignedInteger('remaining_warranty_days')->default(0);
            $table->date('old_warranty_ends_at')->nullable();
            $table->date('new_warranty_started_at')->nullable();
            $table->date('new_warranty_ends_at')->nullable();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index('old_serial_no');
            $table->index('new_serial_no');
            $table->index('replacement_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_transfers');
    }
};
