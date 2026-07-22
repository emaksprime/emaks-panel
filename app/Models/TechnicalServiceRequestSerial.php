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
        'customer_selectable',
        'customer_visible',
        'hidden_reason',
        'operation_added',
        'operation_added_by',
        'operation_added_at',
        'customer_phone',
        'linked_mrn',
        'operation_note',
        'warning_labels',
        'is_primary',
        'is_returned',
        'return_note',
        'return_date',
        'return_document_no',
        'is_current_latest_sale',
        'color_status',
        'invoice_customer_type',
        'source_payload',
    ];

    protected $casts = [
        'customer_selected' => 'boolean',
        'customer_selectable' => 'boolean',
        'customer_visible' => 'boolean',
        'operation_added' => 'boolean',
        'operation_added_at' => 'datetime',
        'warning_labels' => 'array',
        'is_primary' => 'boolean',
        'is_returned' => 'boolean',
        'is_current_latest_sale' => 'boolean',
        'return_date' => 'date',
        'source_payload' => 'array',
    ];

    public function technicalServiceRequest(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }
}
