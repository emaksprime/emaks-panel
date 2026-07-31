<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Services\TechnicalService\MountFlowDecisionService;
use App\Services\TechnicalService\MountRequestSubmitService;
use App\Services\TechnicalService\MountSessionEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TechnicalServiceQrMountFlowV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_pre_sale_qr_link_only_keeps_product_context_and_hashed_token(): void
    {
        ['link' => $link, 'token' => $token] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => 'PRE-SALE-001',
            'product_name' => 'Emaks Prime Akıllı Kilit',
            'product_model' => 'DDL720',
            'brand' => 'EMAKS PRIME',
        ], 'plain-local-token');

        $this->assertSame('plain-local-token', $token);
        $this->assertSame(hash('sha256', 'plain-local-token'), $link->token_hash);
        $this->assertSame(TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT, $link->link_type);
        $this->assertSame('PRE-SALE-001', $link->serial_number);
        $this->assertSame('Emaks Prime Akıllı Kilit', $link->product_name);
        $this->assertSame('DDL720', $link->product_model);
        $this->assertSame('EMAKS PRIME', $link->brand);
        $this->assertNotContains('invoice_series', Schema::getColumnListing('technical_service_qr_links'));
        $this->assertNotContains('customer_name', Schema::getColumnListing('technical_service_qr_links'));
    }

    public function test_montaj_dahil_session_decision_opens_form(): void
    {
        $session = $this->mountSession([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
        ]);

        $decision = app(MountFlowDecisionService::class)->decide($session);

        $this->assertSame(MountFlowDecisionService::DECISION_SHOW_FORM, $decision['decision']);
        $session->refresh();
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_NOT_REQUIRED, $session->mount_payment_status);
        $this->assertSame(TechnicalServiceMountSession::ENTRY_INCLUDED_MOUNT, $session->customer_entry_mode);
    }

    public function test_montaj_sonradan_dahil_session_decision_opens_form(): void
    {
        $session = $this->mountSession([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL,
        ]);

        $decision = app(MountFlowDecisionService::class)->decide($session);

        $this->assertSame(MountFlowDecisionService::DECISION_SHOW_FORM, $decision['decision']);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_NOT_REQUIRED, $session->fresh()->mount_payment_status);
    }

    public function test_montaj_haric_unpaid_session_decision_shows_payment(): void
    {
        $session = $this->mountSession([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
        ]);

        $decision = app(MountFlowDecisionService::class)->decide($session);

        $this->assertSame(MountFlowDecisionService::DECISION_SHOW_PAYMENT, $decision['decision']);
        $session->refresh();
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PENDING, $session->mount_payment_status);
        $this->assertSame(TechnicalServiceMountSession::ENTRY_SINGLE_PRODUCT, $session->customer_entry_mode);
    }

    public function test_montaj_haric_paid_session_decision_opens_form(): void
    {
        $session = $this->mountSession([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
        ]);

        TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-paid-1',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3500,
            'currency' => 'TRY',
            'paid_at' => now(),
        ]);

        $decision = app(MountFlowDecisionService::class)->decide($session);

        $this->assertSame(MountFlowDecisionService::DECISION_SHOW_FORM, $decision['decision']);
        $session->refresh();
        $this->assertSame(TechnicalServiceMountSession::SALE_MONTAJ_HARIC, $session->sale_mount_status);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PAID, $session->mount_payment_status);
        $this->assertSame(TechnicalServiceMountSession::ENTRY_PAID_SINGLE_PRODUCT, $session->customer_entry_mode);
    }

    public function test_montaj_haric_multi_product_without_payment_opens_multi_form(): void
    {
        $session = $this->mountSession([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT,
        ]);

        $decision = app(MountFlowDecisionService::class)->decide($session);

        $this->assertSame(MountFlowDecisionService::DECISION_SHOW_MULTI_PRODUCT_FORM_WITHOUT_PAYMENT, $decision['decision']);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT, $session->fresh()->mount_payment_status);
    }

    public function test_multi_product_without_payment_submit_adds_operation_warning(): void
    {
        $session = $this->mountSession([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
            'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT,
        ]);

        $request = app(MountRequestSubmitService::class)->submit($session, [
            'customer_name' => 'Burhan Test',
        ]);

        $this->assertStringContainsString(MountRequestSubmitService::MULTI_PRODUCT_OPERATION_WARNING, $request->description);
        $this->assertSame(TechnicalServiceRequest::STATUS_NEW, $request->status);
        $this->assertSame(TechnicalServiceRequest::WORKFLOW_NEW_REQUEST, $request->workflow_status);
    }

    public function test_public_form_submit_service_creates_yeni_yeni_talep_request(): void
    {
        $session = $this->mountSession([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_NOT_REQUIRED,
            'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_INCLUDED_MOUNT,
        ]);

        $request = app(MountRequestSubmitService::class)->submit($session, [
            'customer_name' => 'Test Müşteri',
            'customer_phone' => '+905551112233',
            'customer_city' => 'İstanbul',
            'customer_district' => 'Kadıköy',
            'service_address' => 'Test adres',
        ]);

        $this->assertSame(TechnicalServiceRequest::STATUS_NEW, $request->status);
        $this->assertSame(TechnicalServiceRequest::WORKFLOW_NEW_REQUEST, $request->workflow_status);
        $this->assertSame(TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM, $request->source_channel);
        $this->assertSame(TechnicalServiceRequest::PRIORITY_MEDIUM, $request->priority);
        $this->assertSame(TechnicalServiceRequest::RISK_MEDIUM, $request->risk_level);
        $this->assertNotSame('İnceleniyor', $request->status);
        $this->assertNotSame('İnceleniyor', $request->workflow_status);
        $this->assertCount(1, $request->requestSerials);
        $this->assertTrue($request->requestSerials->first()->is_primary);
    }

    public function test_check_timeout_does_not_block_request_and_sets_retry_ready_state(): void
    {
        $session = $this->mountSession();

        $session = app(MountSessionEnrichmentService::class)
            ->markCheckTimeout($session, 'external check timeout');

        $decision = app(MountFlowDecisionService::class)->decide($session);
        $this->assertSame(TechnicalServiceMountSession::SALE_CHECK_FAILED, $session->sale_mount_status);
        $this->assertSame(TechnicalServiceMountSession::DECISION_CHECK_TIMEOUT, $session->decision_status);
        $this->assertSame(1, $session->check_attempt_count);
        $this->assertSame('external check timeout', $session->check_error);

        $request = app(MountRequestSubmitService::class)->submit($session);

        $this->assertSame(MountFlowDecisionService::DECISION_SHOW_CHECK_FAILED_BUT_ALLOW_SUBMIT, $decision['decision']);
        $this->assertSame(TechnicalServiceMountSession::DECISION_SUBMITTED, $session->fresh()->decision_status);
        $this->assertSame(TechnicalServiceRequest::STATUS_NEW, $request->status);
        $this->assertSame(TechnicalServiceRequest::WORKFLOW_NEW_REQUEST, $request->workflow_status);
        $this->assertStringContainsString(MountRequestSubmitService::CHECK_PENDING_WARNING, $request->description);
    }

    public function test_sale_mount_status_and_mount_payment_status_are_separate_state_fields(): void
    {
        $session = $this->mountSession([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
        ]);

        $decision = app(MountFlowDecisionService::class)->decide($session);

        $this->assertSame(MountFlowDecisionService::DECISION_SHOW_FORM, $decision['decision']);
        $session->refresh();
        $this->assertSame(TechnicalServiceMountSession::SALE_MONTAJ_HARIC, $session->sale_mount_status);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PAID, $session->mount_payment_status);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function mountSession(array $overrides = []): TechnicalServiceMountSession
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => $overrides['serial_number'] ?? 'QR-V2-SERIAL-001',
            'product_name' => 'Emaks Prime Kilit',
            'product_model' => 'DDL720',
            'brand' => 'EMAKS PRIME',
        ]);
        ['session' => $session] = TechnicalServiceMountSession::startForLink($link);

        $session->forceFill($overrides)->save();

        return $session->fresh();
    }
}
