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
use App\Services\Mikro\MikroApiClient;
use App\Services\TechnicalService\TechnicalServiceAssignmentSettlementService;
use App\Services\TechnicalService\TechnicalServicePaymentOrderContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
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
            'services.technical_service.payment_order_context_test_stock' => true,
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
            'commercial_mode' => 'paid',
            'delivery_mode' => 'shipment',
            'lines' => [[
                'stock_selection_token' => $gateway['selection_token'],
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
            'shipping_same_as_billing' => true,
        ], 1000, 'TRY');
        $tracked = $this->stockItem($request, 'Akıllı');

        $this->assertFalse($nonSerial['part']['serial_tracking_required']);
        $this->assertNull($nonSerial['part']['selected_part_serial']);

        $trackedDraft = $this->service()->preview($request, 'part_charge', [
            ...$this->billingInput(),
            'part_supplier' => 'emaks_prime',
            'commercial_mode' => 'paid',
            'delivery_mode' => 'shipment',
            'lines' => [[
                'stock_selection_token' => $tracked['selection_token'],
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
            'shipping_same_as_billing' => true,
        ], 1000, 'TRY');
        $this->assertFalse($trackedDraft['readiness']['ready']);
        $this->assertContains('part_serial_selection_unverified', $trackedDraft['readiness']['blocker_codes']);

        $serialPreview = $this->service()->preview($request, 'part_charge', [
            ...$this->billingInput(),
            'part_supplier' => 'emaks_prime',
            'commercial_mode' => 'paid',
            'delivery_mode' => 'shipment',
            'lines' => [[
                'stock_selection_token' => $tracked['selection_token'],
                'quantity' => 1,
                'unit_price' => 1000,
                'selected_part_serial' => 'TSP-2026-0001',
            ]],
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

    public function test_changed_context_cannot_reuse_pending_link(): void
    {
        [$request, $session] = $this->requestFixture();
        [, $payment] = $this->pendingMountContext($request, $session);
        $changed = $this->service()->preview($request, 'mount_collection', [
            'billing_source' => 'manual_billing_draft',
            'billing' => $this->manualBilling(),
        ], 1200, 'TRY', 'fake', 'local');

        $this->assertSame('fresh_link_required', $changed['payment_retry']['state']);
        $this->assertFalse($changed['payment_retry']['reason_required']);
        $this->assertSame($payment->id, $changed['payment_retry']['supersede_payment_id']);
        $this->assertSame(1, $changed['payment_retry']['authoritative_counts']['pending']);

        $this->expectException(ValidationException::class);
        $this->service()->prepare($request, 'mount_collection', [
            'billing_source' => 'manual_billing_draft',
            'billing' => $this->manualBilling(),
            'expected_context_hash' => $changed['context_hash'],
            'expected_revision' => $changed['revision'],
        ], 1200, 'TRY', $this->actor(), false, null, 'fake', 'local');
    }

    public function test_changed_context_explicitly_supersedes_pending_and_creates_one_fresh_link(): void
    {
        [$request, $session] = $this->requestFixture();
        [, $oldPayment] = $this->pendingMountContext($request, $session);
        $input = [
            'billing_source' => 'manual_billing_draft',
            'billing' => $this->manualBilling(),
        ];
        $preview = $this->service()->preview($request, 'mount_collection', $input, 1200, 'TRY', 'fake', 'local');
        $payload = [
            'amount' => '1200.00',
            'currency' => 'TRY',
            'reason' => 'mount_collection',
            'purpose' => 'mount_collection',
            'fresh_payment_requested' => true,
            'order_context' => [
                ...$input,
                'expected_context_hash' => $preview['context_hash'],
                'expected_revision' => $preview['revision'],
            ],
        ];

        $this->actingAs($this->actor())
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", $payload)
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('payment.status', TechnicalServiceMountPayment::STATUS_PENDING);
        $oldPayment->refresh();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_CANCELLED, $oldPayment->status);
        $this->assertSame('order_context_superseded', data_get($oldPayment->raw_payload, 'cancel_source'));
        $this->assertSame(2, TechnicalServiceMountPayment::query()->where('technical_service_request_id', $request->id)->count());

        $this->actingAs($this->actor())
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->assertSame(2, TechnicalServiceMountPayment::query()->where('technical_service_request_id', $request->id)->count());
        $this->assertSame(1, TechnicalServiceMountPayment::query()
            ->where('technical_service_request_id', $request->id)
            ->where('status', TechnicalServiceMountPayment::STATUS_PENDING)
            ->count());
    }

    public function test_unsafe_pending_session_can_be_superseded_once(): void
    {
        [$request, $session] = $this->requestFixture();
        [, $oldPayment] = $this->pendingMountContext($request, $session);
        $oldPayment->forceFill(['payment_url' => null])->save();
        $preview = $this->service()->preview($request, 'mount_collection', $this->billingInput(), 1200, 'TRY', 'fake', 'local');

        $this->assertSame('fresh_link_required', $preview['payment_retry']['state']);
        $this->assertTrue($preview['payment_retry']['reason_required']);
        $this->assertSame($oldPayment->id, $preview['payment_retry']['supersede_payment_id']);

        $payload = [
            'amount' => '1200.00',
            'currency' => 'TRY',
            'reason' => 'mount_collection',
            'purpose' => 'mount_collection',
            'fresh_payment_requested' => true,
            'terminal_retry_reason' => 'Eski sağlayıcı oturumu güncel yerel profile ait değil.',
            'order_context' => [
                ...$this->billingInput(),
                'expected_context_hash' => $preview['context_hash'],
                'expected_revision' => $preview['revision'],
            ],
        ];

        $this->actingAs($this->actor())
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", $payload)
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('payment.status', TechnicalServiceMountPayment::STATUS_PENDING);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_CANCELLED, $oldPayment->fresh()->status);
        $this->assertSame(2, TechnicalServiceMountPayment::query()->where('technical_service_request_id', $request->id)->count());
        $this->assertSame(2, DB::table(TechnicalServicePaymentOrderContextService::TABLE)
            ->where('technical_service_request_id', $request->id)
            ->where('payment_purpose', 'mount_collection')
            ->count());

        $this->actingAs($this->actor())
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->assertSame(2, TechnicalServiceMountPayment::query()->where('technical_service_request_id', $request->id)->count());
    }

    public function test_terminal_payment_allows_explicit_fresh_retry(): void
    {
        [$request, $session] = $this->requestFixture();
        [$context, $oldPayment] = $this->pendingMountContext($request, $session);
        $oldPayment->forceFill(['status' => TechnicalServiceMountPayment::STATUS_FAILED])->save();
        $this->service()->releaseFailedPayment((int) $context->id, $oldPayment->fresh());
        $preview = $this->service()->preview($request, 'mount_collection', $this->billingInput(), 1200, 'TRY', 'fake', 'local');

        $this->assertSame('fresh_link_required', $preview['payment_retry']['state']);
        $this->assertTrue($preview['payment_retry']['reason_required']);
        $this->assertSame(1, $preview['payment_retry']['authoritative_counts']['failed']);

        $this->actingAs($this->actor())
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", [
                'amount' => '1200.00',
                'currency' => 'TRY',
                'reason' => 'mount_collection',
                'purpose' => 'mount_collection',
                'fresh_payment_requested' => true,
                'terminal_retry_reason' => 'Önceki sağlayıcı oturumu başarısız tamamlandı.',
                'order_context' => [
                    ...$this->billingInput(),
                    'expected_context_hash' => $preview['context_hash'],
                    'expected_revision' => $preview['revision'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('payment.status', TechnicalServiceMountPayment::STATUS_PENDING);

        $this->assertSame(TechnicalServiceMountPayment::STATUS_FAILED, $oldPayment->fresh()->status);
        $this->assertSame(2, TechnicalServiceMountPayment::query()->where('technical_service_request_id', $request->id)->count());
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
        $input = $this->emaksPartInput($request);
        $preview = $this->service()->preview($request, 'part_charge', $input, 1000, 'TRY');
        [, $payment] = $this->pendingContext($request, $session, 'part_charge', $preview, $input, 1000);
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
            'commercial_mode' => 'paid',
            'delivery_mode' => 'shipment',
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

    public function test_individual_billing_requires_first_and_last_name(): void
    {
        [$request] = $this->requestFixture();

        try {
            $this->mountPreview($request, 1200, [
                'billing_source' => 'manual_billing_draft',
                'billing' => [...$this->individualBilling(), 'last_name' => ''],
            ]);
            $this->fail('Soyadı olmayan kişi faturası kabul edildi.');
        } catch (ValidationException $exception) {
            $this->assertSame('Soyad alanı zorunludur.', $exception->errors()['order_context.billing.last_name'][0]);
        }
    }

    public function test_individual_billing_projects_full_name(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->mountPreview($request, 1200, [
            'billing_source' => 'manual_billing_draft',
            'billing' => $this->individualBilling(),
        ]);

        $this->assertSame('Ahmet Aslan', $preview['billing']['name_or_title']);
        $this->assertStringContainsString('FATURA MÜŞTERİSİ: Ahmet Aslan', $preview['description2_preview']);
    }

    public function test_company_billing_uses_legal_title(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->mountPreview($request, 1200, [
            'billing_source' => 'manual_billing_draft',
            'billing' => $this->manualBilling(),
        ]);

        $this->assertSame('company', $preview['billing']['billing_type']);
        $this->assertSame('Fatura AŞ', $preview['billing']['legal_title']);
        $this->assertSame('Fatura AŞ', $preview['billing']['name_or_title']);
    }

    public function test_billing_phone_rejects_letters(): void
    {
        [$request] = $this->requestFixture();

        $this->expectException(ValidationException::class);
        $this->mountPreview($request, 1200, [
            'billing_source' => 'manual_billing_draft',
            'billing' => [...$this->individualBilling(), 'phone' => 'Aslan'],
        ]);
    }

    public function test_billing_city_and_district_are_not_reversed(): void
    {
        [$request] = $this->requestFixture();

        try {
            $this->mountPreview($request, 1200, [
                'billing_source' => 'manual_billing_draft',
                'billing' => [...$this->individualBilling(), 'city' => 'Esenyurt', 'district' => 'İstanbul'],
            ]);
            $this->fail('Ters il/ilçe kabul edildi.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Türkiye ili', $exception->errors()['order_context'][0]);
        }
    }

    public function test_normal_runtime_never_returns_test_part_fixture(): void
    {
        [$request] = $this->requestFixture();
        config(['services.technical_service.payment_order_context_test_stock' => false]);
        $mikro = Mockery::mock(MikroApiClient::class);
        $mikro->shouldReceive('searchStocks')->once()->andReturn(['success' => false, 'error_code' => 'MIKRO_RESPONSE_SCHEMA_UNVERIFIED']);
        $service = new TechnicalServicePaymentOrderContextService($mikro, app(TechnicalServiceAssignmentSettlementService::class));

        try {
            $service->searchParts($request, 'Gateway');
            $this->fail('Normal runtime sahte stok döndürdü.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Gerçek stok doğrulanmadan', $exception->errors()['query'][0]);
            $this->assertStringNotContainsString('TS-PART-001', $exception->errors()['query'][0]);
        }
    }

    public function test_part_search_uses_typed_mikro_stock_list(): void
    {
        [$request] = $this->requestFixture();
        config(['services.technical_service.payment_order_context_test_stock' => false]);
        $mikro = Mockery::mock(MikroApiClient::class);
        $mikro->shouldReceive('searchStocks')->once()->andReturn([
            'success' => true,
            'freshness_at' => '2026-08-14T12:00:00+03:00',
            'stale' => false,
            'data' => [[
                'item_code' => 'GERCEK-001',
                'item_name' => 'Gerçek Parça',
                'item_short_name' => 'Parça',
                'unit_code' => 'ADET',
                'stock_type' => 8,
                'detail_tracking_type' => 0,
            ]],
        ]);
        $mikro->shouldReceive('physicalStockQuantities')->once()->with(['GERCEK-001'])->andReturn(
            $this->physicalStockResponse(['GERCEK-001'], '4.000000', '2.000000'),
        );
        $service = new TechnicalServicePaymentOrderContextService($mikro, app(TechnicalServiceAssignmentSettlementService::class));
        $result = $service->searchParts($request, 'GERCEK');

        $this->assertSame('Mikro API', $result['source_label']);
        $this->assertSame('GERCEK-001', $result['items'][0]['item_code']);
        $this->assertSame('part', $result['items'][0]['item_kind']);
        $this->assertTrue($result['items'][0]['selectable']);
        $this->assertSame('2026-08-14T12:00:01+03:00', $result['items'][0]['freshness_at']);
        $this->assertNull($result['items'][0]['warehouse_code']);
        $this->assertNull($result['items'][0]['on_hand']);
        $this->assertNull($result['items'][0]['reserved']);
        $this->assertNull($result['items'][0]['available']);
        $this->assertTrue($result['items'][0]['availability_verified']);
        $this->assertTrue($result['items'][0]['physical_stock_verified']);
        $this->assertSame('6.000000', $result['items'][0]['physical_stock_total']);
        $this->assertSame('Stokta: 6 ADET', $result['items'][0]['stock_status_label']);
    }

    public function test_verified_physical_stock_allows_part_draft_without_availability_claim(): void
    {
        [$request] = $this->requestFixture();
        config(['services.technical_service.payment_order_context_test_stock' => false]);
        $mikro = Mockery::mock(MikroApiClient::class);
        $mikro->shouldReceive('searchStocks')->once()->andReturn([
            'success' => true,
            'freshness_at' => '2026-08-14T12:00:00+03:00',
            'data' => [[
                'item_code' => 'GERCEK-002',
                'item_name' => 'Gerçek Kilit Gövdesi',
                'item_short_name' => null,
                'unit_code' => 'ADET',
                'stock_type' => 8,
                'detail_tracking_type' => 0,
            ]],
        ]);
        $mikro->shouldNotReceive('stockAvailability');
        $mikro->shouldNotReceive('serialLookup');
        $mikro->shouldReceive('physicalStockQuantities')->once()->with(['GERCEK-002'])->andReturn(
            $this->physicalStockResponse(['GERCEK-002'], '3.000000', '2.000000'),
        );
        $service = new TechnicalServicePaymentOrderContextService($mikro, app(TechnicalServiceAssignmentSettlementService::class));
        $stock = $service->searchParts($request, 'GERCEK-002')['items'][0];

        $preview = $service->preview($request, 'part_charge', [
            ...$this->billingInput(),
            'part_supplier' => 'emaks_prime',
            'commercial_mode' => 'paid',
            'delivery_mode' => 'hand_delivery',
            'lines' => [[
                'stock_selection_token' => $stock['selection_token'],
                'quantity' => 1,
                'unit_price' => 600,
            ]],
            'delivery_target' => 'mrn_customer',
        ], 600, 'TRY');

        $this->assertTrue($preview['readiness']['ready']);
        $this->assertNotContains('physical_stock_unverified', $preview['readiness']['blocker_codes']);
        $this->assertSame(5.0, $preview['lines'][0]['physical_stock_total_snapshot']);
        $this->assertArrayNotHasKey('available', array_filter($preview['lines'][0], fn (mixed $value): bool => $value !== null));
        $this->assertSame(600.0, $preview['order_reference_total']);
    }

    public function test_stock_list_does_not_expose_unverified_serial_tracking_state(): void
    {
        [$request] = $this->requestFixture();
        config(['services.technical_service.payment_order_context_test_stock' => false]);
        $mikro = Mockery::mock(MikroApiClient::class);
        $mikro->shouldReceive('searchStocks')->once()->andReturn([
            'success' => true,
            'freshness_at' => '2026-08-14T12:00:00+03:00',
            'data' => [[
                'item_code' => 'GERCEK-SERI-001',
                'item_name' => 'Gerçek Parça',
                'item_short_name' => null,
                'unit_code' => 'ADET',
                'stock_type' => 8,
                'detail_tracking_type' => 3,
            ]],
        ]);
        $mikro->shouldNotReceive('stockAvailability');
        $mikro->shouldNotReceive('serialLookup');
        $mikro->shouldReceive('physicalStockQuantities')->once()->with(['GERCEK-SERI-001'])->andReturn(
            $this->physicalStockResponse(['GERCEK-SERI-001'], '1.000000', '1.000000'),
        );
        $service = new TechnicalServicePaymentOrderContextService($mikro, app(TechnicalServiceAssignmentSettlementService::class));
        $stock = $service->searchParts($request, 'GERCEK-SERI-001')['items'][0];

        $this->assertTrue($stock['serial_tracking_required']);
        $this->assertSame('required', $stock['serial_tracking_state']);
        $this->assertSame([], $stock['serials']);
        $this->assertTrue($stock['availability_verified']);
    }

    public function test_mikro_stock_type_eight_maps_to_part(): void
    {
        [$request] = $this->requestFixture();
        $service = $this->serviceWithSearchRows([[
            'item_code' => 'TKN000009',
            'item_name' => 'DDL 720 DIŞ DOKUMATİK',
            'item_short_name' => 'DIŞ DOKUMATİK',
            'unit_code' => 'ADET',
            'stock_type' => 8,
            'detail_tracking_type' => 0,
        ]]);

        $item = $service->searchParts($request, 'DOKUMATİK')['items'][0];

        $this->assertSame('part', $item['item_kind']);
        $this->assertSame('mikro_stock_type', $item['classification_source']);
        $this->assertTrue($item['selectable']);
    }

    public function test_mikro_stock_type_six_maps_presentation_stand_to_accessory(): void
    {
        [$request] = $this->requestFixture();
        $service = $this->serviceWithSearchRows([[
            'item_code' => 'EE.BCK.STD.0010',
            'item_name' => 'PHILIPS SUNUM STANDI',
            'item_short_name' => 'SUNUM STANDI',
            'unit_code' => 'ADET',
            'stock_type' => 6,
            'detail_tracking_type' => 0,
        ]]);

        $item = $service->searchParts($request, 'EE.BCK.STD.0010')['items'][0];

        $this->assertSame('accessory', $item['item_kind']);
        $this->assertSame('Aksesuar / sunum ekipmanı', $item['item_kind_label']);
        $this->assertSame('mikro_stock_type', $item['classification_source']);
        $this->assertTrue($item['selectable']);
        $this->assertSame('15.000000', $item['physical_stock_total']);
    }

    public function test_zero_physical_stock_and_mikro_failure_fail_closed(): void
    {
        [$request] = $this->requestFixture();
        config(['services.technical_service.payment_order_context_test_stock' => false]);
        $row = [
            'item_code' => 'ZERO-001',
            'item_name' => 'Sıfır Stoklu Parça',
            'item_short_name' => null,
            'unit_code' => 'ADET',
            'stock_type' => 8,
            'detail_tracking_type' => 0,
        ];
        $zeroMikro = Mockery::mock(MikroApiClient::class);
        $zeroMikro->shouldReceive('searchStocks')->once()->andReturn([
            'success' => true,
            'freshness_at' => '2026-08-14T12:00:00+03:00',
            'data' => [$row],
        ]);
        $zeroMikro->shouldReceive('physicalStockQuantities')->once()->with(['ZERO-001'])->andReturn(
            $this->physicalStockResponse(['ZERO-001'], '-1.000000', '1.000000'),
        );
        $zeroItem = (new TechnicalServicePaymentOrderContextService(
            $zeroMikro,
            app(TechnicalServiceAssignmentSettlementService::class),
        ))->searchParts($request, 'ZERO-001')['items'][0];

        $this->assertFalse($zeroItem['selectable']);
        $this->assertSame('out_of_stock', $zeroItem['physical_stock_state']);
        $this->assertSame('Stokta yok', $zeroItem['stock_status_label']);

        $failureMikro = Mockery::mock(MikroApiClient::class);
        $failureMikro->shouldReceive('searchStocks')->once()->andReturn([
            'success' => true,
            'freshness_at' => '2026-08-14T12:00:00+03:00',
            'data' => [[...$row, 'item_code' => 'FAIL-001']],
        ]);
        $failureMikro->shouldReceive('physicalStockQuantities')->once()->with(['FAIL-001'])->andReturn([
            'success' => false,
            'error_code' => 'MIKRO_TIMEOUT',
            'data' => [],
        ]);
        $failedItem = (new TechnicalServicePaymentOrderContextService(
            $failureMikro,
            app(TechnicalServiceAssignmentSettlementService::class),
        ))->searchParts($request, 'FAIL-001')['items'][0];

        $this->assertFalse($failedItem['selectable']);
        $this->assertSame('unverified', $failedItem['physical_stock_state']);
        $this->assertSame('Stok doğrulanamadı', $failedItem['stock_status_label']);
    }

    public function test_canonical_product_catalog_maps_device_and_device_cannot_be_added_to_part_context(): void
    {
        [$request] = $this->requestFixture();
        DB::table('panel.support_activation_codes')->insert([
            'code' => 'DEVICE-PROOF-'.uniqid(),
            'stock_code' => 'EP.BCK.003.0001.R001',
            'stock_name' => 'Canonical cihaz',
            'metadata' => '{}',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = $this->serviceWithSearchRows([[
            'item_code' => 'EP.BCK.003.0001.R001',
            'item_name' => 'Akıllı Kilit',
            'item_short_name' => null,
            'unit_code' => 'ADET',
            'stock_type' => 0,
            'detail_tracking_type' => 3,
        ]]);
        $item = $service->searchParts($request, 'EP.BCK.003.0001.R001')['items'][0];

        $this->assertSame('device', $item['item_kind']);
        $this->assertSame('panel_product_catalog', $item['classification_source']);
        $this->assertFalse($item['selectable']);

        try {
            $service->preview($request, 'part_charge', $this->multiLineInput($request, [[
                'stock_selection_token' => $item['selection_token'],
                'quantity' => 1,
                'unit_price' => 1000,
            ]]), 1000, 'TRY');
            $this->fail('Canonical device was accepted as a spare-part line.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('cihaz ekleme akışına', $exception->errors()['order_context.lines.0.stock_selection_token'][0]);
        }
    }

    public function test_unknown_item_type_fails_closed_and_prefix_does_not_classify_item(): void
    {
        [$request] = $this->requestFixture();
        $service = $this->serviceWithSearchRows([[
            'item_code' => 'TKN-UNKNOWN-PART',
            'item_name' => 'Yedek Parça Adı Gibi Görünen Kayıt',
            'item_short_name' => 'Parça',
            'unit_code' => 'ADET',
            'stock_type' => 2,
            'detail_tracking_type' => null,
        ]]);
        $item = $service->searchParts($request, 'TKN-UNKNOWN')['items'][0];

        $this->assertSame('unknown', $item['item_kind']);
        $this->assertSame('no_canonical_evidence', $item['classification_source']);
        $this->assertFalse($item['selectable']);
        $this->assertSame('unverified', $item['serial_tracking_state']);
    }

    public function test_part_search_classification_uses_one_catalog_query_for_twenty_rows(): void
    {
        [$request] = $this->requestFixture();
        $rows = collect(range(1, 20))->map(fn (int $index): array => [
            'item_code' => 'BULK-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'item_name' => 'Sınıflandırma adayı '.$index,
            'item_short_name' => null,
            'unit_code' => 'ADET',
            'stock_type' => 2,
            'detail_tracking_type' => 0,
        ])->all();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $this->serviceWithSearchRows($rows)->searchParts($request, 'BULK');
        $catalogQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains((string) ($query['query'] ?? ''), 'support_activation_codes'))
            ->values();
        DB::disableQueryLog();

        $this->assertCount(20, $result['items']);
        $this->assertCount(1, $catalogQueries);
    }

    public function test_twenty_selectable_results_create_one_physical_stock_batch(): void
    {
        [$request] = $this->requestFixture();
        $rows = collect(range(1, 20))->map(fn (int $index): array => [
            'item_code' => 'PART-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'item_name' => 'Gerçek parça '.$index,
            'item_short_name' => null,
            'unit_code' => 'ADET',
            'stock_type' => 8,
            'detail_tracking_type' => 0,
        ])->all();

        $result = $this->serviceWithSearchRows($rows)->searchParts($request, 'PART');

        $this->assertCount(20, $result['items']);
        $this->assertTrue(collect($result['items'])->every(fn (array $item): bool => $item['selectable'] === true));
        $this->assertTrue(collect($result['items'])->every(fn (array $item): bool => $item['physical_stock_total'] === '15.000000'));
    }

    public function test_quantity_cannot_exceed_verified_physical_stock(): void
    {
        [$request] = $this->requestFixture();
        config(['services.technical_service.payment_order_context_test_stock' => false]);
        $mikro = Mockery::mock(MikroApiClient::class);
        $mikro->shouldReceive('searchStocks')->once()->andReturn([
            'success' => true,
            'freshness_at' => '2026-08-14T12:00:00+03:00',
            'data' => [[
                'item_code' => 'LIMIT-001',
                'item_name' => 'Sınırlı Stok',
                'item_short_name' => null,
                'unit_code' => 'ADET',
                'stock_type' => 8,
                'detail_tracking_type' => 0,
            ]],
        ]);
        $mikro->shouldReceive('physicalStockQuantities')->once()->with(['LIMIT-001'])->andReturn(
            $this->physicalStockResponse(['LIMIT-001'], '1.000000', '1.000000'),
        );
        $service = new TechnicalServicePaymentOrderContextService(
            $mikro,
            app(TechnicalServiceAssignmentSettlementService::class),
        );
        $item = $service->searchParts($request, 'LIMIT-001')['items'][0];

        try {
            $service->preview($request, 'part_charge', $this->multiLineInput($request, [[
                'stock_selection_token' => $item['selection_token'],
                'quantity' => 3,
                'unit_price' => 100,
            ]], ['delivery_mode' => 'hand_delivery', 'delivery_target' => 'mrn_customer']), 300, 'TRY');
            $this->fail('Doğrulanmış fiziksel stoktan yüksek adet kabul edildi.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Stokta yalnız 2 ADET bulunuyor.', $exception->errors()['order_context.lines'][0]);
        }

        $this->assertDatabaseCount(TechnicalServicePaymentOrderContextService::TABLE, 0);
    }

    public function test_failed_final_stock_revalidation_creates_no_context_or_payment(): void
    {
        [$request] = $this->requestFixture();
        config(['services.technical_service.payment_order_context_test_stock' => false]);
        $mikro = Mockery::mock(MikroApiClient::class);
        $mikro->shouldReceive('searchStocks')->once()->andReturn([
            'success' => true,
            'freshness_at' => '2026-08-14T12:00:00+03:00',
            'data' => [[
                'item_code' => 'RECHECK-001',
                'item_name' => 'Yeniden Doğrulanacak Parça',
                'item_short_name' => null,
                'unit_code' => 'ADET',
                'stock_type' => 8,
                'detail_tracking_type' => 0,
            ]],
        ]);
        $mikro->shouldReceive('physicalStockQuantities')->twice()->with(['RECHECK-001'])->andReturn(
            $this->physicalStockResponse(['RECHECK-001']),
            ['success' => false, 'error_code' => 'MIKRO_TIMEOUT', 'data' => []],
        );
        $service = new TechnicalServicePaymentOrderContextService(
            $mikro,
            app(TechnicalServiceAssignmentSettlementService::class),
        );
        $item = $service->searchParts($request, 'RECHECK-001')['items'][0];
        $input = $this->multiLineInput($request, [[
            'stock_selection_token' => $item['selection_token'],
            'quantity' => 1,
            'unit_price' => 100,
        ]], ['delivery_mode' => 'hand_delivery', 'delivery_target' => 'mrn_customer']);
        $preview = $service->preview($request, 'part_charge', $input, 100, 'TRY');

        try {
            $service->prepare($request, 'part_charge', [
                ...$input,
                'expected_context_hash' => $preview['context_hash'],
                'expected_revision' => $preview['revision'],
            ], 100, 'TRY', $this->actor(), false);
            $this->fail('Başarısız son stok doğrulamasından sonra bağlam oluşturuldu.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Mikro stok bilgisi doğrulanamadı', $exception->errors()['order_context.lines'][0]);
        }

        $this->assertDatabaseCount(TechnicalServicePaymentOrderContextService::TABLE, 0);
        $this->assertDatabaseCount('technical_service_mount_payments', 0);
    }

    public function test_new_context_supports_multiple_part_lines_and_server_calculated_totals(): void
    {
        [$request] = $this->requestFixture();
        $gateway = $this->stockItem($request, 'Gateway');
        $motor = $this->stockItem($request, 'Akıllı');
        $input = $this->multiLineInput($request, [
            ['stock_selection_token' => $gateway['selection_token'], 'quantity' => 2, 'unit_price' => 500],
            ['stock_selection_token' => $motor['selection_token'], 'quantity' => 1, 'unit_price' => 750],
        ], ['delivery_mode' => 'hand_delivery', 'delivery_target' => 'mrn_customer']);
        $preview = $this->service()->preview($request, 'part_charge', $input, 1750, 'TRY');

        $this->assertSame(2, $preview['line_count']);
        $this->assertSame(3.0, $preview['total_quantity']);
        $this->assertSame(1000.0, $preview['lines'][0]['line_total']);
        $this->assertSame(750.0, $preview['lines'][1]['line_total']);
        $this->assertSame(1750.0, $preview['order_reference_total']);
        $this->assertSame(1750.0, $preview['collection_amount']);

        $prepared = $this->service()->prepare($request, 'part_charge', [
            ...$input,
            'expected_context_hash' => $preview['context_hash'],
            'expected_revision' => $preview['revision'],
        ], 1750, 'TRY', $this->actor(), false);
        $projection = $this->service()->latestPartContext($request);

        $this->assertNotNull($projection);
        $this->assertSame((int) $prepared['context']->id, $projection['id']);
        $this->assertCount(2, $projection['lines']);
        $this->assertDatabaseCount(TechnicalServicePaymentOrderContextService::ITEM_TABLE, 2);
    }

    public function test_same_item_addition_increments_quantity_without_duplicate_line(): void
    {
        [$request] = $this->requestFixture();
        $gateway = $this->stockItem($request, 'Gateway');
        $input = $this->multiLineInput($request, [
            ['stock_selection_token' => $gateway['selection_token'], 'quantity' => 1, 'unit_price' => 500],
            ['stock_selection_token' => $gateway['selection_token'], 'quantity' => 2, 'unit_price' => 500],
        ], ['delivery_mode' => 'hand_delivery', 'delivery_target' => 'mrn_customer']);
        $preview = $this->service()->preview($request, 'part_charge', $input, 1500, 'TRY');

        $this->assertSame(1, $preview['line_count']);
        $this->assertSame(3.0, $preview['lines'][0]['quantity']);
        $this->assertSame(1500.0, $preview['lines'][0]['line_total']);
    }

    public function test_legacy_single_item_context_remains_readable(): void
    {
        [$request] = $this->requestFixture();
        $input = $this->emaksPartInput($request, [
            'delivery_mode' => 'hand_delivery',
            'delivery_target' => 'mrn_customer',
        ], 500);
        $preview = $this->service()->preview($request, 'part_charge', $input, 500, 'TRY');
        $prepared = $this->service()->prepare($request, 'part_charge', [
            ...$input,
            'expected_context_hash' => $preview['context_hash'],
            'expected_revision' => $preview['revision'],
        ], 500, 'TRY', $this->actor(), false);

        $legacyLine = $preview['lines'][0];
        $this->convertPreparedContextToLegacy($prepared['context'], $legacyLine);

        $projection = $this->service()->latestPartContext($request);

        $this->assertNotNull($projection);
        $this->assertCount(1, $projection['lines']);
        $this->assertSame('legacy_single_item_context', $projection['lines'][0]['classification_source']);
        $this->assertSame($legacyLine['item_code'], $projection['lines'][0]['item_code']);
        $this->assertSame($legacyLine['item_name'], $projection['lines'][0]['item_name']);
        $this->assertTrue($projection['readiness']['legacy_context']);
        $this->assertDatabaseCount(TechnicalServicePaymentOrderContextService::ITEM_TABLE, 0);
    }

    public function test_historical_context_is_not_mutated_when_legacy_projection_is_read(): void
    {
        [$request] = $this->requestFixture();
        $input = $this->emaksPartInput($request, [
            'delivery_mode' => 'hand_delivery',
            'delivery_target' => 'mrn_customer',
        ], 500);
        $preview = $this->service()->preview($request, 'part_charge', $input, 500, 'TRY');
        $prepared = $this->service()->prepare($request, 'part_charge', [
            ...$input,
            'expected_context_hash' => $preview['context_hash'],
            'expected_revision' => $preview['revision'],
        ], 500, 'TRY', $this->actor(), false);
        $this->convertPreparedContextToLegacy($prepared['context'], $preview['lines'][0]);
        $before = DB::table(TechnicalServicePaymentOrderContextService::TABLE)
            ->where('id', $prepared['context']->id)
            ->first();

        $this->service()->latestPartContext($request);

        $after = DB::table(TechnicalServicePaymentOrderContextService::TABLE)
            ->where('id', $prepared['context']->id)
            ->first();
        $this->assertSame((array) $before, (array) $after);
        $this->assertDatabaseCount(TechnicalServicePaymentOrderContextService::ITEM_TABLE, 0);
    }

    public function test_client_total_cannot_override_server_total(): void
    {
        [$request] = $this->requestFixture();
        $gateway = $this->stockItem($request, 'Gateway');
        $input = $this->multiLineInput($request, [[
            'stock_selection_token' => $gateway['selection_token'],
            'quantity' => 2,
            'unit_price' => 500,
        ]]);

        $this->expectException(ValidationException::class);
        $this->service()->preview($request, 'part_charge', $input, 999, 'TRY');
    }

    public function test_negative_price_and_zero_quantity_are_rejected(): void
    {
        [$request] = $this->requestFixture();
        $gateway = $this->stockItem($request, 'Gateway');

        foreach ([
            ['quantity' => 1, 'unit_price' => -1],
            ['quantity' => 0, 'unit_price' => 500],
        ] as $invalidLine) {
            try {
                $this->service()->preview($request, 'part_charge', $this->multiLineInput($request, [[
                    'stock_selection_token' => $gateway['selection_token'],
                    ...$invalidLine,
                ]]), 0, 'TRY');
                $this->fail('Invalid quantity/price was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_maximum_twenty_lines_is_enforced(): void
    {
        [$request] = $this->requestFixture();
        $gateway = $this->stockItem($request, 'Gateway');
        $lines = array_fill(0, 21, [
            'stock_selection_token' => $gateway['selection_token'],
            'quantity' => 1,
            'unit_price' => 1,
        ]);

        $this->expectException(ValidationException::class);
        $this->service()->preview($request, 'part_charge', $this->multiLineInput($request, $lines), 21, 'TRY');
    }

    public function test_line_order_does_not_change_context_hash_but_line_change_does(): void
    {
        [$request] = $this->requestFixture();
        $gateway = $this->stockItem($request, 'Gateway');
        $motor = $this->stockItem($request, 'Akıllı');
        $first = [
            ['stock_selection_token' => $gateway['selection_token'], 'quantity' => 2, 'unit_price' => 500],
            ['stock_selection_token' => $motor['selection_token'], 'quantity' => 1, 'unit_price' => 750],
        ];
        $overrides = ['delivery_mode' => 'hand_delivery', 'delivery_target' => 'mrn_customer'];
        $a = $this->service()->preview($request, 'part_charge', $this->multiLineInput($request, $first, $overrides), 1750, 'TRY');
        $b = $this->service()->preview($request, 'part_charge', $this->multiLineInput($request, array_reverse($first), $overrides), 1750, 'TRY');
        $changed = $first;
        $changed[0]['quantity'] = 3;
        $c = $this->service()->preview($request, 'part_charge', $this->multiLineInput($request, $changed, $overrides), 2250, 'TRY');

        $this->assertSame($a['context_hash'], $b['context_hash']);
        $this->assertNotSame($a['context_hash'], $c['context_hash']);
    }

    public function test_free_shipment_forces_all_line_prices_to_zero(): void
    {
        [$request] = $this->requestFixture();
        $gateway = $this->stockItem($request, 'Gateway');
        $motor = $this->stockItem($request, 'Akıllı');
        $preview = $this->service()->preview($request, 'part_charge', $this->multiLineInput($request, [
            ['stock_selection_token' => $gateway['selection_token'], 'quantity' => 2, 'unit_price' => 500],
            ['stock_selection_token' => $motor['selection_token'], 'quantity' => 1, 'unit_price' => 750],
        ], ['commercial_mode' => 'free', 'delivery_mode' => 'shipment']), 0, 'TRY');

        $this->assertSame(0.0, $preview['order_reference_total']);
        $this->assertSame(0.0, $preview['collection_amount']);
        $this->assertSame([0.0, 0.0], array_column($preview['lines'], 'unit_price'));
        $this->assertSame('Q', $preview['desired_mikro_series']);
        $this->assertSame('none', $preview['tax_mode']);
    }

    public function test_description2_renders_all_part_lines_and_totals(): void
    {
        [$request] = $this->requestFixture();
        $gateway = $this->stockItem($request, 'Gateway');
        $motor = $this->stockItem($request, 'Akıllı');
        $preview = $this->service()->preview($request, 'part_charge', $this->multiLineInput($request, [
            ['stock_selection_token' => $gateway['selection_token'], 'quantity' => 2, 'unit_price' => 500],
            ['stock_selection_token' => $motor['selection_token'], 'quantity' => 1, 'unit_price' => 750],
        ], ['delivery_mode' => 'hand_delivery', 'delivery_target' => 'mrn_customer']), 1750, 'TRY');

        $this->assertStringContainsString('1. 2 ADET · TS-PART-001 · Gateway', $preview['description2_preview']);
        $this->assertStringContainsString('2. 1 ADET · TS-PART-002 · Akıllı Kilit Motor Modülü', $preview['description2_preview']);
        $this->assertStringContainsString('PARÇA KALEMİ: 2', $preview['description2_preview']);
        $this->assertStringContainsString('SİPARİŞ/REFERANS TOPLAMI: 1.750,00 TL', $preview['description2_preview']);
        $this->assertStringContainsString('TAHSİLAT TOPLAMI: 1.750,00 TL', $preview['description2_preview']);
        $this->assertSame(1, substr_count($preview['description2_preview'], 'İLGİLİ ÜRÜN SERİ NO:'));
    }

    public function test_free_hand_delivery_resolves_q_and_zero_vat(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->emaksPartPreview($request, [
            'commercial_mode' => 'free',
            'delivery_mode' => 'hand_delivery',
            'delivery_target' => 'mrn_customer',
        ], 750);

        $this->assertSame('Q', $preview['desired_mikro_series']);
        $this->assertSame('none', $preview['tax_mode']);
        $this->assertSame(0.0, $preview['vat_rate']);
        $this->assertFalse($preview['payment_link_required']);
        $this->assertSame(750.0, $preview['order_line_total']);
        $this->assertSame(0.0, $preview['collection_amount']);
        $this->assertSame('not_required', $preview['payment_status']);
    }

    public function test_free_shipment_resolves_q_zero_amount_and_zero_vat(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->emaksPartPreview($request, [
            'commercial_mode' => 'free',
            'delivery_mode' => 'shipment',
        ], 0);

        $this->assertSame('Q', $preview['desired_mikro_series']);
        $this->assertSame(0.0, $preview['order_line_total']);
        $this->assertSame(0.0, $preview['collection_amount']);
        $this->assertFalse($preview['payment_link_required']);
        $this->assertTrue($preview['shipment_required']);
    }

    public function test_paid_hand_delivery_resolves_q_and_zero_vat(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->emaksPartPreview($request, [
            'commercial_mode' => 'paid',
            'delivery_mode' => 'hand_delivery',
            'delivery_target' => 'mrn_customer',
        ], 600);

        $this->assertSame('Q', $preview['desired_mikro_series']);
        $this->assertSame('none', $preview['tax_mode']);
        $this->assertFalse($preview['payment_link_required']);
        $this->assertSame('manual', $preview['payment_collection_mode']);
        $this->assertSame('pending', $preview['payment_status']);
    }

    public function test_paid_shipment_resolves_s_and_mikro_tax(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->emaksPartPreview($request, [
            'commercial_mode' => 'paid',
            'delivery_mode' => 'shipment',
        ], 600);

        $this->assertSame('S', $preview['desired_mikro_series']);
        $this->assertSame('standard_from_mikro', $preview['tax_mode']);
        $this->assertNull($preview['vat_rate']);
        $this->assertTrue($preview['payment_link_required']);
        $this->assertSame('payment_paid', $preview['future_order_trigger']);
    }

    public function test_mount_collection_resolves_s_service_order(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->mountPreview($request);

        $this->assertSame('S', $preview['desired_mikro_series']);
        $this->assertSame('standard_from_mikro_service_item', $preview['tax_mode']);
        $this->assertTrue($preview['payment_link_required']);
        $this->assertFalse($preview['shipment_required']);
    }

    public function test_client_cannot_override_series_or_tax_mode(): void
    {
        [$request] = $this->requestFixture();

        $this->expectException(ValidationException::class);
        $this->emaksPartPreview($request, [
            'commercial_mode' => 'free',
            'delivery_mode' => 'hand_delivery',
            'delivery_target' => 'mrn_customer',
            'desired_mikro_series' => 'S',
            'tax_mode' => 'standard_from_mikro',
        ], 600);
    }

    public function test_hand_delivery_can_never_create_payment_link(): void
    {
        [$request] = $this->requestFixture();
        $preview = $this->emaksPartPreview($request, [
            'commercial_mode' => 'paid',
            'delivery_mode' => 'hand_delivery',
            'delivery_target' => 'mrn_customer',
        ], 600);
        $prepared = $this->service()->prepare($request, 'part_charge', [
            ...$this->emaksPartInput($request, [
                'commercial_mode' => 'paid',
                'delivery_mode' => 'hand_delivery',
                'delivery_target' => 'mrn_customer',
            ], 600),
            'expected_context_hash' => $preview['context_hash'],
            'expected_revision' => $preview['revision'],
        ], 600, 'TRY', $this->actor(), false);
        $projection = $this->service()->finalizeWithoutPayment($prepared['context'], $this->actor());

        $this->assertFalse($projection['payment_link_required']);
        $this->assertSame('manual_collection_pending', $projection['state']);
        $this->assertDatabaseCount('technical_service_mount_payments', 0);
    }

    public function test_part_summary_ignores_newer_mount_context(): void
    {
        [$request] = $this->requestFixture();
        $partInput = $this->emaksPartInput($request, [
            'commercial_mode' => 'free',
            'delivery_mode' => 'hand_delivery',
            'delivery_target' => 'mrn_customer',
        ], 600);
        $partPreview = $this->service()->preview($request, 'part_charge', $partInput, 600, 'TRY');
        $partContext = $this->service()->prepare($request, 'part_charge', [
            ...$partInput,
            'expected_context_hash' => $partPreview['context_hash'],
            'expected_revision' => $partPreview['revision'],
        ], 600, 'TRY', $this->actor(), false)['context'];
        $mountInput = $this->billingInput();
        $mountPreview = $this->mountPreview($request, 1200, $mountInput);
        $this->service()->prepare($request, 'mount_collection', [
            ...$mountInput,
            'expected_context_hash' => $mountPreview['context_hash'],
            'expected_revision' => $mountPreview['revision'],
        ], 1200, 'TRY', $this->actor(), false);

        $latestPart = $this->service()->latestPartContext($request);

        $this->assertSame((int) $partContext->id, $latestPart['id']);
        $this->assertSame('part_charge', $latestPart['payment_purpose']);
    }

    public function test_technician_delivery_marks_paid_once(): void
    {
        [$request] = $this->requestFixture();
        $this->activateTechnician($request, 'Teslim Ustası', 'Denizli', 'Pamukkale');
        $input = $this->emaksPartInput($request->fresh(), [
            'commercial_mode' => 'paid',
            'delivery_mode' => 'hand_delivery',
            'delivery_target' => 'technician',
        ], 600);
        $preview = $this->service()->preview($request->fresh(), 'part_charge', $input, 600, 'TRY');
        $prepared = $this->service()->prepare($request->fresh(), 'part_charge', [
            ...$input,
            'expected_context_hash' => $preview['context_hash'],
            'expected_revision' => $preview['revision'],
        ], 600, 'TRY', $this->actor(), false);
        $this->service()->finalizeWithoutPayment($prepared['context'], $this->actor());
        $updated = $this->service()->updateHandDeliveryState($request->fresh(), (int) $prepared['context']->id, 1, 'record_delivery', null, null, $this->actor());
        $again = $this->service()->updateHandDeliveryState($request->fresh(), (int) $prepared['context']->id, 2, 'record_delivery', null, null, $this->actor());

        $this->assertSame('delivered', $updated['delivery_status']);
        $this->assertSame('paid', $updated['payment_status']);
        $this->assertSame('auto_from_technician_delivery', $updated['payment_status_source']);
        $this->assertSame(2, $again['revision']);
        $this->assertSame(1, $request->events()->where('event_type', 'part_hand_delivery_recorded')->count());
        $this->assertDatabaseCount('technical_service_mount_payments', 0);
    }

    public function test_ops_can_override_auto_paid_with_reason_and_finance_review(): void
    {
        [$request] = $this->requestFixture();
        $this->activateTechnician($request, 'Teslim Ustası', 'Denizli', 'Pamukkale');
        $input = $this->emaksPartInput($request->fresh(), [
            'commercial_mode' => 'paid',
            'delivery_mode' => 'hand_delivery',
            'delivery_target' => 'technician',
        ], 600);
        $preview = $this->service()->preview($request->fresh(), 'part_charge', $input, 600, 'TRY');
        $prepared = $this->service()->prepare($request->fresh(), 'part_charge', [
            ...$input,
            'expected_context_hash' => $preview['context_hash'],
            'expected_revision' => $preview['revision'],
        ], 600, 'TRY', $this->actor(), false);
        $this->service()->finalizeWithoutPayment($prepared['context'], $this->actor());
        $this->service()->updateHandDeliveryState($request->fresh(), (int) $prepared['context']->id, 1, 'record_delivery', null, null, $this->actor());

        try {
            $this->service()->updateHandDeliveryState($request->fresh(), (int) $prepared['context']->id, 2, 'set_payment_status', 'cancelled', '', $this->actor());
            $this->fail('Gerekçesiz ödeme override kabul edildi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        $cancelled = $this->service()->updateHandDeliveryState($request->fresh(), (int) $prepared['context']->id, 2, 'set_payment_status', 'cancelled', 'Müşteri ödemesi teyit edilemedi', $this->actor());

        $this->assertSame('cancelled', $cancelled['payment_status']);
        $this->assertTrue($cancelled['finance_review_required']);
        $this->assertSame('manual', $cancelled['payment_status_source']);
        $this->assertDatabaseCount('technical_service_mount_payments', 0);
    }

    public function test_local_code_generates_no_real_order_number_or_max_plus_one(): void
    {
        $source = file_get_contents(app_path('Services/TechnicalService/TechnicalServicePaymentOrderContextService.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('MAX(order_no)', $source);
        $this->assertStringNotContainsString('MAX(siparis_no)', $source);
        $this->assertDoesNotMatchRegularExpression('/[QS]-(?:FAKE|DRYRUN)-/i', $source);
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
            'billing_type' => 'company',
            'first_name' => null,
            'last_name' => null,
            'legal_title' => 'Fatura AŞ',
            'phone' => '905550001122',
            'email' => 'fatura@example.test',
            'tckn' => null,
            'vkn' => '1234567890',
            'tax_office' => 'Pamukkale',
            'address' => 'Fatura Caddesi No:1',
            'city' => 'Denizli',
            'district' => 'Merkezefendi',
            'postal_code' => '20000',
        ];
    }

    /** @return array<string, string|null> */
    private function individualBilling(): array
    {
        return [
            'billing_type' => 'individual',
            'first_name' => 'Ahmet',
            'last_name' => 'Aslan',
            'legal_title' => null,
            'phone' => '905550001122',
            'email' => 'ahmet@example.test',
            'tckn' => '11111111111',
            'vkn' => null,
            'tax_office' => null,
            'address' => 'Fatura Caddesi No:1',
            'city' => 'İstanbul',
            'district' => 'Esenyurt',
            'postal_code' => '34000',
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
    private function mountPreview(TechnicalServiceRequest $request, float $amount = 1200, ?array $input = null): array
    {
        return $this->service()->preview($request, 'mount_collection', $input ?? $this->billingInput(), $amount, 'TRY');
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function emaksPartPreview(TechnicalServiceRequest $request, array $overrides = [], float $amount = 1000): array
    {
        return $this->service()->preview($request, 'part_charge', $this->emaksPartInput($request, $overrides, $amount), $amount, 'TRY');
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function emaksPartInput(TechnicalServiceRequest $request, array $overrides = [], float $amount = 1000): array
    {
        $stock = $this->stockItem($request, 'Gateway');

        return array_replace([
            ...$this->billingInput(),
            'part_supplier' => 'emaks_prime',
            'commercial_mode' => 'paid',
            'delivery_mode' => 'shipment',
            'lines' => [[
                'stock_selection_token' => $stock['selection_token'],
                'quantity' => 1,
                'unit_price' => $amount,
            ]],
            'shipping_same_as_billing' => true,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function stockItem(TechnicalServiceRequest $request, string $query): array
    {
        $result = $this->service()->searchParts($request, $query);
        $this->assertSame('Test verisi', $result['source_label']);
        $this->assertNotEmpty($result['items']);

        return $result['items'][0];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function multiLineInput(TechnicalServiceRequest $request, array $lines, array $overrides = []): array
    {
        return array_replace([
            ...$this->billingInput(),
            'part_supplier' => 'emaks_prime',
            'commercial_mode' => 'paid',
            'delivery_mode' => 'shipment',
            'lines' => $lines,
            'shipping_same_as_billing' => true,
        ], $overrides);
    }

    /** @param array<string, mixed> $line */
    private function convertPreparedContextToLegacy(object $context, array $line): void
    {
        DB::table(TechnicalServicePaymentOrderContextService::TABLE)
            ->where('id', $context->id)
            ->update([
                'item_code' => $line['item_code'],
                'item_name_snapshot' => $line['item_name'],
                'quantity' => $line['quantity'],
                'unit_code' => $line['unit_code'],
                'stock_source' => $line['stock_source'],
                'stock_freshness_at' => $line['stock_freshness_at'],
                'part_serial_tracking_required' => $line['serial_tracking_required'],
                'selected_part_serial' => $line['selected_part_serial'],
                'order_line_unit_price' => $line['unit_price'],
                'order_line_total' => $line['line_total'],
            ]);
        DB::table(TechnicalServicePaymentOrderContextService::ITEM_TABLE)
            ->where('context_id', $context->id)
            ->delete();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function serviceWithSearchRows(array $rows): TechnicalServicePaymentOrderContextService
    {
        config(['services.technical_service.payment_order_context_test_stock' => false]);
        $mikro = Mockery::mock(MikroApiClient::class);
        $mikro->shouldReceive('searchStocks')->once()->andReturn([
            'success' => true,
            'freshness_at' => '2026-08-14T12:00:00+03:00',
            'stale' => false,
            'data' => $rows,
        ]);
        $selectableCodes = collect($rows)
            ->filter(fn (array $row): bool => in_array((int) ($row['stock_type'] ?? -1), [6, 8], true))
            ->pluck('item_code')
            ->map(fn (mixed $code): string => mb_strtoupper(trim((string) $code), 'UTF-8'))
            ->sort()
            ->values()
            ->all();
        if ($selectableCodes === []) {
            $mikro->shouldNotReceive('physicalStockQuantities');
        } else {
            $mikro->shouldReceive('physicalStockQuantities')->once()->with($selectableCodes)->andReturn(
                $this->physicalStockResponse($selectableCodes),
            );
        }

        return new TechnicalServicePaymentOrderContextService(
            $mikro,
            app(TechnicalServiceAssignmentSettlementService::class),
        );
    }

    /** @param array<int, string> $itemCodes @return array<string, mixed> */
    private function physicalStockResponse(
        array $itemCodes,
        ?string $warehouseOne = '10.000000',
        ?string $warehouseFive = '5.000000',
    ): array {
        $rows = [];
        foreach ($itemCodes as $itemCode) {
            $rows[] = [
                'item_code' => $itemCode,
                'unit_code' => 'ADET',
                'warehouse_code' => 1,
                'physical_quantity' => $warehouseOne,
            ];
            $rows[] = [
                'item_code' => $itemCode,
                'unit_code' => 'ADET',
                'warehouse_code' => 5,
                'physical_quantity' => $warehouseFive,
            ];
        }

        return [
            'success' => true,
            'stale' => false,
            'fallback_used' => false,
            'freshness_at' => '2026-08-14T12:00:01+03:00',
            'correlation_id' => 'physical-stock-test',
            'data' => $rows,
        ];
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
            'payment_url' => 'https://payments.example.test/mount-payment/'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => $amount,
            'currency' => 'TRY',
            'raw_payload' => [
                'source' => 'operation_order_context_payment',
                'purpose' => $purpose,
                'total_amount' => $amount,
                'provider_mode' => 'local',
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
