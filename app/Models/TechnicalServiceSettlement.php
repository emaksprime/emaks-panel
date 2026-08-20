<?php

namespace App\Models;

use App\Models\B2B\B2BPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicalServiceSettlement extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CALCULATED = 'calculated';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIAL_PAID = 'partial_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_EXCLUDED = 'excluded';

    public const STATUS_ADMIN_REVIEW = 'admin_review';

    protected $table = 'technical_service_settlements';

    protected $fillable = [
        'technical_service_request_id',
        'root_request_id',
        'request_code',
        'root_mrn',
        'technical_service_technician_id',
        'b2b_partner_id',
        'technical_service_assignment_offer_id',
        'technical_service_earning_item_id',
        'currency',
        'labor_earning_amount',
        'route_earning_amount',
        'technician_earning_total',
        'customer_collection_amount',
        'customer_direct_to_technician_amount',
        'customer_direct_assumed_paid_amount',
        'company_payable_amount',
        'company_paid_amount',
        'company_remaining_amount',
        'overpay_warning_amount',
        'status',
        'settlement_source',
        'overpay_requires_review',
        'review_reason',
        'direct_payment_message_dispatch_id',
        'direct_payment_message_sent_at',
        'finalized_at',
        'completed_at',
        'excluded_at',
        'paid_at',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'labor_earning_amount' => 'decimal:2',
            'route_earning_amount' => 'decimal:2',
            'technician_earning_total' => 'decimal:2',
            'customer_collection_amount' => 'decimal:2',
            'customer_direct_to_technician_amount' => 'decimal:2',
            'customer_direct_assumed_paid_amount' => 'decimal:2',
            'company_payable_amount' => 'decimal:2',
            'company_paid_amount' => 'decimal:2',
            'company_remaining_amount' => 'decimal:2',
            'overpay_warning_amount' => 'decimal:2',
            'overpay_requires_review' => 'boolean',
            'direct_payment_message_sent_at' => 'datetime',
            'finalized_at' => 'datetime',
            'completed_at' => 'datetime',
            'excluded_at' => 'datetime',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }

    public function rootRequest(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'root_request_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'technical_service_technician_id');
    }

    public function b2bPartner(): BelongsTo
    {
        return $this->belongsTo(B2BPartner::class, 'b2b_partner_id');
    }

    public function assignmentOffer(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceAssignmentOffer::class, 'technical_service_assignment_offer_id');
    }

    public function earningItem(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceEarningItem::class, 'technical_service_earning_item_id');
    }

    public function directPaymentMessageDispatch(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceMessageDispatch::class, 'direct_payment_message_dispatch_id');
    }

    public function earningPayments(): HasMany
    {
        return $this->hasMany(TechnicalServiceEarningPayment::class, 'technical_service_settlement_id');
    }
}
