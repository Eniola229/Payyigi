<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterRecipient extends Model
{
    use HasUuid;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['newsletter_id', 'user_id', 'status', 'error', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}