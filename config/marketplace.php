<?php

/*
|--------------------------------------------------------------------------
| The Stays marketplace — §16 of docs/WEBSITE_UPGRADE_PLAN.md
|--------------------------------------------------------------------------
|
| Every value here is the owner's to state. Where a value is null, the
| code that needs it refuses rather than guesses — a marketplace booking
| with no commission recorded is a silent loss, and an invented rate is
| the same mistake as the invented social links (AGENTS.md).
|
*/

return [

    /*
    | What a local pays in. A tourist pays in the property's own currency,
    | which is what every room was priced in before §16 and stays so: a
    | guesthouse quotes foreigners in dollars and a Malé room may be in
    | rufiyaa, and neither is converted (§16.2 rule 5).
    */
    'currencies' => [
        'local' => env('MARKETPLACE_LOCAL_CURRENCY', 'MVR'),
        // Only what a tourist's price *range* in search is typed in. A
        // tourist is still charged in each property's own currency.
        'tourist' => env('MARKETPLACE_TOURIST_CURRENCY', 'USD'),
    ],

    /*
    | Rihla's commission on a marketplace booking, in whole percent, when a
    | host has none of their own. Null until the owner states it (§16.16).
    */
    'default_commission_pct' => env('MARKETPLACE_COMMISSION_PCT') === null
        ? null
        : (int) env('MARKETPLACE_COMMISSION_PCT'),

    /*
    | Only hosts whose Ministry of Tourism registration somebody at Rihla has
    | checked may go live (§16.3 decision 3).
    */
    'require_registration' => (bool) env('MARKETPLACE_REQUIRE_REGISTRATION', true),

];
