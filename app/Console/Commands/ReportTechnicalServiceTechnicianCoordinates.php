<?php

namespace App\Console\Commands;

use App\Services\TechnicalService\TechnicianCoordinateDataService;
use Illuminate\Console\Command;

class ReportTechnicalServiceTechnicianCoordinates extends Command
{
    protected $signature = 'technical-service:geocode-technicians-report
        {--no-mark-review : Only report suspicious records without marking needs_review}';

    protected $description = 'Report technical service technician coordinate quality and suspicious records.';

    public function handle(TechnicianCoordinateDataService $service): int
    {
        $report = $service->report(! (bool) $this->option('no-mark-review'));

        $this->info('Technical service technician coordinate report.');
        $this->line('total: '.$report['total']);
        $this->line('with_coordinates: '.$report['with_coordinates']);
        $this->line('without_coordinates: '.$report['without_coordinates']);
        $this->line('needs_review: '.$report['needs_review']);

        $this->line('source_distribution:');
        foreach ($report['source_distribution'] as $source => $count) {
            $this->line("- {$source}: {$count}");
        }

        $this->line('duplicate_coordinates: '.count($report['duplicate_coordinates']));
        foreach (array_slice($report['duplicate_coordinates'], 0, 20) as $duplicate) {
            $cities = implode(', ', $duplicate['cities']);
            $this->line("- {$duplicate['coordinate']} count={$duplicate['count']} cities={$cities}");
        }

        $this->line('suspicious: '.count($report['suspicious']));
        foreach (array_slice($report['suspicious'], 0, 30) as $item) {
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
