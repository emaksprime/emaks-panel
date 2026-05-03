<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarrantyCard extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'serial_no',
        'stock_code',
        'stock_name',
        'last_sale_date',
        'last_sale_customer_code',
        'last_sale_customer_name',
        'last_sale_document_type',
        'last_sale_document_no',
        'last_sale_mikro_fingerprint',
        'installation_completed_at',
        'warranty_started_at',
        'warranty_ends_at',
        'warranty_period_months',
        'status',
        'source',
        'notes',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'last_sale_date' => 'date',
        'installation_completed_at' => 'date',
        'warranty_started_at' => 'date',
        'warranty_ends_at' => 'date',
        'warranty_period_months' => 'integer',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(WarrantyEvent::class);
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(WarrantyTransfer::class, 'old_warranty_card_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(WarrantyTransfer::class, 'new_warranty_card_id');
    }
}
