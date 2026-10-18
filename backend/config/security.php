<?php

return [

    /*
    | Reject every request that did not arrive via HTTPS. Only disable this
    | for local development – production must never run without it.
    */
    'force_https' => (bool) env('FORCE_HTTPS', true),

    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 63072000),

    /*
    | Login: magic links and the long-lived session cookie. The __Host-
    | prefix pins the cookie to this host and HTTPS; it needs Secure, so
    | local HTTP development falls back to a plain name.
    */
    'magic_link_minutes' => (int) env('MAGIC_LINK_MINUTES', 15),

    'session' => [
        'cookie' => env('FORCE_HTTPS', true) ? '__Host-vp_session' : 'vp_session',
        'secure' => (bool) env('FORCE_HTTPS', true),
        // Sliding: every use extends the session to this many days from now.
        'lifetime_days' => (int) env('SESSION_LIFETIME_DAYS', 90),
        // Write the extension to the database at most this often.
        'touch_after_minutes' => 60 * 24,
    ],

    'ssl_check' => [
        // Host to check; defaults to the host of APP_URL.
        'host' => env('SSL_CHECK_HOST'),
        'port' => (int) env('SSL_CHECK_PORT', 443),
        'warn_days' => (int) env('SSL_CHECK_WARN_DAYS', 14),
    ],

    /*
    | Push messages are only sent to the push services of the browsers
    | (Chrome/Android, Firefox, Safari/iOS, Edge). Host names must end
    | with one of these.
    */
    'push_hosts' => ['fcm.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'],

    /*
    | Chat attachments. The real limit is the smallest of this and PHP's
    | upload_max_filesize / post_max_size (on ALL-INKL set in the KAS under
    | PHP settings). Pictures are scaled down and re-encoded (drops the
    | location data), videos and documents are stored as uploaded.
    */
    'chat_upload' => [
        'max_mb' => (int) env('CHAT_UPLOAD_MAX_MB', 50),
        'image_max_px' => 1600,
        'per_message' => 10,
    ],

];
