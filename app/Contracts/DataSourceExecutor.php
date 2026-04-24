<?php

namespace App\Contracts;

use App\Models\DataSource;

interface DataSourceExecutor
{
    public function driver(): string;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(DataSource $dataSource, array $payload = []): array;
}
