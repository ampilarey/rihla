<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Holds
    |--------------------------------------------------------------------------
    |
    | How long the dates stay off the calendar once a partner has said yes,
    | while the customer pays the deposit. §15.2 decision 2 sets twenty-four
    | hours, and the reason it is that long rather than the fifteen minutes a
    | seat hold gets is BML Connect: it is redirect-based and cannot hold a
    | card, so the deposit is a payment link somebody has to open, and a
    | visitor in another time zone may well be asleep when it is issued.
    |
    | Expiry is never trusted to a scheduler. A lapsed hold is reclaimed
    | inside the same row lock the next hold takes — see
    | App\Services\Stays\StayAllocator — because this application runs on
    | cPanel shared hosting, where a misconfigured cron means dates stay
    | held for ever and a guesthouse reads as full while it is empty.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Green tax
    |--------------------------------------------------------------------------
    |
    | The Maldives green tax is charged per guest per night. §15.2 decision 5
    | makes it a separate line, "paid at the property" by default, and says
    | the amount is a value the owner enters.
    |
    | **It is null until they do, and that is deliberate.** The real figure
    | is set by a government and differs by the kind of property; writing a
    | plausible one here would put a number on a customer's quote that
    | nobody at Rihla had checked, which is the defect this codebase keeps
    | catching in other forms — an invented social link, a made-up playlist
    | id. Unset, the pages say the tax applies and do not quote a figure,
    | which is true and useless rather than false and convincing.
    |
    | Whether it is already inside the room rate is a per-partner answer
    | (`partners.green_tax_mode`), because it is a commercial term of that
    | partner's contract rather than a property of the tax.
    |
    */

    'green_tax' => [
        // Minor units per guest per night. Null means nobody has stated it.
        'amount_minor' => env('STAYS_GREEN_TAX_MINOR') === null
            ? null
            : (int) env('STAYS_GREEN_TAX_MINOR'),

        // The tax is levied in dollars whatever the room is priced in.
        'currency' => env('STAYS_GREEN_TAX_CURRENCY', 'USD'),
    ],

    'holds' => [
        'hours' => (int) env('STAYS_HOLD_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stay references
    |--------------------------------------------------------------------------
    |
    | RIH-S-2026-0031: prefix, the year the stay was requested, and a
    | zero-padded sequence. The sequence is the primary key, assigned by the
    | database after insert, so two requests in the same second cannot be
    | given the same reference. `S` rather than `B` so a stay and a booking
    | are never mistaken for one another on the phone.
    |
    */

    'reference' => [
        'prefix' => env('STAYS_REFERENCE_PREFIX', 'RIH-S'),
        'pad' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | How far ahead a stay may be booked
    |--------------------------------------------------------------------------
    |
    | A ceiling on the date picker, not a business rule. Rates are entered a
    | season at a time, and a request for a night three years out would be
    | quoted at the base rate because nobody has priced it yet — which is a
    | number Rihla would then be held to.
    |
    */

    'booking_window' => [
        'days' => (int) env('STAYS_BOOKING_WINDOW_DAYS', 540),
    ],

];
