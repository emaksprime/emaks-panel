<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportActivationImportBatch extends Model
{
    protected $table = 'panel.support_activation_import_batches';

    protected $fillable = [
        'filename',
        'source',
        'status',
        'total_rows',
        'created_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'preview_payload',
        'result_payload',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'preview_payload' => 'array',
            'result_payload' => 'array',
        ];
    }
}
