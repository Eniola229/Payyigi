<?php

return [
    'rate_lock_seconds' => env('PAYYIGI_RATE_LOCK_SECONDS', 60),

    // Breet's fixed fee — this is PayYigi's cost, not charged to user separately
    'breet_fee_percent' => 0.5,

    // Tiered platform fee (spread) based on transaction value in USD
    // < 100  → 3.5%
    // >= 100 → 2.5%
    'platform_fee_tiers' => [
        ['min' => 0,   'max' => 100,  'percent' => 3.5], // < 100 units
        ['min' => 100, 'max' => null, 'percent' => 2.5], // >= 100 units
    ],
    'supported_assets' => ['BTC', 'USDT', 'SOL', 'ETH', 'BNB', 'TRX', 'XRP', 'LTC', 'BCH', 'USDC', 'AVAX', 'TON', 'DOGE'],

    'supported_networks' => [
        'BTC'  => ['bitcoin'],
        'USDT' => ['trc20', 'erc20', 'bep20'],
        'SOL'  => ['solana'],
        'ETH'  => ['erc20'],
        'BNB'  => ['bep20'],
        'TRX'  => ['tron'],
        'XRP'  => ['xrp'],
        'LTC'  => ['litecoin'],
        'BCH'  => ['bitcoin-cash'],
        'USDC' => ['erc20', 'trc20', 'bep20'],
        'AVAX' => ['avax'],
        'TON'  => ['ton'],
        'DOGE' => ['dogecoin'],
    ],

    'min_withdrawal' => 100,
    'max_withdrawal' => 10_000_000,
];