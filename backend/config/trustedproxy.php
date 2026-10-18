<?php

return [

    /*
    | Proxies whose X-Forwarded-* headers are trusted (comma separated IPs,
    | or "*"). Needed when the hoster terminates TLS in front of PHP, so the
    | app still recognises the connection as HTTPS. By default only proxies
    | in private networks (the usual shared-hosting setup) are trusted;
    | clients from the internet can never fake the header.
    */
    'proxies' => env('TRUSTED_PROXIES', 'PRIVATE_SUBNETS'),

];
