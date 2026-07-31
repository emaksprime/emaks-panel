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
            if (! Schema::hasColumn('technical_service_request_serials', 'customer_selectable')) {
                $table->boolean('customer_selectable')->default(false)->index();
            }

            if (! Schema::hasColumn('technical_service_request_serials', 'is_current_latest_sale')) {
                $table->boolean('is_current_latest_sale')->default(false)->index();
            }

            if (! Schema::hasColumn('technical_service_request_serials', 'color_status')) {
                $table->string('color_status', 32)->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_request_serials', 'return_document_no')) {
                $table->string('return_document_no')->nullable();
            }

            if (! Schema::hasColumn('technical_service_request_serials', 'invoice_customer_type')) {
                $table->string('invoice_customer_type', 64)->default('unknown')->index();
            }

            if (! Schema::hasColumn('technical_service_request_serials', 'source_payload')) {
                $table->json('source_payload')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('technical_service_request_serials')) {
            return;
        }

        Schema::table('technical_service_request_serials', function (Blueprint $table): void {
            foreach (['customer_selectable', 'is_current_latest_sale', 'color_status'] as $column) {
                if (Schema::hasColumn('technical_service_request_serials', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
