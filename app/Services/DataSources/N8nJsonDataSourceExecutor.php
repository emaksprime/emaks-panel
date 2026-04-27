<?php

namespace App\Services\DataSources;

use App\Contracts\DataSourceExecutor;
use App\Models\DataSource;
use App\Services\N8nPanelDataGateway;

class N8nJsonDataSourceExecutor implements DataSourceExecutor
{
    public function __construct(
        private readonly N8nPanelDataGateway $gateway,
    ) {
    }

    public function driver(): string
    {
        return 'n8n_json';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(DataSource $dataSource, array $payload = []): array
    {
        return $this->gateway->run($dataSource->code, $payload, $dataSource);
    }
}
