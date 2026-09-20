<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * Will this departure fill? — §8.5's forecasting.
 *
 * ## It forecasts from pace, and only from pace
 *
 * At any point before a departure, some fraction of its eventual seats has
 * been sold. Past journeys say what that fraction usually is this far out.
 * Divide what is sold today by that fraction and you have a projection of
 * where this one lands. That is the whole method, and it is the only one
 * this operator has the data for.
 *
 * ## It refuses more often than it answers, on purpose
 *
 * A projection from two past journeys is an extrapolation with a confident
 * face on it, and the face is the dangerous part — a seat forecast is a
 * number somebody charters an aircraft on. So it declines, by name, when:
 *
 * - fewer than `forecast.minimum_comparable_journeys` comparable journeys
 *   have flown;
 * - nobody had booked this early on any of them, so there is no fraction to
 *   divide by;
 * - nothing has been sold on this one yet — pace needs something to pace;
 * - the departure is inside `forecast.quiet_within_days`, where the useful
 *   question is no longer "will it fill" but "who is outstanding", which the
 *   departure board already answers.
 *
 * **The historical import is the reason the floor is not one.** Bookings
 * brought in from the old spreadsheets carry the import's dates, not the
 * day somebody actually booked, so their apparent pace is an artefact. A
 * forecast built on them would be confident and wrong.
 *
 * ## A range, never a point
 *
 * The spread comes from the comparable journeys themselves: the slowest
 * and fastest of them at this same distance out. A single projected number
 * hides how much the journeys differ from each other, and how much they
 * differ is the thing worth knowing before chartering.
 */
final class SeatForecast
{
    private function __construct(
        public readonly Departure $departure,
        public readonly int $capacity,
        public readonly int $soldNow,
        public readonly int $daysToGo,
        /** How many past journeys the pace was taken from. */
        public readonly int $comparableJourneys,
        /** True when those journeys share this one's package. */
        public readonly bool $comparablesSharePackage,
        /** Low end of the projection, or null when it declined. */
        public readonly ?int $low,
        /** High end of the projection, or null when it declined. */
        public readonly ?int $high,
        /** Why there is no projection. Null when there is one. */
        public readonly ?string $because,
    ) {}

    public static function build(Departure $departure): self
    {
        $capacity = (int) $departure->capacity_total;
        $sold = self::soldOn($departure);
        // copy() on both: startOfDay() mutates an Illuminate Carbon in place,
        // and the right-hand one is the model's own attribute.
        $daysToGo = (int) Carbon::now()->startOfDay()
            ->diffInDays($departure->date_start->copy()->startOfDay(), false);

        $decline = fn (string $because, int $journeys = 0, bool $samePackage = false): self => new self(
            departure: $departure,
            capacity: $capacity,
            soldNow: $sold,
            daysToGo: $daysToGo,
            comparableJourneys: $journeys,
            comparablesSharePackage: $samePackage,
            low: null,
            high: null,
            because: $because,
        );

        if ($daysToGo < 0) {
            return $decline('This departure has already flown. What it made is on the profitability screen; what it might have sold is no longer a question.');
        }

        $quiet = (int) config('forecast.quiet_within_days', 7);

        if ($daysToGo <= $quiet) {
            return $decline('It leaves in '.($daysToGo === 0 ? 'less than a day' : $daysToGo.' '.($daysToGo === 1 ? 'day' : 'days'))
                .'. This close, the useful question is who is still outstanding rather than whether it will fill, and the departure board answers that one.');
        }

        if ($capacity < 1) {
            return $decline('No capacity has been set for this departure, so there is nothing to fill.');
        }

        if ($sold < 1) {
            return $decline('Nothing has been sold on this departure yet. Pace needs something to pace: a projection from zero is zero however fast the past ones went.');
        }

        // Prefer journeys of the same product; fall back to all of them
        // rather than refuse, and say which was used.
        $samePackage = self::pastJourneys($departure, samePackage: true);
        $sharePackage = $samePackage->count() >= (int) config('forecast.minimum_comparable_journeys', 3);
        $past = $sharePackage ? $samePackage : self::pastJourneys($departure, samePackage: false);

        $minimum = (int) config('forecast.minimum_comparable_journeys', 3);

        if ($past->count() < $minimum) {
            return $decline(
                'Only '.$past->count().' comparable '.($past->count() === 1 ? 'journey has' : 'journeys have')
                .' flown, and a projection needs at least '.$minimum
                .'. Forecasting from fewer is an extrapolation with a confident face on it, and a seat forecast is a number somebody charters an aircraft on.',
                $past->count(),
                $sharePackage,
            );
        }

        /** @var list<float> $fractions */
        $fractions = [];

        foreach ($past as $journey) {
            $final = self::soldOn($journey);

            if ($final < 1) {
                continue;
            }

            $byNow = self::soldOnBy(
                $journey,
                $journey->date_start->copy()->subDays($daysToGo)->endOfDay(),
            );

            if ($byNow < 1) {
                continue;
            }

            $fractions[] = $byNow / $final;
        }

        if (count($fractions) < $minimum) {
            return $decline(
                'On '.($fractions === [] ? 'none' : 'only '.count($fractions))
                .' of the '.$past->count().' comparable journeys had anybody booked this far out, so there is no pace to divide by. '
                .'That is itself worth knowing: '.$daysToGo.' days ahead may simply be earlier than this operator\'s customers book.',
                $past->count(),
                $sharePackage,
            );
        }

        sort($fractions);

        // The extremes, not a confidence interval: these are the journeys
        // that actually happened, which is a claim somebody can check. A
        // standard deviation over four data points is statistics theatre.
        //
        // Note which way round it goes. A journey that had sold a LARGE
        // fraction of its final seats by this point front-loaded, so this
        // one — at the same seat count — projects LOW against it. The
        // largest fraction therefore gives the bottom of the range.
        $mostFrontLoaded = $fractions[count($fractions) - 1];
        $mostBackLoaded = $fractions[0];

        return new self(
            departure: $departure,
            capacity: $capacity,
            soldNow: $sold,
            daysToGo: $daysToGo,
            comparableJourneys: $past->count(),
            comparablesSharePackage: $sharePackage,
            low: (int) floor($sold / $mostFrontLoaded),
            high: (int) ceil($sold / $mostBackLoaded),
            because: null,
        );
    }

