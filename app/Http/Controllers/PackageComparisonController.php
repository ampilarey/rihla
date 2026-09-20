<?php

namespace App\Http\Controllers;

use App\Models\Departure;
use App\Models\Package;
use App\Models\PriceTier;
use Illuminate\Database\Eloquent\Builder;
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
            // Departure::published(), not Departure::query()->published():
            // the static call is typed as a builder for this model, so the
            // local scope resolves. query() is typed as a builder for the
            // base Model, which has no published().
            : Departure::published()
                // Upcoming only, matching the package page. A comparison
                // link saved last season would otherwise resurrect
                // departures that have already left and show them as
                // something to book. Dropping a column is the lesser
                // surprise.
                ->upcoming()
                ->whereKey($ids)
                // whereHas() hands its callback a builder typed for the
                // base Model, which has no published() scope — that, not the
                // static call above, is what static analysis objected to.
                // Naming the generic keeps the scope as the single source of
                // truth; repeating `where('is_published', true)` here would
                // work too and would be a second place to change.
                ->whereHas('package', function ($query): void {
                    /** @var Builder<Package> $query */
                    $query->published();
                })
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

    /**
     * The ids asked for, as an array or a comma-separated string.
     *
     * `positive-int`, not `int`: the filter below narrows the type, and a
     * Collection's value type is not covariant, so a Collection of
     * positive-int is not a Collection of int as far as static analysis is
     * concerned. Saying what it actually holds is better than widening it
     * back with a no-op map.
     *
     * @return Collection<int, positive-int>
     */
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
