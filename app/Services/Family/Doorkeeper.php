<?php

namespace App\Services\Family;

use App\Models\Booking;
use App\Models\FamilyAccess;
use App\Services\Portal\Gatekeeper;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * The way into the Family Portal — §6.2.
 *
 * Deliberately a separate class from {@see Gatekeeper}
 * with its own session keys, not a mode on it. A family session must never
 * satisfy a pilgrim-portal check: the two carry different amounts of
 * somebody's life, and sharing the machinery is how they end up sharing a
 * bug.
 *
 * The token handling is the same and that part is on purpose — 40 random
 * characters, SHA-256 before storage, plaintext returned once. What differs
 * is who may mint one (the pilgrim, not staff) and what a live session then
 * opens.
 */
final class Doorkeeper
{
    private const SESSION_ACCESS = 'family.access';

    private const SESSION_UNTIL = 'family.until';

    /**
     * Mint a link for this booking. Returns the plaintext, once.
     *
     * Called from the Pilgrim Portal, by whoever holds the booking. §6.2
     * puts these controls in the pilgrim's hands, so there is no staff path
     * to this method and adding one would be a change to the promise rather
     * than a convenience.
     */
    public function issue(Booking $booking, ?string $label = null, bool $sharesAttendance = false): string
    {
        $token = Str::random(40);

        FamilyAccess::create([
            'booking_id' => $booking->getKey(),
            'token_hash' => $this->hash($token),
            'label' => $label,
            'shares_attendance' => $sharesAttendance,
            'expires_at' => now()->addDays((int) config('portal.family_link_days', 60)),
        ]);

        return $token;
    }

    /**
     * The access a token names, usable or not.
     *
     * Returns the row even when expired or revoked, so the page can say
     * *why* rather than showing the same blank "not found" for a mistyped
     * link and one the pilgrim turned off.
     */
    public function find(string $token): ?FamilyAccess
    {
        return FamilyAccess::where('token_hash', $this->hash($token))->first();
    }

    /** Spend a link: stamp it, and open a family session. */
    public function admit(FamilyAccess $access): void
    {
        $access->forceFill([
            'uses' => $access->uses + 1,
            'last_used_at' => now(),
        ])->save();

        Session::put(self::SESSION_ACCESS, $access->getKey());
        Session::put(self::SESSION_UNTIL, now()->addHours((int) config('portal.session_hours', 12))->timestamp);
    }

    /**
     * The access this visitor holds, or null.
     *
     * Re-read every request rather than trusted from the session, so a
     * pilgrim who revokes a link closes it on whoever is already looking —
     * which is the point of a control you own.
     */
    public function access(): ?FamilyAccess
    {
        $id = Session::get(self::SESSION_ACCESS);
        $until = Session::get(self::SESSION_UNTIL);

        if ($id === null || $until === null || now()->timestamp > $until) {
            return null;
        }

        $access = FamilyAccess::with('booking.departure.package')->find($id);

        return $access?->isLive() === true ? $access : null;
    }

    public function leave(): void
    {
        Session::forget([self::SESSION_ACCESS, self::SESSION_UNTIL]);
    }

    public function revoke(FamilyAccess $access): void
    {
        $access->forceFill(['revoked_at' => now()])->save();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
