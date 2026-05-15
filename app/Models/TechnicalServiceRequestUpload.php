<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceRequestUpload extends Model
{
    public const CATEGORY_OPERATION_CONTROL_DOOR_PHOTO = 'operation_control_door_photo';

    protected $table = 'technical_service_request_uploads';

    protected $fillable = [
        'technical_service_request_id',
        'field_code',
        'category',
        'original_name',
        'path',
        'mime',
        'size',
    ];

    protected $casts = [
        'technical_service_request_id' => 'integer',
        'size' => 'integer',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }
}
