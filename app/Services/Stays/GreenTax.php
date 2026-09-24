<?php

namespace App\Services\Stays;

use App\Models\Partner;
use App\Models\Property;
use App\Support\Money;

/**
 * The Maldives green tax, as a line somebody can read — §15.2 decision 5.
 *
 * ## What was missing
 *
 * `partners.green_tax_mode` has existed since §15.4 and says whether the
 * tax is inside the room rate or collected at the property. Nothing said
 * **how much**. So a foreign guest read "green tax is paid at the
 * guesthouse", agreed, and found out the size of it at check-out — which
 * is the precise failure the mode was introduced to prevent. Four people
 * for five nights is twenty guest-nights; at any plausible rate that is
 * real money to meet by surprise in a currency you do not hold.
 *
 * And `included` was stored and never acted on, so both modes produced
 * exactly the same page. A mode that changes nothing is worse than no
 * mode: it reads as a considered answer.
 *
 * ## It never moves Rihla's total
 *
 * Deliberately, in both modes. `included` means the partner's rate
 * already carries it, so adding it again would double-charge; `at_property`
 * means the guesthouse collects it, so it is not Rihla's money to take.
 * This is a **disclosure**, not a charge — which is why it is computed
 * here and not inside {@see Quote::total()}.
 *
 * ## An unset amount is not zero
 *
 * `config('stays.green_tax.amount_minor')` is null until the owner states
 * it, and null means *nobody has told this system the figure*. Rendering
 * that as "USD 0.00" would be a quote nobody checked. Every method here
 * returns null instead, and the pages say the tax applies without naming
 * a number.
 */
final class GreenTax
{
    /**
     * What the tax comes to for a party over a stay, or null if the
     * amount has never been stated.
     *
     * Per guest per night, children included — the real exemptions are set
     * by a government and vary, and encoding a guess about who is exempt
     * would understate somebody's bill as confidently as overstating it.
     * If Rihla needs an age rule, that is a decision to record, not an
     * assumption to make here.
     */
    public function forParty(int $guests, int $nights): ?Money
    {
        $amount = $this->perGuestPerNight();

        if ($amount === null || $guests < 1 || $nights < 1) {
            return null;
        }

        return Money::ofMinor($amount->minor * $guests * $nights, $amount->currency);
    }

    /** The stated rate, or null if nobody has stated one. */
    public function perGuestPerNight(): ?Money
    {
        $minor = config('stays.green_tax.amount_minor');

        if ($minor === null) {
            return null;
        }

        return Money::ofMinor((int) $minor, (string) config('stays.green_tax.currency', 'USD'));
    }

    /** Is this property's tax collected at the door rather than by Rihla? */
    public function isCollectedAtProperty(Property $property): bool
    {
        // `->`, not `?->`: `??` already suppresses the null, and the
        // nullsafe would be dead syntax static analysis reports. The
        // fallback is the point — a property whose partner row has gone
        // is treated as collecting at the door, which is the answer that
        // warns somebody rather than the one that reassures them.
        return ($property->partner->green_tax_mode ?? Partner::GREEN_TAX_AT_PROPERTY)
            === Partner::GREEN_TAX_AT_PROPERTY;
    }

    /**
     * What to freeze into `stays.rate_snapshot`.
     *
     * The same reason the nightly rates and the policy are frozen: this is
     * a config value, it will move when a government moves it, and a
     * customer is owed what they were shown on the day. A stay agreed when
     * the tax was one figure must not be re-read later at another.
     *
     * Records the mode too, because "paid at the property" is the half
     * that tells somebody reading a dispute whether Rihla ever held this
     * money.
     *
     * @return array<string, mixed>
     */
    public function snapshotFor(Property $property, int $guests, int $nights): array
    {
        $rate = $this->perGuestPerNight();
        $total = $this->forParty($guests, $nights);

        return [
            'mode' => $property->partner->green_tax_mode ?? Partner::GREEN_TAX_AT_PROPERTY,
            'guests' => $guests,
            'nights' => $nights,
            // Null all the way down when nobody has stated the amount, so a
            // snapshot can never be read as "the tax was zero that day".
            'per_guest_per_night_minor' => $rate?->minor,
            'currency' => $rate?->currency,
            'total_minor' => $total?->minor,
        ];
    }
}
