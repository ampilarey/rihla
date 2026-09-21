<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second factor on the staff panel — §10.4's "MFA for staff".
 *
 * ## Three states, and none of them locks anybody out
 *
 * 1. **Has a confirmed factor, session not yet verified** → the challenge.
 * 2. **Role requires one, has none** → enrolment, which they can always
 *    complete. Enforcement means "you must set this up", never "you cannot
 *    get in": a staff account nobody can reach is an outage, and an outage
 *    is how a security control gets switched off for good.
 * 3. **Anything else** → straight through.
 *
 * ## Why the session is remembered rather than re-challenged
 *
 * Asking on every request is how people write the code on a sticky note
 * beside the monitor. `mfa.remember_minutes` is the trade, and it is
 * stored as the moment of verification rather than a boolean so that a
 * session cannot outlive it by staying open.
 */
class RequireSecondFactor
{
    /** The session key holding when this session last proved the factor. */
    public const VERIFIED_AT = 'mfa.verified_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Defence in depth. As wired today the MFA routes live in the web
        // group and never reach this middleware at all, so this branch is
        // unreachable — but the day somebody adds RequireSecondFactor to
        // the web group, a screen that redirects to itself is a staff
        // account nobody can open. `SecondFactorTest` proves the round trip
        // end to end rather than trusting this line.
        if ($request->routeIs('mfa.*')) {
            return $next($request);
        }

        if ($user->hasSecondFactor()) {
            return $this->verifiedRecently($request)
                ? $next($request)
                : redirect()->route('mfa.challenge');
        }

        if ($user->mustHaveSecondFactor()) {
            return redirect()->route('mfa.enrol')
                ->with('status', __('messages.Your role needs a second step at sign-in. Set it up to carry on.'));
        }

        return $next($request);
    }

    private function verifiedRecently(Request $request): bool
    {
        $at = $request->session()->get(self::VERIFIED_AT);

        if ($at === null) {
            return false;
        }

        return Carbon::parse((string) $at)
            ->gt(Carbon::now()->subMinutes((int) config('mfa.remember_minutes', 720)));
    }
}
