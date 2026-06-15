<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportKeyingGuideStep extends Model
{
    protected $table = 'panel.support_keying_guide_steps';

    protected $fillable = [
        'product_id',
        'section_type',
        'custom_title',
        'entry_method',
        'entry_format',
        'title',
        'content',
        'is_active',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SupportKeyingGuideProduct::class, 'product_id');
    }
}
