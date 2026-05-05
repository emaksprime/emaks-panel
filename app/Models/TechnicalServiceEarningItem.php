<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceEarningItem extends Model
{
    protected $table = 'technical_service_earning_items';

    protected $fillable = [
        'earning_id',
        'technical_service_request_id',
        'mrn',
        'job_date',
        'customer_city',
        'customer_district',
        'service_type',
        'product_name',
        'serial_number',
        'labor_amount',
        'travel_round_trip_km',
        'travel_billable_km',
        'travel_fee_amount',
        'line_total',
        'note',
    ];

    protected $casts = [
        'job_date' => 'datetime',
        'labor_amount' => 'decimal:2',
        'travel_round_trip_km' => 'decimal:2',
        'travel_billable_km' => 'decimal:2',
        'travel_fee_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function earning(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceEarning::class, 'earning_id');
    }
}
