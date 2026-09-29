<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A marketplace booking for a host whose commission nobody has stated —
 * §16.9. Refused rather than guessed: a stay recorded with no commission
 * is money Rihla earned and no report will ever show.
 */
class CommissionNotSet extends RuntimeException
{
    public static function forHost(string $host): self
    {
        return new self("No commission is set for {$host}, and no default is configured (MARKETPLACE_COMMISSION_PCT).");
    }
}
