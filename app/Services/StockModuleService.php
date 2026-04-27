<?php

namespace App\Services;

class StockModuleService
{
    public function __construct(
        private readonly PrimeCrmIntegrationService $primeCrm,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return $this->primeCrm->placeholder('stock');
    }

    /**
     * @return array<string, mixed>
     */
    public function critical(): array
    {
        return $this->primeCrm->placeholder('stock_critical');
    }

    /**
     * @return array<string, mixed>
     */
    public function warehouse(): array
    {
        return $this->primeCrm->placeholder('stock_warehouse');
    }
}
