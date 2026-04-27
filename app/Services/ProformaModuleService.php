<?php

namespace App\Services;

class ProformaModuleService
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
        return $this->primeCrm->placeholder('proforma');
    }

    /**
     * @return array<string, mixed>
     */
    public function createDraft(): array
    {
        return $this->primeCrm->placeholder('proforma_create');
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(): array
    {
        return $this->primeCrm->placeholder('proforma_detail');
    }
}
