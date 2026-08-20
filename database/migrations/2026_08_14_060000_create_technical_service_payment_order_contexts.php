<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_service_payment_order_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_service_request_id')
                ->constrained('technical_service_requests', 'id', 'ts_poc_request_fk')
                ->restrictOnDelete();
            $table->foreignId('root_request_id')
                ->constrained('technical_service_requests', 'id', 'ts_poc_root_request_fk')
                ->restrictOnDelete();
            $table->foreignId('srv_request_id')
                ->nullable()
                ->constrained('technical_service_requests', 'id', 'ts_poc_srv_request_fk')
                ->restrictOnDelete();
            $table->foreignId('technical_service_mount_payment_id')
                ->nullable()
                ->unique('ts_poc_payment_unique')
                ->constrained('technical_service_mount_payments', 'id', 'ts_poc_payment_fk')
                ->nullOnDelete();
            $table->foreignId('technical_service_part_request_id')
                ->nullable()
                ->constrained('technical_service_part_requests', 'id', 'ts_poc_part_request_fk')
                ->restrictOnDelete();

            $table->string('payment_purpose', 64);
            $table->string('context_type', 48);
            $table->string('state', 48)->default('draft');
            $table->string('desired_mikro_series', 8)->nullable();
            $table->string('future_mikro_write_state', 32)->default('not_authorized');

            $table->string('billing_source', 48);
            $table->string('billing_customer_code', 64)->nullable();
            $table->string('billing_name_or_title', 200);
            $table->string('billing_phone', 64);
            $table->string('billing_email', 200)->nullable();
            $table->string('billing_tax_identity', 64)->nullable();
            $table->string('billing_tax_office', 160)->nullable();
            $table->text('billing_address');
            $table->string('billing_city', 120);
            $table->string('billing_district', 160);
            $table->string('billing_postal_code', 32)->nullable();

            $table->boolean('shipping_same_as_billing')->default(false);
            $table->string('delivery_target', 48)->nullable();
            $table->string('shipping_recipient_name', 200)->nullable();
            $table->string('shipping_recipient_phone', 64)->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('shipping_city', 120)->nullable();
            $table->string('shipping_district', 160)->nullable();
            $table->string('shipping_postal_code', 32)->nullable();

            $table->string('part_supplier', 32)->nullable();
            $table->string('collection_allocation', 32)->nullable();
            $table->string('item_code', 120)->nullable();
            $table->string('item_name_snapshot', 240)->nullable();
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('unit_code', 32)->nullable();
            $table->string('warehouse_code', 64)->nullable();
            $table->string('stock_source', 48)->nullable();
            $table->timestamp('stock_freshness_at')->nullable();
            $table->boolean('part_serial_tracking_required')->default(false);
            $table->string('selected_part_serial', 160)->nullable();
            $table->string('related_product_serial', 160)->nullable();

            $table->decimal('charged_amount', 14, 2);
            $table->string('currency', 8)->default('TRY');
            $table->boolean('shipment_required')->default(false);
            $table->string('future_carrier_state', 48)->default('not_required');
            $table->text('description2_preview');
            $table->unsignedSmallInteger('description2_version')->default(1);
            $table->string('context_hash', 64);
            $table->string('idempotency_key', 160)->unique('ts_poc_idempotency_unique');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['technical_service_request_id', 'payment_purpose', 'revision'], 'ts_poc_request_purpose_revision_idx');
            $table->index(['root_request_id', 'context_type', 'state'], 'ts_poc_root_type_state_idx');
            $table->index(['state', 'future_mikro_write_state'], 'ts_poc_future_state_idx');
            $table->index('context_hash', 'ts_poc_context_hash_idx');
            $table->index('created_by', 'ts_poc_created_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_payment_order_contexts');
    }
};
