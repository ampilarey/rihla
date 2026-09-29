<?php

namespace App\Services\Stays;

use App\Models\PropertyAddon;
use App\Models\Stay;
use App\Models\StayCharge;
use Illuminate\Support\Collection;

/**
 * The add-ons a guest picked, onto the stay's bill — §16.14, §16 Phase 15.
 *
 * Each becomes an `extra` line at the price of the day: the add-on can be
 * repriced or withdrawn tomorrow and this bill still says what was agreed.
 * Paid at the property, with the rest of the extras — the room total, the
 * deposit and Rihla's commission are the room's alone and are not touched.
 *
 * Only the stay's own listing's add-ons, only active ones, and only those
 * priced for the stay's audience: an id from the request is a wish, never
 * an instruction.
 */
class StayAddons
{
    /**
     * @param  array<int, mixed>  $ids
     * @return Collection<int, StayCharge>
     */
    public function attach(Stay $stay, array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return collect();
        }

        $guests = (int) $stay->adults + (int) $stay->children;

        return PropertyAddon::query()
            ->where('property_id', $stay->property_id)
            ->where('is_active', true)
            ->whereKey($ids)
            ->with('property')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (PropertyAddon $addon) use ($stay, $guests): ?StayCharge {
                $each = $addon->priceFor($stay->audience);

                if ($each === null || $each->currency !== $stay->currency) {
                    return null;
                }

                return $stay->charges()->create([
                    'kind' => StayCharge::EXTRA,
                    'description' => (string) $addon->getTranslation('name', 'en'),
                    'quantity' => $addon->quantityFor($guests),
                    'unit_minor' => $each->minor,
                    'currency' => $stay->currency,
                ]);
            })
            ->filter()
            ->values();
    }

    /**
     * What the listing offers this audience, for the booking form.
     *
     * @param  Collection<int, PropertyAddon>  $addons
     * @return Collection<int, PropertyAddon>
     */
    public static function offered(Collection $addons, string $audience): Collection
    {
        return $addons->filter(fn (PropertyAddon $addon): bool => $addon->isOfferedTo($audience))->values();
    }
}
