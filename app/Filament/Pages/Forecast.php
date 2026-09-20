<?php

namespace App\Filament\Pages;

use App\Models\Departure;
use App\Support\SeatForecast;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * What is likely to fill — §8.5's forecasting.
 *
 * Behind `kpi.view` rather than a verb of its own. A seat projection is a
 * commercial planning number for the same three roles the dashboard is
 * for, and a permission per screen would be a list of inert strings.
 *
 * The screen will often be mostly refusals, and that is the correct state
 * of it today: see {@see SeatForecast} for why a projection over two past
 * journeys is worse than no projection at all.
 */
class Forecast extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationLabel = 'What is likely to fill';

    protected static ?string $title = 'What is likely to fill';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.forecast';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('kpi.view') === true;
    }

    /**
     * Departures still selling, soonest first.
     *
     * Soonest first because a departure three weeks out with half its
     * seats is the one somebody has to do something about this week; the
     * one next spring can wait.
     *
     * @return Collection<int, SeatForecast>
     */
    public function getForecasts(): Collection
    {
        return Departure::query()
            ->whereDate('date_start', '>=', Carbon::now()->toDateString())
            ->with('package')
            ->orderBy('date_start')
            ->limit(24)
            ->get()
            ->map(fn (Departure $departure): SeatForecast => SeatForecast::build($departure));
    }

    /** How many past journeys a projection needs before it will give one. */
    public function minimumJourneys(): int
    {
        return (int) config('forecast.minimum_comparable_journeys', 3);
    }
}
