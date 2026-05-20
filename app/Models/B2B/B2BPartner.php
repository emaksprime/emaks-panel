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

    public const TYPE_MANUFACTURER = 'manufacturer';

    public const TYPE_SELLER = 'seller';

    public const SUPPORTED_CAPABILITIES = [
        self::TYPE_DEALER,
        self::TYPE_LOCKSMITH,
        self::TYPE_MANUFACTURER,
        self::TYPE_SELLER,
    ];

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
        return $query->whereHas('activeCapabilities', function (Builder $query): void {
            $query->where('capability', self::TYPE_DEALER);
        });
    }

    public function scopeLocksmith(Builder $query): Builder
    {
        return $query->whereHas('activeCapabilities', function (Builder $query): void {
            $query->where('capability', self::TYPE_LOCKSMITH);
        });
    }

    public function isDealer(): bool
    {
        return $this->hasCapability(self::TYPE_DEALER);
    }

    public function isLocksmith(): bool
    {
        return $this->hasCapability(self::TYPE_LOCKSMITH);
    }

    public function hasCapability(string $capability): bool
    {
        if ($this->relationLoaded('capabilities')) {
            return $this->capabilities
                ->where('capability', $capability)
                ->where('active', true)
                ->isNotEmpty();
        }

        return $this->activeCapabilities()
            ->where('capability', $capability)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    public function capabilityCodes(): array
    {
        if ($this->relationLoaded('capabilities')) {
            $capabilities = $this->capabilities
                ->where('active', true)
                ->pluck('capability')
                ->values()
                ->all();

            return count($capabilities) > 0 ? $capabilities : [$this->partner_type];
        }

        $capabilities = $this->activeCapabilities()
            ->pluck('capability')
            ->values()
            ->all();

        return count($capabilities) > 0 ? $capabilities : [$this->partner_type];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'technical_service_technician_id');
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(B2BPartnerCapability::class, 'partner_id');
    }

    public function activeCapabilities(): HasMany
    {
        return $this->capabilities()->where('active', true);
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
