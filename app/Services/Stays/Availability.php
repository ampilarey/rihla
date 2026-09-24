<?php

namespace App\Services\Stays;

use App\Exceptions\RoomNotAvailable;
use App\Models\BlockedDate;
use App\Models\Rate;
use App\Models\RoomType;
use App\Models\Stay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What a room costs on a given night, and whether anybody can have it —
 * §15.4 (Phase 9.2).
 *
 * Reads only. It answers questions and throws when the answer is no; it
 * never writes and never locks. {@see StayAllocator} is the only thing that
 * takes dates, and it calls this **inside** its row lock — which is the
 * whole reason this class does no locking of its own. A read here, outside
 * a lock, is a quote and a page; a read here inside the allocator's lock is
 * a decision.
 *
 * ## Nights, not days
 *
 * Everything is expressed in nights slept. A stay from the 3rd to the 5th
 * is two nights — the 3rd and the 4th — and the 5th belongs to whoever
 * comes next. Every method takes the half-open range `[$checkIn,
 * $checkOut)` and the arithmetic is done once, in {@see nights()}, so no
 * caller has a chance to get it wrong a second way.
 */
class Availability
{
    /**
     * The nights actually slept in `[$checkIn, $checkOut)`.
     *
     * Empty when check-out is on or before check-in, rather than throwing:
     * a date picker will produce that state while somebody is still
     * choosing, and a page asking "what would this cost" should get "zero
     * nights" rather than an exception.
     *
     * @return list<CarbonImmutable>
     */
    public function nights(CarbonInterface $checkIn, CarbonInterface $checkOut): array
    {
        $from = CarbonImmutable::parse($checkIn->toDateString());
        $until = CarbonImmutable::parse($checkOut->toDateString());

        $nights = [];

        for ($night = $from; $night->lessThan($until); $night = $night->addDay()) {
            $nights[] = $night;
        }

        return $nights;
    }

    /**
     * Price the stay, night by night.
     *
     * A night takes the rate of the season covering it, and the room's base
     * rate when no season does. Where two seasons overlap a night the one
     * that **starts later** wins: a year-round season with a new-year
     * override inside it is the shape guesthouses actually write, and the
     * narrower one is the answer they mean. Ordering by `starts_on`
     * ascending and letting each later season overwrite is that rule, and
     * it is deterministic — where "whichever the database returned first"
     * would quietly change with an index.
     */
    public function quote(RoomType $room, CarbonInterface $checkIn, CarbonInterface $checkOut): Quote
    {
        $nights = $this->nights($checkIn, $checkOut);

        if ($nights === []) {
            return Quote::of([], $this->currencyFor($room), $this->minimumNights($room, collect()));
        }

        $seasons = Rate::query()
            ->where('room_type_id', $room->getKey())
            ->covering($nights[0], $nights[count($nights) - 1]->addDay())
            ->orderBy('starts_on')
            ->get();

        $nightly = [];

        foreach ($nights as $night) {
            $rate = $room->base_rate_minor;

            foreach ($seasons as $season) {
                if ($season->covers($night)) {
                    $rate = $season->rate_minor;
                }
            }

            $nightly[$night->toDateString()] = (int) $rate;
        }

        return Quote::of($nightly, $this->currencyFor($room), $this->minimumNights($room, $seasons));
    }

    /**
     * How many of this room are already taken, per night.
     *
     * Only {@see Stay::OCCUPYING} counts. A *requested* stay takes nothing:
     * §15.2 decision 1 makes a request cost the customer nothing until a
     * real room is theirs, which means it cannot take the room from anybody
     * else either — two people may ask for the last room and the partner
     * decides.
     *
     * `$ignoring` exists for the one case that would otherwise deadlock the
     * arithmetic: re-checking a stay that is itself already holding these
     * dates, which must not be counted as its own competitor.
     *
     * @return array<string, int> date (Y-m-d) => how many taken
     */
    public function taken(
        RoomType $room,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?Stay $ignoring = null,
    ): array {
        $nights = $this->nights($checkIn, $checkOut);

        if ($nights === []) {
            return [];
        }

        $overlapping = Stay::query()
            ->where('room_type_id', $room->getKey())
            ->occupying()
            ->overlapping($nights[0], $nights[count($nights) - 1]->addDay())
            ->when(
                $ignoring?->exists,
                fn ($query) => $query->whereKeyNot($ignoring->getKey()),
            )
            ->get(['check_in', 'check_out']);

        $taken = [];

        foreach ($nights as $night) {
            $taken[$night->toDateString()] = $overlapping
                ->filter(fn (Stay $stay): bool => $this->stayCovers($stay, $night))
                ->count();
        }

        return $taken;
    }

