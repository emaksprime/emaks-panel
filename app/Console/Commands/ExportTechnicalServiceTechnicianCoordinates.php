<?php

namespace App\Console\Commands;

use App\Services\TechnicalService\TechnicianCoordinateDataService;
use Illuminate\Console\Command;

class ExportTechnicalServiceTechnicianCoordinates extends Command
{
    protected $signature = 'technical-service:export-technician-coordinates
        {--output=database/data/technical_service_technician_coordinates.json : Versioned JSON output path}
        {--include-review : Include needs_review and suspicious records}';

    protected $description = 'Export reviewed technical service technician coordinates to versioned JSON.';

    public function handle(TechnicianCoordinateDataService $service): int
    {
        $outputPath = $this->resolvePath((string) $this->option('output'));
        $summary = $service->export($outputPath, (bool) $this->option('include-review'));

        $this->info('Technician coordinates exported.');
        $this->line('exported: '.$summary['exported']);
        $this->line('needs_review_excluded: '.$summary['needs_review_excluded']);
        $this->line('suspicious_excluded: '.$summary['suspicious_excluded']);
        $this->line('path: '.$summary['path']);

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        return preg_match('/^[A-Za-z]:[\\\\\\/]|^\//', $path) === 1
            ? $path
            : base_path($path);
    }
}
