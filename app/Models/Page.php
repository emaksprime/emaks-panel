<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Page extends Model
{
    use HasFactory;

    protected $table = 'panel.pages';

    protected $fillable = [
        'resource_code',
        'code',
        'name',
        'route',
        'component',
        'icon',
        'parent_id',
        'description',
        'page_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function buttons(): HasMany
    {
        return $this->hasMany(Button::class)->orderBy('sort_order');
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(PageMenu::class)->orderBy('sort_order');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'resource_code', 'code');
    }

    public function pageConfig(): HasOne
    {
        return $this->hasOne(PageConfig::class, 'page_code', 'code');
    }
}
