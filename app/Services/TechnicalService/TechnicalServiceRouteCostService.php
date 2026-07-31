<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRouteQuote;
use App\Models\TechnicalServiceTechnician;
use Illuminate\Support\Facades\Http;
use Throwable;

class TechnicalServiceRouteCostService
{
    private const ROUTES_ENDPOINT = 'https://routes.googleapis.com/directions/v2:computeRoutes';
    private const THRESHOLD_KM = 30.0;

    /**
     * @return array<string, mixed>
     */
    public function quote(TechnicalServiceRequest $request, TechnicalServiceTechnician $technician, bool $force = false): array
    {
        $origin = $this->technicianCoordinates($technician);
        $destination = $this->requestCoordinates($request);

        if ($destination === null) {
            return $this->storeSafeQuote(
                $request,
                $technician,
                TechnicalServiceRouteQuote::STATUS_MISSING_LOCATION,
                'Müşteri konumu eksik.',
                $origin,
                null,
            );
        }

        if ($origin === null) {
            return $this->storeSafeQuote(
                $request,
                $technician,
                TechnicalServiceRouteQuote::STATUS_MISSING_LOCATION,
                'Usta konumu eksik.',
                null,
                $destination,
            );
        }

        if (! $force) {
            $cached = $this->cachedQuote($request, $technician, $origin, $destination);

            if ($cached !== null) {
                return $this->payload($cached);
            }
        }

        $apiKey = trim((string) config('services.google.routes_api_key', ''));

        if ($apiKey === '') {
            return $this->storeSafeQuote(
                $request,
                $technician,
                TechnicalServiceRouteQuote::STATUS_MISSING_API_KEY,
                'Google Routes API anahtarı tanımlı değil.',
                $origin,
                $destination,
            );
        }

        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration',
            ])->post(self::ROUTES_ENDPOINT, [
                'origin' => [
                    'location' => [
                        'latLng' => [
                            'latitude' => $origin['latitude'],
                            'longitude' => $origin['longitude'],
                        ],
                    ],
                ],
                'destination' => [
                    'location' => [
                        'latLng' => [
                            'latitude' => $destination['latitude'],
                            'longitude' => $destination['longitude'],
                        ],
                    ],
                ],
                'travelMode' => 'DRIVE',
                'routingPreference' => 'TRAFFIC_UNAWARE',
                'languageCode' => 'tr-TR',
                'units' => 'METRIC',
            ]);

            if (! $response->successful()) {
                return $this->storeSafeQuote(
                    $request,
                    $technician,
                    TechnicalServiceRouteQuote::STATUS_FAILED,
                    'Google Routes mesafe hesabı tamamlanamadı.',
                    $origin,
                    $destination,
                    $response->json(),
                );
            }

            $body = $response->json();
            $route = is_array($body) ? ($body['routes'][0] ?? null) : null;
            $oneWayMeters = is_array($route) ? (int) ($route['distanceMeters'] ?? 0) : 0;

            if ($oneWayMeters <= 0) {
                return $this->storeSafeQuote(
                    $request,
                    $technician,
                    TechnicalServiceRouteQuote::STATUS_FAILED,
                    'Google Routes mesafe bilgisi döndürmedi.',
                    $origin,
                    $destination,
                    $body,
                );
            }

            $durationSeconds = $this->parseDurationSeconds(is_array($route) ? ($route['duration'] ?? null) : null);
            $roundTripMeters = $oneWayMeters * 2;
            $roundTripKm = round($roundTripMeters / 1000, 2);
            $extraKm = round(max($roundTripKm - self::THRESHOLD_KM, 0), 2);
            $feePerKm = $this->feePerKm();
            $feeAmount = $extraKm > 0
                ? ($feePerKm !== null ? round($extraKm * $feePerKm, 2) : null)
                : 0.0;

            $quote = TechnicalServiceRouteQuote::query()->create([
                'technical_service_request_id' => $request->id,
                'technician_id' => $technician->id,
                'origin_latitude' => $origin['latitude'],
                'origin_longitude' => $origin['longitude'],
                'destination_latitude' => $destination['latitude'],
                'destination_longitude' => $destination['longitude'],
                'distance_meters' => $roundTripMeters,
                'distance_km' => $roundTripKm,
                'duration_seconds' => $durationSeconds,
                'threshold_km' => self::THRESHOLD_KM,
                'extra_km' => $extraKm,
                'fee_per_km' => $feePerKm,
                'fee_amount' => $feeAmount,
                'travel_fee_required' => $extraKm > 0,
                'provider' => TechnicalServiceRouteQuote::PROVIDER_GOOGLE_ROUTES,
                'status' => TechnicalServiceRouteQuote::STATUS_CALCULATED,
                'raw_payload' => [
                    'google_routes' => $body,
                    'one_way_distance_meters' => $oneWayMeters,
                    'round_trip_distance_meters' => $roundTripMeters,
                ],
                'calculated_at' => now(),
            ]);

            return $this->payload($quote);
        } catch (Throwable) {
            return $this->storeSafeQuote(
                $request,
                $technician,
                TechnicalServiceRouteQuote::STATUS_FAILED,
                'Google Routes mesafe hesabı sırasında hata oluştu.',
                $origin,
                $destination,
            );
        }
    }

    private function cachedQuote(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        array $origin,
        array $destination,
    ): ?TechnicalServiceRouteQuote {
        return $request->routeQuotes()
            ->where('technician_id', $technician->id)
            ->where('status', TechnicalServiceRouteQuote::STATUS_CALCULATED)
            ->latest('calculated_at')
            ->get()
            ->first(fn (TechnicalServiceRouteQuote $quote): bool => $this->coordinatesMatch($quote, $origin, $destination));
    }

    /**
     * @return array{latitude:float,longitude:float}|null
     */
    private function technicianCoordinates(TechnicalServiceTechnician $technician): ?array
    {
        $latitude = $technician->latitude ?? $technician->start_latitude;
        $longitude = $technician->longitude ?? $technician->start_longitude;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
        ];
    }

    /**
     * @return array{latitude:float,longitude:float}|null
     */
    private function requestCoordinates(TechnicalServiceRequest $request): ?array
    {
        if ($request->location_latitude === null || $request->location_longitude === null) {
            return null;
        }

        return [
            'latitude' => (float) $request->location_latitude,
            'longitude' => (float) $request->location_longitude,
        ];
    }

    /**
     * @param array{latitude:float,longitude:float}|null $origin
     * @param array{latitude:float,longitude:float}|null $destination
     * @param array<string, mixed>|null $rawPayload
     *
     * @return array<string, mixed>
     */
    private function storeSafeQuote(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        string $status,
        string $message,
        ?array $origin,
        ?array $destination,
        ?array $rawPayload = null,
    ): array {
        $quote = TechnicalServiceRouteQuote::query()->create([
            'technical_service_request_id' => $request->id,
            'technician_id' => $technician->id,
            'origin_latitude' => $origin['latitude'] ?? null,
            'origin_longitude' => $origin['longitude'] ?? null,
            'destination_latitude' => $destination['latitude'] ?? null,
            'destination_longitude' => $destination['longitude'] ?? null,
            'threshold_km' => self::THRESHOLD_KM,
            'extra_km' => 0,
            'fee_per_km' => $this->feePerKm(),
            'travel_fee_required' => false,
            'provider' => TechnicalServiceRouteQuote::PROVIDER_GOOGLE_ROUTES,
            'status' => $status,
            'error_message' => $message,
            'raw_payload' => $rawPayload,
            'calculated_at' => now(),
        ]);

        return $this->payload($quote);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(TechnicalServiceRouteQuote $quote): array
    {
        return [
            'ok' => $quote->status === TechnicalServiceRouteQuote::STATUS_CALCULATED,
            'id' => $quote->id,
            'status' => $quote->status,
            'distance_km' => $quote->distance_km !== null ? (float) $quote->distance_km : null,
            'distance_meters' => $quote->distance_meters,
            'duration_seconds' => $quote->duration_seconds,
            'duration_text' => $this->durationText($quote->duration_seconds),
            'threshold_km' => $quote->threshold_km !== null ? (float) $quote->threshold_km : self::THRESHOLD_KM,
            'extra_km' => $quote->extra_km !== null ? (float) $quote->extra_km : 0.0,
            'travel_fee_required' => (bool) $quote->travel_fee_required,
            'fee_per_km' => $quote->fee_per_km !== null ? (float) $quote->fee_per_km : null,
            'fee_amount' => $quote->fee_amount !== null ? (float) $quote->fee_amount : null,
            'provider' => $quote->provider,
            'calculated_at' => $quote->calculated_at?->toISOString(),
            'message' => $this->messageFor($quote),
        ];
    }

    private function coordinatesMatch(TechnicalServiceRouteQuote $quote, array $origin, array $destination): bool
    {
        return $this->sameCoordinate($quote->origin_latitude, $origin['latitude'])
            && $this->sameCoordinate($quote->origin_longitude, $origin['longitude'])
            && $this->sameCoordinate($quote->destination_latitude, $destination['latitude'])
            && $this->sameCoordinate($quote->destination_longitude, $destination['longitude']);
    }

    private function sameCoordinate(mixed $left, float $right): bool
    {
        return abs(((float) $left) - $right) < 0.000001;
    }

    private function feePerKm(): ?float
    {
        $value = config('services.google.routes_fee_per_km');

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function parseDurationSeconds(mixed $duration): ?int
    {
        if (! is_string($duration) || $duration === '') {
            return null;
        }

        if (preg_match('/^(\d+)s$/', $duration, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function durationText(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        $minutes = (int) ceil($seconds / 60);

        if ($minutes < 60) {
            return "{$minutes} dk";
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0 ? "{$hours} sa {$remainingMinutes} dk" : "{$hours} sa";
    }

    private function messageFor(TechnicalServiceRouteQuote $quote): string
    {
        if ($quote->status === TechnicalServiceRouteQuote::STATUS_MISSING_API_KEY) {
            return 'Google Routes API anahtarı tanımlı değil.';
        }

        if ($quote->status === TechnicalServiceRouteQuote::STATUS_MISSING_LOCATION) {
            return $quote->error_message ?: 'Yol ücreti hesaplanamadı.';
        }

        if ($quote->status === TechnicalServiceRouteQuote::STATUS_FAILED) {
            return $quote->error_message ?: 'Yol ücreti hesaplanamadı.';
        }

        if ((bool) $quote->travel_fee_required) {
            return $quote->fee_amount === null
                ? 'Yol ücreti hesaplanacak. Km başı ücret ayarı eksik.'
                : '30 km üstü yol ücreti gerekli.';
        }

        return '30 km ücretsiz sınır içinde.';
    }
}
