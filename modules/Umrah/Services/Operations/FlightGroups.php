<?php

namespace Modules\Umrah\Services\Operations;

use Illuminate\Support\Facades\DB;
use Modules\Umrah\Models\BookingTraveller;
use Modules\Umrah\Models\FlightGroup;

/**
 * Puts travellers in a flight group (companies plan, Phase 3c, from Hijaz).
 *
 * Somebody moved into another group comes off their old group's bus, with
 * their boarding tick: that coach meets a different flight. Somebody on a
 * bus that belongs to no group keeps it, because nothing says it is wrong.
 * Somebody on another departure is skipped and named, never moved.
 */
final class FlightGroups
{
    /**
     * @param  list<int>  $bookingTravellerIds
     * @return array{assigned: int, skipped: list<string>, offBus: int}
     */
    public function assign(FlightGroup $group, array $bookingTravellerIds): array
    {
        return DB::transaction(function () use ($group, $bookingTravellerIds): array {
            $assigned = 0;
            $offBus = 0;
            $skipped = [];

            $members = BookingTraveller::query()
                ->whereKey($bookingTravellerIds)
                ->with(['traveller', 'booking', 'busGroup'])
                ->get();

            foreach ($members as $member) {
                if ((int) $member->booking->departure_id !== (int) $group->departure_id) {
                    $skipped[] = $member->traveller->full_name.': on another departure';

                    continue;
                }

                if ($member->flight_group_id === $group->getKey()) {
                    continue;
                }

                $changes = ['flight_group_id' => $group->getKey()];

                $bus = $member->busGroup;
                if ($bus !== null && $bus->flight_group_id !== null && $bus->flight_group_id !== $group->getKey()) {
                    $changes += ['bus_group_id' => null, 'checked_in_at' => null];
                    $offBus++;
                }

                $member->forceFill($changes)->save();
                $assigned++;
            }

            return ['assigned' => $assigned, 'skipped' => $skipped, 'offBus' => $offBus];
        });
    }

    /**
     * Take these people out of whatever group they are in. Their bus, if it
     * belongs to a group, goes with it.
     *
     * @param  list<int>  $bookingTravellerIds
     */
    public function unassign(array $bookingTravellerIds): int
    {
        $taken = 0;

        foreach (BookingTraveller::query()->whereKey($bookingTravellerIds)->whereNotNull('flight_group_id')->with('busGroup')->get() as $member) {
            $changes = ['flight_group_id' => null];

            if ($member->busGroup?->flight_group_id !== null) {
                $changes += ['bus_group_id' => null, 'checked_in_at' => null];
            }

            $member->forceFill($changes)->save();
            $taken++;
        }

        return $taken;
    }
}
