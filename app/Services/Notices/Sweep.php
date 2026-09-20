<?php

namespace App\Services\Notices;

use App\Models\Booking;
use App\Models\Notice;
use App\Support\TravelReadiness;

/**
 * Turning records into things somebody needs to be told — §11.2's list.
 *
 * ## It observes, it does not invent
 *
 * Every notice here is raised from a record that already exists: a booking
 * that became confirmed, a passport that is missing, a visa that was
 * issued, a departure date getting close. There is no notice for anything
 * this system cannot see, which is what stops it manufacturing news — the
 * failure that put invented social links on the live site.
 *
 * ## A sweep, not an event listener
 *
 * Deliberately. ADR 0002 rules out a queue worker on this host, so an
 * event-driven fan-out would run inside a customer's web request and fail
 * silently when it went wrong. A sweep is a cron line, is idempotent, and
 * can be run by hand when somebody wants to know what is outstanding right
 * now.
 *
 * It is idempotent because {@see Notice::raise()} refuses to stack a second
 * outstanding notice of the same kind. Running it hourly and running it
 * once a day produce the same portal.
 */
final class Sweep
{
    /**
     * How many days before departure somebody should be reminded.
     *
     * Configuration because it is an operational choice. Two weeks is the
     * default: long enough to fix a passport, short enough to be believed.
     */
    private function remindDaysBefore(): int
    {
        return (int) config('notices.remind_days_before', 14);
    }

    /**
     * Look at every live booking and raise what is outstanding.
     *
     * @return array<string, int> how many of each kind were raised
     */
    public function run(): array
    {
        $raised = array_fill_keys(Notice::KINDS, 0);

        $bookings = Booking::query()
            ->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])
            ->whereHas('departure', fn ($query) => $query->where('date_end', '>=', now()->startOfDay()))
            ->with(['departure.package', 'travellers.traveller', 'customer'])
            ->get();

        foreach ($bookings as $booking) {
            foreach ($this->noticesFor($booking) as $kind => $notice) {
                if (Notice::raise($booking, $kind, $notice['headline'], $notice['body']) !== null) {
                    $raised[$kind]++;
                }
            }
        }

        return $raised;
    }

    /**
     * What is outstanding on one booking.
     *
     * @return array<string, array{headline: string, body: ?string}>
     */
    public function noticesFor(Booking $booking): array
    {
        $notices = [];

        $blockers = TravelReadiness::blockers($booking);

        if ($blockers !== []) {
            // Counted by requirement, not by person, for the same reason
            // the departure board does it: "two of you are not ready" is a
            // fact nobody can act on, and "we need two passports" is a
            // thing to do this afternoon.
            $outstanding = [];

            foreach ($blockers as $unmet) {
                foreach ($unmet as $requirement) {
                    $outstanding[$requirement] = ($outstanding[$requirement] ?? 0) + 1;
                }
            }

            if (isset($outstanding[TravelReadiness::PASSPORT])) {
                $notices[Notice::DOCUMENT_NEEDED] = [
                    'headline' => 'We still need a passport',
                    'body' => $this->passportSentence($outstanding[TravelReadiness::PASSPORT]),
                ];
            }
        }

        $daysAway = (int) now()->startOfDay()->diffInDays($booking->departure->date_start->startOfDay(), false);

        if ($daysAway >= 0 && $daysAway <= $this->remindDaysBefore()) {
            $notices[Notice::DEPARTURE_SOON] = [
                'headline' => $daysAway === 0
                    ? 'You travel today'
                    : ($daysAway === 1 ? 'You travel tomorrow' : "You travel in {$daysAway} days"),
                'body' => 'Departure is '.$booking->departure->date_start->format('j F Y').'.',
            ];
        }

        return $notices;
    }

    /**
     * Whole sentences rather than a string built from a count and a noun,
     * so the plural is right and nothing has to be translated by
     * concatenation.
     */
    private function passportSentence(int $count): string
    {
        return $count === 1
            ? 'One traveller on your booking has no usable passport on file yet. Send us a photo of the picture page through the portal.'
            : $count.' travellers on your booking have no usable passport on file yet. Send us a photo of each picture page through the portal.';
    }
}
