<?php

namespace App\Exceptions;

use App\Models\Booking;
use RuntimeException;

/**
 * Something asked for a Saudi document on a booking that never leaves the
 * Maldives — §15.5 (Phase 10).
 *
 * Thrown rather than silently returning null. A visa desk that quietly
 * declines to open an application looks exactly like one that opened it,
 * and the traveller finds out at the point where somebody expected a visa
 * to exist. A family going to Ukulhas is never asked for a permit, and the
 * refusal says which booking and why.
 */
class NotAnUmrahBooking extends RuntimeException
{
    public static function forVisa(Booking $booking): self
    {
        return self::because($booking, 'a visa');
    }

    public static function forPermit(Booking $booking): self
    {
        return self::because($booking, 'an Umrah permit');
    }

    private static function because(Booking $booking, string $document): self
    {
        return new self(sprintf(
            'Booking %s is an island holiday, which needs no %s. '
            .'Travel documents are gated on Package::needsTravelDocuments().',
            $booking->reference ?? (string) $booking->getKey(),
            $document,
        ));
    }
}
