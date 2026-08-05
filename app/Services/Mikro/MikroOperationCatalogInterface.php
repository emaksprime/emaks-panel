<?php

namespace App\Services\Mikro;

interface MikroOperationCatalogInterface
{
    public function find(string $code): ?MikroOperationDefinition;
}
