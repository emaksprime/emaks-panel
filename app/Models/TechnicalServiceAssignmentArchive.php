<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceAssignmentArchive extends Model
{
    protected $table = 'technical_service_assignment_archives';

    protected $fillable = [
        'technical_service_request_id',
        'old_technician_id',
        'new_technician_id',
        'old_partner_id',
        'new_partner_id',
        'reason',
        'archived_by',
        'archived_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }

    public function oldTechnician(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'old_technician_id');
    }

    public function newTechnician(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'new_technician_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}
