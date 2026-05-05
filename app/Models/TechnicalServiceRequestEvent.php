<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceRequestEvent extends Model
{
    use HasFactory;

    protected $table = 'technical_service_request_events';

    protected $fillable = [
        'technical_service_request_id',
        'event_type',
        'title',
        'note',
        'from_status',
        'to_status',
        'author_user_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }
}
