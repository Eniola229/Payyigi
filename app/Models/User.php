<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasUuid, Notifiable, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'transaction_pin',
        'nin',
        'nin_verified',
        'nin_verified_at',
        'nin_phone',
        'kyc_level',
        'kyc_verified_at',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'is_active',
        'is_suspended',
        'suspension_reason',
        'suspended_at',
        'referral_code',
        'referred_by',
        'avatar',
        'date_of_birth',
        'bvn',
        'bvn_verified',
        'last_login_ip',
        'last_login_at',
        'last_login_device',
    ];

    protected $hidden = [
        'password',
        'transaction_pin',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'nin',
        'bvn',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'        => 'datetime',
        'nin_verified_at'          => 'datetime',
        'kyc_verified_at'          => 'datetime',
        'suspended_at'             => 'datetime',
        'last_login_at'            => 'datetime',
        'nin_verified'             => 'boolean',
        'bvn_verified'             => 'boolean',
        'two_factor_enabled'       => 'boolean',
        'is_active'                => 'boolean',
        'is_suspended'             => 'boolean',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_secret'        => 'encrypted',
        'nin'                      => 'encrypted',
        'bvn'                      => 'encrypted',
    ];

    // ─── Relationships ───────────────────────────────────────────────────────

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class)->where('currency', 'NGN');
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function defaultBankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class)->where('is_default', true);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function otpCodes(): HasMany
    {
        return $this->hasMany(OtpCode::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // ─── Accessors ───────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    // ─── Security Methods ────────────────────────────────────────────────────

    public function verifyTransactionPin(string $pin): bool
    {
        return Hash::check($pin, $this->transaction_pin);
    }

    public function setTransactionPinAttribute(string $value): void
    {
        $this->attributes['transaction_pin'] = Hash::make($value);
    }

    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = Hash::make($value);
    }

    public function isAccountActive(): bool
    {
        return $this->is_active && !$this->is_suspended;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_enabled && !is_null($this->two_factor_secret);
    }

    public function hasVerifiedNin(): bool
    {
        return $this->nin_verified;
    }

    public function canWithdraw(): bool
    {
        return $this->hasVerifiedEmail()
            && $this->hasVerifiedNin()
            && $this->kyc_level !== 'none'
            && $this->isAccountActive();
    }

    public function dailyWithdrawalLimit(): int
    {
        return match ($this->kyc_level) {
            'basic'    => 500000000, // ₦5,000,000 in kobo
            'advanced' => PHP_INT_MAX,
            default    => 50000000, // ₦500,000 in kobo (no kyc)
        };
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('is_suspended', false);
    }

    public function scopeKycVerified($query)
    {
        return $query->whereIn('kyc_level', ['basic', 'advanced']);
    }
}