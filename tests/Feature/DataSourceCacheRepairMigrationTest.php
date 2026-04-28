<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DataSourceCacheRepairMigrationTest extends TestCase
{
    public function test_repair_migration_handles_plain_bigint_id_without_sequence(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific repair migration scenario.');
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS panel');
        Schema::dropIfExists('panel.data_source_cache');
        DB::statement(<<<'SQL'
CREATE TABLE panel.data_source_cache (
    id bigint,
    source_code varchar(128),
    created_at timestamp without time zone,
    updated_at timestamp without time zone
)
SQL);

        DB::statement(<<<'SQL'
INSERT INTO panel.data_source_cache (id, source_code)
VALUES (NULL, 'sales_main_dashboard'), (5, 'stock_dashboard'), (5, 'orders_alinan')
SQL);

        $migration = require database_path('migrations/2026_04_27_180000_repair_panel_data_source_cache_columns.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('panel.data_source_cache', 'cache_key'));
        $this->assertTrue(Schema::hasColumn('panel.data_source_cache', 'request_payload'));
        $this->assertTrue(Schema::hasColumn('panel.data_source_cache', 'response_payload'));
        $this->assertTrue(Schema::hasColumn('panel.data_source_cache', 'expires_at'));

        $this->assertNotNull(DB::selectOne(
            "select pg_get_serial_sequence('panel.data_source_cache', 'id') as sequence_name"
        )->sequence_name);

        $this->assertSame(0, (int) DB::table('panel.data_source_cache')->whereNull('id')->count());
        $this->assertNotNull(DB::selectOne(<<<'SQL'
SELECT constraint_name
FROM information_schema.table_constraints
WHERE table_schema = 'panel'
  AND table_name = 'data_source_cache'
  AND constraint_type = 'PRIMARY KEY'
LIMIT 1
SQL)->constraint_name);
    }
}
