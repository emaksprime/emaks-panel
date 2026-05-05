<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicalServiceEarningsPeriod extends Model
{
    protected $table = 'technical_service_earnings_periods';

    protected $fillable = [
        'year',
        'month',
        'status',
        'calculated_at',
        'approved_at',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'calculated_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function earnings(): HasMany
    {
        return $this->hasMany(TechnicalServiceEarning::class, 'period_id');
    }
}
