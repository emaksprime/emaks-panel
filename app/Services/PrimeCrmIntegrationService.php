<?php

namespace App\Services;

class PrimeCrmIntegrationService
{
    /**
     * @return array<string, mixed>|null
     */
    public function forPageCode(?string $pageCode): ?array
    {
        if (! $pageCode) {
            return null;
        }

        $module = config("primecrm.modules.{$pageCode}");

        if (! is_array($module)) {
            return null;
        }

        $baseUrl = rtrim((string) config('primecrm.base_url'), '/');
        $path = '/'.ltrim((string) ($module['path'] ?? '/'), '/');
        $externalUrl = $baseUrl !== '' ? $baseUrl.$path : null;

        return [
            'provider' => 'primecrm',
            'label' => $module['label'] ?? $pageCode,
            'capability' => $module['capability'] ?? null,
            'path' => $path,
            'externalUrl' => $externalUrl,
            'enabled' => (bool) config('primecrm.enabled') && $externalUrl !== null,
            'launchMode' => config('primecrm.launch_mode', 'external'),
            'message' => $externalUrl
                ? 'PrimeCRM harici Coolify servisi üzerinden açılır.'
                : 'PRIMECRM_BASE_URL tanımlanınca harici PrimeCRM servisine bağlanacak.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function placeholder(string $pageCode): array
    {
        return [
            'mode' => 'external_integration',
            'message' => 'Canlı veri Laravel içine gömülmedi; PrimeCRM harici servis entegrasyonu üzerinden bağlanacak.',
            'integration' => $this->forPageCode($pageCode),
        ];
    }
}
