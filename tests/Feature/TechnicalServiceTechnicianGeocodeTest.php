<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceTechnician;
use App\Services\TechnicalService\TechnicalServiceGeocodingService;
use App\Services\TechnicalService\TechnicianGeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TechnicalServiceTechnicianGeocodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_common_geocode_service_returns_valid_lat_lng(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Konak, İzmir, Türkiye',
                    'geometry' => ['location' => ['lat' => 38.423734, 'lng' => 27.142826]],
                ]],
            ], 200),
        ]);

        $result = app(TechnicalServiceGeocodingService::class)->geocodeText('Konak, İzmir, Türkiye', 'address');

        $this->assertTrue($result['ok']);
        $this->assertSame(38.423734, $result['latitude']);
        $this->assertSame(27.142826, $result['longitude']);
        $this->assertSame('Konak, İzmir, Türkiye', $result['formatted_address']);
        $this->assertSame('address_fallback', $result['quality']);
    }

    public function test_common_geocode_service_rejects_zero_zero(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Zero Island',
                    'geometry' => ['location' => ['lat' => 0, 'lng' => 0]],
                ]],
            ], 200),
        ]);

        $result = app(TechnicalServiceGeocodingService::class)->geocodeText('Test adres', 'address');

        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $result['quality']);
    }

    public function test_common_geocode_service_returns_safe_error_when_api_key_is_missing(): void
    {
        config([
            'services.google.geocoding_api_key' => null,
            'services.google.places_api_key' => null,
            'services.google.routes_api_key' => null,
        ]);
        Http::fake();

        $result = app(TechnicalServiceGeocodingService::class)->geocodeText('Konak, İzmir, Türkiye', 'address');

        $this->assertFalse($result['ok']);
        $this->assertSame('missing_api_key', $result['status']);
        $this->assertSame('Google geocoding key tanımlı değil.', $result['error']);
        Http::assertNothingSent();
    }

    public function test_query_builder_uses_expected_priority_and_skips_empty_addresses(): void
    {
        $service = app(TechnicianGeocodingService::class);

        $plusCodeTechnician = new TechnicalServiceTechnician([
            'name' => 'Plus Code Usta',
            'google_plus_code' => '394F+84 Bodrum, Muğla',
            'location_code' => 'Location code should not win',
        ]);
        $this->assertSame('google_plus_code', $service->bestQueryFor($plusCodeTechnician)['source_type'] ?? null);
        $this->assertSame('exact_plus_code', $service->bestQueryFor($plusCodeTechnician)['quality'] ?? null);

        $formattedAddressTechnician = new TechnicalServiceTechnician([
            'name' => 'Formatlı Usta',
            'google_formatted_address' => 'Konak, İzmir, Türkiye',
        ]);
        $this->assertSame('google_formatted_address', $service->bestQueryFor($formattedAddressTechnician)['source_type'] ?? null);
        $this->assertSame('formatted_address', $service->bestQueryFor($formattedAddressTechnician)['quality'] ?? null);

        $fallbackAddressTechnician = new TechnicalServiceTechnician([
            'name' => 'Adresli Usta',
            'address' => 'Test Mahallesi Test Sokak No 1',
            'district' => 'Kadıköy',
            'city' => 'İstanbul',
        ]);
        $fallback = $service->bestQueryFor($fallbackAddressTechnician);
        $this->assertSame('address', $fallback['source_type'] ?? null);
        $this->assertSame('address_fallback', $fallback['quality'] ?? null);
        $this->assertStringContainsString('Türkiye', $fallback['query'] ?? '');

        $this->assertNull($service->bestQueryFor(new TechnicalServiceTechnician(['name' => 'Boş Usta'])));
    }

    public function test_command_dry_run_does_not_write_and_update_persists_coordinates(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Test formatted address',
                    'geometry' => ['location' => ['lat' => 38.423734, 'lng' => 27.142826]],
                ]],
            ], 200),
        ]);

        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Geocode Usta',
            'phone' => '+905555555555',
            'city' => 'İzmir',
            'google_plus_code' => '8G7C+X5 İzmir',
            'active' => true,
        ]);

        $dryRunExit = Artisan::call('technical-service:geocode-technicians', [
            '--dry-run' => true,
            '--id' => [$technician->id],
            '--sleep-ms' => 0,
        ]);

        $this->assertSame(0, $dryRunExit);
        $this->assertNull($technician->refresh()->latitude);
        $this->assertNull($technician->longitude);

        $updateExit = Artisan::call('technical-service:geocode-technicians', [
            '--id' => [$technician->id],
            '--force' => true,
            '--sleep-ms' => 0,
        ]);

        $this->assertSame(0, $updateExit);
        $technician->refresh();
        $this->assertSame('38.4237340', $technician->latitude);
        $this->assertSame('27.1428260', $technician->longitude);
        $this->assertSame('38.4237340', $technician->start_latitude);
        $this->assertSame('27.1428260', $technician->start_longitude);
        $this->assertSame('google_geocode', $technician->location_source);
        $this->assertStringContainsString('Geocoded from google_plus_code', (string) $technician->route_note);
        $this->assertStringNotContainsString('test-geocoding-key', (string) $technician->route_note);
    }

    public function test_command_returns_safe_error_when_api_key_is_missing(): void
    {
        config([
            'services.google.geocoding_api_key' => null,
            'services.google.places_api_key' => null,
            'services.google.routes_api_key' => null,
        ]);

        $exit = Artisan::call('technical-service:geocode-technicians', [
            '--dry-run' => true,
            '--sleep-ms' => 0,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Google geocoding key tanımlı değil.', Artisan::output());
    }
}
