<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServicePartRequest extends Model
{
    use HasFactory;

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_OPS_REVIEW = 'ops_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_SENT = 'sent';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_SERVICE_VISIT_REQUIRED = 'service_visit_required';
    public const STATUS_SERVICE_VISIT_CREATED = 'service_visit_created';
    public const STATUS_CLOSED = 'closed';

    public const ACTIVE_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_OPS_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_ORDERED,
        self::STATUS_SENT,
        self::STATUS_RECEIVED,
        self::STATUS_SERVICE_VISIT_REQUIRED,
    ];

    protected $table = 'technical_service_part_requests';

    protected $fillable = [
        'technical_service_request_id',
        'root_request_id',
        'request_serial_id',
        'source_partner_action_id',
        'requested_by_user_id',
        'requested_by_technician_id',
        'status',
        'part_name',
        'part_code',
        'quantity',
        'reason',
        'technician_note',
        'ops_note',
        'partner_message',
        'shipment_provider',
        'tracking_no',
        'sent_at',
        'received_at',
        'received_by_user_id',
        'requires_service_visit',
        'service_visit_request_id',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'requires_service_visit' => 'boolean',
        'metadata' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }

    public function rootRequest(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'root_request_id');
    }

    public function requestSerial(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequestSerial::class, 'request_serial_id');
    }

    public function sourcePartnerAction(): BelongsTo
    {
        return $this->belongsTo(TechnicalServicePartnerJobAction::class, 'source_partner_action_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function requestedByTechnician(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'requested_by_technician_id');
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function serviceVisitRequest(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'service_visit_request_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function statusLabel(): string
    {
        if ($this->isChargePaymentPending()) {
            return 'Müşteri parça ödemesi bekleniyor';
        }

        if ($this->isChargePaymentPaid() && $this->status === self::STATUS_APPROVED) {
            return 'Parça ödemesi alındı';
        }

        return self::labelForStatus((string) $this->status);
    }

    public function partnerStatusLabel(): string
    {
        if ($this->isChargePaymentPending()) {
            return 'Müşteri parça ödemesi bekleniyor';
        }

        if ($this->isChargePaymentPaid() && $this->status === self::STATUS_APPROVED) {
            return 'Parça ödemesi alındı';
        }

        return self::partnerLabelForStatus((string) $this->status);
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataPayload(): array
    {
        return is_array($this->metadata) ? $this->metadata : [];
    }

    public function chargeDecision(): ?string
    {
        $metadata = $this->metadataPayload();

        return is_string($metadata['charge_decision'] ?? null) ? $metadata['charge_decision'] : null;
    }

    public function chargeStatus(): ?string
    {
        $metadata = $this->metadataPayload();
        $customerCharge = is_array($metadata['customer_charge'] ?? null) ? $metadata['customer_charge'] : [];
        $status = $customerCharge['status'] ?? $metadata['charge_status'] ?? null;

        return is_string($status) ? $status : null;
    }

    public function isChargeable(): bool
    {
        return $this->chargeDecision() === 'chargeable';
    }

    public function isChargePaymentPaid(): bool
    {
        return $this->isChargeable() && $this->chargeStatus() === 'paid';
    }

    public function isChargePaymentPending(): bool
    {
        if (! $this->isChargeable()) {
            return false;
        }

        return ! $this->isChargePaymentPaid();
    }

    public function canBeShipped(): bool
    {
        return ! $this->isChargePaymentPending();
    }

    public function needsRepeatServiceDecision(): bool
    {
        return $this->status === self::STATUS_RECEIVED && ! $this->service_visit_request_id;
    }

    public static function labelForStatus(string $status): string
    {
        return match ($status) {
            self::STATUS_REQUESTED,
            self::STATUS_OPS_REVIEW => 'Parça talebi incelenmeli',
            self::STATUS_APPROVED => 'Parça talebi onaylandı',
            self::STATUS_REJECTED => 'Parça talebi reddedildi',
            self::STATUS_ORDERED => 'Parça tedarikte',
            self::STATUS_SENT => 'Parça gönderildi',
            self::STATUS_RECEIVED => 'Parça teslim alındı',
            self::STATUS_SERVICE_VISIT_REQUIRED => 'Parça sonrası servis gerekli',
            self::STATUS_SERVICE_VISIT_CREATED => 'Parça sonrası servis oluşturuldu',
            self::STATUS_CLOSED => 'Parça talebi kapatıldı',
            default => 'Parça talebi',
        };
    }

    public static function partnerLabelForStatus(string $status): string
    {
        return match ($status) {
            self::STATUS_REQUESTED,
            self::STATUS_OPS_REVIEW => 'Parça talebi operasyon incelemesinde',
            self::STATUS_APPROVED => 'Parça talebi onaylandı',
            self::STATUS_REJECTED => 'Parça talebi reddedildi',
            self::STATUS_ORDERED => 'Parça tedarikte',
            self::STATUS_SENT => 'Parça gönderildi',
            self::STATUS_RECEIVED => 'Parça teslim alındı',
            self::STATUS_SERVICE_VISIT_REQUIRED => 'Operasyon servis planlıyor',
            self::STATUS_SERVICE_VISIT_CREATED => 'Parça sonrası servis oluşturuldu',
            self::STATUS_CLOSED => 'Parça talebi kapatıldı',
            default => 'Parça talebi',
        };
    }
}
