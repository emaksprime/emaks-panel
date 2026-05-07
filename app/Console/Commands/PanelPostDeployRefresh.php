<?php

namespace App\Console\Commands;

use Closure;
use Database\Seeders\PanelDataSourcesSeeder;
use Database\Seeders\PanelKnownWorkflowDataSourcesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class PanelPostDeployRefresh extends Command
{
    protected $signature = 'panel:post-deploy-refresh';

    protected $description = 'Refresh panel datasource metadata and clear deploy caches without touching users or permissions.';

    public function handle(): int
    {
        $this->runStep('PanelDataSourcesSeeder', fn () => $this->call('db:seed', [
            '--class' => PanelDataSourcesSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]));

        $this->runStep('PanelKnownWorkflowDataSourcesSeeder', fn () => $this->call('db:seed', [
            '--class' => PanelKnownWorkflowDataSourcesSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]));

        $this->runStep('optimize:clear', fn () => $this->call('optimize:clear', ['--no-interaction' => true]));
        $this->runStep('cache:clear', fn () => $this->call('cache:clear', ['--no-interaction' => true]));
        $this->runStep('route:clear', fn () => $this->call('route:clear', ['--no-interaction' => true]));
        $this->runStep('view:clear', fn () => $this->call('view:clear', ['--no-interaction' => true]));
        $this->runStep('panel.data_source_cache truncate', fn () => $this->clearDataSourceCache());

        $this->info('Panel post-deploy refresh completed.');

        return self::SUCCESS;
    }

    private function runStep(string $name, Closure $callback): void
    {
        $this->line("Running {$name}...");

        try {
            $result = $callback();

            if (is_int($result) && $result !== self::SUCCESS) {
                throw new RuntimeException("Step exited with code {$result}");
            }

            $this->info("Completed {$name}.");
        } catch (Throwable $exception) {
            Log::error('Panel post-deploy refresh step failed.', [
                'step' => $name,
                'message' => $exception->getMessage(),
            ]);

            $this->error("Failed {$name}: {$exception->getMessage()}");

            throw $exception;
        }
    }

    private function clearDataSourceCache(): void
    {
        if (! Schema::hasTable('panel.data_source_cache')) {
            $this->warn('panel.data_source_cache table does not exist; skipping cache truncate.');

            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('TRUNCATE TABLE panel.data_source_cache RESTART IDENTITY');

            return;
        }

        DB::table('panel.data_source_cache')->delete();
    }
}
