<?php

namespace App\Exceptions;

use App\Models\Departure;
use RuntimeException;

/**
 * Thrown when seats cannot be taken off a departure.
 *
 * A distinct exception rather than a boolean return, because every caller
 * has to do something about it and a boolean is ignorable. The public
 * checkout turns it into "these seats have just gone"; the admin shows the
 * message; nothing may quietly carry on and write the row anyway.
 */
class NoSeatsAvailable extends RuntimeException
{
    public static function on(Departure $departure, int $requested, int $remaining): self
    {
        return new self(sprintf(
            'Departure %d has %d seat(s) left; %d were asked for.',
            $departure->getKey(), $remaining, $requested,
        ));
    }

    /**
     * Capacity of zero means nobody has entered one, not that the departure
     * is unlimited. Every departure backfilled from `trips` starts there, so
     * this is the message booking staff will actually see first, and it has
     * to say what to do about it.
     */
    public static function becauseCapacityIsUnset(Departure $departure): self
    {
        return new self(sprintf(
            'Departure %d has no capacity recorded. Set the total number of seats before selling any.',
            $departure->getKey(),
        ));
    }
}
