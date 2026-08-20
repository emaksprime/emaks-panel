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
            if (! Schema::hasColumn('technical_service_technicians', 'technician_type')) {
                $table->string('technician_type', 64)->default('technician')->index();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'city_plate_code')) {
                $table->string('city_plate_code', 16)->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'priority')) {
                $table->unsignedInteger('priority')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'phone_display')) {
                $table->string('phone_display')->nullable();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'location_code')) {
                $table->string('location_code')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'cari_code')) {
                $table->string('cari_code')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'cari_title')) {
                $table->string('cari_title')->nullable();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'cari_address')) {
                $table->text('cari_address')->nullable();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'cari_city_district_country')) {
                $table->string('cari_city_district_country')->nullable();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'display_name')) {
                $table->string('display_name')->nullable();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'import_status')) {
                $table->string('import_status')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'import_note')) {
                $table->text('import_note')->nullable();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'needs_review')) {
                $table->boolean('needs_review')->default(false)->index();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'import_source')) {
                $table->string('import_source')->nullable();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'imported_at')) {
                $table->timestamp('imported_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('technical_service_technicians')) {
            return;
        }

        Schema::table('technical_service_technicians', function (Blueprint $table): void {
            foreach ([
                'imported_at',
                'import_source',
                'needs_review',
                'import_note',
                'import_status',
                'display_name',
                'cari_city_district_country',
                'cari_address',
                'cari_title',
                'cari_code',
                'location_code',
                'phone_display',
                'priority',
                'city_plate_code',
                'technician_type',
            ] as $column) {
                if (Schema::hasColumn('technical_service_technicians', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
