<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Enquiry;
use Illuminate\Support\Collection;

/**
 * Who has been quiet long enough to be worth a phone call — §8.1's
 * post-Umrah re-engagement.
 *
 * ## Computed, and deliberately not a campaign
 *
 * This produces a list of names with a reason beside each one. It does not
 * send anything, schedule anything or score anybody. There is no email on
 * this host (§11.2) and no WhatsApp account, so a "campaign" here would be
 * a button that silently does nothing — and even with both, a list a human
 * works through is the right shape for forty households.
 *
 * ## The two exclusions are the whole design
 *
 * Somebody with an **open enquiry** is already being spoken to; ringing
 * them again as a cold lead is how an operator looks disorganised.
 * Somebody with an **upcoming departure** has not gone quiet — they are
 * about to fly, and the last thing they need is a "we miss you" call.
 *
 * Both are checked against live records rather than a flag, so nobody has
 * to remember to take a person off a list.
 */
final class Reengagement
{
    /** The default silence before somebody is worth a call. */
    public const DEFAULT_MONTHS = 12;

    /**
     * Customers who travelled, have gone quiet, and are not already in a
     * conversation.
     *
     * @return Collection<int, array{customer: Customer, last_journey: Departure, months: int}>
     */
    public static function candidates(int $months = self::DEFAULT_MONTHS): Collection
    {
        $cutoff = now()->subMonths($months)->toDateString();

        // Already being spoken to, or about to fly. Both are live reads:
        // nobody has to remember to take a person off a list.
        $inConversation = Enquiry::open()->whereNotNull('customer_id')->pluck('customer_id')->unique();

        $flyingSoon = Booking::query()
            ->active()
            ->whereIn(
                'departure_id',
                Departure::query()->whereDate('date_start', '>=', now()->toDateString())->select('id'),
            )
            ->pluck('customer_id')
            ->unique();

        return Customer::query()
            ->whereNotIn('id', $inConversation->merge($flyingSoon)->all())
            ->with('tags')
            ->get()
            ->map(function (Customer $customer) use ($cutoff): ?array {
                $last = $customer->lastDeparted();

                // No `date_start === null` check: the column is not
                // nullable, and `lastDeparted()` already filters on it.
                if ($last === null) {
                    return null;
                }

                if ($last->date_start->toDateString() > $cutoff) {
                    return null;
                }

                return [
                    'customer' => $customer,
                    'last_journey' => $last,
                    'months' => (int) $last->date_start->diffInMonths(now()),
                ];
            })
            ->filter()
            ->sortByDesc('months')
            ->values();
    }
}
