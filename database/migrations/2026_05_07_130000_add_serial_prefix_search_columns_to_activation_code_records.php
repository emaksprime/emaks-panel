<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activation_code_records', function (Blueprint $table) {
            $table->string('serial_prefix_clean')->nullable()->after('serial_prefix');
            $table->string('serial_prefix_tail_6', 6)->nullable()->after('serial_prefix_clean');
            $table->string('serial_prefix_tail_10', 10)->nullable()->after('serial_prefix_tail_6');

            $table->index('serial_prefix_clean');
            $table->index('serial_prefix_tail_6');
            $table->index('serial_prefix_tail_10');
        });

        DB::table('activation_code_records')
            ->orderBy('id')
            ->chunkById(250, function ($records): void {
                foreach ($records as $record) {
                    $serialNo = trim((string) ($record->serial_no ?? ''));
                    $serialPrefix = $this->serialPrefix($serialNo);
                    $serialPrefixClean = $this->normalizeSearchValue($serialPrefix);

                    DB::table('activation_code_records')
                        ->where('id', $record->id)
                        ->update([
                            'serial_prefix' => $serialPrefix !== '' ? $serialPrefix : null,
                            'serial_prefix_clean' => $serialPrefixClean !== '' ? $serialPrefixClean : null,
                            'serial_prefix_tail_6' => $this->tail($serialPrefixClean, 6),
                            'serial_prefix_tail_10' => $this->tail($serialPrefixClean, 10),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('activation_code_records', function (Blueprint $table) {
            $table->dropIndex(['serial_prefix_clean']);
            $table->dropIndex(['serial_prefix_tail_6']);
            $table->dropIndex(['serial_prefix_tail_10']);
            $table->dropColumn([
                'serial_prefix_clean',
                'serial_prefix_tail_6',
                'serial_prefix_tail_10',
            ]);
        });
    }

    private function serialPrefix(string $serialNo): string
    {
        $serialNo = trim($serialNo);

        if ($serialNo === '') {
            return '';
        }

        if (! str_contains($serialNo, '-')) {
            return $serialNo;
        }

        return trim((string) str($serialNo)->beforeLast('-'));
    }

    private function normalizeSearchValue(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]+/u', '', mb_strtoupper(trim((string) $value), 'UTF-8')) ?? '';
    }

    private function tail(string $value, int $length): ?string
    {
        if ($value === '') {
            return null;
        }

        return strlen($value) <= $length ? $value : substr($value, -$length);
    }
};
