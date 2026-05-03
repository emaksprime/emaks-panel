<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'warranty_card_id',
        'event_type',
        'title',
        'note',
        'from_status',
        'to_status',
        'metadata',
        'author_user_id',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function warrantyCard(): BelongsTo
    {
        return $this->belongsTo(WarrantyCard::class);
    }
}
