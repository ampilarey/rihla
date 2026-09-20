<?php

namespace App\Support;

use App\Models\Departure;
use App\Models\Package;
use App\Models\PriceTier;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The package finder's criteria, read from the query string.
 *
 * Every option offered is derived from departures that actually exist: the
 * month list is the months something departs in, the budget bands come from
 * the real lead prices. A filter offering "December" when nothing departs in
 * December wastes the one interaction a visitor gives you, and a budget band
 * nobody's prices fall into is worse — it reads as "nothing here for me".
 *
 * @implements Arrayable<string, mixed>
 */
final class PackageFilters implements Arrayable
{
    private function __construct(
        public readonly ?string $month,
        public readonly ?int $maxBudgetMinor,
        public readonly ?int $maxNights,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $month = $request->query('month');

        return new self(
            // YYYY-MM, and only if it parses. Anything else is ignored
            // rather than erroring: a hand-edited URL should show packages,
            // not a validation page.
            month: is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : null,
            // The visitor types whole rufiyaa; prices are stored in laari.
            // The conversion happens here, at the boundary, and nowhere
            // deeper — see App\Support\Money and [R-7].
            maxBudgetMinor: ($budget = self::positiveInt($request->query('budget'))) !== null
                ? Money::ofMajor($budget)->minor
                : null,
            maxNights: self::positiveInt($request->query('nights')),
        );
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->month !== null) {
            $start = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();

            $query->whereHas('departures', function ($departures) use ($start): void {
                /** @var Builder<Departure> $departures */
                $departures->published()
                    ->whereBetween('date_start', [$start, $start->copy()->endOfMonth()]);
            });
        }

        if ($this->maxNights !== null) {
            $query->where('nights', '<=', $this->maxNights);
        }

        if ($this->maxBudgetMinor !== null) {
            $query->whereHas('departures', function ($departures): void {
                /** @var Builder<Departure> $departures */
                $departures->published()->upcoming()->whereHas('priceTiers', function ($tiers): void {
                    /** @var Builder<PriceTier> $tiers */
                    $tiers->where('amount_minor', '<=', $this->maxBudgetMinor);
                });
            });
        }

        return $query;
    }

    public function isEmpty(): bool
    {
        return $this->month === null && $this->maxBudgetMinor === null && $this->maxNights === null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'month' => $this->month,
            'budget' => $this->maxBudgetMinor,
            'nights' => $this->maxNights,
        ];
    }
}
