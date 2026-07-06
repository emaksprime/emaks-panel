<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;

class TechnicalServiceMessageProviderRouter
{
    public function __construct(
        private readonly TechnicalServiceMessagingSettingsService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dispatch(TechnicalServiceMessageDispatch $dispatch, bool $noExternal = false): array
    {
        $provider = (string) ($dispatch->provider_key ?: 'null_local');

        if ($provider === 'null_local') {
            return [
                'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED,
                'provider_status' => 'dry_run',
                'provider_message_id' => null,
                'response' => ['provider' => 'null_local', 'external_call' => false],
                'error' => null,
            ];
        }

        if (str_starts_with($provider, 'voibot')) {
            return $this->blocked('contract_pending', 'Voibot sağlayıcı sözleşmesi/API netleşmeden gönderim kapalı.');
        }

        if ($provider === 'future_sms_provider') {
            return $this->blocked('provider_pending', 'Gelecek SMS sağlayıcısı henüz aktif değil.');
        }

        if ($provider === 'nac_sms') {
            return $this->fakeableAccepted($dispatch, $noExternal, 'nac_sms', 'direct_laravel');
        }

        if ($provider === 'evo_whatsapp') {
            return $this->fakeableAccepted($dispatch, $noExternal, 'evo_whatsapp', 'evo_adapter');
        }

        return $this->blocked('provider_unknown', 'Bilinmeyen mesaj sağlayıcısı.');
    }

    public function providerReady(string $provider): bool
    {
        $payloadRoot = $this->settings->payload();
        if ($provider === 'nac_sms') {
            return (bool) data_get($payloadRoot, 'nac_sms.enabled', false);
        }

        $providers = collect($this->settings->payload()['providers'] ?? []);
        $payload = $providers->firstWhere('key', $provider);

        if ($provider === 'null_local') {
            return true;
        }

        return is_array($payload)
            && (bool) ($payload['enabled'] ?? false)
            && (bool) ($payload['contract_confirmed'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeableAccepted(TechnicalServiceMessageDispatch $dispatch, bool $noExternal, string $provider, string $transport): array
    {
        if (! $this->providerReady($provider)) {
            return $this->blocked('provider_not_ready', 'Provider kapalı veya readiness eksik.');
        }

        if ($noExternal) {
            return [
                'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED,
                'provider_status' => 'no_external',
                'provider_message_id' => null,
                'response' => [
                    'provider' => $provider,
                    'transport' => $transport,
                    'external_call' => false,
                ],
                'error' => null,
            ];
        }

        if ($dispatch->recipient_role !== 'test') {
            return $this->blocked('business_send_disabled_rel4d', 'REL-4D provider router business gönderimi yapmaz; REL-4E/REL-4F bekleniyor.');
        }

        return [
            'status' => TechnicalServiceMessageDispatch::STATUS_TEST_SENT,
            'provider_status' => 'fake_accepted',
            'provider_message_id' => $provider.'-fake-'.$dispatch->id,
            'response' => [
                'provider' => $provider,
                'transport' => $transport,
                'external_call' => false,
                'test_only' => true,
            ],
            'error' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blocked(string $code, string $message): array
    {
        return [
            'status' => TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR,
            'provider_status' => $code,
            'provider_message_id' => null,
            'response' => ['status' => $code, 'message' => $message],
            'error' => $message,
        ];
    }
}
