<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_provider_credentials')) {
            return;
        }

        Schema::create('payment_provider_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 64)->default('technical_service');
            $table->string('provider', 64);
            $table->string('mode', 16);
            $table->text('api_key_encrypted')->nullable();
            $table->text('secret_key_encrypted')->nullable();
            $table->string('api_key_mask', 96)->nullable();
            $table->string('secret_key_mask', 96)->nullable();
            $table->string('credentials_status', 32)->default('missing');
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_verification_status', 64)->nullable();
            $table->text('last_verification_message')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'provider', 'mode'], 'payment_provider_credentials_unique');
            $table->index(['scope', 'provider'], 'payment_provider_credentials_scope_provider_idx');
            $table->index('credentials_status', 'payment_provider_credentials_status_idx');
            $table->index('updated_by', 'payment_provider_credentials_updated_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_credentials');
    }
};
