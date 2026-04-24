<?php

namespace App\Services;

use App\Contracts\DataSourceExecutor;
use App\Models\DataSource;
use App\Services\DataSources\MssqlDataSourceExecutor;
use Illuminate\Support\Collection;
use RuntimeException;

class PanelDataSourceManager
{
    /** @var array<string, DataSourceExecutor> */
    private array $executors = [];

    public function __construct()
    {
        $this->register(new MssqlDataSourceExecutor);
    }

    public function register(DataSourceExecutor $executor): void
    {
        $this->executors[$executor->driver()] = $executor;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function summaries(): array
    {
        return DataSource::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (DataSource $dataSource) => [
                'id' => $dataSource->id,
                'name' => $dataSource->name,
                'slug' => $dataSource->code,
                'driver' => $dataSource->db_type,
                'target' => $dataSource->connection_meta['target'] ?? null,
                'status' => $dataSource->active ? 'active' : 'inactive',
                'description' => $dataSource->description,
                'database' => $dataSource->connection_meta['database'] ?? null,
                'host' => $dataSource->connection_meta['host'] ?? null,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(DataSource $dataSource, array $payload = []): array
    {
        $executor = $this->executors[$dataSource->db_type] ?? null;

        if (! $executor) {
            throw new RuntimeException("No executor registered for datasource driver [{$dataSource->db_type}].");
        }

        return $executor->execute($dataSource, $payload);
    }

    /**
     * @return Collection<int, string>
     */
    public function drivers(): Collection
    {
        return collect(array_keys($this->executors));
    }
}
