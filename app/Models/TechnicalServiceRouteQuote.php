<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceRouteQuote extends Model
{
    use HasFactory;

    public const PROVIDER_GOOGLE_ROUTES = 'google_routes';
    public const PROVIDER_MANUAL_OVERRIDE = 'manual_override';

    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_FAILED = 'failed';
    public const STATUS_MISSING_LOCATION = 'missing_location';
    public const STATUS_MISSING_API_KEY = 'missing_api_key';

    protected $table = 'technical_service_route_quotes';

    protected $fillable = [
        'technical_service_request_id',
        'technician_id',
        'origin_latitude',
        'origin_longitude',
        'destination_latitude',
        'destination_longitude',
        'distance_meters',
        'distance_km',
        'duration_seconds',
        'threshold_km',
        'extra_km',
        'fee_per_km',
        'fee_amount',
        'travel_fee_required',
        'provider',
        'status',
        'error_message',
        'raw_payload',
        'calculated_at',
    ];

    protected $casts = [
        'origin_latitude' => 'decimal:7',
        'origin_longitude' => 'decimal:7',
        'destination_latitude' => 'decimal:7',
        'destination_longitude' => 'decimal:7',
        'distance_meters' => 'integer',
        'distance_km' => 'decimal:2',
        'duration_seconds' => 'integer',
        'threshold_km' => 'decimal:2',
        'extra_km' => 'decimal:2',
        'fee_per_km' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'travel_fee_required' => 'boolean',
        'raw_payload' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'technician_id');
    }
}
