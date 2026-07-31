<?php

namespace App\Console\Commands;

use App\Services\TechnicalService\LocksmithImportService;
use Illuminate\Console\Command;
use RuntimeException;

class ImportTechnicalServiceLocksmiths extends Command
{
    protected $signature = 'technical-service:import-locksmiths {path : XLSX import file path} {--dry-run : Parse and report without writing}';

    protected $description = 'Import locksmith technicians from the consolidated technical service XLSX list.';

    public function handle(LocksmithImportService $service): int
    {
        $path = (string) $this->argument('path');

        try {
            $summary = $service->import($path, (bool) $this->option('dry-run'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(($summary['dry_run'] ? 'Dry-run completed.' : 'Import completed.'));
        $this->line('imported: '.$summary['imported']);
        $this->line('updated: '.$summary['updated']);
        $this->line('skipped: '.$summary['skipped']);
        $this->line('needs_review: '.$summary['needs_review']);

        if ($summary['errors'] !== []) {
            $this->warn('Skipped rows:');

            foreach (array_slice($summary['errors'], 0, 20) as $error) {
                $this->line('- row '.$error['row'].': '.$error['reason']);
            }

            if (count($summary['errors']) > 20) {
                $this->line('- ...');
            }
        }

        return self::SUCCESS;
    }
}
