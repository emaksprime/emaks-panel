<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceMountPayment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'technical_service_mount_session_id',
        'technical_service_request_id',
        'provider',
        'provider_reference',
        'provider_payment_reference',
        'provider_transaction_reference',
        'provider_receipt_reference',
        'status',
        'amount',
        'currency',
        'payment_url',
        'paid_at',
        'provider_paid_at',
        'provider_last_synced_at',
        'provider_sync_attempts',
        'provider_last_sync_status',
        'provider_last_sync_error',
        'provider_sync_locked_at',
        'provider_paid_confirmed_at',
        'receipt_notification_sent_at',
        'receipt_notification_to',
        'receipt_notification_status',
        'receipt_notification_error',
        'raw_payload',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'provider_paid_at' => 'datetime',
        'provider_last_synced_at' => 'datetime',
        'provider_sync_attempts' => 'integer',
        'provider_sync_locked_at' => 'datetime',
        'provider_paid_confirmed_at' => 'datetime',
        'receipt_notification_sent_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceMountSession::class, 'technical_service_mount_session_id');
    }

    public function technicalServiceRequest(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }
}
