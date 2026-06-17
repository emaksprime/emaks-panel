<?php

namespace App\Models\B2B;

use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class B2BPartnerTechnician extends Model
{
    protected $table = 'b2b_partner_technicians';

    protected $fillable = [
        'partner_id',
        'technical_service_technician_id',
        'relationship_type',
        'is_primary',
        'active',
        'source',
        'match_reason',
        'service_city',
        'service_district',
        'service_region_note',
        'priority',
        'needs_review',
        'review_reason',
        'review_reasons',
        'reviewed_at',
        'reviewed_by',
        'metadata',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'active' => 'boolean',
            'priority' => 'integer',
            'needs_review' => 'boolean',
            'review_reasons' => 'array',
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(B2BPartner::class, 'partner_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'technical_service_technician_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
