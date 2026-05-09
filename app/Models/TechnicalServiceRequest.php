<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TechnicalServiceRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

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
        'description',
        'resolution_notes',
        'source_channel',
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
        'scheduled_date' => 'date',
        'scheduled_at' => 'datetime',
        'technician_approved_at' => 'datetime',
        'technician_revision_requested_at' => 'datetime',
        'field_started_at' => 'datetime',
        'field_arrived_at' => 'datetime',
        'field_completed_at' => 'datetime',
        'requires_reschedule' => 'boolean',
        'customer_closure_approved_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'completed_at' => 'datetime',
        'installation_completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reopened_at' => 'datetime',
        'reopen_count' => 'integer',
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
}
