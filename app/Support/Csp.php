<?php

namespace App\Support;

/**
 * The per-request nonce that tells the browser which scripts this application
 * wrote.
 *
 * A Content-Security-Policy that names a nonce refuses every other script on
 * the page. That is the whole protection: an attacker who manages to get
 * `<script>…</script>` into a trip title, a guide step or a media caption
 * cannot guess the nonce, so the browser will not run it — and injected
 * markup is how cross-site scripting nearly always arrives.
 *
 * It has to be unguessable and it has to be new on every response. A nonce
 * reused across requests, or cached into a page, is a nonce an attacker can
 * read once and then include in their own injected tag.
 */
class Csp
{
    /**
     * Held on the container rather than in a static, so a second request in
     * the same process — a test, or a queue worker rendering mail — cannot
     * inherit the first one's nonce.
     */
    public static function nonce(): string
    {
        if (! app()->bound('csp.nonce')) {
            app()->instance('csp.nonce', base64_encode(random_bytes(16)));
        }

        return app('csp.nonce');
    }

    /** Called at the start of each request so nothing carries over. */
    public static function forget(): void
    {
        app()->forgetInstance('csp.nonce');
    }
}
