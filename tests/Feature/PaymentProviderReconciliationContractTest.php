<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Services\Payments\TechnicalServicePaymentProviderReconciliationService;
use App\Services\TechnicalService\TechnicalServicePaymentOwnershipService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_provider_status_paid_response_marks_paid_once_and_updates_customer_collection(): void
    {
        $request = $this->technicalServiceRequest([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
        ]);
        $payment = $this->mountPaymentForRequest($request, [
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-token',
        ]);
        $service = app(TechnicalServicePaymentProviderReconciliationService::class);

        $service->handleProviderStatusResponse($payment, [
            'ok' => true,
            'provider' => 'iyzico',
            'operation' => 'sync_status',
            'provider_token' => 'iyzico-token',
            'provider_status' => 'paid',
            'provider_response_redacted' => ['status' => 'paid'],
        ]);
        $secondResult = $service->handleProviderStatusResponse($payment->fresh(), [
            'ok' => true,
            'provider' => 'iyzico',
            'operation' => 'sync_status',
            'provider_token' => 'iyzico-token',
            'provider_status' => 'paid',
            'provider_response_redacted' => ['status' => 'paid'],
        ]);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $secondResult->status);
        $this->assertNotNull($secondResult->paid_at);
        $this->assertSame(1, $request->events()->where('event_type', 'mount_payment_paid')->count());

        $summary = app(TechnicalServicePaymentOwnershipService::class)->summary($request->fresh());
        $this->assertSame(1234.5, $summary['company_collected_amount']);
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
     * @param array<string, mixed> $overrides
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
     * @param array<string, mixed> $overrides
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
}
