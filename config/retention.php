<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How long personal data is kept
    |--------------------------------------------------------------------------
    |
    | Every value here is null, and that is not an oversight: **how long to
    | keep a pilgrim's passport number is a legal and commercial decision,
    | not a technical one.** It depends on Maldivian tax and company record
    | rules, on what the Ministry of Islamic Affairs expects of a licensed
    | Umrah operator, and on what Rihla is willing to promise a customer.
    | Nobody has stated any of that, so nothing here guesses at it — a
    | default invented by a developer becomes the policy by accident, and
    | the first anybody hears of it is when the data is gone.
    |
    | `php artisan data:retention` reports what is held and how old it is,
    | which is the information needed to choose. Once a number is set here,
    | the same command says how much is past it. Deleting is still a
    | deliberate act, one person at a time, through `data:forget`.
    |
    | Values are in years, counted from the row's creation.
    |
    */

    'years' => [

        // Names, contacts, national ID. The commercial record of who Rihla
        // has served.
        'customers' => null,

        // Passport numbers, dates of birth, medical notes, emergency
        // contacts. The most sensitive category and the one with the
        // weakest case for keeping: it is needed to fly somebody, not to
        // account for having flown them.
        'travellers' => null,

        // Identity documents on disk. A passport scan is the single worst
        // thing in this system to still be holding.
        'documents' => null,

        // People who asked and did not book. No financial record attaches
        // to these at all.
        'enquiries' => null,

        // Questions put to the assistant, which can be personal.
        'assistant_exchanges' => null,

        // Who did what in the staff panel.
        'audit_logs' => null,
    ],

];
