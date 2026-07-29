<?php

namespace Tests\Feature;

use App\Mail\TechnicalServicePaymentAuditMail;
use App\Models\MailTransportProfile;
use App\Models\PageConfig;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\Payments\TechnicalServicePaymentProviderCredentialService;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use App\Services\TechnicalService\TechnicalServicePaymentOwnershipService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TechnicalServiceIyzicoScheduledReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_command_selects_pending_iyzico_payments_and_dry_run_does_not_call_provider(): void
    {
        $payment = $this->mountPayment([
            'provider_reference' => 'dry-run-token',
        ]);
        $this->mountPayment([
            'provider_reference' => 'paid-token',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
        ]);
        Http::fake();

        $this->artisan('technical-service:reconcile-iyzico-payments', [
            '--dry-run' => true,
            '--older-than-minutes' => 0,
        ])->assertSuccessful();

        Http::assertNothingSent();
        $payment->refresh();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);
        $this->assertSame(0, $payment->provider_sync_attempts);
    }

    public function test_reconcile_command_skips_missing_provider_token(): void
    {
        $payment = $this->mountPayment([
            'provider_reference' => null,
        ]);
        Http::fake();

        $this->artisan('technical-service:reconcile-iyzico-payments', [
            '--dry-run' => true,
            '--older-than-minutes' => 0,
        ])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, $payment->fresh()->provider_sync_attempts);
    }

    public function test_live_reconcile_no_live_api_call_without_live_enablement(): void
    {
        $this->configureDirectSandbox();
        $payment = $this->mountPayment([
            'provider_reference' => 'live-disabled-token',
            'raw_payload' => [
                'provider_mode' => 'live',
                'provider_transport' => 'direct_laravel',
            ],
        ]);
        Http::fake();

        $this->artisan('technical-service:reconcile-iyzico-payments', [
            '--only-live' => true,
            '--older-than-minutes' => 0,
        ])->assertSuccessful();

        Http::assertNothingSent();
        $payment->refresh();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);
        $this->assertSame(0, $payment->provider_sync_attempts);
        $this->assertNull($payment->paid_at);
    }

    public function test_only_sandbox_option_still_selects_sandbox_without_live_candidate(): void
    {
        $this->configureDirectSandbox();
        $sandbox = $this->mountPayment([
            'provider_reference' => 'sandbox-filter-token',
        ]);
        $live = $this->mountPayment([
            'provider_reference' => 'live-filter-token',
            'raw_payload' => [
                'provider_mode' => 'live',
                'provider_transport' => 'direct_laravel',
            ],
        ]);
        Http::fake();

        $this->artisan('technical-service:reconcile-iyzico-payments', [
            '--only-sandbox' => true,
            '--dry-run' => true,
            '--older-than-minutes' => 0,
        ])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, $sandbox->fresh()->provider_sync_attempts);
        $this->assertSame(0, $live->fresh()->provider_sync_attempts);
    }

    public function test_reconcile_command_payment_id_filter_reconciles_single_payment(): void
    {
        $this->configureDirectSandbox();
        $first = $this->mountPayment(['provider_reference' => 'first-token']);
        $second = $this->mountPayment(['provider_reference' => 'second-token']);
        $this->storeGatewayConversation($second);
        Http::fake([
            'https://sandbox-api.iyzipay.com/v2/iyzilink/products/second-token' => Http::response($this->paidIyzicoResponse($second, 'second-token'), 200),
        ]);

        $this->artisan('technical-service:reconcile-iyzico-payments', [
            '--payment-id' => $second->id,
            '--older-than-minutes' => 0,
        ])->assertSuccessful();

        $this->assertSame(0, $first->fresh()->provider_sync_attempts);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $second->fresh()->status);
    }

    public function test_reconcile_command_paid_response_marks_paid_once_and_does_not_send_duplicate_mail(): void
    {
        $this->configureDirectSandbox();
        $this->enablePaymentNotification('payment-audit@example.test');
        $this->configureReadySmtpProfile();
        Mail::fake();

        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
        ]);
        $payment = $this->mountPayment([
            'technical_service_request_id' => $request->id,
            'provider_reference' => 'paid-command-token',
        ]);
        $this->storeGatewayConversation($payment);
        Http::fake([
            'https://sandbox-api.iyzipay.com/v2/iyzilink/products/paid-command-token' => Http::response($this->paidIyzicoResponse($payment, 'paid-command-token'), 200),
        ]);

        $this->artisan('technical-service:reconcile-iyzico-payments', [
            '--older-than-minutes' => 0,
        ])->assertSuccessful();
        $this->artisan('technical-service:reconcile-iyzico-payments', [
            '--older-than-minutes' => 0,
        ])->assertSuccessful();

        $payment->refresh();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertNotNull($payment->provider_last_synced_at);
        $this->assertSame(1, $payment->provider_sync_attempts);
        $this->assertSame('paid', $payment->provider_last_sync_status);
        $this->assertNotNull($payment->provider_paid_confirmed_at);
        Mail::assertSent(TechnicalServicePaymentAuditMail::class, 1);
        $this->assertSame(1, $request->events()->where('event_type', 'mount_payment_paid')->count());

        $summary = app(TechnicalServicePaymentOwnershipService::class)->summary($request->fresh());
        $this->assertSame(1234.5, $summary['company_collected_amount']);
    }

    public function test_reconcile_command_pending_and_cancelled_responses_do_not_collect(): void
    {
        $this->configureDirectSandbox();
        $pending = $this->mountPayment(['provider_reference' => 'pending-command-token']);
        $cancelled = $this->mountPayment(['provider_reference' => 'cancelled-command-token']);
        $this->storeGatewayConversation($pending);
        $this->storeGatewayConversation($cancelled);
        Http::fake([
            'https://sandbox-api.iyzipay.com/v2/iyzilink/products/pending-command-token' => Http::response([
                'status' => 'success',
                'conversationId' => 'payment:'.$pending->id,
                'data' => [
                    'token' => 'pending-command-token',
                    'productStatus' => 'ACTIVE',
                    'soldCount' => 0,
                    'price' => '1234.50',
                    'currencyCode' => 'TRY',
                ],
            ], 200),
            'https://sandbox-api.iyzipay.com/v2/iyzilink/products/cancelled-command-token' => Http::response([
                'status' => 'success',
                'conversationId' => 'payment:'.$cancelled->id,
                'data' => [
                    'token' => 'cancelled-command-token',
                    'productStatus' => 'PASSIVE',
                    'soldCount' => 0,
                    'price' => '1234.50',
                    'currencyCode' => 'TRY',
                ],
            ], 200),
        ]);

        $this->artisan('technical-service:reconcile-iyzico-payments', [
            '--older-than-minutes' => 0,
        ])->assertSuccessful();

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $pending->fresh()->status);
        $this->assertSame(1, $pending->fresh()->provider_sync_attempts);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->assertSame(1, $cancelled->fresh()->provider_sync_attempts);
        $this->assertNull($pending->fresh()->paid_at);
        $this->assertNull($cancelled->fresh()->paid_at);
    }

    public function test_admin_sync_status_api_still_uses_trusted_provider_sync(): void
    {
        $this->configureDirectSandbox();
        $admin = User::factory()->create(['role_code' => 'admin']);
        $request = $this->technicalServiceRequest();
        $payment = $this->mountPayment([
            'technical_service_request_id' => $request->id,
            'provider_reference' => 'admin-sync-command-token',
        ]);
        $this->storeGatewayConversation($payment);
        Http::fake([
            'https://sandbox-api.iyzipay.com/v2/iyzilink/products/admin-sync-command-token' => Http::response($this->paidIyzicoResponse($payment, 'admin-sync-command-token'), 200),
        ]);

        $this->actingAs($admin)
            ->getJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/status?sync_provider=1")
            ->assertOk()
            ->assertJsonPath('payment.status', TechnicalServiceMountPayment::STATUS_PAID)
            ->assertJsonPath('payment.provider_last_sync_status', TechnicalServiceMountPayment::STATUS_PAID)
            ->assertJsonPath('payment.provider_sync_attempts', 1);

        $payment->refresh();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $payment->status);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_reconcile_command_provider_error_records_redacted_error_and_does_not_mark_paid(): void
    {
        $this->configureDirectSandbox();
        $payment = $this->mountPayment(['provider_reference' => 'error-command-token']);
        Http::fake([
            'https://sandbox-api.iyzipay.com/v2/iyzilink/products/error-command-token' => Http::response([
                'status' => 'failure',
                'errorMessage' => 'api_key=abc123 password=super-secret',
            ], 422),
        ]);

        $this->artisan('technical-service:reconcile-iyzico-payments', [
            '--older-than-minutes' => 0,
        ])->assertFailed();

        $payment->refresh();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);
        $this->assertSame(1, $payment->provider_sync_attempts);
        $this->assertSame('provider_error', $payment->provider_last_sync_status);
        $this->assertStringNotContainsString('abc123', (string) $payment->provider_last_sync_error);
        $this->assertStringNotContainsString('super-secret', (string) $payment->provider_last_sync_error);
        $this->assertNull($payment->paid_at);
    }

    public function test_only_live_option_does_not_call_live_when_disabled(): void
    {
        $this->configureDirectSandbox();
        $payment = $this->mountPayment([
            'provider_reference' => 'only-live-disabled-token',
            'raw_payload' => [
                'provider_mode' => 'live',
                'provider_transport' => 'direct_laravel',
            ],
        ]);
        Http::fake();

        $this->artisan('technical-service:reconcile-iyzico-payments', [
            '--only-live' => true,
            '--older-than-minutes' => 0,
        ])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, $payment->fresh()->provider_sync_attempts);
    }

    public function test_scheduler_registers_mode_aware_iyzico_reconcile_command(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertTrue(collect($events)->contains(function ($event): bool {
            $command = (string) ($event->command ?? '');

            return str_contains($command, 'technical-service:reconcile-iyzico-payments')
                && ! str_contains($command, '--only-sandbox')
                && str_contains($command, '--max-attempts=5');
        }));
    }

    public function test_admin_readiness_shows_live_reconcile_disabled_until_live_activation(): void
    {
        $this->configureDirectSandbox();

        $payload = app(TechnicalServicePaymentProviderSettingsService::class)->payload();

        $this->assertTrue($payload['automatic_reconcile']['sandbox']['ready']);
        $this->assertSame('Aktif / hazır', $payload['automatic_reconcile']['sandbox']['label']);
        $this->assertFalse($payload['automatic_reconcile']['live']['ready']);
        $this->assertSame('Kapalı / canlı ödeme aktif edilince açılacak', $payload['automatic_reconcile']['live']['label']);
        $this->assertFalse($payload['automatic_reconcile']['callback_verified']);
        $this->assertStringContainsString('admin/manual sync + scheduled reconcile', $payload['automatic_reconcile']['accepted_fallback']);
        $this->assertStringContainsString('live reconcile readiness', $payload['automatic_reconcile']['live_release_requirement']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureDirectSandbox(array $overrides = []): void
    {
        config(array_merge([
            'payments.real_provider_enabled' => true,
            'payments.provider_name' => 'iyzico',
            'payments.provider_transport' => 'direct_laravel',
            'payments.gateway.mode' => 'sandbox',
            'payments.iyzico.live_send_approved' => false,
        ], $overrides));

        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('sandbox', 'TEST_SANDBOX_API_KEY_DIRECT', 'TEST_SANDBOX_SECRET_DIRECT');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function technicalServiceRequest(array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'PR88-REL3C9H-MRN-'.uniqid(),
            'root_mrn' => null,
            'customer_name' => 'PR88 REL3C9H Müşteri',
            'customer_phone' => '5550000000',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'PR88 REL3C9H test adres',
            'product_name' => 'PR88 REL3C9H Ürün',
            'serial_number' => 'PR88-REL3C9H-SERIAL-'.uniqid(),
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
    private function mountPayment(array $overrides = []): TechnicalServiceMountPayment
    {
        $request = isset($overrides['technical_service_request_id'])
            ? TechnicalServiceRequest::query()->findOrFail($overrides['technical_service_request_id'])
            : $this->technicalServiceRequest();

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
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken(uniqid('rel3c9h-session-', true)),
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
        ]);

        return TechnicalServiceMountPayment::query()->create(array_merge([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'iyzico',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 1234.5,
            'currency' => 'TRY',
            'payment_url' => 'https://sandbox.iyzi.link/'.($overrides['provider_reference'] ?? 'rel3c9h-token'),
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'provider_mode' => 'sandbox',
                'provider_transport' => 'direct_laravel',
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

    /**
     * @return array<string, mixed>
     */
    private function paidIyzicoResponse(TechnicalServiceMountPayment $payment, string $token): array
    {
        return [
            'status' => 'success',
            'conversationId' => 'payment:'.$payment->id,
            'paymentId' => 'PAYMENT-'.$payment->id,
            'data' => [
                'token' => $token,
                'productStatus' => 'ACTIVE',
                'soldCount' => 1,
                'price' => '1234.50',
                'currencyCode' => 'TRY',
            ],
            'itemTransactions' => [
                ['paymentTransactionId' => 'TRANSACTION-'.$payment->id],
            ],
        ];
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
            'smtp_password_encrypted' => str_repeat('x', 12),
            'smtp_username_mask' => 'pay****it@example.test',
            'smtp_password_mask' => '************',
            'from_address' => 'no-reply@example.test',
            'from_name' => 'EMAKS Test',
        ]);
    }
}
