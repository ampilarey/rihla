<?php

namespace App\Support;

use App\Models\Departure;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What needs attention — §8.5's smart alerts.
 *
 * ## It only raises what nothing else is watching
 *
 * This operator already has screens for most of its own work: the
 * departure board carries {@see DepartureReadiness}'s concerns, the Today
 * page carries adrift enquiries and follow-ups due, the chasing list
 * carries outstanding documents, and the scholar's desk carries the review
 * queue. Restating any of those here would double every number in the
 * office and make both copies less trusted than one.
 *
 * So this raises the conditions that have **no screen watching them**:
 *
 * - a departure the forecast says will not fill, while there is still time
 *   to do something about it;
 * - a departure the forecast says is over-subscribed;
 * - money that has been sitting unreviewed;
 * - a quotation about to expire with no answer;
 * - a departure flying soon that still has a blocker nobody has cleared —
 *   which *is* readiness, raised here because the board only tells you
 *   when you go and open it, and nobody opens it on a quiet Tuesday.
 * - religious content waiting on a scholar for longer than a fortnight,
 *   because only the scholar's own desk shows that, and a scholar who has
 *   gone quiet is exactly the person who will not look at it.
 *
 * ## Each alert is shown only to somebody who can act on it
 *
 * The page is open to anyone in the panel; the list is not. A tour leader
 * shown "MVR 84,000 is waiting to be reconciled" has learned something
 * about the business and can do nothing with it. See {@see Alert}.
 *
 * ## Computed, never stored
 *
 * The same reasoning as every other read model here, and one more: a
 * stored alert has to be dismissed, dismissal has to be remembered, and a
 * remembered dismissal is how a real problem stays hidden for a season.
 * These disappear when the condition does, and not before.
 */
final class Alerts
{
    /** A departure inside this many days is too close to fix a shortfall. */
    private const TOO_LATE_TO_SELL = 21;

    /** A departure inside this many days with a blocker is today's problem. */
    private const FLYING_SOON = 30;

    /** Money unreviewed for longer than this is somebody's oversight. */
    private const PAYMENT_PATIENCE_DAYS = 3;

    /** A quotation expiring inside this many days needs chasing now. */
    private const QUOTATION_NOTICE_DAYS = 5;

    /**
     * Everything worth raising, urgent first, then by size.
     *
     * @return Collection<int, Alert>
     */
    public static function all(): Collection
    {
        return collect(array_merge(
            self::departuresThatWillNotFill(),
            self::departuresOverSubscribed(),
            self::moneyWaitingToBeReviewed(),
            self::quotationsAboutToExpire(),
            self::departuresFlyingWithBlockers(),
            self::contentWaitingOnAScholar(),
        ))
            // Two passes, not `sortBy([fn, fn])`. In that multi-sort form
            // Laravel calls each callable as a two-argument *comparator*, so
            // a one-argument accessor returns 0 or 1 and never -1 — not a
            // consistent comparator, and `uasort` on one of those is
            // undefined behaviour.
            //
            // On this screen's data it happened to come out right, which is
            // why it shipped and why no test here can tell the two apart.
            // On {@see Referrals} the same form put a nought-seat referrer
            // above an eight-seat one, and that one is pinned by a test.
            // Both were changed together rather than leaving a form that
            // works by luck next to one that does not.
            ->sortByDesc(fn (Alert $alert): int => $alert->count)
            ->sortBy(fn (Alert $alert): int => $alert->isUrgent() ? 0 : 1)
            ->values();
    }

    /**
     * The ones this person can actually do something about.
     *
     * @return Collection<int, Alert>
     */
    public static function for(User $user): Collection
    {
        return self::all()->filter(
            fn (Alert $alert): bool => $alert->permission === null || $user->can($alert->permission),
        )->values();
    }

    // ── The conditions ──────────────────────────────────────────────────

