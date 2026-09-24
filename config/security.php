<?php

return [

    /*
    |---------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |---------------------------------------------------------------------------
    |
    | Sent only over HTTPS. Browsers cache this for the lifetime given, so it
    | is not fully reversible: setting the value to 0 stops the header being
    | issued, but a browser that already saw it will keep refusing plain HTTP
    | for that host until the original max-age expires.
    |
    | `includeSubDomains` and `preload` are deliberately not sent. Both extend
    | the promise to hosts this application does not control, and preload is
    | effectively permanent.
    |
    */

    /*
    |---------------------------------------------------------------------------
    | Self-registration
    |---------------------------------------------------------------------------
    |
    | Off. Breeze's `/register` was left open (D16): anybody could make an
    | account that holds no role, reaches nothing, and — because User must
    | verify its email — mails whatever address was typed. Nothing on the
    | site links to it. Staff are created with `php artisan admin:create`,
    | and a pilgrim reaches their booking through a signed link, not an
    | account. Set REGISTRATION_OPEN=true only if that ever changes.
    |
    */

    'registration_open' => filter_var(env('REGISTRATION_OPEN', false), FILTER_VALIDATE_BOOL),

    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

    /*
    |---------------------------------------------------------------------------
    | Content-Security-Policy
    |---------------------------------------------------------------------------
    |
    | Enforcing by default. Set SECURITY_CSP_REPORT_ONLY=true to send it as
    | Content-Security-Policy-Report-Only instead, which reports violations
    | without blocking anything — useful when adding a new embed or widget and
    | you want to see what it needs before it can break a live page.
    |
    | SECURITY_CSP=false removes the header entirely. That is an escape hatch
    | for an emergency, not a setting to leave alone.
    |
    */

    'csp_enabled' => filter_var(env('SECURITY_CSP', true), FILTER_VALIDATE_BOOL),

    'csp_report_only' => filter_var(env('SECURITY_CSP_REPORT_ONLY', false), FILTER_VALIDATE_BOOL),

];
