<?php

namespace App\Filament\Support;

use App\Models\Departure;

/**
 * "Ramadan Umrah (14 Mar 2027)" for every departure a form or filter might
 * need, newest first.
 */
final class DepartureOptions
{
    /**
     * @param  bool  $recentOnly  leave out departures that came home more
     *                            than three months ago — for a form, where
     *                            nobody is entering flights for last year
     * @return array<int, string>
     */
    public static function all(bool $recentOnly = false): array
    {
        return Departure::query()
            ->with('package')
            ->when($recentOnly, fn ($query) => $query->where('date_end', '>=', now()->subMonths(3)))
            ->orderByDesc('date_start')
            ->get()
            ->mapWithKeys(fn (Departure $departure): array => [$departure->getKey() => self::label($departure)])
            ->all();
    }

    /** One departure's name, for a table column. Load `departure.package` first. */
    public static function label(?Departure $departure): string
    {
        if ($departure === null) {
            return '—';
        }

        return sprintf('%s (%s)', $departure->package->title ?? 'Departure', $departure->date_start->format('j M Y'));
    }
}
