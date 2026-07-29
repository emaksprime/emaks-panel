<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('technical_service_mount_payments')
            || Schema::hasColumn('technical_service_mount_payments', 'technical_service_request_id')) {
            return;
        }

        Schema::table('technical_service_mount_payments', function (Blueprint $table): void {
            $table->foreignId('technical_service_request_id')
                ->nullable()
                ->after('technical_service_mount_session_id')
                ->constrained('technical_service_requests')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('technical_service_mount_payments')
            || ! Schema::hasColumn('technical_service_mount_payments', 'technical_service_request_id')) {
            return;
        }

        Schema::table('technical_service_mount_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('technical_service_request_id');
        });
    }
};