    public function hasProjection(): bool
    {
        return $this->low !== null && $this->high !== null;
    }

    /** Seats still unsold, which is the number the office acts on. */
    public function seatsLeft(): int
    {
        return max(0, $this->capacity - $this->soldNow);
    }

    /**
     * The projection in a sentence, or null when there is none.
     *
     * Written as a range with the history behind it, because "42 seats"
     * invites a decision that "between 34 and 51, from four past journeys"
     * does not.
     */
    public function spoken(): ?string
    {
        if (! $this->hasProjection()) {
            return null;
        }

        $range = $this->low === $this->high
            ? 'at '.$this->low.' seats'
            : 'between '.$this->low.' and '.$this->high.' seats';

        return 'On the pace of '.$this->comparableJourneys.' past '
            .($this->comparableJourneys === 1 ? 'journey' : 'journeys')
            .($this->comparablesSharePackage ? ' of this same package' : ' across all packages')
            .', this one lands '.$range.' against a capacity of '.$this->capacity.'.';
    }

    /**
     * What to do about it, or null when there is nothing to say.
     *
     * Only three outcomes are worth a sentence, and each names what the
     * office would actually do.
     */
    public function advice(): ?string
    {
        if (! $this->hasProjection()) {
            return null;
        }

        if ($this->low > $this->capacity) {
            return 'Even the least favourable reading of that history fills this one. Open the waiting list, and consider whether there are seats to add.';
        }

        if ($this->high < $this->capacity) {
            return 'Even the most favourable reading leaves '.($this->capacity - $this->high).' '
                .($this->capacity - $this->high === 1 ? 'seat' : 'seats').' unsold. '
                .'If this departure has to go out full, it needs something the past ones did not have.';
        }

        return 'It lands either side of capacity depending on which past journey it turns out to resemble. Worth watching rather than acting on.';
    }

    public function tone(): string
    {
        if (! $this->hasProjection()) {
            return 'gray';
        }

        return match (true) {
            $this->low >= $this->capacity => 'success',
            $this->high < $this->capacity => 'danger',
            default => 'warning',
        };
    }

    // ── Queries ─────────────────────────────────────────────────────────

    /** Seats on bookings that are real money, on one departure. */
    private static function soldOn(Departure $departure): int
    {
        return (int) Booking::query()
            ->where('departure_id', $departure->getKey())
            ->whereIn('status', Customer::TRAVELLED_ON)
            ->sum('seats');
    }

    /**
     * The same, as it stood on a given day.
     *
     * CarbonInterface, not Illuminate's Carbon: a model's date cast hands
     * back a Carbon\Carbon, and the two are not the same class.
     */
    private static function soldOnBy(Departure $departure, CarbonInterface $asOf): int
    {
        return (int) Booking::query()
            ->where('departure_id', $departure->getKey())
            ->whereIn('status', Customer::TRAVELLED_ON)
            ->where('created_at', '<=', $asOf)
            ->sum('seats');
    }

    /**
     * Journeys that have flown and could tell this one something.
     *
     * @return EloquentCollection<int, Departure>
     */
    private static function pastJourneys(Departure $departure, bool $samePackage): EloquentCollection
    {
        $query = Departure::query()
            ->whereKeyNot($departure->getKey())
            ->whereDate('date_start', '<', Carbon::now()->toDateString())
            ->where('capacity_total', '>', 0)
            ->orderByDesc('date_start')
            ->limit(24);

        if ($samePackage) {
            $query->where('package_id', $departure->package_id);
        }

        return $query->get(['id', 'package_id', 'date_start', 'capacity_total']);
    }
}
