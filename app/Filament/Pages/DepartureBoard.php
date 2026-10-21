<?php

namespace App\Filament\Pages;

use App\Exceptions\IllegalDepartureTransition;
use App\Models\Departure;
use App\Models\ZiyarahLocation;
use App\Support\DepartureReadiness;
use App\Support\StudyPlan;
use BackedEnum;
use Filament\Notifications\Notification;
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
     * Every departure that is still somebody's work, with its concerns
     * attached: open, closed, on the road or home and not yet settled, and
     * a draft close enough to leaving that somebody should be opening it.
     * Settled and cancelled departures are history and never here.
     *
     * Ordered by date rather than by how wrong it is: the departure that
     * leaves on Tuesday is the one to fix first, even if the one in March
     * has more wrong with it.
     *
     * @return Collection<int, array{departure: Departure, concerns: list<array{area: string, severity: string, headline: string, detail: string}>, blocking: int, attention: int, unmatched_places: list<string>}>
     */
    public function getBoard(): Collection
    {
        return Departure::query()
            ->notHistory()
            ->where(fn ($query) => $query
                ->where('status', '!=', Departure::DRAFT)
                ->orWhere('date_start', '<=', today()->addDays(DepartureReadiness::OPEN_WITHIN_DAYS)))
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
                    // §7.3's itinerary tie-in matches a module's place
                    // against the itinerary text, so it can miss a name the
                    // office wrote differently. Not a concern — nothing is
                    // wrong with the departure — but the office is the only
                    // party that can fix a spelling, so the office is told
                    // rather than the module being dropped in silence.
                    'unmatched_places' => array_values(
                        StudyPlan::build($departure)
                            ->unmatchedLocations
                            ->map(fn (ZiyarahLocation $location): string => (string) $location->name)
                            ->all(),
                    ),
                ];
            });
    }

    /**
     * The moves the board offers, and who may make each: the ones that need
     * no reason. Closing and cancelling ask why, and live on the package's
     * departures list with a form to say it.
     *
     * @var array<string, array{string, string}> to-state => [permission, button label]
     */
    public const MOVES = [
        Departure::DEPARTED => ['departure.lifecycle', 'Mark departed'],
        Departure::RETURNED => ['departure.lifecycle', 'Mark returned'],
        Departure::SETTLED => ['departure.settle', 'Settle'],
    ];

    /**
     * The moves this person may make on this departure from here. Finance
     * settles from the board because the package's departures list, where
     * the other moves live, is not a screen Finance opens.
     *
     * @return array<string, string> to-state => button label
     */
    public function movesFor(Departure $departure): array
    {
        $user = auth()->user();
        $moves = [];

        foreach (self::MOVES as $to => [$permission, $label]) {
            if ($departure->canBecome($to) && $user?->can($permission) === true) {
                $moves[$to] = $label;
            }
        }

        return $moves;
    }

    public function move(int $departureId, string $to): void
    {
        abort_unless(self::canAccess(), 403);

        [$permission, $label] = self::MOVES[$to] ?? abort(422, 'Not a move the board makes.');
        abort_unless(auth()->user()?->can($permission) === true, 403);

        $departure = Departure::query()->findOrFail($departureId);

        try {
            $departure->transitionTo($to);
        } catch (IllegalDepartureTransition $e) {
            Notification::make()->danger()->title('Not moved')->body($e->getMessage())->persistent()->send();

            return;
        }

        Notification::make()->success()->title($label)->body(($departure->package->title ?? 'The departure').' is now '.strtolower($departure->statusLabel()).'.')->send();
    }

    /** @param list<array{area: string, severity: string, headline: string, detail: string}> $concerns */
    private static function countBy(array $concerns, string $severity): int
    {
        return count(array_filter($concerns, fn (array $concern): bool => $concern['severity'] === $severity));
    }
}
