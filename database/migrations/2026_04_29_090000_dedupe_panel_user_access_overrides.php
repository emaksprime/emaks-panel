<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('panel.user_access')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
WITH grouped AS (
    SELECT
        user_id,
        resource_code,
        MIN(id) AS keep_id,
        BOOL_AND(can_view) AS merged_can_view,
        MIN(created_at) AS first_created_at,
        MAX(updated_at) AS last_updated_at
    FROM panel.user_access
    GROUP BY user_id, resource_code
)
UPDATE panel.user_access AS access
SET
    can_view = grouped.merged_can_view,
    created_at = COALESCE(grouped.first_created_at, access.created_at),
    updated_at = COALESCE(grouped.last_updated_at, access.updated_at)
FROM grouped
WHERE access.id = grouped.keep_id
SQL);

        DB::statement(<<<'SQL'
WITH grouped AS (
    SELECT user_id, resource_code, MIN(id) AS keep_id
    FROM panel.user_access
    GROUP BY user_id, resource_code
)
DELETE FROM panel.user_access AS access
USING grouped
WHERE access.user_id = grouped.user_id
  AND access.resource_code = grouped.resource_code
  AND access.id <> grouped.keep_id
SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS panel_user_access_user_resource_unique ON panel.user_access (user_id, resource_code)');
    }

    public function down(): void
    {
        // Production-safe migration: keep deduplicated access overrides intact.
    }
};
