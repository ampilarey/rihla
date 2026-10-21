<?php

namespace App\Services\Departures;

use App\Models\Departure;
use App\Models\DepartureCost;
use App\Models\DepartureFlight;
use App\Models\DepartureTransfer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * "Copy this departure": the same set-up on new dates.
 *
 * A package sells the same journey many times, and until now every run of
 * it had its prices, hotels, itinerary, flights, transfers and checklist
 * typed in again. The copy carries all of that with every date moved by the
 * number of days between the two departures, so a 14-night plan stays a
 * 14-night plan and the Madinah day stays the Madinah day.
 *
 * Not copied, because they belong to the run that happened: bookings and
 * seat holds (the counters start at zero), the airline's booking references
 * (a PNR is for one flight on one day), checklist ticks, what the costs
 * came to (every cost returns to an estimate), the Nusuk records, and
 * the state — a copy is a draft, and goes on sale when somebody has read it
 * through and opened it for booking.
 * Rooms are not copied either: they are a rooming list, not a plan.
 */
class DepartureCopier
{
    public function copy(Departure $from, CarbonInterface $dateStart): Departure
    {
        $shift = (int) $from->date_start->diffInDays($dateStart, false);
        $shifted = fn (?CarbonInterface $date): ?string => $date?->copy()->addDays($shift)->toDateString();
        $shiftedAt = fn (?CarbonInterface $at): ?CarbonInterface => $at?->copy()->addDays($shift);

        return DB::transaction(function () use ($from, $dateStart, $shifted, $shiftedAt): Departure {
            $to = Departure::query()->create([
                'package_id' => $from->package_id,
                'date_start' => $dateStart->toDateString(),
                'date_end' => $shifted($from->date_end),
                'airline' => $from->airline,
                'tour_leader_id' => $from->tour_leader_id,
                'scholar_id' => $from->scholar_id,
                'capacity_total' => $from->capacity_total,
                'capacity_held' => 0,
                'capacity_confirmed' => 0,
                // A draft: it goes on sale when somebody has read it through
                // and opened it for booking.
                'status' => Departure::DRAFT,
            ]);

            foreach ($from->priceTiers as $tier) {
                $to->priceTiers()->create($tier->only(['audience', 'occupancy', 'pax_type', 'amount_minor', 'currency', 'sort_order']));
            }

            foreach ($from->hotels as $hotel) {
                $to->hotels()->create($hotel->only(['city', 'name', 'rating', 'distance_metres', 'walk_minutes', 'nights', 'sort_order']));
            }

            foreach ($from->itinerary as $day) {
                $to->itinerary()->create([
                    'day_number' => $day->day_number,
                    'city' => $day->city,
                    'title' => $day->getTranslations('title'),
                    'description' => $day->getTranslations('description'),
                ]);
            }

            foreach ($from->flights as $flight) {
                $to->flights()->create(array_merge(
                    $flight->only(['direction', 'airline', 'flight_number', 'from_airport', 'to_airport', 'seats', 'notes']),
                    [
                        'departs_at' => $shiftedAt($flight->departs_at),
                        'arrives_at' => $shiftedAt($flight->arrives_at),
                    ],
                ));
            }

            foreach ($from->transfers as $transfer) {
                $to->transfers()->create(array_merge(
                    $transfer->only(['mode', 'from_place', 'to_place', 'meeting_point', 'provider', 'contact_phone', 'notes']),
                    ['starts_at' => $shiftedAt($transfer->starts_at)],
                ));
            }

            foreach ($from->checklist as $item) {
                $to->checklist()->create(array_merge(
                    $item->only(['title', 'is_blocking', 'notes', 'sort_order']),
                    ['due_on' => $shifted($item->due_on)],
                ));
            }

            foreach (DepartureCost::query()->where('departure_id', $from->getKey())->orderBy('id')->get() as $cost) {
                DepartureCost::query()->create(array_merge(
                    $cost->only(['category', 'supplier', 'description', 'currency', 'amount_minor', 'is_per_person']),
                    ['departure_id' => $to->getKey(), 'status' => DepartureCost::ESTIMATED],
                ));
            }

            return $to->refresh();
        });
    }

    /**
     * What the copy will carry, for the confirmation the action shows.
     *
     * @return list<string>
     */
    public static function carries(Departure $from): array
    {
        $counts = [
            'prices' => $from->priceTiers()->count(),
            'hotels' => $from->hotels()->count(),
            'itinerary days' => $from->itinerary()->count(),
            'flights' => DepartureFlight::query()->where('departure_id', $from->getKey())->count(),
            'transfers' => DepartureTransfer::query()->where('departure_id', $from->getKey())->count(),
            'checklist items' => $from->checklist()->count(),
            'estimated costs' => DepartureCost::query()->where('departure_id', $from->getKey())->count(),
        ];

        $lines = [];

        foreach ($counts as $what => $count) {
            if ($count > 0) {
                $lines[] = $count.' '.$what;
            }
        }

        return $lines;
    }
}
