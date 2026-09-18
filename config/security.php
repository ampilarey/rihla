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

    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

];
