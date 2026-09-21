<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * One place an account is signed in — §10.4's device management.
 *
 * Computed from a row of the `sessions` table and never stored. There is
 * no devices table, and there should not be: the session *is* the record
 * of being signed in, and a second copy of it would go stale the moment
 * somebody's session expired.
 */
final class Device
{
    public function __construct(
        /** The session id. Secret enough to be worth not printing anywhere. */
        public readonly string $id,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly CarbonInterface $lastActive,
        public readonly bool $isCurrent,
    ) {}

    /**
     * What to call this device on the screen.
     *
     * Read out of the user agent by hand rather than by a package. A user
     * agent parser is a list of strings that goes out of date, and on a
     * host updated by hand (ADR 0002) a stale dependency is worse than a
     * short matcher that says plainly when it does not recognise
     * something. Being wrong here is not a security failure — it is a
     * label on a row somebody is about to sign out — but *pretending* to
     * know is, because "Chrome on Windows" next to a session the owner
     * does not recognise is what they act on.
     */
    public function description(): string
    {
        $browser = $this->browser();
        $platform = $this->platform();

        if ($browser === null && $platform === null) {
            return __('messages.A device that did not say what it is');
        }

        if ($browser === null) {
            return __('messages.Something on :platform', ['platform' => $platform]);
        }

        if ($platform === null) {
            return $browser;
        }

        return __('messages.:browser on :platform', ['browser' => $browser, 'platform' => $platform]);
    }

    /**
     * Order matters: every one of these lies about the others.
     *
     * Edge and Opera both claim to be Chrome, Chrome claims to be Safari,
     * and Safari claims to be Mozilla. Checking the most specific first is
     * the only way this comes out right.
     */
    private function browser(): ?string
    {
        $agent = (string) $this->userAgent;

        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/'), str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            default => null,
        };
    }

    /** iPhone before iOS-flavoured Mac, Android before Linux. */
    private function platform(): ?string
    {
        $agent = (string) $this->userAgent;

        return match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh'), str_contains($agent, 'Mac OS') => 'a Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };
    }
}
