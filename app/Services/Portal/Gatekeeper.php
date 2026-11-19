<?php

namespace App\Services\Portal;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\PortalAccess;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
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
    /** A real hash of nothing, made once, so a miss costs what a hit does. */
    private static ?string $dummyHash = null;

    public const SESSION_BOOKING = 'portal.booking';

    private const SESSION_UNTIL = 'portal.until';

    private const SESSION_OPENED = 'portal.opened';

    /**
     * Set only by signing in with a password (companies plan, Phase 3h). A
     * link opens one booking; a password opens the customer, and every
     * booking of theirs.
     */
    public const SESSION_CUSTOMER = 'portal.customer';

    /** The bookings a signed-in customer is shown, newest first. */
    public const LISTED = [Booking::HELD, Booking::CONFIRMED, Booking::COMPLETED];

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

        // A new session id on the way in, so an id planted before the link
        // was clicked is not the one that gets the booking (security review).
        Session::regenerate();
        Session::put(self::SESSION_BOOKING, $access->booking_id);
        Session::put(self::SESSION_UNTIL, now()->addHours((int) config('portal.session_hours', 12))->timestamp);
        Session::put(self::SESSION_OPENED, now()->timestamp);
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

        // Signed in with a password: the session is the customer's, and ends
        // when the password is cleared or changed, wherever it was opened.
        if (Session::has(self::SESSION_CUSTOMER)) {
            $customer = $this->signedInCustomer();
            $booking = $customer === null ? null : Booking::with(['departure.package', 'travellers.traveller', 'customer'])
                ->where('customer_id', $customer->getKey())
                ->find($id);

            if ($booking === null) {
                $this->leave();
            }

            return $booking;
        }

        // Revoking a link ends the sessions it opened, not only the next
        // click (security review, §16). Sessions from before this was
        // recorded date from the start of their window.
        $opened = (int) (Session::get(self::SESSION_OPENED) ?? $until - (int) config('portal.session_hours', 12) * 3600);

        if (PortalAccess::where('booking_id', $id)->where('revoked_at', '>=', Carbon::createFromTimestamp($opened))->exists()) {
            $this->leave();

            return null;
        }

        return Booking::with(['departure.package', 'travellers.traveller', 'customer'])->find($id);
    }

    // ── The customer portal with a password (companies plan, Phase 3h) ──

    /**
     * Choose a password for the customer whose booking this link session
     * opened. Only when none is set — the first one chosen stands, so a link
     * forwarded into a family chat cannot be used to change it — and only
     * by somebody who can also type the mobile number on file. The office
     * clears a password that went wrong ({@see clearPassword()}).
     */
    public function choosePassword(Booking $booking, string $phone, string $password): bool
    {
        $customer = $booking->customer;

        if ($customer->hasPortalPassword() || PhoneNumber::key($phone) === null || PhoneNumber::key($phone) !== PhoneNumber::key($customer->phone)) {
            return false;
        }

        $customer->forceFill(['portal_password' => Hash::make($password), 'portal_password_set_at' => now()])->save();
        $this->admitCustomer($customer, $booking);

        return true;
    }

    /**
     * The customer this mobile number and password open, or null. Every way
     * of being wrong answers the same, so the form cannot be used to learn
     * whose number is on file. Looks only within the company being served.
     */
    public function attempt(string $phone, string $password): ?Customer
    {
        $key = PhoneNumber::key($phone);

        if ($key === null) {
            Hash::check($password, self::$dummyHash ??= Hash::make(Str::random(32)));

            return null;
        }

        $matches = Customer::query()
            ->whereNotNull('portal_password')
            ->get(['id', 'phone', 'portal_password'])
            ->filter(fn (Customer $customer): bool => PhoneNumber::key($customer->phone) === $key);

        foreach ($matches as $customer) {
            if (Hash::check($password, (string) $customer->portal_password)) {
                return Customer::query()->find($customer->getKey());
            }
        }

        // The same work whether or not the number exists.
        if ($matches->isEmpty()) {
            Hash::check($password, self::$dummyHash ??= Hash::make(Str::random(32)));
        }

        return null;
    }

    /** Open a session for the customer, on the booking given or their latest. */
    public function admitCustomer(Customer $customer, ?Booking $booking = null): void
    {
        $booking ??= $this->bookingsOf($customer)->first();

        Session::regenerate();
        Session::put(self::SESSION_CUSTOMER, $customer->getKey());
        Session::put(self::SESSION_BOOKING, $booking?->getKey());
        Session::put(self::SESSION_UNTIL, now()->addHours((int) config('portal.session_hours', 12))->timestamp);
        Session::put(self::SESSION_OPENED, now()->timestamp);

        $customer->forceFill(['portal_last_login_at' => now()])->saveQuietly();
    }

    /** The customer signed in with a password, or null for a link session or none. */
    public function signedInCustomer(): ?Customer
    {
        $id = Session::get(self::SESSION_CUSTOMER);
        $until = Session::get(self::SESSION_UNTIL);

        if ($id === null || $until === null || now()->timestamp > $until) {
            return null;
        }

        $customer = Customer::query()->find($id);
        $opened = (int) Session::get(self::SESSION_OPENED);

        if ($customer === null || ! $customer->hasPortalPassword()
            || ($customer->portal_password_set_at !== null && $customer->portal_password_set_at->timestamp > $opened)) {
            return null;
        }

        return $customer;
    }

    /** Switch to another of the signed-in customer's bookings. */
    public function choose(Booking $booking): bool
    {
        $customer = $this->signedInCustomer();

        if ($customer === null || (int) $booking->customer_id !== (int) $customer->getKey()) {
            return false;
        }

        Session::put(self::SESSION_BOOKING, $booking->getKey());

        return true;
    }

    /** @return Collection<int, Booking> */
    public function bookingsOf(Customer $customer): Collection
    {
        return Booking::query()
            ->where('customer_id', $customer->getKey())
            ->whereIn('status', self::LISTED)
            ->with('departure.package')
            ->latest('id')
            ->get();
    }

    /** The office's reset: the password goes, and every session it opened with it. */
    public function clearPassword(Customer $customer): void
    {
        $customer->forceFill(['portal_password' => null, 'portal_password_set_at' => now()])->save();
    }

    public function leave(): void
    {
        Session::forget([self::SESSION_BOOKING, self::SESSION_UNTIL, self::SESSION_OPENED, self::SESSION_CUSTOMER]);
        // The old id is destroyed, not merely emptied (security review).
        Session::regenerate(true);
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
