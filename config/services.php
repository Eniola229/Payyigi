<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'mailgun' => [
        'domain'   => env('MAILGUN_DOMAIN'),
        'secret'   => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme'   => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // ── Twilio ────────────────────────────────────────────────────────────────
    'twilio' => [
        'sid'   => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from'  => env('TWILIO_FROM', env('TWILIO_NUMBER')),
    ],

    // ── Termii ────────────────────────────────────────────────────────────────
    'termii' => [
        'api_key'   => env('TERMII_API_KEY'),
        'sender_id' => env('TERMII_SENDER_ID', 'PayYigi'),
        'base_url'  => env('TERMII_BASE_URL', 'https://api.ng.termii.com/api'),
        'channel'   => 'dnd', // dnd = transactional route, bypasses DND numbers
    ],
    // ── Korapay (NIN verification + BVN + future payments) ──────────────────────────
    'korapay' => [
        'secret_key'  => env('KORAPAY_SECRET_KEY'),
        'public_key'  => env('KORAPAY_PUBLIC_KEY'),
        'base_url'    => env('KORAPAY_BASE_URL', 'https://api.korapay.com/merchant/api/v1'),
    ],

    // ── Localramp (Crypto-to-NGN) ─────────────────────────────────────────────────
    'localramp' => [
        'secret_key' => env('LOCALRAMP_SECRET_KEY'),
        'public_key'  => env('LOCALRAMP_PUBLIC_KEY'),
        'base_url'    => env('LOCALRAMP_BASE_URL', 'https://api.localramp.co/v1'),
    ],

    'company_account_number' => env('COMPANY_ACCOUNT_NUMBER'),
    'company_bank_code'      => env('COMPANY_BANK_CODE'),
    'company_account_name'   => env('COMPANY_ACCOUNT_NAME'),

];
