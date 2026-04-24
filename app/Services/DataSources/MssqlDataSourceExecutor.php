<?php

namespace App\Services\DataSources;

use App\Contracts\DataSourceExecutor;
use App\Models\DataSource;
use RuntimeException;

class MssqlDataSourceExecutor implements DataSourceExecutor
{
    public function driver(): string
    {
        return 'mssql';
    }

    public function execute(DataSource $dataSource, array $payload = []): array
    {
        throw new RuntimeException(
            sprintf(
                'MSSQL execution is intentionally not wired yet for datasource "%s". Configure the connection at runtime via the data_sources metadata and a concrete executor implementation.',
                $dataSource->code,
            ),
        );
    }
}
