<?php

namespace App\Services\Hosts;

use App\Models\Partner;
use App\Models\Payment;
use App\Models\Stay;
use App\Services\Stays\Commission;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * What a host earned in a month — §16.6 `Pages\Earnings`, §16.9.
 *
 * Computed at read time from the stays themselves, never stored: stays that
 * ended in the month (checked out, or due to have), split into what the
 * marketplace brought — gross, Rihla's commission, the host's net — and
 * what the host brought themselves. Then who holds the money: what the
 * guests paid Rihla online against what Rihla's commission comes to. Every
 * figure is per currency; nothing is summed across two.
 */
class Earnings
{
    /**
     * @return array<string, array{
     *     marketplace_count: int, marketplace_gross: Money, commission: Money, host_net: Money,
     *     direct_count: int, direct_gross: Money,
     *     paid_to_rihla: Money, paid_here: Money, rihla_holds_for_host: Money, commission_outstanding: Money
     * }>
     */
    public function forMonth(Partner $host, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $until = $month->endOfMonth();

        $stays = Stay::query()
            ->whereHas('property', fn ($query) => $query->where('partner_id', $host->getKey()))
            ->whereIn('status', [Stay::CHECKED_IN, Stay::COMPLETED])
            ->whereBetween('check_out', [$from->toDateString(), $until->toDateString()])
            ->with('payments')
            ->get();

        $rows = [];

        foreach ($stays->groupBy('currency') as $currency => $group) {
            $currency = (string) $currency;
            $money = fn (int $minor): Money => Money::ofMinor($minor, $currency);

            $marketplace = $group->filter(fn (Stay $stay): bool => $stay->source === Commission::MARKETPLACE);
            $direct = $group->reject(fn (Stay $stay): bool => $stay->source === Commission::MARKETPLACE);

            $sum = function ($stays, string $collector): int {
                return (int) $stays->sum(fn (Stay $stay): int => (int) $stay->payments
                    ->where('status', Payment::SUCCEEDED)
                    ->where('collected_by', $collector)
                    ->sum('amount_minor'));
            };

            $commission = (int) $marketplace->sum('commission_minor');
            $toRihla = $sum($marketplace, Payment::COLLECTED_BY_RIHLA);

            $rows[$currency] = [
                'marketplace_count' => $marketplace->count(),
                'marketplace_gross' => $money((int) $marketplace->sum('total_minor')),
                'commission' => $money($commission),
                'host_net' => $money((int) $marketplace->sum('host_net_minor')),
                'direct_count' => $direct->count(),
                'direct_gross' => $money((int) $direct->sum('total_minor')),
                'paid_to_rihla' => $money($toRihla),
                'paid_here' => $money($sum($group, Payment::COLLECTED_BY_HOST)),
                // Beyond the commission, what guests paid Rihla is the host's.
                'rihla_holds_for_host' => $money(max(0, $toRihla - $commission)),
                // Commission not covered by what reached Rihla online.
                'commission_outstanding' => $money(max(0, $commission - $toRihla)),
            ];
        }

        ksort($rows);

        return $rows;
    }
}
