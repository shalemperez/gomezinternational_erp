<?php

return [
    // Google Workspace domain whose accounts may log in (hd claim). Null disables domain checking only for allow-listed emails.
    'google_workspace_domain' => env('GOOGLE_WORKSPACE_DOMAIN'),

    // Comma-separated Google account emails (possibly outside the domain) that are support Super Admins.
    'super_admin_emails' => array_values(array_filter(array_map(
        fn (string $e): string => strtolower(trim($e)),
        explode(',', (string) env('SUPER_ADMIN_EMAILS', ''))
    ))),

    'exchange_rates' => [
        'fetch_time' => '08:30', // app timezone
    ],
];
