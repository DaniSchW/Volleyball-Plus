<?php

return [

    /*
    | Proxies whose X-Forwarded-* headers are trusted (comma separated IPs,
    | or "*"). Needed when the hoster terminates TLS in front of PHP, so the
    | app still recognises the connection as HTTPS.
    */
    'proxies' => env('TRUSTED_PROXIES'),

];
