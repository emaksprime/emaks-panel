<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceTechnicianImportRow extends Model
{
    protected $table = 'technical_service_technician_import_rows';

    protected $fillable = [
        'batch_id',
        'row_number',
        'action',
        'status',
        'technician_id',
        'partner_id',
        'link_id',
        'normalized_payload',
        'changed_fields',
        'warnings',
        'errors',
        'geocode_result',
    ];

    protected $casts = [
        'normalized_payload' => 'array',
        'changed_fields' => 'array',
        'warnings' => 'array',
        'errors' => 'array',
        'geocode_result' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnicianImportBatch::class, 'batch_id');
    }
}
