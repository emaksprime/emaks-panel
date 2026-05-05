<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicalServiceEarning extends Model
{
    protected $table = 'technical_service_earnings';

    protected $fillable = [
        'period_id',
        'technical_service_technician_id',
        'technician_name_snapshot',
        'city_snapshot',
        'job_count',
        'installation_count',
        'service_count',
        'labor_total',
        'travel_fee_total',
        'travel_round_trip_km_total',
        'travel_billable_km_total',
        'grand_total',
        'status',
        'dispute_note',
        'internal_note',
        'paid_at',
    ];

    protected $casts = [
        'job_count' => 'integer',
        'installation_count' => 'integer',
        'service_count' => 'integer',
        'labor_total' => 'decimal:2',
        'travel_fee_total' => 'decimal:2',
        'travel_round_trip_km_total' => 'decimal:2',
        'travel_billable_km_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceEarningsPeriod::class, 'period_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'technical_service_technician_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TechnicalServiceEarningItem::class, 'earning_id');
    }
}
