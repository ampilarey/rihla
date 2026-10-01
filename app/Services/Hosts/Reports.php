<?php

namespace App\Services\Hosts;

use App\Models\Partner;
use App\Models\PropertyUnit;
use App\Models\Stay;
use App\Services\Stays\Commission;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A host's reports for a date range — §16.10.
 *
 * Computed at read time from the stays, never stored. A stay that crosses
 * the edge of the range counts only for its nights inside it, and its
 * money is shared out by those nights — so two months' reports add up to
 * the stay, not to twice it. Money is per currency, never summed across.
 */
class Reports
{
    /** Statuses whose nights were, or are, somebody in a room. */
    public const COUNTED = [Stay::HELD, Stay::CONFIRMED, Stay::CHECKED_IN, Stay::COMPLETED];

    /**
     * @return array{
     *     nights: int,
     *     occupancy: list<array{listing: string, occupied: int, available: int, rate: ?float}>,
     *     money: array<string, array{room_nights: int, revenue: Money, average_rate: ?Money, by_source: array<string, Money>, by_audience: array<string, Money>, commission: Money, tgst: ?Money, gst: ?Money}>,
     *     green_tax: array<string, Money>,
     *     tax_rates: array{tgst: ?float, gst: ?float}
     * }
     */
    public function forRange(Partner $host, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $from = $from->startOfDay();
        $until = $until->startOfDay();                 // inclusive last night
        $end = $until->addDay();                        // exclusive
        $nights = (int) $from->diffInDays($end);

        $stays = Stay::query()
            ->with('property')
            ->whereHas('property', fn ($query) => $query->where('partner_id', $host->getKey()))
            ->whereIn('status', self::COUNTED)
            ->overlapping($from, $end)
            ->get();

        return [
            'nights' => $nights,
            'occupancy' => $this->occupancy($host, $stays, $from, $end, $nights),
            'money' => $this->money($stays, $from, $end),
            'green_tax' => $this->greenTax($stays, $from, $end),
            'tax_rates' => [
                'tgst' => config('stays.tax.tgst_pct'),
                'gst' => config('stays.tax.gst_pct'),
            ],
        ];
    }

    /** A stay's nights that fall inside [from, end). */
    public static function nightsWithin(Stay $stay, CarbonImmutable $from, CarbonImmutable $end): int
    {
        $start = $stay->check_in->greaterThan($from) ? CarbonImmutable::parse($stay->check_in) : $from;
        $stop = $stay->check_out->lessThan($end) ? CarbonImmutable::parse($stay->check_out) : $end;

        return max(0, (int) $start->diffInDays($stop, false));
    }

    /** Its money shared out by the nights inside the range. */
    private static function share(Stay $stay, int $minor, int $within): int
    {
        return $stay->nights > 0 ? intdiv($minor * $within, $stay->nights) : 0;
    }

    /**
     * Occupied unit-nights over units × nights, per listing. Rooms that
     * exist as units count as units; a listing with none counts the room
     * quantities it sells.
     *
     * @param  Collection<int, Stay>  $stays
     * @return list<array{listing: string, occupied: int, available: int, rate: ?float}>
     */
    private function occupancy(Partner $host, Collection $stays, CarbonImmutable $from, CarbonImmutable $end, int $nights): array
    {
        $rows = [];

        foreach ($host->properties()->with('roomTypes')->get() as $property) {
            $units = PropertyUnit::query()->where('property_id', $property->getKey())->where('is_active', true)->count();
            $rooms = $units > 0 ? $units : (int) $property->roomTypes->sum('quantity');
            $available = $rooms * $nights;

            $occupied = (int) $stays
                ->where('property_id', $property->getKey())
                ->sum(fn (Stay $stay): int => self::nightsWithin($stay, $from, $end));

            $rows[] = [
                'listing' => (string) $property->name,
                'occupied' => $occupied,
                'available' => $available,
                'rate' => $available > 0 ? round($occupied / $available * 100, 1) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Stay>  $stays
     * @return array<string, array<string, mixed>>
     */
    private function money(Collection $stays, CarbonImmutable $from, CarbonImmutable $end): array
    {
        $rows = [];

        foreach ($stays->groupBy('currency') as $currency => $group) {
            $currency = (string) $currency;
            $money = fn (int $minor): Money => Money::ofMinor($minor, $currency);

            $roomNights = 0;
            $revenue = 0;
            $commission = 0;
            $bySource = [];
            $byAudience = [];

            foreach ($group as $stay) {
                $within = self::nightsWithin($stay, $from, $end);
                $share = self::share($stay, (int) $stay->total_minor, $within);

                $roomNights += $within;
                $revenue += $share;
                $commission += $stay->source === Commission::MARKETPLACE ? self::share($stay, (int) $stay->commission_minor, $within) : 0;

                $source = $stay->source === Commission::MARKETPLACE ? 'Rihla marketplace' : ucfirst(str_replace('_', ' ', (string) ($stay->source ?: 'direct')));
                $bySource[$source] = ($bySource[$source] ?? 0) + $share;
                $byAudience[$stay->audience] = ($byAudience[$stay->audience] ?? 0) + $share;
            }

            ksort($bySource);
            ksort($byAudience);

            $rows[$currency] = [
                'room_nights' => $roomNights,
                'revenue' => $money($revenue),
                'average_rate' => $roomNights > 0 ? $money(intdiv($revenue, $roomNights)) : null,
                'by_source' => array_map($money, $bySource),
                'by_audience' => array_map($money, $byAudience),
                'commission' => $money($commission),
                // Prices include the tax: revenue ÷ (1 + rate) × rate. Only
                // when somebody has stated the rate.
                'tgst' => $this->included($revenue, config('stays.tax.tgst_pct'), $currency),
                'gst' => $this->included($revenue, config('stays.tax.gst_pct'), $currency),
            ];
        }

        ksort($rows);

        return $rows;
    }

    private function included(int $revenue, mixed $pct, string $currency): ?Money
    {
        if ($pct === null) {
            return null;
        }

        // Integer arithmetic, the percentage carried to two places: the
        // one float on a money figure in the tree (site audit).
        $hundredths = (int) round((float) $pct * 100);

        return Money::ofMinor(intdiv($revenue * $hundredths, 10000 + $hundredths), $currency);
    }

    /**
     * Green Tax on tourist stays for the nights in the range, from each
     * stay's own snapshot — the figure the MIRA return asks for.
     *
     * @param  Collection<int, Stay>  $stays
     * @return array<string, Money>
     */
    private function greenTax(Collection $stays, CarbonImmutable $from, CarbonImmutable $end): array
    {
        $totals = [];

        foreach ($stays as $stay) {
            $tax = (array) ($stay->rate_snapshot['green_tax'] ?? []);

            if (! ($tax['applies'] ?? false) || ! isset($tax['per_guest_per_night_minor'], $tax['currency'])) {
                continue;
            }

            $guests = (int) ($tax['guests'] ?? ($stay->adults + $stay->children));
            $currency = (string) $tax['currency'];
            $totals[$currency] = ($totals[$currency] ?? 0)
                + $guests * self::nightsWithin($stay, $from, $end) * (int) $tax['per_guest_per_night_minor'];
        }

        ksort($totals);

        $money = [];

        foreach ($totals as $currency => $minor) {
            $money[$currency] = Money::ofMinor($minor, $currency);
        }

        return $money;
    }
}
