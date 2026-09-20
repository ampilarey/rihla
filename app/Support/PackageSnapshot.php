<?php

namespace App\Support;

use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\PriceTier;

/**
 * What was sold, frozen at the moment of sale.
 *
 * Packages and departures go on being edited after a booking is taken — a
 * hotel is swapped, a price is corrected, the inclusions are reworded, the
 * tour leader changes. None of that may retroactively change somebody's
 * contract, and "look at the live relationship" is exactly how a customer
 * ends up reading terms they never agreed to.
 *
 * So the booking carries a copy. Every screen that shows a customer what
 * they bought reads this; the relationship is for operations, who need to
 * know what the departure is *now*.
 *
 * Translations are stored whole — `{"en": …, "dv": …}` — rather than
 * resolved to one language, because the customer may read the confirmation
 * in Dhivehi and the invoice in English, and resolving at capture time would
 * pick one for ever.
 */
final class PackageSnapshot
{
    /** @return array<string, mixed> */
    public static function of(Departure $departure): array
    {
        $departure->loadMissing(['package', 'hotels', 'priceTiers', 'tourLeader', 'scholar']);
        $package = $departure->package;

        return [
            // The version marker. When this structure changes, old bookings
            // keep the shape they were written with and whatever reads them
            // can tell which it is holding — rather than guessing from
            // whether a key happens to be present.
            'version' => 1,
            'captured_at' => now()->toIso8601String(),

            'package' => [
                'id' => $package?->getKey(),
                'slug' => $package?->slug,
                'title' => $package?->getTranslations('title'),
                'summary' => $package?->getTranslations('summary'),
                'inclusions' => $package?->getTranslations('inclusions'),
                'exclusions' => $package?->getTranslations('exclusions'),
                'nights' => $package?->nights,
                'accessibility_rating' => $package?->accessibility_rating,
                'accessibility_notes' => $package?->getTranslations('accessibility_notes'),
            ],

            'departure' => [
                'id' => $departure->getKey(),
                'date_start' => $departure->date_start?->toDateString(),
                'date_end' => $departure->date_end?->toDateString(),
                'airline' => $departure->airline,
                // Names only. A snapshot of a person is a copy of their
                // biography in every booking row, which is both useless and
                // a thing to keep correct in two places.
                'tour_leader' => $departure->tourLeader?->name,
                'scholar' => $departure->scholar?->name,
            ],

            'hotels' => $departure->hotels->map(fn (DepartureHotel $hotel): array => [
                'city' => $hotel->city,
                'name' => $hotel->name,
                'rating' => $hotel->rating,
                'distance_metres' => $hotel->distance_metres,
                'walk_minutes' => $hotel->walk_minutes,
                'nights' => $hotel->nights,
            ])->all(),

            'price_tiers' => $departure->priceTiers->map(fn (PriceTier $tier): array => [
                'occupancy' => $tier->occupancy,
                'pax_type' => $tier->pax_type,
                'amount_minor' => $tier->amount_minor,
                'currency' => $tier->currency,
            ])->all(),
        ];
    }
}
