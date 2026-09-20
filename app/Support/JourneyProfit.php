<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Departure;
use App\Models\DepartureCost;
use Illuminate\Support\Collection;

/**
 * What a departure made, and what it cost — §8.4's per-journey
 * profitability, "the report that changes pricing decisions".
 *
 * ## Computed, never stored
 *
 * The same reasoning as every other read model here. A stored margin is
 * wrong from the next payment or the next supplier invoice, and a margin
 * that is quietly wrong is the one thing on this list that gets priced on.
 *
 * ## It will not invent an exchange rate
 *
 * Hotels bill in SAR, airlines in USD, the office collects MVR. A single
 * profit figure needs a rate, and a rate this application made up would be
 * a number somebody prices a season on. So:
 *
 * - every currency is reported separately, always;
 * - a combined total appears **only** when `config('finance.rates')` names
 *   a rate for each foreign currency present, and it carries the rate and
 *   the date it was set;
 * - when a rate is missing, {@see whyNoSingleFigure()} says which currency
 *   is missing one rather than the report quietly adding numbers that are
 *   not comparable.
 *
 * ## Estimates and paid costs are different questions
 *
 * `$countingUpTo` chooses. On estimates this is a forecast; on paid costs
 * it is history, and it is usually incomplete until months after the
 * departure returns. The report says which it used rather than leaving the
 * reader to guess.
 */
final class JourneyProfit
{
    private function __construct(
        public readonly Departure $departure,
        public readonly string $countingUpTo,
        public readonly int $travellers,
        /** @var Collection<string, Money> */
        public readonly Collection $revenue,
        /** @var Collection<string, Money> */
        public readonly Collection $costs,
        /** @var Collection<string, Collection<string, Money>> */
        public readonly Collection $costsByCategory,
    ) {}

    public static function build(Departure $departure, string $countingUpTo = DepartureCost::ESTIMATED): self
    {
        $bookings = Booking::query()
            ->where('departure_id', $departure->getKey())
            // Money that is actually owed to this operator. A draft or an
            // expired hold is somebody who thought about it.
            ->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])
            ->get();

        $travellers = (int) $bookings->sum('seats');

        $revenue = [];

        foreach ($bookings as $booking) {
            $total = $booking->total();
            $revenue[$total->currency] = ($revenue[$total->currency] ?? 0) + $total->minor;
        }

        $costs = [];
        $byCategory = [];

        foreach (DepartureCost::where('departure_id', $departure->getKey())->counting($countingUpTo)->get() as $cost) {
            $amount = $cost->totalFor($travellers);

            $costs[$amount->currency] = ($costs[$amount->currency] ?? 0) + $amount->minor;
            $byCategory[$cost->category][$amount->currency] =
                ($byCategory[$cost->category][$amount->currency] ?? 0) + $amount->minor;
        }

        return new self(
            departure: $departure,
            countingUpTo: $countingUpTo,
            travellers: $travellers,
            revenue: self::toMoney($revenue),
            costs: self::toMoney($costs),
            costsByCategory: collect($byCategory)->map(
                fn (array $perCurrency): Collection => self::toMoney($perCurrency),
            ),
        );
    }

    /**
     * Every currency that appears anywhere in this journey.
     *
     * @return Collection<int, string>
     */
    public function currencies(): Collection
    {
        return $this->revenue->keys()->merge($this->costs->keys())->unique()->sort()->values();
    }

    /**
     * Revenue minus costs, per currency and never across them.
     *
     * A currency with revenue and no cost, or a cost and no revenue, still
     * appears — that is exactly the row worth looking at.
     *
     * @return Collection<string, Money>
     */
    public function marginByCurrency(): Collection
    {
        return $this->currencies()->mapWithKeys(fn (string $currency): array => [
            $currency => Money::ofMinor(
                ($this->revenue->get($currency)->minor ?? 0) - ($this->costs->get($currency)->minor ?? 0),
                $currency,
            ),
        ]);
    }

    /** The base currency everything would be converted into, if it could be. */
    public function baseCurrency(): string
    {
        return strtoupper((string) config('finance.rates.to', 'MVR'));
    }

    /**
     * Why there is no single profit figure, or null when there is one.
     *
     * Named rather than silent: a report that quietly declines to total is
     * a report somebody assumes has nothing to total.
     */
    public function whyNoSingleFigure(): ?string
    {
        $missing = $this->currenciesWithoutARate();

        if ($missing->isEmpty()) {
            return null;
        }

        return 'This journey has money in '.$missing->join(', ', ' and ')
            .', and no exchange rate has been set for '
            .($missing->count() === 1 ? 'it' : 'them')
            .'. Adding those to '.$this->baseCurrency()
            .' without a rate would be a made-up number, so the figures are kept apart. Set the rate in config/finance.php and this becomes one total.';
    }

    /** @return Collection<int, string> */
    private function currenciesWithoutARate(): Collection
    {
        $rates = (array) config('finance.rates', []);

        return $this->currencies()
            ->reject(fn (string $currency): bool => $currency === $this->baseCurrency()
                || isset($rates[$currency]))
            ->values();
    }

    /**
     * The whole journey in one currency, when every rate is known.
     *
     * Null otherwise — see {@see whyNoSingleFigure()}.
     */
    public function margin(): ?Money
    {
        if ($this->whyNoSingleFigure() !== null) {
            return null;
        }

        $total = 0;

        foreach ($this->marginByCurrency() as $currency => $money) {
            $total += $this->inBaseCurrency($money);
        }

        return Money::ofMinor($total, $this->baseCurrency());
    }

    /** What one traveller left behind, which is the number pricing moves on. */
    public function marginPerTraveller(): ?Money
    {
        $margin = $this->margin();

        return $margin === null || $this->travellers < 1
            ? null
            : Money::ofMinor(intdiv($margin->minor, $this->travellers), $this->baseCurrency());
    }

    /** The rate used, and when somebody set it — both, or neither is trustworthy. */
    public function rateNote(): ?string
    {
        $missing = $this->currenciesWithoutARate();

        if ($missing->isNotEmpty() || $this->currencies()->count() < 2) {
            return null;
        }

        $asOf = config('finance.rates.as_of');

        return 'Converted to '.$this->baseCurrency().' at the rates in config/finance.php'
            .($asOf ? ', set on '.$asOf : ', which carry no date — nobody has recorded when they were last checked').'.';
    }

    public function countingLabel(): string
    {
        return match ($this->countingUpTo) {
            DepartureCost::PAID => 'costs actually paid',
            DepartureCost::COMMITTED => 'costs agreed or paid',
            default => 'every cost, including estimates',
        };
    }

    private function inBaseCurrency(Money $money): int
    {
        if ($money->currency === $this->baseCurrency()) {
            return $money->minor;
        }

        // Minor units of the base currency per whole foreign unit, so the
        // foreign minor units are divided back out once. Integer division
        // throughout: a float here is the [R-7] mistake in the one place
        // it would be read as a margin.
        $rate = (int) (config('finance.rates', [])[$money->currency] ?? 0);

        return intdiv($money->minor * $rate, 100);
    }

    /**
     * @param  array<string, int>  $minorByCurrency
     * @return Collection<string, Money>
     */
    private static function toMoney(array $minorByCurrency): Collection
    {
        return collect($minorByCurrency)->map(
            fn (int $minor, string $currency): Money => Money::ofMinor($minor, $currency),
        );
    }
}
