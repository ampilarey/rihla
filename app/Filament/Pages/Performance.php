<?php

namespace App\Filament\Pages;

use App\Support\Kpis;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * How the business is doing — §8.5's executive dashboard over §10.5's KPIs.
 *
 * A page rather than a resource because there is no `kpis` table: every
 * figure is arithmetic over rows that already exist, done at read time.
 * See {@see Kpis} for why, and for why four of the thirteen measures
 * appear as named absences rather than as numbers.
 *
 * ## The margin row is gated separately
 *
 * `kpi.view` opens the dashboard; `profit.view` opens the margin. The
 * Reporting role holds the first and not the second, and the page says so
 * in a line rather than leaving a gap where a row would be — a dashboard
 * that silently shows one reader twelve rows and another thirteen is a
 * dashboard two people will disagree about in a meeting.
 */
class Performance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'How the business is doing';

    protected static ?string $title = 'How the business is doing';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.performance';

    /** How far back to look. Bound to the tabs on the page. */
    public int $days = 90;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('kpi.view') === true;
    }

    public function mayReadMargin(): bool
    {
        return auth()->user()?->can('profit.view') === true;
    }

    public function getBoard(): Kpis
    {
        return Kpis::build($this->days, $this->mayReadMargin());
    }

    /** @return array<int, string> */
    public function windowOptions(): array
    {
        return Kpis::WINDOWS;
    }
}
