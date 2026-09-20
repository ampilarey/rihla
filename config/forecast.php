<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How much history a forecast needs before it will give a number
    |--------------------------------------------------------------------------
    |
    | A projection from two past journeys is an extrapolation with a
    | confident face on it. Rihla has run a handful of departures and most
    | of the older ones arrived through the historical import, where booking
    | dates are the import's dates rather than the day somebody actually
    | booked — so the pace they appear to have sold at is not real.
    |
    | Below this many comparable journeys the forecast refuses and says so,
    | and the screen shows what is actually known instead: seats sold, days
    | to go, and the pace so far. Raise it as the history grows.
    |
    */

    'minimum_comparable_journeys' => (int) env('FORECAST_MINIMUM_JOURNEYS', 3),

    /*
    |--------------------------------------------------------------------------
    | How far out a forecast is worth making
    |--------------------------------------------------------------------------
    |
    | Inside this many days the question stops being "will it fill" and
    | becomes "who is still outstanding", which the departure board already
    | answers. A pace projection three days before a flight is arithmetic
    | nobody acts on.
    |
    */

    'quiet_within_days' => (int) env('FORECAST_QUIET_WITHIN_DAYS', 7),

];
