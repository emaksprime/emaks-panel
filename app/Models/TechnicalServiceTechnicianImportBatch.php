<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicalServiceTechnicianImportBatch extends Model
{
    protected $table = 'technical_service_technician_import_batches';

    protected $fillable = [
        'file_name',
        'file_hash',
        'preview_hash',
        'source_type',
        'sheet_name',
        'dry_run_summary',
        'apply_summary',
        'status',
        'created_by',
        'applied_by',
        'applied_at',
    ];

    protected $casts = [
        'dry_run_summary' => 'array',
        'apply_summary' => 'array',
        'applied_at' => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(TechnicalServiceTechnicianImportRow::class, 'batch_id');
    }
}
