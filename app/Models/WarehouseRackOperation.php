<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseRackOperation extends Model
{
    protected $table = 'panel.warehouse_rack_operations';

    protected $fillable = [
        'operation_no',
        'operation_type',
        'source_warehouse_no',
        'source_rack_code',
        'target_warehouse_no',
        'target_rack_code',
        'serial_no',
        'stock_code',
        'quantity',
        'status',
        'validation_status',
        'validation_message',
        'created_by',
        'completed_by',
        'cancelled_by',
        'completed_at',
        'cancelled_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'source_warehouse_no' => 'integer',
            'target_warehouse_no' => 'integer',
            'quantity' => 'decimal:4',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseRackOperationItem::class, 'operation_id')->orderBy('line_no');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
