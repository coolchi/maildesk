<?php

/*
|--------------------------------------------------------------------------
| Workspace subdomains
|--------------------------------------------------------------------------
|
| Labels that can never be claimed as a workspace subdomain
| ({subdomain}.{base_domain}). The first label of every central domain
| (maildesk.central_domains) is reserved as well, at runtime.
| Extra labels can be added with SUBDOMAINS_RESERVED=foo,bar.
|
*/

return [
    'reserved' => array_values(array_unique(array_filter(array_map('trim', array_merge([
        'www', 'app', 'api', 'admin', 'mail', 'smtp', 'imap', 'pop', 'ftp',
        'docs', 'help', 'support', 'billing', 'status', 'blog', 'dashboard',
        'static', 'assets', 'cdn', 'dev', 'staging', 'test', 'root', 'ns1', 'ns2',
    ], explode(',', (string) env('SUBDOMAINS_RESERVED', ''))))))),
];
