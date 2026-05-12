<?php

use App\Services\SupportActivationCodeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('panel.support_activation_codes')) {
            return;
        }

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
        $now = now();
        $snapshotCodes = [];

        foreach (array_chunk($records, 250) as $chunk) {
            $rows = array_map(function (array $record) use ($service, $now, &$snapshotCodes): array {
                $payload = $service->buildRecordPayload($record);
                $snapshotCodes[$payload['code']] = true;

                return [
                    ...$payload,
                    'metadata' => json_encode($payload['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $chunk);

            DB::table('panel.support_activation_codes')->upsert(
                $rows,
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

        DB::table('panel.support_activation_codes')
            ->select('id', 'code')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($snapshotCodes): void {
                $staleCodes = $rows
                    ->pluck('code')
                    ->filter(fn (string $code): bool => ! isset($snapshotCodes[$code]))
                    ->values();

                if ($staleCodes->isNotEmpty()) {
                    DB::table('panel.support_activation_codes')
                        ->whereIn('code', $staleCodes->all())
                        ->delete();
                }
            });
    }

    public function down(): void
    {
        // Snapshot refresh only; keep the support activation table intact.
    }
};
