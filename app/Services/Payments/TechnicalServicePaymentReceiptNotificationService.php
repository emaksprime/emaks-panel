<?php

namespace App\Services\Payments;

use App\Mail\TechnicalServicePaymentAuditMail;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class TechnicalServicePaymentReceiptNotificationService
{
    public function __construct(
        private readonly TechnicalServicePaymentProviderSettingsService $settings,
        private readonly TechnicalServiceMailTransportSettingsService $mailTransportSettings,
    ) {}

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    public function notifyTrustedPaid(TechnicalServiceMountPayment $payment, array $providerResponse = []): TechnicalServiceMountPayment
    {
        $payment = $payment->fresh();

        if (! $payment instanceof TechnicalServiceMountPayment
            || $payment->status !== TechnicalServiceMountPayment::STATUS_PAID
            || ! $this->settings->paymentNotificationEnabled()) {
            return $payment;
        }

        $recipients = $this->settings->paymentNotificationRecipients();
        if ($recipients === []) {
            return $payment;
        }

        $claim = $this->claimReceiptNotification($payment, $recipients);
        if ($claim === null) {
            return $payment->fresh();
        }

        $payment = $claim['payment'];
        $details = $this->mailDetails($payment, $providerResponse);

        try {
            $this->mailTransportSettings->sendPaymentAuditMail($recipients, new TechnicalServicePaymentAuditMail($payment, $details));
            $payment = $this->finishReceiptNotification(
                $payment,
                $claim['idempotency_hash'],
                'sent',
                null,
                $recipients,
            );
        } catch (TechnicalServiceMailTransportNotReadyException $exception) {
            $payment = $this->finishReceiptNotification(
                $payment,
                $claim['idempotency_hash'],
                'mailer_not_configured',
                $this->redactedError($exception),
                $recipients,
            );
        } catch (Throwable $exception) {
            $payment = $this->finishReceiptNotification(
                $payment,
                $claim['idempotency_hash'],
                'failed',
                $this->redactedError($exception),
                $recipients,
            );
        }

        return $payment->fresh();
    }

    /**
     * @param  array<int, string>  $recipients
     * @return array{payment:TechnicalServiceMountPayment,idempotency_hash:string}|null
     */
    private function claimReceiptNotification(TechnicalServiceMountPayment $payment, array $recipients): ?array
    {
        return DB::transaction(function () use ($payment, $recipients): ?array {
            $locked = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== TechnicalServiceMountPayment::STATUS_PAID
                || $locked->receipt_notification_sent_at !== null
                || $locked->receipt_notification_status !== null) {
                return null;
            }

            $payload = is_array($locked->raw_payload) ? $locked->raw_payload : [];
            $identity = [
                'payment_id' => (int) $locked->getKey(),
                'payment_purpose' => strtolower(trim((string) ($payload['purpose'] ?? $payload['charge_type'] ?? $payload['source'] ?? 'payment'))),
                'event' => 'payment_receipt_notification',
                'recipient_fingerprints' => collect($recipients)
                    ->map(fn (string $recipient): string => hash('sha256', strtolower(trim($recipient))))
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
                'paid_at' => $locked->paid_at?->toIso8601String(),
                'provider_reference_fingerprint' => hash('sha256', trim((string) $locked->provider_reference)),
            ];
            $idempotencyHash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $payload['payment_receipt_notification_claim'] = [
                'schema_version' => 1,
                'status' => 'claimed',
                'idempotency_hash' => $idempotencyHash,
                'recipient_fingerprints' => $identity['recipient_fingerprints'],
                'claimed_at' => now()->toIso8601String(),
            ];
            $locked->forceFill([
                'raw_payload' => $payload,
                'receipt_notification_to' => implode(',', $recipients),
                'receipt_notification_status' => 'claimed',
                'receipt_notification_error' => null,
            ])->save();

            return [
                'payment' => $locked->fresh(),
                'idempotency_hash' => $idempotencyHash,
            ];
        });
    }

    /** @param array<int, string> $recipients */
    private function finishReceiptNotification(
        TechnicalServiceMountPayment $payment,
        string $idempotencyHash,
        string $status,
        ?string $error,
        array $recipients,
    ): TechnicalServiceMountPayment {
        return DB::transaction(function () use ($payment, $idempotencyHash, $status, $error, $recipients): TechnicalServiceMountPayment {
            $locked = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $payload = is_array($locked->raw_payload) ? $locked->raw_payload : [];
            $claim = is_array($payload['payment_receipt_notification_claim'] ?? null)
                ? $payload['payment_receipt_notification_claim']
                : [];
            if ($locked->receipt_notification_status !== 'claimed'
                || ! hash_equals((string) ($claim['idempotency_hash'] ?? ''), $idempotencyHash)) {
                throw new ConflictHttpException('payment_receipt_notification_claim_mismatch: Receipt mail sonucu current atomic claim ile eşleşmiyor.');
            }

            $claim['status'] = $status;
            $claim['completed_at'] = now()->toIso8601String();
            $payload['payment_receipt_notification_claim'] = $claim;
            $locked->forceFill([
                'raw_payload' => $payload,
                'receipt_notification_sent_at' => $status === 'sent' ? now() : null,
                'receipt_notification_to' => implode(',', $recipients),
                'receipt_notification_status' => $status,
                'receipt_notification_error' => $error,
            ])->save();

            $event = match ($status) {
                'sent' => ['payment_receipt_notification_sent', 'Ödeme bildirimi maili gönderildi'],
                'mailer_not_configured' => ['payment_receipt_notification_blocked', 'Ödeme bildirimi maili gönderilemedi'],
                default => ['payment_receipt_notification_failed', 'Ödeme bildirimi maili gönderilemedi'],
            };
            $metadata = [
                'recipients' => array_map(fn (string $recipient): string => $this->maskEmail($recipient), $recipients),
            ];
            if ($error !== null) {
                $metadata['error'] = $error;
            }
            $this->recordEvent($locked, $event[0], $event[1], $metadata);

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     * @return array<string, mixed>
     */
    public function mailDetails(TechnicalServiceMountPayment $payment, array $providerResponse = []): array
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $request = $payment->technicalServiceRequest;

        return [
            'mrn' => $this->stringValue(Arr::get($payload, 'request_code')) ?: $request?->mrn,
            'root_mrn' => $this->stringValue(Arr::get($payload, 'root_mrn')) ?: $request?->root_mrn,
            'serial_no' => $this->stringValue(Arr::get($payload, 'serial_number')) ?: $request?->serial_number,
            'customer_name' => $this->stringValue(Arr::get($payload, 'customer_name')) ?: $request?->customer_name,
            'customer_phone' => $this->stringValue(Arr::get($payload, 'customer_phone')) ?: $request?->customer_phone,
            'amount' => number_format((float) $payment->amount, 2, ',', '.'),
            'currency' => strtoupper((string) ($payment->currency ?: 'TRY')),
            'paid_at' => ($payment->paid_at ?? now())->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            'provider' => ucfirst((string) ($payment->provider ?: 'iyzico')),
            'provider_mode' => $this->providerMode($payload),
            'provider_reference' => $payment->provider_reference,
            'provider_payment_reference' => $payment->provider_payment_reference,
            'provider_transaction_reference' => $payment->provider_transaction_reference,
            'provider_receipt_reference' => $payment->provider_receipt_reference,
            'provider_status' => $this->providerStatus($payment, $providerResponse),
            'note' => 'Bu bildirim provider reconciliation sonucu oluşturulmuştur.',
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordEvent(TechnicalServiceMountPayment $payment, string $eventType, string $title, array $metadata): void
    {
        $request = $payment->technicalServiceRequest;
        if (! $request instanceof TechnicalServiceRequest) {
            return;
        }

        $request->events()->create([
            'event_type' => $eventType,
            'title' => $title,
            'note' => 'Ödeme bildirimi mail ayarlarına göre işlendi.',
            'from_status' => $request->workflow_status,
            'to_status' => $request->workflow_status,
            'author_user_id' => null,
            'metadata' => array_merge([
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'provider_reference' => $payment->provider_reference,
                'provider_payment_reference' => $payment->provider_payment_reference,
                'provider_transaction_reference' => $payment->provider_transaction_reference,
                'provider_receipt_reference' => $payment->provider_receipt_reference,
            ], $metadata),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerMode(array $payload): string
    {
        $mode = $this->stringValue(Arr::get($payload, 'provider_mode'))
            ?: $this->stringValue(Arr::get($payload, 'provider_decision.provider_mode'))
            ?: $this->stringValue(Arr::get($payload, 'provider_environment'))
            ?: $this->settings->providerMode();

        return strtolower($mode) === 'live' ? 'Live' : (strtolower($mode) === 'local' ? 'Local' : 'Sandbox');
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function providerStatus(TechnicalServiceMountPayment $payment, array $providerResponse): ?string
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

        return $this->stringValue($providerResponse['provider_status'] ?? null)
            ?: $this->stringValue(Arr::get($payload, 'provider_reconciliation.provider_status'));
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($local === '' || $domain === '') {
            return '[invalid-email]';
        }

        return substr($local, 0, 1).'***@'.$domain;
    }

    private function redactedError(Throwable $exception): string
    {
        $message = PaymentProviderGatewayResponse::redactProviderResponse([
            'error' => $this->redactSecretLikeText($exception->getMessage()),
        ])['error'] ?? 'Mail gönderimi başarısız.';

        return is_scalar($message) && trim((string) $message) !== ''
            ? trim((string) $message)
            : 'Mail gönderimi başarısız.';
    }

    private function redactSecretLikeText(string $message): string
    {
        $redacted = preg_replace(
            '/\b(password|secret|api[_\s-]?key|auth(?:orization)?|signature|x-panel[_-]?token|gateway[_\s-]?token)\s*[:=]\s*[^,\s;]+/i',
            '$1=[redacted]',
            $message,
        );

        $iyzwsScheme = 'IYZWS'.'v2';
        $redacted = preg_replace('/'.preg_quote($iyzwsScheme, '/').'\s+[A-Za-z0-9+\/=._-]+/i', $iyzwsScheme.' [redacted]', (string) $redacted);

        return is_string($redacted) ? $redacted : 'Mail gönderimi başarısız.';
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
