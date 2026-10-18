<?php

return [

    /*
    | Reject every request that did not arrive via HTTPS. Only disable this
    | for local development – production must never run without it.
    */
    'force_https' => (bool) env('FORCE_HTTPS', true),

    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 63072000),

    'ssl_check' => [
        // Host to check; defaults to the host of APP_URL.
        'host' => env('SSL_CHECK_HOST'),
        'port' => (int) env('SSL_CHECK_PORT', 443),
        'warn_days' => (int) env('SSL_CHECK_WARN_DAYS', 14),
    ],

];
