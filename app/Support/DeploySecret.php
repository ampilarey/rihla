<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The shared secret between GitHub Actions and this host.
 *
 * It was defined once, inside the webhook controller. The health endpoint now
 * needs the same question answered — is this caller the deploy pipeline? — and
 * the business phone number in this codebase was recently found in fourteen
 * places for want of somewhere to put it once.
 */
class DeploySecret
{
    /**
     * The configured secret, or null when there is effectively none.
     *
     * A short secret is treated as no secret: a guessable one would be worse
     * than none, because it looks like protection.
     */
    public static function configured(): ?string
    {
        $secret = (string) config('deploy.test_webhook_secret', '');

        return strlen($secret) >= 16 ? $secret : null;
    }

    public static function presentedIn(Request $request): string
    {
        // header() returns a string when given a string default.
        $header = $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }

        return trim($request->header('X-Deploy-Secret', ''));
    }

    /** Constant-time, and false whenever no secret is configured. */
    public static function matches(Request $request): bool
    {
        $secret = self::configured();
        $provided = self::presentedIn($request);

        if ($secret === null || $provided === '') {
            return false;
        }

        return hash_equals($secret, $provided);
    }
}
