<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    use HasUuid;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'reference', 'user_id', 'email', 'subject', 'status',
        'escalation_reason', 'escalated_at', 'last_message_at',
    ];

    protected $casts = [
        'escalated_at'    => 'datetime',
        'last_message_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($ticket) {
            if (empty($ticket->reference)) {
                do {
                    $ref = 'TKT-' . strtoupper(Str::random(8));
                } while (self::where('reference', $ref)->exists());
                $ticket->reference = $ref;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('created_at');
    }

    public function isEscalated(): bool { return $this->status === 'escalated'; }
    public function isClosed(): bool    { return $this->status === 'closed'; }
}
