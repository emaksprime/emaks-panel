<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class TechnicalServiceRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_NEW = 'Yeni';
    public const WORKFLOW_NEW_REQUEST = 'Yeni Talep';
    public const SOURCE_QR_MOUNT_FORM = 'qr_mount_form';
    public const PRIORITY_MEDIUM = 'Orta';
    public const RISK_MEDIUM = 'Orta';

    protected $table = 'technical_service_requests';

    protected $fillable = [
        'mrn',
        'customer_name',
        'customer_phone',
        'customer_city',
        'customer_district',
        'service_address',
        'product_name',
        'product_model',
        'brand',
        'stock_code',
        'activation_code',
        'serial_number',
        'service_type',
        'status',
        'workflow_status',
        'priority',
        'risk_level',
        'customer_contact_status',
        'customer_contacted_at',
        'customer_contact_note',
        'customer_confirmed_at',
        'customer_confirmation_method',
        'customer_preferred_date',
        'customer_preferred_time_start',
        'customer_preferred_time_end',
        'customer_callback_at',
        'customer_rejection_reason',
        'technician_name',
        'technical_service_technician_id',
        'technician_approval_status',
        'technician_approved_at',
        'technician_revision_requested_at',
        'technician_revision_note',
        'scheduled_date',
        'scheduled_time',
        'scheduled_at',
        'field_status',
        'field_started_at',
        'field_arrived_at',
        'field_completed_at',
        'field_completion_note',
        'technician_started_at',
        'technician_arrived_at',
        'technician_completed_at',
        'checklist_payload',
        'checklist_status',
        'checklist_completed_at',
        'before_photo_count',
        'after_photo_count',
        'general_photo_count',
        'customer_closure_approval_method',
        'customer_closure_approval_code',
        'customer_signature_name',
        'customer_signature_at',
        'completion_block_reason',
        'incomplete_reason',
        'requires_second_visit',
        'second_visit_reason',
        'missing_info_reason',
        'pending_reason',
        'requires_reschedule',
        'reschedule_reason',
        'document_status',
        'photo_status',
        'customer_closure_approval_status',
        'customer_closure_approved_at',
        'cancellation_reason',
        'next_action',
        'sla_due_at',
        'sla_status',
        'completed_at',
        'installation_completed_at',
        'cancelled_at',
        'reopened_at',
        'reopened_by_user_id',
        'reopen_reason',
        'reopen_note',
        'reopen_count',
        'parent_request_id',
        'root_mrn',
        'service_sequence',
        'service_code',
        'service_visit_reason',
        'source_part_request_id',
        'source_partner_action_id',
        'description',
        'resolution_notes',
        'source_channel',
        'qr_link_id',
        'mount_session_id',
        'current_serial_state',
        'has_current_sale',
        'sale_mount_status',
        'mount_payment_status',
        'mount_payment_label',
        'mount_payment_provider',
        'mount_payment_reference',
        'mount_payment_paid_at',
        'invoice_series',
        'invoice_number',
        'invoice_display_no',
        'dispatch_series',
        'dispatch_number',
        'dispatch_display_no',
        'order_series',
        'order_number',
        'order_display_no',
        'invoice_customer_type',
        'qr_context_payload',
        'location_latitude',
        'location_longitude',
        'location_place_id',
        'location_formatted_address',
        'location_map_url',
        'location_source',
        'location_accuracy',
        'location_note',
        'building_no',
        'apartment_no',
        'door_no',
        'floor_no',
        'site_name',
        'operation_control_payload',
        'operation_control_checked_by_user_id',
        'operation_control_checked_at',
        'travel_round_trip_km',
        'travel_billable_km',
        'travel_fee_amount',
        'technician_payment_amount',
        'travel_calculation_source',
        'travel_calculated_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'customer_contacted_at' => 'datetime',
        'customer_confirmed_at' => 'datetime',
        'customer_preferred_date' => 'date',
        'customer_callback_at' => 'datetime',
        'scheduled_date' => 'date',
        'scheduled_at' => 'datetime',
        'technician_approved_at' => 'datetime',
        'technician_revision_requested_at' => 'datetime',
        'field_started_at' => 'datetime',
        'field_arrived_at' => 'datetime',
        'field_completed_at' => 'datetime',
        'technician_started_at' => 'datetime',
        'technician_arrived_at' => 'datetime',
        'technician_completed_at' => 'datetime',
        'checklist_payload' => 'array',
        'checklist_completed_at' => 'datetime',
        'requires_reschedule' => 'boolean',
        'before_photo_count' => 'integer',
        'after_photo_count' => 'integer',
        'general_photo_count' => 'integer',
        'customer_closure_approved_at' => 'datetime',
        'customer_signature_at' => 'datetime',
        'requires_second_visit' => 'boolean',
        'sla_due_at' => 'datetime',
        'completed_at' => 'datetime',
        'installation_completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reopened_at' => 'datetime',
        'reopen_count' => 'integer',
        'parent_request_id' => 'integer',
        'service_sequence' => 'integer',
        'source_part_request_id' => 'integer',
        'source_partner_action_id' => 'integer',
        'qr_link_id' => 'integer',
        'mount_session_id' => 'integer',
        'has_current_sale' => 'boolean',
        'mount_payment_paid_at' => 'datetime',
        'qr_context_payload' => 'array',
        'location_latitude' => 'decimal:7',
        'location_longitude' => 'decimal:7',
        'operation_control_payload' => 'array',
        'operation_control_checked_by_user_id' => 'integer',
        'operation_control_checked_at' => 'datetime',
        'travel_round_trip_km' => 'decimal:2',
        'travel_billable_km' => 'decimal:2',
        'travel_fee_amount' => 'decimal:2',
        'technician_payment_amount' => 'decimal:2',
        'travel_calculated_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(TechnicalServiceRequestEvent::class, 'technical_service_request_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(TechnicalServiceAuditLog::class, 'entity_id')
            ->where('entity_type', 'technical_service_request');
    }

    public function technicianRecord(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'technical_service_technician_id');
    }

    public function requestSerials(): HasMany
    {
        return $this->hasMany(TechnicalServiceRequestSerial::class, 'technical_service_request_id');
    }

    public function parentRequest(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_request_id');
    }

    public function childRequests(): HasMany
    {
        return $this->hasMany(self::class, 'parent_request_id');
    }

    public function sourcePartRequest(): BelongsTo
    {
        return $this->belongsTo(TechnicalServicePartRequest::class, 'source_part_request_id');
    }

    public function partRequests(): HasMany
    {
        return $this->hasMany(TechnicalServicePartRequest::class, 'technical_service_request_id');
    }

    public function activePartRequests(): HasMany
    {
        return $this->partRequests()->whereIn('status', TechnicalServicePartRequest::ACTIVE_STATUSES);
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(TechnicalServiceRequestUpload::class, 'technical_service_request_id');
    }

    public function customerConfirmations(): HasMany
    {
        return $this->hasMany(TechnicalServiceCustomerConfirmation::class, 'technical_service_request_id');
    }

    public function routeQuotes(): HasMany
    {
        return $this->hasMany(TechnicalServiceRouteQuote::class, 'technical_service_request_id');
    }

    public function partnerJobActions(): HasMany
    {
        return $this->hasMany(TechnicalServicePartnerJobAction::class, 'technical_service_request_id');
    }

    public function assignmentOffers(): HasMany
    {
        return $this->hasMany(TechnicalServiceAssignmentOffer::class, 'technical_service_request_id');
    }

    public function latestAssignmentOffer(): HasOne
    {
        return $this->hasOne(TechnicalServiceAssignmentOffer::class, 'technical_service_request_id')
            ->ofMany(['id' => 'max'], fn ($query) => $query->whereIn('status', [
                TechnicalServiceAssignmentOffer::STATUS_SENT,
                TechnicalServiceAssignmentOffer::STATUS_ACCEPTED,
                TechnicalServiceAssignmentOffer::STATUS_REVISED,
            ]));
    }

    public function assignmentArchives(): HasMany
    {
        return $this->hasMany(TechnicalServiceAssignmentArchive::class, 'technical_service_request_id');
    }

    public function latestRouteQuote(): HasOne
    {
        return $this->hasOne(TechnicalServiceRouteQuote::class, 'technical_service_request_id')->latestOfMany();
    }
}
