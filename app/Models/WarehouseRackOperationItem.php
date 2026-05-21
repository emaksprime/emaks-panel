<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseRackOperationItem extends Model
{
    protected $table = 'panel.warehouse_rack_operation_items';

    protected $fillable = [
        'operation_id',
        'line_no',
        'item_type',
        'warehouse_no',
        'source_rack_code',
        'target_rack_code',
        'serial_no',
        'stock_code',
        'stock_name',
        'barcode',
        'quantity',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'operation_id' => 'integer',
            'line_no' => 'integer',
            'warehouse_no' => 'integer',
            'quantity' => 'decimal:4',
            'meta' => 'array',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(WarehouseRackOperation::class, 'operation_id');
    }
}
