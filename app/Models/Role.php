<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $table = 'panel.roles';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_super_admin',
    ];

    protected function casts(): array
    {
        return [
            'is_super_admin' => 'boolean',
        ];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RoleResourcePermission::class, 'role_code', 'code');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_code', 'code');
    }
}
