<?php

namespace App\Filament\Pages;

use App\Models\Departure;
use App\Support\DepartureReadiness;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The departure board — §8.2's journey workspace, as a question.
 *
 * One screen that answers "what is not ready?" across every upcoming
 * departure, so the answer does not have to be assembled by opening six
 * other screens and remembering what was on each.
 *
 * A page rather than a resource: there is no `departure_board` to list, and
 * every number on it is computed at read time. See
 * {@see DepartureReadiness} for why nothing here is stored and why it is a
 * list of named concerns rather than a percentage.
 */
class DepartureBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Departure board';

    protected static ?string $title = 'Departure board';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.departure-board';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('departure.board') === true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Every upcoming departure with its concerns attached.
     *
     * Ordered by date rather than by how wrong it is: the departure that
     * leaves on Tuesday is the one to fix first, even if the one in March
     * has more wrong with it.
     *
     * @return Collection<int, array{departure: Departure, concerns: list<array{area: string, severity: string, headline: string, detail: string}>, blocking: int, attention: int}>
     */
    public function getBoard(): Collection
    {
        return Departure::query()
            ->where('date_start', '>=', now()->startOfDay())
            ->with(['package', 'hotels', 'tourLeader', 'scholar'])
            ->orderBy('date_start')
            ->get()
            ->map(function (Departure $departure): array {
                $concerns = DepartureReadiness::concerns($departure);

                return [
                    'departure' => $departure,
                    'concerns' => $concerns,
                    'blocking' => self::countBy($concerns, DepartureReadiness::BLOCKING),
                    'attention' => self::countBy($concerns, DepartureReadiness::ATTENTION),
                ];
            });
    }

    /** @param list<array{area: string, severity: string, headline: string, detail: string}> $concerns */
    private static function countBy(array $concerns, string $severity): int
    {
        return count(array_filter($concerns, fn (array $concern): bool => $concern['severity'] === $severity));
    }
}
