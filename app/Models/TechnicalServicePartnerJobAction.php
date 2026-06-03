<?php

namespace App\Models;

use App\Models\B2B\B2BPartner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServicePartnerJobAction extends Model
{
    use HasFactory;

    public const ACTION_ACCEPTED = 'accepted';

    public const ACTION_APPOINTMENT_ACCEPTED_BY_TECHNICIAN = 'appointment_accepted_by_technician';

    public const ACTION_REVISIT_REQUESTED = 'revisit_requested';

    public const ACTION_COMPLETION_SUBMITTED = 'completion_submitted';

    public const ACTION_NOTE_ADDED = 'note_added';

    public const ACTION_APPOINTMENT_PROPOSED = 'appointment_proposed';

    public const ACTION_APPOINTMENT_CHANGE_REQUESTED = 'appointment_change_requested';

    public const ACTION_JOB_REJECTED = 'job_rejected';

    public const ACTION_CUSTOMER_OTP_REQUESTED = 'customer_otp_requested';

    public const ACTION_CUSTOMER_APPROVAL_CONFIRMED = 'customer_approval_confirmed';

    public const ACTION_CUSTOMER_APPROVAL_REJECTED = 'customer_approval_rejected';

    public const ACTION_SUPPORT_REQUESTED = 'support_requested';

    public const ACTION_PHOTOS_UPLOADED = 'photos_uploaded';

    public const ACTION_PRICE_REVISION_REQUESTED = 'price_revision_requested';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_OPS_REVIEW = 'ops_review';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVISION_REQUESTED = 'revision_requested';

    protected $table = 'technical_service_partner_job_actions';

    protected $fillable = [
        'technical_service_request_id',
        'partner_id',
        'user_id',
        'technical_service_technician_id',
        'action',
        'status',
        'payload',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(B2BPartner::class, 'partner_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'technical_service_technician_id');
    }
}
