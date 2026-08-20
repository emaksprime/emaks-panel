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
        $paymentCreateOutcome = is_array($payload['payment_create_outcome'] ?? null)
            ? $payload['payment_create_outcome']
            : [];
        $receiptClaim = is_array($payload['payment_receipt_notification_claim'] ?? null)
            ? $payload['payment_receipt_notification_claim']
            : [];
        $mikroSimulation = is_array($payload['mikro_order_simulation'] ?? null)
            ? $payload['mikro_order_simulation']
            : null;
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
        $storedPaymentUrl = trim((string) ($payment->payment_url ?? ''));
        $paymentUrl = $storedPaymentUrl;
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
        $paymentCreateState = trim((string) ($paymentCreateOutcome['state'] ?? ''));
        $providerCreateActionable = $paymentCreateState === '' || $paymentCreateState === 'provider_success_attached';
        $operationsReviewRequired = (bool) ($paymentCreateOutcome['operations_review_required'] ?? false);
        $retryCreateAllowed = (bool) ($paymentCreateOutcome['retry_allowed'] ?? false) && ! $operationsReviewRequired;
        $paymentCreateMessage = match ($paymentCreateState) {
            'provider_rejected' => 'Iyzico Sandbox ödeme bağlantısı hazırlanamadı.',
            'provider_success_url_invalid', 'provider_effect_ambiguous' => 'Sağlayıcı bağlantıyı oluşturdu ancak Panel kaydı kesinleştirilemedi. Yeni işlem başlatmadan önce operasyon kontrolü gerekir.',
            'provider_success_attached' => 'Ödeme bağlantısı hazır.',
            default => null,
        };
        $syncWaiting = $isIyzicoProvider && $isPending && $payment->provider_reference !== null;
        $canCopy = $providerCreateActionable && $paymentUrl !== '';
        $canFakeComplete = $isFakeProvider && $isPending && $fakeApproveUrl !== null;
        $canOpenProviderUrl = ! $isFakeProvider && $isPending && $providerCreateActionable && $paymentUrl !== '';
        $canOpen = $isPending && $providerCreateActionable && $paymentUrl !== '';
        $canCopyPending = $isPending && $providerCreateActionable && $paymentUrl !== '';
        $canSend = $isPending && $providerCreateActionable && $paymentUrl !== '' && (float) $payment->amount > 0;
        $canCheck = $isPending && $providerCreateActionable && $isIyzicoProvider && trim((string) $payment->provider_reference) !== '';
        $canCancel = $isPending && $providerCreateActionable;
        $actionKind = 'none';
        $actionLabel = null;
        $disabledReason = null;
        $copyDisabledReason = $canCopy
            ? null
            : ($storedPaymentUrl === '' ? 'Ödeme bağlantısı bu kayıt için bulunamadı.' : 'Kayıtlı ödeme linki güvenli biçimde çözümlenemedi.');
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
            $disabledReason = $storedPaymentUrl === ''
                ? 'Ödeme bağlantısı bu kayıt için bulunamadı.'
                : 'Kayıtlı ödeme linki güvenli biçimde çözümlenemedi.';
        } elseif ($isFakeProvider) {
            $disabledReason = 'Fake ödeme simülasyonu bu ekranda kapalı.';
        }

        return [
            'canonical_url' => $paymentUrl !== '' ? $paymentUrl : null,
            'status' => $payment->status,
            'payment_url' => $paymentUrl !== '' ? $paymentUrl : null,
            'copy_url' => $paymentUrl !== '' ? $paymentUrl : null,
            'can_open' => $canOpen,
            'can_copy' => $canCopyPending,
            'can_send' => $canSend,
            'can_check' => $canCheck,
            'can_cancel' => $canCancel,
            'disabled_reason' => $disabledReason,
            'provider' => $provider !== '' ? $provider : null,
            'provider_mode' => $providerMode !== '' ? $providerMode : null,
            'provider_transport' => $providerTransport !== '' ? $providerTransport : null,
            'provider_token' => $payment->provider_reference,
            'provider_reference' => $payment->provider_reference,
            'provider_payment_reference' => $payment->provider_payment_reference,
            'provider_transaction_reference' => $payment->provider_transaction_reference,
            'provider_host_reference' => $payload['provider_host_reference'] ?? null,
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
            'can_cancel_payment' => $canCancel,
            'payment_action_kind' => $actionKind,
            'payment_action_label' => $actionLabel,
            'payment_action_disabled_reason' => $disabledReason,
            'copy_disabled_reason' => $copyDisabledReason,
            'public_url_blocker_code' => $publicUrlBlockerCode,
            'payment_create_state' => $paymentCreateState !== '' ? $paymentCreateState : null,
            'payment_create_message' => $paymentCreateMessage,
            'payment_create_retry_allowed' => $retryCreateAllowed,
            'payment_create_operations_review_required' => $operationsReviewRequired,
            'fake_approve_url' => $canFakeComplete ? $fakeApproveUrl : null,
            'receipt_notification_status' => $payment->receipt_notification_status,
            'receipt_notification_error' => $payment->receipt_notification_error,
            'receipt_notification_sent_at' => $payment->receipt_notification_sent_at?->toISOString(),
            'can_retry_receipt_notification' => $isPaid
                && (bool) ($receiptClaim['retry_available'] ?? false),
            'mikro_order_simulation' => $mikroSimulation,
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
