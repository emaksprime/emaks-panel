<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'old_warranty_card_id',
        'new_warranty_card_id',
        'old_serial_no',
        'new_serial_no',
        'replacement_date',
        'remaining_warranty_days',
        'old_warranty_ends_at',
        'new_warranty_started_at',
        'new_warranty_ends_at',
        'reason',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'replacement_date' => 'date',
        'remaining_warranty_days' => 'integer',
        'old_warranty_ends_at' => 'date',
        'new_warranty_started_at' => 'date',
        'new_warranty_ends_at' => 'date',
    ];

    public function oldWarrantyCard(): BelongsTo
    {
        return $this->belongsTo(WarrantyCard::class, 'old_warranty_card_id');
    }

    public function newWarrantyCard(): BelongsTo
    {
        return $this->belongsTo(WarrantyCard::class, 'new_warranty_card_id');
    }
}
