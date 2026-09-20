<?php

namespace App\Support;

use App\Models\Departure;
use App\Models\Package;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The choices the finder offers, derived from departures that exist.
 *
 * Offering "December" when nothing departs in December wastes the one
 * interaction a visitor gives you, and a budget band nobody's prices fall
 * into reads as "nothing here for me". So every option below is computed
 * from real, published, upcoming departures — and when there are none, the
 * finder has nothing to offer and says so rather than rendering empty
 * dropdowns.
 */
final class PackageFinderOptions
{
    /**
     * @param  Collection<int, array{value: string, label: string}>  $months
     * @param  Collection<int, array{value: int, label: string}>  $budgets
     * @param  Collection<int, array{value: int, label: string}>  $durations
     */
    private function __construct(
        public readonly Collection $months,
        public readonly Collection $budgets,
        public readonly Collection $durations,
    ) {}

    public static function build(): self
    {
        $departures = Departure::published()
            ->upcoming()
            ->whereHas('package', function ($package): void {
                /** @var Builder<Package> $package */
                $package->published();
            })
            ->with(['priceTiers', 'package'])
            ->get();

        return new self(
            months: self::months($departures),
            budgets: self::budgets($departures),
            durations: self::durations($departures),
        );
    }

    public function isEmpty(): bool
    {
        return $this->months->isEmpty();
    }

    /**
     * @param  Collection<int, Departure>  $departures
     * @return Collection<int, array{value: string, label: string}>
     */
    private static function months(Collection $departures): Collection
    {
        return $departures
            ->sortBy('date_start')
            ->map(fn (Departure $departure): array => [
                'value' => $departure->date_start->format('Y-m'),
                'label' => $departure->date_start->translatedFormat('F Y'),
            ])
            ->unique('value')
            ->values();
    }

    /**
     * Three bands spanning the real prices, rounded to something a person
     * would say out loud.
     *
     * @param  Collection<int, Departure>  $departures
     * @return Collection<int, array{value: int, label: string}>
     */
    private static function budgets(Collection $departures): Collection
    {
        $leads = $departures
            ->map(fn (Departure $departure): ?int => $departure->priceTiers->min('amount_minor'))
            ->filter()
            ->values();

        if ($leads->count() < 2) {
            return collect();
        }

        $cheapest = (int) $leads->min();
        $dearest = (int) $leads->max();

        if ($dearest <= $cheapest) {
            return collect();
        }

        $step = intdiv($dearest - $cheapest, 3);

        return collect([1, 2, 3])
            ->map(function (int $multiple) use ($cheapest, $step, $dearest): array {
                // Rounded up to the nearest 1,000 rufiyaa so the label reads
                // as a budget rather than as a computed boundary.
                $minor = min($dearest, $cheapest + $step * $multiple);
                $major = (int) (ceil($minor / 100 / 1000) * 1000);

                return [
                    'value' => $major,
                    // Cast: __() is typed array|string, because a key can
                    // resolve to a whole group. This one resolves to a
                    // sentence, and the shape above promises a string.
                    'label' => (string) __('messages.Up to :amount', [
                        'amount' => Money::ofMajor($major)->format(),
                    ]),
                ];
            })
            ->unique('value')
            ->values();
    }

    /**
     * @param  Collection<int, Departure>  $departures
     * @return Collection<int, array{value: int, label: string}>
     */
    private static function durations(Collection $departures): Collection
    {
        return $departures
            ->map(fn (Departure $departure): int => $departure->nights)
            ->filter(fn (int $nights): bool => $nights > 0)
            ->unique()
            ->sort()
            ->map(fn (int $nights): array => [
                'value' => $nights,
                'label' => trans_choice('{1}:count night or fewer|[2,*]:count nights or fewer', $nights, ['count' => $nights]),
            ])
            ->values();
    }
}
