<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentProviderCredential extends Model
{
    use HasFactory;

    public const SCOPE_TECHNICAL_SERVICE = 'technical_service';
    public const PROVIDER_IYZICO = 'iyzico';
    public const MODE_SANDBOX = 'sandbox';
    public const MODE_LIVE = 'live';
    public const STATUS_MISSING = 'missing';
    public const STATUS_CONFIGURED = 'configured';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'scope',
        'provider',
        'mode',
        'api_key_encrypted',
        'secret_key_encrypted',
        'api_key_mask',
        'secret_key_mask',
        'credentials_status',
        'last_verified_at',
        'last_verification_status',
        'last_verification_message',
        'created_by',
        'updated_by',
        'metadata',
    ];

    protected $hidden = [
        'api_key_encrypted',
        'secret_key_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'api_key_encrypted' => 'encrypted',
            'secret_key_encrypted' => 'encrypted',
            'last_verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isConfigured(): bool
    {
        return $this->credentials_status === self::STATUS_CONFIGURED
            && filled($this->api_key_encrypted)
            && filled($this->secret_key_encrypted);
    }
}
