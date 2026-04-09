<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    use HasFactory, HasUuid;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id', 'device_fingerprint', 'device_name', 'device_type',
        'browser', 'platform', 'ip_address', 'is_trusted', 'trusted_at', 'last_used_at',
    ];

    protected $casts = [
        'is_trusted'   => 'boolean',
        'trusted_at'   => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trust(): void
    {
        $this->update(['is_trusted' => true, 'trusted_at' => now()]);
    }

    public function markUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }
}