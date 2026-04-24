<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageMenu extends Model
{
    use HasFactory;

    protected $table = 'panel.page_menu';

    protected $fillable = [
        'menu_group_id',
        'page_id',
        'label',
        'icon',
        'sort_order',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
        ];
    }

    public function menuGroup(): BelongsTo
    {
        return $this->belongsTo(MenuGroup::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
