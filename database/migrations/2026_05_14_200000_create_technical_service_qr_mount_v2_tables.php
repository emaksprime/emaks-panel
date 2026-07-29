<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('technical_service_qr_links')) {
            Schema::create('technical_service_qr_links', function (Blueprint $table): void {
                $table->id();
                $table->string('token_hash', 128)->unique();
                $table->string('serial_number');
                $table->string('product_name');
                $table->string('product_model')->nullable();
                $table->string('brand')->nullable();
                $table->string('link_type', 64)->index();
                $table->string('status', 32)->default('active')->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();

                $table->index('serial_number');
            });
        }

        if (! Schema::hasTable('technical_service_mount_sessions')) {
            Schema::create('technical_service_mount_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('technical_service_qr_link_id')
                    ->constrained('technical_service_qr_links')
                    ->cascadeOnDelete();
                $table->string('session_token_hash', 128)->unique();
                $table->string('serial_number')->index();
                $table->string('sale_mount_status', 64)->default('unknown')->index();
                $table->string('mount_payment_status', 64)->nullable()->index();
                $table->string('customer_entry_mode', 64)->nullable()->index();
                $table->string('decision_status', 64)->default('pending_check')->index();
                $table->unsignedInteger('check_attempt_count')->default(0);
                $table->timestamp('last_checked_at')->nullable();
                $table->text('check_error')->nullable();
                $table->json('context_payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('technical_service_mount_payments')) {
            Schema::create('technical_service_mount_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('technical_service_mount_session_id')
                    ->constrained('technical_service_mount_sessions')
                    ->cascadeOnDelete();
                $table->string('provider', 64);
                $table->string('provider_reference')->nullable()->index();
                $table->string('status', 32)->index();
                $table->decimal('amount', 12, 2);
                $table->string('currency', 3)->default('TRY');
                $table->text('payment_url')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->json('raw_payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('technical_service_request_serials')) {
            Schema::create('technical_service_request_serials', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('technical_service_request_id')
                    ->nullable()
                    ->constrained('technical_service_requests')
                    ->nullOnDelete();
                $table->string('mrn')->nullable()->index();
                $table->string('serial_number')->index();
                $table->string('product_name')->nullable();
                $table->string('product_model')->nullable();
                $table->string('brand')->nullable();
                $table->string('stock_code')->nullable();
                $table->string('invoice_series')->nullable();
                $table->string('invoice_number')->nullable();
                $table->boolean('customer_selected')->default(false)->index();
                $table->boolean('customer_visible')->default(false)->index();
                $table->text('hidden_reason')->nullable();
                $table->boolean('is_primary')->default(false)->index();
                $table->boolean('is_returned')->default(false)->index();
                $table->text('return_note')->nullable();
                $table->date('return_date')->nullable();
                $table->string('return_document_no')->nullable();
                $table->string('invoice_customer_type', 64)->default('unknown')->index();
                $table->json('source_payload')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_request_serials');
        Schema::dropIfExists('technical_service_mount_payments');
        Schema::dropIfExists('technical_service_mount_sessions');
        Schema::dropIfExists('technical_service_qr_links');
    }
};