    /**
     * Departures the forecast projects short, with time left to act.
     *
     * Inside {@see TOO_LATE_TO_SELL} days it is no longer an alert but a
     * fact, and raising it then only teaches people to ignore the alert
     * that arrived in time.
     *
     * @return list<Alert>
     */
    private static function departuresThatWillNotFill(): array
    {
        $short = self::selling()
            ->map(fn (Departure $departure): SeatForecast => SeatForecast::build($departure))
            ->filter(fn (SeatForecast $forecast): bool => $forecast->hasProjection()
                && $forecast->high < $forecast->capacity
                && $forecast->daysToGo > self::TOO_LATE_TO_SELL);

        if ($short->isEmpty()) {
            return [];
        }

        $seats = (int) $short->sum(fn (SeatForecast $forecast): int => $forecast->capacity - $forecast->high);

        return [new Alert(
            key: 'forecast.short',
            severity: Alert::WORTH_KNOWING,
            headline: $short->count() === 1
                ? 'A departure is not on course to fill'
                : $short->count().' departures are not on course to fill',
            detail: 'On the pace of past journeys, '.($short->count() === 1 ? 'it lands' : 'they land')
                .' short by '.$seats.' '.($seats === 1 ? 'seat' : 'seats').' in total, and there is still time to sell them. '
                .'Soonest: '.$short->sortBy(fn (SeatForecast $f): int => $f->daysToGo)->first()?->departure->date_start->format('j M Y').'.',
            action: 'Open the forecast to see which, and by how much.',
            url: route('filament.staff.pages.forecast'),
            permission: 'kpi.view',
            count: $short->count(),
        )];
    }

    /** @return list<Alert> */
    private static function departuresOverSubscribed(): array
    {
        $over = self::selling()
            ->map(fn (Departure $departure): SeatForecast => SeatForecast::build($departure))
            ->filter(fn (SeatForecast $forecast): bool => $forecast->hasProjection()
                && $forecast->low > $forecast->capacity);

        if ($over->isEmpty()) {
            return [];
        }

        return [new Alert(
            key: 'forecast.oversubscribed',
            severity: Alert::WORTH_KNOWING,
            headline: $over->count() === 1
                ? 'A departure is selling faster than it has seats'
                : $over->count().' departures are selling faster than they have seats',
            detail: 'Even the least favourable reading of past journeys fills '
                .($over->count() === 1 ? 'it' : 'them').'. Somebody should decide now whether there are seats to add, '
                .'while the airline still has them.',
            action: 'Open the forecast, then the waiting list.',
            url: route('filament.staff.pages.forecast'),
            permission: 'kpi.view',
            count: $over->count(),
        )];
    }

