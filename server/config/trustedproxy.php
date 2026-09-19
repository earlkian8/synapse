<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The proxies whose X-Forwarded-* headers are believed — comma-separated
    | addresses or CIDR ranges, or "*" for whichever proxy is calling. Laravel's
    | TrustProxies middleware reads this key.
    |
    | Behind a load balancer the client's real address only arrives in
    | X-Forwarded-For, and an attendance policy's web address allowlist
    | (ADR 0040) checks that address — so it must come from a proxy named here,
    | never from the client. Unset trusts none.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
