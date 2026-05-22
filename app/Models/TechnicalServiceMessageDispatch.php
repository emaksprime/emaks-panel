<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceMessageDispatch extends Model
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    protected $table = 'technical_service_message_dispatches';

    protected $fillable = [
        'event',
        'technical_service_request_id',
        'technical_service_partner_job_action_id',
        'technical_service_assignment_offer_id',
        'technical_service_earning_id',
        'target_type',
        'original_phone',
        'target_phone',
        'test_mode',
        'status',
        'request_payload',
        'response_payload',
        'error_message',
        'sent_by',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'test_mode' => 'boolean',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }

    public function partnerJobAction(): BelongsTo
    {
        return $this->belongsTo(TechnicalServicePartnerJobAction::class, 'technical_service_partner_job_action_id');
    }

    public function assignmentOffer(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceAssignmentOffer::class, 'technical_service_assignment_offer_id');
    }

    public function earning(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceEarning::class, 'technical_service_earning_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
