<?php

namespace Tests\Feature;

use App\Mail\TechnicalServicePaymentAuditMail;
use App\Models\MailTransportProfile;
use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Payments\TechnicalServiceMailTransportSettingsService;
use App\Services\Payments\TechnicalServicePaymentProviderReconciliationService;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use App\Services\Payments\TechnicalServicePaymentReceiptNotificationService;
use App\Services\TechnicalService\TechnicalServicePaymentOwnershipService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PaymentProviderReconciliationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_sync_status_payload_contains_payment_mrn_serial_customer_and_excludes_secret(): void
    {
        $request = $this->technicalServiceRequest();
        $payment = $this->mountPaymentForRequest($request, [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'technical_service_request_id' => $request->id,
                'request_code' => $request->mrn,
                'root_mrn' => $request->root_mrn ?: $request->mrn,
                'serial_number' => $request->serial_number,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'api_key' => 'api-key-should-not-leak',
                'secret_key' => 'secret-should-not-leak',
                'Authorization' => 'IYZWSv2 should-not-leak',
            ],
        ]);

        $payload = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->buildSyncStatusPayload($payment);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('sync_status', $payload['operation']);
        $this->assertSame((string) $payment->id, $payload['payment_id']);
        $this->assertSame($request->mrn, $payload['request_code']);
        $this->assertSame($request->serial_number, $payload['serial_no']);
        $this->assertSame($request->customer_name, $payload['customer']['name']);
        $this->assertStringContainsString('sync_status', $payload['idempotency_key']);
        $this->assertStringNotContainsString('api-key-should-not-leak', $encoded);
        $this->assertStringNotContainsString('secret-should-not-leak', $encoded);
        $this->assertStringNotContainsString('IYZWSv2 should-not-leak', $encoded);
    }

    public function test_dry_run_and_no_send_response_do_not_mark_payment_paid(): void
    {
        $payment = $this->mountPaymentForRequest($this->technicalServiceRequest(), [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
        ]);

        $result = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($payment, [
                'ok' => true,
                'provider' => 'iyzico',
                'operation' => 'sync_status',
                'provider_status' => 'paid',
                'dry_run' => true,
                'no_send' => true,
                'provider_response_redacted' => ['status' => 'paid'],
            ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $result->status);
        $this->assertNull($result->paid_at);
        $this->assertSame('no_send', $result->raw_payload['provider_reconciliation']['status'] ?? null);
    }

    public function test_iyzico_link_sold_count_response_marks_paid_once_and_updates_customer_collection(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
        ]);
        $payment = $this->mountPaymentForRequest($request, [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
        ]);
        $this->storeGatewayConversation($payment);
        $service = app(TechnicalServicePaymentProviderReconciliationService::class);

        $service->handleProviderStatusResponse($payment, [
            'ok' => true,
            'provider' => 'iyzico',
            'operation' => 'sync_status',
            'provider_token' => 'iyzico-token',
            'provider_status' => 'sold',
            'conversation_id' => 'payment:'.$payment->id,
            'provider_response_redacted' => [
                'status' => 'success',
                'conversationId' => 'payment:'.$payment->id,
                'data' => [
                    'token' => 'iyzico-token',
                    'productStatus' => 'ACTIVE',
                    'soldCount' => 1,
                    'price' => '1234.50',
                    'currencyCode' => 'TRY',
                ],
            ],
        ]);
        $secondResult = $service->handleProviderStatusResponse($payment->fresh(), [
            'ok' => true,
            'provider' => 'iyzico',
            'operation' => 'sync_status',
            'provider_token' => 'iyzico-token',
            'provider_status' => 'sold',
            'conversation_id' => 'payment:'.$payment->id,
            'provider_response_redacted' => [
                'status' => 'success',
                'conversationId' => 'payment:'.$payment->id,
                'data' => [
                    'token' => 'iyzico-token',
                    'productStatus' => 'ACTIVE',
                    'soldCount' => 1,
                    'price' => '1234.50',
                    'currencyCode' => 'TRY',
                ],
            ],
        ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $secondResult->status);
        $this->assertNotNull($secondResult->paid_at);
        $this->assertSame(1, $request->events()->where('event_type', 'mount_payment_paid')->count());

        $summary = app(TechnicalServicePaymentOwnershipService::class)->summary($request->fresh());
        $this->assertSame(1234.5, $summary['company_collected_amount']);
    }

    public function test_iyzico_paid_response_extracts_provider_reference_without_fake_receipt_dekont(): void
    {
        $payment = $this->mountPaymentForRequest($this->technicalServiceRequest(), [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
        ]);
        $this->storeGatewayConversation($payment);

        $result = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($payment, [
                'ok' => true,
                'provider' => 'iyzico',
                'operation' => 'sync_status',
                'provider_reference' => 'iyzico-token',
                'provider_payment_reference' => '25236546',
                'provider_token' => 'iyzico-token',
                'provider_status' => 'sold',
                'conversation_id' => 'payment:'.$payment->id,
                'provider_response_redacted' => [
                    'status' => 'success',
                    'conversationId' => 'payment:'.$payment->id,
                    'paymentId' => '25236546',
                    'hostReference' => 'HOST-REF-8842',
                    'receiptNo' => 'NOT-A-LINK-DEKONT',
                    'data' => [
                        'token' => 'iyzico-token',
                        'productStatus' => 'ACTIVE',
                        'soldCount' => 1,
                        'price' => '1234.50',
                        'currencyCode' => 'TRY',
                    ],
                    'itemTransactions' => [
                        ['paymentTransactionId' => '27225634'],
                    ],
                ],
            ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $result->status);
        $this->assertSame('25236546', $result->provider_payment_reference);
        $this->assertSame('27225634', $result->provider_transaction_reference);
        $this->assertNull($result->provider_receipt_reference);
        $this->assertSame('25236546', $result->raw_payload['provider_reconciliation']['provider_payment_reference'] ?? null);
        $this->assertSame('27225634', $result->raw_payload['provider_reconciliation']['provider_transaction_reference'] ?? null);
        $this->assertNull($result->raw_payload['provider_reconciliation']['provider_receipt_reference'] ?? null);
    }

    public function test_iyzico_link_paid_response_does_not_use_local_payment_id_as_provider_reference(): void
    {
        $payment = $this->mountPaymentForRequest($this->technicalServiceRequest(), [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
        ]);
        $this->storeGatewayConversation($payment);

        $result = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($payment, [
                'ok' => true,
                'provider' => 'iyzico',
                'operation' => 'sync_status',
                'payment_id' => (string) $payment->id,
                'provider_token' => 'iyzico-token',
                'provider_status' => 'sold',
                'conversation_id' => 'payment:'.$payment->id,
                'provider_response_redacted' => [
                    'status' => 'success',
                    'conversationId' => 'payment:'.$payment->id,
                    'data' => [
                        'token' => 'iyzico-token',
                        'productStatus' => 'ACTIVE',
                        'soldCount' => 1,
                        'price' => '1234.50',
                        'currencyCode' => 'TRY',
                    ],
                ],
            ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $result->status);
        $this->assertNull($result->provider_payment_reference);
        $this->assertNull($result->provider_transaction_reference);
        $this->assertNull($result->provider_receipt_reference);
        $this->assertNull($result->raw_payload['provider_reconciliation']['provider_payment_reference'] ?? null);
        $this->assertSame('iyzico-token', $result->provider_reference);
    }

    public function test_paid_reconcile_sends_notification_when_enabled_and_duplicate_sync_does_not_resend(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        $this->enablePaymentNotification('payment-audit@example.test');
        $this->configureReadySmtpProfile();
        app(TechnicalServiceMessagingSettingsService::class)->update([
            'messaging_enabled' => true,
            'test_mode_enabled' => true,
            'shared_test_phone' => '0546 764 74 28',
            'ops_whatsapp_enabled' => true,
            'ops_whatsapp_phone' => '0546 764 74 28',
            'message_types' => [
                'payment_received_ops' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
            ],
        ]);

        $request = $this->technicalServiceRequest([
            'mrn' => 'PR88-REL3C9-MRN',
            'serial_number' => 'PR88-REL3C9-SERIAL',
            'customer_name' => 'PR88 REL3C9 Müşteri',
            'customer_phone' => '5550000001',
        ]);
        $payment = $this->mountPaymentForRequest($request, [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
        ]);
        $this->storeGatewayConversation($payment);
        $service = app(TechnicalServicePaymentProviderReconciliationService::class);

        $providerResponse = [
            'ok' => true,
            'provider' => 'iyzico',
            'operation' => 'sync_status',
            'provider_token' => 'iyzico-token',
            'provider_status' => 'sold',
            'conversation_id' => 'payment:'.$payment->id,
            'provider_response_redacted' => [
                'status' => 'success',
                'conversationId' => 'payment:'.$payment->id,
                'paymentId' => '25236546',
                'hostReference' => 'HOST-REF-8842',
                'api_key' => '[redacted]',
                'data' => [
                    'token' => 'iyzico-token',
                    'productStatus' => 'ACTIVE',
                    'soldCount' => 1,
                    'price' => '1234.50',
                    'currencyCode' => 'TRY',
                ],
                'itemTransactions' => [
                    ['paymentTransactionId' => '27225634'],
                ],
            ],
        ];

        $firstResult = $service->handleProviderStatusResponse($payment, $providerResponse);
        $secondResult = $service->handleProviderStatusResponse($firstResult->fresh(), $providerResponse);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $secondResult->status);
        $this->assertSame('sent', $secondResult->receipt_notification_status);
        $this->assertSame('payment-audit@example.test', $secondResult->receipt_notification_to);
        $this->assertNotNull($secondResult->receipt_notification_sent_at);
        $this->assertSame('sent', data_get(
            $secondResult->raw_payload,
            'payment_receipt_notification_claim.status',
        ));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get(
            $secondResult->raw_payload,
            'payment_receipt_notification_claim.idempotency_hash',
        ));

        Mail::assertSent(TechnicalServicePaymentAuditMail::class, 1);
        Mail::assertSent(TechnicalServicePaymentAuditMail::class, function (TechnicalServicePaymentAuditMail $mail): bool {
            $rendered = $mail->render();

            return $mail->hasTo('payment-audit@example.test')
                && str_contains($rendered, 'PR88-REL3C9-MRN')
                && str_contains($rendered, 'PR88-REL3C9-SERIAL')
                && str_contains($rendered, 'PR88 REL3C9 Müşteri')
                && str_contains($rendered, '1.234,50 TRY')
                && str_contains($rendered, '25236546')
                && str_contains($rendered, '27225634')
                && str_contains($rendered, 'Sağlayıcı tarafından dönmedi')
                && ! str_contains($rendered, 'TEST_SANDBOX_SECRET_KEY')
                && ! str_contains($rendered, 'api-key-should-not-leak');
        });
        $receiptDispatch = TechnicalServiceMessageDispatch::query()
            ->where('related_type', TechnicalServiceMountPayment::class)
            ->where('related_id', $payment->id)
            ->where('message_type', 'payment_receipt_notification')
            ->sole();
        $this->assertSame('email', $receiptDispatch->channel);
        $this->assertSame('smtp_payment_receipt', $receiptDispatch->provider_key);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $receiptDispatch->status);
        $this->assertSame(1, $receiptDispatch->attempt_count);
        $this->assertSame(1, $receiptDispatch->max_attempts);
        $this->assertFalse((bool) data_get($receiptDispatch->metadata, 'automatic_retry_allowed'));

        $this->assertSame(1, $request->events()->where('event_type', 'payment_receipt_notification_sent')->count());
        $this->assertSame(1, $request->events()->where('event_type', 'mount_payment_paid')->count());
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'payment_received_ops',
            'channel' => 'whatsapp',
            'recipient_role' => 'ops',
            'provider_key' => 'evo_whatsapp',
            'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED,
            'last_error_code' => 'outbound_execution_mode_local',
        ]);
        $this->assertDatabaseMissing('technical_service_message_dispatches', [
            'technical_service_request_id' => $request->id,
            'message_type' => 'payment_received_ops',
            'channel' => 'sms',
        ]);
        $opsDispatch = TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'payment_received_ops')
            ->firstOrFail();
        $this->assertFalse((bool) data_get($opsDispatch->metadata, 'provider_send_attempted'));
        $this->assertFalse((bool) data_get($opsDispatch->metadata, 'external_provider_call'));
        Http::assertNothingSent();

        $opsBody = (string) TechnicalServiceMessageDispatch::query()
            ->where('technical_service_request_id', $request->id)
            ->where('message_type', 'payment_received_ops')
            ->where('channel', 'whatsapp')
            ->firstOrFail()
            ->request_payload['body'];

        $this->assertStringContainsString('PR88-REL3C9-MRN', $opsBody);
        $this->assertStringContainsString('PR88 REL3C9 Müşteri', $opsBody);
        $this->assertStringContainsString('5550000001', $opsBody);
        $this->assertStringContainsString('PR88-REL3C9-SERIAL', $opsBody);
        $this->assertStringContainsString('1.234,50 TRY', $opsBody);
        $this->assertStringContainsString('25236546', $opsBody);
        $this->assertStringContainsString('27225634', $opsBody);
        $this->assertStringContainsString('Sağlayıcı tarafından dönmedi', $opsBody);
    }

    public function test_pending_and_cancelled_sync_do_not_send_payment_notification(): void
    {
        Mail::fake();
        $this->enablePaymentNotification('payment-audit@example.test');
        $service = app(TechnicalServicePaymentProviderReconciliationService::class);

        $pendingPayment = $this->mountPaymentForRequest($this->technicalServiceRequest(), [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-pending-token',
        ]);
        $this->storeGatewayConversation($pendingPayment);
        $service->handleProviderStatusResponse($pendingPayment, [
            'ok' => true,
            'provider' => 'iyzico',
            'operation' => 'sync_status',
            'provider_token' => 'iyzico-pending-token',
            'provider_status' => 'active',
            'conversation_id' => 'payment:'.$pendingPayment->id,
            'provider_response_redacted' => [
                'status' => 'success',
                'conversationId' => 'payment:'.$pendingPayment->id,
                'data' => [
                    'token' => 'iyzico-pending-token',
                    'productStatus' => 'ACTIVE',
                    'soldCount' => 0,
                    'price' => '1234.50',
                    'currencyCode' => 'TRY',
                ],
            ],
        ]);

        $cancelledPayment = $this->mountPaymentForRequest($this->technicalServiceRequest(), [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-cancelled-token',
        ]);
        $service->handleProviderStatusResponse($cancelledPayment, [
            'ok' => true,
            'provider' => 'iyzico',
            'operation' => 'sync_status',
            'provider_token' => 'iyzico-cancelled-token',
            'provider_status' => 'passive',
            'provider_response_redacted' => ['status' => 'passive'],
        ]);

        Mail::assertNothingSent();
        $this->assertNull($pendingPayment->fresh()->receipt_notification_status);
        $this->assertNull($cancelledPayment->fresh()->receipt_notification_status);
    }

    public function test_payment_notification_blocks_when_smtp_profile_is_missing(): void
    {
        Mail::fake();
        $this->enablePaymentNotification('payment-audit@example.test');

        $request = $this->technicalServiceRequest();
        $payment = $this->mountPaymentForRequest($request, [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
        ]);
        $this->storeGatewayConversation($payment);

        $result = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($payment, [
                'ok' => true,
                'provider' => 'iyzico',
                'operation' => 'sync_status',
                'provider_token' => 'iyzico-token',
                'provider_status' => 'sold',
                'conversation_id' => 'payment:'.$payment->id,
                'provider_response_redacted' => [
                    'status' => 'success',
                    'conversationId' => 'payment:'.$payment->id,
                    'paymentId' => '25236546',
                    'data' => [
                        'token' => 'iyzico-token',
                        'productStatus' => 'ACTIVE',
                        'soldCount' => 1,
                        'price' => '1234.50',
                        'currencyCode' => 'TRY',
                    ],
                ],
            ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $result->status);
        $this->assertSame('mailer_not_configured', $result->receipt_notification_status);
        $this->assertStringContainsString('SMTP ayarları tamamlanmalı', (string) $result->receipt_notification_error);
        $this->assertSame(1, $request->events()->where('event_type', 'payment_receipt_notification_blocked')->count());
        Mail::assertNothingSent();
    }

    public function test_mail_failure_does_not_unpay_payment_and_redacts_error(): void
    {
        $this->enablePaymentNotification('payment-audit@example.test');
        $this->configureReadySmtpProfile();
        $transport = new class extends TechnicalServiceMailTransportSettingsService
        {
            public int $calls = 0;

            public function __construct() {}

            public function sendPaymentAuditMail(array $recipients, TechnicalServicePaymentAuditMail $mail): void
            {
                $this->calls++;
                throw new \RuntimeException('SMTP failed password=super-secret gateway_token=abc123');
            }
        };
        $this->app->instance(TechnicalServiceMailTransportSettingsService::class, $transport);

        $request = $this->technicalServiceRequest();
        $payment = $this->mountPaymentForRequest($request, [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
        ]);
        $this->storeGatewayConversation($payment);

        $result = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($payment, [
                'ok' => true,
                'provider' => 'iyzico',
                'operation' => 'sync_status',
                'provider_token' => 'iyzico-token',
                'provider_status' => 'sold',
                'conversation_id' => 'payment:'.$payment->id,
                'provider_response_redacted' => [
                    'status' => 'success',
                    'conversationId' => 'payment:'.$payment->id,
                    'paymentId' => '25236546',
                    'data' => [
                        'token' => 'iyzico-token',
                        'productStatus' => 'ACTIVE',
                        'soldCount' => 1,
                        'price' => '1234.50',
                        'currencyCode' => 'TRY',
                    ],
                ],
            ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $result->status);
        $this->assertSame('failed', $result->receipt_notification_status);
        $this->assertStringNotContainsString('super-secret', (string) $result->receipt_notification_error);
        $this->assertStringNotContainsString('abc123', (string) $result->receipt_notification_error);
        $this->assertSame(1, $request->events()->where('event_type', 'payment_receipt_notification_failed')->count());

        app(TechnicalServicePaymentReceiptNotificationService::class)->notifyTrustedPaid($result->fresh());

        $this->assertSame(1, $transport->calls);
        $this->assertNull($result->fresh()->receipt_notification_sent_at);
        $this->assertSame('failed', data_get(
            $result->fresh()->raw_payload,
            'payment_receipt_notification_claim.status',
        ));
        $failedDispatch = TechnicalServiceMessageDispatch::query()
            ->where('related_type', TechnicalServiceMountPayment::class)
            ->where('related_id', $payment->id)
            ->where('message_type', 'payment_receipt_notification')
            ->sole();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_FAILED, $failedDispatch->status);
        $this->assertSame(1, $failedDispatch->attempt_count);
        $this->assertFalse((bool) data_get($failedDispatch->metadata, 'automatic_retry_allowed'));
        $this->assertSame(1, $request->events()->where('event_type', 'payment_receipt_notification_failed')->count());
    }

    public function test_paid_transition_and_receipt_intent_commit_atomically_and_remain_durable(): void
    {
        Mail::fake();
        $this->enablePaymentNotification('payment-audit@example.test');
        $payment = $this->mountPaymentForRequest($this->technicalServiceRequest(), [
            'provider' => 'iyzico',
            'provider_reference' => 'durable-receipt-token',
        ]);
        $notifications = app(TechnicalServicePaymentReceiptNotificationService::class);

        try {
            DB::transaction(function () use ($payment, $notifications): void {
                $locked = TechnicalServiceMountPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
                $locked->forceFill([
                    'status' => TechnicalServiceMountPayment::STATUS_PAID,
                    'paid_at' => now(),
                ])->save();
                $notifications->persistPaidReceiptIntentWithinTransaction($locked);

                throw new \RuntimeException('synthetic crash before commit');
            });
            $this->fail('Synthetic pre-commit crash rollback üretmeliydi.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('synthetic crash before commit', $exception->getMessage());
        }

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(0, TechnicalServiceMessageDispatch::query()
            ->where('message_type', 'payment_receipt_notification')
            ->count());

        $firstIntentId = DB::transaction(function () use ($payment, $notifications): int {
            $locked = TechnicalServiceMountPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'status' => TechnicalServiceMountPayment::STATUS_PAID,
                'paid_at' => now(),
            ])->save();

            return (int) $notifications->persistPaidReceiptIntentWithinTransaction($locked)?->id;
        });
        $secondIntentId = DB::transaction(function () use ($payment, $notifications): int {
            $locked = TechnicalServiceMountPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            return (int) $notifications->persistPaidReceiptIntentWithinTransaction($locked)?->id;
        });

        $this->assertSame($firstIntentId, $secondIntentId);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $payment->fresh()->status);
        $this->assertDatabaseHas('technical_service_message_dispatches', [
            'id' => $firstIntentId,
            'message_type' => 'payment_receipt_notification',
            'channel' => 'email',
            'status' => TechnicalServiceMessageDispatch::STATUS_QUEUED,
            'attempt_count' => 0,
            'max_attempts' => 1,
        ]);
        $this->assertSame(1, TechnicalServiceMessageDispatch::query()
            ->where('message_type', 'payment_receipt_notification')
            ->count());
        Mail::assertNothingSent();
    }

    public function test_concurrent_paid_state_cannot_be_regressed_by_reconciliation_save(): void
    {
        $payment = $this->mountPaymentForRequest($this->technicalServiceRequest(), [
            'provider' => 'iyzico',
            'provider_reference' => 'stale-reconciliation-token',
        ]);
        $stalePayment = $payment->fresh();
        TechnicalServiceMountPayment::query()->whereKey($payment->id)->update([
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'paid_at' => now(),
            'provider_paid_confirmed_at' => now(),
        ]);
        $payment->session()->update([
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
        ]);

        $result = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($stalePayment, [
                'ok' => true,
                'provider' => 'iyzico',
                'provider_status' => 'passive',
                'provider_response_redacted' => ['status' => 'passive'],
            ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $result->status);
        $this->assertNotNull($result->paid_at);
        $this->assertSame(
            TechnicalServiceMountSession::PAYMENT_PAID,
            $payment->session()->firstOrFail()->mount_payment_status,
        );
    }

    public function test_iyzico_api_success_without_link_sold_count_does_not_mark_paid(): void
    {
        $request = $this->technicalServiceRequest();
        $payment = $this->mountPaymentForRequest($request, [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
        ]);
        $this->storeGatewayConversation($payment);

        $result = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($payment, [
                'ok' => true,
                'provider' => 'iyzico',
                'operation' => 'sync_status',
                'provider_token' => 'iyzico-token',
                'provider_status' => 'success',
                'conversation_id' => 'payment:'.$payment->id,
                'provider_response_redacted' => [
                    'status' => 'success',
                    'conversationId' => 'payment:'.$payment->id,
                    'data' => [
                        'token' => 'iyzico-token',
                        'productStatus' => 'ACTIVE',
                        'soldCount' => 0,
                        'price' => '1234.50',
                        'currencyCode' => 'TRY',
                    ],
                ],
            ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $result->status);
        $this->assertNull($result->paid_at);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);
        $this->assertNull($payload['sale_and_payment']['payment_summary']['total_customer_collection']);
    }

    public function test_iyzico_link_sold_count_with_wrong_token_does_not_mark_paid(): void
    {
        $payment = $this->mountPaymentForRequest($this->technicalServiceRequest(), [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
        ]);
        $this->storeGatewayConversation($payment);

        $result = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($payment, [
                'ok' => true,
                'provider' => 'iyzico',
                'operation' => 'sync_status',
                'provider_token' => 'other-token',
                'provider_status' => 'sold',
                'conversation_id' => 'payment:'.$payment->id,
                'provider_response_redacted' => [
                    'status' => 'success',
                    'conversationId' => 'payment:'.$payment->id,
                    'data' => [
                        'token' => 'other-token',
                        'productStatus' => 'ACTIVE',
                        'soldCount' => 1,
                        'price' => '1234.50',
                        'currencyCode' => 'TRY',
                    ],
                ],
            ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $result->status);
        $this->assertNull($result->paid_at);
    }

    public function test_iyzico_link_sold_count_with_wrong_amount_does_not_mark_paid(): void
    {
        $payment = $this->mountPaymentForRequest($this->technicalServiceRequest(), [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
        ]);
        $this->storeGatewayConversation($payment);

        $result = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($payment, [
                'ok' => true,
                'provider' => 'iyzico',
                'operation' => 'sync_status',
                'provider_token' => 'iyzico-token',
                'provider_status' => 'sold',
                'conversation_id' => 'payment:'.$payment->id,
                'provider_response_redacted' => [
                    'status' => 'success',
                    'conversationId' => 'payment:'.$payment->id,
                    'data' => [
                        'token' => 'iyzico-token',
                        'productStatus' => 'ACTIVE',
                        'soldCount' => 1,
                        'price' => '1.00',
                        'currencyCode' => 'TRY',
                    ],
                ],
            ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $result->status);
        $this->assertNull($result->paid_at);
    }

    public function test_iyzico_paid_after_cancel_is_blocked_for_admin_review(): void
    {
        $request = $this->technicalServiceRequest();
        $payment = $this->mountPaymentForRequest($request, [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
            'status' => TechnicalServiceMountPayment::STATUS_CANCELLED,
        ]);
        $this->storeGatewayConversation($payment);

        $result = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($payment, [
                'ok' => true,
                'provider' => 'iyzico',
                'operation' => 'sync_status',
                'provider_token' => 'iyzico-token',
                'provider_status' => 'sold',
                'conversation_id' => 'payment:'.$payment->id,
                'provider_response_redacted' => [
                    'status' => 'success',
                    'conversationId' => 'payment:'.$payment->id,
                    'data' => [
                        'token' => 'iyzico-token',
                        'productStatus' => 'PASSIVE',
                        'soldCount' => 1,
                        'price' => '1234.50',
                        'currencyCode' => 'TRY',
                    ],
                ],
            ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_CANCELLED, $result->status);
        $this->assertNull($result->paid_at);
        $this->assertSame('paid_after_cancel_requires_admin_review', $result->raw_payload['provider_reconciliation']['blocked_reason'] ?? null);
        $this->assertSame(0, $request->events()->where('event_type', 'mount_payment_paid')->count());
    }

    public function test_provider_status_pending_response_keeps_pending_and_not_collected(): void
    {
        $request = $this->technicalServiceRequest();
        $payment = $this->mountPaymentForRequest($request, ['provider' => 'iyzico']);

        $result = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($payment, [
                'ok' => true,
                'provider_status' => 'active',
                'provider_response_redacted' => ['status' => 'active'],
            ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $result->status);
        $this->assertNull($result->paid_at);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);
        $this->assertNull($payload['sale_and_payment']['payment_summary']['total_customer_collection']);
    }

    public function test_provider_status_cancelled_response_marks_cancelled_and_not_collected(): void
    {
        $request = $this->technicalServiceRequest();
        $payment = $this->mountPaymentForRequest($request, ['provider' => 'iyzico']);

        $result = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($payment, [
                'ok' => true,
                'provider_status' => 'passive',
                'provider_response_redacted' => ['status' => 'passive'],
            ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_CANCELLED, $result->status);
        $this->assertNull($result->paid_at);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);
        $this->assertNull($payload['sale_and_payment']['payment_summary']['total_customer_collection']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function technicalServiceRequest(array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-RECONCILE-'.uniqid(),
            'root_mrn' => null,
            'customer_name' => 'Reconcile Müşteri',
            'customer_phone' => '5550000000',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'Reconcile test adres',
            'product_name' => 'Reconcile Ürün',
            'serial_number' => 'SN-RECONCILE-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function mountPaymentForRequest(TechnicalServiceRequest $request, array $overrides = []): TechnicalServiceMountPayment
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => $request->serial_number,
            'product_name' => $request->product_name,
            'product_model' => $request->product_model,
            'brand' => $request->brand,
        ]);
        $session = TechnicalServiceMountSession::query()->create([
            'technical_service_qr_link_id' => $link->id,
            'technical_service_request_id' => $request->id,
            'serial_number' => $request->serial_number,
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken(uniqid('reconcile-session-', true)),
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
        ]);

        return TechnicalServiceMountPayment::query()->create(array_merge([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 1234.5,
            'currency' => 'TRY',
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'technical_service_request_id' => $request->id,
                'request_code' => $request->mrn,
                'root_mrn' => $request->root_mrn ?: $request->mrn,
                'serial_number' => $request->serial_number,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
            ],
        ], $overrides));
    }

    private function storeGatewayConversation(TechnicalServiceMountPayment $payment): void
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $payload['provider_gateway'] = array_merge(
            is_array($payload['provider_gateway'] ?? null) ? $payload['provider_gateway'] : [],
            ['conversation_id' => 'payment:'.$payment->id],
        );

        $payment->forceFill(['raw_payload' => $payload])->save();
    }

    private function enablePaymentNotification(string $recipients): void
    {
        PageConfig::query()->create([
            'page_code' => TechnicalServicePaymentProviderSettingsService::PAGE_CODE,
            'layout_json' => [
                'technical_service' => [
                    'payment' => [
                        'notification' => [
                            'enabled' => true,
                            'recipients' => $recipients,
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function configureReadySmtpProfile(): void
    {
        MailTransportProfile::query()->create([
            'scope' => MailTransportProfile::SCOPE_TECHNICAL_SERVICE,
            'profile_key' => MailTransportProfile::PROFILE_DEFAULT,
            'display_name' => 'Test SMTP',
            'outgoing_enabled' => true,
            'outgoing_mailer' => MailTransportProfile::MAILER_SMTP,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username_encrypted' => 'payment-audit@example.test',
            'smtp_password_encrypted' => 'PR88_MAIL_PASS_TEST_ONLY',
            'smtp_username_mask' => 'pay****it@example.test',
            'smtp_password_mask' => '************',
            'from_address' => 'no-reply@example.test',
            'from_name' => 'EMAKS Test',
        ]);
    }
}
