<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('technical_service_mount_payments')) {
            return;
        }

        Schema::table('technical_service_mount_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('technical_service_mount_payments', 'provider_payment_reference')) {
                $table->string('provider_payment_reference')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_mount_payments', 'provider_transaction_reference')) {
                $table->string('provider_transaction_reference')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_mount_payments', 'provider_receipt_reference')) {
                $table->string('provider_receipt_reference')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_mount_payments', 'provider_paid_at')) {
                $table->timestamp('provider_paid_at')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_mount_payments', 'receipt_notification_sent_at')) {
                $table->timestamp('receipt_notification_sent_at')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_mount_payments', 'receipt_notification_to')) {
                $table->text('receipt_notification_to')->nullable();
            }

            if (! Schema::hasColumn('technical_service_mount_payments', 'receipt_notification_status')) {
                $table->string('receipt_notification_status', 32)->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_mount_payments', 'receipt_notification_error')) {
                $table->text('receipt_notification_error')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('technical_service_mount_payments')) {
            return;
        }

        Schema::table('technical_service_mount_payments', function (Blueprint $table): void {
            foreach ([
                'provider_payment_reference',
                'provider_transaction_reference',
                'provider_receipt_reference',
                'provider_paid_at',
                'receipt_notification_sent_at',
                'receipt_notification_to',
                'receipt_notification_status',
                'receipt_notification_error',
            ] as $column) {
                if (Schema::hasColumn('technical_service_mount_payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
