<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpCode extends Model
{
    use HasFactory, HasUuid;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id', 'code', 'phone', 'purpose',
        'used', 'used_at', 'attempts', 'ip_address', 'expires_at',
    ];

    protected $casts = [
        'used'       => 'boolean',
        'used_at'    => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $hidden = ['code'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return now()->gt($this->expires_at);
    }

    public function isValid(): bool
    {
        return !$this->used && !$this->isExpired() && $this->attempts < 3;
    }

    public function markUsed(): void
    {
        $this->update(['used' => true, 'used_at' => now()]);
    }

    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeValid($query)
    {
        return $query->where('used', false)
                     ->where('attempts', '<', 3)
                     ->where('expires_at', '>', now());
    }

    public function scopeForPurpose($query, string $purpose)
    {
        return $query->where('purpose', $purpose);
    }
}