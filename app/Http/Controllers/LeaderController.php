<?php

namespace App\Http\Controllers;

use App\Models\Departure;
use App\Models\Incident;
use App\Models\RollCall;
use App\Models\RollCallMark;
use App\Models\User;
use App\Services\Leader\Outbox;
use App\Support\Rooming;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The Tour Leader Portal — §6.3.
 *
 * Its own Blade pages rather than a Filament panel, for two reasons that
 * are not aesthetic. Filament's panel serves a stylesheet with no Tailwind
 * utilities in it, so everything here would have to be built out of
 * `fi-*` components designed for a desk. And this is used one-handed, on a
 * phone, in a crowd, sometimes with no signal — which needs a page small
 * enough to cache and simple enough to render from a local copy.
 *
 * ## Whose groups
 *
 * A leader sees the departures their **profile** is assigned to. An account
 * with no linked profile sees none and is told to ask the office. A roster
 * carries pilgrim names, ages and who is sharing a room with whom, so
 * "they probably won't look" is not an access rule.
 *
 * Operations sees every current departure, because that is the job.
 */
class LeaderController extends Controller
{
    public function __construct(private readonly Outbox $outbox) {}

    /** The groups this person is on the ground with. */
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('leader.index', [
            'departures' => $this->departuresFor($user),
            'unlinked' => $this->isUnlinkedLeader($user),
        ]);
    }

    /** One group: who is on it, where they are sleeping, and the counts. */
    public function departure(Request $request, Departure $departure): View
    {
        $this->authoriseDeparture($request->user(), $departure);

        $departure->load(['package', 'hotels.rooms.assignments.traveller']);

        return view('leader.departure', [
            'departure' => $departure,
            'travellers' => Rooming::travellersOwedABed($departure),
            'rollCalls' => RollCall::where('departure_id', $departure->getKey())
                ->with(['marks.traveller', 'departure'])
                ->orderByDesc('taken_at')
                ->get(),
            'openIncidents' => Incident::where('departure_id', $departure->getKey())
                ->open()
                ->orderByDesc('happened_at')
                ->get(),
        ]);
    }

    /** One head count, as a list of names to tap. */
    public function count(Request $request, Departure $departure, RollCall $rollCall): View
    {
        $this->authoriseDeparture($request->user(), $departure);

        abort_unless($rollCall->departure_id === $departure->getKey(), 404);

        $rollCall->load(['marks.traveller']);

        return view('leader.count', [
            'departure' => $departure,
            'rollCall' => $rollCall,
            'travellers' => $rollCall->expected(),
            'marks' => $rollCall->marks->keyBy('traveller_id'),
            'states' => RollCallMark::STATES,
        ]);
    }

    /**
     * Everything one departure needs to work from a local copy.
     *
     * Deliberately not the whole record: no passport numbers, no
     * phone numbers, no money. What a leader needs at a coach door is a
     * name, a room and whether they have been marked — and this payload
     * sits in a phone's storage until somebody clears it, so the less of it
     * there is, the less there is to lose with the phone.
     */
    public function snapshot(Request $request, Departure $departure): JsonResponse
    {
        $this->authoriseDeparture($request->user(), $departure);

        $rooms = [];

        foreach ($departure->hotels as $hotel) {
            foreach ($hotel->rooms as $room) {
                foreach ($room->assignments as $assignment) {
                    $rooms[$assignment->traveller_id][] = $hotel->cityLabel().' '.$room->label;
                }
            }
        }

        $travellers = Rooming::travellersOwedABed($departure)
            ->map(fn ($traveller): array => [
                'id' => $traveller->getKey(),
                'name' => $traveller->full_name,
                'rooms' => $rooms[$traveller->getKey()] ?? [],
            ])
            ->values();

        return response()->json([
            'departure' => [
                'id' => $departure->getKey(),
                'title' => $departure->package->title ?? 'Departure',
                'starts' => $departure->date_start->toDateString(),
            ],
            'travellers' => $travellers,
            'roll_calls' => RollCall::where('departure_id', $departure->getKey())
                ->with('marks')
                ->orderByDesc('taken_at')
                ->get()
                ->map(fn (RollCall $rollCall): array => [
                    'id' => $rollCall->getKey(),
                    'moment' => $rollCall->moment,
                    'taken_at' => $rollCall->taken_at->toIso8601String(),
                    'marks' => $rollCall->marks
                        ->mapWithKeys(fn (RollCallMark $mark): array => [
                            $mark->traveller_id => $mark->state,
                        ]),
                ])
                ->values(),
            // So a phone can tell a stale copy from a fresh one without
            // guessing from a filename or a cache header it does not
            // control.
            'taken_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Everything the phone queued while it had no signal.
     *
     * The response says what happened to each item by the id the phone gave
     * it, so the phone can clear exactly those and keep the rest. A batch
     * that half-applies must leave the outbox in a state the phone can
     * reason about — anything else and a leader loses marks without being
     * told which.
     */
    public function sync(Request $request): JsonResponse
    {
        $request->validate([
            'items' => ['required', 'array', 'max:200'],
            'items.*.id' => ['required'],
            'items.*.type' => ['required', 'string'],
        ]);

        // `validate()` returns *only* the keys it was given rules for, so
        // using its return value here silently dropped `roll_call_id`,
        // `traveller_id` and `state` and every queued mark came back
        // "that head count no longer exists". The rules are the shape
        // check; the Outbox validates each item's contents itself, against
        // what this user may do and the departure it claims.
        return response()->json([
            'results' => $this->outbox->apply($request->input('items'), $request->user()),
        ]);
    }

    /**
     * The departures this person may work on.
     *
     * @return Collection<int, Departure>
     */
    private function departuresFor(?User $user): Collection
    {
        $query = Departure::query()
            ->with('package')
            // On the ground now, or about to be. A trip that came home last
            // March is not what somebody opens this on a phone for.
            ->where('date_end', '>=', now()->subDays(3))
            ->where('date_start', '<=', now()->addDays(30))
            ->orderBy('date_start');

        if ($user?->can('attendance.delete') !== true) {
            $person = $user?->person;

            if ($person === null) {
                return collect();
            }

            $query->where('tour_leader_id', $person->getKey());
        }

        return $query->get();
    }

    /** A leader whose account nobody has linked to their profile. */
    private function isUnlinkedLeader(?User $user): bool
    {
        return $user !== null
            && $user->can('attendance.create')
            && $user->can('attendance.delete') !== true
            && $user->person === null;
    }

    private function authoriseDeparture(?User $user, Departure $departure): void
    {
        abort_unless(
            $this->departuresFor($user)->contains('id', $departure->getKey()),
            404,
        );
    }
}
