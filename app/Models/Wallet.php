<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasFactory, HasUuid;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id', 'currency', 'balance', 'locked_balance', 'is_active',
    ];

    protected $casts = [
        'balance'        => 'decimal:2',
        'locked_balance' => 'decimal:2',
        'is_active'      => 'boolean',
    ];

    // ─── Relationships ───────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    // ─── Balance Methods ─────────────────────────────────────────────────────

    public function getAvailableBalance(): float
    {
        return (float) ($this->balance - $this->locked_balance);
    }

    public function hasSufficientBalance(float $amount): bool
    {
        return $this->getAvailableBalance() >= $amount;
    }

    /**
     * Credit wallet — always wrap in DB transaction
     */
    public function credit(float $amount): void
    {
        $this->increment('balance', $amount);
        $this->refresh();
    }

    /**
     * Debit wallet — always wrap in DB transaction
     */
    public function debit(float $amount): void
    {
        if (!$this->hasSufficientBalance($amount)) {
            throw new \Exception('Insufficient wallet balance.');
        }
        $this->decrement('balance', $amount);
        $this->refresh();
    }

    /**
     * Lock funds for a pending transaction
     */
    public function lockFunds(float $amount): void
    {
        $this->increment('locked_balance', $amount);
    }

    /**
     * Release locked funds
     */
    public function unlockFunds(float $amount): void
    {
        $this->decrement('locked_balance', $amount);
    }
}