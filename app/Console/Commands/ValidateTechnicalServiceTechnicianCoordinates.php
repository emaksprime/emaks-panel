<?php

namespace App\Console\Commands;

use App\Services\TechnicalService\TechnicianCoordinateDataService;
use Illuminate\Console\Command;

class ValidateTechnicalServiceTechnicianCoordinates extends Command
{
    protected $signature = 'technical-service:validate-technician-coordinates
        {--clear-invalid : Clear latitude/longitude fields for suspicious technician coordinates}';

    protected $description = 'Validate technical service technician coordinates and mark suspicious records for review.';

    public function handle(TechnicianCoordinateDataService $service): int
    {
        $summary = $service->validate((bool) $this->option('clear-invalid'));

        $this->info('Technical service technician coordinate validation completed.');
        $this->line('checked: '.$summary['checked']);
        $this->line('suspicious: '.count($summary['suspicious']));
        $this->line('marked_review: '.$summary['marked_review']);
        $this->line('cleared: '.$summary['cleared']);

        foreach (array_slice($summary['suspicious'], 0, 30) as $item) {
            $this->line(sprintf(
                '- #%s %s %s reasons=%s',
                $item['id'],
                $item['name'],
                $item['coordinate_key'] ?? '-',
                implode(',', $item['reasons'])
            ));
        }

        return self::SUCCESS;
    }
}
