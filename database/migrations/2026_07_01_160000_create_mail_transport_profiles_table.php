<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_transport_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('scope')->default('technical_service');
            $table->string('profile_key')->default('default');
            $table->string('display_name')->nullable();
            $table->boolean('outgoing_enabled')->default(false);
            $table->string('outgoing_mailer')->default('smtp');
            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->nullable();
            $table->string('smtp_encryption')->nullable();
            $table->text('smtp_username_encrypted')->nullable();
            $table->text('smtp_password_encrypted')->nullable();
            $table->string('smtp_username_mask')->nullable();
            $table->string('smtp_password_mask')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->boolean('incoming_enabled')->default(false);
            $table->string('incoming_protocol')->nullable();
            $table->string('incoming_host')->nullable();
            $table->unsignedInteger('incoming_port')->nullable();
            $table->string('incoming_encryption')->nullable();
            $table->text('incoming_username_encrypted')->nullable();
            $table->text('incoming_password_encrypted')->nullable();
            $table->string('incoming_username_mask')->nullable();
            $table->string('incoming_password_mask')->nullable();
            $table->string('incoming_mailbox')->nullable();
            $table->timestamp('last_outgoing_tested_at')->nullable();
            $table->string('last_outgoing_test_status')->nullable();
            $table->text('last_outgoing_test_message')->nullable();
            $table->timestamp('last_incoming_tested_at')->nullable();
            $table->string('last_incoming_test_status')->nullable();
            $table->text('last_incoming_test_message')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'profile_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_transport_profiles');
    }
};
