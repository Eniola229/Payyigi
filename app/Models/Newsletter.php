<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class Newsletter extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'subject', 'content', 'audience', 'status',
        'total_recipients', 'sent_count', 'failed_count',
        'created_by', 'sent_at', 'error_message',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public const AUDIENCES = [
        'all',
        'no_kyc',
        'no_transactions',
        'verified_no_transactions',
        'dormant_30d',
        'suspended',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NewsletterRecipient::class);
    }

    /**
     * Resolve the User query for a given audience segment.
     */
    public static function audienceQuery(string $audience): Builder
    {
        return match ($audience) {
            'all' => User::query()->active(),

            'no_kyc' => User::query()->active()
                ->where('nin_verified', false),

            'no_transactions' => User::query()->active()
                ->whereDoesntHave('transactions'),

            'verified_no_transactions' => User::query()->active()
                ->where('nin_verified', true)
                ->whereDoesntHave('transactions'),

            'dormant_30d' => User::query()->active()
                ->where(function ($q) {
                    $q->where('last_login_at', '<', now()->subDays(30))
                      ->orWhereNull('last_login_at');
                }),

            'suspended' => User::query()->where('is_suspended', true),

            default => throw new InvalidArgumentException("Unknown newsletter audience: {$audience}"),
        };
    }
}