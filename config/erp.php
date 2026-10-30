<?php

return [
    // Google Workspace domain whose accounts may log in (hd claim). Null disables domain checking only for allow-listed emails.
    'google_workspace_domain' => ($d = strtolower(trim((string) env('GOOGLE_WORKSPACE_DOMAIN', '')))) === '' ? null : $d,

    // Comma-separated Google account emails (possibly outside the domain) that are support Super Admins.
    'super_admin_emails' => array_values(array_filter(array_map(
        fn (string $e): string => strtolower(trim($e)),
        explode(',', (string) env('SUPER_ADMIN_EMAILS', ''))
    ))),

    // Comma-separated proxy IPs/CIDRs (or "*") whose X-Forwarded-* headers are trusted; empty trusts none.
    'trusted_proxies' => trim((string) env('TRUSTED_PROXIES', '')),

    'exchange_rates' => [
        'fetch_time' => '08:30', // app timezone
    ],

    'gmail' => [
        'driver' => env('GMAIL_DRIVER', env('APP_ENV') === 'production' ? 'google' : 'log'),
        'scope' => 'https://www.googleapis.com/auth/gmail.send',
    ],
];
