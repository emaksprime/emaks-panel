<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServiceGeocodingService;
use App\Services\TechnicalService\TechnicianGeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

    public function test_common_geocode_service_flags_generic_city_result_for_review(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Ankara, Türkiye',
                    'geometry' => [
                        'location_type' => 'APPROXIMATE',
                        'location' => ['lat' => 39.933365, 'lng' => 32.859742],
                    ],
                ]],
            ], 200),
        ]);

        $result = app(TechnicalServiceGeocodingService::class)->geocodeText('Ankara', 'cari_address', [
            'city' => 'Ankara',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['needs_review']);
        $this->assertSame('review_required: generic_city_result', $result['review_reason']);
        $this->assertSame(39.933365, $result['latitude']);
        $this->assertSame(32.859742, $result['longitude']);
    }

    public function test_common_geocode_service_flags_city_mismatch_for_review(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Tunalı Hilmi Cad. No:1, Çankaya/Ankara, Türkiye',
                    'geometry' => [
                        'location_type' => 'ROOFTOP',
                        'location' => ['lat' => 39.92077, 'lng' => 32.85411],
                    ],
                ]],
            ], 200),
        ]);

        $result = app(TechnicalServiceGeocodingService::class)->geocodeText('İzmir adres', 'address', [
            'city' => 'İzmir',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['needs_review']);
        $this->assertStringContainsString('review_required: city_mismatch İzmir vs Ankara', (string) $result['review_reason']);
        $this->assertSame(39.92077, $result['latitude']);
        $this->assertSame(32.85411, $result['longitude']);
    }

    public function test_plus_code_detailed_result_is_accepted(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Manisa Organize Sanayi Bölgesi, Yunusemre/Manisa, Türkiye',
                    'geometry' => [
                        'location_type' => 'ROOFTOP',
                        'location' => ['lat' => 38.619099, 'lng' => 27.428921],
                    ],
                ]],
            ], 200),
        ]);

        $result = app(TechnicalServiceGeocodingService::class)->geocodeText('8G7C+X5 Manisa', 'google_plus_code', [
            'city' => 'Manisa',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['needs_review']);
        $this->assertSame(38.619099, $result['latitude']);
        $this->assertSame(27.428921, $result['longitude']);
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

        $cityOnlyTechnician = new TechnicalServiceTechnician([
            'name' => 'Sadece Şehir Usta',
            'city' => 'Ankara',
            'district' => 'Çankaya',
        ]);
        $this->assertNull($service->bestQueryFor($cityOnlyTechnician));

        $this->assertNull($service->bestQueryFor(new TechnicalServiceTechnician(['name' => 'Boş Usta'])));
    }

    public function test_command_dry_run_does_not_write_and_update_persists_coordinates(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Alsancak Mahallesi, Konak/İzmir, Türkiye',
                    'geometry' => [
                        'location_type' => 'ROOFTOP',
                        'location' => ['lat' => 38.423734, 'lng' => 27.142826],
                    ],
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
        $this->assertFalse($technician->needs_review);
        $this->assertStringContainsString('Geocoded from google_plus_code', (string) $technician->route_note);
        $this->assertStringNotContainsString('test-geocoding-key', (string) $technician->route_note);
    }

    public function test_command_writes_city_mismatch_coordinates_as_review_required(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Tunalı Hilmi Cad. No:1, Çankaya/Ankara, Türkiye',
                    'geometry' => [
                        'location_type' => 'ROOFTOP',
                        'location' => ['lat' => 39.92077, 'lng' => 32.85411],
                    ],
                ]],
            ], 200),
        ]);

        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Mismatch Usta',
            'phone' => '+905555555556',
            'city' => 'İzmir',
            'address' => 'Test Mahallesi No 1',
            'active' => true,
        ]);

        $exit = Artisan::call('technical-service:geocode-technicians', [
            '--id' => [$technician->id],
            '--force' => true,
            '--sleep-ms' => 0,
        ]);

        $this->assertSame(0, $exit);
        $technician->refresh();
        $this->assertSame('39.9207700', $technician->latitude);
        $this->assertSame('32.8541100', $technician->longitude);
        $this->assertTrue($technician->needs_review);
        $this->assertStringContainsString('review_required: city_mismatch İzmir vs Ankara', (string) $technician->route_note);
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

    public function test_technician_update_marks_coordinates_stale_when_address_changes(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Stale Usta',
            'first_name' => 'Stale',
            'phone' => '+905555555557',
            'city' => 'İzmir',
            'address' => 'Eski adres',
            'latitude' => '38.4237340',
            'longitude' => '27.1428260',
            'start_latitude' => '38.4237340',
            'start_longitude' => '27.1428260',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/technicians/{$technician->id}", [
                'first_name' => 'Stale',
                'city' => 'İzmir',
                'address' => 'Yeni adres',
                'latitude' => 38.423734,
                'longitude' => 27.142826,
                'start_latitude' => 38.423734,
                'start_longitude' => 27.142826,
                'active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('technician.needs_review', true);

        $technician->refresh();
        $this->assertTrue($technician->needs_review);
        $this->assertStringContainsString('Adres değişti, koordinat yeniden doğrulanmalı', (string) $technician->route_note);
    }

    public function test_manual_lat_lng_update_is_validated_and_marks_manual_source(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Manual Usta',
            'first_name' => 'Manual',
            'phone' => '+905555555558',
            'city' => 'İzmir',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/technicians/{$technician->id}", [
                'first_name' => 'Manual',
                'city' => 'İzmir',
                'latitude' => 38.423734,
                'longitude' => 27.142826,
                'active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('technician.location_source', 'manual')
            ->assertJsonPath('technician.needs_review', false);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/technicians/{$technician->id}", [
                'first_name' => 'Manual',
                'city' => 'İzmir',
                'latitude' => 0,
                'longitude' => 0,
                'active' => true,
            ])
            ->assertStatus(422);
    }

    public function test_technician_geocode_endpoint_updates_coordinates_with_quality_rules(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Manisa Organize Sanayi Bölgesi, Yunusemre/Manisa, Türkiye',
                    'geometry' => [
                        'location_type' => 'ROOFTOP',
                        'location' => ['lat' => 38.619099, 'lng' => 27.428921],
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Endpoint Usta',
            'first_name' => 'Endpoint',
            'phone' => '+905555555559',
            'city' => 'Manisa',
            'google_plus_code' => '8G7C+X5 Manisa',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/technicians/{$technician->id}/geocode")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('technician.latitude', '38.6190990')
            ->assertJsonPath('technician.longitude', '27.4289210')
            ->assertJsonPath('technician.needs_review', false);
    }

    public function test_technician_geocode_dry_run_does_not_write_coordinates(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Organize Sanayi Bölgesi, Yunusemre/Manisa, Türkiye',
                    'geometry' => [
                        'location_type' => 'ROOFTOP',
                        'location' => ['lat' => 38.619099, 'lng' => 27.428921],
                    ],
                ]],
            ], 200),
        ]);
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Dry Run Endpoint Usta',
            'first_name' => 'Dry Run',
            'phone' => '+905555555561',
            'city' => 'Manisa',
            'district' => 'Yunusemre',
            'address' => 'Organize Sanayi Bölgesi',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/technicians/{$technician->id}/geocode", [
                'dry_run' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('can_apply', true)
            ->assertJsonPath('plan.source_type', 'address')
            ->assertJsonPath('result.ok', true);

        $technician->refresh();
        $this->assertNull($technician->latitude);
        $this->assertNull($technician->longitude);
        Http::assertSentCount(1);
    }

    public function test_geocode_dry_run_provider_validation_zero_results_returns_warning_without_write(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'ZERO_RESULTS',
                'results' => [],
            ], 200),
        ]);
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Zero Results Usta',
            'first_name' => 'Zero',
            'phone' => '+905555555563',
            'city' => 'İzmir',
            'district' => 'Bornova',
            'address' => 'Çözülemeyen adres',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/technicians/{$technician->id}/geocode", [
                'dry_run' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('can_apply', false)
            ->assertJsonPath('result.provider_status', 'ZERO_RESULTS')
            ->assertJsonPath('message', 'Adres Google tarafından çözülemedi. Lütfen plus code veya açık adresi düzelt.');

        $technician->refresh();
        $this->assertNull($technician->latitude);
        $this->assertNull($technician->longitude);
    }

    public function test_geocode_apply_zero_results_does_not_write_coordinates(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'ZERO_RESULTS',
                'results' => [],
            ], 200),
        ]);
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Apply Zero Usta',
            'first_name' => 'Apply',
            'phone' => '+905555555564',
            'city' => 'İzmir',
            'district' => 'Bornova',
            'address' => 'Çözülemeyen adres',
            'latitude' => '38.4237340',
            'longitude' => '27.1428260',
            'start_latitude' => '38.4237340',
            'start_longitude' => '27.1428260',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/technicians/{$technician->id}/geocode", [
                'override_existing_coordinates' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('result.provider_status', 'ZERO_RESULTS');

        $technician->refresh();
        $this->assertSame('38.4237340', $technician->latitude);
        $this->assertSame('27.1428260', $technician->longitude);
        $this->assertTrue($technician->needs_review);
    }

    public function test_short_plus_code_is_resolved_with_city_context_and_fallbacks_to_full_address(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fakeSequence()
            ->push([
                'status' => 'ZERO_RESULTS',
                'results' => [],
            ], 200)
            ->push([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Kazımdirik, 402. Sk. No:11 D:3, Bornova/İzmir, Türkiye',
                    'geometry' => [
                        'location_type' => 'ROOFTOP',
                        'location' => ['lat' => 38.4621, 'lng' => 27.2177],
                    ],
                ]],
            ], 200);
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Short Plus Usta',
            'first_name' => 'Short',
            'phone' => '+905555555565',
            'city' => 'İzmir',
            'district' => 'Bornova',
            'address' => 'Kazımdirik, 402. Sk. No:11 D:3',
            'google_plus_code' => 'C5XJ+3P',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/technicians/{$technician->id}/geocode")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.fallback_from.source_type', 'google_plus_code')
            ->assertJsonPath('technician.latitude', '38.4621000');

        Http::assertSent(function ($request): bool {
            return str_contains((string) $request->url(), 'maps.googleapis.com')
                && str_contains(rawurldecode((string) $request->url()), 'C5XJ+3P, Bornova, İzmir, Türkiye');
        });
        Http::assertSent(function ($request): bool {
            return str_contains(rawurldecode((string) $request->url()), 'Kazımdirik, 402. Sk. No:11 D:3, Bornova, İzmir, Türkiye');
        });
    }

    public function test_manual_location_fix_clears_review_when_complete(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Review Manual Usta',
            'first_name' => 'Review',
            'phone' => null,
            'city' => null,
            'needs_review' => true,
            'review_status' => 'review_required',
            'review_reason' => 'Telefon eksik. Adres/şehir eksik. Koordinat eksik.',
            'review_reasons' => ['Telefon eksik.', 'Adres/şehir eksik.', 'Koordinat eksik.'],
            'active' => true,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/technicians/{$technician->id}/location-review", [
                'phone' => '+905555555562',
                'city' => 'İzmir',
                'district' => 'Konak',
                'address' => 'Manuel Mahallesi No:1',
                'latitude' => 38.423734,
                'longitude' => 27.142826,
                'start_latitude' => 38.423734,
                'start_longitude' => 27.142826,
                'mark_reviewed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('technician.needs_review', false)
            ->assertJsonPath('technician.review_status', 'reviewed');

        $technician->refresh();
        $this->assertFalse((bool) $technician->needs_review);
        $this->assertSame('reviewed', $technician->review_status);
        $this->assertSame([], $technician->review_reasons);
        $this->assertSame($user->id, $technician->reviewed_by);
        $this->assertNotNull($technician->reviewed_at);
    }

    public function test_mark_reviewed_fails_if_required_fields_missing(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Eksik Kontrol Usta',
            'first_name' => 'Eksik',
            'phone' => '+905555555563',
            'city' => 'İstanbul',
            'needs_review' => true,
            'active' => true,
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/technicians/{$technician->id}/mark-reviewed")
            ->assertStatus(422)
            ->assertJsonPath('errors.mark_reviewed.0', 'Kontrol kapatılamaz: telefon/adres/koordinat eksik.');

        $this->assertTrue((bool) $technician->refresh()->needs_review);
    }

    public function test_mark_reviewed_sets_reviewed_at_and_reviewed_by(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Tam Kontrol Usta',
            'first_name' => 'Tam',
            'phone' => '+905555555564',
            'city' => 'İstanbul',
            'address' => 'Tam Mahallesi No:1',
            'latitude' => '41.0082376',
            'longitude' => '28.9783589',
            'start_latitude' => '41.0082376',
            'start_longitude' => '28.9783589',
            'needs_review' => true,
            'review_status' => 'review_required',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/technicians/{$technician->id}/mark-reviewed")
            ->assertOk()
            ->assertJsonPath('technician.needs_review', false)
            ->assertJsonPath('technician.review_status', 'reviewed');

        $technician->refresh();
        $this->assertFalse((bool) $technician->needs_review);
        $this->assertSame('reviewed', $technician->review_status);
        $this->assertSame($user->id, $technician->reviewed_by);
        $this->assertNotNull($technician->reviewed_at);
    }

    public function test_coordinate_validation_command_keeps_reviewable_city_mismatch_coordinates(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Berkay Example',
            'phone' => '+905555555560',
            'city' => 'İzmir',
            'latitude' => '39.9207700',
            'longitude' => '32.8541100',
            'start_latitude' => '39.9207700',
            'start_longitude' => '32.8541100',
            'location_source' => 'google_geocode',
            'route_note' => 'Geocoded from cari_address; formatted: Tunalı Hilmi Cad. No:1, Çankaya/Ankara, Türkiye; at 2026-05-18 10:00:00',
            'active' => true,
        ]);

        $exit = Artisan::call('technical-service:validate-technician-coordinates', [
            '--clear-invalid' => true,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $technician->refresh();
        $this->assertTrue($technician->needs_review);
        $this->assertSame('39.9207700', $technician->latitude);
        $this->assertSame('32.8541100', $technician->longitude);
        $this->assertStringContainsString('city_mismatch', (string) $technician->route_note);
    }

    public function test_coordinate_validation_default_marks_review_without_clearing_coordinates(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Default Validate Usta',
            'phone' => '+905555555572',
            'city' => 'Ä°zmir',
            'latitude' => '0.0000000',
            'longitude' => '0.0000000',
            'start_latitude' => '0.0000000',
            'start_longitude' => '0.0000000',
            'active' => true,
        ]);

        $exit = Artisan::call('technical-service:validate-technician-coordinates');

        $this->assertSame(0, $exit, Artisan::output());
        $technician->refresh();
        $this->assertTrue($technician->needs_review);
        $this->assertSame('0.0000000', $technician->latitude);
        $this->assertSame('0.0000000', $technician->longitude);
    }

    public function test_coordinate_validation_command_keeps_reviewable_generic_city_coordinates(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Generic City Usta',
            'phone' => '+905555555573',
            'city' => 'Ankara',
            'latitude' => '39.9333650',
            'longitude' => '32.8597420',
            'start_latitude' => '39.9333650',
            'start_longitude' => '32.8597420',
            'location_source' => 'google_geocode',
            'route_note' => 'Geocoded from cari_address; formatted: Ankara, Turkey; at 2026-05-18 10:00:00',
            'active' => true,
        ]);

        $exit = Artisan::call('technical-service:validate-technician-coordinates', [
            '--clear-invalid' => true,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $technician->refresh();
        $this->assertTrue($technician->needs_review);
        $this->assertSame('39.9333650', $technician->latitude);
        $this->assertSame('32.8597420', $technician->longitude);
        $this->assertStringContainsString('generic_city_country_result', (string) $technician->route_note);
    }

    public function test_coordinate_validation_command_clears_only_impossible_coordinates(): void
    {
        $zeroZero = TechnicalServiceTechnician::query()->create([
            'name' => 'Zero Zero',
            'phone' => '+905555555570',
            'city' => 'İzmir',
            'latitude' => '0.0000000',
            'longitude' => '0.0000000',
            'start_latitude' => '0.0000000',
            'start_longitude' => '0.0000000',
            'active' => true,
        ]);
        $outsideTurkey = TechnicalServiceTechnician::query()->create([
            'name' => 'Outside Turkey',
            'phone' => '+905555555571',
            'city' => 'İzmir',
            'latitude' => '52.5200080',
            'longitude' => '13.4049540',
            'start_latitude' => '52.5200080',
            'start_longitude' => '13.4049540',
            'active' => true,
        ]);
        DB::table('technical_service_technicians')->insert([
            'name' => 'Non Numeric',
            'phone' => '+905555555574',
            'city' => 'Ä°zmir',
            'latitude' => 'not-a-number',
            'longitude' => '27.1428260',
            'start_latitude' => 'not-a-number',
            'start_longitude' => '27.1428260',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('technical-service:validate-technician-coordinates', [
            '--clear-invalid' => true,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertNull($zeroZero->refresh()->latitude);
        $this->assertNull($zeroZero->longitude);
        $this->assertNull($outsideTurkey->refresh()->latitude);
        $this->assertNull($outsideTurkey->longitude);
        $nonNumeric = DB::table('technical_service_technicians')->where('name', 'Non Numeric')->first();
        $this->assertNull($nonNumeric->latitude);
        $this->assertNull($nonNumeric->longitude);
    }

    public function test_frontend_contains_stale_and_manual_coordinate_controls(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-technicians.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('Adres değişti, koordinat yeniden doğrulanmalı', $source);
        $this->assertStringContainsString('Mevcut koordinat eski adrese ait olabilir', $source);
        $this->assertStringContainsString('Google ile koordinatı güncelle', $source);
        $this->assertStringContainsString('Manuel koordinat', $source);
        $this->assertStringContainsString('Koordinat geçersiz. Latitude/Longitude 0/0 olamaz.', $source);
    }

    public function test_coordinate_export_excludes_review_and_suspicious_duplicate_coordinates(): void
    {
        $good = TechnicalServiceTechnician::query()->create([
            'name' => 'Export Good',
            'phone' => '+905555555551',
            'phone_e164' => '+905555555551',
            'city' => 'Istanbul',
            'source_key' => 'locksmith:+905555555551:ISTANBUL',
            'latitude' => '41.0082376',
            'longitude' => '28.9783589',
            'start_latitude' => '41.0082376',
            'start_longitude' => '28.9783589',
            'location_source' => 'google_geocode',
            'route_note' => 'Geocoded from google_plus_code; formatted: Sultanahmet Mahallesi, Fatih/Istanbul, Turkiye; at 2026-05-18 10:00:00',
            'needs_review' => false,
            'active' => true,
        ]);
        TechnicalServiceTechnician::query()->create([
            'name' => 'Review Export',
            'phone' => '+905555555552',
            'phone_e164' => '+905555555552',
            'city' => 'Ankara',
            'source_key' => 'locksmith:+905555555552:ANKARA',
            'latitude' => '39.9333650',
            'longitude' => '32.8597420',
            'needs_review' => true,
            'active' => true,
        ]);
        TechnicalServiceTechnician::query()->create([
            'name' => 'Generic Export',
            'phone' => '+905555555561',
            'phone_e164' => '+905555555561',
            'city' => 'Ankara',
            'source_key' => 'generic-city',
            'latitude' => '39.9333650',
            'longitude' => '32.8597420',
            'location_source' => 'google_geocode',
            'route_note' => 'Geocoded from cari_address; formatted: Ankara, Türkiye; at 2026-05-18 10:00:00',
            'needs_review' => false,
            'active' => true,
        ]);

        foreach (['Ankara', 'Izmir', 'Mugla'] as $index => $city) {
            TechnicalServiceTechnician::query()->create([
                'name' => 'Duplicate '.$index,
                'phone' => '+90555555556'.$index,
                'phone_e164' => '+90555555556'.$index,
                'city' => $city,
                'source_key' => 'duplicate-'.$index,
                'latitude' => '38.9637450',
                'longitude' => '35.2433220',
                'active' => true,
            ]);
        }

        $outputPath = storage_path('framework/testing/technical_service_technician_coordinates.json');
        if (is_file($outputPath)) {
            unlink($outputPath);
        }

        $exit = Artisan::call('technical-service:export-technician-coordinates', [
            '--output' => $outputPath,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertFileExists($outputPath);
        $data = json_decode((string) file_get_contents($outputPath), true);

        $this->assertIsArray($data);
        $this->assertCount(1, $data['items']);
        $this->assertSame($good->source_key, $data['items'][0]['source_key']);
        $this->assertSame('google_plus_code', $data['items'][0]['geocode_quality']);
        $this->assertArrayNotHasKey('phone', $data['items'][0]);
        $this->assertNotContains('locksmith:+905555555552:ANKARA', array_column($data['items'], 'source_key'));
        $this->assertNotContains('generic-city', array_column($data['items'], 'source_key'));
        $this->assertNotContains('duplicate-0', array_column($data['items'], 'source_key'));
    }

    public function test_coordinate_seeder_updates_by_source_key_and_phone_city_without_name_only_match(): void
    {
        $sourceKeyTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Source Match',
            'phone' => '+905555555551',
            'phone_e164' => '+905555555551',
            'city' => 'Istanbul',
            'source_key' => 'locksmith:+905555555551:ISTANBUL',
            'active' => true,
        ]);
        $phoneCityTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Phone City Match',
            'phone' => '+905555555552',
            'phone_e164' => '+905555555552',
            'city' => 'Ankara',
            'active' => true,
        ]);
        $nameOnlyTechnician = TechnicalServiceTechnician::query()->create([
            'name' => 'Name Only Match',
            'phone' => '+905555555553',
            'phone_e164' => '+905555555553',
            'city' => 'Izmir',
            'active' => true,
        ]);

        $path = storage_path('framework/testing/coordinate-seed.json');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode([
            'items' => [
                [
                    'source_key' => $sourceKeyTechnician->source_key,
                    'phone_e164' => '+900000000000',
                    'city' => 'Wrong City',
                    'name' => 'Source Match',
                    'latitude' => 41.0082376,
                    'longitude' => 28.9783589,
                    'start_latitude' => 41.0082376,
                    'start_longitude' => 28.9783589,
                    'location_source' => 'google_geocode',
                    'route_note' => 'Geocoded from google_plus_code; formatted: Istanbul, Turkiye',
                    'needs_review' => false,
                ],
                [
                    'source_key' => 'missing-source',
                    'phone_e164' => $phoneCityTechnician->phone_e164,
                    'city' => $phoneCityTechnician->city,
                    'name' => 'Different Name',
                    'latitude' => 39.933365,
                    'longitude' => 32.859742,
                    'location_source' => 'google_geocode',
                    'route_note' => 'Geocoded from address; formatted: Ankara, Turkiye',
                    'needs_review' => false,
                ],
                [
                    'name' => $nameOnlyTechnician->name,
                    'city' => $nameOnlyTechnician->city,
                    'latitude' => 38.423734,
                    'longitude' => 27.142826,
                    'needs_review' => false,
                ],
                [
                    'source_key' => 'review-source',
                    'phone_e164' => '+905555555554',
                    'city' => 'Bursa',
                    'latitude' => 40.188528,
                    'longitude' => 29.060964,
                    'needs_review' => true,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        config(['technical_service.technician_coordinate_seed_data_path' => $path]);

        Artisan::call('db:seed', ['--class' => 'TechnicalServiceTechnicianCoordinateSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'TechnicalServiceTechnicianCoordinateSeeder', '--force' => true]);

        $sourceKeyTechnician->refresh();
        $phoneCityTechnician->refresh();
        $nameOnlyTechnician->refresh();

        $this->assertSame('41.0082376', $sourceKeyTechnician->latitude);
        $this->assertSame('28.9783589', $sourceKeyTechnician->longitude);
        $this->assertSame('google_geocode', $sourceKeyTechnician->location_source);
        $this->assertFalse($sourceKeyTechnician->needs_review);

        $this->assertSame('39.9333650', $phoneCityTechnician->latitude);
        $this->assertSame('32.8597420', $phoneCityTechnician->longitude);
        $this->assertFalse($phoneCityTechnician->needs_review);

        $this->assertNull($nameOnlyTechnician->latitude);
        $this->assertNull($nameOnlyTechnician->longitude);
        $this->assertSame(3, TechnicalServiceTechnician::query()->count());
    }
}
