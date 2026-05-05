<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $oldWhere = <<<'SQL'
WHERE ISNULL(LTRIM(RTRIM(msg_S_1032)), N'') <> N'';
SQL;

        $newWhere = <<<'SQL'
WHERE ISNULL(LTRIM(RTRIM(msg_S_1032)), N'') <> N''
  AND UPPER(LTRIM(RTRIM(ISNULL(msg_S_0012, N'')))) IN (
      N'A1', N'AS1', N'D1', N'G1', N'K1',
      N'KA1', N'M1', N'O1', N'OT1', N'YM1'
  );
SQL;

        $queryTemplate = DB::table('data_sources')
            ->where('code', 'sales_main_dashboard')
            ->value('query_template');

        if (! is_string($queryTemplate) || $queryTemplate === '') {
            return;
        }

        if (str_contains($queryTemplate, $newWhere)) {
            return;
        }

        if (! str_contains($queryTemplate, $oldWhere)) {
            return;
        }

        DB::table('data_sources')
            ->where('code', 'sales_main_dashboard')
            ->update([
                'query_template' => str_replace($oldWhere, $newWhere, $queryTemplate),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $oldWhere = <<<'SQL'
WHERE ISNULL(LTRIM(RTRIM(msg_S_1032)), N'') <> N'';
SQL;

        $newWhere = <<<'SQL'
WHERE ISNULL(LTRIM(RTRIM(msg_S_1032)), N'') <> N''
  AND UPPER(LTRIM(RTRIM(ISNULL(msg_S_0012, N'')))) IN (
      N'A1', N'AS1', N'D1', N'G1', N'K1',
      N'KA1', N'M1', N'O1', N'OT1', N'YM1'
  );
SQL;

        $queryTemplate = DB::table('data_sources')
            ->where('code', 'sales_main_dashboard')
            ->value('query_template');

        if (! is_string($queryTemplate) || ! str_contains($queryTemplate, $newWhere)) {
            return;
        }

        DB::table('data_sources')
            ->where('code', 'sales_main_dashboard')
            ->update([
                'query_template' => str_replace($newWhere, $oldWhere, $queryTemplate),
                'updated_at' => now(),
            ]);
    }
};