    /**
     * Nights this room is not for sale at all.
     *
     * Separate from occupancy on purpose. A blocked night is not freed when
     * a hold lapses, and counting it as one of the `quantity` taken would
     * make the same number mean two different things depending on the day.
     *
     * @return list<string> dates as Y-m-d
     */
    public function blocked(RoomType $room, CarbonInterface $checkIn, CarbonInterface $checkOut): array
    {
        $nights = $this->nights($checkIn, $checkOut);

        if ($nights === []) {
            return [];
        }

        return BlockedDate::query()
            ->where('room_type_id', $room->getKey())
            ->whereDate('date', '>=', $nights[0]->toDateString())
            ->whereDate('date', '<=', $nights[count($nights) - 1]->toDateString())
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
            ->all();
    }

    /**
     * Can this room be taken for these nights?
     *
     * Throws rather than returning false, so no caller can ignore the
     * answer, and throws the *specific* reason: a visitor told "not
     * available" for a room that is merely under its minimum stay will pick
     * different dates; one told the same about a room that is full will
     * not come back.
     *
     * @throws RoomNotAvailable
     */
    public function assertAvailable(
        RoomType $room,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?Stay $ignoring = null,
    ): void {
        $nights = $this->nights($checkIn, $checkOut);

        if ($nights === []) {
            throw RoomNotAvailable::forTooFewNights($room, 0, 1);
        }

        // Zero means nobody has entered a quantity, not that the room is
        // unlimited. Refusing every night of such a room is deliberate:
        // selling a room whose count nobody recorded is exactly what this
        // class exists to prevent.
        if ($room->quantity < 1) {
            throw RoomNotAvailable::becauseQuantityIsUnset($room);
        }

        $minimum = $this->minimumNights($room, $this->seasonsFor($room, $checkIn, $checkOut));

        if (count($nights) < $minimum) {
            throw RoomNotAvailable::forTooFewNights($room, count($nights), $minimum);
        }

        $blocked = $this->blocked($room, $checkIn, $checkOut);

        if ($blocked !== []) {
            throw RoomNotAvailable::blocked($room, CarbonImmutable::parse($blocked[0]));
        }

        foreach ($this->taken($room, $checkIn, $checkOut, $ignoring) as $date => $count) {
            if ($count >= $room->quantity) {
                throw RoomNotAvailable::taken($room, CarbonImmutable::parse($date), $room->quantity);
            }
        }
    }

    /** The boolean form, for a page that is listing rather than deciding. */
    public function isAvailable(
        RoomType $room,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?Stay $ignoring = null,
    ): bool {
        try {
            $this->assertAvailable($room, $checkIn, $checkOut, $ignoring);

            return true;
        } catch (RoomNotAvailable) {
            return false;
        }
    }

    /**
     * A stay covers a night when the night is on or after check-in and
     * strictly before check-out — the half-open rule, in one place.
     */
    private function stayCovers(Stay $stay, CarbonInterface $night): bool
    {
        $date = $night->toDateString();

        return $date >= $stay->check_in->toDateString()
            && $date < $stay->check_out->toDateString();
    }

    /** @return Collection<int, Rate> */
    private function seasonsFor(RoomType $room, CarbonInterface $checkIn, CarbonInterface $checkOut): Collection
    {
        $nights = $this->nights($checkIn, $checkOut);

        if ($nights === []) {
            return collect();
        }

        return Rate::query()
            ->where('room_type_id', $room->getKey())
            ->covering($nights[0], $nights[count($nights) - 1]->addDay())
            ->orderBy('starts_on')
            ->get();
    }

    /**
     * The property's minimum, unless a season covering these nights raises
     * it.
     *
     * The **highest** season minimum wins rather than the last one written.
     * A five-night minimum over new year exists to stop a two-night
     * booking, and letting an adjacent ordinary season lower it back to two
     * would defeat the only reason anybody sets one.
     *
     * @param  Collection<int, Rate>  $seasons
     */
    private function minimumNights(RoomType $room, Collection $seasons): int
    {
        $fromSeasons = $seasons
            ->pluck('min_nights')
            ->filter(fn (?int $minimum): bool => $minimum !== null && $minimum > 0);

        return max(
            1,
            (int) $room->property->min_nights,
            $fromSeasons->isEmpty() ? 1 : (int) $fromSeasons->max(),
        );
    }

    /**
     * The currency belongs to the building. No `?->` fallback: a room type
     * that cannot reach its property is a broken row, and quietly quoting
     * it in USD would put a dollar sign on a Malé rental priced in rufiyaa.
     * `properties.id` is NOT NULL and constrained, so the building is there.
     */
    private function currencyFor(RoomType $room): string
    {
        return $room->property->currency;
    }
}
