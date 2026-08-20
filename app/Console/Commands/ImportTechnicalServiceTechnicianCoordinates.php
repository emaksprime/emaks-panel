<?php

namespace App\Console\Commands;

use App\Services\TechnicalService\TechnicianCoordinateDataService;
use Illuminate\Console\Command;
use RuntimeException;

class ImportTechnicalServiceTechnicianCoordinates extends Command
{
    protected $signature = 'technical-service:import-technician-coordinates
        {--source= : JSON source under storage/app/private/technical-service}
        {--apply : Apply the validated import; default is dry-run}';

    protected $description = 'Safely import locksmith coordinates from an explicit private JSON source.';

    public function handle(TechnicianCoordinateDataService $service): int
    {
        $source = trim((string) $this->option('source'));
        if ($source === '') {
            $this->error('Private coordinate source zorunludur.');

            return self::FAILURE;
        }

        try {
            $summary = $service->import($source, (bool) $this->option('apply'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($summary['dry_run'] ? 'Coordinate dry-run completed.' : 'Coordinate import completed.');
        foreach (['total', 'valid', 'insert', 'update', 'skip', 'conflict', 'invalid', 'delete'] as $key) {
            $this->line($key.': '.$summary[$key]);
        }

        return self::SUCCESS;
    }
}
