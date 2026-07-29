<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MailTransportProfile extends Model
{
    use HasFactory;

    public const SCOPE_TECHNICAL_SERVICE = 'technical_service';
    public const PROFILE_DEFAULT = 'default';
    public const MAILER_SMTP = 'smtp';
    public const PROTOCOL_IMAP = 'imap';
    public const PROTOCOL_POP3 = 'pop3';
    public const STATUS_READY = 'ready';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'scope',
        'profile_key',
        'display_name',
        'outgoing_enabled',
        'outgoing_mailer',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username_encrypted',
        'smtp_password_encrypted',
        'smtp_username_mask',
        'smtp_password_mask',
        'from_address',
        'from_name',
        'incoming_enabled',
        'incoming_protocol',
        'incoming_host',
        'incoming_port',
        'incoming_encryption',
        'incoming_username_encrypted',
        'incoming_password_encrypted',
        'incoming_username_mask',
        'incoming_password_mask',
        'incoming_mailbox',
        'last_outgoing_tested_at',
        'last_outgoing_test_status',
        'last_outgoing_test_message',
        'last_incoming_tested_at',
        'last_incoming_test_status',
        'last_incoming_test_message',
        'created_by',
        'updated_by',
        'metadata',
    ];

    protected $hidden = [
        'smtp_username_encrypted',
        'smtp_password_encrypted',
        'incoming_username_encrypted',
        'incoming_password_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'outgoing_enabled' => 'boolean',
            'incoming_enabled' => 'boolean',
            'smtp_username_encrypted' => 'encrypted',
            'smtp_password_encrypted' => 'encrypted',
            'incoming_username_encrypted' => 'encrypted',
            'incoming_password_encrypted' => 'encrypted',
            'last_outgoing_tested_at' => 'datetime',
            'last_incoming_tested_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function outgoingReady(): bool
    {
        return $this->outgoing_enabled
            && $this->outgoing_mailer === self::MAILER_SMTP
            && filled($this->smtp_host)
            && filled($this->smtp_port)
            && filled($this->smtp_username_encrypted)
            && filled($this->smtp_password_encrypted)
            && filled($this->from_address);
    }

    public function incomingReady(): bool
    {
        return $this->incoming_enabled
            && in_array($this->incoming_protocol, [self::PROTOCOL_IMAP, self::PROTOCOL_POP3], true)
            && filled($this->incoming_host)
            && filled($this->incoming_port)
            && filled($this->incoming_username_encrypted)
            && filled($this->incoming_password_encrypted);
    }
}
