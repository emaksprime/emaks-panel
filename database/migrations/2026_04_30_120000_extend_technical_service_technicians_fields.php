<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_technicians', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('google_plus_code')->nullable()->after('address');
            $table->text('google_formatted_address')->nullable()->after('google_plus_code');
            $table->string('default_start_plus_code')->nullable()->after('default_start_address');
            $table->decimal('start_latitude', 10, 7)->nullable()->after('longitude');
            $table->decimal('start_longitude', 10, 7)->nullable()->after('start_latitude');
            $table->string('mikro_cari_kodu')->nullable()->after('start_longitude');
            $table->string('mikro_cari_adi')->nullable()->after('mikro_cari_kodu');
        });

        DB::table('technical_service_technicians')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->each(function ($technician): void {
                $name = trim((string) $technician->name);

                if ($name === '') {
                    return;
                }

                $parts = preg_split('/\s+/', $name) ?: [];
                $firstName = array_shift($parts) ?: $name;
                $lastName = trim(implode(' ', $parts)) ?: null;

                DB::table('technical_service_technicians')
                    ->where('id', $technician->id)
                    ->update([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('technical_service_technicians', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'google_plus_code',
                'google_formatted_address',
                'default_start_plus_code',
                'start_latitude',
                'start_longitude',
                'mikro_cari_kodu',
                'mikro_cari_adi',
            ]);
        });
    }
};
