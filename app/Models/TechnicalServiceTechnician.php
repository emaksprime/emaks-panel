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
        'first_name',
        'last_name',
        'phone',
        'city',
        'district',
        'address',
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
        'mikro_cari_kodu',
        'mikro_cari_adi',
    ];

    protected $casts = [
        'active' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'start_latitude' => 'decimal:7',
        'start_longitude' => 'decimal:7',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(TechnicalServiceRequest::class, 'technical_service_technician_id');
    }
}
