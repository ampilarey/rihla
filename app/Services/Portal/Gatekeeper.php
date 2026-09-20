<?php

namespace App\Services\Portal;

use App\Models\Booking;
use App\Models\PortalAccess;
use App\Models\User;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Issuing, checking and revoking the way into the Pilgrim Portal.
 *
 * ## The token is a secret, and is treated like one
 *
 * 40 random characters from `Str::random()`, which is
 * cryptographically secure, hashed with SHA-256 before storage. The
 * plaintext is returned once, to the member of staff who pressed the button,
 * and never again. A system that can show you the link a second time can be
 * made to show it to somebody else.
 *
 * SHA-256 rather than bcrypt deliberately: this is a 40-character random
 * string, not a human password, so there is nothing to brute-force and
 * nothing for a work factor to slow down — while a bcrypt lookup would mean
 * reading every row and comparing each one, instead of a single indexed
 * read.
 *
 * ## Using a link starts a session; the link is not re-sent on every page
 *
 * Otherwise the token would sit in the address bar of every screenshot, in
 * the browser history of a shared phone, and in the Referer header of every
 * outbound click. The link is spent once and swapped for a session that
 * expires on its own.
 */
final class Gatekeeper
{
    private const SESSION_BOOKING = 'portal.booking';

    private const SESSION_UNTIL = 'portal.until';

    /**
     * Mint a link for this booking.
     *
     * Returns the plaintext token. The caller builds the URL and hands it to
     * whoever is going to send it.
     */
    public function issue(Booking $booking, ?User $actor = null): string
    {
        $token = Str::random(40);

        PortalAccess::create([
            'booking_id' => $booking->getKey(),
            'token_hash' => $this->hash($token),
            'expires_at' => now()->addDays((int) config('portal.link_days', 30)),
            'issued_by' => ($actor ?? auth()->user())?->getKey(),
        ]);

        return $token;
    }

    /**
     * The access a token names, whether or not it is still usable.
     *
     * Returns the row even when expired or revoked, so the page can say
     * *why* rather than showing the same blank "not found" for a mistyped
     * link and a cancelled one.
     */
    public function find(string $token): ?PortalAccess
    {
        return PortalAccess::where('token_hash', $this->hash($token))->first();
    }

    /** Spend a link: stamp it, and open a session for the booking it names. */
    public function admit(PortalAccess $access, ?string $ip = null): void
    {
        $access->forceFill([
            'uses' => $access->uses + 1,
            'last_used_at' => now(),
            'first_used_ip' => $access->first_used_ip ?? $ip,
        ])->save();

        Session::put(self::SESSION_BOOKING, $access->booking_id);
        Session::put(self::SESSION_UNTIL, now()->addHours((int) config('portal.session_hours', 12))->timestamp);
    }

    /**
     * The booking this visitor may see, or null.
     *
     * Checked on every portal request. The expiry is held in the session
     * rather than left to the session cookie's own lifetime, because that is
     * shared with the ordinary site and is measured in weeks.
     */
    public function booking(): ?Booking
    {
        $id = Session::get(self::SESSION_BOOKING);
        $until = Session::get(self::SESSION_UNTIL);

        if ($id === null || $until === null || now()->timestamp > $until) {
            return null;
        }

        return Booking::with(['departure.package', 'travellers.traveller', 'customer'])->find($id);
    }

    public function leave(): void
    {
        Session::forget([self::SESSION_BOOKING, self::SESSION_UNTIL]);
    }

    /** Immediately, without waiting for the link to lapse. */
    public function revoke(PortalAccess $access): void
    {
        $access->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Every live link for a booking, killed at once.
     *
     * What staff actually want when a link went to the wrong number: they
     * do not know which of the three they issued was the one.
     */
    public function revokeAllFor(Booking $booking): int
    {
        return PortalAccess::where('booking_id', $booking->getKey())
            ->live()
            ->update(['revoked_at' => now()]);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
