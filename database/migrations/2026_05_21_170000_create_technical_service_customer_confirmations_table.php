<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_service_customer_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_service_request_id')
                ->constrained('technical_service_requests')
                ->cascadeOnDelete();
            $table->string('token', 96)->unique();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('customer_note')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['technical_service_request_id', 'status'], 'ts_customer_confirmations_request_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_customer_confirmations');
    }
};
