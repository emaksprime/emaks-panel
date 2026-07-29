<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCariGroupPermission extends Model
{
    use HasFactory;

    public const MODE_ALLOW = 'allow';

    public const MODE_DENY = 'deny';

    protected $table = 'panel.user_cari_group_permissions';

    protected $fillable = [
        'user_id',
        'cari_group_code',
        'mode',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
