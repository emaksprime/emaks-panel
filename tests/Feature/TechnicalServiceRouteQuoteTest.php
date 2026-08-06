<?php

namespace Tests\Feature;

use App\Models\PageConfig;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\TechnicalServiceRouteQuote;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\Payments\FakePaymentProvider;
use App\Services\Payments\PaymentProviderGatewayClient;
use App\Services\Payments\PaymentProviderGatewayRequest;
use App\Services\Payments\PaymentProviderGatewayResponse;
use App\Services\Payments\TechnicalServicePaymentProviderCredentialService;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use App\Services\TechnicalService\TechnicalServicePaymentActionPresenter;
use App\Services\TechnicalService\TechnicalServiceRouteCostService;
use App\Services\TechnicalService\TechnicalServiceUiLabelService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use App\Support\PartnerPortalPublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithTestHttpIsolation;
use Tests\TestCase;

class TechnicalServiceRouteQuoteTest extends TestCase
{
    use InteractsWithTestHttpIsolation, RefreshDatabase;

    public function test_route_service_calculates_thresholds_and_fee_from_round_trip_distance(): void
    {
        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);
        $roundTripDistances = [29, 30, 30.1, 45];
        $this->fakeIsolatedHttp([
            self::TEST_GOOGLE_ROUTES_URL => function () use (&$roundTripDistances) {
                return Http::response($this->googleRoutesResponseForRoundTripKm((float) array_shift($roundTripDistances)), 200);
            },
        ], expectedRequests: 4);

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
        $this->fakeIsolatedHttp([
            self::TEST_GOOGLE_ROUTES_URL => Http::response($this->googleRoutesResponseForOneWayMeters(87900), 200),
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
        $this->fakeIsolatedHttp([
            self::TEST_GOOGLE_ROUTES_URL => Http::response($this->googleRoutesResponseForRoundTripKm(45), 200),
        ], expectedRequests: 0);

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
        $this->fakeIsolatedHttp([
            self::TEST_GOOGLE_ROUTES_URL => Http::response($this->googleRoutesResponseForOneWayMeters(6000), 200),
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
        $this->fakeIsolatedHttp([
            self::TEST_GOOGLE_ROUTES_URL => Http::response($this->googleRoutesResponseForRoundTripKm(45), 200),
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
        $this->fakeIsolatedHttp([
            self::TEST_GOOGLE_ROUTES_URL => Http::response($this->googleRoutesResponseForRoundTripKm(45), 200),
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

    public function test_route_service_geocodes_existing_technician_address_before_reporting_missing_location(): void
    {
        config([
            'services.google.geocoding_api_key' => 'test-google-geocoding-key',
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);
        $this->fakeIsolatedHttp([
            self::TEST_GOOGLE_GEOCODING_PATTERN => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Kadıköy, İstanbul, Türkiye',
                    'geometry' => [
                        'location_type' => 'ROOFTOP',
                        'location' => ['lat' => 41.018, 'lng' => 29.012],
                    ],
                ]],
            ], 200),
            self::TEST_GOOGLE_ROUTES_URL => Http::response($this->googleRoutesResponseForRoundTripKm(45), 200),
        ], expectedRequests: 2);

        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Adresli Usta',
            'phone' => '+905555555551',
            'city' => 'İstanbul',
            'district' => 'Kadıköy',
            'address' => 'Rıhtım Caddesi No: 1',
            'active' => true,
        ]);

        $payload = app(TechnicalServiceRouteCostService::class)->quote(
            $this->technicalServiceRequestWithLocation(),
            $technician,
        );

        $this->assertSame(TechnicalServiceRouteQuote::STATUS_CALCULATED, $payload['status']);
        $this->assertSame(41.018, $payload['origin_latitude']);
        $this->assertSame(29.012, $payload['origin_longitude']);
        $this->assertSame(45.0, $payload['round_trip_distance_km']);
        $this->assertSame('30 km üstü yol ücreti gerekli.', $payload['message']);
        $this->assertNull($technician->fresh()->latitude);
        $this->assertNull($technician->fresh()->longitude);
    }

    public function test_route_service_reuses_cached_quote_for_same_coordinates(): void
    {
        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);

        $this->fakeIsolatedHttp([
            self::TEST_GOOGLE_ROUTES_URL => Http::response($this->googleRoutesResponseForRoundTripKm(45), 200),
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
        $this->fakeIsolatedHttp([
            self::TEST_GOOGLE_ROUTES_URL => Http::response($this->googleRoutesResponseForRoundTripKm(45), 200),
        ], expectedRequests: 2);

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
        $this->fakeIsolatedHttp([
            self::TEST_GOOGLE_ROUTES_URL => Http::response($this->googleRoutesResponseForRoundTripKm(45), 200),
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
        $this->fakeIsolatedHttp([
            self::TEST_GOOGLE_ROUTES_URL => Http::response($this->googleRoutesResponseForRoundTripKm(80), 200),
        ], expectedRequests: 0);

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

    public function test_srv_travel_earning_calculates_on_first_locksmith_selection(): void
    {
        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);
        $this->fakeIsolatedHttp([
            self::TEST_GOOGLE_ROUTES_URL => Http::response($this->googleRoutesResponseForRoundTripKm(60), 200),
        ]);

        $parent = $this->technicalServiceRequestWithLocation();
        $child = $this->technicalServiceRequest([
            'mrn' => 'SRV-ROUTE-FIRST-001',
            'parent_request_id' => $parent->id,
            'root_mrn' => $parent->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-ROUTE-FIRST-001',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Servis',
            'location_latitude' => null,
            'location_longitude' => null,
        ]);
        $technician = $this->technicianWithLocation();

        $payload = app(TechnicalServiceRouteCostService::class)->quote($child->fresh(), $technician, true);

        $this->assertSame(TechnicalServiceRouteQuote::STATUS_CALCULATED, $payload['status']);
        $this->assertSame(60.0, $payload['round_trip_distance_km']);
        $this->assertSame(30.0, $payload['billable_km']);
        $this->assertSame(300.0, $payload['fee_amount']);
        $this->assertSame((float) $parent->location_latitude, $payload['destination_latitude']);
        $this->assertSame((float) $parent->location_longitude, $payload['destination_longitude']);
        $this->assertSame('60.00', $child->fresh()->travel_round_trip_km);
        $this->assertSame('300.00', $child->fresh()->travel_fee_amount);

        $childPayload = app(TechnicalServiceWorkflowService::class)->serialize($child->fresh(), true);
        $this->assertNull($childPayload['location']['latitude']);
        $this->assertNull($childPayload['location']['longitude']);
        $this->assertSame((float) $parent->location_latitude, $childPayload['location']['route_latitude']);
        $this->assertSame((float) $parent->location_longitude, $childPayload['location']['route_longitude']);
        $this->assertSame('parent_request', $childPayload['location']['route_source']);
        Http::assertSentCount(1);
    }

    public function test_first_selection_calculates_and_persists_route(): void
    {
        $pageSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));

        $this->assertIsString($pageSource);
        $this->assertStringContainsString('routeQuoteLatestSelection.current = {', $pageSource);
        $this->assertStringContainsString('const autoKey = [', $pageSource);
        $this->assertStringContainsString('|| !routeQuoteAutoEnabled', $pageSource);
        $this->assertStringContainsString('setRouteQuoteAutoEnabled(true)', $pageSource);
        $this->assertStringNotContainsString('|| !assignDialogOpen', $pageSource);
        $this->assertStringContainsString('queueMicrotask(() =>', $pageSource);
        $this->assertStringNotContainsString('window.setTimeout(() =>', $pageSource);
        $this->assertStringContainsString('routeQuoteLastAutoKey.current = autoKey', $pageSource);
        $this->assertStringContainsString('routeQuoteAutoRequestSeq.current', $pageSource);
        $this->assertStringContainsString('requestRouteCoordinatePair', $pageSource);
        $this->assertStringContainsString('technicianCoordinates.latitude', $pageSource);
        $this->assertStringContainsString('requestCoordinates.latitude', $pageSource);
        $this->assertStringContainsString('Usta konumu eksik; yol hakedişi manuel girilmeli.', $pageSource);
        $this->assertStringContainsString('Müşteri konumu eksik; yol hakedişi manuel girilmeli.', $pageSource);
        $this->assertStringContainsString('/route-quote', $pageSource);

        config([
            'services.google.routes_api_key' => 'test-google-routes-key',
            'services.google.routes_fee_per_km' => 10,
        ]);
        $this->fakeIsolatedHttp([
            self::TEST_GOOGLE_ROUTES_URL => Http::response($this->googleRoutesResponseForRoundTripKm(60), 200),
        ]);
        $request = $this->technicalServiceRequestWithLocation();
        $technician = $this->technicianWithLocation();

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/technicians/{$technician->id}/route-quote")
            ->assertOk()
            ->assertJsonPath('round_trip_distance_km', 60)
            ->assertJsonPath('fee_amount', 300);

        $this->assertDatabaseHas('technical_service_route_quotes', [
            'technical_service_request_id' => $request->id,
            'technician_id' => $technician->id,
            'status' => TechnicalServiceRouteQuote::STATUS_CALCULATED,
            'fee_amount' => '300.00',
        ]);
        $this->assertSame('300.00', $request->fresh()->travel_fee_amount);
    }

    public function test_final_assignment_modal_uses_canonical_labor_route_total(): void
    {
        config(['services.google.routes_fee_per_km' => 10]);
        $request = $this->technicalServiceRequestWithLocation();
        $request->forceFill([
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ])->save();
        $technician = $this->technicianWithLocation();
        $quote = $this->createCachedQuote($request, $technician, 10.0, 150.0);

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'route_quote_id' => $quote->id,
                'labor_amount' => 3000,
                'travel_amount' => 0,
                'confirm_assignment' => true,
                'assignment_offer' => [
                    'labor_amount' => 3000,
                    'route_fee_amount' => 0,
                    'total_amount' => 3000,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('request.assignment_offer.labor_amount', 3000)
            ->assertJsonPath('request.assignment_offer.route_fee_amount', 150)
            ->assertJsonPath('request.assignment_offer.total_amount', 3150)
            ->assertJsonPath('request.travel_fee_amount', '150.00');

        $offer = TechnicalServiceAssignmentOffer::query()
            ->where('technical_service_request_id', $request->id)
            ->firstOrFail();
        $this->assertSame(3000.0, (float) $offer->labor_amount);
        $this->assertSame(150.0, (float) $offer->route_fee_amount);
        $this->assertSame(3150.0, (float) $offer->total_amount);

        $pageSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));
        $this->assertIsString($pageSource);
        $this->assertStringContainsString('Yol hakedişi henüz hesaplanmadı.', $pageSource);
        $this->assertStringContainsString("travelRoundTripKm.trim() === '' ? Number.NaN", $pageSource);
    }

    public function test_route_failure_never_silently_becomes_zero(): void
    {
        $request = $this->technicalServiceRequestWithLocation();
        $technician = $this->technicianWithLocation();
        TechnicalServiceRouteQuote::query()->create([
            'technical_service_request_id' => $request->id,
            'technician_id' => $technician->id,
            'provider' => TechnicalServiceRouteQuote::PROVIDER_GOOGLE_ROUTES,
            'status' => TechnicalServiceRouteQuote::STATUS_FAILED,
            'error_code' => 'routes_unavailable',
            'error_message' => 'Rota hesaplanamadı.',
            'calculated_at' => now(),
        ]);

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technical_service_technician_id' => $technician->id,
                'travel_round_trip_km' => 0,
                'assignment_offer' => [
                    'labor_amount' => 3000,
                    'route_fee_amount' => 0,
                    'total_amount' => 3000,
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('route_quote_id');

        $this->assertNull($request->fresh()->travel_fee_amount);
        $this->assertDatabaseCount('technical_service_assignment_offers', 0);
    }

    public function test_dirty_zero_distance_quote_is_not_displayed_as_calculated_fee(): void
    {
        config([
            'services.google.routes_fee_per_km' => 10,
        ]);

        $request = $this->technicalServiceRequestWithLocation();
        $technician = $this->technicianWithLocation([
            'latitude' => 40.192,
            'longitude' => 29.061,
        ]);
        $quote = TechnicalServiceRouteQuote::query()->create([
            'technical_service_request_id' => $request->id,
            'technician_id' => $technician->id,
            'origin_latitude' => $technician->latitude,
            'origin_longitude' => $technician->longitude,
            'destination_latitude' => $request->location_latitude,
            'destination_longitude' => $request->location_longitude,
            'distance_meters' => 0,
            'distance_km' => 0,
            'duration_seconds' => 0,
            'threshold_km' => 30,
            'extra_km' => 0,
            'fee_per_km' => 10,
            'fee_amount' => 10,
            'travel_fee_required' => false,
            'provider' => TechnicalServiceRouteQuote::PROVIDER_GOOGLE_ROUTES,
            'status' => TechnicalServiceRouteQuote::STATUS_CALCULATED,
            'raw_payload' => [
                'one_way_distance_meters' => 0,
                'round_trip_distance_meters' => 0,
                'straight_line_distance_km' => 120.28,
            ],
            'calculated_at' => now(),
        ]);

        $payload = app(TechnicalServiceRouteCostService::class)->payload($quote);

        $this->assertFalse($payload['ok']);
        $this->assertSame(TechnicalServiceRouteQuote::STATUS_FAILED, $payload['status']);
        $this->assertSame(0.0, $payload['round_trip_distance_km']);
        $this->assertNull($payload['fee_amount']);
        $this->assertTrue($payload['suspicious_route']);
        $this->assertSame('Google Routes mesafe bilgisi döndürmedi. Konumlar kontrol edilmeli.', $payload['message']);
    }

    public function test_manual_route_quote_endpoint_recalculates_or_overrides_fee_without_closing_request_payload(): void
    {
        config(['services.google.routes_fee_per_km' => 10]);

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
        config(['services.partner_portal.public_url' => 'https://payments.example.test']);

        $user = $this->adminUser();
        [$request, $session, $serial] = $this->technicalServiceRequestWithSessionAndSerial();
        $technician = $this->technicianWithLocation();
        $quote = $this->createCachedQuote($request, $technician, 10.0, 150.0);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", [
                'route_quote_id' => $quote->id,
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$serial->id],
                'amount' => '150.00',
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
        $this->assertSame($request->id, $payment->technical_service_request_id);
        $this->assertSame('operation_extra_mount_fee', $payment->raw_payload['source']);
        $this->assertSame($request->id, $payment->raw_payload['technical_service_request_id']);
        $this->assertSame($request->mrn, $payment->raw_payload['request_code']);
        $this->assertSame($request->root_mrn ?: $request->mrn, $payment->raw_payload['root_mrn']);
        $this->assertSame($request->serial_number, $payment->raw_payload['serial_number']);
        $this->assertSame($request->customer_name, $payment->raw_payload['customer_name']);
        $this->assertSame($request->customer_phone, $payment->raw_payload['customer_phone']);
        $this->assertSame('manual_ops_amount', $payment->raw_payload['amount_source']);
        $this->assertSame([$serial->id], $payment->raw_payload['selected_serial_ids']);
        $this->assertSame($serial->serial_number, $payment->raw_payload['selected_serials'][0]['serial_number']);
        $this->assertSame($serial->customer_phone, $payment->raw_payload['selected_serials'][0]['customer_phone']);
        $this->assertNotEmpty($payment->payment_url);
        $this->assertStringContainsString('/mount-payment/', $payment->payment_url);
        $this->assertStringNotContainsString('/mount-payment/fake/', $payment->payment_url);
        $this->assertSame('fake', $payment->provider);
        $this->assertSame('local', $payment->raw_payload['provider_environment']);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'mount_payment_link_created',
            'title' => 'Ödeme linki oluşturuldu',
        ]);
    }

    public function test_manual_mount_payment_link_binds_request_snapshot_without_technician(): void
    {
        config(['services.partner_portal.public_url' => 'https://payments.example.test']);

        $user = $this->adminUser();
        [$request, , $serial] = $this->technicalServiceRequestWithSessionAndSerial();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", [
                'selected_serial_ids' => [$serial->id],
                'amount' => '875.00',
                'currency' => 'TRY',
                'reason' => 'manual_extra',
                'purpose' => 'manual_mount_payment',
                'note' => 'Manuel montaj ödeme linki',
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('payment.amount', 875)
            ->assertJsonPath('payment.amount_source', 'manual_ops_amount')
            ->assertJsonPath('request.id', $request->id);

        $payment = TechnicalServiceMountPayment::query()->firstOrFail();

        $this->assertSame($request->id, $payment->technical_service_request_id);
        $this->assertNull($payment->raw_payload['technician_id']);
        $this->assertSame($request->mrn, $payment->raw_payload['mrn']);
        $this->assertSame($request->mrn, $payment->raw_payload['request_code']);
        $this->assertSame($request->root_mrn ?: $request->mrn, $payment->raw_payload['root_mrn']);
        $this->assertSame($request->serial_number, $payment->raw_payload['serial_number']);
        $this->assertSame($request->customer_name, $payment->raw_payload['customer_name']);
        $this->assertSame($request->customer_phone, $payment->raw_payload['customer_phone']);
        $this->assertSame('manual_mount_payment', $payment->raw_payload['purpose']);
        $this->assertSame('manual_extra', $payment->raw_payload['reason']);
        $this->assertSame('manual_ops_amount', $payment->raw_payload['amount_source']);
        $this->assertSame([$serial->id], $payment->raw_payload['selected_serial_ids']);
        $this->assertSame($serial->serial_number, $payment->raw_payload['selected_serials'][0]['serial_number']);
    }

    public function test_payment_public_extra_mount_fee_payment_link_uses_public_payment_base_url(): void
    {
        config([
            'services.partner_portal.public_url' => 'https://payments.example.test',
            'services.public_urls.payment_base_url' => 'https://payments.example.test',
            'services.public_urls.app_url' => 'https://app.example.test',
            'app.url' => 'https://app-url.example.test',
        ]);
        $user = $this->adminUser();
        [$request, , $serial] = $this->technicalServiceRequestWithSessionAndSerial();
        $technician = $this->technicianWithLocation();

        $response = $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/mount-extra-payment", [
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$serial->id],
                'amount' => '150.00',
                'currency' => 'TRY',
                'purpose' => 'mount_extra',
            ])
            ->assertCreated();

        $payment = TechnicalServiceMountPayment::query()->firstOrFail();

        $this->assertSame($payment->payment_url, $response->json('payment.payment_url'));
        $this->assertStringStartsWith('https://payments.example.test/mount-payment/', $payment->payment_url);
    }

    public function test_payment_create_returns_payment_url_and_copy_url_contract(): void
    {
        config(['services.partner_portal.public_url' => 'https://payments.example.test']);

        $user = $this->adminUser();
        [$request, , $serial] = $this->technicalServiceRequestWithSessionAndSerial();
        $technician = $this->technicianWithLocation();

        $response = $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/mount-extra-payment", [
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$serial->id],
                'amount' => '150.00',
                'currency' => 'TRY',
                'purpose' => 'mount_extra',
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('payment.status', TechnicalServiceMountPayment::STATUS_PENDING)
            ->assertJsonPath('payment.provider', 'fake')
            ->assertJsonPath('payment.provider_mode', 'local')
            ->assertJsonPath('payment.provider_transport', 'fake_local')
            ->assertJsonPath('payment.provider_label', 'Fake/Yerel ödeme simülasyonu')
            ->assertJsonPath('payment.provider_display_label', 'Fake/Yerel ödeme simülasyonu')
            ->assertJsonPath('payment.is_fake_provider', true)
            ->assertJsonPath('payment.payment_action_kind', 'none')
            ->assertJsonPath('payment.copy_disabled_reason', null)
            ->assertJsonPath('payment.request_id', $request->id)
            ->assertJsonPath('payment.request_code', $request->mrn)
            ->assertJsonPath('payment.root_mrn', $request->root_mrn ?: $request->mrn)
            ->assertJsonPath('payment.serial_no', $request->serial_number)
            ->assertJsonPath('payment.customer_name', $request->customer_name)
            ->assertJsonPath('payment.customer_phone', $request->customer_phone);

        $paymentUrl = $response->json('payment.payment_url');

        $this->assertNotEmpty($paymentUrl);
        $this->assertSame($paymentUrl, $response->json('payment.copy_url'));
        $this->assertSame($response->json('payment.provider_reference'), $response->json('payment.provider_token'));
        $this->assertSame($paymentUrl, $response->json('request.sale_and_payment.mount_payments.pending_rows.0.copy_url'));
        $this->assertSame($paymentUrl, $response->json('request.sale_and_payment.extra_mount_payment.copy_url'));
    }

    public function test_direct_iyzico_http_fake_create_returns_copyable_url(): void
    {
        config([
            'payments.provider' => 'iyzico',
            'payments.provider_name' => 'iyzico',
            'payments.provider_transport' => 'direct_laravel',
            'payments.real_provider_enabled' => true,
            'payments.gateway.mode' => 'sandbox',
            'payments.gateway.health_verified' => true,
            'payments.gateway.http_enabled' => true,
            'payments.gateway.credential_source' => 'laravel_encrypted_pending_bridge',
            'payments.gateway.credentials_ready' => true,
            'payments.gateway.dry_run' => false,
            'payments.gateway.no_send' => false,
            'payments.gateway.allow_provider_send' => true,
            'services.public_urls.trusted_payment_provider_origins' => ['https://sandbox-payment.example.test'],
            'services.public_urls.profiles.uat_public' => [
                'environment' => 'UAT',
                'origin' => 'https://uat-public.example.test',
                'active' => true,
                'revision' => 3,
            ],
            'services.public_urls.profiles.production_public' => [
                'environment' => 'PROD',
                'origin' => 'https://prod-public.example.test',
                'active' => true,
                'revision' => 4,
            ],
        ]);

        $this->assertTrue(PartnerPortalPublicUrl::resolveProfile('staging', 'live', 'live')['ready']);
        $this->assertTrue(PartnerPortalPublicUrl::resolveProfile('production', 'live', 'live')['ready']);

        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('sandbox', 'TEST_SANDBOX_API_KEY', 'TEST_SANDBOX_SECRET_KEY');
        app(TechnicalServicePaymentProviderSettingsService::class)->update([
            'company_recipient' => [
                'company_address' => 'Route quote firma tahsilat adresi',
            ],
        ]);

        $client = new class implements PaymentProviderGatewayClient
        {
            public bool $called = false;

            public ?PaymentProviderGatewayRequest $lastRequest = null;

            public function send(PaymentProviderGatewayRequest $request): PaymentProviderGatewayResponse
            {
                $this->called = true;
                $this->lastRequest = $request;

                return PaymentProviderGatewayResponse::fromArray([
                    'ok' => true,
                    'provider' => 'iyzico',
                    'mode' => 'sandbox',
                    'operation' => $request->operation(),
                    'provider_token' => 'direct-copy-token',
                    'payment_url' => 'https://sandbox-payment.example.test/direct-copy-token',
                    'provider_status' => 'ACTIVE',
                    'provider_response_redacted' => ['status' => 'success'],
                ]);
            }
        };
        $this->app->instance(PaymentProviderGatewayClient::class, $client);

        $user = $this->adminUser();
        [$request, , $serial] = $this->technicalServiceRequestWithSessionAndSerial();
        $technician = $this->technicianWithLocation();

        $response = $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/mount-extra-payment", [
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$serial->id],
                'amount' => '150.00',
                'currency' => 'TRY',
                'purpose' => 'mount_extra',
            ])
            ->assertCreated()
            ->assertJsonPath('payment.provider', 'iyzico')
            ->assertJsonPath('payment.provider_mode', 'sandbox')
            ->assertJsonPath('payment.provider_transport', 'direct_laravel')
            ->assertJsonPath('payment.provider_label', 'Iyzico Sandbox')
            ->assertJsonPath('payment.provider_display_label', 'Iyzico Sandbox')
            ->assertJsonPath('payment.is_external_provider', true)
            ->assertJsonPath('payment.payment_action_kind', 'open_provider_url')
            ->assertJsonPath('payment.payment_action_label', 'Iyzico ödeme ekranını aç')
            ->assertJsonPath('payment.can_open_payment_url', true)
            ->assertJsonPath('payment.can_fake_complete_payment', false)
            ->assertJsonPath('payment.copy_disabled_reason', null)
            ->assertJsonPath('payment.provider_token', 'direct-copy-token')
            ->assertJsonPath('payment.provider_status', 'ACTIVE')
            ->assertJsonPath('payment.payment_url', 'https://sandbox-payment.example.test/direct-copy-token')
            ->assertJsonPath('payment.copy_url', 'https://sandbox-payment.example.test/direct-copy-token')
            ->assertJsonPath('request.sale_and_payment.mount_payments.pending_rows.0.copy_url', 'https://sandbox-payment.example.test/direct-copy-token');

        $this->assertTrue($client->called);
        $this->assertSame('create_link', $client->lastRequest?->operation());
        $this->assertSame($response->json('payment.payment_url'), TechnicalServiceMountPayment::query()->firstOrFail()->payment_url);

        config()->set('services.public_urls.trusted_payment_provider_origins', []);
        $gatewayProvenanceOnly = (new TechnicalServiceMountPayment)->forceFill([
            'provider' => 'iyzico',
            'provider_reference' => 'direct-copy-token',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 150,
            'currency' => 'TRY',
            'payment_url' => 'https://sandbox-payment.example.test/direct-copy-token',
            'raw_payload' => [
                'provider_mode' => 'sandbox',
                'provider_transport' => 'direct_laravel',
                'provider_gateway' => [
                    'ok' => true,
                    'dry_run' => false,
                    'no_send' => false,
                    'provider_token' => 'direct-copy-token',
                    'payment_url' => 'https://sandbox-payment.example.test/direct-copy-token',
                ],
            ],
        ]);
        $blocked = TechnicalServicePaymentActionPresenter::forPayment($gatewayProvenanceOnly);
        $this->assertNull($blocked['payment_url']);
        $this->assertNull($blocked['copy_url']);
        $this->assertFalse($blocked['can_open_payment_url']);
        $this->assertSame('LEGACY_PUBLIC_URL_ORIGIN_NOT_ALLOWED', $blocked['public_url_blocker_code']);
    }

    public function test_extra_mount_fee_payment_link_reuses_existing_pending_session(): void
    {
        config(['services.partner_portal.public_url' => 'https://payments.example.test']);

        $user = $this->adminUser();
        [$request, , $serial] = $this->technicalServiceRequestWithSessionAndSerial();
        $technician = $this->technicianWithLocation();

        $payload = [
            'technician_id' => $technician->id,
            'selected_serial_ids' => [$serial->id],
            'amount' => '150.00',
            'currency' => 'TRY',
            'purpose' => 'mount_extra',
        ];

        $firstResponse = $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/mount-extra-payment", $payload)
            ->assertCreated()
            ->assertJsonPath('payment.reused', false);

        $firstPaymentId = $firstResponse->json('payment.id');
        $firstPaymentUrl = $firstResponse->json('payment.payment_url');

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/mount-extra-payment", $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Ödeme linki zaten var.')
            ->assertJsonPath('payment.id', $firstPaymentId)
            ->assertJsonPath('payment.payment_url', $firstPaymentUrl)
            ->assertJsonPath('payment.copy_url', $firstPaymentUrl)
            ->assertJsonPath('payment.provider_mode', 'local')
            ->assertJsonPath('payment.provider_transport', 'fake_local')
            ->assertJsonPath('payment.reused', true);

        $this->assertSame(2, TechnicalServiceMountPayment::query()->count());
        $this->assertSame(1, TechnicalServiceMountPayment::query()
            ->where('status', TechnicalServiceMountPayment::STATUS_PENDING)
            ->count());
        $this->assertSame(1, TechnicalServiceMountPayment::query()
            ->where('status', TechnicalServiceMountPayment::STATUS_CANCELLED)
            ->whereNotNull('raw_payload->canonical_payment_duplicate')
            ->count());
        $this->assertSame(1, $request->events()->where('event_type', 'mount_payment_link_created')->count());
    }

    public function test_technical_service_shortcut_cannot_bypass_manager_for_unsafe_pending(): void
    {
        config(['services.partner_portal.public_url' => 'https://payments.example.test']);
        $user = $this->adminUser();
        [$request, , $serial] = $this->technicalServiceRequestWithSessionAndSerial();
        $payload = [
            'selected_serial_ids' => [$serial->id],
            'amount' => '150.00',
            'currency' => 'TRY',
            'purpose' => 'manual_mount_payment',
            'reason' => 'manual_extra',
        ];
        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/mount-extra-payment", $payload)
            ->assertCreated();
        $unsafe = TechnicalServiceMountPayment::query()->sole();
        $rawPayload = is_array($unsafe->raw_payload) ? $unsafe->raw_payload : [];
        unset($rawPayload['canonical_payment_session_authority']);
        $unsafe->forceFill([
            'provider_reference' => null,
            'payment_url' => null,
            'raw_payload' => $rawPayload,
        ])->save();
        $this->mock(FakePaymentProvider::class, function ($mock): void {
            $mock->shouldNotReceive('createPayment');
        });

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/mount-extra-payment", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment')
            ->assertJsonPath(
                'errors.payment.0',
                'Mevcut ödeme bağlantısı güvenli biçimde kullanılamıyor. Ödeme geçmişini kontrol edip açıklamalı yeni bağlantı oluşturun.',
            );

        $this->assertSame(1, TechnicalServiceMountPayment::query()->count());
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $unsafe->fresh()->status);
        $this->assertNull($unsafe->fresh()->provider_reference);
        $this->assertNull($unsafe->fresh()->payment_url);
    }

    public function test_payment_status_endpoint_returns_fresh_request_and_next_action(): void
    {
        config(['services.partner_portal.public_url' => 'https://payments.example.test']);

        PageConfig::query()->updateOrCreate(
            ['page_code' => 'technical_service_admin'],
            ['layout_json' => [
                'technical_service' => [
                    'qr' => [
                        'pre_form_payment_for_mount_excluded_enabled' => true,
                    ],
                ],
            ]],
        );

        $user = $this->adminUser();
        [$request, , $serial] = $this->technicalServiceRequestWithSessionAndSerial();
        $request->forceFill([
            'operation_control_payload' => [
                'door_photos_checked' => 'compatible',
            ],
        ])->save();
        $technician = $this->technicianWithLocation();

        $response = $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/mount-extra-payment", [
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$serial->id],
                'amount' => '150.00',
                'currency' => 'TRY',
                'purpose' => 'mount_extra',
            ])
            ->assertCreated();

        $payment = TechnicalServiceMountPayment::query()->firstOrFail();

        $this->actingAs($user)
            ->getJson("/api/technical-service/requests/{$request->id}/payments/{$payment->id}/status")
            ->assertOk()
            ->assertJsonPath('payment.status', TechnicalServiceMountPayment::STATUS_PENDING)
            ->assertJsonPath('request.id', $request->id)
            ->assertJsonPath('request.next_action_payload.code', 'payment_pending')
            ->assertJsonPath('request.next_action_payload.primary_action', 'create_payment_link');

        $this->assertSame($response->json('payment.payment_url'), $payment->payment_url);
    }

    public function test_iyzico_provider_without_keys_returns_safe_validation_error(): void
    {
        config([
            'payments.provider' => 'iyzico_sandbox',
            'payments.provider_name' => 'iyzico',
            'payments.real_provider_enabled' => true,
            'payments.gateway.url' => null,
            'payments.gateway.token' => null,
            'payments.gateway.health_verified' => false,
            'payments.gateway.http_enabled' => false,
        ]);
        $config = PageConfig::query()->firstOrCreate(
            ['page_code' => TechnicalServicePaymentProviderSettingsService::PAGE_CODE],
            ['layout_json' => []],
        );
        $layout = is_array($config->layout_json) ? $config->layout_json : [];
        data_set($layout, TechnicalServicePaymentProviderSettingsService::COMPANY_RECIPIENT_KEY, [
            'company_address' => 'Route quote firma tahsilat adresi',
        ]);
        $config->forceFill(['layout_json' => $layout])->save();

        $user = $this->adminUser();
        [$request, , $serial] = $this->technicalServiceRequestWithSessionAndSerial();
        $technician = $this->technicianWithLocation();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/mount-extra-payment", [
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$serial->id],
                'amount' => '150.00',
                'currency' => 'TRY',
                'purpose' => 'mount_extra',
            ])
            ->assertJsonValidationErrors('payment');

        $failedPayment = TechnicalServiceMountPayment::query()->sole();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_FAILED, $failedPayment->status);
        $this->assertTrue(collect((array) data_get($failedPayment->raw_payload, 'canonical_payment_create_history'))
            ->contains(fn (mixed $entry): bool => is_array($entry)
                && ($entry['status'] ?? null) === 'failed'
                && ($entry['replay_blocked'] ?? null) === true));
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

    public function test_payment_identity_inputs_fail_closed_before_provider(): void
    {
        $user = $this->adminUser();
        [$request, , $serial] = $this->technicalServiceRequestWithSessionAndSerial();
        $technician = $this->technicianWithLocation();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", [
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$serial->id],
                'amount' => 3500,
                'currency' => 'TRY',
                'purpose' => 'mount_extra',
            ])
            ->assertJsonValidationErrors('amount');

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", [
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$serial->id],
                'amount' => 3.5e3,
                'currency' => 'TRY',
                'purpose' => 'mount_extra',
            ])
            ->assertJsonValidationErrors('amount');

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", [
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$serial->id],
                'amount' => '3.5e3',
                'currency' => 'TRY',
                'purpose' => 'mount_extra',
            ])
            ->assertJsonValidationErrors('amount');

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", [
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$serial->id],
                'amount' => '10000000000.00',
                'currency' => 'TRY',
                'purpose' => 'mount_extra',
            ])
            ->assertJsonValidationErrors('amount');

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", [
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$serial->id],
                'amount' => '3500.00',
                'currency' => 'TRY',
                'purpose' => 'mount_extra',
                'charge_type' => 'route_fee',
            ])
            ->assertJsonValidationErrors('charge_type');

        [$foreignRequest, , $foreignSerial] = $this->technicalServiceRequestWithSessionAndSerial();
        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", [
                'technician_id' => $technician->id,
                'selected_serial_ids' => [$foreignSerial->id],
                'amount' => '3500.00',
                'currency' => 'TRY',
                'purpose' => 'mount_extra',
            ])
            ->assertJsonValidationErrors('selected_serial_ids');

        $foreignPart = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $foreignRequest->id,
            'root_request_id' => $foreignRequest->id,
            'status' => TechnicalServicePartRequest::STATUS_OPS_REVIEW,
            'part_name' => 'Foreign scoped part',
        ]);
        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/payments/extra-mount-fee", [
                'selected_serial_ids' => [$serial->id],
                'amount' => '3500.00',
                'part_amount' => '3500.00',
                'currency' => 'TRY',
                'purpose' => 'part_payment',
                'charge_type' => 'part_payment',
                'part_request_id' => $foreignPart->id,
            ])
            ->assertJsonValidationErrors('part_request_id');

        $this->assertSame(0, TechnicalServiceMountPayment::query()->count());
    }

    public function test_fake_approve_extra_mount_fee_marks_request_and_selected_serials_paid(): void
    {
        config([
            'payments.provider' => 'fake',
            'payments.enable_fake_approve' => true,
        ]);

        [$request, $session, $serial] = $this->technicalServiceRequestWithSessionAndSerial();

        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $request->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-extra-'.uniqid(),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 150,
            'currency' => 'TRY',
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
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

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($request->refresh(), true);

        $this->assertSame(150.0, $payload['extra_customer_payment']);
        $this->assertSame(150.0, $payload['total_customer_collected']);
        $this->assertSame(150.0, $payload['sale_and_payment']['payment_summary']['total_customer_collection']);
        $this->assertNull($payload['sale_and_payment']['technician_earning_message']);
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

    public function test_route_suggestion_card_uses_display_safe_city_district(): void
    {
        $user = $this->adminUser();
        TechnicalServiceTechnician::query()->create([
            'name' => 'SMOKE-SCOPE-20260606021857 Other Usta',
            'phone' => '+905550005620',
            'city' => '?stanbul',
            'district' => 'Kad?k?y',
            'address' => '?stanbul · Kad?k?y',
            'active' => true,
        ]);

        $item = collect($this->actingAs($user)
            ->getJson('/api/technical-service/technicians?active=1')
            ->assertOk()
            ->json('items'))
            ->firstWhere('phone', '+905550005620');

        $this->assertIsArray($item);
        $this->assertSame('SMOKE-SCOPE-20260606021857 Diğer Usta', $item['name']);
        $this->assertSame('İstanbul', $item['city']);
        $this->assertSame('Kadıköy', $item['district']);
        $this->assertSame('İstanbul · Kadıköy', $item['address']);

        $encoded = json_encode($item, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('?stanbul', $encoded);
        $this->assertStringNotContainsString('Kad?k?y', $encoded);
        $this->assertStringNotContainsString('Other Usta', $encoded);
        $this->assertStringNotContainsString('�', $encoded);
    }

    public function test_technical_service_label_normalizer_is_idempotent_for_valid_turkish_and_converts_akilli_kapi_variants(): void
    {
        $valid = 'İstanbul / Kadıköy / Müşteri / Fotoğraf / Çilingir';

        $this->assertSame($valid, TechnicalServiceUiLabelService::cleanDisplayText($valid));
        $this->assertSame('İstanbul / Kadıköy', TechnicalServiceUiLabelService::cleanDisplayText('?stanbul / Kad?k?y'));
        $this->assertSame('Müşteri Planlı Tamamlandı', TechnicalServiceUiLabelService::cleanDisplayText('M??teri Planl? Tamamland?'));
        $this->assertSame('Usta işi kabul etti', TechnicalServiceUiLabelService::cleanDisplayText('Usta iÅŸi kabul etti'));
        $this->assertSame('Fotoğraf / Müşteri / Çilingir', TechnicalServiceUiLabelService::cleanDisplayText('FotoÄŸraf / MÃ¼ÅŸteri / Ã‡ilingir'));
        $this->assertSame('Acceptance Akıllı Kapı Kilidi', TechnicalServiceUiLabelService::cleanDisplayText('Acceptance Ak?ll? Kap? Kilidi'));
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

        $normalizedDetailsSource = preg_replace('/\s+/', ' ', $detailsSource) ?? $detailsSource;

        foreach ([
            'için usta yol hakedişi henüz hesaplanmadı.',
            'Usta yol hakedişini hesaplamak için seçili usta ve müşteri konumu kullanılacak.',
            'Usta yol hakedişi hesaplanıyor...',
            'Yeniden hesapla',
            'routeQuoteActiveForSelectedTechnician',
            'activeRouteQuote',
            'Usta ↔ müşteri düz çizgi mesafesi',
            'Google Routes tek yön mesafesi',
            'Ödeme linki tutarı',
            'Ödeme linki oluştur',
            'Linki müşteriye gönder',
            'Teknik detay',
            'Rota mesafesi düz çizgi mesafesine göre yüksek',
            'Montaj durumu',
            'Km başı ücret',
            'Hesaplanmadı',
            'storedRouteRoundTripKm',
            'storedRouteBillableKm',
            'storedRouteFeeAmount',
            'hasRouteCostEvidence',
            'shouldShowRouteQuoteLoading',
            "hasRouteCostEvidence ? numericInputValue(routeOneWayKm) : ''",
            "hasRouteCostEvidence ? numericInputValue(routeBillableKm) : '0'",
            'Usta yol hakedişi kaydedildi',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $normalizedDetailsSource);
        }

        foreach ([
            'Seçili usta değişti. Yeni usta için yol ücreti tekrar hesaplanmalı.',
            'Bu usta için yol ücretini hesapla',
            'Yol hesabı durumu',
            'Mesafe uyumsuzluğu - kontrol gerekli',
            'Koordinat kontrol gerekli',
            'Usta koordinatı kontrol gerekli. Yol ücreti otomatik onaylanmamalı.',
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
            'requestRouteCoordinatePair',
            'routeQuoteActiveForSelection',
            'routeQuoteAutoRequestSeq',
            'routeQuoteLatestSelection',
            'routeQuoteLastAutoKey',
            "setTravelRoundTripKm('')",
            'setRouteQuoteLoading(true)',
            'setRouteQuoteLoading(false)',
            'queueMicrotask(() =>',
            'routeQuoteLatestSelection.current.requestId !== submittedRequestId',
            'routeQuoteLatestSelection.current.technicianId !== submittedTechnicianId',
            'const responseStatus = typeof response.status',
            "responseStatus !== 'calculated'",
            'routeQuoteFailed',
            'if (!updatedRequest)',
            'setSelectedDetailRequest(updatedRequest)',
            'Yaklaşık şehir/adres mesafesi',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $pageSource);
        }

        $this->assertStringNotContainsString('window.setTimeout(() =>', $pageSource);

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
            'Müşteri açık adresi',
            'Google Routes tek yön mesafesi',
            'Ücretsiz sınır',
            'Ücrete tabi km',
            'Km başı ücret',
            'Usta yol hakedişi hesaplanamadı',
            'Usta yol hakedişi gönderilmeli',
            'Usta yol hakedişi yok',
            'Usta konumu eksik',
            'Müşteri konumu eksik',
            'Atanan servis',
            'Servis telefonu',
            'Diğer serileri kontrol et',
            'Usta hakedişi / yol düzenle',
            'Usta yol hakedişi',
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
            'Yeniden hesapla',
            'Usta yol hakedişi hesabı',
            'Tek yön yol mesafesi',
            'Gidiş-geliş mesafe',
            'Ücrete tabi km',
            'Tahmini usta yol hakedişi',
            'route-quote',
            'payments/mount-extra-payment',
            'handleExtraMountPaymentCreate',
            'assignmentPartnerJobPath',
            'assignmentFinalMessagePreview',
            'Son hakediş onayı',
            'Atama sonrası mesaj durumu server ayarları ve kanal politikasıyla belirlenir',
            'İş kartı:',
            'Atamayı onayla ve mesajı hazırla',
            'loadRequestDetail(updatedRequest?.id ?? selectedId)',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $pageSource);
        }

        $this->assertStringNotContainsString('Usta → müşteri mesafesi', $detailsSource);
        $this->assertStringNotContainsString('Usta → müşteri mesafesi', $pageSource);
        $this->assertStringNotContainsString('Servise ata ve mesaj gönder', $pageSource);

        foreach ([
            'Usta yol hakedişi hesaplanamadı',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $cardSource);
        }

        $this->assertStringNotContainsString('Usta yol hakedişi kontrolü', $cardSource);
        $this->assertStringNotContainsString('Yol ücreti onayı gerekli', $cardSource);
        $this->assertStringNotContainsString('Yol ücreti yok', $cardSource);
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
     * @param  array<string, mixed>  $overrides
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
     * @param  array<string, mixed>  $overrides
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
