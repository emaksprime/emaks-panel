<?php

use App\Services\SupportActivationCodeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('panel.support_activation_codes')) {
            return;
        }

        Schema::table('panel.support_activation_codes', function (Blueprint $table): void {
            if (! Schema::hasColumn('panel.support_activation_codes', 'stock_code')) {
                $table->text('stock_code')->nullable()->after('code');
                $table->index('stock_code');
            }

            if (! Schema::hasColumn('panel.support_activation_codes', 'stock_name')) {
                $table->text('stock_name')->nullable()->after('stock_code');
            }

            if (! Schema::hasColumn('panel.support_activation_codes', 'serial_number_clean')) {
                $table->text('serial_number_clean')->nullable()->after('serial_number');
                $table->index('serial_number_clean');
            }

            if (! Schema::hasColumn('panel.support_activation_codes', 'search_code')) {
                $table->text('search_code')->nullable()->after('serial_number_clean');
                $table->index('search_code');
            }
        });

        $this->seedActivationCodes(now());
    }

    public function down(): void
    {
        // Additive data backfill; keep imported support records intact on rollback.
    }

    private function seedActivationCodes(mixed $now): void
    {
        $payload = json_decode(
            file_get_contents(database_path('data/support-activation-codes.json')) ?: '{}',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $records = $payload['records'] ?? [];

        if ($records === []) {
            return;
        }

        $service = app(SupportActivationCodeService::class);

        foreach (array_chunk($records, 250) as $chunk) {
            DB::table('panel.support_activation_codes')->upsert(
                array_map(function (array $record) use ($service, $now): array {
                    $payload = $service->buildRecordPayload($record);

                    return [
                        ...$payload,
                        'metadata' => json_encode($payload['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }, $chunk),
                ['code'],
                [
                    'stock_code',
                    'stock_name',
                    'serial_number',
                    'serial_number_clean',
                    'search_code',
                    'activation_code',
                    'activation_link',
                    'metadata',
                    'search_text',
                    'is_active',
                    'updated_at',
                ],
            );
        }
    }
};
