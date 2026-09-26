<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Monipay (https://monipay.ng/api-docs) — NGN plan upgrade payments.
    | The public key is used for initialize (and is the only key that may
    | ever reach the browser); the private (secret) key is server-only and
    | is used for verify and, unless MONIPAY_WEBHOOK_SECRET is set, for the
    | webhook HMAC-SHA512 signature. Amounts are always integer kobo.
    */
    'monipay' => [
        'base_url' => 'https://api.monipay.ng',
        'checkout_url' => 'https://checkout.monipay.ng',
        'inline_js_url' => 'https://js.monipay.ng/v2/inline.js',
        'public_key' => env('MONIPAY_PUBLIC_KEY'),
        'secret_key' => env('MONIPAY_SECRET_KEY'),
        'webhook_secret' => env('MONIPAY_WEBHOOK_SECRET'),
        'currency' => 'NGN',
        'min_amount' => 5000, // kobo (₦50.00)
        'timeout' => (int) (env('MONIPAY_TIMEOUT') ?: 20),
        // Plan prices are stored as whole currency units; when a plan has no
        // explicit price_kobo, kobo = price × this rate × 100.
        'naira_per_price_unit' => (float) (env('MONIPAY_NAIRA_PER_PLAN_PRICE_UNIT') ?: 1),
        'log_channel' => env('MONIPAY_LOG_CHANNEL'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
