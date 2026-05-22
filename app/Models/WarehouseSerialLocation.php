<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseSerialLocation extends Model
{
    protected $table = 'panel.warehouse_serial_locations';

    protected $fillable = [
        'serial_no',
        'stock_code',
        'stock_name',
        'category_name',
        'warehouse_no',
        'rack_code',
        'status',
        'source',
        'last_operation_no',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'warehouse_no' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }
}
