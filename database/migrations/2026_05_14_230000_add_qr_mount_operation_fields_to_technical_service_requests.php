<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('technical_service_requests')) {
            return;
        }

        Schema::table('technical_service_requests', function (Blueprint $table): void {
            $this->unsignedBigIntegerColumn($table, 'qr_link_id');
            $this->unsignedBigIntegerColumn($table, 'mount_session_id');
            $this->stringColumn($table, 'brand');
            $this->stringColumn($table, 'stock_code');
            $this->stringColumn($table, 'activation_code');
            $this->stringColumn($table, 'current_serial_state', 64);
            $this->booleanColumn($table, 'has_current_sale');
            $this->stringColumn($table, 'sale_mount_status', 64);
            $this->stringColumn($table, 'mount_payment_status', 64);
            $this->stringColumn($table, 'mount_payment_label');
            $this->stringColumn($table, 'mount_payment_provider', 64);
            $this->stringColumn($table, 'mount_payment_reference');
            $this->timestampColumn($table, 'mount_payment_paid_at');
            $this->stringColumn($table, 'invoice_series');
            $this->stringColumn($table, 'invoice_number');
            $this->stringColumn($table, 'invoice_display_no');
            $this->stringColumn($table, 'dispatch_series');
            $this->stringColumn($table, 'dispatch_number');
            $this->stringColumn($table, 'dispatch_display_no');
            $this->stringColumn($table, 'order_series');
            $this->stringColumn($table, 'order_number');
            $this->stringColumn($table, 'order_display_no');
            $this->stringColumn($table, 'invoice_customer_type', 64);
            $this->jsonColumn($table, 'qr_context_payload');
            $this->jsonColumn($table, 'operation_control_payload');
            $this->unsignedBigIntegerColumn($table, 'operation_control_checked_by_user_id');
            $this->timestampColumn($table, 'operation_control_checked_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('technical_service_requests')) {
            return;
        }

        Schema::table('technical_service_requests', function (Blueprint $table): void {
            foreach ([
                'operation_control_checked_at',
                'operation_control_checked_by_user_id',
                'operation_control_payload',
                'qr_context_payload',
                'invoice_customer_type',
                'order_display_no',
                'order_number',
                'order_series',
                'dispatch_display_no',
                'dispatch_number',
                'dispatch_series',
                'invoice_display_no',
                'invoice_number',
                'invoice_series',
                'mount_payment_paid_at',
                'mount_payment_reference',
                'mount_payment_provider',
                'mount_payment_label',
                'mount_payment_status',
                'sale_mount_status',
                'has_current_sale',
                'current_serial_state',
                'activation_code',
                'stock_code',
                'brand',
                'mount_session_id',
                'qr_link_id',
            ] as $column) {
                if (Schema::hasColumn('technical_service_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function stringColumn(Blueprint $table, string $name, int $length = 255): void
    {
        if (! Schema::hasColumn('technical_service_requests', $name)) {
            $table->string($name, $length)->nullable();
        }
    }

    private function unsignedBigIntegerColumn(Blueprint $table, string $name): void
    {
        if (! Schema::hasColumn('technical_service_requests', $name)) {
            $table->unsignedBigInteger($name)->nullable()->index();
        }
    }

    private function booleanColumn(Blueprint $table, string $name): void
    {
        if (! Schema::hasColumn('technical_service_requests', $name)) {
            $table->boolean($name)->nullable()->index();
        }
    }

    private function timestampColumn(Blueprint $table, string $name): void
    {
        if (! Schema::hasColumn('technical_service_requests', $name)) {
            $table->timestamp($name)->nullable();
        }
    }

    private function jsonColumn(Blueprint $table, string $name): void
    {
        if (! Schema::hasColumn('technical_service_requests', $name)) {
            $table->json($name)->nullable();
        }
    }
};
