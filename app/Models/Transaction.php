<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Transaction extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id', 'wallet_id', 'reference', 'session_id', 'ip_address',
        'user_agent', 'type', 'entry_type', 'currency', 'amount', 'fee',
        'provider_fee', 'net_amount', 'spread_amount', 'balance_before',
        'balance_after', 'crypto_asset', 'crypto_network', 'crypto_amount',
        'swap_to_asset', 'swap_to_amount', 'rate', 'deposit_address',
        'crypto_tx_hash', 'provider_order_id', 'provider_reference', 'provider_response',
        'bank_account_id', 'bank_name', 'bank_code', 'account_number',
        'account_name', 'bank_transfer_reference', 'transfer_to_user_id',
        'status', 'failure_reason', 'notes', 'rate_locked_at',
        'rate_expires_at', 'completed_at', 'failed_at', 'metadata', 'flagged_at'
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'fee'            => 'decimal:2',
        'provider_fee'      => 'decimal:2',
        'net_amount'     => 'decimal:2',
        'spread_amount'  => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after'  => 'decimal:2',
        'crypto_amount'  => 'decimal:10',
        'swap_to_amount' => 'decimal:10',
        'rate'           => 'decimal:2',
        'provider_response' => 'array',
        'metadata'       => 'array',
        'rate_locked_at' => 'datetime',
        'rate_expires_at'=> 'datetime',
        'completed_at'   => 'datetime',
        'failed_at'      => 'datetime',
        'flagged_at'      => 'datetime',
    ];

    // ─── Boot ────────────────────────────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->reference)) {
                $model->reference = self::generateReference();
            }
        });
    }

    public static function generateReference(): string
    {
        do {
            $ref = 'TXN-' . strtoupper(Str::random(12));
        } while (self::where('reference', $ref)->exists());

        return $ref;
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function fraudFlags(): BelongsTo
    {
        return $this->belongsTo(FraudFlag::class, 'id');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function isPending(): bool     { return $this->status === 'pending'; }
    public function isCompleted(): bool   { return $this->status === 'completed'; }
    public function isFailed(): bool      { return $this->status === 'failed'; }
    public function isExpired(): bool     { return $this->status === 'expired'; }

    public function isRateLocked(): bool
    {
        return $this->rate_expires_at && now()->lt($this->rate_expires_at);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeCompleted($query)  { return $query->where('status', 'completed'); }
    public function scopePending($query)    { return $query->where('status', 'pending'); }
    public function scopeOfType($query, string $type) { return $query->where('type', $type); }
    public function scopeCredit($query)     { return $query->where('entry_type', 'credit'); }
    public function scopeDebit($query)      { return $query->where('entry_type', 'debit'); }
}