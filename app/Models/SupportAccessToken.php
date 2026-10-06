<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SupportAccessToken extends Model
{
    use HasUuid;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['email', 'token_hash', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];
}
