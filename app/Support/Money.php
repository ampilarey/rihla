<?php

namespace App\Support;

/**
 * Money, in integer minor units.
 *
 * The plan's [R-7]: every amount is laari for MVR and cents for USD, held in
 * an integer column. Never a float — 0.1 + 0.2 is not 0.3 in binary floating
 * point, and a price list that is out by a laari per row is the kind of bug
 * that is found by a customer. Never a decimal-as-string either, because the
 * first thing anything does with it is cast it.
 *
 * This class exists so the conversion happens in one place. A price is read
 * from the database as an integer and formatted for display here; nothing
 * else multiplies or divides by 100.
 */
final class Money
{
    /** Currencies this application quotes, and how many minor units each has. */
    private const MINOR_UNITS = [
        'MVR' => 100,   // laari
        'USD' => 100,   // cents
    ];

    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {}

    public static function ofMinor(int $minor, string $currency = 'MVR'): self
    {
        return new self($minor, strtoupper($currency));
    }

    /**
     * From a whole-unit amount — 28500 rufiyaa becomes 2,850,000 laari.
     *
     * Takes an int, not a float, on purpose: a caller holding 28500.50 has to
     * decide what to do about the half before it gets here, rather than
     * silently losing it to a cast.
     */
    public static function ofMajor(int $major, string $currency = 'MVR'): self
    {
        return new self($major * self::factor($currency), strtoupper($currency));
    }

    private static function factor(string $currency): int
    {
        return self::MINOR_UNITS[strtoupper($currency)] ?? 100;
    }

    /** The whole-unit part, rounded down. For display only. */
    public function major(): int
    {
        return intdiv($this->minor, self::factor($this->currency));
    }

    /**
     * "MVR 28,500" — no minor units when the amount is whole, because Umrah
     * prices are quoted in whole rufiyaa and ".00" on every card is noise.
     *
     * Deliberately number_format() rather than NumberFormatter. The intl
     * extension is not installed on the CI runners and nobody has confirmed
     * it on the cPanel account, so depending on it would mean money
     * formatting fataling on a host this application cannot inspect — and
     * the first draft of this class did exactly that, passing locally where
     * intl happens to be present.
     *
     * Nothing is lost by it: both locales display these amounts in Western
     * digits with a comma thousands separator, which is what this does.
     */
    public function format(): string
    {
        $factor = self::factor($this->currency);
        $decimals = $this->minor % $factor === 0 ? 0 : 2;

        return $this->currency.' '.number_format($this->minor / $factor, $decimals, '.', ',');
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
