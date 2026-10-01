<?php

namespace App\Support;

use App\Models\Property;
use App\Services\Stays\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * What a visitor asked the guesthouse list for — §15.4 (Phase 9.4).
 *
 * A value object built from the query string, so filtering is a URL:
 * shareable, bookmarkable, readable by a crawler, and working with no
 * JavaScript. The package finder does the same, for the same reasons.
 *
 * Every field is optional and every bad value is dropped rather than
 * rejected. A hand-edited URL shows guesthouses, not a validation page —
 * the visitor did not fill in a form, and there is nothing for them to
 * correct.
 */
final class StayFilters
{
    /** The orders a search can be put in — §16.7. By rating arrives with reviews (Phase 14). */
    public const SORTS = ['recommended', 'price', 'newest', 'rating'];

    /**
     * @param  list<string>  $kinds
     */
    private function __construct(
        public readonly ?string $island,
        public readonly ?CarbonImmutable $checkIn,
        public readonly ?CarbonImmutable $checkOut,
        public readonly ?int $guests,
        public readonly ?string $atoll = null,
        public readonly array $kinds = [],
        public readonly ?int $priceMin = null,
        public readonly ?int $priceMax = null,
        public readonly string $sort = 'recommended',
        public readonly string $audience = Audience::TOURIST,
        public readonly bool $audienceChosen = false,
    ) {}

    /**
     * The longest stay a search or a booking may ask about — site audit.
     * Availability holds one object per night per room, so a request for
     * 2026 to 2400 was 194 MB and two seconds *per room*, and the URL took
     * any date it could parse.
     */
    public const MAX_NIGHTS = 60;

    public static function fromRequest(Request $request): self
    {
        $checkIn = self::date($request->query('from'));
        $checkOut = self::date($request->query('to'));

        // Both or neither. A check-in with no check-out cannot price
        // anything and cannot be checked for availability, so holding half
        // of it would make the page claim to have filtered on dates when it
        // had not.
        if ($checkIn === null || $checkOut === null || $checkOut->lessThanOrEqualTo($checkIn)
            || $checkIn->diffInDays($checkOut) > self::MAX_NIGHTS) {
            $checkIn = $checkOut = null;
        }

        $island = $request->query('island');
        $atoll = $request->query('atoll');
        $guests = $request->query('guests');
        $sort = $request->query('sort');
        $audience = $request->query('audience');

        // A visitor who has said which they are is believed; otherwise the
        // language they are reading in is the best guess there is — §16.7.
        $chosen = is_string($audience) && Audience::isValid($audience);

        $kinds = array_values(array_intersect(
            Property::KINDS,
            array_filter((array) $request->query('kind', []), 'is_string'),
        ));

        $min = self::wholeAmount($request->query('price_min'));
        $max = self::wholeAmount($request->query('price_max'));

        // Swapped rather than dropped: "500 to 100" means "100 to 500"
        // to anybody who typed it.
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return new self(
            island: is_string($island) && trim($island) !== '' ? trim($island) : null,
            checkIn: $checkIn,
            checkOut: $checkOut,
            guests: is_numeric($guests) && (int) $guests >= 1 ? min(30, (int) $guests) : null,
            atoll: is_string($atoll) && trim($atoll) !== '' ? trim($atoll) : null,
            kinds: $kinds,
            priceMin: $min,
            priceMax: $max,
            sort: is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : 'recommended',
            audience: $chosen ? $audience : Audience::fromLocale(app()->getLocale()),
            audienceChosen: $chosen,
        );
    }

    private static function wholeAmount(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? min(10_000_000, (int) $value) : null;
    }

    public function hasPriceRange(): bool
    {
        return $this->priceMin !== null || $this->priceMax !== null;
    }

    /** Nothing asked for at all — the page says so instead of "0 results". */
    public function isEmpty(): bool
    {
        return $this->island === null
            && $this->checkIn === null
            && $this->guests === null
            && $this->atoll === null
            && $this->kinds === []
            && ! $this->hasPriceRange();
    }

    public function hasDates(): bool
    {
        return $this->checkIn !== null && $this->checkOut !== null;
    }

    public function nights(): int
    {
        return $this->hasDates() ? (int) $this->checkIn->diffInDays($this->checkOut) : 0;
    }

    /**
     * Narrow the query by the things a column can answer.
     *
     * Dates are deliberately **not** applied here. Availability is a
     * per-night count across another table and a room's `quantity`; doing
     * it in SQL would mean a correlated subquery per night, and doing it
     * wrong would mean the list and the property page disagreeing about
     * whether somewhere is free. The controller asks
     * {@see Availability} — the same class the booking
     * path uses — and filters the result.
     *
     * @param  Builder<Property>  $query
     * @return Builder<Property>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->island !== null) {
            $query->where('island', $this->island);
        }

        if ($this->guests !== null) {
            $query->whereHas('roomTypes', fn (Builder $rooms) => $rooms->where('sleeps', '>=', $this->guests));
        }

        if ($this->atoll !== null) {
            $query->where('atoll', $this->atoll);
        }

        if ($this->kinds !== []) {
            $query->whereIn('kind', $this->kinds);
        }

        return $query;
    }

    /** The query string this filter came from, for a canonical link. */
    public function toQuery(): array
    {
        return array_filter([
            'island' => $this->island,
            'from' => $this->checkIn?->toDateString(),
            'to' => $this->checkOut?->toDateString(),
            'guests' => $this->guests,
            // Carried only when the visitor chose it, so a link shared from
            // a Dhivehi page does not pin an English reader to local prices.
            'audience' => $this->audienceChosen ? $this->audience : null,
        ], fn ($value): bool => $value !== null);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', trim($value));
        } catch (\Throwable) {
            return null;
        }

        // createFromFormat returns false rather than throwing on some bad
        // input, and Carbon's own exception is not guaranteed across
        // versions — so the result is checked as well as the call guarded.
        return $date instanceof CarbonImmutable ? $date->startOfDay() : null;
    }
}
