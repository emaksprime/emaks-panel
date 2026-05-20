<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'import_source',
        'imported_at',
        'source_key',
    ];

    protected $casts = [
        'active' => 'boolean',
        'priority' => 'integer',
        'needs_review' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'start_latitude' => 'decimal:7',
        'start_longitude' => 'decimal:7',
        'imported_at' => 'datetime',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(TechnicalServiceRequest::class, 'technical_service_technician_id');
    }
}
