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
            if (! Schema::hasColumn('technical_service_mount_payments', 'provider_last_synced_at')) {
                $table->timestamp('provider_last_synced_at')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_mount_payments', 'provider_sync_attempts')) {
                $table->unsignedInteger('provider_sync_attempts')->default(0);
            }

            if (! Schema::hasColumn('technical_service_mount_payments', 'provider_last_sync_status')) {
                $table->string('provider_last_sync_status', 64)->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_mount_payments', 'provider_last_sync_error')) {
                $table->text('provider_last_sync_error')->nullable();
            }

            if (! Schema::hasColumn('technical_service_mount_payments', 'provider_sync_locked_at')) {
                $table->timestamp('provider_sync_locked_at')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_mount_payments', 'provider_paid_confirmed_at')) {
                $table->timestamp('provider_paid_confirmed_at')->nullable()->index();
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
                'provider_last_synced_at',
                'provider_sync_attempts',
                'provider_last_sync_status',
                'provider_last_sync_error',
                'provider_sync_locked_at',
                'provider_paid_confirmed_at',
            ] as $column) {
                if (Schema::hasColumn('technical_service_mount_payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
