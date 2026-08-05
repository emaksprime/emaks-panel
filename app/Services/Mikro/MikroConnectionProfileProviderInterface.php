<?php

namespace App\Services\Mikro;

interface MikroConnectionProfileProviderInterface
{
    public function profile(): MikroConnectionProfile;

    public function credentials(): MikroCredentialEnvelope;
}
