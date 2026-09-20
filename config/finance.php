<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Exchange rates
    |--------------------------------------------------------------------------
    |
    | Hotels bill in SAR, airlines in USD, and the office collects MVR. A
    | single profit figure for a departure therefore needs a rate — and a
    | rate this application invents would be a number somebody prices a
    | season on.
    |
    | So this is empty until the owner states it. While it is empty,
    | App\Support\JourneyProfit reports each currency separately and says
    | plainly that it cannot give one figure. Fill it in and the same
    | report adds a converted total, labelled with the rate it used and the
    | date that rate was set.
    |
    | Values are how many MVR one unit of the currency is worth, in minor
    | units of MVR per whole unit of the foreign currency — 1 USD at 15.42
    | MVR is 1542.
    |
    */
    'rates' => [
        'to' => env('FINANCE_BASE_CURRENCY', 'MVR'),

        // 'USD' => 1542,
        // 'SAR' => 411,
        'as_of' => env('FINANCE_RATES_AS_OF'),
    ],
];
