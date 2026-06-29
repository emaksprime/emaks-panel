<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Services\Payments\PaymentProviderGatewayClient;
use App\Services\Payments\PaymentProviderGatewayRequest;
use App\Services\Payments\PaymentProviderGatewayResponse;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\TechnicalServicePaymentProviderDisabledException;
use App\Services\Payments\TechnicalServicePaymentProviderGateway;
use App\Services\Payments\TechnicalServicePaymentProviderModeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PaymentProviderGatewayContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_provider_defaults_to_fake_mode(): void
    {
        $resolver = app(TechnicalServicePaymentProviderModeResolver::class);
        $manager = app(PaymentProviderManager::class);

        $this->assertFalse($resolver->realProviderEnabled());
        $this->assertTrue($resolver->shouldUseFakeProvider());
        $this->assertSame('fake', $resolver->activeProviderName());
        $this->assertSame('fake', $manager->providerName());
    }

    public function test_real_provider_disabled_by_default(): void
    {
        $status = app(TechnicalServicePaymentProviderModeResolver::class)->status();

        $this->assertFalse($status['real_provider_enabled']);
        $this->assertFalse($status['ready']);
        $this->assertSame('fake', $status['active_provider']);
    }

    public function test_fake_provider_still_creates_payment_when_real_disabled(): void
    {
        config([
            'payments.provider' => 'iyzico',
            'payments.provider_name' => 'iyzico',
            'payments.real_provider_enabled' => false,
        ]);

        $payment = $this->mountPayment();

        app(PaymentProviderManager::class)->createPayment($payment);

        $payment->refresh();
        $this->assertSame('fake', $payment->provider);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);
        $this->assertNotEmpty($payment->provider_reference);
        $this->assertStringContainsString('/mount-payment/', (string) $payment->payment_url);
    }

    public function test_real_provider_enabled_without_credentials_blocks_payment_create(): void
    {
        config([
            'payments.real_provider_enabled' => true,
            'payments.provider_name' => 'iyzico',
            'payments.gateway.url' => null,
            'payments.gateway.token' => null,
            'payments.gateway.health_verified' => false,
            'payments.gateway.http_enabled' => false,
        ]);

        $payment = $this->mountPayment(['provider' => 'iyzico']);

        $this->expectException(TechnicalServicePaymentProviderDisabledException::class);
        $this->expectExceptionMessage(TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE);

        app(PaymentProviderManager::class)->createPayment($payment);
    }

    public function test_payment_provider_gateway_payload_contains_payment_request_mrn_serial_customer(): void
    {
        $request = $this->technicalServiceRequest();
        $payment = $this->mountPaymentForRequest($request);

        $payload = app(TechnicalServicePaymentProviderGateway::class)
            ->buildRequest(PaymentProviderGatewayRequest::OPERATION_CREATE_LINK, $payment)
            ->toArray();

        $this->assertSame((string) $payment->id, $payload['payment_id']);
        $this->assertSame((string) $request->id, $payload['request_id']);
        $this->assertSame($request->mrn, $payload['request_code']);
        $this->assertSame($request->root_mrn ?: $request->mrn, $payload['root_mrn']);
        $this->assertSame($request->serial_number, $payload['serial_no']);
        $this->assertSame($request->customer_name, $payload['customer']['name']);
        $this->assertSame($request->customer_phone, $payload['customer']['phone']);
        $this->assertSame('1234.50', $payload['amount']);
        $this->assertSame('TRY', $payload['currency']);
        $this->assertSame('payment:'.$payment->id, $payload['conversation_id']);
        $this->assertStringContainsString('create_link', $payload['idempotency_key']);
    }

    public function test_payment_provider_gateway_builds_n8n_payload_without_secret(): void
    {
        $payment = $this->mountPayment([
            'raw_payload' => [
                'request_code' => 'MRN-SECRET-TEST',
                'serial_number' => 'SN-SECRET-TEST',
                'customer_name' => 'Gizli Test',
                'customer_phone' => '5550009999',
                'api_key' => 'plain-api-key-value',
                'secret_key' => 'plain-secret-value',
                'authorization' => 'Bearer should-not-leak',
            ],
        ]);

        $payload = app(TechnicalServicePaymentProviderGateway::class)
            ->buildRequest(PaymentProviderGatewayRequest::OPERATION_CREATE_LINK, $payment)
            ->toArray();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('plain-api-key-value', $encoded);
        $this->assertStringNotContainsString('plain-secret-value', $encoded);
        $this->assertStringNotContainsString('should-not-leak', $encoded);
        $this->assertSame('[redacted]', $payload['metadata']['api_key']);
        $this->assertSame('[redacted]', $payload['metadata']['secret_key']);
        $this->assertSame('[redacted]', $payload['metadata']['authorization']);
    }

    public function test_provider_gateway_does_not_call_http_when_disabled(): void
    {
        $client = new RecordingPaymentProviderGatewayClient;
        $this->app->instance(PaymentProviderGatewayClient::class, $client);

        $this->expectException(TechnicalServicePaymentProviderDisabledException::class);

        try {
            app(TechnicalServicePaymentProviderGateway::class)->createLink($this->mountPayment());
        } finally {
            $this->assertFalse($client->called);
        }
    }

    public function test_provider_gateway_blocks_unknown_operation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(TechnicalServicePaymentProviderGateway::class)->buildRequest('unknown_operation', $this->mountPayment());
    }

    public function test_provider_gateway_health_check_reports_disabled_when_not_configured(): void
    {
        $response = app(TechnicalServicePaymentProviderGateway::class)->healthCheck()->toArray();

        $this->assertFalse($response['ok']);
        $this->assertSame('provider_disabled', $response['error_code']);
        $this->assertSame('Gerçek ödeme sağlayıcısı devre dışı.', $response['error_message']);
    }

    public function test_provider_response_redacts_secret_fields(): void
    {
        $response = PaymentProviderGatewayResponse::fromArray([
            'ok' => true,
            'provider_token' => 'link-token',
            'payment_url' => 'https://pay.example.test/link-token',
            'provider_response' => [
                'api_key' => 'api-key-value',
                'secretKey' => 'secret-value',
                'authorization' => 'auth-value',
                'token' => 'link-token',
                'nested' => [
                    'gateway_token' => 'gateway-value',
                ],
            ],
        ])->toArray();

        $this->assertSame('link-token', $response['provider_token']);
        $this->assertSame('[redacted]', $response['provider_response_redacted']['api_key']);
        $this->assertSame('[redacted]', $response['provider_response_redacted']['secretKey']);
        $this->assertSame('[redacted]', $response['provider_response_redacted']['authorization']);
        $this->assertSame('[redacted]', $response['provider_response_redacted']['nested']['gateway_token']);
        $this->assertSame('link-token', $response['provider_response_redacted']['token']);
    }

    public function test_env_example_has_no_real_payment_provider_secret(): void
    {
        $envExamplePath = base_path('.env.example');

        if (! file_exists($envExamplePath)) {
            $this->markTestSkipped('.env.example bulunamadı.');
        }

        $contents = (string) file_get_contents($envExamplePath);

        $this->assertStringNotContainsString('IYZICO_SANDBOX_SECRET_KEY=', $contents);
        $this->assertStringNotContainsString('IYZICO_LIVE_SECRET_KEY=', $contents);
        $this->assertStringNotContainsString('PAYMENT_PROVIDER_GATEWAY_TOKEN=', $contents);
        $this->assertStringNotContainsString('PAYMENT_REAL_PROVIDER_ENABLED=true', $contents);
    }

    public function test_payment_link_create_uses_fake_when_real_disabled(): void
    {
        config([
            'payments.provider' => 'iyzico',
            'payments.provider_name' => 'iyzico',
            'payments.real_provider_enabled' => false,
        ]);

        $request = $this->technicalServiceRequest();
        $session = $this->mountSessionForRequest($request);
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => app(PaymentProviderManager::class)->providerName(),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 500,
            'currency' => 'TRY',
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'technical_service_request_id' => $request->id,
                'request_code' => $request->mrn,
                'serial_number' => $request->serial_number,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
            ],
        ]);

        app(PaymentProviderManager::class)->createPayment($payment);

        $this->assertSame('fake', $payment->refresh()->provider);
        $this->assertSame(500.0, (float) $payment->amount);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function technicalServiceRequest(array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-GATEWAY-'.uniqid(),
            'root_mrn' => null,
            'customer_name' => 'Gateway Müşteri',
            'customer_phone' => '5550000000',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'Gateway test adres',
            'product_name' => 'Gateway Ürün',
            'serial_number' => 'SN-GATEWAY-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function mountPayment(array $overrides = []): TechnicalServiceMountPayment
    {
        return $this->mountPaymentForRequest($this->technicalServiceRequest(), $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function mountPaymentForRequest(TechnicalServiceRequest $request, array $overrides = []): TechnicalServiceMountPayment
    {
        $session = $this->mountSessionForRequest($request);

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

    private function mountSessionForRequest(TechnicalServiceRequest $request): TechnicalServiceMountSession
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => $request->serial_number,
            'product_name' => $request->product_name,
            'product_model' => $request->product_model,
            'brand' => $request->brand,
        ]);

        return TechnicalServiceMountSession::query()->create([
            'technical_service_qr_link_id' => $link->id,
            'technical_service_request_id' => $request->id,
            'serial_number' => $request->serial_number,
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken(uniqid('gateway-session-', true)),
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
        ]);
    }
}

class RecordingPaymentProviderGatewayClient implements PaymentProviderGatewayClient
{
    public bool $called = false;

    public function send(PaymentProviderGatewayRequest $request): PaymentProviderGatewayResponse
    {
        $this->called = true;

        return PaymentProviderGatewayResponse::fromArray([
            'ok' => true,
            'provider' => 'iyzico',
            'operation' => $request->operation(),
            'provider_token' => 'test-token',
            'payment_url' => 'https://pay.example.test/test-token',
            'provider_response_redacted' => [],
        ]);
    }
}
