<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['username', 'full_name', 'password_hash', 'role_code', 'temsilci_kodu', 'aktif', 'last_login_at'])]
#[Hidden(['password_hash', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected $table = 'panel.users';

    protected $appends = [
        'name',
        'email',
        'is_active',
        'representative_code',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'aktif' => 'boolean',
            'password_hash' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_code', 'code');
    }

    public function getNameAttribute(): string
    {
        return (string) $this->full_name;
    }

    public function getEmailAttribute(): string
    {
        return (string) $this->username;
    }

    public function getRepresentativeCodeAttribute(): ?string
    {
        return $this->temsilci_kodu;
    }

    public function getIsActiveAttribute(): bool
    {
        return (bool) $this->aktif;
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function setPasswordAttribute(string $password): void
    {
        $this->attributes['password_hash'] = Hash::make($password);
    }
}
