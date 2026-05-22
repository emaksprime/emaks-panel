<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceRequestUpload extends Model
{
    public const CATEGORY_OPERATION_CONTROL_DOOR_PHOTO = 'operation_control_door_photo';
    public const CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT = 'partner_portal_field_document';

    protected $table = 'technical_service_request_uploads';

    protected $fillable = [
        'technical_service_request_id',
        'field_code',
        'category',
        'original_name',
        'path',
        'mime',
        'size',
        'review_status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
        'review_payload',
    ];

    protected $casts = [
        'technical_service_request_id' => 'integer',
        'size' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'review_payload' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
