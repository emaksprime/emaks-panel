<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('technical_service_request_serials')) {
            return;
        }

        Schema::table('technical_service_request_serials', function (Blueprint $table): void {
            if (! Schema::hasColumn('technical_service_request_serials', 'operation_added')) {
                $table->boolean('operation_added')->default(false)->index();
            }

            if (! Schema::hasColumn('technical_service_request_serials', 'operation_added_by')) {
                $table->foreignId('operation_added_by')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_request_serials', 'operation_added_at')) {
                $table->timestamp('operation_added_at')->nullable();
            }

            if (! Schema::hasColumn('technical_service_request_serials', 'customer_phone')) {
                $table->string('customer_phone')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_request_serials', 'linked_mrn')) {
                $table->string('linked_mrn')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_request_serials', 'operation_note')) {
                $table->text('operation_note')->nullable();
            }

            if (! Schema::hasColumn('technical_service_request_serials', 'warning_labels')) {
                $table->json('warning_labels')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('technical_service_request_serials')) {
            return;
        }

        Schema::table('technical_service_request_serials', function (Blueprint $table): void {
            foreach ([
                'operation_added',
                'operation_added_by',
                'operation_added_at',
                'customer_phone',
                'linked_mrn',
                'operation_note',
                'warning_labels',
            ] as $column) {
                if (Schema::hasColumn('technical_service_request_serials', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
