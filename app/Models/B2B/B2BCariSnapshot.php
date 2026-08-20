<?php

namespace App\Models\B2B;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class B2BCariSnapshot extends Model
{
    protected $table = 'b2b_cari_snapshots';

    protected $fillable = [
        'source_code',
        'base_mikro_cari_kodu',
        'mikro_cari_kodu',
        'mikro_cari_unvan',
        'normalized_unvan',
        'cari_grup_kodu',
        'responsibility_code',
        'temsilci_kodu',
        'phone',
        'email',
        'city',
        'district',
        'address',
        'tax_no',
        'tax_office',
        'suggested_capabilities',
        'child_cari_accounts',
        'invoice_profile',
        'shipping_profile',
        'raw_payload',
        'payload_hash',
        'existing_partner_id',
        'candidate_status',
        'review_reason',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'suggested_capabilities' => 'array',
            'child_cari_accounts' => 'array',
            'invoice_profile' => 'array',
            'shipping_profile' => 'array',
            'raw_payload' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function existingPartner(): BelongsTo
    {
        return $this->belongsTo(B2BPartner::class, 'existing_partner_id');
    }
}
