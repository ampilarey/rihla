<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\DepartureFlight;

/**
 * Where a departure is on each day, in order: Jeddah, Makkah, Madinah
 * (companies plan, Phase 3d, from Hijaz's route).
 *
 * Worked out rather than stored. The hotels already carry their order and
 * their nights and the flights their dates, so a second table of stops
 * would be one more thing to keep in step with them, and the day it was
 * not, the tour leader would be handed the wrong night in Madinah. What
 * nothing recorded — how each hotel is left — is `departure_hotels.leaves_by`.
 *
 * Landing in Jeddah is a stop of its own, because a coach meets the flight
 * there whoever sleeps where; so is the last day there before the flight
 * home. A date not known yet (no flights, no departure dates, a hotel with
 * no nights) is null, never guessed.
 */
final class DepartureRoute
{
    /** @var array<string, string> */
    public const LEAVES_BY = [
        'bus' => 'Coach',
        'train' => 'Haramain train',
        'car' => 'Car',
        'flight' => 'Flight',
    ];

    /**
     * @return list<array{place: string, hotel: ?string, arrives: ?CarbonInterface, leaves: ?CarbonInterface, by: ?string}>
     */
    public static function stops(Departure $departure): array
    {
        $flights = $departure->flights()->orderBy('departs_at')->get();
        $out = $flights->first(fn (DepartureFlight $f): bool => $f->direction === DepartureFlight::OUTBOUND);
        $home = $flights->last(fn (DepartureFlight $f): bool => $f->direction === DepartureFlight::RETURN);
        $hotels = $departure->hotels()->orderBy('sort_order')->orderBy('id')->get();

        $stops = [];
        $landed = $out !== null ? ($out->arrives_at ?? $out->departs_at)->copy()->startOfDay() : $departure->date_start?->copy();

        if ($out !== null && strtoupper((string) $out->to_airport) === 'JED') {
            $stops[] = ['place' => 'Jeddah', 'hotel' => null, 'arrives' => $landed, 'leaves' => $landed, 'by' => self::LEAVES_BY['bus']];
        }

        $cursor = $landed;

        foreach ($hotels->values() as $i => $hotel) {
            $leaves = ($cursor !== null && $hotel->nights) ? $cursor->copy()->addDays((int) $hotel->nights) : null;
            $isLast = $i === $hotels->count() - 1;

            $stops[] = [
                'place' => $hotel->cityLabel(),
                'hotel' => $hotel->name,
                'arrives' => $cursor,
                'leaves' => $leaves,
                'by' => self::LEAVES_BY[(string) $hotel->leaves_by] ?? ($isLast && $home !== null ? self::LEAVES_BY['bus'].' to the airport' : self::LEAVES_BY['bus']),
            ];

            $cursor = $leaves;
        }

        if ($home !== null && strtoupper((string) $home->from_airport) === 'JED' && $hotels->isNotEmpty()) {
            $day = $home->departs_at->copy()->startOfDay();
            $stops[] = ['place' => 'Jeddah', 'hotel' => null, 'arrives' => $day, 'leaves' => $day, 'by' => self::LEAVES_BY['flight'].' '.trim((string) $home->flight_number)];
        }

        return $stops;
    }

    /** "Jeddah 30 Nov → Makkah 30 Nov–5 Dec → Madinah 5–9 Dec", for a line of print. */
    public static function line(Departure $departure): string
    {
        return collect(self::stops($departure))
            ->map(fn (array $stop): string => $stop['place'].($stop['arrives'] ? ' '.$stop['arrives']->format('j M') : ''))
            ->implode(' → ');
    }
}
