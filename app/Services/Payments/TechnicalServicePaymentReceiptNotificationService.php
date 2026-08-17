<?php

namespace App\Services\Payments;

use App\Mail\TechnicalServicePaymentAuditMail;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class TechnicalServicePaymentReceiptNotificationService
{
    public const RECEIPT_EVENT = 'payment_receipt_notification';

    public const RECEIPT_CHANNEL = 'email';

    public const RECEIPT_PROVIDER = 'smtp_payment_receipt';

    public function __construct(
        private readonly TechnicalServicePaymentProviderSettingsService $settings,
        private readonly TechnicalServiceMailTransportSettingsService $mailTransportSettings,
    ) {}

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    public function notifyTrustedPaid(
        TechnicalServiceMountPayment $payment,
        array $providerResponse = [],
        bool $retryFailed = false,
    ): TechnicalServiceMountPayment {
        $intent = DB::transaction(function () use ($payment, $retryFailed): ?TechnicalServiceMessageDispatch {
            $locked = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return $this->persistPaidReceiptIntentWithinTransaction($locked, $retryFailed);
        });
        if (! $intent instanceof TechnicalServiceMessageDispatch) {
            return $payment->fresh() ?? $payment;
        }

        return $this->processReceiptIntent($intent, $providerResponse);
    }

    /** @param array<string, mixed> $providerResponse */
    public function retryFailedReceipt(
        TechnicalServiceMountPayment $payment,
        array $providerResponse = [],
    ): TechnicalServiceMountPayment {
        return $this->notifyTrustedPaid($payment, $providerResponse, true);
    }

    /**
     * The payment row is the serialization lock. Settlement calls this while
     * its paid-state transaction is still open, so PAID and durable intent
     * commit together.
     */
    public function persistPaidReceiptIntentWithinTransaction(
        TechnicalServiceMountPayment $payment,
        bool $retryFailed = false,
    ): ?TechnicalServiceMessageDispatch {
        if (DB::transactionLevel() < 1) {
            throw new ConflictHttpException('payment_receipt_intent_requires_transaction: Receipt intent paid transition transactionı içinde yazılmalıdır.');
        }
        if ($payment->status !== TechnicalServiceMountPayment::STATUS_PAID) {
            return null;
        }
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $identity = $this->receiptBusinessIdentity($payment);
        $identityHash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $idempotencyKey = 'receipt:'.$identityHash;
        $dispatch = TechnicalServiceMessageDispatch::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();
        if (! $dispatch instanceof TechnicalServiceMessageDispatch) {
            if (! $this->settings->paymentNotificationEnabled()) {
                return null;
            }
            $recipients = $this->normalizedReceiptRecipients($this->settings->paymentNotificationRecipients());
            $recipientMissing = $recipients === [];
            $recipientFingerprints = $this->recipientFingerprints($recipients);
            $dispatch = TechnicalServiceMessageDispatch::query()->create([
                'event' => self::RECEIPT_EVENT,
                'technical_service_request_id' => $payment->technical_service_request_id,
                'request_id' => $payment->technical_service_request_id,
                'related_type' => TechnicalServiceMountPayment::class,
                'related_id' => (int) $payment->getKey(),
                'message_type' => self::RECEIPT_EVENT,
                'channel' => self::RECEIPT_CHANNEL,
                'provider_key' => self::RECEIPT_PROVIDER,
                'recipient_role' => 'payment_notification',
                'template_key' => TechnicalServicePaymentAuditMail::class,
                'template_version' => 1,
                'payload_hash' => $identityHash,
                'idempotency_key' => $idempotencyKey,
                'channel_policy' => 'single_claim_explicit_retry',
                'test_mode' => ! app()->environment('production'),
                'status' => $recipientMissing
                    ? TechnicalServiceMessageDispatch::STATUS_NOT_CONFIGURED
                    : TechnicalServiceMessageDispatch::STATUS_QUEUED,
                'attempt_count' => 0,
                'max_attempts' => $recipientMissing ? 0 : 2,
                'queued_at' => $recipientMissing ? null : now(),
                'failed_at' => $recipientMissing ? now() : null,
                'last_error_code' => $recipientMissing ? 'recipient_not_configured' : null,
                'last_error_message_redacted' => $recipientMissing ? 'Muhasebe e-posta alıcısı yapılandırılmadı.' : null,
                'triggered_by' => 'payment_paid_transaction',
                'metadata' => [
                    'schema_version' => 2,
                    'payment_id' => (int) $payment->getKey(),
                    'recipient_fingerprints' => $recipientFingerprints,
                    'amount_minor' => $identity['amount_minor'],
                    'currency' => $identity['currency'],
                    'payment_purpose_fingerprint' => hash('sha256', $identity['payment_purpose']),
                    'paid_transition_fingerprint' => $identity['paid_transition_fingerprint'],
                    'automatic_retry_allowed' => false,
                    'recipient_not_configured' => $recipientMissing,
                ],
                'request_payload' => [
                    'event' => self::RECEIPT_EVENT,
                    'payment_fingerprint' => hash('sha256', (string) $payment->getKey()),
                    'recipient_emails' => $recipients,
                ],
            ]);
        } else {
            $this->assertReceiptDispatchAuthority($dispatch, $payment, $identityHash);
            $recipientMissing = (bool) data_get($dispatch->metadata, 'recipient_not_configured', false);
            $recipients = $recipientMissing ? [] : $this->storedReceiptRecipients($dispatch);
            $recipientFingerprints = $this->recipientFingerprints($recipients);
            if ($retryFailed) {
                $dispatch = $this->requeueFailedReceiptWithinTransaction($dispatch, $payment);
            }
        }

        $payload['payment_receipt_notification_claim'] = [
            'schema_version' => 2,
            'status' => $this->paymentReceiptStatus($dispatch),
            'idempotency_hash' => $identityHash,
            'dispatch_id' => (int) $dispatch->getKey(),
            'recipient_fingerprints' => $recipientFingerprints,
            'automatic_retry_allowed' => false,
            'retry_available' => $this->receiptRetryAvailable($dispatch),
        ];
        $payment->forceFill([
            'raw_payload' => $payload,
            'receipt_notification_to' => implode(',', $recipients),
            'receipt_notification_status' => $this->paymentReceiptStatus($dispatch),
            'receipt_notification_error' => $dispatch->last_error_message_redacted,
        ])->save();

        return $dispatch;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function processReceiptIntent(
        TechnicalServiceMessageDispatch $intent,
        array $providerResponse,
    ): TechnicalServiceMountPayment {
        $claim = $this->claimReceiptDispatch($intent);
        if ($claim === null) {
            $payment = TechnicalServiceMountPayment::query()->findOrFail((int) $intent->related_id);

            return $payment->fresh();
        }
        $payment = $claim['payment'];
        $recipients = $claim['recipients'];

        try {
            $this->mailTransportSettings->sendPaymentAuditMail(
                $recipients,
                new TechnicalServicePaymentAuditMail($payment, $this->mailDetails($payment, $providerResponse)),
            );

            return $this->finishReceiptDispatch($claim['dispatch'], TechnicalServiceMessageDispatch::STATUS_SENT, null, $recipients);
        } catch (TechnicalServiceMailTransportNotReadyException $exception) {
            return $this->finishReceiptDispatch(
                $claim['dispatch'],
                TechnicalServiceMessageDispatch::STATUS_NOT_CONFIGURED,
                $this->redactedError($exception),
                $recipients,
            );
        } catch (Throwable $exception) {
            return $this->finishReceiptDispatch(
                $claim['dispatch'],
                TechnicalServiceMessageDispatch::STATUS_FAILED,
                $this->redactedError($exception),
                $recipients,
            );
        }
    }

    /** @return array{dispatch:TechnicalServiceMessageDispatch,payment:TechnicalServiceMountPayment,recipients:array<int,string>}|null */
    private function claimReceiptDispatch(TechnicalServiceMessageDispatch $intent): ?array
    {
        return DB::transaction(function () use ($intent): ?array {
            $payment = TechnicalServiceMountPayment::query()
                ->whereKey((int) $intent->related_id)
                ->lockForUpdate()
                ->firstOrFail();
            $dispatch = TechnicalServiceMessageDispatch::query()
                ->whereKey($intent->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($dispatch->status !== TechnicalServiceMessageDispatch::STATUS_QUEUED
                || (int) $dispatch->attempt_count >= (int) $dispatch->max_attempts) {
                return null;
            }
            if ($dispatch->related_type !== TechnicalServiceMountPayment::class
                || (int) $dispatch->related_id !== (int) $payment->getKey()
                || $payment->status !== TechnicalServiceMountPayment::STATUS_PAID) {
                throw new ConflictHttpException('payment_receipt_dispatch_authority_invalid: Dispatch paid canonical payment ile bağlı değil.');
            }
            $identity = $this->receiptBusinessIdentity($payment);
            $identityHash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $this->assertReceiptDispatchAuthority($dispatch, $payment, $identityHash);
            $recipients = $this->storedReceiptRecipients($dispatch);

            $dispatch->forceFill([
                'status' => TechnicalServiceMessageDispatch::STATUS_SENDING,
                'attempt_count' => (int) $dispatch->attempt_count + 1,
                'sending_started_at' => now(),
                'provider_status' => 'dispatching',
                'last_error_code' => null,
                'last_error_message_redacted' => null,
            ])->save();
            $payment->forceFill([
                'raw_payload' => array_replace(is_array($payment->raw_payload) ? $payment->raw_payload : [], [
                    'payment_receipt_notification_claim' => array_replace(
                        (array) data_get($payment->raw_payload, 'payment_receipt_notification_claim', []),
                        ['status' => 'sending', 'claimed_at' => now()->toIso8601String()],
                    ),
                ]),
                'receipt_notification_status' => 'sending',
                'receipt_notification_error' => null,
            ])->save();

            return [
                'dispatch' => $dispatch->fresh(),
                'payment' => $payment->fresh(),
                'recipients' => $recipients,
            ];
        });
    }

    /** @param array<int, string> $recipients */
    private function finishReceiptDispatch(
        TechnicalServiceMessageDispatch $intent,
        string $status,
        ?string $error,
        array $recipients,
    ): TechnicalServiceMountPayment {
        return DB::transaction(function () use ($intent, $status, $error, $recipients): TechnicalServiceMountPayment {
            $payment = TechnicalServiceMountPayment::query()
                ->whereKey((int) $intent->related_id)
                ->lockForUpdate()
                ->firstOrFail();
            $dispatch = TechnicalServiceMessageDispatch::query()
                ->whereKey($intent->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($dispatch->status !== TechnicalServiceMessageDispatch::STATUS_SENDING) {
                throw new ConflictHttpException('payment_receipt_dispatch_claim_mismatch: SMTP sonucu current dispatch claim ile eşleşmiyor.');
            }
            if ($dispatch->related_type !== TechnicalServiceMountPayment::class
                || (int) $dispatch->related_id !== (int) $payment->getKey()) {
                throw new ConflictHttpException('payment_receipt_dispatch_authority_invalid: Dispatch canonical payment bağı değişti.');
            }
            $dispatch->forceFill([
                'status' => $status,
                'sent_at' => $status === TechnicalServiceMessageDispatch::STATUS_SENT ? now() : null,
                'failed_at' => $status === TechnicalServiceMessageDispatch::STATUS_SENT ? null : now(),
                'provider_status' => $status === TechnicalServiceMessageDispatch::STATUS_SENT ? 'accepted' : $status,
                'last_error_code' => $status === TechnicalServiceMessageDispatch::STATUS_SENT ? null : $status,
                'last_error_message_redacted' => $error,
                'provider_response_redacted' => ['status' => $status],
            ])->save();
            $paymentStatus = $status === TechnicalServiceMessageDispatch::STATUS_NOT_CONFIGURED
                ? 'mailer_not_configured'
                : $status;
            $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
            $payload['payment_receipt_notification_claim'] = array_replace(
                (array) ($payload['payment_receipt_notification_claim'] ?? []),
                [
                    'status' => $paymentStatus,
                    'completed_at' => now()->toIso8601String(),
                    'retry_available' => $this->receiptRetryAvailable($dispatch),
                ],
            );
            $payment->forceFill([
                'raw_payload' => $payload,
                'receipt_notification_sent_at' => $status === TechnicalServiceMessageDispatch::STATUS_SENT ? now() : null,
                'receipt_notification_to' => implode(',', $recipients),
                'receipt_notification_status' => $paymentStatus,
                'receipt_notification_error' => $error,
            ])->save();

            $event = match ($status) {
                TechnicalServiceMessageDispatch::STATUS_SENT => ['payment_receipt_notification_sent', 'Ödeme bildirimi maili gönderildi'],
                TechnicalServiceMessageDispatch::STATUS_NOT_CONFIGURED => ['payment_receipt_notification_blocked', 'Ödeme bildirimi maili gönderilemedi'],
                default => ['payment_receipt_notification_failed', 'Ödeme bildirimi maili gönderilemedi'],
            };
            $metadata = [
                'recipients' => array_map(fn (string $recipient): string => $this->maskEmail($recipient), $recipients),
            ];
            if ($error !== null) {
                $metadata['error'] = $error;
            }
            $this->recordEvent($payment, $event[0], $event[1], $metadata);

            return $payment->fresh();
        });
    }

    private function amountMinorUnits(TechnicalServiceMountPayment $payment): string
    {
        $amount = trim((string) ($payment->getRawOriginal('amount') ?? $payment->amount));
        if (! preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/', $amount, $matches)) {
            throw new ConflictHttpException('payment_receipt_amount_invalid: Receipt identity canonical decimal amount gerektirir.');
        }

        return ltrim($matches[1].str_pad((string) ($matches[2] ?? ''), 2, '0'), '0') ?: '0';
    }

    /** @return array<string, mixed> */
    private function receiptBusinessIdentity(TechnicalServiceMountPayment $payment): array
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

        return [
            'schema_version' => 2,
            'payment_id' => (int) $payment->getKey(),
            'payment_purpose' => strtolower(trim((string) ($payload['purpose'] ?? $payload['charge_type'] ?? $payload['source'] ?? 'payment'))),
            'event' => self::RECEIPT_EVENT,
            'amount_minor' => $this->amountMinorUnits($payment),
            'currency' => strtoupper((string) $payment->currency),
            'paid_transition_fingerprint' => hash('sha256', implode('|', [
                (string) ($payment->paid_at?->toIso8601String() ?? ''),
                (string) $payment->provider,
                (string) $payment->provider_reference,
            ])),
        ];
    }

    /** @param array<int, mixed> $recipients */
    private function normalizedReceiptRecipients(array $recipients): array
    {
        $normalized = [];
        foreach ($recipients as $recipient) {
            if (! is_string($recipient)) {
                throw new ConflictHttpException('payment_receipt_recipient_invalid: Receipt recipient snapshot geçerli e-posta gerektirir.');
            }
            $recipient = trim($recipient);
            if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new ConflictHttpException('payment_receipt_recipient_invalid: Receipt recipient snapshot geçerli e-posta gerektirir.');
            }
            $normalized[strtolower($recipient)] = $recipient;
        }
        ksort($normalized);

        return array_values($normalized);
    }

    /** @param array<int, string> $recipients */
    private function recipientFingerprints(array $recipients): array
    {
        $fingerprints = array_map(
            fn (string $recipient): string => hash('sha256', strtolower(trim($recipient))),
            $recipients,
        );
        sort($fingerprints);

        return array_values(array_unique($fingerprints));
    }

    /** @return array<int, string> */
    private function storedReceiptRecipients(TechnicalServiceMessageDispatch $dispatch): array
    {
        $stored = data_get($dispatch->request_payload, 'recipient_emails');
        if (! is_array($stored) || ! array_is_list($stored)) {
            throw new ConflictHttpException('payment_receipt_recipient_snapshot_missing: Receipt dispatch immutable recipient snapshot taşımıyor.');
        }
        $recipients = $this->normalizedReceiptRecipients($stored);
        $storedFingerprints = collect((array) data_get($dispatch->metadata, 'recipient_fingerprints', []))
            ->map(fn (mixed $fingerprint): string => (string) $fingerprint)
            ->sort()
            ->values()
            ->all();
        if ($recipients === [] || $this->recipientFingerprints($recipients) !== $storedFingerprints) {
            throw new ConflictHttpException('payment_receipt_recipient_snapshot_invalid: Receipt recipient snapshot fingerprint bağı geçersiz.');
        }

        return $recipients;
    }

    private function assertReceiptDispatchAuthority(
        TechnicalServiceMessageDispatch $dispatch,
        TechnicalServiceMountPayment $payment,
        string $identityHash,
    ): void {
        if ($dispatch->event !== self::RECEIPT_EVENT
            || $dispatch->message_type !== self::RECEIPT_EVENT
            || $dispatch->channel !== self::RECEIPT_CHANNEL
            || $dispatch->provider_key !== self::RECEIPT_PROVIDER
            || $dispatch->related_type !== TechnicalServiceMountPayment::class
            || (int) $dispatch->related_id !== (int) $payment->getKey()
            || ! hash_equals((string) $dispatch->payload_hash, $identityHash)
            || ! hash_equals((string) $dispatch->idempotency_key, 'receipt:'.$identityHash)) {
            throw new ConflictHttpException('payment_receipt_dispatch_authority_invalid: Receipt dispatch canonical paid transition authority ile eşleşmiyor.');
        }
    }

    private function paymentReceiptStatus(TechnicalServiceMessageDispatch $dispatch): string
    {
        if ((bool) data_get($dispatch->metadata, 'recipient_not_configured', false)) {
            return 'recipient_not_configured';
        }

        return match ($dispatch->status) {
            TechnicalServiceMessageDispatch::STATUS_QUEUED => 'queued',
            TechnicalServiceMessageDispatch::STATUS_SENDING => 'provider_unknown',
            TechnicalServiceMessageDispatch::STATUS_NOT_CONFIGURED => 'mailer_not_configured',
            default => (string) $dispatch->status,
        };
    }

    private function receiptRetryAvailable(TechnicalServiceMessageDispatch $dispatch): bool
    {
        if ((bool) data_get($dispatch->metadata, 'recipient_not_configured', false)) {
            return false;
        }

        return in_array($dispatch->status, [
            TechnicalServiceMessageDispatch::STATUS_FAILED,
            TechnicalServiceMessageDispatch::STATUS_NOT_CONFIGURED,
        ], true)
            && (int) $dispatch->attempt_count < (int) $dispatch->max_attempts;
    }

    private function requeueFailedReceiptWithinTransaction(
        TechnicalServiceMessageDispatch $dispatch,
        TechnicalServiceMountPayment $payment,
    ): TechnicalServiceMessageDispatch {
        if ($payment->status !== TechnicalServiceMountPayment::STATUS_PAID) {
            throw new ConflictHttpException('payment_receipt_retry_requires_paid: Yalnız ödenmiş kaydın muhasebe maili yeniden denenebilir.');
        }
        if (! $this->receiptRetryAvailable($dispatch)) {
            throw new ConflictHttpException('payment_receipt_retry_not_available: Muhasebe maili yeniden denemeye uygun değil.');
        }

        $metadata = is_array($dispatch->metadata) ? $dispatch->metadata : [];
        $metadata['manual_retry_requested_at'] = now()->toIso8601String();
        $metadata['manual_retry_count'] = max(0, (int) ($metadata['manual_retry_count'] ?? 0)) + 1;

        $dispatch->forceFill([
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
            'queued_at' => now(),
            'sending_started_at' => null,
            'failed_at' => null,
            'provider_status' => 'manual_retry_queued',
            'last_error_code' => null,
            'last_error_message_redacted' => null,
            'metadata' => $metadata,
        ])->save();

        return $dispatch->fresh();
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     * @return array<string, mixed>
     */
    public function mailDetails(TechnicalServiceMountPayment $payment, array $providerResponse = []): array
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $request = $payment->technicalServiceRequest;
        $orderContext = is_array($payload['order_context'] ?? null) ? $payload['order_context'] : [];
        $simulation = is_array($payload['mikro_order_simulation'] ?? null) ? $payload['mikro_order_simulation'] : [];
        $billing = is_array($orderContext['billing'] ?? null) ? $orderContext['billing'] : [];
        $shipping = is_array($orderContext['shipping'] ?? null) ? $orderContext['shipping'] : [];

        return [
            'mrn' => $this->stringValue(Arr::get($payload, 'request_code')) ?: $request?->mrn,
            'root_mrn' => $this->stringValue(Arr::get($payload, 'root_mrn')) ?: $request?->root_mrn,
            'srv' => $this->stringValue(Arr::get($payload, 'service_code')),
            'serial_no' => $this->stringValue(Arr::get($payload, 'serial_number')) ?: $request?->serial_number,
            'related_product_serial' => $this->stringValue($orderContext['related_product_serial'] ?? null),
            'customer_name' => $this->stringValue(Arr::get($payload, 'customer_name')) ?: $request?->customer_name,
            'customer_phone' => $this->stringValue(Arr::get($payload, 'customer_phone')) ?: $request?->customer_phone,
            'amount' => number_format((float) $payment->amount, 2, ',', '.'),
            'currency' => strtoupper((string) ($payment->currency ?: 'TRY')),
            'paid_at' => ($payment->paid_at ?? now())->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            'provider' => ucfirst((string) ($payment->provider ?: 'iyzico')),
            'provider_mode' => $this->providerMode($payload),
            'provider_reference' => $this->maskedReference($payment->provider_reference),
            'provider_payment_reference' => $payment->provider_payment_reference,
            'provider_transaction_reference' => $payment->provider_transaction_reference,
            'provider_host_reference' => $this->stringValue($payload['provider_host_reference'] ?? null),
            'provider_receipt_reference' => $payment->provider_receipt_reference,
            'provider_status' => $this->providerStatus($payment, $providerResponse),
            'billing' => $this->mailParty($billing, true),
            'shipping' => $this->mailParty($shipping, false),
            'delivery_mode' => $this->deliveryModeLabel($orderContext['delivery_mode'] ?? null),
            'lines' => $this->mailLines($orderContext['lines'] ?? []),
            'gross_total' => $this->moneyLabel($orderContext['gross_total'] ?? $payment->amount),
            'net_total' => $this->moneyLabel($orderContext['net_total'] ?? null),
            'vat_total' => $this->moneyLabel($orderContext['vat_total'] ?? null),
            'desired_series' => $this->stringValue($simulation['desired_series'] ?? $orderContext['desired_mikro_series'] ?? null),
            'simulation_reference' => $this->stringValue($simulation['simulation_reference'] ?? null),
            'simulation_status' => $this->stringValue($simulation['status'] ?? null),
            'context_revision' => is_numeric($simulation['context_revision'] ?? $orderContext['revision'] ?? null)
                ? (int) ($simulation['context_revision'] ?? $orderContext['revision'])
                : null,
            'context_hash' => $this->stringValue($simulation['context_hash'] ?? $orderContext['context_hash'] ?? null),
            'description2' => $this->stringValue($orderContext['description2_preview'] ?? null),
            'mikro_write_attempted' => false,
            'real_mikro_order_number' => null,
            'real_mikro_document_guid' => null,
            'note' => 'Bu bildirim provider reconciliation sonucu oluşturulmuştur.',
        ];
    }

    /** @return array<string, string|null> */
    private function mailParty(array $party, bool $billing): array
    {
        $name = $this->stringValue($party['name_or_title'] ?? null)
            ?: $this->stringValue($party['legal_title'] ?? null)
            ?: trim(implode(' ', array_filter([
                $this->stringValue($party['first_name'] ?? null),
                $this->stringValue($party['last_name'] ?? null),
            ])));

        return [
            'name' => $name !== '' ? $name : ($this->stringValue($party['recipient_name'] ?? null) ?: null),
            'identity' => $billing ? $this->stringValue($party['tax_identity'] ?? null) : null,
            'tax_office' => $billing ? $this->stringValue($party['tax_office'] ?? null) : null,
            'phone' => $this->stringValue($party['phone'] ?? $party['recipient_phone'] ?? null),
            'email' => $this->stringValue($party['email'] ?? null),
            'address' => $this->stringValue($party['address'] ?? null),
            'city' => $this->stringValue($party['city'] ?? null),
            'district' => $this->stringValue($party['district'] ?? null),
            'postal_code' => $this->stringValue($party['postal_code'] ?? null),
        ];
    }

    /** @return array<int, array<string, string|null>> */
    private function mailLines(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }

        return collect($lines)
            ->filter(fn (mixed $line): bool => is_array($line))
            ->map(fn (array $line): array => [
                'item_code' => $this->stringValue($line['item_code'] ?? null),
                'item_name' => $this->stringValue($line['item_name'] ?? null),
                'quantity' => is_numeric($line['quantity'] ?? null) ? $this->decimalLabel($line['quantity']) : null,
                'unit_code' => $this->stringValue($line['unit_code'] ?? null),
                'gross_unit_price' => $this->moneyLabel($line['gross_unit_price'] ?? $line['unit_price'] ?? null),
                'gross_line_total' => $this->moneyLabel($line['gross_line_total'] ?? $line['line_total'] ?? null),
                'vat_rate' => is_numeric($line['selected_tax_rate'] ?? null)
                    ? '%'.$this->decimalLabel($line['selected_tax_rate'])
                    : null,
                'net_line_total' => $this->moneyLabel($line['net_line_total'] ?? null),
                'vat_line_total' => $this->moneyLabel($line['vat_line_total'] ?? null),
            ])
            ->values()
            ->all();
    }

    private function deliveryModeLabel(mixed $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'shipment' => 'Sevk',
            'hand_delivery' => 'Elden teslim',
            'technician_delivery' => 'Usta teslimi',
            default => $this->stringValue($value),
        };
    }

    private function moneyLabel(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, ',', '.').' TL' : null;
    }

    private function decimalLabel(mixed $value): string
    {
        $formatted = number_format((float) $value, 4, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',');
    }

    private function maskedReference(?string $reference): ?string
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }
        if (strlen($reference) <= 10) {
            return substr($reference, 0, 2).'***'.substr($reference, -2);
        }

        return substr($reference, 0, 6).'***'.substr($reference, -4);
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
