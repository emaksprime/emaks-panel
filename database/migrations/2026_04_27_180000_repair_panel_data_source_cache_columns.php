<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS panel');
        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS panel.data_source_cache (
    id bigserial PRIMARY KEY
)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE panel.data_source_cache
    ADD COLUMN IF NOT EXISTS cache_key varchar(128),
    ADD COLUMN IF NOT EXISTS source_code varchar(128),
    ADD COLUMN IF NOT EXISTS request_payload json,
    ADD COLUMN IF NOT EXISTS response_payload json,
    ADD COLUMN IF NOT EXISTS expires_at timestamp without time zone,
    ADD COLUMN IF NOT EXISTS created_at timestamp without time zone,
    ADD COLUMN IF NOT EXISTS updated_at timestamp without time zone
SQL);

        DB::statement(<<<'SQL'
DELETE FROM panel.data_source_cache
WHERE cache_key IS NULL
SQL);

        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS data_source_cache_cache_key_unique
    ON panel.data_source_cache (cache_key)
SQL);

        DB::statement(<<<'SQL'
CREATE INDEX IF NOT EXISTS data_source_cache_source_code_index
    ON panel.data_source_cache (source_code)
SQL);

        DB::statement(<<<'SQL'
CREATE INDEX IF NOT EXISTS data_source_cache_expires_at_index
    ON panel.data_source_cache (expires_at)
SQL);
    }

    public function down(): void
    {
        // Production repair migration: do not drop cache columns or data automatically.
    }
};
