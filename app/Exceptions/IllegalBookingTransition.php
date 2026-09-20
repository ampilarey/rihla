<?php

namespace App\Exceptions;

use App\Models\Booking;
use RuntimeException;

/**
 * A booking was asked to move to a status it cannot reach from where it is.
 *
 * Throwing rather than ignoring: a confirmed booking silently accepting
 * "draft" again would unpick a paid seat with no record of who did it.
 */
class IllegalBookingTransition extends RuntimeException
{
    public static function from(Booking $booking, string $to): self
    {
        $allowed = Booking::TRANSITIONS[$booking->status] ?? [];

        return new self(sprintf(
            'A %s booking cannot become %s. Allowed from here: %s.',
            $booking->status,
            $to,
            $allowed === [] ? 'nothing — it is a final status' : implode(', ', $allowed),
        ));
    }
}
