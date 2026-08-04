<?php

return [
    'company_timezone' => env('ATMS_COMPANY_TIMEZONE', 'Africa/Tripoli'),
    'attachment_disk' => env('ATMS_ATTACHMENT_DISK', 'attachments'),

    /*
     * Base URL of the Vue SPA, used to build the deep links sent in email. It is
     * separate from APP_URL because the SPA and the API need not share a host;
     * APP_URL is the fallback for single-host deployments.
     *
     * `?:` rather than an env() default on purpose: Compose injects this key as an
     * empty string when the root .env omits it, and an empty string is not "unset",
     * so a default argument would never apply and links would come out relative.
     */
    'frontend_url' => env('FRONTEND_URL') ?: env('APP_URL'),
    'allowed_email_domains' => array_map(
        'trim',
        explode(',', (string) env('ATMS_ALLOWED_EMAIL_DOMAINS', 'ldc.com.ly')),
    ),
];
