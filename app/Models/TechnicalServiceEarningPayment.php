<?php

namespace App\Models;

use App\Models\B2B\B2BPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceEarningPayment extends Model
{
    public const TYPE_COMPANY_PAYOUT = 'company_payout';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_WRITE_OFF = 'write_off';

    public const TYPE_REVERSAL = 'reversal';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_VOID = 'void';

    public const STATUS_PENDING = 'pending';

    protected $table = 'technical_service_earning_payments';

    protected $fillable = [
        'technical_service_settlement_id',
        'technical_service_request_id',
        'technical_service_technician_id',
        'b2b_partner_id',
        'currency',
        'payment_type',
        'amount',
        'status',
        'paid_at',
        'paid_by',
        'paid_by_name',
        'reason',
        'reference',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceSettlement::class, 'technical_service_settlement_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'technical_service_technician_id');
    }

    public function b2bPartner(): BelongsTo
    {
        return $this->belongsTo(B2BPartner::class, 'b2b_partner_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
