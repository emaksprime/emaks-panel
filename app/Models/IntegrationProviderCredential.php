<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntegrationProviderCredential extends Model
{
    use HasFactory;

    public const SCOPE_TECHNICAL_SERVICE = 'technical_service';

    public const PROFILE_DEFAULT = 'default';

    public const MODE_LIVE = 'live';

    public const STATUS_MISSING = 'missing';

    public const STATUS_CONFIGURED = 'configured';

    public const STATUS_INVALID = 'invalid';

    protected $fillable = [
        'scope',
        'provider',
        'profile_key',
        'mode',
        'username_encrypted',
        'password_encrypted',
        'api_key_encrypted',
        'token_encrypted',
        'username_mask',
        'api_key_mask',
        'token_mask',
        'credentials_status',
        'last_verified_at',
        'last_verification_status',
        'last_verification_message',
        'created_by',
        'updated_by',
        'metadata',
    ];

    protected $hidden = [
        'username_encrypted',
        'password_encrypted',
        'api_key_encrypted',
        'token_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'username_encrypted' => 'encrypted',
            'password_encrypted' => 'encrypted',
            'api_key_encrypted' => 'encrypted',
            'token_encrypted' => 'encrypted',
            'last_verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function basicAuthReady(): bool
    {
        return $this->credentials_status === self::STATUS_CONFIGURED
            && filled($this->username_encrypted)
            && filled($this->password_encrypted);
    }

    public function apiKeyReady(): bool
    {
        return $this->credentials_status === self::STATUS_CONFIGURED
            && (filled($this->api_key_encrypted) || filled($this->token_encrypted));
    }
}
