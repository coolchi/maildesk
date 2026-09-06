<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default mail provider
    |--------------------------------------------------------------------------
    |
    | Supported: "resend", "smtp"
    |
    */
    'default_provider' => env('MAILDESK_PROVIDER', 'resend'),

    /*
    |--------------------------------------------------------------------------
    | Fake outbound delivery
    |--------------------------------------------------------------------------
    |
    | When true, MailManager uses ArrayProvider so compose/API sends succeed
    | without a live Resend/SMTP credential (local + tests by default).
    |
    */
    'fake_send' => env('MAILDESK_FAKE_SEND', env('APP_ENV') === 'local' || env('APP_ENV') === 'testing'),

    'providers' => [
        'resend' => [
            'driver' => 'resend',
            'api_key' => env('RESEND_API_KEY'),
        ],
        'smtp' => [
            'driver' => 'smtp',
            'host' => env('MAILDESK_SMTP_HOST'),
            'port' => env('MAILDESK_SMTP_PORT', 587),
            'username' => env('MAILDESK_SMTP_USERNAME'),
            'password' => env('MAILDESK_SMTP_PASSWORD'),
            'encryption' => env('MAILDESK_SMTP_ENCRYPTION', 'tls'),
        ],
    ],

    'api' => [
        'prefix' => 'v1',
        'rate_limit' => env('MAILDESK_API_RATE_LIMIT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-tenant hosts
    |--------------------------------------------------------------------------
    |
    | Central domains serve the shared app shell (workspace switcher). Tenant
    | subdomains are {subdomain}.{base_domain}, e.g. acme.maildesk.test.
    |
    */
    'base_domain' => env('MAILDESK_BASE_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),

    'central_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'MAILDESK_CENTRAL_DOMAINS',
            parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost',
        )),
    ))),

];
