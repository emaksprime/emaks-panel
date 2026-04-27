<?php

namespace App\Services;

class CariModuleService
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
        return $this->primeCrm->placeholder('cari');
    }

    /**
     * @return array<string, mixed>
     */
    public function balance(): array
    {
        return $this->primeCrm->placeholder('cari_balance');
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(): array
    {
        return $this->primeCrm->placeholder('cari_detail');
    }

    /**
     * @return array<string, mixed>
     */
    public function statement(): array
    {
        return $this->primeCrm->placeholder('cari_detail');
    }
}
