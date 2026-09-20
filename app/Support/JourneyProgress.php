<?php

namespace App\Support;

use App\Models\Departure;
use App\Models\DepartureHotel;

/**
 * Where a group is in its journey — §6.2's "journey progress".
 *
 * ## Derived from what is recorded, never from what would be nice to show
 *
 * A family portal that says "your father is in Madinah" because it is the
 * ninth day of a fourteen-day trip is guessing, and it will be wrong on the
 * trip where the coach broke down. Everything here comes from the
 * departure's dates and its recorded hotel nights, and where those do not
 * answer the question it says so rather than filling the gap.
 *
 * ## No live location, and no column for one
 *
 * §6.2: "no individual live location unless explicitly enabled". Nothing
 * records a location, so nothing here can leak one. That is deliberate and
 * not an oversight to be tidied up later — the safest place for data nobody
 * has asked for is absent.
 */
final class JourneyProgress
{
    public const BEFORE = 'before';

    public const TRAVELLING_OUT = 'travelling_out';

    public const IN_MAKKAH = 'in_makkah';

    public const IN_MADINAH = 'in_madinah';

    public const ON_THE_TRIP = 'on_the_trip';

    public const HOME = 'home';

    /**
     * One stage, and a sentence a family can read.
     *
     * @return array{stage: string, headline: string, detail: string}
     */
    public static function of(Departure $departure): array
    {
        $today = now()->startOfDay();
        $start = $departure->date_start->startOfDay();
        $end = $departure->date_end->startOfDay();

        if ($today->lessThan($start)) {
            $days = (int) $today->diffInDays($start);

            return [
                'stage' => self::BEFORE,
                'headline' => $days === 0
                    ? 'They leave today'
                    : ($days === 1 ? 'They leave tomorrow' : "They leave in {$days} days"),
                'detail' => 'The group has not set off yet.',
            ];
        }

        if ($today->greaterThan($end)) {
            return [
                'stage' => self::HOME,
                'headline' => 'The journey is over',
                'detail' => 'The group came home on '.$departure->date_end->format('j F Y').'.',
            ];
        }

        if ($today->equalTo($start)) {
            return [
                'stage' => self::TRAVELLING_OUT,
                'headline' => 'They are travelling today',
                'detail' => 'The group leaves Malé today.',
            ];
        }

        $city = self::cityOn($departure, $today);

        if ($city === null) {
            // The honest answer when nobody recorded the hotel nights. A
            // guess from the day number would be wrong on the trip where
            // the coach broke down, and that is the trip a family is
            // refreshing this page on.
            return [
                'stage' => self::ON_THE_TRIP,
                'headline' => 'They are on the journey',
                'detail' => 'The group is away until '.$departure->date_end->format('j F Y').'.',
            ];
        }

        return [
            'stage' => $city === 'makkah' ? self::IN_MAKKAH : self::IN_MADINAH,
            'headline' => $city === 'makkah' ? 'They are in Makkah' : 'They are in Madinah',
            'detail' => 'Until '.$departure->date_end->format('j F Y').'.',
        ];
    }

    /**
     * Which city, from the recorded hotel nights.
     *
     * The hotels are walked in the itinerary's order and each is given its
     * recorded number of nights from the departure date. Null when the
     * nights do not cover today — which happens whenever somebody has not
     * finished entering them, and is a fact rather than a bug.
     */
    private static function cityOn(Departure $departure, \DateTimeInterface $day): ?string
    {
        $hotels = $departure->hotels()->orderBy('id')->get();

        if ($hotels->isEmpty()) {
            return null;
        }

        $cursor = $departure->date_start->copy()->startOfDay();

        foreach ($hotels as $hotel) {
            $nights = (int) $hotel->nights;

            if ($nights <= 0) {
                continue;
            }

            $until = $cursor->copy()->addDays($nights);

            if ($day >= $cursor && $day < $until) {
                return self::normalise($hotel);
            }

            $cursor = $until;
        }

        return null;
    }

    private static function normalise(DepartureHotel $hotel): ?string
    {
        return match (strtolower((string) $hotel->city)) {
            'makkah', 'mecca' => 'makkah',
            'madinah', 'medina' => 'madinah',
            default => null,
        };
    }
}
