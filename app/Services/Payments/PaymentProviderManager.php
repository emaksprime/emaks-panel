<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Throwable;

class PaymentProviderManager
{
    public function __construct(
        private readonly TechnicalServicePaymentProviderModeResolver $modeResolver,
        private readonly TechnicalServicePaymentProviderTransportResolver $transportResolver,
        private readonly TechnicalServiceMessagingSettingsService $messagingSettings,
    ) {}

    public function createPayment(TechnicalServiceMountPayment $payment): array
    {
        $this->messagingSettings->assertProviderHttpOutsideTransaction();
        $provider = $this->providerName();
        $scopedProvider = $this->scopedProviderName($provider);
        $claim = $this->messagingSettings->claimScopedLocalUatSandboxPaymentEffect(
            $payment,
            TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CREATE,
            $scopedProvider,
        );
        if ($claim['duplicate']) {
            $existing = TechnicalServiceMountPayment::query()
                ->findOrFail((int) ($claim['duplicate_payment_id'] ?? $payment->getKey()));

            return $this->existingPaymentResponse($existing);
        }

        try {
            $payment = $payment->refresh();
            if (is_string($claim['claim_nonce'])) {
                $this->messagingSettings->beginScopedLocalUatEffectDispatch($claim['claim_nonce']);
                $payment = $payment->refresh();
            }
            $this->stampProviderDecision($payment, $provider);
            $this->messagingSettings->assertProviderHttpOutsideTransaction();
            $this->providerForName($scopedProvider)->createPayment($payment->refresh());
            if (is_string($claim['claim_nonce'])) {
                $this->messagingSettings->completeScopedLocalUatEffect($claim['claim_nonce']);
            }

            return $this->existingPaymentResponse($payment->refresh());
        } catch (Throwable $exception) {
            if (is_string($claim['claim_nonce'])) {
                $this->messagingSettings->failScopedLocalUatEffect($claim['claim_nonce'], $exception);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function canonicalPaymentFromCreateResult(array $result): TechnicalServiceMountPayment
    {
        $paymentId = $result['payment_id'] ?? null;
        if (! is_numeric($paymentId) || (int) $paymentId < 1) {
            throw new InvalidArgumentException('Kanonik odeme kaydi create sonucunda bulunamadi.');
        }

        return TechnicalServiceMountPayment::query()->findOrFail((int) $paymentId);
    }

    public function discardFailedCreatePaymentUnlessAudited(TechnicalServiceMountPayment $payment): void
    {
        $fresh = $payment->fresh();
        if (! $fresh instanceof TechnicalServiceMountPayment) {
            return;
        }
        $history = data_get($fresh->raw_payload, 'scoped_local_uat_effect_history', []);
        $auditedScopedFailure = $fresh->status === TechnicalServiceMountPayment::STATUS_FAILED
            && is_array($history)
            && collect($history)->contains(fn (mixed $entry): bool => is_array($entry)
                && (string) ($entry['operation'] ?? '') === TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CREATE
                && (string) ($entry['status'] ?? '') === 'failed');

        if (! $auditedScopedFailure) {
            $fresh->delete();
        }
    }

    public function updatePayment(TechnicalServiceMountPayment $payment): array
    {
        $this->messagingSettings->assertScopedLocalUatUnsupportedPaymentEffect($payment, 'payment_update');
        $this->stampProviderDecision($payment, $this->providerNameForPayment($payment), $this->paymentModeForExistingPayment($payment));

        return $this->providerForPayment($payment->refresh())->updatePayment($payment->refresh());
    }

    public function cancelPayment(TechnicalServiceMountPayment $payment): array
    {
        $this->messagingSettings->assertScopedLocalUatUnsupportedPaymentEffect($payment, 'payment_cancel');
        $this->stampProviderDecision($payment, $this->providerNameForPayment($payment), $this->paymentModeForExistingPayment($payment));

        return $this->providerForPayment($payment->refresh())->cancelPayment($payment->refresh());
    }

    public function syncPayment(TechnicalServiceMountPayment $payment): array
    {
        $this->messagingSettings->assertScopedLocalUatUnsupportedPaymentEffect($payment, 'payment_sync');
        $this->stampProviderDecision($payment, $this->providerNameForPayment($payment), $this->paymentModeForExistingPayment($payment));

        return $this->providerForPayment($payment->refresh())->syncPayment($payment->refresh());
    }

    public function provider(): PaymentProviderInterface
    {
        return $this->providerForName($this->configuredProviderName());
    }

    private function providerForName(string $provider): PaymentProviderInterface
    {
        return match (strtolower(trim($provider))) {
            'fake' => app(FakePaymentProvider::class),
            'fake_payment' => app(FakePaymentProvider::class),
            'iyzico', 'iyzico_sandbox', 'iyzico_live' => app(IyzicoPaymentProvider::class),
            default => throw new InvalidArgumentException('Desteklenmeyen odeme saglayicisi.'),
        };
    }

    public function providerName(): string
    {
        return $this->modeResolver->activeProviderName();
    }

    public function environment(): string
    {
        return $this->modeResolver->environment();
    }

    private function configuredProviderName(): string
    {
        return $this->modeResolver->activeProviderName();
    }

    private function providerNameForPayment(TechnicalServiceMountPayment $payment): string
    {
        return strtolower((string) ($payment->provider ?: $this->configuredProviderName()));
    }

    private function scopedProviderName(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'fake', 'fake_payment' => 'fake_payment',
            'iyzico_sandbox' => 'iyzico_sandbox',
            'iyzico_live' => 'iyzico_live',
            'iyzico' => match ($this->modeResolver->gatewayMode()) {
                'sandbox' => 'iyzico_sandbox',
                'live' => 'iyzico_live',
                default => throw new InvalidArgumentException('Desteklenmeyen odeme saglayicisi modu.'),
            },
            default => throw new InvalidArgumentException('Desteklenmeyen odeme saglayicisi.'),
        };
    }

    /**
     * @return array{payment_id:int,provider_reference:string|null,payment_url:string|null,status:string}
     */
    private function existingPaymentResponse(TechnicalServiceMountPayment $payment): array
    {
        return [
            'payment_id' => (int) $payment->getKey(),
            'provider_reference' => $payment->provider_reference,
            'payment_url' => $payment->payment_url,
            'status' => (string) $payment->status,
        ];
    }

    private function providerForPayment(TechnicalServiceMountPayment $payment): PaymentProviderInterface
    {
        return match ($this->providerNameForPayment($payment)) {
            'fake' => app(FakePaymentProvider::class),
            'iyzico', 'iyzico_sandbox', 'iyzico_live' => app(IyzicoPaymentProvider::class),
            default => throw new InvalidArgumentException('Desteklenmeyen odeme saglayicisi.'),
        };
    }

    private function stampProviderDecision(TechnicalServiceMountPayment $payment, string $provider, ?string $providerMode = null): void
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $provider = str_starts_with($provider, 'iyzico') ? 'iyzico' : $provider;
        $transport = $provider === 'fake'
            ? 'fake_local'
            : $this->transportResolver->activeTransport();
        $providerMode = $provider === 'fake'
            ? 'local'
            : ($providerMode ?? $this->modeResolver->gatewayMode());

        $payload['provider_decision'] = [
            'provider' => $provider,
            'provider_mode' => $providerMode,
            'provider_transport' => $transport,
            'environment' => $this->environment(),
            'real_provider_enabled' => $this->modeResolver->realProviderEnabled(),
            'decided_at' => now()->toIso8601String(),
        ];
        $payload['provider_mode'] = $payload['provider_decision']['provider_mode'];
        $payload['provider_transport'] = $transport;
        $payload['provider_environment'] = $this->environment();

        $payment->forceFill([
            'provider' => $provider,
            'raw_payload' => $payload,
        ])->save();
    }

    private function paymentModeForExistingPayment(TechnicalServiceMountPayment $payment): ?string
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $mode = Arr::get($payload, 'provider_mode')
            ?? Arr::get($payload, 'provider_decision.provider_mode')
            ?? Arr::get($payload, 'provider_gateway.mode')
            ?? Arr::get($payload, 'provider_gateway.provider_mode');

        if ($mode === null || $mode === '') {
            return null;
        }

        return strtolower((string) $mode) === 'live' ? 'live' : 'sandbox';
    }
}
