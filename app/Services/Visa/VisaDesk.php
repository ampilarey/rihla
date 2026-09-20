<?php

namespace App\Services\Visa;

use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Traveller;
use App\Models\VisaApplication;
use Illuminate\Support\Collection;

/**
 * Opening visa applications, and re-opening them after a refusal.
 *
 * Nothing here touches Nusuk permits [R-4]. They are a separate
 * authorisation with separate failure modes, and the point of keeping them
 * apart is that a traveller can hold a valid visa and still be barred from
 * the Rawdah — a state one combined workflow cannot express.
 */
final class VisaDesk
{
    /**
     * The application somebody is working on, creating it if there is none.
     *
     * Idempotent per traveller per booking while one is open: calling this
     * twice does not queue two applications for the same person, which would
     * be two submissions to a government for one traveller.
     */
    public function open(Booking $booking, Traveller $traveller, ?string $visaType = null): VisaApplication
    {
        $existing = VisaApplication::forBooking($booking)
            ->where('traveller_id', $traveller->getKey())
            ->orderByDesc('attempt')
            ->first();

        if ($existing instanceof VisaApplication && ! $existing->isClosed()) {
            return $existing;
        }

        // A previous attempt that ended — refused, cancelled, or issued and
        // now being applied for again — means this one is the next attempt,
        // not the first.
        return VisaApplication::create([
            'booking_id' => $booking->getKey(),
            'traveller_id' => $traveller->getKey(),
            'attempt' => ($existing?->attempt ?? 0) + 1,
            'visa_type' => $visaType ?? $existing?->visa_type,
            'assigned_to' => $existing?->assigned_to,
        ]);
    }

    /**
     * Everybody on the booking who does not have an open application.
     *
     * The ordinary way a booking enters the visa workflow: one row per
     * traveller, because a visa is granted to a person and not to a party.
     *
     * @return Collection<int, VisaApplication>
     */
    public function openForBooking(Booking $booking, ?string $visaType = null): Collection
    {
        return $booking->travellers()
            ->with('traveller')
            ->get()
            ->map(fn (BookingTraveller $line): VisaApplication => $this->open($booking, $line->traveller, $visaType));
    }

    /**
     * Try again after a refusal.
     *
     * A new row, never an edit of the rejected one. The refusal is a fact
     * about a particular submission — its date, its reference, its stated
     * reason — and reopening the record to try again destroys the only
     * evidence of what was actually sent. §5.4a calls re-application a
     * first-class path; this is what that means in the schema.
     */
    public function reapply(VisaApplication $rejected): VisaApplication
    {
        return VisaApplication::create([
            'booking_id' => $rejected->booking_id,
            'traveller_id' => $rejected->traveller_id,
            'attempt' => $rejected->attempt + 1,
            // Carried over, because the second attempt is usually the same
            // application with a corrected document.
            'visa_type' => $rejected->visa_type,
            'assigned_to' => $rejected->assigned_to,
        ]);
    }

    /**
     * Applications nobody has moved inside their service level.
     *
     * Computed rather than flagged: §5.4a wants an alert when a stage
     * stalls, and a stored "overdue" column is wrong the moment the clock
     * passes it.
     *
     * @return Collection<int, VisaApplication>
     */
    public function stalled(): Collection
    {
        return VisaApplication::open()->with(['traveller', 'booking'])->get()
            ->filter(fn (VisaApplication $application): bool => $application->isStalled())
            ->values();
    }
}
