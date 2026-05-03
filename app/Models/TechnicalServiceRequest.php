<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TechnicalServiceRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'technical_service_requests';

    protected $fillable = [
        'mrn',
        'customer_name',
        'customer_phone',
        'customer_city',
        'customer_district',
        'service_address',
        'product_name',
        'product_model',
        'serial_number',
        'service_type',
        'status',
        'priority',
        'risk_level',
        'technician_name',
        'technical_service_technician_id',
        'scheduled_at',
        'sla_due_at',
        'completed_at',
        'installation_completed_at',
        'cancelled_at',
        'reopened_at',
        'reopened_by_user_id',
        'reopen_reason',
        'reopen_note',
        'reopen_count',
        'description',
        'resolution_notes',
        'source_channel',
        'travel_round_trip_km',
        'travel_billable_km',
        'travel_fee_amount',
        'travel_calculation_source',
        'travel_calculated_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'completed_at' => 'datetime',
        'installation_completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reopened_at' => 'datetime',
        'reopen_count' => 'integer',
        'travel_round_trip_km' => 'decimal:2',
        'travel_billable_km' => 'decimal:2',
        'travel_fee_amount' => 'decimal:2',
        'travel_calculated_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(TechnicalServiceRequestEvent::class, 'technical_service_request_id');
    }

    public function technicianRecord(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'technical_service_technician_id');
    }
}
