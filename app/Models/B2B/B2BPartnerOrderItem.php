<?php

namespace App\Models\B2B;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class B2BPartnerOrderItem extends Model
{
    use HasFactory;

    protected $table = 'b2b_partner_order_items';

    protected $fillable = [
        'order_id',
        'product_code',
        'product_name',
        'requested_quantity',
        'stock_status',
        'note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(B2BPartnerOrder::class, 'order_id');
    }
}
