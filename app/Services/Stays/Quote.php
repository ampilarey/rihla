<?php

namespace App\Services\Stays;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * What a room costs for a set of nights, night by night — §15.4.
 *
 * The per-night breakdown is not decoration. It is what gets frozen into
 * `stays.rate_snapshot`, so that a season edited next week cannot move the
 * price of a stay somebody has already agreed to. A single total would
 * freeze the number without the reasoning, and the first question anybody
 * asks about a disputed invoice is which night was charged at what.
 *
 * Immutable, and built only by {@see Availability::quote()}.
 */
final class Quote
{
    /**
     * @param  array<string, int>  $nightly  date (Y-m-d) => rate in minor units
     */
    private function __construct(
        public readonly array $nightly,
        public readonly string $currency,
        public readonly int $minimumNights,
    ) {}

    /**
     * @param  array<string, int>  $nightly
     */
    public static function of(array $nightly, string $currency, int $minimumNights = 1): self
    {
        return new self($nightly, strtoupper($currency), max(1, $minimumNights));
    }

    public function nights(): int
    {
        return count($this->nightly);
    }

    public function total(): Money
    {
        return Money::ofMinor(array_sum($this->nightly), $this->currency);
    }

    /**
     * The deposit, rounded **up** to the minor unit.
     *
     * Up rather than down, and stated rather than left to intdiv: 30% of
     * USD 85.05 is 2551.5 cents, and rounding down would leave Rihla
     * collecting a deposit half a cent short of the policy it printed on
     * the page. The balance is the remainder, so nothing is lost either
     * way — but the number that gets charged should be the one that
     * satisfies the stated percentage.
     */
    public function deposit(int $percent): Money
    {
        $percent = max(0, min(100, $percent));

        return Money::ofMinor(
            (int) ceil($this->total()->minor * $percent / 100),
            $this->currency,
        );
    }

    /** What goes into `stays.rate_snapshot`. */
    public function snapshot(): array
    {
        return [
            'currency' => $this->currency,
            'nightly' => $this->nightly,
            'total_minor' => $this->total()->minor,
            'minimum_nights' => $this->minimumNights,
            // The day the quote was made, so a dispute can be read without
            // guessing which season was live at the time.
            'quoted_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }
}
