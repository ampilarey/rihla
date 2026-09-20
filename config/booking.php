<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seat holds
    |--------------------------------------------------------------------------
    |
    | How long a seat stays reserved while somebody finishes checking out.
    | The plan (§5.2) sets fifteen minutes. It is configuration rather than a
    | constant because it is an operations decision — during Ramadan, when a
    | departure sells out in an afternoon, holding a seat for a quarter of an
    | hour is expensive — and changing it should not be a deployment.
    |
    | Expiry is never trusted to a scheduler. A hold is expired because the
    | clock says so, and App\Services\Booking\SeatAllocator reclaims lapsed
    | holds inside the same row lock it takes to issue a new one. The
    | `bookings:expire-holds` command exists so the counters are tidy on a
    | quiet departure too, not because correctness depends on cron running.
    |
    */

    'holds' => [
        'minutes' => (int) env('BOOKING_HOLD_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Booking references
    |--------------------------------------------------------------------------
    |
    | RIH-B-2026-0417: prefix, the year the booking was made, and a
    | zero-padded sequence. The sequence is the primary key, assigned by the
    | database after insert, so two people pressing save in the same second
    | cannot be given the same reference.
    |
    */

    'reference' => [
        'prefix' => env('BOOKING_REFERENCE_PREFIX', 'RIH-B'),
        'pad' => 4,
    ],

];
