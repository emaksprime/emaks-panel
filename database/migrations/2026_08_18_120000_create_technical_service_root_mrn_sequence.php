<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SEQUENCE = 'technical_service_root_mrn_sequence';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Global root MRN sequence migration requires PostgreSQL.');
        }

        if (! Schema::hasTable('technical_service_requests')) {
            throw new RuntimeException('Technical service requests table is missing.');
        }

        $duplicate = DB::table('technical_service_requests')
            ->select('mrn')
            ->groupBy('mrn')
            ->havingRaw('count(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException('Historical technical service MRN duplicates block global sequence creation.');
        }

        $highWater = 0;
        $roots = DB::table('technical_service_requests')
            ->select([
                'id',
                'mrn',
                'source_channel',
                'mount_session_id',
                'qr_link_id',
            ])
            ->whereNull('parent_request_id')
            ->whereNull('service_code')
            ->orderBy('id')
            ->get();

        foreach ($roots as $root) {
            $mrn = trim((string) $root->mrn);

            if (preg_match('/^MRN-\d{4}[A-Z]{2}\d{2}(\d{4,})$/D', $mrn, $matches) === 1) {
                $highWater = max($highWater, (int) $matches[1]);

                continue;
            }

            if ($this->isKnownNonGeneratedRoot($root, $mrn)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Unclassified historical root MRN blocks global sequence creation (request id %d).',
                (int) $root->id,
            ));
        }

        $start = $highWater + 1;

        DB::statement(sprintf(
            'create sequence if not exists %s as bigint increment by 1 minvalue 1 start with %d no cycle cache 1',
            self::SEQUENCE,
            $start,
        ));

        $state = DB::selectOne(sprintf(
            'select last_value, is_called from %s',
            self::SEQUENCE,
        ));
        $lastValue = (int) ($state?->last_value ?? 0);
        $isCalled = filter_var($state?->is_called ?? false, FILTER_VALIDATE_BOOL);
        $existingNext = $isCalled ? $lastValue + 1 : $lastValue;
        $effectiveStart = max($start, $existingNext);

        DB::selectOne(
            "select setval('technical_service_root_mrn_sequence'::regclass, cast(? as bigint), false)",
            [$effectiveStart],
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('drop sequence if exists '.self::SEQUENCE);
    }

    private function isKnownNonGeneratedRoot(object $root, string $mrn): bool
    {
        if ((string) $root->source_channel === 'synthetic_local_uat') {
            return true;
        }

        return str_starts_with($mrn, 'PR88-REL4E17A1-VERIFY-JOB-')
            && (string) $root->source_channel === 'qr_mount_form'
            && $root->mount_session_id === null
            && $root->qr_link_id === null;
    }
};
