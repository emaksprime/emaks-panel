<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TechnicalServiceAuditLog extends Model
{
    use HasFactory;

    protected $table = 'technical_service_audit_logs';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'action_type',
        'old_value',
        'new_value',
        'user_id',
        'user_name',
        'note',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
    ];
}
