<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BreetAsset extends Model
{
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType      = 'string';

    protected $fillable = [
        'id', 'symbol', 'name', 'identifier', 'network',
        'type', 'icon', 'tx_link', 'minimum', 'flag_fee_usd',
        'is_account_based', 'is_active',
    ];

    protected $casts = [
        'minimum'          => 'float',
        'flag_fee_usd'     => 'float',
        'is_account_based' => 'boolean',
        'is_active'        => 'boolean',
    ];

    /**
     * Look up a Breet asset by ticker symbol + network.
     * Used by SellController to resolve asset_id from user input.
     *
     * Example:
     *   BreetAsset::resolve('USDT', 'trc20')  → BreetAsset
     *   BreetAsset::resolve('BTC', 'bitcoin') → BreetAsset
     */
    public static function resolve(string $symbol, string $network): self
    {
        $asset = static::where('symbol', strtoupper($symbol))
            ->where('is_active', true)
            ->whereRaw('LOWER(network) = ?', [strtolower($network)])
            ->first();

        if (!$asset) {
            throw new \Exception("Unsupported asset: {$symbol} on {$network}.");
        }

        return $asset;
    }
}