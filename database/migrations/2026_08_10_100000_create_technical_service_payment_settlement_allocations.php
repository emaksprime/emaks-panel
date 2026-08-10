<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_earning_payments', function (Blueprint $table): void {
            $table->foreignId('technical_service_assignment_offer_id')
                ->nullable()
                ->after('technical_service_request_id')
                ->constrained('technical_service_assignment_offers', 'id', 'ts_ep_assignment_fk')
                ->nullOnDelete();
            $table->foreignId('source_company_payment_line_id')
                ->nullable()
                ->after('payment_type')
                ->constrained('technical_service_earning_payments', 'id', 'ts_ep_company_line_fk')
                ->nullOnDelete();

            $table->index('technical_service_assignment_offer_id', 'ts_earning_payments_assignment_idx');
            $table->index('source_company_payment_line_id', 'ts_earning_payments_company_line_idx');
        });

        Schema::create('technical_service_payment_settlement_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_service_mount_payment_id')
                ->constrained('technical_service_mount_payments', 'id', 'ts_psa_payment_fk')
                ->restrictOnDelete();
            $table->foreignId('technical_service_settlement_id')
                ->constrained('technical_service_settlements', 'id', 'ts_psa_settlement_fk')
                ->restrictOnDelete();
            $table->foreignId('technical_service_request_id')
                ->constrained('technical_service_requests', 'id', 'ts_psa_request_fk')
                ->restrictOnDelete();
            $table->foreignId('root_request_id')
                ->constrained('technical_service_requests', 'id', 'ts_psa_root_request_fk')
                ->restrictOnDelete();
            $table->foreignId('current_srv_id')
                ->nullable()
                ->constrained('technical_service_requests', 'id', 'ts_psa_current_srv_fk')
                ->restrictOnDelete();
            $table->foreignId('technical_service_assignment_offer_id')
                ->constrained('technical_service_assignment_offers', 'id', 'ts_psa_assignment_fk')
                ->restrictOnDelete();
            $table->foreignId('technical_service_technician_id')
                ->constrained('technical_service_technicians', 'id', 'ts_psa_technician_fk')
                ->restrictOnDelete();
            $table->string('payment_purpose', 64);
            $table->string('currency', 8)->default('TRY');
            $table->decimal('source_paid_amount', 14, 2);
            $table->decimal('covered_amount', 14, 2)->default(0);
            $table->decimal('eligible_amount', 14, 2);
            $table->string('decision', 32);
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decided_by_name', 160)->nullable();
            $table->timestamp('decided_at');
            $table->foreignId('settlement_line_id')
                ->nullable()
                ->constrained('technical_service_earning_payments', 'id', 'ts_psa_settlement_line_fk')
                ->nullOnDelete();
            $table->foreignId('reversal_of_id')
                ->nullable()
                ->constrained('technical_service_payment_settlement_allocations', 'id', 'ts_psa_reversal_fk')
                ->restrictOnDelete();
            $table->string('status', 24)->default('active');
            $table->string('idempotency_key', 160)->unique('ts_payment_allocation_idempotency_unique');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['technical_service_mount_payment_id', 'status'], 'ts_payment_allocation_payment_status_idx');
            $table->index(['technical_service_request_id', 'status'], 'ts_payment_allocation_request_status_idx');
            $table->index(['technical_service_settlement_id', 'status'], 'ts_payment_allocation_settlement_status_idx');
            $table->index(['technical_service_technician_id', 'status'], 'ts_payment_allocation_technician_status_idx');
            $table->index(['decision', 'status'], 'ts_payment_allocation_decision_status_idx');
            $table->index('decided_by', 'ts_payment_allocation_actor_idx');
            $table->index('decided_at', 'ts_payment_allocation_decided_at_idx');
            $table->index('reversal_of_id', 'ts_payment_allocation_reversal_idx');
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('technical_service_payment_settlement_allocations', function (Blueprint $table): void {
                $table->foreign('decided_by', 'ts_payment_allocation_actor_fk')
                    ->references('id')
                    ->on('panel.users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_payment_settlement_allocations');

        Schema::table('technical_service_earning_payments', function (Blueprint $table): void {
            $table->dropForeign('ts_ep_company_line_fk');
            $table->dropForeign('ts_ep_assignment_fk');
            $table->dropIndex('ts_earning_payments_company_line_idx');
            $table->dropIndex('ts_earning_payments_assignment_idx');
            $table->dropColumn(['source_company_payment_line_id', 'technical_service_assignment_offer_id']);
        });
    }
};
