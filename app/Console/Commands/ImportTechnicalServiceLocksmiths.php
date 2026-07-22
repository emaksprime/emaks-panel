<?php

namespace App\Console\Commands;

use App\Services\TechnicalService\LocksmithImportService;
use Illuminate\Console\Command;
use RuntimeException;

class ImportTechnicalServiceLocksmiths extends Command
{
    protected $signature = 'technical-service:import-locksmiths
        {--source= : XLSX source under storage/app/private/technical-service}
        {--apply : Apply the validated import; default is dry-run}';

    protected $description = 'Safely import locksmith master data from an explicit private XLSX source.';

    public function handle(LocksmithImportService $service): int
    {
        $path = trim((string) $this->option('source'));

        if ($path === '') {
            $this->error('Private locksmith source zorunludur.');

            return self::FAILURE;
        }

        try {
            $summary = $service->import($path, (bool) $this->option('apply'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($summary['dry_run'] ? 'Dry-run completed.' : 'Import completed.');
        foreach (['total', 'valid', 'insert', 'update', 'skip', 'conflict', 'invalid', 'delete'] as $key) {
            $this->line($key.': '.$summary[$key]);
        }

        return self::SUCCESS;
    }
}
