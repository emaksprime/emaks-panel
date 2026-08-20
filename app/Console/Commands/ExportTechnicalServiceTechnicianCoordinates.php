<?php

namespace App\Console\Commands;

use App\Services\TechnicalService\TechnicianCoordinateDataService;
use Illuminate\Console\Command;
use RuntimeException;

class ExportTechnicalServiceTechnicianCoordinates extends Command
{
    protected $signature = 'technical-service:export-technician-coordinates
        {--output= : New JSON output under storage/app/private/technical-service}
        {--include-review : Include needs_review and suspicious records}';

    protected $description = 'Export locksmith coordinates to an explicit private JSON path.';

    public function handle(TechnicianCoordinateDataService $service): int
    {
        $outputPath = trim((string) $this->option('output'));
        if ($outputPath === '') {
            $this->error('Private coordinate output zorunludur.');

            return self::FAILURE;
        }

        try {
            $summary = $service->export($outputPath, (bool) $this->option('include-review'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Technician coordinates exported.');
        $this->line('exported: '.$summary['exported']);
        $this->line('needs_review_excluded: '.$summary['needs_review_excluded']);
        $this->line('suspicious_excluded: '.$summary['suspicious_excluded']);

        return self::SUCCESS;
    }
}
