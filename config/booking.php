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

    /*
    |--------------------------------------------------------------------------
    | Party size
    |--------------------------------------------------------------------------
    |
    | The most seats one checkout may take at once. Not a business rule about
    | group size — larger groups are welcome and are exactly the bookings
    | Rihla wants — but a ceiling on how many traveller forms one page asks a
    | phone to render and one person to fill in. Beyond it, talking to
    | somebody is genuinely the better route, and the page says so.
    |
    */

    'party' => [
        'max' => (int) env('BOOKING_PARTY_MAX', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Traveller types
    |--------------------------------------------------------------------------
    |
    | Which price tier a traveller falls into, by age *on the departure date*
    | — not today's age. A fourteen-year-old who turns fifteen in the air is
    | a different booking from one who does not.
    |
    | The bands follow the airline convention every carrier flying this route
    | uses (infant under 2, child under 12). **They are the operator's to
    | confirm**, which is why they are configuration and not constants.
    |
    | A band only changes the price when the departure actually publishes a
    | tier for it. A departure that prices only adults prices everybody as an
    | adult, rather than this quietly inventing a discount nobody offered.
    |
    */

    'pax_types' => [
        // Highest age, inclusive, that still counts as this type.
        'infant' => 1,
        'child' => 11,
    ],

    'reference' => [
        'prefix' => env('BOOKING_REFERENCE_PREFIX', 'RIH-B'),
        'pad' => 4,
    ],

];
