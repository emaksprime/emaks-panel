<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'panel.warehouse_serial_locations',
            'panel.warehouse_stock_locations',
            'panel.warehouse_rack_operation_items',
        ] as $tableName) {
            if (Schema::hasColumn($tableName, 'category_name')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('category_name')->nullable()->after('stock_name');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'panel.warehouse_rack_operation_items',
            'panel.warehouse_stock_locations',
            'panel.warehouse_serial_locations',
        ] as $tableName) {
            if (! Schema::hasColumn($tableName, 'category_name')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('category_name');
            });
        }
    }
};
