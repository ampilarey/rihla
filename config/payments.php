<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    |
    | How money can arrive. Bank transfer is first and is not an afterthought:
    | the plan (§5.3) is explicit that it will remain a large share of
    | Maldivian payments, and an operator that treats it as the fallback
    | builds a checkout most of its customers cannot use.
    |
    | Card is switched off because there is nothing behind it yet — BML
    | merchant onboarding has not happened. It is a seam, not a stub: the
    | driver refuses loudly rather than pretending to take money.
    |
    */

    'methods' => [
        'bank_transfer' => [
            'enabled' => (bool) env('PAYMENTS_BANK_TRANSFER', true),
            'driver' => 'bank_transfer',
            'label' => 'Bank transfer',
        ],

        'cash' => [
            'enabled' => (bool) env('PAYMENTS_CASH', true),
            'driver' => 'manual',
            'label' => 'Cash at the office',
        ],

        'card' => [
            'enabled' => (bool) env('PAYMENTS_CARD', false),
            'driver' => 'bml',
            'label' => 'Card',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bank accounts
    |--------------------------------------------------------------------------
    |
    | **Empty on purpose.** Nobody has given Rihla's account numbers, and an
    | invented one is not a placeholder — it is an instruction to a customer
    | to send money somewhere. The checkout says "ask us for the transfer
    | details" while this is empty, which is awkward and true, rather than
    | printing a number that is neither.
    |
    | This is the same rule that the fabricated social links and the
    | `PLxxxxxxxxxx` playlist broke on the live site.
    |
    | Fill it in `.env` or move it to settings when the operator provides it:
    |
    |   PAYMENTS_BANK_NAME="Bank of Maldives"
    |   PAYMENTS_BANK_ACCOUNT_NAME="..."
    |   PAYMENTS_BANK_ACCOUNT_MVR="..."
    |   PAYMENTS_BANK_ACCOUNT_USD="..."
    |
    */

    'bank' => [
        'name' => env('PAYMENTS_BANK_NAME'),
        'account_name' => env('PAYMENTS_BANK_ACCOUNT_NAME'),
        'accounts' => array_filter([
            'MVR' => env('PAYMENTS_BANK_ACCOUNT_MVR'),
            'USD' => env('PAYMENTS_BANK_ACCOUNT_USD'),
        ]),
    ],

    /*
    |--------------------------------------------------------------------------
    | BML Connect
    |--------------------------------------------------------------------------
    |
    | Nothing here is set, and the driver refuses while that is true. Merchant
    | onboarding is the longest external lead time in this phase and it gates
    | every card payment; the shape is ready so that connecting it is a
    | configuration change rather than a rewrite.
    |
    */

    'bml' => [
        'api_key' => env('BML_API_KEY'),
        'app_id' => env('BML_APP_ID'),
        'mode' => env('BML_MODE', 'sandbox'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slips
    |--------------------------------------------------------------------------
    |
    | The transfer slip a customer uploads. Same private disk as the document
    | wallet, and reachable only through a short-lived signed URL: a slip
    | carries an account number and a name.
    |
    */

    'slips' => [
        'disk' => env('PAYMENTS_SLIP_DISK', 'documents'),
        'max_kilobytes' => (int) env('PAYMENTS_SLIP_MAX_KB', 8192),
        'mime_types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'image/webp'],
        'link_minutes' => (int) env('PAYMENTS_SLIP_LINK_MINUTES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Instalments
    |--------------------------------------------------------------------------
    |
    | **Not modelled.** The plan names deposit-plus-instalments, but the terms
    | — how much deposit, how many instalments, how far before departure the
    | balance is due, what happens when one is missed — are the operator's
    | commercial policy and nobody has stated them. A guessed schedule shown
    | to a customer is a promise this application invented.
    |
    | Until then a booking can take any number of payments towards its total,
    | which is what actually happens on the phone today, and the balance is a
    | plain subtraction.
    |
    */

];
