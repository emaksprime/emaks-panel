<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TechnicalServiceQrLink extends Model
{
    public const TYPE_PRE_SALE_PRODUCT = 'pre_sale_product';
    public const TYPE_SOLD_PRODUCT = 'sold_product';
    public const TYPE_MANUAL_TEST = 'manual_test';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'token_hash',
        'public_token',
        'serial_number',
        'product_name',
        'product_model',
        'brand',
        'link_type',
        'status',
        'created_by',
        'printed_at',
        'last_scanned_at',
        'scan_count',
        'metadata',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'printed_at' => 'datetime',
        'last_scanned_at' => 'datetime',
        'metadata' => 'array',
        'scan_count' => 'integer',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(TechnicalServiceMountSession::class, 'technical_service_qr_link_id');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', trim($token));
    }

    public static function findActiveByToken(string $token): ?self
    {
        $token = trim($token);

        return self::query()
            ->where(function ($query) use ($token): void {
                $query->where('token_hash', self::hashToken($token))
                    ->orWhere('public_token', $token);
            })
            ->where('status', self::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /**
     * @param array{serial_number:string,product_name:string,product_model?:?string,brand?:?string} $context
     * @return array{link:self,token:string}
     */
    public static function createPreSaleProductLink(array $context, ?string $token = null, ?CarbonInterface $expiresAt = null): array
    {
        $token ??= Str::random(64);

        return [
            'link' => self::query()->create([
                'token_hash' => self::hashToken($token),
                'public_token' => $token,
                'serial_number' => trim($context['serial_number']),
                'product_name' => trim($context['product_name']),
                'product_model' => self::nullableText($context['product_model'] ?? null),
                'brand' => self::nullableText($context['brand'] ?? null),
                'link_type' => self::TYPE_PRE_SALE_PRODUCT,
                'status' => self::STATUS_ACTIVE,
                'scan_count' => 0,
                'expires_at' => $expiresAt,
            ]),
            'token' => $token,
        ];
    }

    public function isActiveForOpen(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function publicToken(): string
    {
        if ($this->public_token) {
            return $this->public_token;
        }

        $token = Str::random(64);

        $this->forceFill(['public_token' => $token])->save();

        return $token;
    }

    public function publicPath(): string
    {
        return '/mount-request/'.$this->publicToken();
    }

    public function publicUrl(): string
    {
        return url($this->publicPath());
    }

    public function markScanned(): void
    {
        $this->forceFill([
            'last_scanned_at' => now(),
            'scan_count' => (int) $this->scan_count + 1,
        ])->save();
    }

    private static function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
