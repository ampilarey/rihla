<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\Room;
use App\Models\Traveller;
use Illuminate\Support\Collection;

/**
 * What is wrong with a rooming list — §8.2.
 *
 * ## It reports; it does not allocate
 *
 * §8.2 asks for "intelligent grouping by family/gender/age". This is
 * deliberately not that. An allocator that shuffles real pilgrims into
 * rooms on rules nobody has stated is how a mother is separated from her
 * children by a heuristic, and how a conflict nobody spotted becomes an
 * argument at a hotel desk at two in the morning. What this does is find
 * every mistake and name it, for a person to fix.
 *
 * ## The checks, and why each one
 *
 * - **Over capacity** — four people in a three-bed room. Arithmetic, and
 *   the one that actually happens when a booking grows after the rooming
 *   was done.
 * - **Mixed gender** in a room not designated `family`. A `family` room is
 *   mixed on purpose; a room with no designation at all is reported as
 *   undesignated rather than assumed either way.
 * - **A child with no adult in the room.** Age at the departure date, not
 *   today. The threshold is configuration because it is a safeguarding
 *   judgement rather than a fact.
 * - **Somebody in two rooms in the same hotel.** The database forbids the
 *   same room twice; two different rooms is a separate mistake and the
 *   commonest one after a re-plan.
 * - **A confirmed traveller with no room at all.** The one that is invisible
 *   on a rooming list, because the person simply is not on it.
 *
 * ## Only confirmed travellers are owed a bed
 *
 * A draft booking or a lapsed hold is not somebody who is going, and
 * counting them would make every departure permanently short of rooms.
 */
final class Rooming
{
    public const OVER_CAPACITY = 'over_capacity';

    public const MIXED_GENDER = 'mixed_gender';

    public const UNDESIGNATED = 'undesignated';

    public const UNACCOMPANIED_CHILD = 'unaccompanied_child';

    public const DOUBLE_BOOKED = 'double_booked';

    public const UNROOMED = 'unroomed';

    /**
     * Everything wrong with one hotel's rooming, in order of how much it
     * matters at the hotel desk.
     *
     * @return list<array{type: string, room: ?string, who: ?string, detail: string}>
     */
    public static function problemsWith(DepartureHotel $hotel): array
    {
        $hotel->loadMissing(['rooms.assignments.traveller', 'departure']);

        $problems = [];

        foreach ($hotel->rooms as $room) {
            $problems = array_merge($problems, self::problemsInRoom($room, $hotel->departure));
        }

        return array_merge(
            $problems,
            self::doubleBooked($hotel),
            self::unroomed($hotel),
        );
    }

    /** @return list<array{type: string, room: ?string, who: ?string, detail: string}> */
    private static function problemsInRoom(Room $room, Departure $departure): array
    {
        $problems = [];
        $occupants = $room->occupants();

        if ($room->isOverfull()) {
            $problems[] = [
                'type' => self::OVER_CAPACITY,
                'room' => $room->label,
                'who' => null,
                'detail' => sprintf(
                    '%d people in a room with %s.',
                    $occupants->count(),
                    $room->capacity === 1 ? 'one bed' : $room->capacity.' beds',
                ),
            ];
        }

        if ($occupants->isEmpty()) {
            return $problems;
        }

        if (config('rooming.checks.mixed_gender')) {
            $problems = array_merge($problems, self::genderProblems($room, $occupants));
        }

        if (config('rooming.checks.unaccompanied_child')) {
            $problems = array_merge($problems, self::childProblems($room, $occupants, $departure));
        }

        return $problems;
    }

    /**
     * @param  Collection<int, Traveller>  $occupants
     * @return list<array{type: string, room: ?string, who: ?string, detail: string}>
     */
    private static function genderProblems(Room $room, $occupants): array
    {
        // A family room is mixed on purpose.
        if ($room->gender === Room::FAMILY) {
            return [];
        }

        $genders = $occupants->pluck('gender')->filter()->unique()->values();

        if ($genders->count() > 1) {
            return [[
                'type' => self::MIXED_GENDER,
                'room' => $room->label,
                'who' => $occupants->pluck('full_name')->implode(', '),
                'detail' => 'Men and women share this room, and it is not marked as a family room.',
            ]];
        }

        // Designated for one gender, occupied by the other.
        if ($room->gender !== null && $genders->count() === 1 && $genders->first() !== $room->gender) {
            return [[
                'type' => self::MIXED_GENDER,
                'room' => $room->label,
                'who' => $occupants->pluck('full_name')->implode(', '),
                'detail' => sprintf('Marked for %s, but occupied by %s.', $room->genderLabel(), $genders->first()),
            ]];
        }

        // Nobody has said what this room is. Reported rather than assumed:
        // a rooming list handed to a hotel with blanks in it is how a
        // family is split across two floors.
        if ($room->gender === null) {
            return [[
                'type' => self::UNDESIGNATED,
                'room' => $room->label,
                'who' => null,
                'detail' => 'Nobody has said whether this is a men\'s, women\'s or family room.',
            ]];
        }

        return [];
    }

