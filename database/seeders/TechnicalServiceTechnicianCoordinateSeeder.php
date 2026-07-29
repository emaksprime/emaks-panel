<?php

namespace Database\Seeders;

use App\Services\TechnicalService\TechnicianCoordinateDataService;
use Illuminate\Database\Seeder;
use RuntimeException;

class TechnicalServiceTechnicianCoordinateSeeder extends Seeder
{
    public function run(TechnicianCoordinateDataService $service): void
    {
        $path = (string) config(
            'technical_service.technician_coordinate_seed_data_path',
            database_path('data/technical_service_technician_coordinates.json'),
        );

        try {
            $summary = $service->seed($path);
        } catch (RuntimeException $exception) {
            $this->command?->error($exception->getMessage());
            throw $exception;
        }

        $this->command?->info('Technical service technician coordinate seed completed.');
        $this->command?->line('updated: '.$summary['updated']);
        $this->command?->line('skipped: '.$summary['skipped']);
        $this->command?->line('review_skipped: '.$summary['review_skipped']);
    }
}
