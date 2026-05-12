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
        $now = now();

        if (! Schema::hasTable('panel.support_activation_codes')) {
            Schema::create('panel.support_activation_codes', function (Blueprint $table): void {
                $table->id();
                $table->text('code')->unique();
                $table->text('stock_code')->nullable();
                $table->text('stock_name')->nullable();
                $table->text('serial_number')->nullable();
                $table->text('serial_number_clean')->nullable();
                $table->text('search_code')->nullable();
                $table->text('activation_code')->nullable();
                $table->text('activation_link')->nullable();
                $table->jsonb('metadata')->default('{}');
                $table->text('search_text')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestampTz('created_at');
                $table->timestampTz('updated_at');

                $table->index('stock_code');
                $table->index('serial_number');
                $table->index('serial_number_clean');
                $table->index('search_code');
                $table->index('activation_code');
                $table->index('is_active');
            });
        }

        $this->seedActivationCodes($now);
    }

    public function down(): void
    {
        Schema::dropIfExists('panel.support_activation_codes');
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

        foreach (array_chunk($records, 100) as $chunk) {
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
                    'serial_number',
                    'stock_code',
                    'stock_name',
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
