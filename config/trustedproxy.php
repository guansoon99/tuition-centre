<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Which upstream addresses are believed when they send X-Forwarded-* headers.
| Read by App\Http\Middleware\TrustProxies, which the framework's base class
| consults through config('trustedproxy.proxies').
|
| Accepted values for TRUSTED_PROXIES:
|
|   (unset)       Trust nothing. Correct for a box that faces the internet
|                 directly — staging, local dev. Forwarded headers from
|                 anyone are ignored.
|   cloudflare    Trust Cloudflare's published edge ranges only. The
|                 production setting. Pair it with a firewall that admits
|                 80/443 from those same ranges and nothing else — otherwise
|                 a client connecting straight to the origin can forge the
|                 header, and the login throttle keys on the forged address.
|   1.2.3.4,...   Explicit list, comma separated.
|   *             Trust every caller. Only ever right when the box is
|                 unreachable except through a proxy you control.
|
*/

return [
    'proxies' => env('TRUSTED_PROXIES'),
];