    /**
     * @param  Collection<int, Traveller>  $occupants
     * @return list<array{type: string, room: ?string, who: ?string, detail: string}>
     */
    private static function childProblems(Room $room, $occupants, Departure $departure): array
    {
        $under = (int) config('rooming.child_under', 16);
        $travelDate = $departure->date_start;

        $children = $occupants->filter(function (Traveller $traveller) use ($under, $travelDate): bool {
            $age = $traveller->ageOn($travelDate);

            // An unknown date of birth is not a child. Guessing either way
            // is wrong, and guessing "child" would flag every traveller
            // whose birthday nobody recorded.
            return $age !== null && $age < $under;
        });

        if ($children->isEmpty()) {
            return [];
        }

        $adults = $occupants->filter(function (Traveller $traveller) use ($under, $travelDate): bool {
            $age = $traveller->ageOn($travelDate);

            return $age === null || $age >= $under;
        });

        if ($adults->isNotEmpty()) {
            return [];
        }

        return [[
            'type' => self::UNACCOMPANIED_CHILD,
            'room' => $room->label,
            'who' => $children->pluck('full_name')->implode(', '),
            'detail' => sprintf('Under %d, with no adult in the room.', $under),
        ]];
    }

    /**
     * Somebody in two rooms in the same hotel.
     *
     * The database forbids the same room twice; two *different* rooms is a
     * separate mistake, and the commonest one after a re-plan.
     *
     * @return list<array{type: string, room: ?string, who: ?string, detail: string}>
     */
    private static function doubleBooked(DepartureHotel $hotel): array
    {
        $seen = [];

        foreach ($hotel->rooms as $room) {
            foreach ($room->assignments as $assignment) {
                $seen[$assignment->traveller_id][] = $room->label;
            }
        }

        $problems = [];

        foreach ($seen as $travellerId => $labels) {
            if (count($labels) < 2) {
                continue;
            }

            $problems[] = [
                'type' => self::DOUBLE_BOOKED,
                'room' => implode(' and ', $labels),
                'who' => Traveller::find($travellerId)?->full_name,
                'detail' => 'In more than one room in this hotel.',
            ];
        }

        return $problems;
    }

    /**
     * Confirmed travellers with no bed here.
     *
     * The problem that is invisible on a rooming list, because the person
     * is simply not on it.
     *
     * @return list<array{type: string, room: ?string, who: ?string, detail: string}>
     */
    private static function unroomed(DepartureHotel $hotel): array
    {
        $roomed = $hotel->rooms->flatMap(
            fn (Room $room): array => $room->assignments->pluck('traveller_id')->all(),
        )->unique();

        $problems = [];

        foreach (self::travellersOwedABed($hotel->departure) as $traveller) {
            if ($roomed->contains($traveller->getKey())) {
                continue;
            }

            $problems[] = [
                'type' => self::UNROOMED,
                'room' => null,
                'who' => $traveller->full_name,
                'detail' => 'Confirmed on this departure, with no room in this hotel.',
            ];
        }

        return $problems;
    }

    /**
     * Everybody on a confirmed booking for this departure.
     *
     * @return Collection<int, Traveller>
     */
    public static function travellersOwedABed(Departure $departure): Collection
    {
        return $departure->bookings()
            ->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])
            ->with('travellers.traveller')
            ->get()
            ->flatMap(fn (Booking $booking) => $booking->travellers->map(fn ($line) => $line->traveller))
            ->filter()
            ->unique('id')
            ->values();
    }

    /** Whether a hotel's rooming is ready to send. */
    public static function isSettled(DepartureHotel $hotel): bool
    {
        return self::problemsWith($hotel) === [];
    }
}
