<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class B2BPartnerUserProfile extends Model
{
    use HasFactory;

    protected $table = 'b2b_partner_user_profiles';

    protected $fillable = [
        'user_id',
        'partner_id',
        'title',
        'phone',
        'active',
        'last_seen_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(B2BPartner::class, 'partner_id');
    }
}
