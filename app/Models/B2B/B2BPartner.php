<?php

namespace App\Models\B2B;

use App\Models\TechnicalServiceTechnician;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class B2BPartner extends Model
{
    use HasFactory;

    public const TYPE_DEALER = 'dealer';

    public const TYPE_LOCKSMITH = 'locksmith';

    protected $table = 'b2b_partners';

    protected $fillable = [
        'partner_type',
        'partner_code',
        'display_name',
        'mikro_cari_kodu',
        'mikro_cari_unvan',
        'cari_grup_kodu',
        'responsibility_code',
        'phone',
        'email',
        'city',
        'district',
        'active',
        'technical_service_technician_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeDealer(Builder $query): Builder
    {
        return $query->where('partner_type', self::TYPE_DEALER);
    }

    public function scopeLocksmith(Builder $query): Builder
    {
        return $query->where('partner_type', self::TYPE_LOCKSMITH);
    }

    public function isDealer(): bool
    {
        return $this->partner_type === self::TYPE_DEALER;
    }

    public function isLocksmith(): bool
    {
        return $this->partner_type === self::TYPE_LOCKSMITH;
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'technical_service_technician_id');
    }

    public function access(): HasMany
    {
        return $this->hasMany(B2BPartnerUserAccess::class, 'partner_id');
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(B2BPartnerUserProfile::class, 'partner_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(B2BPartnerAuditLog::class, 'partner_id');
    }
}
