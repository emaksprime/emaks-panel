<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseStockLocation extends Model
{
    protected $table = 'panel.warehouse_stock_locations';

    protected $fillable = [
        'warehouse_no',
        'rack_code',
        'stock_code',
        'stock_name',
        'quantity',
        'source',
        'last_operation_no',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'warehouse_no' => 'integer',
            'quantity' => 'decimal:4',
            'last_seen_at' => 'datetime',
        ];
    }
}
