<?php

namespace App\Models;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerTechnician;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TechnicalServiceTechnician extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'technical_service_technicians';

    protected $fillable = [
        'name',
        'technician_type',
        'first_name',
        'last_name',
        'city_plate_code',
        'priority',
        'phone',
        'phone_e164',
        'phone_display',
        'city',
        'district',
        'address',
        'location_code',
        'google_plus_code',
        'google_formatted_address',
        'default_start_address',
        'default_start_plus_code',
        'active',
        'note',
        'latitude',
        'longitude',
        'start_latitude',
        'start_longitude',
        'location_source',
        'route_note',
        'mikro_cari_kodu',
        'mikro_cari_adi',
        'cari_code',
        'cari_title',
        'cari_address',
        'cari_city_district_country',
        'display_name',
        'import_status',
        'import_note',
        'needs_review',
        'review_status',
        'review_reason',
        'review_reasons',
        'reviewed_at',
        'reviewed_by',
        'import_source',
        'imported_at',
        'source_key',
        'geocode_status',
        'geocode_source',
        'geocode_confidence',
        'geocoded_at',
        'geocode_payload',
    ];

    protected $casts = [
        'active' => 'boolean',
        'priority' => 'integer',
        'needs_review' => 'boolean',
        'review_reasons' => 'array',
        'reviewed_at' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'start_latitude' => 'decimal:7',
        'start_longitude' => 'decimal:7',
        'imported_at' => 'datetime',
        'geocode_confidence' => 'integer',
        'geocoded_at' => 'datetime',
        'geocode_payload' => 'array',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(TechnicalServiceRequest::class, 'technical_service_technician_id');
    }

    public function b2bPartnerLinks(): HasMany
    {
        return $this->hasMany(B2BPartnerTechnician::class, 'technical_service_technician_id');
    }

    public function b2bPartners(): BelongsToMany
    {
        return $this->belongsToMany(
            B2BPartner::class,
            'b2b_partner_technicians',
            'technical_service_technician_id',
            'partner_id',
        )
            ->withPivot(['id', 'relationship_type', 'is_primary', 'active', 'source', 'match_reason', 'service_city', 'service_district', 'service_region_note', 'priority', 'needs_review', 'review_reason', 'review_reasons', 'reviewed_at', 'reviewed_by', 'metadata', 'created_by'])
            ->withTimestamps();
    }
}
