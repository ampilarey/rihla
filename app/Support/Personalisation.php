<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * What the site may honestly say to the person reading it — §4.2's
 * "personalisation for returning users".
 *
 * ## It only personalises for somebody who told us who they are
 *
 * There is exactly one input: a signed-in customer with bookings. No
 * cookie, no fingerprint, no behavioural profile, no "people like you also
 * viewed".
 *
 * That is a decision, not an oversight, and it is the same finding §10.5's
 * dashboard already records: **nothing here logs a visit.** Building a
 * visit log in order to recommend things from it would be building the
 * surveillance first and asking whether anybody wanted it afterwards — and
 * for an operator whose whole differentiator is being trusted about
 * religion, that is a bad trade at any conversion rate.
 *
 * {@see whyNothingPersonal()} says so on the screen rather than leaving an
 * anonymous visitor to wonder why the site does not know them.
 *
 * ## It recommends nothing, and that turned out to be the honest answer
 *
 * The first version of this offered "the journeys on sale you have not been
 * on". Rendered on the homepage, that list was **the same three departures
 * the page already shows underneath it** — because a returning pilgrim with
 * one past journey has not been on any of the current ones. A
 * "recommended for you" panel over the identical list below it is the
 * arithmetic-dressed-as-insight this class was written to avoid, so it was
 * taken out after reading the page rather than left in because it had been
 * built.
 *
 * What is left is the part that is actually personal and actually true:
 * **who they are to Rihla**, and the rule that somebody about to fly is not
 * somebody to sell to.
 *
 * ## Computed, never stored
 *
 * The same reasoning as every other read model here.
 */
final class Personalisation
{
    private function __construct(
        public readonly ?Customer $customer,
        /** Journeys actually taken, on {@see Customer::TRAVELLED_ON}'s definition. */
        public readonly int $journeysTaken,
        public readonly ?Carbon $lastTravelled,
        /** A booking already made and not yet flown, if there is one. */
        public readonly ?Booking $upcoming,
    ) {}

    public static function for(?User $user): self
    {
        $nobody = fn (): self => new self(null, 0, null, null);

        if ($user === null) {
            return $nobody();
        }

        $customer = Customer::query()->where('user_id', $user->getKey())->first();

        if ($customer === null) {
            return $nobody();
        }

        /** @var EloquentCollection<int, Booking> $bookings */
        $bookings = Booking::query()
            ->where('customer_id', $customer->getKey())
            ->whereIn('status', Customer::TRAVELLED_ON)
            ->with('departure')
            ->get();

        $today = Carbon::now()->startOfDay();

        $flown = $bookings->filter(
            fn (Booking $booking): bool => $booking->departure !== null
                && $booking->departure->date_start->lt($today),
        );

        $upcoming = $bookings
            ->filter(fn (Booking $booking): bool => $booking->departure !== null
                && $booking->departure->date_start->gte($today))
            ->sortBy(fn (Booking $booking): string => $booking->departure->date_start->toDateString())
            ->first();

        return new self(
            customer: $customer,
            journeysTaken: $flown->count(),
            lastTravelled: $flown
                ->map(fn (Booking $booking): Carbon => Carbon::parse($booking->departure->date_start->toDateString()))
                ->sortDesc()
                ->first(),
            upcoming: $upcoming,
        );
    }

    /** Whether there is anything at all to say to this reader. */
    public function hasSomethingToSay(): bool
    {
        return $this->customer !== null
            && ($this->journeysTaken > 0 || $this->upcoming !== null);
    }

    /**
     * The line at the top, or null when there is nothing honest to put there.
     *
     * Deliberately plain. "Welcome back, Ahmed! We've missed you!" from a
     * company somebody trusted with their Umrah reads as a marketing
     * database, not as a person who remembers them.
     */
    public function greeting(): ?string
    {
        if ($this->customer === null) {
            return null;
        }

        $name = trim((string) $this->customer->name);
        $first = $name === '' ? null : explode(' ', $name)[0];

        if ($this->upcoming !== null) {
            return $first === null
                ? 'Your journey is booked.'
                : 'Welcome back, '.$first.'. Your journey is booked.';
        }

        if ($this->journeysTaken < 1) {
            return null;
        }

        return ($first === null ? 'Welcome back.' : 'Welcome back, '.$first.'.')
            .' You travelled with us '
            .($this->journeysTaken === 1 ? 'once' : $this->journeysTaken.' times')
            .($this->lastTravelled !== null ? ', most recently in '.$this->lastTravelled->format('F Y') : '')
            .'.';
    }

    /**
     * What to do next, when they already have a booking.
     *
     * Somebody with a departure coming is not somebody to sell to. The
     * useful thing is their own portal, and offering them another package
     * instead is how a travel company reads as a shop rather than as the
     * people taking them.
     */
    public function shouldSellAnything(): bool
    {
        return $this->upcoming === null;
    }

    /**
     * Why an anonymous visitor sees nothing personal.
     *
     * On the page, not only in this docblock: a visitor who has been to
     * six other travel sites this week has been followed round all six,
     * and the absence here is worth one sentence.
     */
    public function whyNothingPersonal(): string
    {
        return 'This site does not follow you around. Nothing here records what you looked at, and nothing is '
            .'recommended from a profile of you — if you sign in, it knows what you booked with us and nothing else.';
    }
}
