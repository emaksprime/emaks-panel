<?php

namespace Database\Seeders;

use App\Services\TechnicalService\LocksmithImportService;
use Illuminate\Database\Seeder;
use RuntimeException;

class TechnicalServiceLocksmithSeeder extends Seeder
{
    public function run(LocksmithImportService $service): void
    {
        $path = trim((string) config('technical_service.locksmith_seed_data_path'));

        if ($path === '') {
            throw new RuntimeException('Explicit private locksmith seed path zorunludur.');
        }

        try {
            $summary = $service->seedFromJson($path);
        } catch (RuntimeException $exception) {
            $this->command?->error($exception->getMessage());
            throw $exception;
        }

        $this->command?->info('Technical service locksmith seed completed.');
        $this->command?->line('imported: '.$summary['imported']);
        $this->command?->line('updated: '.$summary['updated']);
        $this->command?->line('skipped: '.$summary['skipped']);
        $this->command?->line('needs_review: '.$summary['needs_review']);
    }
}
