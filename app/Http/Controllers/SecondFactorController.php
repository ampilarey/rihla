<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireSecondFactor;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Setting up and proving a second factor — §10.4.
 *
 * Plain routes rather than Filament pages, because the challenge has to be
 * reachable by somebody who has not yet passed it: a screen inside the
 * panel that the panel's own middleware guards cannot be the way through
 * that middleware.
 */
class SecondFactorController extends Controller
{
    /** The session key holding a secret somebody is part-way through enrolling. */
    private const PENDING = 'mfa.pending_secret';

    public function enrol(Request $request): View|RedirectResponse
    {
        $user = $this->user($request);

        if ($user->hasSecondFactor()) {
            return redirect()->route('mfa.settings');
        }

        // Kept in the session, not on the row: a secret written to `users`
        // before it is proved is a half-enrolment that {@see User::hasSecondFactor()}
        // has to keep explaining away.
        $secret = $request->session()->get(self::PENDING) ?? Totp::secret();
        $request->session()->put(self::PENDING, $secret);

        return view('mfa.enrol', [
            'secret' => $secret,
            'spaced' => Totp::spaced($secret),
            'uri' => Totp::uri($secret, $user->email, config('app.name', 'Rihla Travels')),
            'required' => $user->mustHaveSecondFactor(),
        ]);
    }

    public function confirm(Request $request): RedirectResponse|View
    {
        $user = $this->user($request);
        $secret = (string) $request->session()->get(self::PENDING);

        $data = $request->validate(['code' => ['required', 'string']]);

        if ($secret === '') {
            return redirect()->route('mfa.enrol');
        }

        if (! Totp::verify($secret, $data['code'])) {
            return back()->withErrors([
                'code' => __('messages.That code did not match. Check the clock on your telephone is right, and try the next one.'),
            ]);
        }

        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => now(),
        ])->save();

        $request->session()->forget(self::PENDING);
        $request->session()->put(RequireSecondFactor::VERIFIED_AT, now()->toDateTimeString());

        // Shown once, here, and never again — so they are handed over on
        // this screen rather than left to be found later.
        return view('mfa.recovery-codes', [
            'codes' => $user->regenerateRecoveryCodes(),
        ]);
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        $user = $this->user($request);

        if (! $user->hasSecondFactor()) {
            return redirect()->route('mfa.enrol');
        }

        return view('mfa.challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $data = $request->validate(['code' => ['required', 'string']]);

        // Five a minute per account. A six-digit code is a million
        // possibilities, which is a weekend of guessing at HTTP speed and
        // an afternoon without this.
        $key = 'mfa:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors([
                'code' => __('messages.Too many attempts. Wait a minute and try again.'),
            ]);
        }

        $code = (string) $data['code'];

        if (Totp::verify((string) $user->mfa_secret, $code) || $user->consumeRecoveryCode($code)) {
            RateLimiter::clear($key);

            // A new session id on a privilege change, so a fixated session
            // cannot ride through the factor it never passed.
            $request->session()->regenerate();
            $request->session()->put(RequireSecondFactor::VERIFIED_AT, now()->toDateTimeString());

            return redirect()->intended(route('filament.staff.pages.dashboard'));
        }

        RateLimiter::hit($key, 60);

        return back()->withErrors([
            'code' => __('messages.That code did not match. Use the next one your app shows, or one of your recovery codes.'),
        ]);
    }

    public function settings(Request $request): View
    {
        $user = $this->user($request);

        return view('mfa.settings', [
            'enabled' => $user->hasSecondFactor(),
            'required' => $user->mustHaveSecondFactor(),
            'remaining' => count((array) ($user->mfa_recovery_codes ?? [])),
        ]);
    }

    /**
     * Turn it off — but not for somebody whose role requires it.
     *
     * Refused rather than hidden: a button that silently does nothing is
     * worse than one that says why it will not.
     */
    public function disable(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        if ($user->mustHaveSecondFactor()) {
            return back()->withErrors([
                'code' => __('messages.Your role needs a second step at sign-in, so this cannot be turned off.'),
            ]);
        }

        $request->validate(['password' => ['required', 'current_password']]);

        $user->forceFill([
            'mfa_secret' => null,
            'mfa_confirmed_at' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        $request->session()->forget(RequireSecondFactor::VERIFIED_AT);

        return redirect()->route('mfa.settings')
            ->with('status', __('messages.The second step has been turned off for your account.'));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
