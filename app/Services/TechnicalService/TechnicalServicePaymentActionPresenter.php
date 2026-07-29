<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountPayment;
use App\Support\PartnerPortalPublicUrl;
use InvalidArgumentException;

class TechnicalServicePaymentActionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function forPayment(TechnicalServiceMountPayment $payment, ?string $fakeApproveUrl = null): array
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $providerDecision = is_array($payload['provider_decision'] ?? null) ? $payload['provider_decision'] : [];
        $providerGateway = is_array($payload['provider_gateway'] ?? null) ? $payload['provider_gateway'] : [];
        $providerGatewaySync = is_array($payload['provider_gateway_sync'] ?? null) ? $payload['provider_gateway_sync'] : [];
        $provider = strtolower((string) ($payment->provider ?? ''));
        $providerMode = strtolower((string) (
            $payload['provider_mode']
            ?? $providerDecision['provider_mode']
            ?? ($provider === 'fake' ? 'local' : ($payload['provider_environment'] ?? ''))
        ));
        $providerTransport = strtolower((string) (
            $payload['provider_transport']
            ?? $providerDecision['provider_transport']
            ?? ($provider === 'fake' ? 'fake_local' : '')
        ));
        $isFakeProvider = $provider === 'fake' || $providerTransport === 'fake_local';
        $isIyzicoProvider = $provider === 'iyzico' || $providerTransport === 'direct_laravel';
        $paymentUrl = trim((string) ($payment->payment_url ?? ''));
        $publicUrlBlockerCode = null;
        try {
            $paymentUrl = $isIyzicoProvider
                ? (PartnerPortalPublicUrl::trustedPaymentProviderUrl($paymentUrl) ?? '')
                : (PartnerPortalPublicUrl::rebaseLegacyUrl($paymentUrl) ?? '');
        } catch (InvalidArgumentException $exception) {
            $paymentUrl = '';
            $publicUrlBlockerCode = preg_match('/^\[([A-Z0-9_]+)\]/', $exception->getMessage(), $matches) === 1
                ? $matches[1]
                : 'LEGACY_PUBLIC_URL_UNRESOLVABLE';
        }
        $providerStatus = $providerGatewaySync['provider_status']
            ?? $providerGateway['provider_status']
            ?? $providerGatewaySync['raw_status']
            ?? $providerGateway['raw_status']
            ?? $payment->status;
        $isPending = $payment->status === TechnicalServiceMountPayment::STATUS_PENDING;
        $isPaid = $payment->status === TechnicalServiceMountPayment::STATUS_PAID;
        $isCancelled = $payment->status === TechnicalServiceMountPayment::STATUS_CANCELLED;
        $syncWaiting = $isIyzicoProvider && $isPending && $payment->provider_reference !== null;
        $canCopy = $paymentUrl !== '';
        $canFakeComplete = $isFakeProvider && $isPending && $fakeApproveUrl !== null;
        $canOpenProviderUrl = ! $isFakeProvider && $isPending && $paymentUrl !== '';
        $actionKind = 'none';
        $actionLabel = null;
        $disabledReason = null;
        $copyDisabledReason = $canCopy
            ? null
            : ($publicUrlBlockerCode === null ? 'Kopyalanacak link yok.' : 'Kayıtlı ödeme linki güvenli biçimde çözümlenemedi.');
        $providerLabel = self::providerLabel($provider, $providerMode, $providerTransport);

        if ($canFakeComplete) {
            $actionKind = 'fake_complete';
            $actionLabel = 'Fake ödeme tamamla';
        } elseif ($canOpenProviderUrl) {
            $actionKind = 'open_provider_url';
            $actionLabel = $isIyzicoProvider ? 'Iyzico ödeme ekranını aç' : 'Ödeme linkini aç';
        } elseif ($isPaid) {
            $disabledReason = 'Ödeme tamamlandı.';
        } elseif ($isCancelled) {
            $disabledReason = 'Ödeme linki iptal edildi.';
        } elseif ($paymentUrl === '') {
            $disabledReason = $publicUrlBlockerCode === null
                ? 'Ödeme linki henüz hazır değil.'
                : 'Kayıtlı ödeme linki güvenli biçimde çözümlenemedi.';
        } elseif ($isFakeProvider) {
            $disabledReason = 'Fake ödeme simülasyonu bu ekranda kapalı.';
        }

        return [
            'payment_url' => $paymentUrl !== '' ? $paymentUrl : null,
            'copy_url' => $paymentUrl !== '' ? $paymentUrl : null,
            'provider' => $provider !== '' ? $provider : null,
            'provider_mode' => $providerMode !== '' ? $providerMode : null,
            'provider_transport' => $providerTransport !== '' ? $providerTransport : null,
            'provider_token' => $payment->provider_reference,
            'provider_reference' => $payment->provider_reference,
            'provider_payment_reference' => $payment->provider_payment_reference,
            'provider_transaction_reference' => $payment->provider_transaction_reference,
            'provider_receipt_reference' => $payment->provider_receipt_reference,
            'provider_status' => $providerStatus,
            'provider_last_synced_at' => $payment->provider_last_synced_at?->toISOString(),
            'provider_sync_attempts' => (int) ($payment->provider_sync_attempts ?? 0),
            'provider_last_sync_status' => $payment->provider_last_sync_status,
            'provider_last_sync_error' => $payment->provider_last_sync_error,
            'provider_paid_confirmed_at' => $payment->provider_paid_confirmed_at?->toISOString(),
            'provider_sync_waiting' => $syncWaiting,
            'provider_sync_message' => $syncWaiting
                ? 'Otomatik kontrol bekliyor. Iyzico Back URL onayı bekleniyor; ödeme durumu otomatik/manuel sağlayıcı senkronizasyonuyla doğrulanır.'
                : null,
            'provider_label' => $providerLabel,
            'provider_display_label' => $providerLabel,
            'is_fake_provider' => $isFakeProvider,
            'is_external_provider' => ! $isFakeProvider,
            'can_open_payment_url' => $canOpenProviderUrl,
            'can_copy_payment_url' => $canCopy,
            'can_fake_complete_payment' => $canFakeComplete,
            'can_cancel_payment' => $isPending,
            'payment_action_kind' => $actionKind,
            'payment_action_label' => $actionLabel,
            'payment_action_disabled_reason' => $disabledReason,
            'copy_disabled_reason' => $copyDisabledReason,
            'public_url_blocker_code' => $publicUrlBlockerCode,
            'fake_approve_url' => $canFakeComplete ? $fakeApproveUrl : null,
        ];
    }

    private static function providerLabel(string $provider, string $mode, string $transport): string
    {
        if ($provider === 'fake' || $transport === 'fake_local') {
            return 'Fake/Yerel ödeme simülasyonu';
        }

        if ($provider === 'iyzico' || $transport === 'direct_laravel') {
            return $mode === 'live' ? 'Iyzico Live' : 'Iyzico Sandbox';
        }

        return 'Ödeme sağlayıcısı';
    }
}
