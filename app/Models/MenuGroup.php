<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuGroup extends Model
{
    use HasFactory;

    protected $table = 'panel.menu_groups';

    protected $fillable = [
        'code',
        'name',
        'icon',
        'menu_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(PageMenu::class)->orderBy('sort_order');
    }
}
