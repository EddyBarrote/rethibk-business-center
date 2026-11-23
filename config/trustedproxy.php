<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proxies in front of Nginx (docs/DEPLOY.md)
    |--------------------------------------------------------------------------
    |
    | Empty on the usual install: Nginx talks to PHP-FPM directly, so the
    | client's IP and HTTPS are already right. Behind Cloudflare or a load
    | balancer, list its addresses (comma-separated, CIDR allowed) or "*"
    | when nothing else can reach the server, so the login rate limit and
    | the audit log see each person's IP and links keep https.
    | Read by Illuminate\Http\Middleware\TrustProxies on every request.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