    /**
     * Money somebody sent that nobody has looked at.
     *
     * Urgent, and not because of the amount: a pilgrim who uploaded a
     * transfer slip four days ago and has heard nothing assumes the money
     * is lost, and rings.
     *
     * @return list<Alert>
     */
    private static function moneyWaitingToBeReviewed(): array
    {
        $waiting = Payment::query()
            ->where('status', Payment::AWAITING_REVIEW)
            ->where('created_at', '<', Carbon::now()->subDays(self::PAYMENT_PATIENCE_DAYS))
            ->get(['id', 'currency', 'amount_minor', 'created_at']);

        if ($waiting->isEmpty()) {
            return [];
        }

        // Per currency, never summed across them — [R-7].
        $byCurrency = $waiting
            ->groupBy('currency')
            ->map(fn (Collection $group, string $currency): string => (string) Money::ofMinor(
                (int) $group->sum('amount_minor'),
                $currency,
            ))
            ->values()
            ->join(' and ');

        $oldest = $waiting->min('created_at');

        return [new Alert(
            key: 'payment.unreviewed',
            severity: Alert::URGENT,
            headline: $waiting->count().' '.($waiting->count() === 1 ? 'payment has' : 'payments have')
                .' been waiting to be checked',
            detail: $byCurrency.' sent more than '.self::PAYMENT_PATIENCE_DAYS.' days ago and still unreviewed. '
                .'The oldest has been waiting '.Carbon::parse((string) $oldest)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE)
                .'. Somebody who sent a transfer and has heard nothing assumes the money is lost.',
            action: 'Open Payments and reconcile them.',
            url: route('filament.staff.resources.payments.index'),
            permission: 'payment.reconcile',
            count: $waiting->count(),
        )];
    }

    /**
     * A price about to stop being true, with nobody having answered.
     *
     * @return list<Alert>
     */
    private static function quotationsAboutToExpire(): array
    {
        $expiring = Quotation::query()
            ->where('status', Quotation::SENT)
            ->whereNull('booking_id')
            ->whereDate('valid_until', '>=', Carbon::now()->toDateString())
            ->whereDate('valid_until', '<=', Carbon::now()->addDays(self::QUOTATION_NOTICE_DAYS)->toDateString())
            ->get(['id', 'valid_until']);

        if ($expiring->isEmpty()) {
            return [];
        }

        return [new Alert(
            key: 'quotation.expiring',
            severity: Alert::WORTH_KNOWING,
            headline: $expiring->count().' '.($expiring->count() === 1 ? 'quotation expires' : 'quotations expire')
                .' within '.self::QUOTATION_NOTICE_DAYS.' days',
            detail: 'Sent, never answered, and about to stop being true. A quotation that lapses in silence is a customer '
                .'who decided somewhere else — one telephone call is the whole intervention.',
            action: 'Open Quotations and ring them.',
            url: route('filament.staff.resources.quotations.index'),
            permission: 'quotation.viewAny',
            count: $expiring->count(),
        )];
    }

    /**
     * A departure flying soon that still has something blocking it.
     *
     * The departure board already knows this. It is raised here because
     * the board only tells you when you open it, and nobody opens it on a
     * quiet Tuesday — which is the Tuesday the permit does not get chased.
     * The alert deliberately carries no detail of *what* is blocking: that
     * belongs on the board, and two copies of it would diverge.
     *
     * @return list<Alert>
     */
    private static function departuresFlyingWithBlockers(): array
    {
        $blocked = Departure::query()
            ->whereDate('date_start', '>=', Carbon::now()->toDateString())
            ->whereDate('date_start', '<=', Carbon::now()->addDays(self::FLYING_SOON)->toDateString())
            ->get()
            ->filter(fn (Departure $departure): bool => DepartureReadiness::hasBlockers($departure));

        if ($blocked->isEmpty()) {
            return [];
        }

        $soonest = $blocked->sortBy('date_start')->first();

        return [new Alert(
            key: 'departure.blocked',
            severity: Alert::URGENT,
            headline: $blocked->count() === 1
                ? 'A departure flying within a month still has a blocker'
                : $blocked->count().' departures flying within a month still have blockers',
            detail: 'Something on '.($blocked->count() === 1 ? 'it' : 'them').' would stop somebody travelling — a permit, a visa, '
                .'a passport. The soonest leaves on '.$soonest?->date_start->format('j M Y')
                .'. The board says exactly what; this only says that nobody has opened it.',
            action: 'Open the departure board.',
            url: route('filament.staff.pages.departure-board'),
            permission: 'departure.board',
            count: $blocked->count(),
        )];
    }

    /**
     * Religious content that has been waiting on a scholar too long.
     *
     * Only the scholar's own desk shows this, and a scholar who has gone
     * quiet is precisely the person who will not be looking at their desk.
     * The office is the one who can pick up the telephone.
     *
     * @return list<Alert>
     */
    private static function contentWaitingOnAScholar(): array
    {
        $stale = ReviewQueue::build()->filter(
            fn (ReviewQueueItem $item): bool => $item->isStale(),
        );

        if ($stale->isEmpty()) {
            return [];
        }

        return [new Alert(
            key: 'review.stale',
            severity: Alert::WORTH_KNOWING,
            headline: $stale->count().' '.($stale->count() === 1 ? 'item has' : 'items have')
                .' been waiting on a scholar for over a fortnight',
            detail: 'The longest has been waiting '.$stale->sortBy(
                fn (ReviewQueueItem $item): string => (string) $item->waitingSince,
            )->first()?->waitingFor().'. Nothing here publishes without sign-off, so a quiet reviewer is a stopped queue '
                .'— and the desk that shows it is the reviewer\'s own.',
            action: 'Open the scholar\'s desk, then telephone them.',
            url: route('filament.staff.pages.scholar-desk'),
            permission: 'knowledge.viewAny',
            count: $stale->count(),
        )];
    }

    // ── Shared query ────────────────────────────────────────────────────

    /** @return EloquentCollection<int, Departure> */
    private static function selling(): EloquentCollection
    {
        return Departure::query()
            ->whereDate('date_start', '>=', Carbon::now()->toDateString())
            ->where('capacity_total', '>', 0)
            ->orderBy('date_start')
            ->limit(24)
            ->get();
    }
}
