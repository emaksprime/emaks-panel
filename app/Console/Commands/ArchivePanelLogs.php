<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ArchivePanelLogs extends Command
{
    protected $signature = 'panel:archive-logs {--dry-run : Count eligible logs without copying or deleting} {--before= : Archive logs before this local date, format YYYY-MM-DD}';

    protected $description = 'Archive panel audit logs from previous months into panel.log_archives.';

    public function handle(): int
    {
        if (! Schema::hasTable('panel.log_archives')) {
            $this->error('panel.log_archives table does not exist. Run migrations first.');

            return self::FAILURE;
        }

        $timezone = 'Europe/Istanbul';
        $beforeLocal = $this->beforeDate($timezone);
        $beforeUtc = $beforeLocal->timezone('UTC');
        $eligible = AuditLog::query()->where('created_at', '<', $beforeUtc)->count();

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$eligible} log record(s) would be archived before {$beforeLocal->format('Y-m-d')}.");

            return self::SUCCESS;
        }

        $archived = 0;

        AuditLog::query()
            ->where('created_at', '<', $beforeUtc)
            ->orderBy('id')
            ->chunkById(500, function ($logs) use (&$archived, $timezone): void {
                DB::transaction(function () use ($logs, &$archived, $timezone): void {
                    $ids = [];

                    foreach ($logs as $log) {
                        $createdAt = $log->created_at instanceof \DateTimeInterface
                            ? CarbonImmutable::parse($log->created_at->format('Y-m-d H:i:s'), 'UTC')
                            : CarbonImmutable::parse((string) $log->created_at, 'UTC');

                        DB::table('panel.log_archives')->updateOrInsert([
                            'original_log_id' => $log->id,
                        ], [
                            'user_id' => $log->user_id,
                            'action' => $log->action,
                            'payload' => $log->payload === null ? null : json_encode($log->payload, JSON_UNESCAPED_UNICODE),
                            'created_at' => $createdAt,
                            'archived_at' => now(),
                            'archive_month' => $createdAt->timezone($timezone)->format('Y-m'),
                        ]);

                        $ids[] = $log->id;
                    }

                    if ($ids !== []) {
                        AuditLog::query()->whereIn('id', $ids)->delete();
                        $archived += count($ids);
                    }
                });
            });

        $this->info("Archived {$archived} log record(s) before {$beforeLocal->format('Y-m-d')}.");

        return self::SUCCESS;
    }

    private function beforeDate(string $timezone): CarbonImmutable
    {
        $before = $this->option('before');

        if ($before === null || $before === '') {
            return CarbonImmutable::now($timezone)->startOfMonth();
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', (string) $before, $timezone);

        if ($date === false) {
            throw new InvalidArgumentException('--before must use YYYY-MM-DD format.');
        }

        return $date->startOfDay();
    }
}
