<?php

namespace App\Support;

use App\Models\ExchangeRate;
use Carbon\CarbonInterface;

/**
 * Exchange rates — batch C from Hijaz, as the owner chose: prices and
 * balances stay in rufiyaa, a payment may arrive in dollars or riyals and
 * is credited at the rate Finance records that day, and the site may show
 * what a price comes to in the other two.
 *
 * **A rate is never invented.** The table Finance keeps is the source; the
 * `finance.rates` config (minor units of the base per whole foreign unit,
 * as it always was) is the fallback for a host that has not opened the
 * screen yet; and where neither names a rate, there is none: the form
 * asks for one, the site shows no equivalent, and the profit report keeps
 * its currencies apart, exactly as before.
 */
final class Rates
{
    /** @return list<string> */
    public static function currencies(): array
    {
        $currencies = array_map('strtoupper', (array) config('finance.currencies', ['MVR', 'USD', 'SAR']));

        return array_values(array_unique([self::base(), ...$currencies]));
    }

    public static function base(): string
    {
        return strtoupper((string) config('finance.rates.to', 'MVR'));
    }

    /** The line that applies today, or on the day given. */
    public static function current(string $currency, ?CarbonInterface $on = null): ?ExchangeRate
    {
        $currency = strtoupper($currency);

        if ($currency === self::base()) {
            return null;
        }

        return ExchangeRate::query()
            ->where('currency', $currency)
            ->where('to_currency', self::base())
            ->whereDate('effective_on', '<=', ($on ?? now())->toDateString())
            ->orderByDesc('effective_on')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Units of the base currency one unit of the foreign currency is worth,
     * or null when nobody has said.
     */
    public static function rate(string $currency, ?CarbonInterface $on = null): ?float
    {
        $currency = strtoupper($currency);

        if ($currency === self::base()) {
            return 1.0;
        }

        $line = self::current($currency, $on);

        if ($line !== null) {
            return $line->rate;
        }

        $configured = config('finance.rates.'.$currency);

        return is_numeric($configured) && (float) $configured > 0 ? (float) $configured / 100 : null;
    }

    /** When the rate in use was set, or null for one from config with no date. */
    public static function asOf(string $currency): ?CarbonInterface
    {
        $line = self::current($currency);

        if ($line !== null) {
            return $line->effective_on;
        }

        $asOf = config('finance.rates.as_of');

        return filled($asOf) ? now()->parse((string) $asOf) : null;
    }

    /**
     * An amount in one currency as the base currency, at the rate given or
     * the one in force. Rounded to the minor unit, half up, once.
     *
     * @throws \InvalidArgumentException when no rate is known and none is given
     */
    public static function toBase(Money $amount, ?float $rate = null): Money
    {
        if ($amount->currency === self::base()) {
            return $amount;
        }

        $rate ??= self::rate($amount->currency);

        if ($rate === null || $rate <= 0) {
            throw new \InvalidArgumentException('No exchange rate is known for '.$amount->currency.'. Set one under Exchange rates, or type the rate used.');
        }

        return Money::ofMinor((int) round($amount->minor * $rate), self::base());
    }

    /**
     * What a base-currency amount comes to in each other currency a rate
     * is known for — for a price list. Nothing for a currency with no rate.
     *
     * @return list<Money>
     */
    public static function equivalents(Money $amount): array
    {
        if ($amount->currency !== self::base()) {
            return [];
        }

        $equivalents = [];

        foreach (self::currencies() as $currency) {
            if ($currency === self::base()) {
                continue;
            }

            $rate = self::rate($currency);

            if ($rate === null || $rate <= 0) {
                continue;
            }

            // To the whole unit: "≈ USD 1,848" is a guide, and the cents
            // would be a precision the rate does not have.
            $equivalents[] = Money::ofMajor((int) round($amount->minor / $rate / 100), $currency);
        }

        return $equivalents;
    }

    /** "≈ USD 1,848 · SAR 6,931", or null when no rate is known. */
    public static function equivalentsWords(Money $amount): ?string
    {
        $equivalents = self::equivalents($amount);

        if ($equivalents === []) {
            return null;
        }

        return '≈ '.implode(' · ', array_map(fn (Money $money): string => $money->format(), $equivalents));
    }
}
