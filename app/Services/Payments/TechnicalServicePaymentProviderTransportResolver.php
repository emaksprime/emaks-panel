<?php

namespace App\Services\Payments;

class TechnicalServicePaymentProviderTransportResolver
{
    public const TRANSPORT_DIRECT_LARAVEL = 'direct_laravel';
    public const TRANSPORT_N8N_DISABLED = 'n8n_disabled';

    public function activeTransport(): string
    {
        $transport = strtolower(trim((string) config('payments.provider_transport', self::TRANSPORT_DIRECT_LARAVEL)));

        return $transport === self::TRANSPORT_DIRECT_LARAVEL
            ? self::TRANSPORT_DIRECT_LARAVEL
            : self::TRANSPORT_N8N_DISABLED;
    }

    public function activeTransportLabel(): string
    {
        return $this->activeTransport() === self::TRANSPORT_DIRECT_LARAVEL
            ? 'Laravel Direct'
            : 'n8n ödeme adaptörü devre dışı';
    }

    public function directLaravel(): bool
    {
        return $this->activeTransport() === self::TRANSPORT_DIRECT_LARAVEL;
    }
}
