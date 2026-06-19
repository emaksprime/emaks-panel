<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceAdminOverride extends Model
{
    use HasFactory;

    public const SOURCE_ADMIN_APPLY = 'ops_admin_direct';
    public const SOURCE_ADMIN_APPROVAL = 'admin_approval';
    public const SOURCE_OPS_REQUEST = 'ops_request';
    public const SOURCE_PARTNER_REQUEST = 'partner_request';
    public const SOURCE_SYSTEM_RECOMPUTE = 'system_recompute';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'technical_service_admin_overrides';

    protected $fillable = [
        'request_id',
        'root_request_id',
        'request_code',
        'root_mrn',
        'field_key',
        'field_label',
        'field_group',
        'source',
        'status',
        'old_value',
        'requested_value',
        'new_value',
        'recompute_flags',
        'reason',
        'requested_by',
        'approved_by',
        'applied_by',
        'rejected_by',
        'requested_at',
        'approved_at',
        'applied_at',
        'rejected_at',
        'rejection_reason',
        'metadata',
    ];

    protected $casts = [
        'old_value' => 'array',
        'requested_value' => 'array',
        'new_value' => 'array',
        'recompute_flags' => 'array',
        'metadata' => 'array',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'applied_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'request_id');
    }

    public function rootRequest(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'root_request_id');
    }
}
