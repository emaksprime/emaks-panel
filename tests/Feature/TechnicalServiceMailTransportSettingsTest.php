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
use App\Services\Payments\TechnicalServicePaymentProviderReconciliationService;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TechnicalServiceMailTransportSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_transport_settings_default_payload_is_masked_and_not_ready(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/technical-service/mail-transport-settings')
            ->assertOk()
            ->assertJsonPath('mail_transport_settings.outgoing.ready', false)
            ->assertJsonPath('mail_transport_settings.outgoing.status_label', 'SMTP eksik')
            ->assertJsonPath('mail_transport_settings.incoming.ready', false)
            ->assertJsonPath('mail_transport_settings.incoming.protocol', 'imap')
            ->assertJsonPath('mail_transport_settings.payment_notification_ready', false);
    }

    public function test_smtp_credentials_are_encrypted_at_rest_and_response_masks_secret(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/mail-transport-settings/outgoing', [
                'enabled' => true,
                'host' => 'smtp.example.test',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'payment-audit@example.test',
                'password' => 'PR88_MAIL_PASS_TEST_ONLY',
                'from_address' => 'no-reply@example.test',
                'from_name' => 'EMAKS Test',
            ])
            ->assertOk()
            ->assertJsonPath('mail_transport_settings.outgoing.ready', true)
            ->assertJsonPath('mail_transport_settings.outgoing.username_mask', 'pay****it@example.test')
            ->assertJsonPath('mail_transport_settings.outgoing.password_mask', '************');

        $encodedResponse = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PR88_MAIL_PASS_TEST_ONLY', $encodedResponse);

        $row = DB::table('mail_transport_profiles')->first();
        $this->assertNotNull($row);
        $this->assertNotSame('payment-audit@example.test', $row->smtp_username_encrypted);
        $this->assertNotSame('PR88_MAIL_PASS_TEST_ONLY', $row->smtp_password_encrypted);

        $profile = MailTransportProfile::query()->firstOrFail();
        $this->assertSame('payment-audit@example.test', $profile->smtp_username_encrypted);
        $this->assertSame('PR88_MAIL_PASS_TEST_ONLY', $profile->smtp_password_encrypted);
    }

    public function test_page_configs_does_not_store_mail_password(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/mail-transport-settings/outgoing', [
                'enabled' => true,
                'host' => 'smtp.example.test',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'payment-audit@example.test',
                'password' => 'PR88_MAIL_PASS_TEST_ONLY',
                'from_address' => 'no-reply@example.test',
                'from_name' => 'EMAKS Test',
            ])
            ->assertOk();

        $encodedPageConfigs = json_encode(PageConfig::query()->pluck('layout_json')->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PR88_MAIL_PASS_TEST_ONLY', $encodedPageConfigs);
        $this->assertStringNotContainsString('payment-audit@example.test', $encodedPageConfigs);
    }

    public function test_non_admin_and_public_cannot_update_mail_settings(): void
    {
        $payload = [
            'enabled' => true,
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'payment-audit@example.test',
            'password' => 'PR88_MAIL_PASS_TEST_ONLY',
            'from_address' => 'no-reply@example.test',
            'from_name' => 'EMAKS Test',
        ];

        $this->postJson('/api/technical-service/mail-transport-settings/outgoing', $payload)
            ->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role_code' => 'ops']))
            ->postJson('/api/technical-service/mail-transport-settings/outgoing', $payload)
            ->assertForbidden();

        $this->assertDatabaseCount('mail_transport_profiles', 0);
    }

    public function test_smtp_validation_rules_are_enforced(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/mail-transport-settings/outgoing', [
                'enabled' => true,
                'host' => '',
                'port' => 70000,
                'encryption' => 'starttls',
                'from_address' => 'not-email',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host', 'port', 'encryption', 'from_address']);
    }

    public function test_imap_pop3_credentials_are_encrypted_and_protocol_validated(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/mail-transport-settings/incoming', [
                'enabled' => true,
                'protocol' => 'smtp',
                'host' => '',
                'port' => 70000,
                'encryption' => 'starttls',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['protocol', 'host', 'port', 'encryption']);

        $response = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/mail-transport-settings/incoming', [
                'enabled' => true,
                'protocol' => 'pop3',
                'host' => 'pop3.example.test',
                'port' => 995,
                'encryption' => 'ssl',
                'username' => 'inbox@example.test',
                'password' => 'PR88_INBOX_PASS_TEST_ONLY',
                'mailbox' => 'INBOX',
            ])
            ->assertOk()
            ->assertJsonPath('mail_transport_settings.incoming.ready', true)
            ->assertJsonPath('mail_transport_settings.incoming.protocol', 'pop3')
            ->assertJsonPath('mail_transport_settings.incoming.username_mask', 'inb****@example.test')
            ->assertJsonPath('mail_transport_settings.incoming.password_mask', '************');

        $encodedResponse = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PR88_INBOX_PASS_TEST_ONLY', $encodedResponse);

        $row = DB::table('mail_transport_profiles')->first();
        $this->assertNotNull($row);
        $this->assertNotSame('inbox@example.test', $row->incoming_username_encrypted);
        $this->assertNotSame('PR88_INBOX_PASS_TEST_ONLY', $row->incoming_password_encrypted);
    }

    public function test_smtp_test_mail_fails_cleanly_when_missing_config(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/mail-transport-settings/outgoing/test', [
                'recipient' => 'payment-audit@example.test',
            ])
            ->assertStatus(422)
            ->assertJsonPath('mail_transport_settings.outgoing.last_test_status', 'blocked')
            ->assertJsonPath('message', 'Gerçek mail gönderimi için SMTP ayarları tamamlanmalı.');
    }

    public function test_incoming_connection_test_reports_missing_config_without_fetching_messages(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/mail-transport-settings/incoming/test')
            ->assertOk()
            ->assertJsonPath('mail_transport_settings.incoming.last_test_status', 'blocked')
            ->assertJsonPath('mail_transport_settings.incoming.last_test_message', 'Gelen kutu bağlantı testi için IMAP/POP3 ayarları tamamlanmalı.');
    }

    public function test_payment_notification_sends_when_smtp_ready_and_enabled(): void
    {
        Mail::fake();
        $this->configureReadySmtpProfile();
        $this->enablePaymentNotification('payment-audit@example.test');

        $request = $this->technicalServiceRequest();
        $payment = $this->mountPaymentForRequest($request, [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
        ]);
        $this->storeGatewayConversation($payment);
        $response = $this->trustedPaidResponse($payment);

        $first = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($payment, $response);
        $second = app(TechnicalServicePaymentProviderReconciliationService::class)
            ->handleProviderStatusResponse($first->fresh(), $response);

        $this->assertSame('sent', $second->receipt_notification_status);
        Mail::assertSent(TechnicalServicePaymentAuditMail::class, 1);
    }

    public function test_payment_notification_blocks_when_smtp_missing(): void
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
            ->handleProviderStatusResponse($payment, $this->trustedPaidResponse($payment));

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $result->status);
        $this->assertSame('mailer_not_configured', $result->receipt_notification_status);
        Mail::assertNothingSent();
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
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

    private function technicalServiceRequest(): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-MAIL-'.uniqid(),
            'root_mrn' => null,
            'customer_name' => 'Mail Test Müşteri',
            'customer_phone' => '5550000000',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'Mail test adres',
            'product_name' => 'Mail Test Ürün',
            'serial_number' => 'SN-MAIL-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ]);
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
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken(uniqid('mail-session-', true)),
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

    /**
     * @return array<string, mixed>
     */
    private function trustedPaidResponse(TechnicalServiceMountPayment $payment): array
    {
        return [
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
        ];
    }
}
