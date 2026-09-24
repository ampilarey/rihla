<?php

namespace App\Exceptions;

use App\Models\Stay;
use RuntimeException;

/**
 * A stay was asked to move to a status it cannot reach from where it is.
 *
 * Throwing rather than ignoring, for the reason the booking version
 * records: a confirmed stay quietly accepting "requested" again would put a
 * paid-for room back on sale with nothing saying who did it.
 */
class IllegalStayTransition extends RuntimeException
{
    public static function from(Stay $stay, string $to): self
    {
        $allowed = Stay::TRANSITIONS[$stay->status] ?? [];

        return new self(sprintf(
            'A %s stay cannot become %s. Allowed from here: %s.',
            $stay->status,
            $to,
            $allowed === [] ? 'nothing — it is a final status' : implode(', ', $allowed),
        ));
    }
}
