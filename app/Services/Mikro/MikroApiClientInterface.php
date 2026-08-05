<?php

namespace App\Services\Mikro;

interface MikroApiClientInterface
{
    public function probeHealth(
        MikroConnectionProfile $profile,
        MikroRequestContext $context,
    ): MikroApiResult;

    public function readStockList(
        MikroConnectionProfile $profile,
        MikroCredentialEnvelope $credentials,
        MikroRequestContext $context,
        MikroStockListRequest $request,
    ): MikroApiResult;
}
