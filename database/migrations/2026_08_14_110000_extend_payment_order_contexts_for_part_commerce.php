<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_payment_order_contexts', function (Blueprint $table): void {
            $table->string('billing_type', 24)->nullable()->after('billing_source');
            $table->string('billing_first_name', 100)->nullable()->after('billing_customer_code');
            $table->string('billing_last_name', 100)->nullable()->after('billing_first_name');
            $table->string('billing_legal_title', 200)->nullable()->after('billing_last_name');

            $table->string('commercial_mode', 24)->nullable()->after('collection_allocation');
            $table->string('delivery_mode', 24)->nullable()->after('commercial_mode');
            $table->string('delivery_status', 24)->default('pending')->after('delivery_mode');
            $table->string('payment_collection_mode', 24)->nullable()->after('delivery_status');
            $table->string('payment_status', 24)->nullable()->after('payment_collection_mode');
            $table->string('payment_status_source', 48)->nullable()->after('payment_status');
            $table->string('tax_mode', 64)->nullable()->after('desired_mikro_series');
            $table->decimal('vat_rate', 8, 4)->nullable()->after('tax_mode');
            $table->decimal('order_line_unit_price', 14, 2)->default(0)->after('charged_amount');
            $table->decimal('order_line_total', 14, 2)->default(0)->after('order_line_unit_price');
            $table->decimal('collection_amount', 14, 2)->default(0)->after('order_line_total');
            $table->boolean('payment_link_required')->default(false)->after('collection_amount');
            $table->boolean('collection_required')->default(false)->after('payment_link_required');
            $table->string('future_order_trigger', 48)->nullable()->after('future_mikro_write_state');
            $table->boolean('finance_review_required')->default(false)->after('future_order_trigger');
            $table->unsignedBigInteger('payment_status_changed_by')->nullable()->after('finance_review_required');
            $table->timestamp('payment_status_changed_at')->nullable()->after('payment_status_changed_by');
            $table->text('payment_status_reason')->nullable()->after('payment_status_changed_at');
            $table->uuid('correlation_id')->nullable()->after('idempotency_key');

            $table->index(['technical_service_request_id', 'payment_purpose', 'payment_status'], 'ts_poc_request_purpose_payment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('technical_service_payment_order_contexts', function (Blueprint $table): void {
            $table->dropIndex('ts_poc_request_purpose_payment_idx');
            $table->dropColumn([
                'billing_type',
                'billing_first_name',
                'billing_last_name',
                'billing_legal_title',
                'commercial_mode',
                'delivery_mode',
                'delivery_status',
                'payment_collection_mode',
                'payment_status',
                'payment_status_source',
                'tax_mode',
                'vat_rate',
                'order_line_unit_price',
                'order_line_total',
                'collection_amount',
                'payment_link_required',
                'collection_required',
                'future_order_trigger',
                'finance_review_required',
                'payment_status_changed_by',
                'payment_status_changed_at',
                'payment_status_reason',
                'correlation_id',
            ]);
        });
    }
};
