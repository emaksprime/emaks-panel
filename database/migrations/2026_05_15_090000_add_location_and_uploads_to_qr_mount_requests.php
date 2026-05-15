<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('technical_service_requests', 'location_latitude')) {
                $table->decimal('location_latitude', 10, 7)->nullable();
            }

            if (! Schema::hasColumn('technical_service_requests', 'location_longitude')) {
                $table->decimal('location_longitude', 10, 7)->nullable();
            }

            if (! Schema::hasColumn('technical_service_requests', 'location_place_id')) {
                $table->string('location_place_id', 255)->nullable();
            }

            if (! Schema::hasColumn('technical_service_requests', 'location_formatted_address')) {
                $table->text('location_formatted_address')->nullable();
            }

            if (! Schema::hasColumn('technical_service_requests', 'location_map_url')) {
                $table->string('location_map_url', 1024)->nullable();
            }

            if (! Schema::hasColumn('technical_service_requests', 'building_no')) {
                $table->string('building_no', 80)->nullable();
            }

            if (! Schema::hasColumn('technical_service_requests', 'apartment_no')) {
                $table->string('apartment_no', 80)->nullable();
            }

            if (! Schema::hasColumn('technical_service_requests', 'door_no')) {
                $table->string('door_no', 80)->nullable();
            }

            if (! Schema::hasColumn('technical_service_requests', 'floor_no')) {
                $table->string('floor_no', 80)->nullable();
            }

            if (! Schema::hasColumn('technical_service_requests', 'site_name')) {
                $table->string('site_name', 255)->nullable();
            }
        });

        if (! Schema::hasTable('technical_service_request_uploads')) {
            Schema::create('technical_service_request_uploads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('technical_service_request_id')
                    ->constrained('technical_service_requests')
                    ->cascadeOnDelete();
                $table->string('field_code', 120);
                $table->string('category', 120);
                $table->string('original_name', 255);
                $table->string('path', 1024);
                $table->string('mime', 120)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->timestamps();

                $table->index(['technical_service_request_id', 'category'], 'ts_request_uploads_request_category_idx');
                $table->index(['field_code', 'category'], 'ts_request_uploads_field_category_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_request_uploads');

        Schema::table('technical_service_requests', function (Blueprint $table): void {
            foreach ([
                'location_latitude',
                'location_longitude',
                'location_place_id',
                'location_formatted_address',
                'location_map_url',
                'building_no',
                'apartment_no',
                'door_no',
                'floor_no',
                'site_name',
            ] as $column) {
                if (Schema::hasColumn('technical_service_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
