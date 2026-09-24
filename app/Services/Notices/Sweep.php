<?php

namespace App\Services\Notices;

use App\Models\Booking;
use App\Models\Notice;
use App\Models\Stay;
use App\Services\Stays\StayBooking;
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
     * The stays booking service, for the one question this class must not
     * answer itself: when the balance falls due. It is read from the
     * snapshot taken on the day, and there is exactly one implementation
     * of that rule.
     */
    public function __construct(private readonly StayBooking $stays) {}

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

        // The stays half — §15.7. Live means held or confirmed and not yet
        // behind us: a stay that was declined, cancelled or lapsed has
        // nothing outstanding, and a follow-up for the two Rihla caused is
        // the CRM's job rather than this one.
        $stays = Stay::query()
            ->whereIn('status', [Stay::HELD, Stay::CONFIRMED])
            ->where('check_out', '>=', now()->startOfDay())
            ->with(['property', 'customer', 'roomType'])
            ->get();

        foreach ($stays as $stay) {
            foreach ($this->noticesForStay($stay) as $kind => $notice) {
                if (Notice::raise($stay, $kind, $notice['headline'], $notice['body']) !== null) {
                    $raised[$kind]++;
                }
            }
        }

        return $raised;
    }

    /**
     * What is outstanding on one stay — §15.7.
     *
     * The same rule as the booking half: every one of these is read off a
     * record that already exists — a status the partner set, a deposit
     * that has not arrived, a date on the calendar. Nothing here invents
     * news, and nothing here is sent anywhere: a notice is a row the
     * customer sees and a line on the staff queue with the WhatsApp
     * conversation already written, because there is no SMTP on this host
     * and no WhatsApp API behind it.
     *
     * ## The two states are not what their names suggest
     *
     * `held` is *the partner has said yes and the deposit is outstanding*
     * — `confirmWithPartner()` puts a stay there, with the hold clock
     * running. `confirmed` is *the deposit arrived*. So the good news and
     * the money owed are the same moment, and it gets one notice rather
     * than two: a queue that says the same thing twice is one people stop
     * reading.
     *
     * @return array<string, array{headline: string, body: ?string}>
     */
    public function noticesForStay(Stay $stay): array
    {
        $notices = [];
        $house = $stay->property?->getTranslation('name', 'en') ?: 'the guesthouse';

        // Held: the guesthouse agreed, and a clock is running. This is the
        // one state on this list with a deadline behind it — a hold that
        // lapses puts the room back on sale, and the customer finds out by
        // opening a page that no longer offers it.
        if ($stay->status === Stay::HELD && ! $stay->depositIsPaid()) {
            $notices[Notice::DEPOSIT_DUE] = [
                'headline' => $house.' has your rooms — the deposit holds them',
                'body' => $this->holdSentence($stay),
            ];
        }

        if ($stay->status === Stay::CONFIRMED) {
            $notices[Notice::STAY_CONFIRMED] = [
                'headline' => $house.' is booked',
                'body' => sprintf(
                    '%s to %s, %s. Reference %s.',
                    $stay->check_in->format('j F Y'),
                    $stay->check_out->format('j F Y'),
                    $stay->roomType?->getTranslation('name', 'en') ?: 'your rooms',
                    $stay->reference,
                ),
            ];

            // The balance, once it is actually due. Not before: a bill
            // three months early is a notice people learn to scroll past.
            //
            // The due date comes from {@see StayBooking::balanceDueAt()},
            // which reads the **snapshot** taken when the customer booked
            // rather than the property's current terms. A guesthouse that
            // has since changed its policy has not changed what this
            // customer agreed to, and chasing them to a date they never
            // saw is the same defect as quoting a price they were never
            // offered.
            if ($stay->outstanding()->minor > 0) {
                $dueOn = $this->stays->balanceDueAt($stay);

                if (now()->greaterThanOrEqualTo($dueOn)) {
                    $notices[Notice::BALANCE_DUE] = [
                        'headline' => 'The balance for '.$house.' is due',
                        'body' => sprintf(
                            '%s remains on %s, due %s.',
                            $stay->outstanding()->format(),
                            $stay->reference,
                            $dueOn->format('j F Y'),
                        ),
                    ];
                }
            }

            $daysAway = (int) now()->startOfDay()->diffInDays($stay->check_in->startOfDay(), false);

            if ($daysAway >= 0 && $daysAway <= $this->remindDaysBefore()) {
                $notices[Notice::CHECK_IN_SOON] = [
                    'headline' => $daysAway === 0
                        ? 'You check in today'
                        : ($daysAway === 1 ? 'You check in tomorrow' : "You check in in {$daysAway} days"),
                    'body' => $this->arrivalSentence($stay),
                ];
            }
        }

        return $notices;
    }

    /**
     * How the property says to get in, when it has said.
     *
     * The instructions are the useful half of a reminder about a
     * guesthouse on an island somebody has never been to — which ferry,
     * which jetty, who to ask for. Falls back to the dates alone rather
     * than inventing directions.
     */
    private function arrivalSentence(Stay $stay): string
    {
        $when = 'Check-in is '.$stay->check_in->format('j F Y');
        $time = $stay->property?->check_in_time;

        $when .= $time ? ' from '.substr((string) $time, 0, 5).'.' : '.';

        $directions = $stay->property?->getTranslation('check_in_instructions', app()->getLocale());

        return filled($directions) ? $when.' '.$directions : $when;
    }

    /**
     * What is owed and by when, in as many words.
     *
     * A hold with no expiry is not a countdown, so it is not described as
     * one — the sentence says what is owed and stops, rather than naming a
     * deadline that does not exist.
     */
    private function holdSentence(Stay $stay): string
    {
        $owed = sprintf(
            '%s holds %s from %s.',
            $stay->deposit()->format(),
            $stay->roomType?->getTranslation('name', 'en') ?: 'your rooms',
            $stay->check_in->format('j F Y'),
        );

        if ($stay->expires_at === null) {
            return $owed;
        }

        return $owed.' The hold runs out on '.$stay->expires_at->format('j F Y \a\t H:i').'.';
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
