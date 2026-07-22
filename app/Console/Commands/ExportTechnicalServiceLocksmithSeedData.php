<?php

namespace App\Console\Commands;

use App\Services\TechnicalService\LocksmithImportService;
use Illuminate\Console\Command;
use RuntimeException;

class ExportTechnicalServiceLocksmithSeedData extends Command
{
    protected $signature = 'technical-service:export-locksmith-seed-data
        {--source= : XLSX source under storage/app/private/technical-service}
        {--output= : New JSON output under storage/app/private/technical-service}';

    protected $description = 'Export normalized locksmith data between explicit private paths.';

    public function handle(LocksmithImportService $service): int
    {
        $sourcePath = trim((string) $this->option('source'));
        $outputPath = trim((string) $this->option('output'));

        if ($sourcePath === '' || $outputPath === '') {
            $this->error('Private source ve output zorunludur.');

            return self::FAILURE;
        }

        try {
            $summary = $service->exportSeedData($sourcePath, $outputPath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Private seed data exported.');
        foreach (['total', 'valid', 'exported', 'skip', 'conflict', 'invalid', 'delete'] as $key) {
            $this->line($key.': '.$summary[$key]);
        }

        return self::SUCCESS;
    }
}
