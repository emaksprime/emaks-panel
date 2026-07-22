<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('technical_service_settlements')) {
            Schema::create('technical_service_settlements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('technical_service_request_id')
                    ->constrained('technical_service_requests')
                    ->restrictOnDelete();
                $table->foreignId('root_request_id')
                    ->nullable()
                    ->constrained('technical_service_requests')
                    ->nullOnDelete();
                $table->string('request_code', 128)->nullable();
                $table->string('root_mrn', 128)->nullable();
                $table->foreignId('technical_service_technician_id')
                    ->nullable()
                    ->constrained('technical_service_technicians')
                    ->nullOnDelete();
                $table->foreignId('b2b_partner_id')
                    ->nullable()
                    ->constrained('b2b_partners')
                    ->nullOnDelete();
                $table->foreignId('technical_service_assignment_offer_id')
                    ->nullable()
                    ->constrained('technical_service_assignment_offers')
                    ->nullOnDelete();
                $table->foreignId('technical_service_earning_item_id')
                    ->nullable()
                    ->constrained('technical_service_earning_items')
                    ->nullOnDelete();
                $table->string('currency', 8)->default('TRY');
                $table->decimal('labor_earning_amount', 12, 2)->default(0);
                $table->decimal('route_earning_amount', 12, 2)->default(0);
                $table->decimal('technician_earning_total', 12, 2)->default(0);
                $table->decimal('customer_collection_amount', 12, 2)->default(0);
                $table->decimal('customer_direct_to_technician_amount', 12, 2)->default(0);
                $table->decimal('customer_direct_assumed_paid_amount', 12, 2)->default(0);
                $table->decimal('company_payable_amount', 12, 2)->default(0);
                $table->decimal('company_paid_amount', 12, 2)->default(0);
                $table->decimal('company_remaining_amount', 12, 2)->default(0);
                $table->decimal('overpay_warning_amount', 12, 2)->default(0);
                $table->string('status', 32)->default('draft');
                $table->string('settlement_source', 64)->nullable();
                $table->boolean('overpay_requires_review')->default(false);
                $table->text('review_reason')->nullable();
                $table->unsignedBigInteger('direct_payment_message_dispatch_id')->nullable();
                $table->timestamp('direct_payment_message_sent_at')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('excluded_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->json('metadata')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique('technical_service_request_id', 'ts_settlements_request_unique');
                $table->index('root_request_id', 'ts_settlements_root_request_idx');
                $table->index('technical_service_technician_id', 'ts_settlements_technician_idx');
                $table->index('b2b_partner_id', 'ts_settlements_partner_idx');
                $table->index('status', 'ts_settlements_status_idx');
                $table->index('overpay_requires_review', 'ts_settlements_overpay_review_idx');
                $table->index('created_at', 'ts_settlements_created_at_idx');
                $table->index('created_by', 'ts_settlements_created_by_idx');
                $table->index('updated_by', 'ts_settlements_updated_by_idx');
                $table->foreign('direct_payment_message_dispatch_id', 'ts_settlements_msg_dispatch_fk')
                    ->references('id')
                    ->on('technical_service_message_dispatches')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('technical_service_earning_payments')) {
            Schema::create('technical_service_earning_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('technical_service_settlement_id')
                    ->constrained('technical_service_settlements')
                    ->cascadeOnDelete();
                $table->foreignId('technical_service_request_id')
                    ->nullable()
                    ->constrained('technical_service_requests')
                    ->nullOnDelete();
                $table->foreignId('technical_service_technician_id')
                    ->nullable()
                    ->constrained('technical_service_technicians')
                    ->nullOnDelete();
                $table->foreignId('b2b_partner_id')
                    ->nullable()
                    ->constrained('b2b_partners')
                    ->nullOnDelete();
                $table->string('currency', 8)->default('TRY');
                $table->string('payment_type', 32)->default('company_payout');
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('status', 32)->default('applied');
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->string('paid_by_name', 160)->nullable();
                $table->text('reason')->nullable();
                $table->string('reference', 160)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('technical_service_settlement_id', 'ts_earning_payments_settlement_idx');
                $table->index('technical_service_request_id', 'ts_earning_payments_request_idx');
                $table->index('technical_service_technician_id', 'ts_earning_payments_technician_idx');
                $table->index('b2b_partner_id', 'ts_earning_payments_partner_idx');
                $table->index('payment_type', 'ts_earning_payments_type_idx');
                $table->index('status', 'ts_earning_payments_status_idx');
                $table->index('paid_at', 'ts_earning_payments_paid_at_idx');
                $table->index('created_at', 'ts_earning_payments_created_at_idx');
                $table->index('paid_by', 'ts_earning_payments_paid_by_idx');
            });
        }

        $this->addPanelUserForeignKeys();
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_earning_payments');
        Schema::dropIfExists('technical_service_settlements');
    }

    private function addPanelUserForeignKeys(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('technical_service_settlements', function (Blueprint $table): void {
            $table->foreign('created_by', 'ts_settlements_created_by_fk')->references('id')->on('panel.users')->nullOnDelete();
            $table->foreign('updated_by', 'ts_settlements_updated_by_fk')->references('id')->on('panel.users')->nullOnDelete();
        });

        Schema::table('technical_service_earning_payments', function (Blueprint $table): void {
            $table->foreign('paid_by', 'ts_earning_payments_paid_by_fk')->references('id')->on('panel.users')->nullOnDelete();
        });
    }
};
