<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportKeyingGuideProduct extends Model
{
    protected $table = 'panel.support_keying_guide_products';

    protected $fillable = [
        'product_name',
        'search_keywords',
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

    public function steps(): HasMany
    {
        return $this->hasMany(SupportKeyingGuideStep::class, 'product_id');
    }
}
