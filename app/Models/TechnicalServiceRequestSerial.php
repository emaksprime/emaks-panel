<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceRequestSerial extends Model
{
    public const CUSTOMER_DIRECT = 'direct_customer';
    public const CUSTOMER_DEALER_OR_PARTNER = 'dealer_or_partner';
    public const CUSTOMER_UNKNOWN = 'unknown';

    protected $fillable = [
        'technical_service_request_id',
        'mrn',
        'serial_number',
        'product_name',
        'product_model',
        'brand',
        'stock_code',
        'invoice_series',
        'invoice_number',
        'customer_selected',
        'customer_visible',
        'hidden_reason',
        'is_primary',
        'is_returned',
        'return_note',
        'return_date',
        'return_document_no',
        'invoice_customer_type',
        'source_payload',
    ];

    protected $casts = [
        'customer_selected' => 'boolean',
        'customer_visible' => 'boolean',
        'is_primary' => 'boolean',
        'is_returned' => 'boolean',
        'return_date' => 'date',
        'source_payload' => 'array',
    ];

    public function technicalServiceRequest(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }
}
