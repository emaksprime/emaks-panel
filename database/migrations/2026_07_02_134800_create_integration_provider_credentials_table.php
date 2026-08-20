<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_provider_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('scope')->default('technical_service');
            $table->string('provider');
            $table->string('profile_key')->default('default');
            $table->string('mode')->default('live');
            $table->text('username_encrypted')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->text('api_key_encrypted')->nullable();
            $table->text('token_encrypted')->nullable();
            $table->string('username_mask')->nullable();
            $table->string('api_key_mask')->nullable();
            $table->string('token_mask')->nullable();
            $table->string('credentials_status')->default('missing');
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_verification_status')->nullable();
            $table->text('last_verification_message')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'provider', 'profile_key', 'mode'], 'integration_provider_credentials_unique');
            $table->index(['scope', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_provider_credentials');
    }
};
