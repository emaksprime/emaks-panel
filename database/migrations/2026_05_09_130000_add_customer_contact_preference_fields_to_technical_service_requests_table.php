<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('technical_service_requests', 'customer_preferred_date')) {
                $table->date('customer_preferred_date')->nullable()->after('customer_confirmation_method');
            }

            if (! Schema::hasColumn('technical_service_requests', 'customer_preferred_time_start')) {
                $table->string('customer_preferred_time_start', 16)->nullable()->after('customer_preferred_date');
            }

            if (! Schema::hasColumn('technical_service_requests', 'customer_preferred_time_end')) {
                $table->string('customer_preferred_time_end', 16)->nullable()->after('customer_preferred_time_start');
            }

            if (! Schema::hasColumn('technical_service_requests', 'customer_callback_at')) {
                $table->timestamp('customer_callback_at')->nullable()->after('customer_preferred_time_end');
            }

            if (! Schema::hasColumn('technical_service_requests', 'customer_rejection_reason')) {
                $table->text('customer_rejection_reason')->nullable()->after('customer_callback_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table) {
            foreach ([
                'customer_preferred_date',
                'customer_preferred_time_start',
                'customer_preferred_time_end',
                'customer_callback_at',
                'customer_rejection_reason',
            ] as $column) {
                if (Schema::hasColumn('technical_service_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
