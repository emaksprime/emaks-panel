<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceRequest;
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
            ->assertJsonPath('distance_km', 45)
            ->assertJsonPath('extra_km', 15)
            ->assertJsonPath('travel_fee_required', true)
            ->assertJsonPath('fee_amount', 150)
            ->assertJsonPath('message', '30 km üstü yol ücreti gerekli.')
            ->assertJsonPath('request.route_quote.distance_km', 45)
            ->assertJsonPath('request.route_quote.message', '30 km üstü yol ücreti gerekli.');
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
            'Google Routes sonucu yok.',
            'Routes hesaplanmadan ekstra km hesaplanmaz.',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $detailsSource);
        }

        foreach ([
            'validCoordinatePair',
            'technicianCoordinatePair',
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
            '30 km ücretsiz sınır',
            'Yol ücreti hesaplanamadı',
            'Yol ücreti onayı gerekli',
            'Yol ücreti yok',
            'Usta konumu eksik',
            'Müşteri konumu eksik',
            'Atanan servis',
            'Servis telefonu',
            'Faturadaki diğer serileri gör',
            'Yol ücreti / fiyat düzenle',
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
            'Google Routes yol ücreti hesabı',
            'Usta → müşteri mesafesi',
            'Tahmini yol ücreti',
            'route-quote',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $pageSource);
        }

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
        $this->assertSame(round($roundTripKm, 2), $payload['distance_km']);
        $this->assertSame($feeRequired, $payload['travel_fee_required']);
        $this->assertSame($extraKm, $payload['extra_km']);
        $this->assertSame($feeAmount, $payload['fee_amount']);
    }

    /**
     * @return array<string, mixed>
     */
    private function googleRoutesResponseForRoundTripKm(float $roundTripKm): array
    {
        return [
            'routes' => [
                [
                    'distanceMeters' => (int) round(($roundTripKm * 1000) / 2),
                    'duration' => '1800s',
                ],
            ],
        ];
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

    private function technicianWithLocation(): TechnicalServiceTechnician
    {
        return TechnicalServiceTechnician::query()->create([
            'name' => 'Rota Usta',
            'phone' => '+905555555552',
            'city' => 'İstanbul',
            'active' => true,
            'latitude' => 41.025,
            'longitude' => 29.015,
        ]);
    }
}
