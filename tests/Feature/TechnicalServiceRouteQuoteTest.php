<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\TechnicalServiceRouteQuote;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServiceRouteCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TechnicalServiceRouteQuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_service_calculates_thresholds_and_fee_from_round_trip_distance(): void
    {
        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);
        $roundTripDistances = [29, 30, 30.1, 45];
        Http::fake([
            'https://routes.googleapis.com/*' => function () use (&$roundTripDistances) {
                return Http::response($this->googleRoutesResponseForRoundTripKm((float) array_shift($roundTripDistances)), 200);
            },
        ]);

        $this->assertQuoteForRoundTripKm(29, false, 0.0, 0.0);
        $this->assertQuoteForRoundTripKm(30, false, 0.0, 0.0);
        $this->assertQuoteForRoundTripKm(30.1, true, 0.1, 1.0);
        $this->assertQuoteForRoundTripKm(45, true, 15.0, 150.0);
    }

    public function test_route_service_uses_google_one_way_distance_for_round_trip_billable_km_and_fee(): void
    {
        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);
        Http::fake([
            'https://routes.googleapis.com/*' => Http::response($this->googleRoutesResponseForOneWayMeters(87900), 200),
        ]);

        $payload = app(TechnicalServiceRouteCostService::class)->quote(
            $this->technicalServiceRequestWithLocation(),
            $this->technicianWithLocation(),
        );

        $this->assertSame(87.9, $payload['one_way_distance_km']);
        $this->assertSame(175.8, $payload['round_trip_distance_km']);
        $this->assertSame(30.0, $payload['threshold_km']);
        $this->assertSame(145.8, $payload['billable_km']);
        $this->assertSame(145.8, $payload['extra_km']);
        $this->assertSame(10.0, $payload['fee_per_km']);
        $this->assertSame(1458.0, $payload['fee_amount']);
    }

    public function test_route_service_same_location_guard_returns_zero_fee_without_google_call(): void
    {
        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);
        Http::fake([
            'https://routes.googleapis.com/*' => Http::response($this->googleRoutesResponseForRoundTripKm(45), 200),
        ]);

        $request = $this->technicalServiceRequestWithLocation();
        $technician = $this->technicianWithLocation([
            'latitude' => $request->location_latitude,
            'longitude' => $request->location_longitude,
        ]);

        $payload = app(TechnicalServiceRouteCostService::class)->quote($request, $technician);

        $this->assertSame(TechnicalServiceRouteQuote::STATUS_CALCULATED, $payload['status']);
        $this->assertSame(TechnicalServiceRouteQuote::PROVIDER_SAME_LOCATION_GUARD, $payload['source']);
        $this->assertSame(0.0, $payload['one_way_distance_km']);
        $this->assertSame(0.0, $payload['round_trip_distance_km']);
        $this->assertSame(0.0, $payload['billable_km']);
        $this->assertSame(0.0, $payload['fee_amount']);
        $this->assertFalse($payload['travel_fee_required']);
        $this->assertSame('Usta ve müşteri konumu aynı/çok yakın. Yol ücreti yok.', $payload['message']);
        Http::assertSentCount(0);
    }

    public function test_route_service_marks_short_straight_line_high_route_as_suspicious(): void
    {
        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);
        Http::fake([
            'https://routes.googleapis.com/*' => Http::response($this->googleRoutesResponseForOneWayMeters(6000), 200),
        ]);

        $request = $this->technicalServiceRequestWithLocation();
        $technician = $this->technicianWithLocation([
            'latitude' => 41.0182376,
            'longitude' => 28.9783589,
        ]);

        $payload = app(TechnicalServiceRouteCostService::class)->quote($request, $technician, true);

        $this->assertSame(6.0, $payload['one_way_distance_km']);
        $this->assertSame(12.0, $payload['round_trip_distance_km']);
        $this->assertTrue($payload['suspicious_route']);
        $this->assertGreaterThan(0.1, $payload['straight_line_distance_km']);
        $this->assertLessThanOrEqual(2.0, $payload['straight_line_distance_km']);
        $this->assertSame('Rota mesafesi düz çizgi mesafesine göre yüksek. Konumlar kontrol edilmeli.', $payload['message']);
    }

    public function test_route_service_keeps_fee_amount_null_when_fee_setting_is_missing(): void
    {
        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => null,
        ]);
        Http::fake([
            'https://routes.googleapis.com/*' => Http::response($this->googleRoutesResponseForRoundTripKm(45), 200),
        ]);

        $payload = app(TechnicalServiceRouteCostService::class)->quote(
            $this->technicalServiceRequestWithLocation(),
            $this->technicianWithLocation(),
        );

        $this->assertTrue($payload['travel_fee_required']);
        $this->assertSame(22.5, $payload['one_way_distance_km']);
        $this->assertSame(45.0, $payload['round_trip_distance_km']);
        $this->assertSame(15.0, $payload['billable_km']);
        $this->assertSame(15.0, $payload['extra_km']);
        $this->assertNull($payload['fee_amount']);
        $this->assertSame('Yol ücreti hesaplanacak. Km başı ücret ayarı eksik.', $payload['message']);
    }

    public function test_route_service_returns_safe_status_for_missing_locations_and_api_key(): void
    {
        $service = app(TechnicalServiceRouteCostService::class);
        $technician = $this->technicianWithLocation();

        $missingCustomerLocation = $service->quote($this->technicalServiceRequest(), $technician);
        $this->assertSame(TechnicalServiceRouteQuote::STATUS_MISSING_LOCATION, $missingCustomerLocation['status']);
        $this->assertSame('Müşteri konumu eksik.', $missingCustomerLocation['message']);

        $missingTechnicianLocation = $service->quote(
            $this->technicalServiceRequestWithLocation(),
            TechnicalServiceTechnician::query()->create([
                'name' => 'Konumsuz Usta',
                'phone' => '+905555555551',
                'city' => 'İstanbul',
                'active' => true,
            ]),
        );
        $this->assertSame(TechnicalServiceRouteQuote::STATUS_MISSING_LOCATION, $missingTechnicianLocation['status']);
        $this->assertSame('Usta konumu eksik.', $missingTechnicianLocation['message']);

        config(['services.google.routes_api_key' => null]);
        $missingApiKey = $service->quote($this->technicalServiceRequestWithLocation(), $technician);
        $this->assertSame(TechnicalServiceRouteQuote::STATUS_MISSING_API_KEY, $missingApiKey['status']);
        $this->assertSame('Google Routes API anahtarı tanımlı değil.', $missingApiKey['message']);
    }

    public function test_route_service_calculates_when_technician_coordinates_need_review(): void
    {
        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);
        Http::fake([
            'https://routes.googleapis.com/*' => Http::response($this->googleRoutesResponseForRoundTripKm(45), 200),
        ]);

        $technician = $this->technicianWithLocation();
        $technician->forceFill(['needs_review' => true])->save();

        $payload = app(TechnicalServiceRouteCostService::class)->quote(
            $this->technicalServiceRequestWithLocation(),
            $technician,
        );

        $this->assertSame(TechnicalServiceRouteQuote::STATUS_CALCULATED, $payload['status']);
        $this->assertSame(45.0, $payload['round_trip_distance_km']);
        $this->assertSame(150.0, $payload['fee_amount']);
    }

    public function test_route_service_reuses_cached_quote_for_same_coordinates(): void
    {
        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);

        Http::fake([
            'https://routes.googleapis.com/*' => Http::response($this->googleRoutesResponseForRoundTripKm(45), 200),
        ]);

        $request = $this->technicalServiceRequestWithLocation();
        $technician = $this->technicianWithLocation();
        $service = app(TechnicalServiceRouteCostService::class);

        $first = $service->quote($request, $technician);
        $second = $service->quote($request, $technician);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, TechnicalServiceRouteQuote::query()->where('technical_service_request_id', $request->id)->count());
        Http::assertSentCount(1);
    }

    public function test_route_service_ignores_cached_quote_when_fee_setting_is_missing_or_changed(): void
    {
        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);
        Http::fake([
            'https://routes.googleapis.com/*' => Http::response($this->googleRoutesResponseForRoundTripKm(45), 200),
        ]);

        $requestWithNullFee = $this->technicalServiceRequestWithLocation();
        $technicianWithNullFee = $this->technicianWithLocation();
        $staleNullFeeQuote = $this->createCachedQuote($requestWithNullFee, $technicianWithNullFee, null, null);

        $nullFeePayload = app(TechnicalServiceRouteCostService::class)->quote($requestWithNullFee, $technicianWithNullFee);

        $this->assertNotSame($staleNullFeeQuote->id, $nullFeePayload['id']);
        $this->assertSame(10.0, $nullFeePayload['fee_per_km']);
        $this->assertSame(150.0, $nullFeePayload['fee_amount']);

        $requestWithChangedFee = $this->technicalServiceRequestWithLocation();
        $technicianWithChangedFee = $this->technicianWithLocation();
        $staleChangedFeeQuote = $this->createCachedQuote($requestWithChangedFee, $technicianWithChangedFee, 8.0, 120.0);

        $changedFeePayload = app(TechnicalServiceRouteCostService::class)->quote($requestWithChangedFee, $technicianWithChangedFee);

        $this->assertNotSame($staleChangedFeeQuote->id, $changedFeePayload['id']);
        $this->assertSame(10.0, $changedFeePayload['fee_per_km']);
        $this->assertSame(150.0, $changedFeePayload['fee_amount']);
        Http::assertSentCount(2);
    }

    public function test_route_quote_endpoint_returns_safe_missing_location_response(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest();
        $technician = $this->technicianWithLocation();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/technicians/{$technician->id}/route-quote")
            ->assertOk()
            ->assertJsonPath('status', TechnicalServiceRouteQuote::STATUS_MISSING_LOCATION)
            ->assertJsonPath('message', 'Müşteri konumu eksik.');
    }

    public function test_route_quote_endpoint_returns_quote_payload_and_request_detail_contains_route_quote(): void
    {
        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);
        Http::fake([
            'https://routes.googleapis.com/*' => Http::response($this->googleRoutesResponseForRoundTripKm(45), 200),
        ]);

        $user = $this->adminUser();
        $request = $this->technicalServiceRequestWithLocation();
        $technician = $this->technicianWithLocation();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/technicians/{$technician->id}/route-quote")
            ->assertOk()
            ->assertJsonPath('status', TechnicalServiceRouteQuote::STATUS_CALCULATED)
            ->assertJsonPath('technician_id', $technician->id)
            ->assertJsonPath('one_way_distance_km', 22.5)
            ->assertJsonPath('round_trip_distance_km', 45)
            ->assertJsonPath('distance_km', 45)
            ->assertJsonPath('billable_km', 15)
            ->assertJsonPath('extra_km', 15)
            ->assertJsonPath('travel_fee_required', true)
            ->assertJsonPath('fee_per_km', 10)
            ->assertJsonPath('current_fee_per_km', 10)
            ->assertJsonPath('fee_per_km_matches_current', true)
            ->assertJsonPath('fee_amount', 150)
            ->assertJsonPath('message', '30 km üstü yol ücreti gerekli.')
            ->assertJsonPath('request.route_fee_config.fee_per_km', 10)
            ->assertJsonPath('request.route_fee_config.threshold_km', 30)
            ->assertJsonPath('request.route_quote.distance_km', 45)
            ->assertJsonPath('request.route_quote.technician_id', $technician->id)
            ->assertJsonPath('request.route_quote.origin_latitude', (float) $technician->latitude)
            ->assertJsonPath('request.route_quote.origin_longitude', (float) $technician->longitude)
            ->assertJsonPath('request.route_quote.destination_latitude', (float) $request->location_latitude)
            ->assertJsonPath('request.route_quote.destination_longitude', (float) $request->location_longitude)
            ->assertJsonPath('request.route_quote.round_trip_distance_km', 45)
            ->assertJsonPath('request.travel_round_trip_km', 45)
            ->assertJsonPath('request.travel_billable_km', 15)
            ->assertJsonPath('request.travel_fee_amount', '150.00')
            ->assertJsonPath('request.route_quote.message', '30 km üstü yol ücreti gerekli.');
    }

    public function test_route_quote_endpoint_embeds_selected_cached_quote_in_request_payload(): void
    {
        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);
        Http::fake([
            'https://routes.googleapis.com/*' => Http::response($this->googleRoutesResponseForRoundTripKm(80), 200),
        ]);

        $user = $this->adminUser();
        $request = $this->technicalServiceRequestWithLocation();
        $selectedTechnician = $this->technicianWithLocation();
        $otherTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Baska Rota Ustasi',
            'phone' => '+905555555553',
            'city' => 'Istanbul',
            'active' => true,
            'latitude' => 41.11,
            'longitude' => 29.11,
        ]);
        $selectedQuote = $this->createCachedQuote($request, $selectedTechnician, 10.0, 150.0);
        $otherQuote = $this->createCachedQuote($request, $otherTechnician, 10.0, 150.0);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/technicians/{$selectedTechnician->id}/route-quote")
            ->assertOk()
            ->assertJsonPath('id', $selectedQuote->id)
            ->assertJsonPath('technician_id', $selectedTechnician->id)
            ->assertJsonPath('request.route_quote.id', $selectedQuote->id)
            ->assertJsonPath('request.route_quote.technician_id', $selectedTechnician->id);

        $this->assertDatabaseHas('technical_service_route_quotes', [
            'id' => $otherQuote->id,
            'technician_id' => $otherTechnician->id,
        ]);
        Http::assertSentCount(0);
    }

    public function test_manual_route_quote_endpoint_recalculates_or_overrides_fee_without_closing_request_payload(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequestWithLocation();
        $technician = $this->technicianWithLocation();

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/route-quote/manual", [
                'technical_service_technician_id' => $technician->id,
                'one_way_distance_km' => 87.9,
                'threshold_km' => 30,
                'fee_per_km' => 10,
                'manual_note' => 'Operasyon düzeltmesi',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServiceRouteQuote::STATUS_CALCULATED)
            ->assertJsonPath('source', TechnicalServiceRouteQuote::PROVIDER_MANUAL_OVERRIDE)
            ->assertJsonPath('one_way_distance_km', 87.9)
            ->assertJsonPath('round_trip_distance_km', 175.8)
            ->assertJsonPath('billable_km', 145.8)
            ->assertJsonPath('fee_per_km', 10)
            ->assertJsonPath('current_fee_per_km', 10)
            ->assertJsonPath('fee_per_km_matches_current', true)
            ->assertJsonPath('fee_amount', 1458)
            ->assertJsonPath('manual_override', false)
            ->assertJsonPath('manual_note', 'Operasyon düzeltmesi')
            ->assertJsonPath('request.route_quote.round_trip_distance_km', 175.8)
            ->assertJsonPath('request.travel_round_trip_km', 175.8)
            ->assertJsonPath('request.travel_billable_km', 145.8)
            ->assertJsonPath('request.travel_fee_amount', '1458.00');

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/route-quote/manual", [
                'technical_service_technician_id' => $technician->id,
                'round_trip_distance_km' => 175.8,
                'threshold_km' => 30,
                'fee_per_km' => 10,
                'fee_amount' => 1500,
                'manual_override' => true,
            ])
            ->assertOk()
            ->assertJsonPath('manual_override', true)
            ->assertJsonPath('fee_amount', 1500)
            ->assertJsonPath('request.travel_fee_amount', '1500.00');
    }

    public function test_extra_mount_fee_payment_link_creates_payment_for_mrn_and_serials(): void
    {
        $user = $this->adminUser();
        [$request, $session, $serial] = $this->technicalServiceRequestWithSessionAndSerial();
        $technician = $this->technicianWithLocation();
        $quote = $this->createCachedQuote($request, $technician, 10.0, 150.0);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", [
                'route_quote_id' => $quote->id,
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$serial->id],
                'amount' => 150,
                'currency' => 'TRY',
                'reason' => 'route_fee',
                'note' => 'Ek yol ücreti',
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('payment.amount', 150)
            ->assertJsonPath('payment.currency', 'TRY')
            ->assertJsonPath('request.id', $request->id)
            ->assertJsonPath('request.sale_and_payment.mount_payment_status', TechnicalServiceMountSession::PAYMENT_PENDING)
            ->assertJsonPath('request.sale_and_payment.extra_mount_payment.status', TechnicalServiceMountPayment::STATUS_PENDING)
            ->assertJsonPath('request.invoice_serials.selected_serials.0.mount_payment_status', TechnicalServiceMountPayment::STATUS_PENDING)
            ->assertJsonPath('request.invoice_serials.selected_serials.0.mount_status_label', 'Ek ödeme bekleniyor');

        $payment = TechnicalServiceMountPayment::query()->firstOrFail();

        $this->assertSame($session->id, $payment->technical_service_mount_session_id);
        $this->assertSame('operation_extra_mount_fee', $payment->raw_payload['source']);
        $this->assertSame($request->id, $payment->raw_payload['technical_service_request_id']);
        $this->assertSame([$serial->id], $payment->raw_payload['selected_serial_ids']);
        $this->assertNotEmpty($payment->payment_url);
    }

    public function test_extra_mount_fee_payment_rejects_zero_amount(): void
    {
        $user = $this->adminUser();
        [$request, , $serial] = $this->technicalServiceRequestWithSessionAndSerial();
        $technician = $this->technicianWithLocation();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", [
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$serial->id],
                'amount' => 0,
                'currency' => 'TRY',
                'reason' => 'route_fee',
            ])
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, TechnicalServiceMountPayment::query()->count());
    }

    public function test_fake_approve_extra_mount_fee_marks_request_and_selected_serials_paid(): void
    {
        [$request, $session, $serial] = $this->technicalServiceRequestWithSessionAndSerial();

        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-extra-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 150,
            'currency' => 'TRY',
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'technical_service_request_id' => $request->id,
                'mrn' => $request->mrn,
                'selected_serial_ids' => [$serial->id],
                'reason' => 'route_fee',
                'note' => 'Ek yol ücreti',
            ],
        ]);

        $this->get("/mount-payment/fake/{$payment->id}/approve")
            ->assertRedirect('/');

        $payment->refresh();
        $request->refresh();
        $serial->refresh();

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $payment->status);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PAID, $request->mount_payment_status);
        $this->assertSame(TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL, $request->sale_mount_status);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $serial->source_payload['mount_payment_status']);
        $this->assertSame('Montaj Dahil', $serial->source_payload['mount_status_label']);
        $this->assertTrue($serial->operation_added);
        $this->assertSame('green', $serial->color_status);
    }

    public function test_technician_api_returns_coordinate_and_address_fields_for_route_ui(): void
    {
        $user = $this->adminUser();
        TechnicalServiceTechnician::query()->create([
            'name' => 'Koordinatli Usta',
            'phone' => '+905555555551',
            'city' => 'Istanbul',
            'address' => 'Test adres',
            'location_code' => '8G7C+X5 Istanbul',
            'latitude' => '38.4237340',
            'longitude' => '27.1428260',
            'active' => true,
        ]);
        TechnicalServiceTechnician::query()->create([
            'name' => 'Baslangic Koordinatli Usta',
            'phone' => '+905555555552',
            'city' => 'Izmir',
            'default_start_plus_code' => '8G7C+X5 Izmir',
            'start_latitude' => '39.1234560',
            'start_longitude' => '28.1234560',
            'active' => true,
        ]);
        TechnicalServiceTechnician::query()->create([
            'name' => 'Plus Code Usta',
            'phone' => '+905555555553',
            'city' => 'Mugla',
            'location_code' => '394F+84 Bodrum, Mugla',
            'google_plus_code' => '394F+84 Bodrum, Mugla',
            'active' => true,
        ]);

        $items = $this->actingAs($user)
            ->getJson('/api/technical-service/technicians?active=1')
            ->assertOk()
            ->json('items');

        $byName = collect($items)->keyBy('name');

        $this->assertSame('38.4237340', $byName['Koordinatli Usta']['latitude']);
        $this->assertSame('27.1428260', $byName['Koordinatli Usta']['longitude']);
        $this->assertSame('39.1234560', $byName['Baslangic Koordinatli Usta']['start_latitude']);
        $this->assertSame('28.1234560', $byName['Baslangic Koordinatli Usta']['start_longitude']);
        $this->assertSame('394F+84 Bodrum, Mugla', $byName['Plus Code Usta']['google_plus_code']);
        $this->assertNull($byName['Plus Code Usta']['latitude']);
        $this->assertNull($byName['Plus Code Usta']['longitude']);
    }

    public function test_frontend_contains_route_quote_and_travel_fee_labels(): void
    {
        $detailsSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));
        $pageSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));
        $cardSource = file_get_contents(resource_path('js/components/technical-service/TechnicalServiceKanbanCard.tsx'));
        $techniciansSource = file_get_contents(resource_path('js/pages/panel/technical-service-technicians.tsx'));

        $this->assertIsString($detailsSource);
        $this->assertIsString($pageSource);
        $this->assertIsString($cardSource);
        $this->assertIsString($techniciansSource);

        foreach ([
            'Gerçek koordinat var',
            'Gerçek koordinat eksik',
            'Usta adres/Plus Code var, gerçek koordinat eksik.',
            'Yol hesabı sonucu yok.',
            'Yol hesabı yapılmadan ücrete tabi km hesaplanmaz.',
            'Seçili usta değişti. Yeni usta için yol ücreti tekrar hesaplanmalı.',
            'için yol ücreti henüz hesaplanmadı.',
            'Yol ücretini hesaplamak için seçili usta ve müşteri konumu kullanılacak.',
            'Bu usta için yol ücretini hesapla',
            'Yol hesabı durumu',
            'routeQuoteActiveForSelectedTechnician',
            'activeRouteQuote',
            'Usta ↔ müşteri düz çizgi mesafesi',
            'Google Routes tek yön mesafesi',
            'Müşteriden istenecek ek ödeme tutarı',
            'Ödeme linki oluştur',
            'WhatsApp ile gönder',
            'Teknik detay',
            'Rota mesafesi düz çizgi mesafesine göre yüksek',
            'Montaj durumu',
            'Km başı ücret (sabit ayar)',
            'Hesaplanmadı',
            "hasActiveRouteQuote ? numericInputValue(routeOneWayKm) : ''",
            "hasActiveRouteQuote ? numericInputValue(routeBillableKm) : '0'",
            'Mesafe uyumsuzluğu - kontrol gerekli',
            'Koordinat kontrol gerekli',
            'Usta koordinatı kontrol gerekli. Yol ücreti otomatik onaylanmamalı.',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $detailsSource);
        }

        foreach ([
            'route quote id',
            'quote technician_id',
            'label="selectedTechnicianId"',
            'eski quote gösterilmiyor',
            'Usta koordinatı lat/lng',
            'Müşteri koordinatı lat/lng',
        ] as $debugText) {
            $this->assertStringNotContainsString($debugText, $detailsSource);
        }

        foreach ([
            'validCoordinatePair',
            'technicianCoordinatePair',
            'routeQuoteActiveForSelection',
            'const responseStatus = typeof response.status',
            "responseStatus !== 'calculated'",
            'routeQuoteFailed',
            'if (!updatedRequest)',
            'setSelectedDetailRequest(updatedRequest)',
            'Yaklaşık şehir/adres mesafesi',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $pageSource);
        }

        foreach ([
            'hasRealCoordinates',
            'Gerçek koordinat var',
            'Gerçek koordinat yok',
            'Plus Code var',
            'Adres var',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $techniciansSource);
        }

        foreach ([
            'Operasyon ve Montaj Kontrolü',
            'Usta / Çilingir Atama',
            'Google Routes tek yön mesafesi',
            'Ücretsiz sınır',
            'Ücrete tabi km',
            'Km başı ücret (sabit ayar)',
            'Yol ücreti hesaplanamadı',
            'Yol ücreti onayı gerekli',
            'Yol ücreti yok',
            'Usta konumu eksik',
            'Müşteri konumu eksik',
            'Atanan servis',
            'Servis telefonu',
            'Faturadaki diğer serileri gör',
            'Yol ücreti / fiyat düzenle',
            'Yol ücreti tutarı',
            'Seri No Sorgu',
            'Montaja ekle',
            'İade - eklenemez',
            'Ana seri - çıkarılamaz',
            'WhatsApp Aç',
            'Önce ödeme kontrolünü tamamlayın',
            'Önce kapı görsellerini uygun olarak işaretleyin',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $detailsSource);
        }

        foreach ([
            'Yol ücreti hesapla',
            'Yol ücreti hesabı',
            'Tek yön yol mesafesi',
            'Gidiş-geliş mesafe',
            'Ücrete tabi km',
            'Tahmini yol ücreti',
            'route-quote',
            'payments/extra-mount-fee',
            'handleExtraMountPaymentCreate',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $pageSource);
        }

        $this->assertStringNotContainsString('Usta → müşteri mesafesi', $detailsSource);
        $this->assertStringNotContainsString('Usta → müşteri mesafesi', $pageSource);

        foreach ([
            'Yol ücreti onayı gerekli',
            'Yol ücreti yok',
            'Yol ücreti hesaplanamadı',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $cardSource);
        }
    }

    private function assertQuoteForRoundTripKm(float $roundTripKm, bool $feeRequired, float $extraKm, float $feeAmount): void
    {
        $payload = app(TechnicalServiceRouteCostService::class)->quote(
            $this->technicalServiceRequestWithLocation(),
            $this->technicianWithLocation(),
            true,
        );

        $this->assertSame(TechnicalServiceRouteQuote::STATUS_CALCULATED, $payload['status']);
        $this->assertSame(round($roundTripKm / 2, 2), $payload['one_way_distance_km']);
        $this->assertSame(round($roundTripKm, 2), $payload['round_trip_distance_km']);
        $this->assertSame(round($roundTripKm, 2), $payload['distance_km']);
        $this->assertSame($feeRequired, $payload['travel_fee_required']);
        $this->assertSame($extraKm, $payload['billable_km']);
        $this->assertSame($extraKm, $payload['extra_km']);
        $this->assertSame($feeAmount, $payload['fee_amount']);
    }

    /**
     * @return array<string, mixed>
     */
    private function googleRoutesResponseForRoundTripKm(float $roundTripKm): array
    {
        return $this->googleRoutesResponseForOneWayMeters((int) round(($roundTripKm * 1000) / 2));
    }

    /**
     * @return array<string, mixed>
     */
    private function googleRoutesResponseForOneWayMeters(int $oneWayMeters): array
    {
        return [
            'routes' => [
                [
                    'distanceMeters' => $oneWayMeters,
                    'duration' => '1800s',
                ],
            ],
        ];
    }

    private function createCachedQuote(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        ?float $feePerKm,
        ?float $feeAmount,
    ): TechnicalServiceRouteQuote {
        return TechnicalServiceRouteQuote::query()->create([
            'technical_service_request_id' => $request->id,
            'technician_id' => $technician->id,
            'origin_latitude' => $technician->latitude,
            'origin_longitude' => $technician->longitude,
            'destination_latitude' => $request->location_latitude,
            'destination_longitude' => $request->location_longitude,
            'distance_meters' => 45000,
            'distance_km' => 45,
            'duration_seconds' => 1800,
            'threshold_km' => 30,
            'extra_km' => 15,
            'fee_per_km' => $feePerKm,
            'fee_amount' => $feeAmount,
            'travel_fee_required' => true,
            'provider' => TechnicalServiceRouteQuote::PROVIDER_GOOGLE_ROUTES,
            'status' => TechnicalServiceRouteQuote::STATUS_CALCULATED,
            'raw_payload' => [
                'one_way_distance_meters' => 22500,
                'round_trip_distance_meters' => 45000,
            ],
            'calculated_at' => now()->subMinute(),
        ]);
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function technicalServiceRequest(array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-ROUTE-'.uniqid(),
            'customer_name' => 'Rota Müşteri',
            'customer_phone' => '+905555555550',
            'customer_city' => 'İstanbul',
            'customer_district' => 'Kadıköy',
            'service_address' => 'Test adres',
            'product_name' => 'Test Ürün',
            'serial_number' => 'SN-ROUTE-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ], $overrides));
    }

    private function technicalServiceRequestWithLocation(): TechnicalServiceRequest
    {
        return $this->technicalServiceRequest([
            'location_latitude' => 41.0082376,
            'location_longitude' => 28.9783589,
        ]);
    }

    /**
     * @return array{0:TechnicalServiceRequest,1:TechnicalServiceMountSession,2:TechnicalServiceRequestSerial}
     */
    private function technicalServiceRequestWithSessionAndSerial(): array
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => 'SN-LINK-'.uniqid(),
            'product_name' => 'Link Ürün',
            'product_model' => 'Model 1',
            'brand' => 'Emaks',
        ]);
        ['session' => $session] = TechnicalServiceMountSession::startForLink($link);
        $request = $this->technicalServiceRequestWithLocation();
        $request->forceFill([
            'qr_link_id' => $link->id,
            'mount_session_id' => $session->id,
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
            'mount_payment_label' => 'Çoklu ürün talebi - ödeme operasyon tarafından netleştirilecek',
        ])->save();

        $serial = TechnicalServiceRequestSerial::query()->create([
            'technical_service_request_id' => $request->id,
            'mrn' => $request->mrn,
            'linked_mrn' => $request->mrn,
            'customer_phone' => $request->customer_phone,
            'serial_number' => 'SN-EXTRA-'.uniqid(),
            'product_name' => 'Ek Ürün',
            'customer_selected' => true,
            'customer_selectable' => true,
            'customer_visible' => true,
            'operation_added' => true,
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'green',
            'source_payload' => [],
        ]);

        return [$request->fresh(), $session, $serial];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function technicianWithLocation(array $overrides = []): TechnicalServiceTechnician
    {
        return TechnicalServiceTechnician::query()->create(array_merge([
            'name' => 'Rota Usta',
            'phone' => '+905555555552',
            'city' => 'İstanbul',
            'active' => true,
            'latitude' => 41.025,
            'longitude' => 29.015,
        ], $overrides));
    }
}
