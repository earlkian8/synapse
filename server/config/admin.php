<?php

/*
|--------------------------------------------------------------------------
| Admin portal (ADR 0085)
|--------------------------------------------------------------------------
|
| The platform admin portal in `admin/` signs in against /api/admin. Its
| sessions are bearer tokens on the `admin` guard, deliberately short-lived:
| one ends `session_lifetime` minutes after sign-in whatever happens, and
| sooner once nothing has used it for `idle_timeout` minutes.
|
*/

return [

    // Minutes a portal session lasts from sign-in.
    'session_lifetime' => (int) env('ADMIN_SESSION_LIFETIME', 480),

    // Minutes without a request before a portal session ends.
    'idle_timeout' => (int) env('ADMIN_IDLE_TIMEOUT', 30),

    // Minutes between a right password and the authenticator code it needs.
    'challenge_ttl' => 5,

    // Wrong authenticator codes one sign-in may spend before it is thrown away.
    'challenge_attempts' => 5,

    // The issuer an authenticator app shows beside the account.
    'two_factor_issuer' => env('ADMIN_TWO_FACTOR_ISSUER', 'SYNAPSE Admin'),

];
