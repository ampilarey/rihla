<?php

namespace App\Filament\Pages;

use App\Models\Departure;
use App\Models\DepartureCost;
use App\Support\JourneyProfit;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * What each journey made — §8.4's per-journey profitability.
 *
 * "The report that changes pricing decisions." It is a page rather than a
 * resource because there is no `profitability` table: every figure on it
 * is arithmetic over bookings and {@see DepartureCost}, done at read time
 * for the reason every other read model here gives.
 *
 * ## It will not invent an exchange rate
 *
 * See {@see JourneyProfit}. Where a rate is missing the page says which
 * currency is missing one, rather than adding numbers that are not
 * comparable into a single figure somebody would price a season on.
 */
class Profitability extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'What each journey made';

    protected static ?string $title = 'What each journey made';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.profitability';

    /** Which costs to count. Bound to the toggle on the page. */
    public string $countingUpTo = DepartureCost::ESTIMATED;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('profit.view') === true;
    }

    /**
     * Departures that have actually gone, newest first.
     *
     * A departure still selling has a margin, and it is a forecast made of
     * a guess at how many will book — which is a different report. This one
     * answers "what did we make", and the answer needs the journey to have
     * happened.
     *
     * @return Collection<int, JourneyProfit>
     */
    public function getJourneys(): Collection
    {
        return Departure::query()
            ->whereDate('date_start', '<', now()->toDateString())
            ->with('package')
            ->orderByDesc('date_start')
            ->limit(24)
            ->get()
            ->map(fn (Departure $departure): JourneyProfit => JourneyProfit::build($departure, $this->countingUpTo));
    }

    /** @return array<string, string> */
    public function countingOptions(): array
    {
        return collect(DepartureCost::STATUSES)
            ->mapWithKeys(fn (string $status): array => [
                $status => match ($status) {
                    DepartureCost::PAID => 'Only what has been paid',
                    DepartureCost::COMMITTED => 'Agreed and paid',
                    default => 'Everything, including estimates',
                },
            ])
            ->all();
    }
}
