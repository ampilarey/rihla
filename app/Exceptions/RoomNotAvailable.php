<?php

namespace App\Exceptions;

use App\Models\RoomType;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Thrown when a room's dates cannot be taken.
 *
 * A distinct exception rather than a boolean, for the reason
 * {@see NoSeatsAvailable} records: every caller has to do something about
 * it and a boolean is ignorable. Nothing may quietly carry on and write the
 * stay anyway — that write is a double booking.
 *
 * The named constructors exist because the four reasons a room cannot be
 * taken need four different sentences. "Not available" tells a guesthouse
 * owner nothing, and tells a visitor who has just been asked for a deposit
 * even less.
 */
class RoomNotAvailable extends RuntimeException
{
    /** Somebody else holds or has confirmed one of these nights. */
    public static function taken(RoomType $room, CarbonInterface $night, int $quantity): self
    {
        return new self(sprintf(
            'All %d of room type %d are taken on %s.',
            $quantity, $room->getKey(), $night->toDateString(),
        ));
    }

    /** The night is not for sale at all — the partner took it back. */
    public static function blocked(RoomType $room, CarbonInterface $night): self
    {
        return new self(sprintf(
            'Room type %d is blocked on %s and is not for sale.',
            $room->getKey(), $night->toDateString(),
        ));
    }

    /**
     * A quantity of zero means nobody has recorded how many of this room the
     * building has — not that it is unlimited. Same shape as a departure
     * with no capacity, and the message has to say what to do about it
     * rather than leaving an integrity error to explain itself.
     */
    public static function becauseQuantityIsUnset(RoomType $room): self
    {
        return new self(sprintf(
            'Room type %d has no quantity recorded. Set how many of this room the property has before selling any.',
            $room->getKey(),
        ));
    }

    public static function forTooFewNights(RoomType $room, int $nights, int $minimum): self
    {
        return new self(sprintf(
            'Room type %d takes a minimum of %d night(s); %d were asked for.',
            $room->getKey(), $minimum, $nights,
        ));
    }
}
