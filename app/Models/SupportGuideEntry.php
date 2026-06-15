<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportGuideEntry extends Model
{
    protected $table = 'panel.support_guide_entries';

    protected $fillable = [
        'code',
        'source_sheet',
        'source_row',
        'devices',
        'device_aliases',
        'method',
        'guide_type',
        'sections',
        'warnings',
        'extra_notes',
        'search_text',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'source_row' => 'integer',
            'devices' => 'array',
            'device_aliases' => 'array',
            'sections' => 'array',
            'warnings' => 'array',
            'extra_notes' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
