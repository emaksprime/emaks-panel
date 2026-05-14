<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TechnicalServiceMountSession extends Model
{
    public const SALE_UNKNOWN = 'unknown';
    public const SALE_NOT_FOUND = 'not_found';
    public const SALE_MONTAJ_DAHIL = 'montaj_dahil';
    public const SALE_MONTAJ_SONRADAN_DAHIL = 'montaj_sonradan_dahil';
    public const SALE_MONTAJ_HARIC = 'montaj_haric';
    public const SALE_CHECK_FAILED = 'check_failed';

    public const PAYMENT_NOT_REQUIRED = 'not_required';
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_CANCELLED = 'cancelled';
    public const PAYMENT_SKIPPED_MULTI_PRODUCT = 'skipped_multi_product';

    public const ENTRY_SINGLE_PRODUCT = 'single_product';
    public const ENTRY_PAID_SINGLE_PRODUCT = 'paid_single_product';
    public const ENTRY_INCLUDED_MOUNT = 'included_mount';
    public const ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT = 'multi_product_without_payment';

    public const DECISION_PENDING_CHECK = 'pending_check';
    public const DECISION_READY = 'decision_ready';
    public const DECISION_FORM_OPEN = 'form_open';
    public const DECISION_SUBMITTED = 'submitted';
    public const DECISION_CHECK_TIMEOUT = 'check_timeout';

    protected $fillable = [
        'technical_service_qr_link_id',
        'session_token_hash',
        'serial_number',
        'sale_mount_status',
        'mount_payment_status',
        'customer_entry_mode',
        'decision_status',
        'check_attempt_count',
        'last_checked_at',
        'check_error',
        'context_payload',
    ];

    protected $casts = [
        'context_payload' => 'array',
        'last_checked_at' => 'datetime',
        'check_attempt_count' => 'integer',
    ];

    public function qrLink(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceQrLink::class, 'technical_service_qr_link_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TechnicalServiceMountPayment::class, 'technical_service_mount_session_id');
    }

    /**
     * @return array{session:self,session_token:string}
     */
    public static function startForLink(TechnicalServiceQrLink $link, ?string $sessionToken = null): array
    {
        $sessionToken ??= Str::random(64);

        return [
            'session' => self::query()->create([
                'technical_service_qr_link_id' => $link->id,
                'session_token_hash' => self::hashSessionToken($sessionToken),
                'serial_number' => $link->serial_number,
                'sale_mount_status' => self::SALE_UNKNOWN,
                'decision_status' => self::DECISION_PENDING_CHECK,
                'context_payload' => [
                    'product_name' => $link->product_name,
                    'product_model' => $link->product_model,
                    'brand' => $link->brand,
                    'link_type' => $link->link_type,
                ],
            ]),
            'session_token' => $sessionToken,
        ];
    }

    public static function hashSessionToken(string $token): string
    {
        return hash('sha256', trim($token));
    }
}
