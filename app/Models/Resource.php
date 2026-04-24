<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    use HasFactory;

    protected $table = 'panel.resources';

    protected $fillable = [
        'code',
        'name',
        'type',
        'description',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RoleResourcePermission::class, 'resource_code', 'code');
    }
}
