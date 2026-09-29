<?php

namespace App\Services\Stays;

use App\Exceptions\CommissionNotSet;
use App\Models\Partner;
use App\Models\Stay;

/**
 * What Rihla earns on a marketplace stay, frozen onto it — §16.9, ADR 0008.
 *
 * Only a stay the marketplace brought carries a commission (decision 2): a
 * booking a host enters themselves is free, which is what keeps their
 * calendar honest. The rate is worked out when the stay is made and
 * written onto it, so a host's percentage changing next month does not
 * rewrite what this stay earned.
 *
 * The rate, in order:
 *
 * - **Rihla's own rooms** — nothing; it would be paying itself.
 * - **A net-rate partner** — nothing as a percentage: Rihla's margin is
 *   already inside the price it sells at, which is what net rate means.
 * - **Anyone else** — their own percentage, else the configured default,
 *   else the booking is refused ({@see CommissionNotSet}).
 */
final class Commission
{
    /** The `stays.source` of a booking the marketplace brought. */
    public const MARKETPLACE = 'marketplace';

    public function rateFor(Partner $partner): ?int
    {
        if ($partner->is_rihla || $partner->pricing_model === Partner::NET_RATE) {
            return 0;
        }

        if ($partner->commission_pct !== null) {
            return $partner->commission_pct;
        }

        $default = config('marketplace.default_commission_pct');

        return $default === null ? null : (int) $default;
    }

    /** @throws CommissionNotSet */
    public function assertSet(Partner $partner): int
    {
        return $this->rateFor($partner) ?? throw CommissionNotSet::forHost($partner->name);
    }

    /**
     * Freeze the split onto the stay. Commission is rounded down to the
     * minor unit, so the host is never short of a fraction a rounding
     * rule gave to Rihla.
     *
     * @throws CommissionNotSet
     */
    public function snapshot(Stay $stay, Partner $partner): Stay
    {
        $pct = $this->assertSet($partner);
        $commission = intdiv($stay->total_minor * $pct, 100);

        $stay->forceFill([
            'commission_pct_snapshot' => $pct,
            'commission_minor' => $commission,
            'host_net_minor' => $stay->total_minor - $commission,
            'settlement_model_snapshot' => $partner->settlement_model,
        ])->save();

        return $stay;
    }
}
