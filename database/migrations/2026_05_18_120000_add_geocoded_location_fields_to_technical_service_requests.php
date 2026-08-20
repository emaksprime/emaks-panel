<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('technical_service_requests', 'location_source')) {
                $table->string('location_source', 64)->nullable()->after('location_map_url');
            }

            if (! Schema::hasColumn('technical_service_requests', 'location_accuracy')) {
                $table->string('location_accuracy', 64)->nullable()->after('location_source');
            }

            if (! Schema::hasColumn('technical_service_requests', 'location_note')) {
                $table->text('location_note')->nullable()->after('location_accuracy');
            }
        });
    }

    public function down(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table): void {
            foreach (['location_note', 'location_accuracy', 'location_source'] as $column) {
                if (Schema::hasColumn('technical_service_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
