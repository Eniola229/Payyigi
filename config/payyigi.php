<?php

return [
    'rate_lock_seconds' => env('PAYYIGI_RATE_LOCK_SECONDS', 60),

    'platform_fee_percent' => env('PAYYIGI_PLATFORM_FEE', 0.5),

    'platform_fee_tiers' => [
        ['min' => 0,   'max' => 100,  'percent' => 3.5],
        ['min' => 100, 'max' => null, 'percent' => 2.5],
    ],

    // LocalRamp country & currency settings
    'country_code'  => env('PAYYIGI_COUNTRY_CODE', 'NG'),
    'fiat_currency' => env('PAYYIGI_FIAT_CURRENCY', 'NGN'),

    'supported_assets' => ['BTC', 'USDT', 'SOL', 'ETH', 'BNB', 'TRX', 'XRP', 'LTC', 'BCH', 'USDC', 'AVAX', 'TON', 'DOGE'],

    'min_withdrawal' => 100,
    'max_withdrawal' => 10_000_000,
];