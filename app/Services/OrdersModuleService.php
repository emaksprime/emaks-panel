<?php

namespace App\Services;

class OrdersModuleService
{
    public function __construct(
        private readonly PrimeCrmIntegrationService $primeCrm,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function alinan(): array
    {
        return $this->primeCrm->placeholder('orders_alinan');
    }

    /**
     * @return array<string, mixed>
     */
    public function verilen(): array
    {
        return $this->primeCrm->placeholder('orders_verilen');
    }
}
