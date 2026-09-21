<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\CrmTask;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Who sends Rihla people — the other half of §8.1's referral tracking.
 *
 * ## Why this exists
 *
 * {@see Customer::referrer()} has carried this since Phase 5.5, and its
 * own docblock says why a referral points at a customer already on file
 * rather than a name in a box: *"the point of tracking a referral is to be
 * able to thank the person who made it."*
 *
 * Nothing delivered that. A referral could be entered on the customer
 * form and filtered for in the table, and then it sat there. This is the
 * screen the docblock promised.
 *
 * ## It does not claim to know whether anybody thanked them
 *
 * A thank-you is a telephone call, and nothing here can see one. What it
 * can see is whether anybody **wrote anything down** about the referrer
 * since their last referral travelled — an ordinary follow-up task, the
 * machinery the CRM already has.
 *
 * Storing a `thanked_at` would mean somebody ticking a box, and a ticked
 * box is evidence that a box was ticked. So the column says "last
 * follow-up recorded" and means exactly that, and the screen repeats the
 * distinction rather than leaving a reader to assume the stronger claim.
 *
 * ## Computed, never stored
 *
 * The same reasoning as every other read model here. There is no
 * `referral_credits` table and no nightly job — there is no queue worker
 * (ADR 0002) — and a stored count is wrong from the next booking.
 */
final class Referrals
{
    /**
     * Everybody who has referred at least one person, most owed first.
     *
     * Ordered by what the office should do next rather than by size: an
     * unacknowledged referral comes before a larger one somebody has
     * already picked up.
     *
     * @return Collection<int, ReferralCredit>
     */
    public static function build(): Collection
    {
        /** @var EloquentCollection<int, Customer> $referred */
        $referred = Customer::query()
            ->whereNotNull('referred_by_customer_id')
            ->get(['id', 'referred_by_customer_id']);

        if ($referred->isEmpty()) {
            return collect();
        }

        $referrerIds = $referred->pluck('referred_by_customer_id')->unique()->filter()->values();

        /** @var EloquentCollection<int, Customer> $referrers */
        $referrers = Customer::query()->whereIn('id', $referrerIds->all())->get();

        $journeys = self::journeysByCustomer($referred->modelKeys());
        $lastNoted = self::lastTaskByCustomer($referrerIds->all());

        return $referrers
            ->map(function (Customer $referrer) use ($referred, $journeys, $lastNoted): ReferralCredit {
                $theirs = $referred
                    ->where('referred_by_customer_id', $referrer->getKey())
                    ->modelKeys();

                $flown = collect($theirs)
                    ->map(fn (int|string $id): ?array => $journeys[$id] ?? null)
                    ->filter();

                return new ReferralCredit(
                    referrer: $referrer,
                    referred: count($theirs),
                    travelled: $flown->count(),
                    seats: (int) $flown->sum(fn (array $journey): int => $journey['seats']),
                    lastArrival: $flown
                        ->map(fn (array $journey): Carbon => $journey['arrived'])
                        ->sortDesc()
                        ->first(),
                    lastNoted: $lastNoted[$referrer->getKey()] ?? null,
                );
            })
            // Two passes, not `sortBy([fn, fn])`. In that multi-sort form
            // Laravel treats each callable as a two-argument *comparator*,
            // so a one-argument accessor silently becomes a comparator that
            // returns 0 or 1 and never -1 — the secondary sort then does
            // nothing at all. PHP 8 sorts are stable, so sorting by the
            // weaker key first and the stronger key second is both correct
            // and readable.
            ->sortByDesc(fn (ReferralCredit $credit): int => $credit->seats)
            ->sortBy(fn (ReferralCredit $credit): int => $credit->isUnacknowledged() ? 0 : 1)
            ->values();
    }

    /**
     * The ones nobody has written anything about since they last sent
     * somebody who then travelled.
     *
     * @return Collection<int, ReferralCredit>
     */
    public static function unacknowledged(): Collection
    {
        return self::build()
            ->filter(fn (ReferralCredit $credit): bool => $credit->isUnacknowledged())
            ->values();
    }

    /**
     * What one customer's referrals came to, or null if they made none.
     *
     * For the customer dossier, which is where somebody looking at a
     * person actually wants this.
     */
    public static function forCustomer(Customer $customer): ?ReferralCredit
    {
        return self::build()->first(
            fn (ReferralCredit $credit): bool => $credit->referrer->getKey() === $customer->getKey(),
        );
    }

    /**
     * Journeys actually taken, per referred customer.
     *
     * Only the most recent counts as the arrival: a person who came twice
     * is one referral that worked, and the question the screen answers is
     * "has anybody said anything since the last one".
     *
     * @param  list<int|string>  $customerIds
     * @return array<int|string, array{seats: int, arrived: Carbon}>
     */
    private static function journeysByCustomer(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $out = [];

        $bookings = Booking::query()
            ->whereIn('customer_id', $customerIds)
            ->whereIn('status', Customer::TRAVELLED_ON)
            ->with('departure')
            ->get(['id', 'customer_id', 'departure_id', 'seats']);

        $today = Carbon::now()->startOfDay();

        foreach ($bookings as $booking) {
            $departure = $booking->departure;

            // A booking on a departure that has not flown is not yet a
            // favour returned — it is a favour in progress, and thanking
            // somebody for it before it happens is premature.
            if ($departure === null || $departure->date_start->gte($today)) {
                continue;
            }

            $arrived = Carbon::parse($departure->date_start->toDateString());
            $id = $booking->customer_id;

            $out[$id] = [
                'seats' => (int) ($out[$id]['seats'] ?? 0) + (int) $booking->seats,
                'arrived' => isset($out[$id]) && $out[$id]['arrived']->gt($arrived)
                    ? $out[$id]['arrived']
                    : $arrived,
            ];
        }

        return $out;
    }

    /**
     * When anybody last recorded a follow-up task about each referrer.
     *
     * Any task, not a "thank-you" task: there is no such kind, on purpose.
     * Marking one would mean a column somebody sets, and then the screen
     * would report ticked boxes rather than work done.
     *
     * @param  list<int|string>  $customerIds
     * @return array<int|string, Carbon>
     */
    private static function lastTaskByCustomer(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return CrmTask::query()
            ->where('about_type', Customer::class)
            ->whereIn('about_id', $customerIds)
            ->selectRaw('about_id, MAX(created_at) as last_at')
            ->groupBy('about_id')
            ->pluck('last_at', 'about_id')
            ->map(fn (mixed $at): Carbon => Carbon::parse((string) $at))
            ->all();
    }
}
