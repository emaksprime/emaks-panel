<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('technical_service_technicians')) {
            return;
        }

        Schema::table('technical_service_technicians', function (Blueprint $table): void {
            if (! Schema::hasColumn('technical_service_technicians', 'location_source')) {
                $table->string('location_source', 64)->nullable()->after('start_longitude');
            }

            if (! Schema::hasColumn('technical_service_technicians', 'route_note')) {
                $table->text('route_note')->nullable()->after('location_source');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('technical_service_technicians')) {
            return;
        }

        Schema::table('technical_service_technicians', function (Blueprint $table): void {
            foreach (['route_note', 'location_source'] as $column) {
                if (Schema::hasColumn('technical_service_technicians', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
