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
        'status',
        'amount',
        'currency',
        'payment_url',
        'paid_at',
        'raw_payload',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
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
