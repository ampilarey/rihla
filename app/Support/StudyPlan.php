<?php

namespace App\Support;

use App\Models\Departure;
use App\Models\LearningModule;
use App\Models\ModuleCompletion;
use App\Models\Traveller;
use App\Models\ZiyarahLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What this pilgrim should read, and by when — §7.3's personalised study
 * plan.
 *
 * ## Computed, never stored
 *
 * The same reasoning as {@see DepartureReadiness} and
 * {@see TravelReadiness}. A stored plan is wrong from the moment the
 * departure moves, a module is published, or the pilgrim reads something —
 * and a plan that is quietly wrong is worse than none, because somebody
 * arrives having done what it told them.
 *
 * ## The date is the whole of what personalises it
 *
 * §7.3 asks for a plan "keyed to the departure date (60 / 30 / 7 days
 * out)". Each module carries its own `days_before_departure`, so the due
 * date is arithmetic on a date this system already holds. There is no
 * assignment step and nobody has to remember to run anything: bring a
 * departure forward by a fortnight and every pilgrim on it sees new
 * deadlines the next time they open the page.
 *
 * ## The itinerary tie-in, and how it is allowed to fail
 *
 * §7.3: "if the trip visits Uhud, surface Uhud's history the week before."
 * A module can name a {@see ZiyarahLocation}, and one that does appears
 * only when this departure's itinerary actually mentions that place.
 *
 * The match is on the location's name against the itinerary text, which is
 * a text match and will miss a spelling the office wrote differently. The
 * right long-term shape is a link between an itinerary item and a location,
 * which is schema nothing has asked for and no data exists for yet. So the
 * miss is made **visible** rather than silent: {@see unmatchedLocations()}
 * lists every location module this itinerary did not reach, and the staff
 * screen shows it. A tie-in that quietly drops a module is the failure this
 * codebase has been bitten by before.
 *
 * ## Nothing here is a gate
 *
 * No score, no certificate, no permission. A pilgrim who has read nothing
 * still travels; the plan exists so they arrive knowing what they are
 * doing, and so the office can see who has not started while there is
 * still time to ring them.
 */
final class StudyPlan
{
    /** Read already. */
    public const DONE = 'done';

    /** Due now — the date it was meant to be read by has arrived or passed. */
    public const NOW = 'now';

    /** Not yet due. */
    public const LATER = 'later';

    private function __construct(
        public readonly Departure $departure,
        public readonly ?Traveller $traveller,
        /** @var Collection<int, StudyPlanItem> */
        public readonly Collection $items,
        /** @var Collection<int, ZiyarahLocation> */
        public readonly Collection $unmatchedLocations,
    ) {}

    /**
     * The plan for one person on one departure.
     *
     * `$traveller` may be null — the office looks at the shape of a plan
     * for a departure before anybody is on it, and a plan with nothing
     * marked done is exactly what a new pilgrim sees.
     */
    public static function build(Departure $departure, ?Traveller $traveller = null): self
    {
        $modules = LearningModule::live()
            ->with('location')
            ->orderByDesc('days_before_departure')
            ->orderBy('id')
            ->get();

        $itineraryText = self::itineraryText($departure);

        $unmatched = collect();
        $relevant = collect();

        foreach ($modules as $module) {
            if ($module->location === null) {
                $relevant->push($module);

                continue;
            }

            if (self::itineraryMentions($itineraryText, $module->location)) {
                $relevant->push($module);
            } else {
                $unmatched->push($module->location);
            }
        }

        $completions = self::completionsFor($traveller, $relevant->pluck('id')->all());

        $start = self::departureDate($departure);
        $today = CarbonImmutable::now()->startOfDay();

        $items = $relevant->map(function (LearningModule $module) use ($completions, $start, $today): StudyPlanItem {
            $dueOn = $start->subDays($module->days_before_departure);
            $completion = $completions->get($module->getKey());

            return new StudyPlanItem(
                module: $module,
                due_on: $dueOn,
                state: match (true) {
                    $completion?->hasBeenRead() === true => self::DONE,
                    $dueOn->lessThanOrEqualTo($today) => self::NOW,
                    default => self::LATER,
                },
                days_until_due: (int) $today->diffInDays($dueOn, false),
                completion: $completion,
            );
        })->values();

        return new self($departure, $traveller, $items, $unmatched->unique('id')->values());
    }

    public function total(): int
    {
        return $this->items->count();
    }

    public function doneCount(): int
    {
        return $this->inState(self::DONE)->count();
    }

    public function dueNowCount(): int
    {
        return $this->inState(self::NOW)->count();
    }

    public function hasStarted(): bool
    {
        return $this->doneCount() > 0;
    }

    /**
     * Whether this plan is still something to act on.
     *
     * Once a departure has left, showing a pilgrim eleven overdue modules
     * is nagging about a thing that cannot be fixed. The page says the
     * journey has been and gone instead.
     */
    public function isHistory(): bool
    {
        return self::departureDate($this->departure)->isPast();
    }

    /**
     * One line for the top of a page, in the words a reader uses.
     *
     * Never a percentage. "3 of 11, 2 to read now" names the next action;
     * "27%" is a grade, and this is not a course anybody is being marked
     * on.
     */
    public function summary(): string
    {
        if ($this->total() === 0) {
            return 'Nothing to read yet.';
        }

        $line = $this->doneCount().' of '.$this->total().' read';

        if ($this->isHistory()) {
            return $line.'.';
        }

        $due = $this->dueNowCount();

        return $due === 0
            ? $line.', nothing due yet.'
            : $line.', '.($due === 1 ? '1 to read now.' : $due.' to read now.');
    }

    /** @return Collection<int, StudyPlanItem> */
    public function inState(string $state): Collection
    {
        return $this->items
            ->filter(fn (StudyPlanItem $item): bool => $item->state === $state)
            ->values();
    }

    /**
     * What this person has already read, keyed by module.
     *
     * A method rather than a ternary at the call site, so the empty case
     * carries the same type as the populated one — an empty `collect()`
     * types as `Collection<int, mixed>` and poisons everything downstream.
     *
     * @param  list<int|string>  $moduleIds
     * @return Collection<int, ModuleCompletion>
     */
    private static function completionsFor(?Traveller $traveller, array $moduleIds): Collection
    {
        if ($traveller === null || $moduleIds === []) {
            return new Collection;
        }

        return ModuleCompletion::where('traveller_id', $traveller->getKey())
            ->whereIn('learning_module_id', $moduleIds)
            ->get()
            ->keyBy('learning_module_id');
    }

    private static function departureDate(Departure $departure): CarbonImmutable
    {
        return CarbonImmutable::parse($departure->date_start)->startOfDay();
    }

    /**
     * Everything this departure's itinerary says, in every language it says
     * it in, folded to lower case once.
     */
    private static function itineraryText(Departure $departure): string
    {
        $parts = [];

        foreach ($departure->itinerary as $item) {
            foreach (['title', 'description'] as $field) {
                $translations = $item->getTranslations($field);

                foreach ($translations as $value) {
                    $parts[] = (string) $value;
                }
            }

            $parts[] = (string) $item->city;
        }

        return mb_strtolower(implode(' | ', $parts));
    }

    /** Does the itinerary name this place, in any language it is stored in? */
    private static function itineraryMentions(string $itineraryText, ZiyarahLocation $location): bool
    {
        foreach ($location->getTranslations('name') as $name) {
            $name = trim(mb_strtolower((string) $name));

            if ($name !== '' && str_contains($itineraryText, $name)) {
                return true;
            }
        }

        return false;
    }
}
