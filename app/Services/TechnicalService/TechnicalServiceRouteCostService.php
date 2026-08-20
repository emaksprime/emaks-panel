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

    private const SAME_LOCATION_GUARD_KM = 0.1;

    private const SUSPICIOUS_STRAIGHT_LINE_MAX_KM = 2.0;

    private const SUSPICIOUS_ROUTE_MIN_ONE_WAY_KM = 5.0;

    public function __construct(
        private readonly TechnicianGeocodingService $technicianGeocoding,
        private readonly TechnicalServiceGeocodingService $geocoding,
    ) {}

    /**
     * @return array{threshold_km:float,fee_per_km:?float}
     */
    public function feeConfig(): array
    {
        return [
            'threshold_km' => self::THRESHOLD_KM,
            'fee_per_km' => $this->feePerKm(),
        ];
    }

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

        $straightLineKm = $this->haversineKm($origin, $destination);

        if ($straightLineKm <= self::SAME_LOCATION_GUARD_KM) {
            $quote = TechnicalServiceRouteQuote::query()->create([
                'technical_service_request_id' => $request->id,
                'technician_id' => $technician->id,
                'origin_latitude' => $origin['latitude'],
                'origin_longitude' => $origin['longitude'],
                'destination_latitude' => $destination['latitude'],
                'destination_longitude' => $destination['longitude'],
                'distance_meters' => 0,
                'distance_km' => 0,
                'duration_seconds' => 0,
                'threshold_km' => self::THRESHOLD_KM,
                'extra_km' => 0,
                'fee_per_km' => $this->feePerKm(),
                'fee_amount' => 0,
                'travel_fee_required' => false,
                'provider' => TechnicalServiceRouteQuote::PROVIDER_SAME_LOCATION_GUARD,
                'status' => TechnicalServiceRouteQuote::STATUS_CALCULATED,
                'raw_payload' => [
                    'same_location_guard' => true,
                    'straight_line_distance_km' => $straightLineKm,
                    'one_way_distance_meters' => 0,
                    'round_trip_distance_meters' => 0,
                ],
                'calculated_at' => now(),
            ]);

            $this->syncRequestTravelSummary($request, $quote);

            return $this->payload($quote);
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
            $oneWayKm = round($oneWayMeters / 1000, 2);
            $roundTripMeters = $oneWayMeters * 2;
            $roundTripKm = round($roundTripMeters / 1000, 2);
            $extraKm = round(max($roundTripKm - self::THRESHOLD_KM, 0), 2);
            $feePerKm = $this->feePerKm();
            $feeAmount = $extraKm > 0
                ? ($feePerKm !== null ? round($extraKm * $feePerKm, 2) : null)
                : 0.0;
            $suspiciousRoute = $straightLineKm > self::SAME_LOCATION_GUARD_KM
                && $straightLineKm <= self::SUSPICIOUS_STRAIGHT_LINE_MAX_KM
                && $oneWayKm > self::SUSPICIOUS_ROUTE_MIN_ONE_WAY_KM;

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
                    'straight_line_distance_km' => $straightLineKm,
                    'suspicious_route' => $suspiciousRoute,
                ],
                'calculated_at' => now(),
            ]);

            $this->syncRequestTravelSummary($request, $quote);

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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function manualQuote(TechnicalServiceRequest $request, ?TechnicalServiceTechnician $technician, array $payload): array
    {
        $origin = $technician instanceof TechnicalServiceTechnician
            ? $this->technicianCoordinates($technician)
            : null;
        $destination = $this->requestCoordinates($request);

        $thresholdKm = $this->numericOrNull($payload['threshold_km'] ?? null) ?? self::THRESHOLD_KM;
        $oneWayKm = $this->numericOrNull($payload['one_way_distance_km'] ?? null);
        $roundTripKm = $this->numericOrNull($payload['round_trip_distance_km'] ?? null);

        if ($roundTripKm === null && $oneWayKm !== null) {
            $roundTripKm = round($oneWayKm * 2, 2);
        }

        if ($oneWayKm === null && $roundTripKm !== null) {
            $oneWayKm = round($roundTripKm / 2, 2);
        }

        $roundTripKm = round(max($roundTripKm ?? 0.0, 0.0), 2);
        $oneWayKm = round(max($oneWayKm ?? ($roundTripKm / 2), 0.0), 2);
        $billableKm = round(max($roundTripKm - $thresholdKm, 0), 2);
        $feePerKm = $this->numericOrNull($payload['fee_per_km'] ?? null) ?? $this->feePerKm();
        $manualOverride = filter_var($payload['manual_override'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $manualFeeAmount = $this->numericOrNull($payload['fee_amount'] ?? null);
        $feeAmount = $manualOverride && $manualFeeAmount !== null
            ? round(max($manualFeeAmount, 0), 2)
            : ($billableKm > 0
                ? ($feePerKm !== null ? round($billableKm * $feePerKm, 2) : null)
                : 0.0);

        $manualNote = trim((string) ($payload['manual_note'] ?? ''));

        $quote = TechnicalServiceRouteQuote::query()->create([
            'technical_service_request_id' => $request->id,
            'technician_id' => $technician?->id,
            'origin_latitude' => $origin['latitude'] ?? null,
            'origin_longitude' => $origin['longitude'] ?? null,
            'destination_latitude' => $destination['latitude'] ?? null,
            'destination_longitude' => $destination['longitude'] ?? null,
            'distance_meters' => (int) round($roundTripKm * 1000),
            'distance_km' => $roundTripKm,
            'duration_seconds' => null,
            'threshold_km' => $thresholdKm,
            'extra_km' => $billableKm,
            'fee_per_km' => $feePerKm,
            'fee_amount' => $feeAmount,
            'travel_fee_required' => $billableKm > 0,
            'provider' => TechnicalServiceRouteQuote::PROVIDER_MANUAL_OVERRIDE,
            'status' => TechnicalServiceRouteQuote::STATUS_CALCULATED,
            'raw_payload' => [
                'manual_override' => $manualOverride,
                'manual_note' => $manualNote !== '' ? $manualNote : null,
                'one_way_distance_km' => $oneWayKm,
                'round_trip_distance_km' => $roundTripKm,
                'billable_km' => $billableKm,
            ],
            'calculated_at' => now(),
        ]);

        $this->syncRequestTravelSummary($request, $quote);

        return $this->payload($quote);
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
            ->first(fn (TechnicalServiceRouteQuote $quote): bool => $this->coordinatesMatch($quote, $origin, $destination)
                && $this->cachedQuoteIsUsable($quote));
    }

    /**
     * @return array{latitude:float,longitude:float}|null
     */
    private function technicianCoordinates(TechnicalServiceTechnician $technician): ?array
    {
        $coordinates = $this->geocoding->validCoordinatePair($technician->latitude, $technician->longitude)
            ?? $this->geocoding->validCoordinatePair($technician->start_latitude, $technician->start_longitude);

        if ($coordinates === null && $this->technicianGeocoding->bestQueryFor($technician) !== null) {
            $geocoded = $this->technicianGeocoding->geocode($technician);
            if (($geocoded['ok'] ?? false) === true) {
                $coordinates = $this->geocoding->validCoordinatePair(
                    $geocoded['latitude'] ?? null,
                    $geocoded['longitude'] ?? null,
                );
            }
        }

        if ($coordinates === null) {
            return null;
        }

        return [
            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],
        ];
    }

    /**
     * @return array{latitude:float,longitude:float}|null
     */
    private function requestCoordinates(TechnicalServiceRequest $request): ?array
    {
        $coordinates = $this->geocoding->validCoordinatePair(
            $request->location_latitude,
            $request->location_longitude,
        );

        if ($coordinates !== null) {
            return [
                'latitude' => $coordinates['latitude'],
                'longitude' => $coordinates['longitude'],
            ];
        }

        if ($request->parent_request_id !== null) {
            $request->loadMissing('parentRequest');
            $parent = $request->parentRequest;

            if ($parent instanceof TechnicalServiceRequest) {
                $parentCoordinates = $this->geocoding->validCoordinatePair(
                    $parent->location_latitude,
                    $parent->location_longitude,
                );

                if ($parentCoordinates !== null) {
                    return [
                        'latitude' => $parentCoordinates['latitude'],
                        'longitude' => $parentCoordinates['longitude'],
                    ];
                }
            }
        }

        if ($request->location_latitude === null || $request->location_longitude === null) {
            return null;
        }

        return [
            'latitude' => (float) $request->location_latitude,
            'longitude' => (float) $request->location_longitude,
        ];
    }

    /**
     * @param  array{latitude:float,longitude:float}|null  $origin
     * @param  array{latitude:float,longitude:float}|null  $destination
     * @param  array<string, mixed>|null  $rawPayload
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
        $canonical = $this->canonicalDistances($quote);
        $dirtyZeroDistance = $this->isDirtyZeroDistanceQuote($quote, $canonical);
        $currentFeePerKm = $this->feePerKm();
        $feePerKmMatchesCurrent = $canonical['fee_per_km'] !== null
            && $currentFeePerKm !== null
            && abs($canonical['fee_per_km'] - $currentFeePerKm) <= 0.001;
        $status = $dirtyZeroDistance ? TechnicalServiceRouteQuote::STATUS_FAILED : $quote->status;
        $feeAmount = $dirtyZeroDistance ? null : $canonical['fee_amount'];

        return [
            'ok' => $status === TechnicalServiceRouteQuote::STATUS_CALCULATED,
            'id' => $quote->id,
            'technician_id' => $quote->technician_id,
            'status' => $status,
            'origin_latitude' => $quote->origin_latitude !== null ? (float) $quote->origin_latitude : null,
            'origin_longitude' => $quote->origin_longitude !== null ? (float) $quote->origin_longitude : null,
            'destination_latitude' => $quote->destination_latitude !== null ? (float) $quote->destination_latitude : null,
            'destination_longitude' => $quote->destination_longitude !== null ? (float) $quote->destination_longitude : null,
            'one_way_distance_km' => $canonical['one_way_distance_km'],
            'round_trip_distance_km' => $canonical['round_trip_distance_km'],
            'distance_km' => $quote->distance_km !== null ? (float) $quote->distance_km : null,
            'distance_meters' => $quote->distance_meters,
            'duration_seconds' => $quote->duration_seconds,
            'duration_text' => $this->durationText($quote->duration_seconds),
            'threshold_km' => $canonical['threshold_km'],
            'billable_km' => $canonical['billable_km'],
            'extra_km' => $canonical['billable_km'],
            'straight_line_distance_km' => $this->quoteStraightLineKm($quote),
            'suspicious_route' => $dirtyZeroDistance || (bool) data_get($quote->raw_payload, 'suspicious_route', false),
            'travel_fee_required' => $dirtyZeroDistance ? false : (bool) $quote->travel_fee_required,
            'fee_per_km' => $canonical['fee_per_km'],
            'current_fee_per_km' => $currentFeePerKm,
            'fee_per_km_matches_current' => $feePerKmMatchesCurrent,
            'fee_amount' => $feeAmount,
            'provider' => $quote->provider,
            'source' => $quote->provider,
            'manual_override' => (bool) data_get($quote->raw_payload, 'manual_override', false),
            'manual_note' => data_get($quote->raw_payload, 'manual_note'),
            'calculated_at' => $quote->calculated_at?->toISOString(),
            'message' => $dirtyZeroDistance
                ? 'Google Routes mesafe bilgisi döndürmedi. Konumlar kontrol edilmeli.'
                : $this->messageFor($quote),
        ];
    }

    private function coordinatesMatch(TechnicalServiceRouteQuote $quote, array $origin, array $destination): bool
    {
        return $this->sameCoordinate($quote->origin_latitude, $origin['latitude'])
            && $this->sameCoordinate($quote->origin_longitude, $origin['longitude'])
            && $this->sameCoordinate($quote->destination_latitude, $destination['latitude'])
            && $this->sameCoordinate($quote->destination_longitude, $destination['longitude']);
    }

    /**
     * @param  array{latitude:float,longitude:float}  $origin
     * @param  array{latitude:float,longitude:float}  $destination
     */
    private function haversineKm(array $origin, array $destination): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($destination['latitude'] - $origin['latitude']);
        $lngDelta = deg2rad($destination['longitude'] - $origin['longitude']);
        $originLat = deg2rad($origin['latitude']);
        $destinationLat = deg2rad($destination['latitude']);
        $a = sin($latDelta / 2) ** 2
            + cos($originLat) * cos($destinationLat) * (sin($lngDelta / 2) ** 2);
        $c = 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));

        return round($earthRadiusKm * $c, 3);
    }

    private function quoteStraightLineKm(TechnicalServiceRouteQuote $quote): ?float
    {
        $fromPayload = data_get($quote->raw_payload, 'straight_line_distance_km');

        if (is_numeric($fromPayload)) {
            return round((float) $fromPayload, 3);
        }

        if ($quote->origin_latitude === null
            || $quote->origin_longitude === null
            || $quote->destination_latitude === null
            || $quote->destination_longitude === null
        ) {
            return null;
        }

        return $this->haversineKm([
            'latitude' => (float) $quote->origin_latitude,
            'longitude' => (float) $quote->origin_longitude,
        ], [
            'latitude' => (float) $quote->destination_latitude,
            'longitude' => (float) $quote->destination_longitude,
        ]);
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

    /**
     * @return array{one_way_distance_km:?float,round_trip_distance_km:?float,threshold_km:float,billable_km:?float,fee_per_km:?float,fee_amount:?float}
     */
    private function canonicalDistances(TechnicalServiceRouteQuote $quote): array
    {
        $thresholdKm = $quote->threshold_km !== null ? (float) $quote->threshold_km : self::THRESHOLD_KM;
        $roundTripKm = $quote->distance_km !== null ? (float) $quote->distance_km : null;
        $oneWayMeters = data_get($quote->raw_payload, 'one_way_distance_meters');
        $oneWayKm = is_numeric($oneWayMeters)
            ? round(((float) $oneWayMeters) / 1000, 2)
            : ($roundTripKm !== null ? round($roundTripKm / 2, 2) : null);

        if ($roundTripKm === null && $oneWayKm !== null) {
            $roundTripKm = round($oneWayKm * 2, 2);
        }

        $billableKm = $roundTripKm !== null ? round(max($roundTripKm - $thresholdKm, 0), 2) : null;
        $feePerKm = $quote->fee_per_km !== null ? (float) $quote->fee_per_km : null;
        $manualOverride = (bool) data_get($quote->raw_payload, 'manual_override', false);
        if ($roundTripKm !== null && $roundTripKm <= 0) {
            $feeAmount = 0.0;
        } elseif ($quote->fee_amount !== null) {
            $feeAmount = (float) $quote->fee_amount;
        } elseif ($billableKm !== null && $billableKm <= 0) {
            $feeAmount = 0.0;
        } else {
            $feeAmount = $billableKm !== null && $feePerKm !== null && ! $manualOverride
                ? round($billableKm * $feePerKm, 2)
                : null;
        }

        return [
            'one_way_distance_km' => $oneWayKm,
            'round_trip_distance_km' => $roundTripKm,
            'threshold_km' => $thresholdKm,
            'billable_km' => $billableKm,
            'fee_per_km' => $feePerKm,
            'fee_amount' => $feeAmount,
        ];
    }

    private function cachedQuoteIsUsable(TechnicalServiceRouteQuote $quote): bool
    {
        if ($quote->status !== TechnicalServiceRouteQuote::STATUS_CALCULATED) {
            return false;
        }

        if ($quote->provider !== TechnicalServiceRouteQuote::PROVIDER_GOOGLE_ROUTES) {
            return false;
        }

        $currentFeePerKm = $this->feePerKm();

        if ($quote->fee_per_km === null || $currentFeePerKm === null || abs(((float) $quote->fee_per_km) - $currentFeePerKm) > 0.001) {
            return false;
        }

        if ($quote->threshold_km === null || abs(((float) $quote->threshold_km) - self::THRESHOLD_KM) > 0.001) {
            return false;
        }

        $canonical = $this->canonicalDistances($quote);

        if ($this->isDirtyZeroDistanceQuote($quote, $canonical)) {
            return false;
        }

        if ($canonical['round_trip_distance_km'] === null || $canonical['billable_km'] === null) {
            return false;
        }

        $expectedBillableKm = round(max($canonical['round_trip_distance_km'] - $canonical['threshold_km'], 0), 2);

        if (abs($canonical['billable_km'] - $expectedBillableKm) > 0.01) {
            return false;
        }

        $expectedFeeAmount = $expectedBillableKm > 0 ? round($expectedBillableKm * $currentFeePerKm, 2) : 0.0;

        return $canonical['fee_amount'] !== null && abs($canonical['fee_amount'] - $expectedFeeAmount) <= 0.01;
    }

    private function syncRequestTravelSummary(TechnicalServiceRequest $request, TechnicalServiceRouteQuote $quote): void
    {
        $canonical = $this->canonicalDistances($quote);

        if ($this->isDirtyZeroDistanceQuote($quote, $canonical)) {
            return;
        }

        $request->forceFill([
            'travel_round_trip_km' => $canonical['round_trip_distance_km'],
            'travel_billable_km' => $canonical['billable_km'],
            'travel_fee_amount' => $canonical['fee_amount'],
            'travel_calculation_source' => $quote->provider,
            'travel_calculated_at' => now(),
        ])->save();
    }

    /**
     * @param  array{one_way_distance_km:?float,round_trip_distance_km:?float,threshold_km:float,billable_km:?float,fee_per_km:?float,fee_amount:?float}  $canonical
     */
    private function isDirtyZeroDistanceQuote(TechnicalServiceRouteQuote $quote, array $canonical): bool
    {
        $straightLineKm = $this->quoteStraightLineKm($quote);

        return $quote->provider === TechnicalServiceRouteQuote::PROVIDER_GOOGLE_ROUTES
            && $quote->status === TechnicalServiceRouteQuote::STATUS_CALCULATED
            && $canonical['one_way_distance_km'] !== null
            && $canonical['one_way_distance_km'] <= 0.0
            && $straightLineKm !== null
            && $straightLineKm > self::SAME_LOCATION_GUARD_KM;
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

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
        if ($quote->provider === TechnicalServiceRouteQuote::PROVIDER_SAME_LOCATION_GUARD) {
            return 'Usta ve müşteri konumu aynı/çok yakın. Yol ücreti yok.';
        }

        if ($quote->status === TechnicalServiceRouteQuote::STATUS_MISSING_API_KEY) {
            return 'Google Routes API anahtarı tanımlı değil.';
        }

        if ($quote->status === TechnicalServiceRouteQuote::STATUS_MISSING_LOCATION) {
            return $quote->error_message ?: 'Yol ücreti hesaplanamadı.';
        }

        if ($quote->status === TechnicalServiceRouteQuote::STATUS_FAILED) {
            return $quote->error_message ?: 'Yol ücreti hesaplanamadı.';
        }

        if ((bool) data_get($quote->raw_payload, 'suspicious_route', false)) {
            return 'Rota mesafesi düz çizgi mesafesine göre yüksek. Konumlar kontrol edilmeli.';
        }

        if ((bool) $quote->travel_fee_required) {
            return $quote->fee_amount === null
                ? 'Yol ücreti hesaplanacak. Km başı ücret ayarı eksik.'
                : '30 km üstü yol ücreti gerekli.';
        }

        return '30 km ücretsiz sınır içinde.';
    }
}
