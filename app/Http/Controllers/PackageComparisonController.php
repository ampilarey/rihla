<?php

namespace App\Http\Controllers;

use App\Models\Departure;
use App\Models\PriceTier;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Two or three departures side by side.
 *
 * The plan rates this highest for impact per effort: every operator in this
 * market sends a PDF per package, so a reader comparing two of them is
 * flipping between documents and holding the differences in their head. A
 * table that puts price, hotel distance, dates and what is included in one
 * column each is instantly better, and nobody here ships one.
 *
 * Deliberately a GET with the ids in the query string, served from the
 * server: the comparison is a URL that can be sent to whoever is paying,
 * it works with no JavaScript on a 3G phone in Malé, and a crawler can read
 * it. A client-side table would be none of those things.
 */
class PackageComparisonController extends Controller
{
    /**
     * Three at once, not more. A fourth column stops fitting on a phone,
     * which is where most of this traffic is.
     */
    private const LIMIT = 3;

    public function __invoke(Request $request): View
    {
        $ids = $this->requestedIds($request);

        $departures = $ids->isEmpty()
            ? new Collection
            : Departure::query()
                ->published()
                // Upcoming only, matching the package page. A comparison
                // link saved last season would otherwise resurrect
                // departures that have already left and show them as
                // something to book. Dropping a column is the lesser
                // surprise.
                ->upcoming()
                ->whereKey($ids)
                ->whereHas('package', fn ($query) => $query->published())
                ->with(['package', 'priceTiers', 'hotels', 'itinerary'])
                ->get()
                // Keep the order the visitor chose rather than the database's.
                ->sortBy(fn (Departure $departure): int => (int) $ids->search($departure->getKey()))
                ->values();

        return view('packages.compare', [
            'departures' => $departures,
            // Every occupancy any of them prices, so the table has one row
            // per occupancy and a gap where a departure does not offer it —
            // which is itself worth seeing.
            'occupancies' => $this->occupancyRows($departures),
            'limit' => self::LIMIT,
        ]);
    }

    /** @return Collection<int, int> */
    private function requestedIds(Request $request): Collection
    {
        /** @var array<int, mixed>|string $raw */
        $raw = $request->input('departures', []);

        return collect(is_array($raw) ? $raw : explode(',', $raw))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->take(self::LIMIT)
            ->values();
    }

    /**
     * The occupancies to give a row to, in the order a price list reads.
     *
     * @param  Collection<int, Departure>  $departures
     * @return list<string>
     */
    private function occupancyRows(Collection $departures): array
    {
        $offered = $departures
            ->flatMap(fn (Departure $departure) => $departure->priceTiers->pluck('occupancy'))
            ->unique();

        return array_values(array_filter(
            PriceTier::OCCUPANCIES,
            fn (string $occupancy): bool => $offered->contains($occupancy),
        ));
    }
}
