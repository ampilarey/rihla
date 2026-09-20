<?php

namespace App\Services\Booking;

use App\Exceptions\NoSeatsAvailable;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\SeatHold;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\URL;

/**
 * The queue for a full departure, and what happens when a seat comes back.
 *
 * ## An offer holds the seats
 *
 * Marking somebody "next in line" and leaving the seats on sale is a race
 * they lose while staff are still typing a message. So a promotion takes the
 * seats off the departure through {@see SeatAllocator} — the same lock and
 * the same constraint as any other booking — and the entry carries the hold.
 *
 * ## There is no notification channel, and this does not pretend otherwise
 *
 * Nobody has given SMTP credentials, and there is no WhatsApp API here. An
 * offer therefore produces a **signed claim link** and appears in the admin
 * as work: staff copy the link into the WhatsApp conversation they were
 * going to have anyway. Writing a Mailable that quietly posts into the log
 * driver would look finished and reach nobody.
 *
 * The link is signed and expiring rather than guessable, because it grants
 * the seats it names.
 *
 * ## Fairness, stated rather than implied
 *
 * Entries are served oldest first, with one exception: an entry is skipped
 * when the seats that came back cannot fit it, and the next one that fits is
 * offered instead. Holding two seats empty for a party of four who may never
 * answer serves nobody — not the party behind them, and not Rihla.
 */
final class Waitlist
{
    public function __construct(private readonly SeatAllocator $seats) {}

    public function join(Departure $departure, Customer $customer, int $seats, ?string $occupancy = null): WaitlistEntry
    {
        // Already waiting for this departure? Update rather than queue the
        // same person twice — a second entry would be two offers and two
        // sets of held seats for one family.
        $existing = WaitlistEntry::where('departure_id', $departure->getKey())
            ->where('customer_id', $customer->getKey())
            ->whereIn('status', [WaitlistEntry::WAITING, WaitlistEntry::OFFERED])
            ->first();

        if ($existing instanceof WaitlistEntry) {
            $existing->forceFill(['seats' => $seats, 'occupancy' => $occupancy])->save();

            return $existing;
        }

        return WaitlistEntry::create([
            'departure_id' => $departure->getKey(),
            'customer_id' => $customer->getKey(),
            'seats' => $seats,
            'occupancy' => $occupancy,
        ]);
    }

    /**
     * Joining from the public form, where there is no account to identify
     * anybody.
     *
     * Matched on the phone number, because that is what a Maldivian customer
     * actually gives and what staff will ring. Without this, a double
     * submission — an impatient tap, a browser retrying a POST — creates a
     * second customer and a second entry, and the same family is offered
     * seats twice while the party behind them waits.
     *
     * Deliberately narrow: only an entry that is still waiting or offered
     * *on this departure* counts as the same person. Somebody who was
     * expired last month and is trying again is joining afresh.
     */
    public function joinByContact(
        Departure $departure,
        string $name,
        string $phone,
        ?string $email,
        int $seats,
    ): WaitlistEntry {
        $existing = WaitlistEntry::where('departure_id', $departure->getKey())
            ->whereIn('status', [WaitlistEntry::WAITING, WaitlistEntry::OFFERED])
            ->whereHas('customer', fn ($query) => $query->where('phone', $phone))
            ->first();

        if ($existing instanceof WaitlistEntry) {
            $existing->forceFill(['seats' => $seats])->save();

            return $existing;
        }

        return $this->join($departure, Customer::create([
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
        ]), $seats);
    }

    /**
     * Seats came back. Offer them to whoever is waiting.
     *
     * @return int entries offered
     */
    public function offerAvailableSeats(Departure $departure): int
    {
        $this->expireLapsedOffers($departure);

        $offered = 0;

        foreach (WaitlistEntry::where('departure_id', $departure->getKey())->waiting()->oldestFirst()->get() as $entry) {
            try {
                $hold = $this->seats->hold(
                    $departure,
                    $entry->seats,
                    null,
                    now()->addMinutes((int) config('booking.waitlist.offer_minutes', 1440)),
                );
            } catch (NoSeatsAvailable) {
                // Too big for what is left. The next entry may still fit.
                continue;
            }

            $entry->forceFill([
                'status' => WaitlistEntry::OFFERED,
                'seat_hold_id' => $hold->getKey(),
                'offered_at' => now(),
                'offer_expires_at' => $hold->expires_at,
            ])->save();

            $offered++;
        }

        return $offered;
    }

    /**
     * An offer nobody answered is over, and the next person gets a turn.
     *
     * Returning the entry to `waiting` instead would offer it the same seats
     * again the moment they came back, for ever, and nobody behind them
     * would ever be reached.
     */
    public function expireLapsedOffers(?Departure $departure = null): int
    {
        $query = WaitlistEntry::offered()->with('seatHold');

        if ($departure instanceof Departure) {
            $query->where('departure_id', $departure->getKey());
        }

        $expired = 0;

        foreach ($query->get() as $entry) {
            if ($entry->offerIsLive()) {
                continue;
            }

            $entry->forceFill(['status' => WaitlistEntry::EXPIRED])->save();
            $expired++;
        }

        return $expired;
    }

    /** They booked. The hold becomes the booking's. */
    public function convert(WaitlistEntry $entry, int $bookingId): void
    {
        $entry->forceFill([
            'status' => WaitlistEntry::CONVERTED,
            'booking_id' => $bookingId,
            'converted_at' => now(),
        ])->save();
    }

    public function cancel(WaitlistEntry $entry, string $reason = SeatHold::CANCELLED): void
    {
        if ($entry->seatHold instanceof SeatHold && $entry->seatHold->isLive()) {
            $this->seats->release($entry->seatHold, $reason);
        }

        $entry->forceFill([
            'status' => WaitlistEntry::CANCELLED,
            'cancelled_at' => now(),
        ])->save();
    }

    /**
     * The link staff paste into WhatsApp.
     *
     * Signed and expiring with the offer: it hands over seats that are
     * already held, so a guessable URL would let anybody take somebody
     * else's.
     */
    public function claimUrl(WaitlistEntry $entry): ?string
    {
        if (! $entry->offerIsLive()) {
            return null;
        }

        return URL::temporarySignedRoute(
            'waitlist.claim',
            $entry->offer_expires_at,
            ['locale' => app()->getLocale(), 'entry' => $entry->getKey()],
        );
    }
}
