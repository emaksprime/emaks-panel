<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Services\Payments\DirectIyzicoLinkProviderClient;
use App\Services\Payments\IyzicoIyzwsV2Signer;
use App\Services\Payments\IyzicoLinkRequestFactory;
use App\Services\Payments\PaymentProviderGatewayClient;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\TechnicalServicePaymentProviderClientException;
use App\Services\Payments\TechnicalServicePaymentProviderCredentialService;
use App\Services\Payments\TechnicalServicePaymentProviderDisabledException;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DirectIyzicoPaymentProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_iyzico_provider_transport_defaults_to_direct_laravel(): void
    {
        $this->assertInstanceOf(DirectIyzicoLinkProviderClient::class, app(PaymentProviderGatewayClient::class));
    }

    public function test_iyzws_v2_signer_generates_authorization_header_shape(): void
    {
        $headers = app(IyzicoIyzwsV2Signer::class)->headers(
            'test-api-key',
            'test-secret-key',
            '/v2/iyzilink/products',
            '{"price":"1.00"}',
        );

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('IYZWSv2 ', $headers['Authorization']);
        $this->assertNotSame('', $headers['x-iyzi-rnd']);

        $decoded = base64_decode(substr($headers['Authorization'], strlen('IYZWSv2 ')), true);
        $this->assertIsString($decoded);
        $this->assertStringContainsString('apiKey:test-api-key', $decoded);
        $this->assertStringContainsString('randomKey:', $decoded);
        $this->assertStringContainsString('signature:', $decoded);
        $this->assertStringNotContainsString('test-secret-key', $decoded);
    }

    public function test_create_link_body_contains_name_description_price_currency_image_and_no_secret(): void
    {
        $body = app(IyzicoLinkRequestFactory::class)->linkBody([
            'request_code' => 'MRN-DIRECT-1',
            'serial_no' => 'SERIAL-DIRECT-1',
            'amount' => '123.4',
            'currency' => 'TRY',
            'description' => 'EMAKS Teknik Servis - MRN MRN-DIRECT-1 - Seri SERIAL-DIRECT-1',
            'api_key' => 'api-key-should-not-appear',
            'secret_key' => 'secret-should-not-appear',
        ]);
        $encoded = json_encode($body, JSON_THROW_ON_ERROR);

        $this->assertSame('EMAKS Teknik Servis', $body['name']);
        $this->assertStringContainsString('MRN-DIRECT-1', $body['description']);
        $this->assertStringContainsString('SERIAL-DIRECT-1', $body['description']);
        $this->assertSame('123.40', $body['price']);
        $this->assertSame('TRY', $body['currencyCode']);
        $this->assertNotEmpty($body['encodedImageFile']);
        $this->assertStringNotContainsString('api-key-should-not-appear', $encoded);
        $this->assertStringNotContainsString('secret-should-not-appear', $encoded);
    }

    public function test_direct_iyzico_create_link_calls_sandbox_base_and_does_not_mark_paid(): void
    {
        $this->configureDirectSandbox();
        Http::fake([
            'https://sandbox-api.iyzipay.com/v2/iyzilink/products' => Http::response([
                'status' => 'success',
                'conversationId' => 'payment:1',
                'data' => [
                    'token' => 'sandbox-token-123',
                    'url' => 'https://sandbox-payment.iyzipay.com/pay/sandbox-token-123',
                    'status' => 'ACTIVE',
                ],
            ], 200),
            'https://api.iyzipay.com/*' => Http::response(['status' => 'failure'], 500),
        ]);

        $payment = $this->mountPayment(['provider' => 'iyzico']);

        app(PaymentProviderManager::class)->createPayment($payment);

        $payment->refresh();
        $this->assertSame('iyzico', $payment->provider);
        $this->assertSame('sandbox-token-123', $payment->provider_reference);
        $this->assertSame('https://sandbox-payment.iyzipay.com/pay/sandbox-token-123', $payment->payment_url);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);
        $this->assertNull($payment->paid_at);

        $payload = json_encode($payment->raw_payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('TEST_SANDBOX_API_KEY_DIRECT', $payload);
        $this->assertStringNotContainsString('TEST_SANDBOX_SECRET_DIRECT', $payload);
        $this->assertStringNotContainsString('IYZWSv2 ', $payload);

        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $request->method() === 'POST'
                && $request->url() === 'https://sandbox-api.iyzipay.com/v2/iyzilink/products'
                && str_starts_with((string) $request->header('Authorization')[0], 'IYZWSv2 ')
                && $body['currencyCode'] === 'TRY'
                && $body['price'] === '1234.50'
                && str_contains((string) $body['description'], 'MRN');
        });
    }

    public function test_live_call_blocked_without_live_approval_and_no_http_sent(): void
    {
        $this->configureDirectSandbox([
            'payments.gateway.mode' => 'live',
            'payments.iyzico.live_send_approved' => false,
        ], 'live');
        Http::fake();

        $this->expectException(TechnicalServicePaymentProviderDisabledException::class);
        $this->expectExceptionMessage(TechnicalServicePaymentProviderSettingsService::LIVE_SEND_APPROVAL_MESSAGE);

        try {
            app(PaymentProviderManager::class)->createPayment($this->mountPayment(['provider' => 'iyzico']));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_direct_iyzico_sandbox_base_url_is_strict_even_when_config_is_overridden(): void
    {
        $this->configureDirectSandbox([
            'payments.iyzico.sandbox_base_url' => 'https://evil.example.test',
        ]);
        Http::fake([
            'https://sandbox-api.iyzipay.com/v2/iyzilink/products' => Http::response([
                'status' => 'success',
                'data' => [
                    'token' => 'sandbox-strict-token',
                    'url' => 'https://sandbox-payment.iyzipay.com/pay/sandbox-strict-token',
                    'status' => 'ACTIVE',
                ],
            ], 200),
            'https://evil.example.test/*' => Http::response(['status' => 'failure'], 500),
            'https://api.iyzipay.com/*' => Http::response(['status' => 'failure'], 500),
        ]);

        app(PaymentProviderManager::class)->createPayment($this->mountPayment(['provider' => 'iyzico']));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox-api.iyzipay.com/v2/iyzilink/products');
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://evil.example.test'));
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.iyzipay.com'));
    }

    public function test_direct_iyzico_live_base_url_is_strict_and_never_uses_sandbox_when_live_is_approved(): void
    {
        $this->configureDirectSandbox([
            'payments.gateway.mode' => 'live',
            'payments.iyzico.live_send_approved' => true,
            'payments.iyzico.live_base_url' => 'https://evil.example.test',
            'payments.iyzico.ip_whitelist_confirmed' => true,
            'services.public_urls.payment_base_url' => 'https://dashboard.emaksprime.com.tr',
        ], 'live');
        Route::post('/test/iyzico-callback', fn () => response()->json(['ok' => true]))
            ->name('mount-payment.callback');
        Route::getRoutes()->refreshNameLookups();
        Http::fake([
            'https://api.iyzipay.com/v2/iyzilink/products' => Http::response([
                'status' => 'success',
                'data' => [
                    'token' => 'live-strict-token',
                    'url' => 'https://payment.iyzipay.com/pay/live-strict-token',
                    'status' => 'ACTIVE',
                ],
            ], 200),
            'https://sandbox-api.iyzipay.com/*' => Http::response(['status' => 'failure'], 500),
            'https://evil.example.test/*' => Http::response(['status' => 'failure'], 500),
        ]);

        app(PaymentProviderManager::class)->createPayment($this->mountPayment(['provider' => 'iyzico']));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.iyzipay.com/v2/iyzilink/products');
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://sandbox-api.iyzipay.com'));
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://evil.example.test'));
    }

    public function test_cancel_link_calls_passive_endpoint_and_marks_cancelled_not_paid(): void
    {
        $this->configureDirectSandbox();
        Http::fake([
            'https://sandbox-api.iyzipay.com/v2/iyzilink/products/sandbox-token-123/status/PASSIVE' => Http::response([
                'status' => 'success',
                'data' => [
                    'token' => 'sandbox-token-123',
                    'status' => 'PASSIVE',
                ],
            ], 200),
        ]);
        $payment = $this->mountPayment([
            'provider' => 'iyzico',
            'provider_reference' => 'sandbox-token-123',
            'payment_url' => 'https://sandbox-payment.iyzipay.com/pay/sandbox-token-123',
        ]);

        app(PaymentProviderManager::class)->cancelPayment($payment);

        $payment->refresh();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_CANCELLED, $payment->status);
        $this->assertNull($payment->paid_at);

        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://sandbox-api.iyzipay.com/v2/iyzilink/products/sandbox-token-123/status/PASSIVE');
    }

    public function test_direct_provider_failure_surfaces_turkish_error_without_fake_fallback(): void
    {
        $this->configureDirectSandbox();
        Http::fake([
            'https://sandbox-api.iyzipay.com/v2/iyzilink/products' => Http::response([
                'status' => 'failure',
                'errorCode' => 'sandbox_error',
                'errorMessage' => 'Iyzico sandbox link oluşturamadı.',
            ], 422),
        ]);
        $payment = $this->mountPayment(['provider' => 'iyzico']);

        $this->expectException(TechnicalServicePaymentProviderClientException::class);
        $this->expectExceptionMessage('Iyzico sandbox link oluşturamadı.');

        try {
            app(PaymentProviderManager::class)->createPayment($payment);
        } finally {
            $payment->refresh();
            $this->assertSame('iyzico', $payment->provider);
            $this->assertNull($payment->payment_url);
            $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function configureDirectSandbox(array $overrides = [], string $credentialMode = 'sandbox'): void
    {
        config(array_merge([
            'payments.real_provider_enabled' => true,
            'payments.provider_name' => 'iyzico',
            'payments.provider_transport' => 'direct_laravel',
            'payments.gateway.mode' => $credentialMode,
            'payments.iyzico.live_send_approved' => false,
        ], $overrides));

        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials(
                $credentialMode,
                $credentialMode === 'live' ? 'TEST_LIVE_API_KEY_DIRECT' : 'TEST_SANDBOX_API_KEY_DIRECT',
                $credentialMode === 'live' ? 'TEST_LIVE_SECRET_DIRECT' : 'TEST_SANDBOX_SECRET_DIRECT',
            );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function mountPayment(array $overrides = []): TechnicalServiceMountPayment
    {
        $request = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-DIRECT-'.uniqid(),
            'root_mrn' => null,
            'customer_name' => 'Direct Iyzico Müşteri',
            'customer_phone' => '5550000000',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'Direct provider test adres',
            'product_name' => 'Direct Provider Ürün',
            'serial_number' => 'SN-DIRECT-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ]);
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
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken(uniqid('direct-session-', true)),
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
}
