<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Button extends Model
{
    use HasFactory;

    protected $table = 'panel.buttons';

    protected $fillable = [
        'page_id',
        'resource_code',
        'label',
        'code',
        'variant',
        'action_type',
        'action_target',
        'position',
        'config_json',
        'confirmation_required',
        'confirmation_text',
        'sort_order',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'config_json' => 'array',
            'confirmation_required' => 'boolean',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'resource_code', 'code');
    }
}
