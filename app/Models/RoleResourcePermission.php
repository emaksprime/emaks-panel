<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleResourcePermission extends Model
{
    use HasFactory;

    protected $table = 'panel.role_resource_permissions';

    protected $fillable = [
        'role_code',
        'resource_code',
        'can_view',
        'can_execute',
    ];

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_execute' => 'boolean',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'resource_code', 'code');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_code', 'code');
    }
}
