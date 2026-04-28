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
    ADD COLUMN IF NOT EXISTS id bigserial,
    ADD COLUMN IF NOT EXISTS cache_key varchar(128),
    ADD COLUMN IF NOT EXISTS source_code varchar(128),
    ADD COLUMN IF NOT EXISTS request_payload json,
    ADD COLUMN IF NOT EXISTS response_payload json,
    ADD COLUMN IF NOT EXISTS expires_at timestamp without time zone,
    ADD COLUMN IF NOT EXISTS created_at timestamp without time zone,
    ADD COLUMN IF NOT EXISTS updated_at timestamp without time zone
SQL);

        DB::statement(<<<'SQL'
DO $$
DECLARE
    cache_sequence regclass;
    max_cache_id bigint;
    has_cache_rows boolean;
BEGIN
    cache_sequence := pg_get_serial_sequence('panel.data_source_cache', 'id')::regclass;

    IF cache_sequence IS NULL THEN
        CREATE SEQUENCE IF NOT EXISTS panel.data_source_cache_id_seq;
        ALTER SEQUENCE panel.data_source_cache_id_seq OWNED BY panel.data_source_cache.id;
        ALTER TABLE panel.data_source_cache
            ALTER COLUMN id SET DEFAULT nextval('panel.data_source_cache_id_seq'::regclass);
        cache_sequence := 'panel.data_source_cache_id_seq'::regclass;
    END IF;

    SELECT COALESCE(MAX(id), 1), MAX(id) IS NOT NULL
    INTO max_cache_id, has_cache_rows
    FROM panel.data_source_cache;

    PERFORM setval(cache_sequence, max_cache_id, has_cache_rows);

    UPDATE panel.data_source_cache
    SET id = nextval(cache_sequence)
    WHERE id IS NULL;

    WITH duplicate_ids AS (
        SELECT ctid, row_number() OVER (PARTITION BY id ORDER BY ctid) AS row_number
        FROM panel.data_source_cache
        WHERE id IS NOT NULL
    )
    UPDATE panel.data_source_cache cache
    SET id = nextval(cache_sequence)
    FROM duplicate_ids
    WHERE cache.ctid = duplicate_ids.ctid
      AND duplicate_ids.row_number > 1;

    SELECT COALESCE(MAX(id), 1), MAX(id) IS NOT NULL
    INTO max_cache_id, has_cache_rows
    FROM panel.data_source_cache;

    PERFORM setval(cache_sequence, max_cache_id, has_cache_rows);
END $$;
SQL);

        DB::statement(<<<'SQL'
DELETE FROM panel.data_source_cache
WHERE cache_key IS NULL
SQL);

        DB::statement(<<<'SQL'
DELETE FROM panel.data_source_cache stale
USING (
    SELECT id, row_number() OVER (PARTITION BY cache_key ORDER BY id) AS row_number
    FROM panel.data_source_cache
    WHERE cache_key IS NOT NULL
) duplicates
WHERE stale.id = duplicates.id
  AND duplicates.row_number > 1
SQL);

        DB::statement(<<<'SQL'
DO $$
DECLARE
    primary_constraint text;
    primary_is_id boolean := false;
BEGIN
    SELECT conname
    INTO primary_constraint
    FROM pg_constraint
    WHERE conrelid = 'panel.data_source_cache'::regclass
      AND contype = 'p'
    LIMIT 1;

    IF primary_constraint IS NOT NULL THEN
        SELECT EXISTS (
            SELECT 1
            FROM pg_constraint c
            JOIN pg_attribute a
              ON a.attrelid = c.conrelid
             AND a.attnum = ANY(c.conkey)
            WHERE c.conrelid = 'panel.data_source_cache'::regclass
              AND c.conname = primary_constraint
              AND c.contype = 'p'
              AND array_length(c.conkey, 1) = 1
              AND a.attname = 'id'
        )
        INTO primary_is_id;

        IF NOT primary_is_id THEN
            EXECUTE format('ALTER TABLE panel.data_source_cache DROP CONSTRAINT %I', primary_constraint);
        END IF;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'panel.data_source_cache'::regclass
          AND contype = 'p'
    ) THEN
        ALTER TABLE panel.data_source_cache ADD PRIMARY KEY (id);
    END IF;
END $$;
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
