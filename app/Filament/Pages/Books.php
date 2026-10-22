<?php

namespace App\Filament\Pages;

use App\Filament\Support\DepartureOptions;
use App\Models\Departure;
use App\Services\Finance\Books as Ledgers;
use App\Support\JourneyProfit;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use UnitEnum;

/**
 * The company books — batch C from Hijaz. Four screens on one page: the
 * cash book of a departure or of the company, the months side by side,
 * what is still owed, and what came in between two dates. Each has its
 * CSV. Nothing here is stored; see {@see Ledgers}.
 *
 * @property-read string $tab
 */
class Books extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Books';

    protected static ?string $title = 'Books';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'books';

    protected string $view = 'filament.pages.books';

    public string $tab = Ledgers::CASH;

    /** Null is the company's own cash book. */
    public ?int $departureId = null;

    /** `Y-m`, or empty for every month. */
    public string $month = '';

    public int $months = 12;

    public string $from = '';

    public string $to = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('books.view') === true;
    }

    public function mount(): void
    {
        $requested = request()->integer('departure');
        $next = Departure::query()->notHistory()->where('date_end', '>=', today())->orderBy('date_start')->value('id');

        $this->departureId = $requested > 0 && Departure::query()->whereKey($requested)->exists()
            ? $requested
            : ($next === null ? null : (int) $next);
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();

        $tab = (string) request()->query('tab', '');

        if (in_array($tab, Ledgers::REPORTS, true)) {
            $this->tab = $tab;
        }
    }

    public function getDeparture(): ?Departure
    {
        return $this->departureId === null ? null : Departure::query()->with('package')->find($this->departureId);
    }

    /** @return array<int|string, string> */
    public function departureOptions(): array
    {
        return ['' => 'The company — no departure'] + DepartureOptions::all(recentOnly: true);
    }

    /** @return array<string, mixed> */
    public function getCashBook(): array
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $this->month) === 1 ? CarbonImmutable::parse($this->month.'-01') : null;

        return app(Ledgers::class)->cashBook($this->getDeparture(), $month);
    }

    /** The departure's margin beside its cash book, so the two are read together. */
    public function getProfit(): ?JourneyProfit
    {
        $departure = $this->getDeparture();

        return $departure === null ? null : JourneyProfit::build($departure);
    }

    /** @return list<array<string, mixed>> */
    public function getMonths(): array
    {
        return app(Ledgers::class)->monthly($this->months);
    }

    /** @return array<string, mixed> */
    public function getOwed(): array
    {
        return app(Ledgers::class)->outstanding();
    }

    /** @return array<string, mixed> */
    public function getCollections(): array
    {
        [$from, $to] = $this->range();

        return app(Ledgers::class)->collections($from, $to);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function range(): array
    {
        $from = rescue(fn () => CarbonImmutable::parse($this->from), CarbonImmutable::now()->startOfMonth(), false);
        $to = rescue(fn () => CarbonImmutable::parse($this->to), CarbonImmutable::now(), false);

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /** The CSV for the screen being looked at, with its filters. */
    public function csvUrl(): string
    {
        [$from, $to] = $this->range();

        return route('books.csv', ['report' => $this->tab] + match ($this->tab) {
            Ledgers::CASH => ['departure' => $this->departureId ?? 0, 'month' => $this->month],
            Ledgers::MONTHS => ['months' => $this->months],
            Ledgers::COLLECTIONS => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            default => [],
        });
    }

    /** @return array<string, string> */
    public function tabs(): array
    {
        return Ledgers::TITLES;
    }

    /** @return array<string, string> */
    public function monthOptions(): array
    {
        $options = ['' => 'Every month'];
        $start = CarbonImmutable::now()->startOfMonth();

        for ($i = 0; $i < 24; $i++) {
            $month = $start->subMonths($i);
            $options[$month->format('Y-m')] = $month->format('M Y');
        }

        return $options;
    }
}
