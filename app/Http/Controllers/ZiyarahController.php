<?php

namespace App\Http\Controllers;

use App\Models\ZiyarahLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The public Ziyarah Guide — §7.2.
 *
 * ## Only what a scholar signed off reaches this controller
 *
 * `live()` is the only way a location gets here, so a draft, a page waiting
 * on review and a withdrawn page are all 404 rather than "not published
 * yet". There is no preview link: a URL that shows unreviewed religious
 * content to whoever holds it is the thing §6.4 exists to prevent.
 *
 * ## The manifest is the offline feature
 *
 * §7.2 calls full offline support the feature pilgrims use with no data in
 * Saudi Arabia, and a service worker that happens to have cached the pages
 * somebody browsed is not that. {@see offlineManifest()} lists every live
 * location in the current language so the phone can be told to fetch all of
 * them deliberately, before the flight, while there is still wifi.
 */
class ZiyarahController extends Controller
{
    public function index(): View
    {
        $locations = ZiyarahLocation::live()
            ->withCount('misconceptions')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('ziyarah.index', [
            'byCity' => $this->groupByCity($locations),
            'total' => $locations->count(),
        ]);
    }

    /**
     * No `$locale` parameter, even though the route carries one: SetLocale
     * consumes it before the controller runs.
     */
    public function show(string $slug): View
    {
        $location = ZiyarahLocation::live()
            ->where('slug', $slug)
            ->with(['reviewer', 'references', 'misconceptions.references'])
            ->firstOrFail();

        $nearby = ZiyarahLocation::live()
            ->inCity($location->city)
            ->whereKeyNot($location->getKey())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(6)
            ->get();

        return view('ziyarah.show', [
            'location' => $location,
            'nearby' => $nearby,
        ]);
    }

    /**
     * Everything the phone has to fetch to hold the whole guide.
     *
     * `version` changes whenever any live page does, so the saved copy can
     * say honestly whether it is the current one. It is derived rather than
     * stored: a counter somebody has to remember to bump is a counter that
     * goes stale, and a stale version here means a pilgrim in Makkah
     * reading a page that was corrected a week ago.
     *
     * Withdrawal is the case worth being clear about: a withdrawn page
     * leaves this list, so a phone that re-checks stops holding it — but a
     * phone that never comes back online keeps the copy it saved. Nothing
     * can reach into a device in Mina. The screen says so rather than
     * implying otherwise.
     */
    public function offlineManifest(): JsonResponse
    {
        $locations = ZiyarahLocation::live()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'city', 'updated_at']);

        $version = $locations
            ->map(fn (ZiyarahLocation $l): string => $l->getKey().':'.($l->updated_at?->getTimestamp() ?? 0))
            ->join('|');

        return response()->json([
            'version' => $locations->isEmpty() ? 'empty' : substr(sha1($version), 0, 16),
            'locale' => app()->getLocale(),
            'count' => $locations->count(),
            'index' => route('ziyarah.index'),
            'urls' => $locations
                ->map(fn (ZiyarahLocation $l): string => route('ziyarah.show', $l->slug))
                ->prepend(route('ziyarah.index'))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Grouped in the order a pilgrim travels, not alphabetically.
     *
     * @param  Collection<int, ZiyarahLocation>  $locations
     * @return Collection<string, Collection<int, ZiyarahLocation>>
     */
    private function groupByCity(Collection $locations): Collection
    {
        return collect(ZiyarahLocation::CITIES)
            ->mapWithKeys(fn (string $city): array => [
                $city => $locations->where('city', $city)->values(),
            ])
            ->filter(fn (Collection $group): bool => $group->isNotEmpty());
    }
}
