<?php

return [

    /*
    |--------------------------------------------------------------------------
    | "Log in as" (impersonation)
    |--------------------------------------------------------------------------
    |
    | Used by platform admins and workspace owners/admins. Sessions are
    | strictly read-only and end automatically after `ttl_minutes`. Starting
    | one requires the actor to have confirmed their password within
    | `password_timeout_seconds`.
    |
    */

    'ttl_minutes' => (int) env('IMPERSONATION_TTL_MINUTES', 30),

    'password_timeout_seconds' => (int) env('IMPERSONATION_PASSWORD_TIMEOUT', 600),

    /*
    | Route names that stay reachable with a mutating HTTP method while
    | impersonating (everything else that is not GET/HEAD/OPTIONS is blocked).
    */
    'allowed_mutations' => [
        'impersonate.leave',
        'logout',
    ],

    /*
    | Route-name patterns denied for every HTTP method (including GET) while
    | impersonating: credentials, secrets, account security and side-effecting
    | GET endpoints.
    */
    'denied_routes' => [
        'admin.*',
        'password.*',
        'profile.*',
        'verification.*',
        'api-keys.*',
        'settings.smtp.*',
        'domains.dns.*',
        'billing.*',
        'workspace.switch',
        'workspaces.store',
    ],

];
