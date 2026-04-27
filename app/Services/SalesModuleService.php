<?php

namespace App\Services;

class SalesModuleService
{
    public function __construct(
        private readonly PrimeCrmIntegrationService $primeCrm,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function mainSummary(): array
    {
        return $this->primeCrm->placeholder('sales_main');
    }

    /**
     * @return array<string, mixed>
     */
    public function onlineDetail(): array
    {
        return $this->primeCrm->placeholder('sales_online');
    }

    /**
     * @return array<string, mixed>
     */
    public function bayiDetail(): array
    {
        return $this->primeCrm->placeholder('sales_bayi');
    }

    /**
     * @return array<string, mixed>
     */
    public function representativeSummary(): array
    {
        return $this->primeCrm->placeholder('sales_representatives');
    }
}
