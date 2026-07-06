<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceMessageDispatch extends Model
{
    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DUPLICATE_BLOCKED = 'duplicate_blocked';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PROVIDER_ERROR = 'provider_error';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_COOLDOWN_BLOCKED = 'cooldown_blocked';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_TEST_SENT = 'test_sent';

    public const STATUS_TEST_FAILED = 'test_failed';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_SUPPRESSED_TEST_FIXTURE = 'suppressed_test_fixture';

    public const STATUS_SUPPRESSED_TESTING_ENVIRONMENT = 'suppressed_testing_environment';

    public const STATUS_SUPPRESSED_REAL_SEND_DISABLED = 'suppressed_real_send_disabled';

    public const STATUS_SUPPRESSED_DUPLICATE = 'suppressed_duplicate';

    public const STATUS_SUPPRESSED_RATE_LIMITED = 'suppressed_rate_limited';

    public const SUCCESS_STATUSES = [
        self::STATUS_SENT,
        self::STATUS_TEST_SENT,
    ];

    public const DUPLICATE_BLOCKING_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SENDING,
        self::STATUS_SENT,
        self::STATUS_TEST_SENT,
    ];

    protected $table = 'technical_service_message_dispatches';

    protected $fillable = [
        'event',
        'technical_service_request_id',
        'technical_service_partner_job_action_id',
        'technical_service_assignment_offer_id',
        'technical_service_earning_id',
        'request_id',
        'related_type',
        'related_id',
        'root_mrn',
        'mrn',
        'srv',
        'message_type',
        'channel',
        'provider_key',
        'recipient_role',
        'recipient_phone_hash',
        'recipient_phone_mask',
        'effective_target_phone_hash',
        'effective_target_phone_mask',
        'test_redirect_applied',
        'template_key',
        'template_version',
        'rendered_body_hash',
        'payload_hash',
        'idempotency_key',
        'channel_policy',
        'target_type',
        'original_phone',
        'target_phone',
        'test_mode',
        'status',
        'attempt_count',
        'max_attempts',
        'queued_at',
        'next_attempt_at',
        'sending_started_at',
        'failed_at',
        'last_error_code',
        'last_error_message_redacted',
        'provider_message_id',
        'provider_status',
        'provider_response_redacted',
        'parent_dispatch_id',
        'force_resend',
        'force_resend_reason',
        'created_by',
        'triggered_by',
        'metadata',
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
            'test_redirect_applied' => 'boolean',
            'force_resend' => 'boolean',
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'template_version' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'provider_response_redacted' => 'array',
            'metadata' => 'array',
            'queued_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'sending_started_at' => 'datetime',
            'failed_at' => 'datetime',
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

    public function parentDispatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_dispatch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
