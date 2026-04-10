<?php

return [
    'spread_percent'       => env('PAYYIGI_SPREAD_PERCENT', 4),
    'rate_lock_seconds'    => env('PAYYIGI_RATE_LOCK_SECONDS', 60),

    // Fees deducted from the NGN payout
    'platform_fee_percent' => env('PAYYIGI_PLATFORM_FEE', 0.5), // PayYigi's own fee
    'breet_fee_percent'    => 0.5,                               // Breet's fixed fee

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
