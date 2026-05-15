<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('technical_service_technicians')) {
            return;
        }

        Schema::table('technical_service_technicians', function (Blueprint $table): void {
            if (! Schema::hasColumn('technical_service_technicians', 'phone_e164')) {
                $table->string('phone_e164')->nullable()->index();
            }

            if (! Schema::hasColumn('technical_service_technicians', 'source_key')) {
                $table->string('source_key')->nullable()->index();
            }
        });

        DB::table('technical_service_technicians')
            ->whereNull('phone_e164')
            ->whereNotNull('phone')
            ->orderBy('id')
            ->get(['id', 'phone', 'city', 'technician_type'])
            ->each(function (object $technician): void {
                $phone = trim((string) $technician->phone);
                $city = $this->normalizeKey($technician->city ?? null);
                $type = trim((string) ($technician->technician_type ?? 'technician')) ?: 'technician';

                DB::table('technical_service_technicians')
                    ->where('id', $technician->id)
                    ->update([
                        'phone_e164' => $phone,
                        'source_key' => $phone !== '' && $city !== '' ? "{$type}:{$phone}:{$city}" : null,
                    ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('technical_service_technicians')) {
            return;
        }

        Schema::table('technical_service_technicians', function (Blueprint $table): void {
            foreach (['source_key', 'phone_e164'] as $column) {
                if (Schema::hasColumn('technical_service_technicians', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function normalizeKey(mixed $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', ' ')
            ->squish()
            ->value();
    }
};
