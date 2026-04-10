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

    // ── Korapay (NIN verification + future payments) ──────────────────────────
    'korapay' => [
        'secret_key'  => env('KORAPAY_SECRET_KEY'),
        'public_key'  => env('KORAPAY_PUBLIC_KEY'),
        'base_url'    => env('KORAPAY_BASE_URL', 'https://api.korapay.com/merchant/api/v1'),
        // Sandbox: https://api.korapay.com/merchant/api/v1 (same URL, different keys)
    ],

    // ── Breet (Crypto-to-NGN) ─────────────────────────────────────────────────
    'breet' => [
        'api_key'        => env('BREET_API_KEY'),
        'api_secret'     => env('BREET_API_SECRET'),
        'base_url'       => env('BREET_API_BASE_URL', 'https://api.breet.app/v1'),
        'webhook_secret' => env('BREET_WEBHOOK_SECRET'),
    ],

];
