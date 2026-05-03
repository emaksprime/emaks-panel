<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warranty_card_id')->constrained('warranty_cards')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('title');
            $table->text('note')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->timestamps();

            $table->index('event_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_events');
    }
};
