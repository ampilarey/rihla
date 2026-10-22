<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\BusGroup;
use App\Models\Departure;
use App\Support\Rooming;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who sits on which coach — Hijaz's bus groups, brought across.
 *
 * Assignment is a person's decision; this records it and then reports what
 * is wrong, in the spirit of {@see Rooming}: a bus over
 * capacity, a family split across two coaches, a confirmed traveller on
 * no bus at all, a bus with nobody named at its door.
 *
 * A family is everybody one person booked onto the departure, across
 * their bookings ({@see Families}), so a relative added on a second
 * booking is missed on the wrong coach as surely as one on the first.
 */
final class Buses
{
    public const OVER_CAPACITY = 'over_capacity';

    public const SPLIT_FAMILY = 'split_family';

    public const UNASSIGNED = 'unassigned';

    public const NO_LEADER = 'no_leader';

    /**
     * Put these people on this bus.
     *
     * Exactly these people: the screen widens a choice to whole families
     * before it calls this ({@see Families::widen()}), so a family is split
     * only by somebody turning that off. Somebody on another departure is
     * skipped and named, not silently moved; somebody already on the bus is
     * left alone. Boarding is reset, because a tick made at the door of a
     * different coach says nothing about this one.
     *
     * @param  list<int>  $bookingTravellerIds
     * @return array{assigned: int, skipped: list<string>, warnings: list<string>}
     */
    public function assign(BusGroup $bus, array $bookingTravellerIds): array
    {
        return DB::transaction(function () use ($bus, $bookingTravellerIds): array {
            $members = BookingTraveller::query()
                ->whereKey($bookingTravellerIds)
                ->with(['traveller', 'booking'])
                ->get();

            $assigned = 0;
            $skipped = [];

            foreach ($members as $member) {
                if ((int) $member->booking->departure_id !== (int) $bus->departure_id) {
                    $skipped[] = $member->traveller->full_name.': on another departure';

                    continue;
                }

                if ($member->bus_group_id === $bus->getKey()) {
                    continue;
                }

                $member->forceFill(['bus_group_id' => $bus->getKey(), 'checked_in_at' => null])->save();
                $assigned++;
            }

            $warnings = [];

            if ($bus->isOverfull()) {
                $warnings[] = sprintf('%s now has %d people for %d seats.', $bus->name, $bus->members()->count(), $bus->capacity);
            }

            $bookers = $members->map(fn (BookingTraveller $member): int => (int) $member->booking->customer_id);

            foreach ($this->splitFamilies($bus->departure) as $split) {
                if ($bookers->contains($split['booker_id'])) {
                    $warnings[] = $split['detail'];
                }
            }

            return ['assigned' => $assigned, 'skipped' => $skipped, 'warnings' => $warnings];
        });
    }

    /**
     * Take these people off whatever bus they are on.
     *
     * @param  list<int>  $bookingTravellerIds
     */
    public function unassign(array $bookingTravellerIds): int
    {
        $taken = 0;

        foreach (BookingTraveller::query()->whereKey($bookingTravellerIds)->whereNotNull('bus_group_id')->get() as $member) {
            $member->forceFill(['bus_group_id' => null, 'checked_in_at' => null])->save();
            $taken++;
        }

        return $taken;
    }

    /** Boarded, or un-boarded if the tick was a mistake. */
    public function toggleCheckIn(BookingTraveller $member): void
    {
        $member->forceFill(['checked_in_at' => $member->isCheckedIn() ? null : now()])->save();
    }

    /**
     * Everything wrong with a departure's buses, for a person to fix.
     *
     * @return list<array{type: string, bus: ?string, who: ?string, detail: string}>
     */
    public function problemsWith(Departure $departure): array
    {
        $problems = [];

        foreach ($departure->busGroups()->get() as $bus) {
            if ($bus->isOverfull()) {
                $problems[] = [
                    'type' => self::OVER_CAPACITY,
                    'bus' => $bus->name,
                    'who' => null,
                    'detail' => sprintf('%d people for %d seats.', $bus->members()->count(), $bus->capacity),
                ];
            }

            if ($bus->leaderLabel() === null) {
                $problems[] = ['type' => self::NO_LEADER, 'bus' => $bus->name, 'who' => null, 'detail' => 'Nobody is named at its door.'];
            }
        }

        foreach ($this->splitFamilies($departure) as $split) {
            $problems[] = ['type' => self::SPLIT_FAMILY, 'bus' => $split['buses'], 'who' => $split['who'], 'detail' => $split['detail']];
        }

        // Silent until there is a bus to be on: "everybody is on no bus"
        // before the coaches exist is the kind of alarm that teaches a
        // board to be ignored.
        if ($departure->busGroups()->exists()) {
            $unassigned = $this->confirmed($departure)->filter(fn (BookingTraveller $member): bool => $member->bus_group_id === null);

            if ($unassigned->isNotEmpty()) {
                $problems[] = [
                    'type' => self::UNASSIGNED,
                    'bus' => null,
                    'who' => $unassigned->map(fn (BookingTraveller $member): string => $member->traveller->full_name)->implode(', '),
                    'detail' => $unassigned->count() === 1
                        ? 'One confirmed traveller is on no bus.'
                        : $unassigned->count().' confirmed travellers are on no bus.',
                ];
            }
        }

        return $problems;
    }

    /**
     * Everybody on a confirmed booking — the people who must be on a bus.
     *
     * @return Collection<int, BookingTraveller>
     */
    public function confirmed(Departure $departure): Collection
    {
        return $this->onBookings($departure, [Booking::CONFIRMED, Booking::COMPLETED]);
    }

    /**
     * Everybody who could be put on a bus: confirmed, or holding a seat
     * while the money comes in.
     *
     * @return Collection<int, BookingTraveller>
     */
    public function candidates(Departure $departure): Collection
    {
        return $this->onBookings($departure, [Booking::HELD, Booking::CONFIRMED, Booking::COMPLETED]);
    }

    /**
     * @param  list<string>  $statuses
     * @return Collection<int, BookingTraveller>
     */
    private function onBookings(Departure $departure, array $statuses): Collection
    {
        return BookingTraveller::query()
            ->whereIn(
                'booking_id',
                Booking::query()->where('departure_id', $departure->getKey())->whereIn('status', $statuses)->select('id'),
            )
            ->with(['traveller', 'booking.customer', 'busGroup'])
            ->get()
            ->sortBy(fn (BookingTraveller $member): string => $member->booking->customer->name.' '.$member->traveller->full_name)
            ->values();
    }

    /**
     * Families whose people sit on different buses: everybody one person
     * booked onto the departure, whichever of their bookings they are on.
     *
     * @return list<array{booker_id: int, who: string, buses: string, detail: string}>
     */
    private function splitFamilies(Departure $departure): array
    {
        $out = [];

        $byBooker = $this->candidates($departure)
            ->filter(fn (BookingTraveller $member): bool => $member->bus_group_id !== null)
            ->groupBy(fn (BookingTraveller $member): int => (int) $member->booking->customer_id);

        foreach ($byBooker as $bookerId => $members) {
            $buses = $members->map(fn (BookingTraveller $member): ?string => $member->busGroup?->name)->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();

            if ($buses->count() < 2) {
                continue;
            }

            $who = $members->first()->booking->customer->name;

            $out[] = [
                'booker_id' => (int) $bookerId,
                'who' => $who,
                'buses' => $buses->implode(' and '),
                'detail' => $who.'\'s family is split across '.$buses->implode(' and ').'.',
            ];
        }

        return $out;
    }
}
