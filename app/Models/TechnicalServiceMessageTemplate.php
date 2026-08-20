<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceMessageTemplate extends Model
{
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_VOICE_SCRIPT = 'voice_script';

    public const CHANNEL_SYSTEM = 'system';

    protected $fillable = [
        'template_key',
        'message_type',
        'channel',
        'provider_key',
        'title',
        'body',
        'active',
        'locale',
        'version',
        'required_variables',
        'optional_variables',
        'validation_rules',
        'metadata',
        'created_by',
        'updated_by',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'version' => 'integer',
            'required_variables' => 'array',
            'optional_variables' => 'array',
            'validation_rules' => 'array',
            'metadata' => 'array',
            'superseded_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
