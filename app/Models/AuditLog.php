<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'panel.logs';

    protected $fillable = [
        'user_id',
        'action',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
