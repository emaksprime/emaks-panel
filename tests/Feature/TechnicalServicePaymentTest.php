<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicalServicePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_mount_amount_is_used_as_customer_collection(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 0,
        ]);
        $session = $this->mountSessionForRequest($request);

        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-total',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3500,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => ['source' => 'public_form_payment'],
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame(3500.0, $payload['sale_and_payment']['paid_amount']);
        $this->assertSame(3500.0, $payload['sale_and_payment']['payment_summary']['mount']['amount']);
        $this->assertSame(3500.0, $payload['sale_and_payment']['payment_summary']['total_customer_collection']);
        $this->assertSame(3500.0, $payload['total_customer_collected']);
        $this->assertSame(500.0, $payload['cost_delta']);
    }

    public function test_default_mount_amount_does_not_override_paid_amount(): void
    {
        $request = $this->technicalServiceRequest([
            'service_type' => 'Montaj',
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
        ]);
        $session = $this->mountSessionForRequest($request);

        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-3500',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3500,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => ['source' => 'public_form_payment'],
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame(3500.0, $payload['sale_and_payment']['paid_amount']);
        $this->assertNotSame(3000.0, $payload['sale_and_payment']['paid_amount']);
    }

    public function test_missing_paid_payment_falls_back_safely_without_fake_collection(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'service_type' => 'Montaj',
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 120,
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertNull($payload['sale_and_payment']['paid_amount']);
        $this->assertNull($payload['sale_and_payment']['payment_summary']['mount']['amount']);
        $this->assertNull($payload['sale_and_payment']['payment_summary']['total_customer_collection']);
        $this->assertNull($payload['total_customer_collected']);
        $this->assertNull($payload['cost_delta']);
        $this->assertSame(3000.0, $payload['customer_fee']);
    }

    public function test_payment_summary_keeps_reference_and_paid_at(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'mount_payment_provider' => 'fake',
            'mount_payment_reference' => 'fake-paid-3500',
            'mount_payment_paid_at' => now(),
        ]);
        $session = $this->mountSessionForRequest($request);

        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-3500',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3500,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => ['source' => 'public_form_payment'],
        ]);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->fresh(), true);

        $this->assertSame('fake-paid-3500', $payload['sale_and_payment']['payment_reference']);
        $this->assertNotNull($payload['sale_and_payment']['payment_paid_at']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function technicalServiceRequest(array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-PAYMENT-'.uniqid(),
            'customer_name' => 'Test Müşteri',
            'customer_phone' => '5550000000',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'Test adres',
            'product_name' => 'Test Ürün',
            'serial_number' => 'SN-PAYMENT-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
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
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken(uniqid('payment-session-', true)),
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
        ]);
    }
}
