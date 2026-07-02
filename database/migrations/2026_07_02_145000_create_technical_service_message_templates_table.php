<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_service_message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('template_key');
            $table->string('message_type');
            $table->string('channel', 32);
            $table->string('provider_key')->nullable();
            $table->string('title');
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->string('locale', 12)->default('tr');
            $table->unsignedInteger('version')->default(1);
            $table->json('required_variables')->nullable();
            $table->json('optional_variables')->nullable();
            $table->json('validation_rules')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['template_key', 'version'], 'technical_service_message_templates_key_version_unique');
            $table->index('message_type');
            $table->index('channel');
            $table->index('provider_key');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_message_templates');
    }
};
