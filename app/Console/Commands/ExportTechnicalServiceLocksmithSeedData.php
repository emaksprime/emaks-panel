<?php

namespace App\Console\Commands;

use App\Services\TechnicalService\LocksmithImportService;
use Illuminate\Console\Command;
use RuntimeException;

class ExportTechnicalServiceLocksmithSeedData extends Command
{
    protected $signature = 'technical-service:export-locksmith-seed-data
        {path=storage/app/imports/clg_servis_konsolide_liste.xlsx : XLSX source file path}
        {--output=database/data/technical_service_locksmiths.json : Versioned JSON output path}';

    protected $description = 'Export normalized locksmith technician seed data from the local XLSX source.';

    public function handle(LocksmithImportService $service): int
    {
        $sourcePath = $this->resolvePath((string) $this->argument('path'));
        $outputPath = $this->resolvePath((string) $this->option('output'));

        try {
            $summary = $service->exportSeedData($sourcePath, $outputPath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Seed data exported.');
        $this->line('exported: '.$summary['exported']);
        $this->line('skipped: '.$summary['skipped']);
        $this->line('needs_review: '.$summary['needs_review']);
        $this->line('path: '.$summary['path']);

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

    private function resolvePath(string $path): string
    {
        return preg_match('/^[A-Za-z]:[\\\\\\/]|^\//', $path) === 1
            ? $path
            : base_path($path);
    }
}
