<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServiceAssignmentSettlementService;
use App\Services\TechnicalService\TechnicalServicePaymentOrderContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TechnicalServicePaymentOrderContextTest extends TestCase
{
    use RefreshDatabase;

    private ?User $testActor = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'services.partner_portal.public_url' => 'https://payments.example.test',
        ]);
    }

    public function test_payment_purposes_include_mount_collection_and_part_charge(): void
    {
        $catalog = collect($this->service()->paymentPurposes())->keyBy('key');

        $this->assertSame('Montaj ücreti tahsilatı', $catalog['mount_collection']['label']);
        $this->assertSame('Parça ödemesi', $catalog['part_charge']['label']);
    }

    public function test_mount_collection_creates_no_shipment_context(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->mountPreview($request);

        $this->assertSame('mount_service', $preview['context_type']);
        $this->assertFalse($preview['shipment_required']);
        $this->assertSame('not_required', $preview['future_carrier_state']);
        $this->assertNull($preview['shipping']);
    }

    public function test_mount_collection_prepares_s_service_order_without_mikro_write(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->mountPreview($request);

        $this->assertSame('S', $preview['desired_mikro_series']);
        $this->assertSame('not_authorized', $preview['future_mikro_write_state']);
        $this->assertSame(0, $preview['mikro_write_execution_count']);
        $this->assertStringContainsString('HİZMET: MONTAJ', $preview['description2_preview']);
        $this->assertStringNotContainsString('Sipariş oluşturuldu', $preview['description2_preview']);
    }

    public function test_part_charge_requires_supplier_decision(): void
    {
        [$request] = $this->requestFixture();

        $this->expectException(ValidationException::class);
        $this->service()->preview($request, 'part_charge', $this->billingInput(), 1000, 'TRY');
    }

    public function test_emaks_supplied_part_requires_mikro_stock_item(): void
    {
        [$request] = $this->requestFixture();

        $this->expectException(ValidationException::class);
        $this->service()->preview($request, 'part_charge', [
            ...$this->billingInput(),
            'part_supplier' => 'emaks_prime',
            'quantity' => 1,
            'shipping_same_as_billing' => true,
        ], 1000, 'TRY');
    }

    public function test_technician_supplied_part_requires_no_mikro_order(): void
    {
        [$request] = $this->requestFixture();
        $this->activateTechnician($request, 'Aktif Usta', 'Denizli', 'Pamukkale');
        $preview = $this->service()->preview($request->fresh(), 'part_charge', [
            ...$this->billingInput(),
            'part_supplier' => 'technician',
            'technician_part_name' => 'Usta kilit gövdesi',
            'quantity' => 1,
        ], 850, 'TRY');

        $this->assertSame('technician_supplied_part', $preview['context_type']);
        $this->assertNull($preview['desired_mikro_series']);
        $this->assertSame('not_required', $preview['future_mikro_write_state']);
        $this->assertFalse($preview['shipment_required']);
        $this->assertSame('pay_technician', $preview['collection_allocation']);
    }

    public function test_billing_party_is_separate_from_shipping_recipient(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->emaksPartPreview($request, [
            'billing_source' => 'manual_billing_draft',
            'billing' => $this->manualBilling(),
            'shipping_same_as_billing' => false,
            'delivery_target' => 'custom_recipient',
            'shipping' => $this->customShipping(),
        ]);

        $this->assertSame('Fatura AŞ', $preview['billing']['name_or_title']);
        $this->assertSame('Sevk Alıcısı', $preview['shipping']['recipient_name']);
        $this->assertNotSame($preview['billing']['address'], $preview['shipping']['address']);
    }

    public function test_shipping_same_as_billing_copies_exact_snapshot(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->emaksPartPreview($request, [
            'billing_source' => 'manual_billing_draft',
            'billing' => $this->manualBilling(),
            'shipping_same_as_billing' => true,
        ]);

        $this->assertSame('billing_address', $preview['delivery_target']);
        $this->assertSame($preview['billing']['name_or_title'], $preview['shipping']['recipient_name']);
        $this->assertSame($preview['billing']['phone'], $preview['shipping']['recipient_phone']);
        $this->assertSame($preview['billing']['address'], $preview['shipping']['address']);
    }

    public function test_technician_delivery_uses_active_technician_address(): void
    {
        [$request] = $this->requestFixture();
        $technician = $this->activateTechnician($request, 'Yeni Usta', 'Denizli', 'Pamukkale');
        $preview = $this->emaksPartPreview($request->fresh(), [
            'shipping_same_as_billing' => false,
            'delivery_target' => 'technician',
        ]);

        $this->assertSame($technician->name, $preview['shipping']['recipient_name']);
        $this->assertSame($technician->address, $preview['shipping']['address']);
        $this->assertSame('Denizli', $preview['shipping']['city']);
        $this->assertSame('Pamukkale', $preview['shipping']['district']);
    }

    public function test_old_technician_address_cannot_leak(): void
    {
        [$request] = $this->requestFixture();
        $this->technician('Eski Usta', 'Ankara', 'Çankaya');
        $active = $this->activateTechnician($request, 'Test Usta', 'Denizli', 'Pamukkale');
        $preview = $this->emaksPartPreview($request->fresh(), [
            'shipping_same_as_billing' => false,
            'delivery_target' => 'technician',
        ]);

        $this->assertSame($active->id, $request->fresh()->technical_service_technician_id);
        $this->assertSame('Denizli', $preview['shipping']['city']);
        $this->assertStringNotContainsString('Ankara', $preview['description2_preview']);
    }

    public function test_mrn_customer_delivery_uses_current_request_address(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->emaksPartPreview($request, [
            'shipping_same_as_billing' => false,
            'delivery_target' => 'mrn_customer',
        ]);

        $this->assertSame($request->customer_name, $preview['shipping']['recipient_name']);
        $this->assertSame($request->service_address, $preview['shipping']['address']);
        $this->assertSame($request->customer_city, $preview['shipping']['city']);
    }

    public function test_custom_recipient_requires_complete_address(): void
    {
        [$request] = $this->requestFixture();

        $this->expectException(ValidationException::class);
        $this->emaksPartPreview($request, [
            'shipping_same_as_billing' => false,
            'delivery_target' => 'custom_recipient',
            'shipping' => ['recipient_name' => 'Eksik Alıcı'],
        ]);
    }

    public function test_related_product_serial_is_always_in_part_context(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->emaksPartPreview($request);

        $this->assertSame($request->serial_number, $preview['related_product_serial']);
        $this->assertNotSame($request->serial_number, $preview['part']['selected_part_serial']);
    }

    public function test_part_serial_is_required_only_for_serial_tracked_item(): void
    {
        [$request] = $this->requestFixture();
        $gateway = $this->stockItem($request, 'Gateway');
        $nonSerial = $this->service()->preview($request, 'part_charge', [
            ...$this->billingInput(),
            'part_supplier' => 'emaks_prime',
            'stock_selection_token' => $gateway['selection_token'],
            'quantity' => 1,
            'shipping_same_as_billing' => true,
        ], 1000, 'TRY');
        $tracked = $this->stockItem($request, 'Akıllı');

        $this->assertFalse($nonSerial['part']['serial_tracking_required']);
        $this->assertNull($nonSerial['part']['selected_part_serial']);

        try {
            $this->service()->preview($request, 'part_charge', [
                ...$this->billingInput(),
                'part_supplier' => 'emaks_prime',
                'stock_selection_token' => $tracked['selection_token'],
                'quantity' => 1,
                'shipping_same_as_billing' => true,
            ], 1000, 'TRY');
            $this->fail('Seri takipli parça seri seçimi olmadan kabul edildi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order_context.selected_part_serial', $exception->errors());
        }

        $serialPreview = $this->service()->preview($request, 'part_charge', [
            ...$this->billingInput(),
            'part_supplier' => 'emaks_prime',
            'stock_selection_token' => $tracked['selection_token'],
            'quantity' => 1,
            'selected_part_serial' => 'TSP-2026-0001',
            'shipping_same_as_billing' => true,
        ], 1000, 'TRY');
        $this->assertSame('TSP-2026-0001', $serialPreview['part']['selected_part_serial']);
    }

    public function test_description2_marks_different_shipping_address(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->emaksPartPreview($request, [
            'shipping_same_as_billing' => false,
            'delivery_target' => 'custom_recipient',
            'shipping' => $this->customShipping(),
        ]);

        $this->assertStringStartsWith('SEVK ADRESİ FARKLIDIR.', $preview['description2_preview']);
        $this->assertStringContainsString('TESLİM TİPİ: FARKLI ALICI', $preview['description2_preview']);
    }

    public function test_description2_contains_related_product_serial(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->emaksPartPreview($request);

        $this->assertStringContainsString('İLGİLİ ÜRÜN SERİ NO: '.$request->serial_number, $preview['description2_preview']);
    }

    public function test_payment_identity_contains_context_hash(): void
    {
        [$request, $session] = $this->requestFixture();
        [$context, $payment] = $this->pendingMountContext($request, $session);

        $this->assertSame($context->context_hash, data_get($payment->raw_payload, 'order_context.context_hash'));
        $this->assertSame($request->id, data_get($payment->raw_payload, 'order_context.request_id'));
        $this->assertSame('mount_collection', data_get($payment->raw_payload, 'purpose'));
    }

    public function test_changed_context_cannot_reuse_pending_payment(): void
    {
        [$request, $session] = $this->requestFixture();
        $this->pendingMountContext($request, $session);
        $changed = $this->service()->preview($request, 'mount_collection', [
            'billing_source' => 'manual_billing_draft',
            'billing' => $this->manualBilling(),
        ], 1200, 'TRY');

        $this->expectException(ValidationException::class);
        $this->service()->prepare($request, 'mount_collection', [
            'billing_source' => 'manual_billing_draft',
            'billing' => $this->manualBilling(),
            'expected_context_hash' => $changed['context_hash'],
            'expected_revision' => $changed['revision'],
        ], 1200, 'TRY', $this->actor(), false);
    }

    public function test_paid_mount_context_waits_for_future_mikro_write(): void
    {
        [$request, $session] = $this->requestFixture();
        [, $payment] = $this->pendingMountContext($request, $session);
        $this->observePaid($payment);

        $this->assertDatabaseHas(TechnicalServicePaymentOrderContextService::TABLE, [
            'technical_service_mount_payment_id' => $payment->id,
            'state' => 'paid_waiting_mikro_write',
            'future_mikro_write_state' => 'not_authorized',
        ]);
        Http::assertNothingSent();
    }

    public function test_paid_part_context_performs_zero_mikro_and_hepsijet_network(): void
    {
        [$request, $session] = $this->requestFixture();
        $preview = $this->emaksPartPreview($request);
        [, $payment] = $this->pendingContext($request, $session, 'part_charge', $preview, [
            ...$this->billingInput(),
            'part_supplier' => 'emaks_prime',
            'stock_selection_token' => $this->stockItem($request, 'Gateway')['selection_token'],
            'quantity' => 1,
            'shipping_same_as_billing' => true,
        ], 1000);
        $this->observePaid($payment);
        $snapshot = data_get($payment->fresh()->raw_payload, 'order_context');

        $this->assertSame(0, $snapshot['mikro_write_execution_count']);
        $this->assertSame(0, $snapshot['carrier_execution_count']);
        $this->assertTrue($snapshot['shipment_required']);
        Http::assertNothingSent();
    }

    public function test_duplicate_paid_observation_creates_no_duplicate_context_or_earning(): void
    {
        [$request, $session] = $this->requestFixture();
        $technician = $this->activateTechnician($request, 'Parça Ustası', 'Denizli', 'Pamukkale');
        $offer = TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 1000,
            'route_fee_amount' => 0,
            'total_amount' => 1000,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'sent_at' => now(),
        ]);
        app(TechnicalServiceAssignmentSettlementService::class)->persistForAssignment(
            $request->fresh(),
            $technician,
            $offer,
            null,
            1000,
            0,
            0,
            $this->actor(),
            TechnicalServiceAssignmentSettlementService::EARNING_PAYMENT_SOURCE_COMPANY,
        );
        $input = [
            ...$this->billingInput(),
            'part_supplier' => 'technician',
            'technician_part_name' => 'Usta göbek parçası',
            'quantity' => 1,
        ];
        $preview = $this->service()->preview($request->fresh(), 'part_charge', $input, 600, 'TRY');
        [, $payment] = $this->pendingContext($request->fresh(), $session, 'part_charge', $preview, $input, 600);

        $this->observePaid($payment);
        $this->observePaid($payment->fresh());

        $this->assertSame(1, DB::table(TechnicalServicePaymentOrderContextService::TABLE)->where('technical_service_mount_payment_id', $payment->id)->count());
        $this->assertSame(1, DB::table('technical_service_earning_payments')->where('reference', 'CUSTOMER-PAYMENT-'.$payment->id)->count());
        $this->assertSame(1, DB::table('technical_service_payment_settlement_allocations')->where('technical_service_mount_payment_id', $payment->id)->count());
    }

    public function test_cancelled_context_cannot_become_future_order_ready(): void
    {
        [$request, $session] = $this->requestFixture();
        [, $payment] = $this->pendingMountContext($request, $session);
        $payment->forceFill(['status' => TechnicalServiceMountPayment::STATUS_CANCELLED])->save();
        $this->service()->markCancelled($payment->fresh());
        $payment->forceFill(['status' => TechnicalServiceMountPayment::STATUS_PAID, 'paid_at' => now()])->save();

        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $this->service()->markPaidWithinTransaction($payment->fresh()));
    }

    public function test_cross_tenant_order_context_access_is_denied(): void
    {
        [$request] = $this->requestFixture();
        [$other] = $this->requestFixture();
        $foreignPart = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $other->id,
            'status' => TechnicalServicePartRequest::STATUS_APPROVED,
            'part_name' => 'Başka talebin parçası',
            'quantity' => 1,
        ]);
        $stock = $this->stockItem($request, 'Gateway');

        $this->expectException(ValidationException::class);
        $this->service()->preview($request, 'part_charge', [
            ...$this->billingInput(),
            'part_supplier' => 'emaks_prime',
            'stock_selection_token' => $stock['selection_token'],
            'quantity' => 1,
            'shipping_same_as_billing' => true,
            'part_request_id' => $foreignPart->id,
        ], 1000, 'TRY');
    }

    public function test_order_context_projection_has_no_n_plus_one(): void
    {
        [$request, $session] = $this->requestFixture();
        $payments = collect(range(1, 5))->map(function (int $index) use ($request, $session): TechnicalServiceMountPayment {
            return TechnicalServiceMountPayment::query()->create([
                'technical_service_mount_session_id' => $session->id,
                'technical_service_request_id' => $request->id,
                'provider' => 'fake',
                'provider_reference' => 'projection-'.$index,
                'status' => TechnicalServiceMountPayment::STATUS_PENDING,
                'amount' => 100 + $index,
                'currency' => 'TRY',
                'raw_payload' => ['order_context' => ['context_hash' => hash('sha256', (string) $index)]],
            ]);
        });

        DB::flushQueryLog();
        DB::enableQueryLog();
        $payments->each(fn (TechnicalServiceMountPayment $payment) => $this->service()->paymentProjection($payment));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries);
    }

    /** @return array{0:TechnicalServiceRequest,1:TechnicalServiceMountSession} */
    private function requestFixture(array $overrides = []): array
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => 'SN-ORDER-'.uniqid(),
            'product_name' => 'Akıllı Kilit',
            'product_model' => 'K1',
            'brand' => 'EMAKS',
        ]);
        ['session' => $session] = TechnicalServiceMountSession::startForLink($link);
        $request = TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-ORDER-'.uniqid(),
            'qr_link_id' => $link->id,
            'mount_session_id' => $session->id,
            'customer_name' => 'Sipariş Müşterisi',
            'customer_phone' => '905551112233',
            'customer_city' => 'Denizli',
            'customer_district' => 'Pamukkale',
            'service_address' => 'Merkez No:21',
            'product_name' => 'Akıllı Kilit',
            'product_model' => 'K1',
            'serial_number' => 'PRODUCT-'.uniqid(),
            'service_type' => 'Montaj',
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ], $overrides));

        return [$request, $session];
    }

    /** @return array<string, mixed> */
    private function billingInput(): array
    {
        return ['billing_source' => 'mrn_customer'];
    }

    /** @return array<string, string|null> */
    private function manualBilling(): array
    {
        return [
            'name_or_title' => 'Fatura AŞ',
            'phone' => '905550001122',
            'email' => 'fatura@example.test',
            'tax_identity' => '1234567890',
            'tax_office' => 'Pamukkale',
            'address' => 'Fatura Caddesi No:1',
            'city' => 'Denizli',
            'district' => 'Merkezefendi',
            'postal_code' => '20000',
        ];
    }

    /** @return array<string, string|null> */
    private function customShipping(): array
    {
        return [
            'recipient_name' => 'Sevk Alıcısı',
            'recipient_phone' => '905550003344',
            'address' => 'Sevk Sokak No:9',
            'city' => 'İzmir',
            'district' => 'Konak',
            'postal_code' => '35000',
        ];
    }

    /** @return array<string, mixed> */
    private function mountPreview(TechnicalServiceRequest $request, float $amount = 1200): array
    {
        return $this->service()->preview($request, 'mount_collection', $this->billingInput(), $amount, 'TRY');
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function emaksPartPreview(TechnicalServiceRequest $request, array $overrides = [], float $amount = 1000): array
    {
        $stock = $this->stockItem($request, 'Gateway');

        return $this->service()->preview($request, 'part_charge', array_replace([
            ...$this->billingInput(),
            'part_supplier' => 'emaks_prime',
            'stock_selection_token' => $stock['selection_token'],
            'quantity' => 1,
            'shipping_same_as_billing' => true,
        ], $overrides), $amount, 'TRY');
    }

    /** @return array<string, mixed> */
    private function stockItem(TechnicalServiceRequest $request, string $query): array
    {
        $result = $this->service()->searchParts($request, $query);
        $this->assertSame('Test verisi', $result['source_label']);
        $this->assertNotEmpty($result['items']);

        return $result['items'][0];
    }

    /** @return array{0:object,1:TechnicalServiceMountPayment} */
    private function pendingMountContext(TechnicalServiceRequest $request, TechnicalServiceMountSession $session): array
    {
        $preview = $this->mountPreview($request);

        return $this->pendingContext($request, $session, 'mount_collection', $preview, $this->billingInput(), 1200);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $input
     * @return array{0:object,1:TechnicalServiceMountPayment}
     */
    private function pendingContext(
        TechnicalServiceRequest $request,
        TechnicalServiceMountSession $session,
        string $purpose,
        array $preview,
        array $input,
        float $amount,
    ): array {
        $prepared = $this->service()->prepare($request, $purpose, [
            ...$input,
            'expected_context_hash' => $preview['context_hash'],
            'expected_revision' => $preview['revision'],
        ], $amount, 'TRY', $this->actor(), false);
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'order-context-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => $amount,
            'currency' => 'TRY',
            'raw_payload' => [
                'source' => 'operation_order_context_payment',
                'purpose' => $purpose,
                'total_amount' => $amount,
            ],
        ]);
        $payment = $this->service()->attachPayment($prepared['context'], $payment);

        return [$prepared['context'], $payment];
    }

    private function observePaid(TechnicalServiceMountPayment $payment): void
    {
        $payment->forceFill([
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'paid_at' => $payment->paid_at ?? now(),
        ])->save();
        DB::transaction(fn () => $this->service()->markPaidWithinTransaction($payment->fresh()));
    }

    private function activateTechnician(TechnicalServiceRequest $request, string $name, string $city, string $district): TechnicalServiceTechnician
    {
        $technician = $this->technician($name, $city, $district);
        $request->forceFill([
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ])->save();

        return $technician;
    }

    private function technician(string $name, string $city, string $district): TechnicalServiceTechnician
    {
        return TechnicalServiceTechnician::query()->create([
            'name' => $name,
            'phone' => '90555'.random_int(1000000, 9999999),
            'phone_e164' => '+90555'.random_int(1000000, 9999999),
            'address' => $district.' Usta Adresi No:1',
            'city' => $city,
            'district' => $district,
            'active' => true,
        ]);
    }

    private function actor(): User
    {
        return $this->testActor ??= User::factory()->create(['role_code' => 'admin']);
    }

    private function service(): TechnicalServicePaymentOrderContextService
    {
        return app(TechnicalServicePaymentOrderContextService::class);
    }
}
