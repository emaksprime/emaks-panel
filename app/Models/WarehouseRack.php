<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseRack extends Model
{
    protected $table = 'panel.warehouse_racks';

    protected $fillable = [
        'warehouse_no',
        'rack_code',
        'rack_name',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'warehouse_no' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
