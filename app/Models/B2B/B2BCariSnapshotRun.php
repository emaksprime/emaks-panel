<?php

namespace App\Models\B2B;

use Illuminate\Database\Eloquent\Model;

class B2BCariSnapshotRun extends Model
{
    protected $table = 'b2b_cari_snapshot_runs';

    protected $fillable = [
        'source_code',
        'status',
        'started_at',
        'finished_at',
        'total_received',
        'total_normalized',
        'excluded_online_retail_count',
        'new_count',
        'changed_count',
        'matched_count',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
