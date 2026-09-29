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

    /*
    |--------------------------------------------------------------------------
    | Inbound email
    |--------------------------------------------------------------------------
    |
    | Providers POST received mail to /api/v1/inbound/{driver}. The resend
    | driver verifies Svix signatures with RESEND_WEBHOOK_SECRET (whsec_...).
    | The generic driver requires the X-MailDesk-Inbound-Secret header.
    | Leave a secret empty to skip verification (local development only).
    |
    */
    'inbound' => [
        'resend_webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
        'generic_secret' => env('MAILDESK_INBOUND_SECRET'),
        'signature_tolerance' => (int) env('MAILDESK_INBOUND_TOLERANCE', 300),
        'resend_api_url' => env('RESEND_API_URL', 'https://api.resend.com'),
        'attachments_disk' => env('MAILDESK_INBOUND_DISK', 'local'),
        // Log channel for inbound events (null = the default stack).
        'log_channel' => env('MAILDESK_INBOUND_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trash
    |--------------------------------------------------------------------------
    |
    | Conversations stay in Trash for this many days, then inbox:purge-trash
    | permanently deletes them (scheduled daily). Empty Trash deletes sooner.
    |
    */
    'trash' => [
        'retention_days' => (int) env('MAILDESK_TRASH_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sending domains
    |--------------------------------------------------------------------------
    |
    | Verification looks up SPF, DKIM, DMARC and MX in real DNS. SPF and
    | DKIM are required; a missing DMARC record is only a warning. Resend
    | domains are also registered with Resend (needs RESEND_API_KEY) so the
    | DNS records shown to users are Resend's real ones. The scheduler
    | re-checks unverified domains hourly and verified ones daily.
    |
    */
    'domains' => [
        'register_with_provider' => (bool) env('MAILDESK_DOMAINS_REGISTER', true),

        // How verification looks records up. "doh" asks a public resolver
        // (Cloudflare 1.1.1.1 over HTTPS) so a stale cache on the local
        // network can't report fresh records as missing. "system" uses the
        // machine's own resolver. DoH falls back to system if unreachable.
        'resolver' => env('MAILDESK_DNS_RESOLVER', 'doh'),
        'doh_url' => env('MAILDESK_DNS_DOH_URL', 'https://1.1.1.1/dns-query'),

        // When a domain has a connected DNS host (Cloudflare), every
        // verification pass publishes missing mail records and fixes drifted
        // ones automatically, including new records the provider starts
        // requiring later. Set false to only publish on the user's click.
        'auto_publish_dns' => (bool) env('MAILDESK_DNS_AUTO_PUBLISH', true),
    ],

    /*
    | Broadcasts are sent from the queue, one job per recipient. Sends are
    | throttled to stay under the provider's rate limit (Resend allows a
    | few requests per second by default). 0 disables the throttle.
    */
    'broadcasts' => [
        'per_second' => (int) env('MAILDESK_BROADCAST_PER_SECOND', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI providers
    |--------------------------------------------------------------------------
    |
    | Platform admin chooses the active provider and can store an encrypted API
    | key. Env values are the fallback when no key is saved in settings.
    | When "fake" is true (local/testing by default), the FakeAiProvider is used.
    |
    */
    'ai' => [
        'fake' => env('MAILDESK_AI_FAKE', env('APP_ENV') === 'local' || env('APP_ENV') === 'testing'),
        'default_provider' => env('MAILDESK_AI_PROVIDER', 'openai'),
        'providers' => [
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            ],
            'anthropic' => [
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
                'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
                'api_version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
            ],
            'fake' => [
                'model' => 'fake-model',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | E2E Mail Test
    |--------------------------------------------------------------------------
    |
    | Configuration for the end-to-end mail test (php artisan maildesk:e2e).
    | The test sends an email and waits for it to return via the inbound
    | webhook to verify the complete mail flow works.
    |
    */
    'e2e' => [
        'from' => env('MAILDESK_E2E_FROM', env('MAIL_FROM_ADDRESS')),
        'mailbox' => env('MAILDESK_E2E_MAILBOX', 'e2e-check@maildesk.ng'),
        'timeout' => (int) env('MAILDESK_E2E_TIMEOUT', 180),
        'organization_id' => env('MAILDESK_E2E_ORGANIZATION_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile App
    |--------------------------------------------------------------------------
    |
    | Settings for the MailDesk mobile app (iOS/Android).
    |
    */
    'mobile' => [
        'push_enabled' => (bool) env('MAILDESK_MOBILE_PUSH_ENABLED', true),
    ],

];
